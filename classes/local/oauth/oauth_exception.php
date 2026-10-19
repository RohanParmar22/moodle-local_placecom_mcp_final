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
 * OAuth protocol error.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\oauth;

use local_placecom_mcp\local\http_response;

/**
 * An OAuth error with its error code, description, HTTP status and extra response headers.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class oauth_exception extends \moodle_exception {
    /** @var string OAuth error code, for example `invalid_grant`. */
    protected string $oautherror;

    /** @var string Human readable description. */
    protected string $description;

    /** @var int HTTP status code. */
    protected int $status;

    /** @var array Extra response headers. */
    protected array $headers;

    /**
     * Create the exception.
     *
     * @param string $error OAuth error code.
     * @param string $description Human readable description.
     * @param int $status HTTP status code.
     * @param array $headers Extra response headers keyed by name.
     */
    public function __construct(string $error, string $description, int $status = 400, array $headers = []) {
        $this->oautherror = $error;
        $this->description = $description;
        $this->status = $status;
        $this->headers = $headers;
        parent::__construct('oautherror', config::COMPONENT, '', $error, $description);
    }

    /**
     * OAuth error code.
     *
     * @return string
     */
    public function get_error(): string {
        return $this->oautherror;
    }

    /**
     * Error description.
     *
     * @return string
     */
    public function get_description(): string {
        return $this->description;
    }

    /**
     * HTTP status code.
     *
     * @return int
     */
    public function get_status(): int {
        return $this->status;
    }

    /**
     * Extra response headers.
     *
     * @return array
     */
    public function get_headers(): array {
        return $this->headers;
    }

    /**
     * The JSON error response for this error.
     *
     * @return http_response
     */
    public function to_response(): http_response {
        return http_response::oauth_error($this->status, $this->oautherror, $this->description, $this->headers);
    }
}
