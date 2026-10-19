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
 * Reviewed list of Moodle external functions that may be exposed as MCP tools.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\mcp;

/**
 * The single source of truth for which external functions an assistant may list or call.
 *
 * Nothing outside this list is ever exposed, whatever else is installed on the site. Functions that
 * change data are marked "write" and additionally need the moodle_mcp_write scope.
 *
 * Deliberately excluded: user lookup and search functions (core_user_*), because they reveal other
 * people's profiles, and the forum discussion/post read functions, because they reveal other users'
 * ids and picture URLs. The site information reply is exposed only in filtered form (see result_filter).
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class allowlist {
    /** @var string Marker for functions that only read data. */
    public const READ = 'read';

    /** @var string Marker for functions that change data. */
    public const WRITE = 'write';

    /** @var array<string, string> Function name => read or write. */
    public const FUNCTIONS = [
        'core_webservice_get_site_info' => self::READ,
        'core_course_get_courses_by_field' => self::READ,
        'core_course_search_courses' => self::READ,
        'core_course_get_categories' => self::READ,
        'core_course_get_course_module' => self::READ,
        'core_course_get_enrolled_courses_by_timeline_classification' => self::READ,
        'core_course_get_recent_courses' => self::READ,
        'core_enrol_get_users_courses' => self::READ,
        'core_group_get_course_groups' => self::READ,
        'core_group_get_course_user_groups' => self::READ,
        'core_group_get_group_members' => self::READ,
        'core_calendar_get_action_events_by_course' => self::READ,
        'core_calendar_get_action_events_by_timesort' => self::READ,
        'core_message_get_unread_notification_count' => self::READ,
        'core_completion_get_course_completion_status' => self::READ,
        'core_badges_get_user_badges' => self::READ,
        'mod_assign_get_assignments' => self::READ,
        'mod_quiz_get_quizzes_by_courses' => self::READ,
        'mod_lesson_get_lessons_by_courses' => self::READ,
        'mod_page_get_pages_by_courses' => self::READ,
        'mod_url_get_urls_by_courses' => self::READ,
        'mod_forum_get_forums_by_courses' => self::READ,
        'mod_forum_add_discussion' => self::WRITE,
        'mod_forum_add_discussion_post' => self::WRITE,
        'message_popup_get_unread_popup_notification_count' => self::READ,
    ];

    /**
     * Whether a function is on the reviewed list.
     *
     * @param string $name External function name.
     * @return bool
     */
    public static function is_allowed(string $name): bool {
        return array_key_exists($name, self::FUNCTIONS);
    }

    /**
     * Whether a function changes data.
     *
     * Fails closed: a function counts as write if either this list or Moodle's own metadata says so.
     *
     * @param string $name External function name.
     * @param \stdClass|null $info Optional function information from external_api::external_function_info().
     * @return bool
     */
    public static function is_write(string $name, ?\stdClass $info = null): bool {
        if ((self::FUNCTIONS[$name] ?? null) === self::WRITE) {
            return true;
        }
        return $info !== null && isset($info->type) && $info->type === self::WRITE;
    }

    /**
     * All allowed function names, sorted alphabetically.
     *
     * @return string[]
     */
    public static function names(): array {
        $names = array_keys(self::FUNCTIONS);
        sort($names, SORT_STRING);
        return $names;
    }
}
