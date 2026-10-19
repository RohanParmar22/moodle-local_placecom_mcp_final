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
 * JSON-RPC protocol error.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\mcp;

/**
 * Raised for protocol-level problems that must be answered with a JSON-RPC error object.
 *
 * Tool execution failures are not protocol errors; they are reported as isError tool results instead.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class jsonrpc_exception extends \moodle_exception {
    /** @var int JSON-RPC error code. */
    protected int $rpccode;

    /** @var int HTTP status used for the response. */
    protected int $httpstatus;

    /** @var string Human-readable error message for the JSON-RPC error object. */
    protected string $rpcmessage;

    /**
     * Constructor.
     *
     * @param int $rpccode JSON-RPC error code (see the jsonrpc class constants).
     * @param string $message Human-readable, already localised message.
     * @param int $httpstatus HTTP status for the response carrying this error.
     */
    public function __construct(int $rpccode, string $message, int $httpstatus = 200) {
        $this->rpccode = $rpccode;
        $this->httpstatus = $httpstatus;
        $this->rpcmessage = $message;
        parent::__construct('error_mcp', 'local_placecom_mcp', '', $message);
    }

    /**
     * JSON-RPC error code.
     *
     * @return int
     */
    public function get_rpc_code(): int {
        return $this->rpccode;
    }

    /**
     * HTTP status for the response.
     *
     * @return int
     */
    public function get_http_status(): int {
        return $this->httpstatus;
    }

    /**
     * Message for the JSON-RPC error object.
     *
     * @return string
     */
    public function get_rpc_message(): string {
        return $this->rpcmessage;
    }
}
