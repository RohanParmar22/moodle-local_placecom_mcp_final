<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Tests for MCP tool execution.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\mcp;

#[\PHPUnit\Framework\Attributes\CoversClass(tool_executor::class)]
/**
 * Tests for {@see tool_executor}.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_placecom_mcp\local\mcp\tool_executor
 */
final class tool_executor_test extends \advanced_testcase {
    /**
     * Create a user, log them in and build their caller context.
     *
     * @param string $scope Token scope.
     * @param \stdClass|null $user Existing user, or null to create one.
     * @return auth_context
     */
    private function caller(string $scope, ?\stdClass $user = null): auth_context {
        $user = $user ?? $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $token = (object) [
            'id' => 1,
            'grantid' => 1,
            'clientid' => 1,
            'clientidentifier' => 'testclient',
            'userid' => $user->id,
            'scope' => $scope,
            'expires' => time() + 3600,
        ];
        return new auth_context($user, $token);
    }

    /**
     * Functions outside the allowlist are refused as invalid params, even though they exist.
     */
    public function test_not_allowlisted(): void {
        $this->resetAfterTest();
        $ctx = $this->caller('moodle_mcp_read');
        try {
            (new tool_executor())->call('core_user_get_users', [], $ctx);
            $this->fail('Expected exception');
        } catch (jsonrpc_exception $e) {
            $this->assertSame(jsonrpc::INVALID_PARAMS, $e->get_rpc_code());
        }
    }

    /**
     * Site information is filtered and mirrored in the text content.
     */
    public function test_site_info(): void {
        $this->resetAfterTest();
        $ctx = $this->caller('moodle_mcp_read');
        $sink = $this->redirectEvents();
        $result = (new tool_executor())->call('core_webservice_get_site_info', [], $ctx);
        $events = $sink->get_events();
        $sink->close();

        $this->assertArrayNotHasKey('isError', $result);
        $data = $result['structuredContent']['result'];
        $this->assertEqualsCanonicalizing(result_filter::SITE_INFO_KEYS, array_keys($data));
        $this->assertSame($ctx->get_userid(), $data['userid']);
        $this->assertSame('text', $result['content'][0]['type']);
        $this->assertSame(['result' => $data], json_decode($result['content'][0]['text'], true));

        $called = array_filter($events, static function ($event): bool {
            return $event instanceof \core\event\webservice_function_called;
        });
        $this->assertCount(1, $called);
        $this->assertSame('core_webservice_get_site_info', reset($called)->other['function']);
    }

    /**
     * A plain read function succeeds.
     */
    public function test_get_categories(): void {
        $this->resetAfterTest();
        $ctx = $this->caller('moodle_mcp_read');
        $result = (new tool_executor())->call('core_course_get_categories', [], $ctx);
        $this->assertArrayNotHasKey('isError', $result);
        $this->assertIsArray($result['structuredContent']['result']);
        $this->assertNotEmpty($result['structuredContent']['result']);
    }

    /**
     * Create a forum in a course, optionally enrolling a user.
     *
     * @param \stdClass|null $student User to enrol, or null.
     * @return \stdClass The forum record.
     */
    private function create_forum(?\stdClass $student): \stdClass {
        $course = $this->getDataGenerator()->create_course();
        if ($student) {
            $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        }
        return $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
    }

    /**
     * Write functions need the write scope.
     */
    public function test_write_without_scope(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $forum = $this->create_forum($user);
        $ctx = $this->caller('moodle_mcp_read', $user);
        $result = (new tool_executor())->call(
            'mod_forum_add_discussion',
            ['forumid' => $forum->id, 'subject' => 'Hello', 'message' => 'World'],
            $ctx
        );
        $this->assertTrue($result['isError']);
        $this->assertSame(get_string('error_writescope', 'local_placecom_mcp'), $result['content'][0]['text']);
        $this->assertSame(0, $DB->count_records('forum_discussions', ['forum' => $forum->id]));
    }

    /**
     * Write functions run with the write scope in an enrolled course.
     */
    public function test_write_with_scope(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $forum = $this->create_forum($user);
        $ctx = $this->caller('moodle_mcp_read moodle_mcp_write', $user);
        $result = (new tool_executor())->call(
            'mod_forum_add_discussion',
            ['forumid' => $forum->id, 'subject' => 'Hello', 'message' => 'World'],
            $ctx
        );
        $this->assertArrayNotHasKey('isError', $result, $result['content'][0]['text']);
        $this->assertGreaterThan(0, $result['structuredContent']['result']['discussionid']);
        $this->assertSame(1, $DB->count_records('forum_discussions', ['forum' => $forum->id]));
    }

    /**
     * Permission failures inside the function become isError results.
     */
    public function test_write_not_enrolled(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $forum = $this->create_forum(null);
        $ctx = $this->caller('moodle_mcp_read moodle_mcp_write', $user);
        $result = (new tool_executor())->call(
            'mod_forum_add_discussion',
            ['forumid' => $forum->id, 'subject' => 'Hello', 'message' => 'World'],
            $ctx
        );
        $this->assertTrue($result['isError']);
        $this->assertNotEmpty($result['content'][0]['text']);
        $this->assertSame(0, $DB->count_records('forum_discussions', ['forum' => $forum->id]));
    }

