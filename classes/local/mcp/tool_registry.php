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
 * MCP tools/list construction.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\mcp;

use core_external\external_api;

/**
 * Builds the tool definitions offered to an authenticated caller.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_registry {
    /**
     * List the tools available to a caller, ordered by name.
     *
     * Functions that are missing on this site or deprecated are skipped, and write functions are
     * skipped when the token lacks the write scope.
     *
     * @param auth_context $ctx Authenticated caller.
     * @return array[] MCP Tool objects.
     */
    public function list_tools(auth_context $ctx): array {
        $tools = [];
        foreach (allowlist::names() as $name) {
            $info = self::function_info($name);
            if ($info === null) {
                continue;
            }
            $iswrite = allowlist::is_write($name, $info);
            if ($iswrite && !$ctx->can_write()) {
                continue;
            }
            $returns = $info->returns_desc === null ? null : result_filter::filter_schema($name, $info->returns_desc);
            $tools[] = [
                'name' => $name,
                'description' => (string) ($info->description ?? ''),
                'inputSchema' => schema_converter::convert($info->parameters_desc),
                'outputSchema' => schema_converter::output_schema($returns),
                'annotations' => ['readOnlyHint' => !$iswrite],
            ];
        }
        return $tools;
    }

    /**
     * Load usable function information.
     *
     * @param string $name External function name.
     * @return \stdClass|null Null when the function is missing, broken or deprecated.
     */
    public static function function_info(string $name): ?\stdClass {
        try {
            $info = external_api::external_function_info($name, IGNORE_MISSING);
        } catch (\Throwable $e) {
            return null;
        }
        if (!$info || !empty($info->deprecated)) {
            return null;
        }
        return $info;
    }
}
