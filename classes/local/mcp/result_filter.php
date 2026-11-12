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
 * Reduction of sensitive function replies.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\mcp;

use core_external\external_description;
use core_external\external_single_structure;

/**
 * Removes credentials and other unneeded data from tool replies, and reduces the advertised output
 * schemas to match.
 *
 * The site information reply is cut down to a short list of allowed keys. For the functions in
 * {@see result_filter::REMOVED_FIELDS} the named fields (group enrolment keys, quiz and lesson passwords
 * and the quiz network restriction) are removed at any depth, because Moodle returns them in plain text to
 * users who may manage the activity.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class result_filter {
    /** @var string The function whose reply is filtered. */
    public const SITE_INFO_FUNCTION = 'core_webservice_get_site_info';

    /** @var string[] The only site information keys passed to assistants. */
    public const SITE_INFO_KEYS = ['userid', 'username', 'firstname', 'lastname', 'fullname', 'sitename'];

    /** @var array<string, string[]> Field names removed, at any depth, from the reply and schema of a function. */
    public const REMOVED_FIELDS = [
        'core_group_get_course_groups' => ['enrolmentkey'],
        'mod_quiz_get_quizzes_by_courses' => ['password', 'subnet'],
        'mod_lesson_get_lessons_by_courses' => ['password'],
    ];

    /**
     * Filter a function reply.
     *
     * @param string $function External function name.
     * @param mixed $result The (cleaned) reply.
     * @return mixed The reduced reply for site information, the reply without the removed fields for the
     *     functions in {@see result_filter::REMOVED_FIELDS}, otherwise the reply unchanged.
     */
    public static function filter(string $function, $result) {
        if (isset(self::REMOVED_FIELDS[$function])) {
            return self::remove_fields($result, self::REMOVED_FIELDS[$function]);
        }
        if ($function !== self::SITE_INFO_FUNCTION || (!is_array($result) && !is_object($result))) {
            return $result;
        }
        $data = (array) $result;
        $filtered = [];
        foreach (self::SITE_INFO_KEYS as $key) {
            if (array_key_exists($key, $data)) {
                $filtered[$key] = $data[$key];
            }
        }
        return $filtered;
    }

    /**
     * Reduce a return description in the same way as {@see filter()} reduces the reply.
     *
     * @param string $function External function name.
     * @param external_description $returns Original return description.
     * @return external_description
     */
    public static function filter_schema(string $function, external_description $returns): external_description {
        if (isset(self::REMOVED_FIELDS[$function])) {
            return self::remove_schema_fields($returns, self::REMOVED_FIELDS[$function]);
        }
        if ($function !== self::SITE_INFO_FUNCTION || !($returns instanceof external_single_structure)) {
            return $returns;
        }
        $keys = [];
        foreach (self::SITE_INFO_KEYS as $key) {
            if (isset($returns->keys[$key])) {
                $keys[$key] = $returns->keys[$key];
            }
        }
        return new external_single_structure(
            $keys,
            $returns->desc,
            $returns->required,
            $returns->default,
            $returns->allownull
        );
    }

    /**
     * Remove fields by name from a reply, at any depth.
     *
     * @param mixed $value Reply, or part of a reply.
     * @param string[] $names Field names to remove.
     * @return mixed The value without those fields.
     */
    protected static function remove_fields($value, array $names) {
        if (is_object($value)) {
            $copy = clone $value;
            foreach (get_object_vars($copy) as $key => $child) {
                if (in_array((string) $key, $names, true)) {
                    unset($copy->{$key});
                } else {
                    $copy->{$key} = self::remove_fields($child, $names);
                }
            }
            return $copy;
        }
        if (!is_array($value)) {
            return $value;
        }
        $clean = [];
        foreach ($value as $key => $child) {
            if (is_string($key) && in_array($key, $names, true)) {
                continue;
            }
            $clean[$key] = self::remove_fields($child, $names);
        }
        return $clean;
    }

    /**
     * Remove fields by name from a return description, at any depth.
     *
     * @param external_description $desc Return description.
     * @param string[] $names Field names to remove.
     * @return external_description A copy of the description without those fields.
     */
    protected static function remove_schema_fields(external_description $desc, array $names): external_description {
        if ($desc instanceof external_single_structure) {
            $keys = [];
            foreach ($desc->keys as $key => $child) {
                if (in_array((string) $key, $names, true)) {
                    continue;
                }
                $keys[$key] = $child instanceof external_description ? self::remove_schema_fields($child, $names) : $child;
            }
            return new external_single_structure($keys, $desc->desc, $desc->required, $desc->default, $desc->allownull);
        }
        if ($desc instanceof \core_external\external_multiple_structure) {
            return new \core_external\external_multiple_structure(
                self::remove_schema_fields($desc->content, $names),
                $desc->desc,
                $desc->required,
                $desc->default,
                $desc->allownull
            );
        }
        return $desc;
    }
}
