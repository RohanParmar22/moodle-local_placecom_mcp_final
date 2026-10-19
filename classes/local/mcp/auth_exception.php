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
 * Authentication or authorisation failure at the MCP endpoint.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\mcp;

use local_placecom_mcp\local\http_response;

/**
 * Raised when a request cannot be authenticated (401), is not allowed (403) or the site is
 * temporarily unavailable (503).
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class auth_exception extends \moodle_exception {
    /** @var int HTTP status. */
    protected int $httpstatus;

    /** @var int JSON-RPC error code. */
    protected int $rpccode;

    /** @var string Human-readable message. */
    protected string $rpcmessage;

    /** @var string|null Value of the WWW-Authenticate header, if any. */
    protected ?string $wwwauthenticate;

    /**
     * Constructor.
     *
     * @param int $httpstatus HTTP status (401, 403 or 503).
     * @param int $rpccode JSON-RPC error code.
     * @param string $message Human-readable, already localised message.
     * @param string|null $wwwauthenticate Value for the WWW-Authenticate header, or null for none.
     */
    public function __construct(int $httpstatus, int $rpccode, string $message, ?string $wwwauthenticate = null) {
        $this->httpstatus = $httpstatus;
        $this->rpccode = $rpccode;
        $this->rpcmessage = $message;
        $this->wwwauthenticate = $wwwauthenticate;
        parent::__construct('error_mcp', 'local_placecom_mcp', '', $message);
    }

    /**
     * HTTP status.
     *
     * @return int
     */
    public function get_http_status(): int {
        return $this->httpstatus;
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
     * Message for the JSON-RPC error object.
     *
     * @return string
     */
    public function get_rpc_message(): string {
        return $this->rpcmessage;
    }

    /**
     * Value of the WWW-Authenticate header, if any.
     *
     * @return string|null
     */
    public function get_www_authenticate(): ?string {
        return $this->wwwauthenticate;
    }

    /**
     * Build the HTTP response: a JSON-RPC error with a null id, plus WWW-Authenticate when set.
     *
     * @return http_response
     */
    public function to_response(): http_response {
        $headers = ['Content-Type' => 'application/json; charset=utf-8'];
        if ($this->wwwauthenticate !== null) {
            $headers['WWW-Authenticate'] = $this->wwwauthenticate;
        }
        if ($this->httpstatus === 503) {
            $headers['Retry-After'] = '300';
        }
        $body = jsonrpc::encode(jsonrpc::error(null, $this->rpccode, $this->rpcmessage));
        return new http_response($this->httpstatus, $headers, $body);
    }
}
