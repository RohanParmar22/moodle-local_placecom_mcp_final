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
 * JSON-RPC 2.0 helpers.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\mcp;

/**
 * Error codes, envelope builders and JSON encoding for the MCP endpoint.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class jsonrpc {
    /** @var string Protocol version string required in every message. */
    public const VERSION = '2.0';

    /** @var int The body is not valid JSON. */
    public const PARSE_ERROR = -32700;

    /** @var int The JSON is not a valid request object. */
    public const INVALID_REQUEST = -32600;

    /** @var int The method does not exist. */
    public const METHOD_NOT_FOUND = -32601;

    /** @var int Invalid method parameters. */
    public const INVALID_PARAMS = -32602;

    /** @var int Internal server error. */
    public const INTERNAL_ERROR = -32603;

    /** @var int Implementation-defined: authentication required or failed. */
    public const UNAUTHORIZED = -32001;

    /** @var int Implementation-defined: authenticated but not allowed. */
    public const FORBIDDEN = -32003;

    /** @var int MCP 2026-07-28: HTTP headers are missing or do not match the request body. */
    public const HEADER_MISMATCH = -32020;

    /** @var int MCP 2026-07-28: the requested protocol version is not supported. */
    public const UNSUPPORTED_PROTOCOL_VERSION = -32022;

    /** @var int Flags used for every JSON document produced by the endpoint. */
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * Build a success response envelope.
     *
     * @param string|int|null $id Request id.
     * @param mixed $result Result member (use \stdClass for an empty JSON object).
     * @return array
     */
    public static function result($id, $result): array {
        return ['jsonrpc' => self::VERSION, 'id' => $id, 'result' => $result];
    }

    /**
     * Build an error response envelope.
     *
     * @param string|int|null $id Request id, or null when it could not be determined.
     * @param int $code JSON-RPC error code.
     * @param string $message Short description of the error.
     * @param array|null $data Optional additional information; omitted when null.
     * @return array
     */
    public static function error($id, int $code, string $message, ?array $data = null): array {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }
        return ['jsonrpc' => self::VERSION, 'id' => $id, 'error' => $error];
    }

    /**
     * Encode a value as JSON with unescaped slashes and Unicode.
     *
     * @param mixed $value Value to encode.
     * @return string
     * @throws jsonrpc_exception When the value cannot be encoded.
     */
    public static function encode($value): string {
        $json = json_encode($value, self::JSON_FLAGS);
        if ($json === false) {
            throw new jsonrpc_exception(self::INTERNAL_ERROR, get_string('error_internal', 'local_placecom_mcp'));
        }
        return $json;
    }

    /**
     * Diagnostic details for an error, only when developer debugging is enabled.
     *
     * @param \Throwable $e The error.
     * @return array|null Null unless the site runs with DEBUG_DEVELOPER.
     */
    public static function debug_data(\Throwable $e): ?array {
        if (!debugging('', DEBUG_DEVELOPER)) {
            return null;
        }
        $class = get_class($e);
        $pos = strrpos($class, '\\');
        $data = ['exception' => $pos === false ? $class : substr($class, $pos + 1)];
        if ($e instanceof \moodle_exception) {
            $data['errorcode'] = $e->errorcode;
            $data['debuginfo'] = (string) $e->debuginfo;
        } else {
            $data['debuginfo'] = $e->getMessage();
        }
        return $data;
    }
}
