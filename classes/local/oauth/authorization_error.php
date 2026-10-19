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
 * Authorization endpoint error.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\oauth;

use local_placecom_mcp\local\http_response;

/**
 * An authorization endpoint error. Once the redirect URI is trusted the error is sent back to the client
 * by redirect (RFC 6749 section 4.1.2.1); before that it is a 400 JSON response.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class authorization_error extends oauth_exception {
    /** @var string|null Trusted redirect URI, or null when none is known. */
    protected ?string $redirecturi;

    /** @var string|null State value from the request. */
    protected ?string $state;

    /**
     * Create the error.
     *
     * @param string $error OAuth error code.
     * @param string $description Human readable description.
     * @param string|null $redirecturi Trusted redirect URI, or null when the error cannot be redirected.
     * @param string|null $state State value to echo.
     */
    public function __construct(string $error, string $description, ?string $redirecturi = null, ?string $state = null) {
        $this->redirecturi = $redirecturi;
        $this->state = $state;
        parent::__construct($error, $description, 400);
    }

    /**
     * Whether the error is delivered by redirect.
     *
     * @return bool
     */
    public function is_redirectable(): bool {
        return $this->redirecturi !== null && $this->redirecturi !== '';
    }

    /**
     * The redirect URI the error is sent to, if any.
     *
     * @return string|null
     */
    public function get_redirect_uri(): ?string {
        return $this->redirecturi;
    }

    /**
     * Redirect to the client with the error, or a 400 JSON response when no redirect URI is trusted.
     *
     * @return http_response
     */
    public function to_response(): http_response {
        if (!$this->is_redirectable()) {
            return parent::to_response();
        }
        return http_response::redirect(http_response::build_url($this->redirecturi, [
            'error' => $this->get_error(),
            'error_description' => $this->get_description(),
            'state' => $this->state,
            'iss' => config::issuer(),
        ]));
    }
}
