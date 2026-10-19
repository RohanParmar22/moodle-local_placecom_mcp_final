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
 * Parsed JSON-RPC message.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\mcp;

/**
 * One JSON-RPC 2.0 message received from an MCP client: a request, a notification or a response.
 *
 * Instances are created only by {@see request::parse()}, which validates the envelope.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class request {
    /** @var string The jsonrpc member. */
    protected string $jsonrpc;

    /** @var string|null Method name, null for responses. */
    protected ?string $method;

    /** @var string|int|null Request id. */
    protected $id;

    /** @var bool Whether the message carried an id member. */
    protected bool $hasid;

    /** @var array|null Params as an associative array, null when absent. */
    protected ?array $params;

    /** @var \stdClass|null Params as decoded objects, used to tell JSON objects from JSON arrays. */
    protected ?\stdClass $rawparams;

    /**
     * Constructor; use {@see request::parse()}.
     *
     * @param string $jsonrpc The jsonrpc member.
     * @param string|null $method Method name.
     * @param string|int|null $id Request id.
     * @param bool $hasid Whether an id member was present.
     * @param array|null $params Params as associative array.
     * @param \stdClass|null $rawparams Params as object tree.
     */
    protected function __construct(
        string $jsonrpc,
        ?string $method,
        $id,
        bool $hasid,
        ?array $params,
        ?\stdClass $rawparams
    ) {
        $this->jsonrpc = $jsonrpc;
        $this->method = $method;
        $this->id = $id;
        $this->hasid = $hasid;
        $this->params = $params;
        $this->rawparams = $rawparams;
    }

    /**
     * Parse and validate a request body.
     *
     * @param string $body Raw HTTP body.
     * @return request
     * @throws jsonrpc_exception When the body is not a valid single JSON-RPC 2.0 message.
     */
    public static function parse(string $body): request {
        $trimmed = trim($body);
        if ($trimmed === '') {
            throw self::fail(jsonrpc::INVALID_REQUEST, 'error_emptybody', 400);
        }

        $assoc = json_decode($trimmed, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw self::fail(jsonrpc::PARSE_ERROR, 'error_invalidjson', 400);
        }
        if ($trimmed[0] === '[') {
            throw self::fail(jsonrpc::INVALID_REQUEST, 'error_batchnotsupported', 400);
        }
        if ($trimmed[0] !== '{' || !is_array($assoc)) {
            throw self::fail(jsonrpc::INVALID_REQUEST, 'error_notobject', 400);
        }
        // A second decode keeps JSON objects as objects so that {} and [] can be told apart.
        $object = json_decode($trimmed, false);

        if (!array_key_exists('jsonrpc', $assoc) || $assoc['jsonrpc'] !== jsonrpc::VERSION) {
            throw self::fail(jsonrpc::INVALID_REQUEST, 'error_jsonrpcversion', 400);
        }

        $hasid = array_key_exists('id', $assoc);
        $id = $hasid ? $assoc['id'] : null;
        if ($id !== null && !is_string($id) && !is_int($id)) {
            throw self::fail(jsonrpc::INVALID_REQUEST, 'error_invalidid', 400);
        }

        $method = null;
        if (array_key_exists('method', $assoc)) {
            if (!is_string($assoc['method']) || $assoc['method'] === '') {
                throw self::fail(jsonrpc::INVALID_REQUEST, 'error_methodrequired', 400);
            }
            $method = $assoc['method'];
        } else if (!array_key_exists('result', $assoc) && !array_key_exists('error', $assoc)) {
            throw self::fail(jsonrpc::INVALID_REQUEST, 'error_methodrequired', 400);
        }

        $params = null;
        $rawparams = null;
        if (isset($object->params)) {
            if (!($object->params instanceof \stdClass)) {
                throw self::fail(jsonrpc::INVALID_PARAMS, 'error_paramsnotobject', 400);
            }
            $params = $assoc['params'];
            $rawparams = $object->params;
        }

        return new request(jsonrpc::VERSION, $method, $id, $hasid, $params, $rawparams);
    }

    /**
     * Build a protocol exception with a plugin string.
     *
     * @param int $code JSON-RPC error code.
     * @param string $identifier Language string identifier.
     * @param int $httpstatus HTTP status.
     * @param string|null $a Optional string placeholder value.
     * @return jsonrpc_exception
     */
    protected static function fail(int $code, string $identifier, int $httpstatus = 200, ?string $a = null): jsonrpc_exception {
        return new jsonrpc_exception($code, get_string($identifier, 'local_placecom_mcp', $a), $httpstatus);
    }

    /**
     * The jsonrpc member (always "2.0" after parsing).
     *
     * @return string
     */
    public function get_jsonrpc(): string {
        return $this->jsonrpc;
    }

    /**
     * Method name, or null for a client response.
     *
     * @return string|null
     */
    public function get_method(): ?string {
        return $this->method;
    }

    /**
     * Request id (null for notifications or an explicit null id).
     *
     * @return string|int|null
     */
    public function get_id() {
        return $this->id;
    }

    /**
     * Whether the message carried an id member.
     *
     * @return bool
     */
    public function has_id(): bool {
        return $this->hasid;
    }

    /**
     * Params as an associative array, or null when absent.
     *
     * @return array|null
     */
    public function get_params(): ?array {
        return $this->params;
    }

    /**
     * Whether this is a notification (a method call without id that expects no answer).
     *
     * @return bool
     */
    public function is_notification(): bool {
        return $this->method !== null && !$this->hasid;
    }

    /**
     * Whether this is a response sent by the client (no method, result or error present).
     *
     * @return bool
     */
    public function is_response(): bool {
        return $this->method === null;
    }

    /**
     * Whether this is a tools/call request.
     *
     * @return bool
     */
    public function is_tool_call(): bool {
        return $this->method === 'tools/call';
    }

    /**
     * The tool name of a tools/call request.
     *
     * @return string
     * @throws jsonrpc_exception When the name is missing, empty or not a string.
     */
    public function get_tool_name(): string {
        $name = $this->params['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw self::fail(jsonrpc::INVALID_PARAMS, 'error_toolnamemissing');
        }
        return $name;
    }

    /**
     * The tool arguments of a tools/call request.
     *
     * @return array Associative array; empty when no arguments were given.
     * @throws jsonrpc_exception When arguments is present but not a JSON object.
     */
    public function get_tool_arguments(): array {
        if ($this->rawparams === null || !isset($this->rawparams->arguments)) {
            return [];
        }
        if (!($this->rawparams->arguments instanceof \stdClass)) {
            throw self::fail(jsonrpc::INVALID_PARAMS, 'error_argumentsnotobject');
        }
        return $this->params['arguments'];
    }
}
