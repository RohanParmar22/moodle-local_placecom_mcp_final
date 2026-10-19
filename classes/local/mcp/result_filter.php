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
 * Removes credentials and other unneeded data from the site information reply, and reduces its
 * advertised output schema to match.
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

    /**
     * Filter a function reply.
     *
     * @param string $function External function name.
     * @param mixed $result The (cleaned) reply.
     * @return mixed The reduced reply for site information, otherwise the reply unchanged.
     */
    public static function filter(string $function, $result) {
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
}
