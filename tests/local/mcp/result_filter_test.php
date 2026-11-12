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
 * Tests for the site information reply filter.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\mcp;

use core_external\external_single_structure;
use core_external\external_value;

#[\PHPUnit\Framework\Attributes\CoversClass(result_filter::class)]
/**
 * Tests for {@see result_filter}.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_placecom_mcp\local\mcp\result_filter
 */
final class result_filter_test extends \advanced_testcase {
    /**
     * A typical full site information reply.
     *
     * @return array
     */
    private static function full_reply(): array {
        return [
            'sitename' => 'Test site',
            'username' => 'student1',
            'firstname' => 'Ada',
            'lastname' => 'Lovelace',
            'fullname' => 'Ada Lovelace',
            'userid' => 42,
            'userprivateaccesskey' => 'secretkey',
            'functions' => [['name' => 'x', 'version' => '1']],
            'userissiteadmin' => false,
            'siteurl' => 'https://example.com',
            'userpictureurl' => 'https://example.com/pic.png',
            'release' => '4.5',
        ];
    }

    /**
     * Only the six safe keys remain, values unchanged.
     */
    public function test_full_reply(): void {
        $filtered = result_filter::filter(result_filter::SITE_INFO_FUNCTION, self::full_reply());
        $this->assertSame(result_filter::SITE_INFO_KEYS, array_keys($filtered));
        $this->assertSame(42, $filtered['userid']);
        $this->assertSame('Test site', $filtered['sitename']);
        $this->assertArrayNotHasKey('userprivateaccesskey', $filtered);
        $this->assertArrayNotHasKey('functions', $filtered);
    }

    /**
     * Missing keys are tolerated.
     */
    public function test_partial_reply(): void {
        $filtered = result_filter::filter(
            result_filter::SITE_INFO_FUNCTION,
            ['username' => 'student1', 'userprivateaccesskey' => 'secretkey']
        );
        $this->assertSame(['username' => 'student1'], $filtered);
    }

    /**
     * Object input is handled like an array.
     */
    public function test_object_reply(): void {
        $filtered = result_filter::filter(result_filter::SITE_INFO_FUNCTION, (object) self::full_reply());
        $this->assertSame(result_filter::SITE_INFO_KEYS, array_keys($filtered));
    }

    /**
     * Other functions pass through untouched.
     */
    public function test_other_function(): void {
        $reply = self::full_reply();
        $this->assertSame($reply, result_filter::filter('core_course_get_categories', $reply));
    }

    /**
     * The schema is reduced to the same six keys.
     */
    public function test_filter_schema(): void {
        $info = tool_registry::function_info(result_filter::SITE_INFO_FUNCTION);
        $schema = result_filter::filter_schema(result_filter::SITE_INFO_FUNCTION, $info->returns_desc);
        $this->assertInstanceOf(external_single_structure::class, $schema);
        $this->assertEqualsCanonicalizing(result_filter::SITE_INFO_KEYS, array_keys($schema->keys));

        $other = new external_single_structure(['a' => new external_value(PARAM_INT)]);
        $this->assertSame($other, result_filter::filter_schema('core_course_get_categories', $other));
    }

    /**
     * Group enrolment keys, quiz and lesson passwords and the quiz network restriction are removed from replies.
     */
    public function test_secret_fields_removed(): void {
        $groups = result_filter::filter('core_group_get_course_groups', [
            ['id' => 1, 'name' => 'Group A', 'enrolmentkey' => 'key1'],
            ['id' => 2, 'name' => 'Group B', 'enrolmentkey' => ''],
        ]);
        $this->assertSame([['id' => 1, 'name' => 'Group A'], ['id' => 2, 'name' => 'Group B']], $groups);

        $quizzes = result_filter::filter('mod_quiz_get_quizzes_by_courses', [
            'quizzes' => [['id' => 5, 'name' => 'Quiz', 'timelimit' => 60, 'password' => 'p', 'subnet' => '10.0.0.0/8']],
            'warnings' => [],
        ]);
        $this->assertSame(['quizzes' => [['id' => 5, 'name' => 'Quiz', 'timelimit' => 60]], 'warnings' => []], $quizzes);

        $lessons = result_filter::filter('mod_lesson_get_lessons_by_courses', [
            'lessons' => [['id' => 7, 'name' => 'Lesson', 'usepassword' => 1, 'password' => 'p']],
            'warnings' => [],
        ]);
        $this->assertSame(['lessons' => [['id' => 7, 'name' => 'Lesson', 'usepassword' => 1]], 'warnings' => []], $lessons);
    }

    /**
     * Objects are cleaned too without changing the original, and other functions keep the same field names.
     */
    public function test_secret_fields_in_objects_and_other_functions(): void {
        $reply = (object) ['lessons' => [(object) ['id' => 7, 'password' => 'p']], 'warnings' => []];
        $filtered = result_filter::filter('mod_lesson_get_lessons_by_courses', $reply);
        $this->assertEquals((object) ['lessons' => [(object) ['id' => 7]], 'warnings' => []], $filtered);
        $this->assertSame('p', $reply->lessons[0]->password);

        $other = [['id' => 1, 'password' => 'kept']];
        $this->assertSame($other, result_filter::filter('mod_assign_get_assignments', $other));
    }

    /**
     * The advertised schemas of the listed functions lose the secret fields, and the original is not changed.
     */
    public function test_secret_fields_removed_from_schema(): void {
        $groups = new \core_external\external_multiple_structure(new external_single_structure([
            'id' => new external_value(PARAM_INT, 'id'),
            'enrolmentkey' => new external_value(PARAM_RAW, 'key'),
        ]));
        $filtered = result_filter::filter_schema('core_group_get_course_groups', $groups);
        $this->assertInstanceOf(\core_external\external_multiple_structure::class, $filtered);
        $this->assertSame(['id'], array_keys($filtered->content->keys));
        $this->assertArrayHasKey('enrolmentkey', $groups->content->keys);

        $quizzes = new external_single_structure([
            'quizzes' => new \core_external\external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'id'),
                'password' => new external_value(PARAM_RAW, 'password', VALUE_OPTIONAL),
                'subnet' => new external_value(PARAM_RAW, 'subnet', VALUE_OPTIONAL),
            ])),
            'warnings' => new external_value(PARAM_RAW, 'warnings', VALUE_OPTIONAL),
        ]);
        $filtered = result_filter::filter_schema('mod_quiz_get_quizzes_by_courses', $quizzes);
        $this->assertSame(['quizzes', 'warnings'], array_keys($filtered->keys));
        $this->assertSame(['id'], array_keys($filtered->keys['quizzes']->content->keys));

        $lessons = new external_single_structure([
            'lessons' => new \core_external\external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'id'),
                'password' => new external_value(PARAM_RAW, 'password', VALUE_OPTIONAL),
            ])),
        ]);
        $filtered = result_filter::filter_schema('mod_lesson_get_lessons_by_courses', $lessons);
        $this->assertSame(['id'], array_keys($filtered->keys['lessons']->content->keys));
    }
}
