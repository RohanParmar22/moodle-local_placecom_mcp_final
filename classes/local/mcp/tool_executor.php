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
 * MCP tools/call execution.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\mcp;

use core_external\external_api;
use core_external\external_description;
use core_external\external_multiple_structure;
use core_external\external_single_structure;

/**
 * Runs an allowlisted external function in-process as the authenticated user.
 *
 * Moodle's own parameter validation, capability checks and return value cleaning apply. Failures of
 * the function are reported as MCP tool results with isError set, not as JSON-RPC errors.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_executor {
    /**
     * Execute a tool.
     *
     * @param string $name Tool (external function) name.
     * @param array $arguments Tool arguments.
     * @param auth_context $ctx Authenticated caller (already logged in as the current user).
     * @return array MCP CallToolResult.
     * @throws jsonrpc_exception For protocol-level problems (unknown or unavailable tool).
     */
    public function call(string $name, array $arguments, auth_context $ctx): array {
        global $DB, $PAGE, $COURSE, $SITE;

        if (!allowlist::is_allowed($name)) {
            throw new jsonrpc_exception(
                jsonrpc::INVALID_PARAMS,
                get_string('error_toolnotallowed', 'local_placecom_mcp', $name)
            );
        }
        $info = tool_registry::function_info($name);
        if ($info === null) {
            throw new jsonrpc_exception(
                jsonrpc::INVALID_PARAMS,
                get_string('error_toolunavailable', 'local_placecom_mcp', $name)
            );
        }
        if (allowlist::is_write($name, $info) && !$ctx->can_write()) {
            return self::error_result(get_string('error_writescope', 'local_placecom_mcp'));
        }

        external_api::set_context_restriction(\context_system::instance());
        \core\event\webservice_function_called::create(['other' => ['function' => $name]])->trigger();

        // External functions may alter the page and course globals; give them fresh ones and restore after.
        $savedpage = $PAGE;
        $savedcourse = $COURSE;
        try {
            $PAGE = new \moodle_page();
            $COURSE = clone($SITE);

            $params = external_api::validate_parameters($info->parameters_desc, $arguments);
            $result = call_user_func_array([$info->classname, $info->methodname], array_values($params));
            $returns = null;
            if ($info->returns_desc !== null) {
                $result = external_api::clean_returnvalue($info->returns_desc, $result);
                $returns = result_filter::filter_schema($name, $info->returns_desc);
            }
            $result = result_filter::filter($name, $result);
            if ($returns !== null) {
                $result = self::shape($returns, $result);
            }
            $structured = ['result' => $result];
            return [
                'content' => [['type' => 'text', 'text' => jsonrpc::encode($structured)]],
                'structuredContent' => $structured,
            ];
        } catch (\Throwable $e) {
            if ($DB->is_transaction_started()) {
                $DB->force_transaction_rollback();
            }
            return self::error_result(self::describe_error($e));
        } finally {
            $PAGE = $savedpage;
            $COURSE = $savedcourse;
        }
    }

    /**
     * Build an MCP tool error result.
     *
     * @param string $message Error text shown to the assistant.
     * @return array
     */
    public static function error_result(string $message): array {
        return [
            'content' => [['type' => 'text', 'text' => $message]],
            'isError' => true,
        ];
    }

    /**
     * Text for a failed tool call; debugging details only on developer-debug sites.
     *
     * @param \Throwable $e The failure.
     * @return string
     */
    protected static function describe_error(\Throwable $e): string {
        $message = $e->getMessage();
        if (
            $e instanceof \moodle_exception && !empty($e->debuginfo) && debugging('', DEBUG_DEVELOPER)
                && strpos($message, (string) $e->debuginfo) === false
        ) {
            $message .= ' (' . $e->debuginfo . ')';
        }
        return $message;
    }

    /**
     * Make empty single structures encode as JSON objects so the value matches the output schema.
     *
     * @param external_description $desc Return description.
     * @param mixed $value Cleaned value.
     * @return mixed
     */
    protected static function shape(external_description $desc, $value) {
        if ($desc instanceof external_single_structure && is_array($value)) {
            if (!$value) {
                return new \stdClass();
            }
            foreach ($value as $key => $child) {
                if (isset($desc->keys[$key]) && $desc->keys[$key] instanceof external_description) {
                    $value[$key] = self::shape($desc->keys[$key], $child);
                }
            }
            return $value;
        }
        if ($desc instanceof external_multiple_structure && is_array($value)) {
            foreach ($value as $key => $child) {
                $value[$key] = self::shape($desc->content, $child);
            }
            return $value;
        }
        return $value;
    }
}