    /**
     * Invalid parameters become isError results.
     */
    public function test_invalid_parameters(): void {
        $this->resetAfterTest();
        $ctx = $this->caller('moodle_mcp_read');
        $result = (new tool_executor())->call('core_course_get_courses_by_field', ['field' => ['not', 'a', 'string']], $ctx);
        $this->assertTrue($result['isError']);
    }

    /**
     * The error result helper builds the MCP shape.
     */
    public function test_error_result(): void {
        $this->assertSame(
            ['content' => [['type' => 'text', 'text' => 'Oops']], 'isError' => true],
            tool_executor::error_result('Oops')
        );
    }

    /**
     * Create a course with an editing teacher, a group with an enrolment key, and a quiz and a lesson with passwords.
     *
     * @return array The teacher and the course.
     */
    private function create_course_with_secrets(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->create_group(['courseid' => $course->id, 'name' => 'Group A', 'enrolmentkey' => 'GroupKey123']);
        $generator->create_module('quiz', [
            'course' => $course->id,
            'name' => 'Secret quiz',
            'password' => 'QuizPass123',
            'subnet' => '10.0.0.0/8',
        ]);
        $generator->create_module('lesson', [
            'course' => $course->id,
            'name' => 'Secret lesson',
            'usepassword' => 1,
            'password' => 'LessonPass123',
        ]);
        return [$teacher, $course];
    }

    /**
     * Call a Moodle function directly, as the current user, without the filter.
     *
     * @param string $name External function name.
     * @param array $arguments Arguments.
     * @return mixed What Moodle returns.
     */
    private function call_moodle(string $name, array $arguments) {
        $info = tool_registry::function_info($name);
        $this->assertNotNull($info);
        $params = \core_external\external_api::validate_parameters($info->parameters_desc, $arguments);
        return call_user_func_array([$info->classname, $info->methodname], array_values($params));
    }

    /**
     * Group enrolment keys are not passed to the assistant, although Moodle returns them to this teacher.
     */
    public function test_group_enrolment_key_removed(): void {
        $this->resetAfterTest();
        [$teacher, $course] = $this->create_course_with_secrets();
        $ctx = $this->caller('moodle_mcp_read', $teacher);

        $moodle = $this->call_moodle('core_group_get_course_groups', ['courseid' => $course->id]);
        $this->assertSame('GroupKey123', $moodle[0]['enrolmentkey']);

        $result = (new tool_executor())->call('core_group_get_course_groups', ['courseid' => $course->id], $ctx);
        $this->assertArrayNotHasKey('isError', $result, $result['content'][0]['text']);
        $groups = $result['structuredContent']['result'];
        $this->assertSame('Group A', $groups[0]['name']);
        $this->assertArrayNotHasKey('enrolmentkey', $groups[0]);
        $this->assertStringNotContainsString('GroupKey123', $result['content'][0]['text']);
    }

    /**
     * Quiz passwords and network restrictions are not passed to the assistant, although Moodle returns them.
     */
    public function test_quiz_password_removed(): void {
        $this->resetAfterTest();
        [$teacher, $course] = $this->create_course_with_secrets();
        $ctx = $this->caller('moodle_mcp_read', $teacher);

        $moodle = $this->call_moodle('mod_quiz_get_quizzes_by_courses', ['courseids' => [$course->id]]);
        $this->assertSame('QuizPass123', $moodle['quizzes'][0]['password']);
        $this->assertSame('10.0.0.0/8', $moodle['quizzes'][0]['subnet']);

        $result = (new tool_executor())->call('mod_quiz_get_quizzes_by_courses', ['courseids' => [$course->id]], $ctx);
        $this->assertArrayNotHasKey('isError', $result, $result['content'][0]['text']);
        $quiz = $result['structuredContent']['result']['quizzes'][0];
        $this->assertSame('Secret quiz', $quiz['name']);
        $this->assertArrayNotHasKey('password', $quiz);
        $this->assertArrayNotHasKey('subnet', $quiz);
        $this->assertStringNotContainsString('QuizPass123', $result['content'][0]['text']);
        $this->assertStringNotContainsString('10.0.0.0/8', $result['content'][0]['text']);
    }

    /**
     * Lesson passwords are not passed to the assistant, although Moodle returns them to this teacher.
     */
    public function test_lesson_password_removed(): void {
        $this->resetAfterTest();
        [$teacher, $course] = $this->create_course_with_secrets();
        $ctx = $this->caller('moodle_mcp_read', $teacher);

        $moodle = $this->call_moodle('mod_lesson_get_lessons_by_courses', ['courseids' => [$course->id]]);
        $this->assertSame('LessonPass123', $moodle['lessons'][0]['password']);

        $result = (new tool_executor())->call('mod_lesson_get_lessons_by_courses', ['courseids' => [$course->id]], $ctx);
        $this->assertArrayNotHasKey('isError', $result, $result['content'][0]['text']);
        $lesson = $result['structuredContent']['result']['lessons'][0];
        $this->assertSame('Secret lesson', $lesson['name']);
        $this->assertArrayHasKey('usepassword', $lesson);
        $this->assertArrayNotHasKey('password', $lesson);
        $this->assertStringNotContainsString('LessonPass123', $result['content'][0]['text']);
    }
}
