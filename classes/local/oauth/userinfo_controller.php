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
 * OpenID Connect UserInfo endpoint logic.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\oauth;

use local_placecom_mcp\local\http_response;

/**
 * Returns the claims of the token owner for a bearer token carrying the `openid` scope.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class userinfo_controller {
    /**
     * Handle a UserInfo request. Never throws.
     *
     * The token is accepted from the Authorization header or, for POST, the form body (RFC 6750 sections
     * 2.1 and 2.2). The query string is never read.
     *
     * @param string $httpmethod HTTP method.
     * @param array $server Server variables (`$_SERVER`).
     * @param array $post Form body (`$_POST`).
     * @return http_response
     */
    public function handle(string $httpmethod, array $server, array $post): http_response {
        try {
            $response = $this->process(strtoupper($httpmethod), $server, $post);
        } catch (\Throwable $e) {
            debugging('UserInfo endpoint failure: ' . $e->getMessage(), DEBUG_DEVELOPER, $e->getTrace());
            $response = http_response::oauth_error(500, 'server_error', self::str('err_servererror'));
        }
        return $response->with_headers(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    /**
     * Process the request.
     *
     * @param string $method Upper-case HTTP method.
     * @param array $server Server variables.
     * @param array $post Form body.
     * @return http_response
     */
    private function process(string $method, array $server, array $post): http_response {
        if ($method !== 'GET' && $method !== 'POST') {
            return http_response::oauth_error(
                405,
                'invalid_request',
                self::str('err_methodnotallowed'),
                ['Allow' => 'GET, POST']
            );
        }

        $headertoken = self::header_token($server);
        $bodytoken = '';
        if ($method === 'POST' && isset($post['access_token']) && is_string($post['access_token'])) {
            $bodytoken = $post['access_token'];
        }
        if ($headertoken !== null && $bodytoken !== '') {
            return http_response::oauth_error(
                400,
                'invalid_request',
                self::str('err_multipletokens'),
                ['WWW-Authenticate' => self::challenge('invalid_request')]
            );
        }
        $token = $headertoken ?? $bodytoken;
        if ($token === '') {
            return http_response::oauth_error(
                401,
                'invalid_token',
                self::str('err_tokenmissing'),
                ['WWW-Authenticate' => self::challenge(null)]
            );
        }

        $row = (new token_store())->find_access_token($token);
        $user = $row ? \core_user::get_user($row->userid) : false;
        if (!$row || !$user || user_guard::check($user) !== user_guard::OK) {
            return http_response::oauth_error(
                401,
                'invalid_token',
                self::str('err_tokeninvalid'),
                ['WWW-Authenticate' => self::challenge('invalid_token')]
            );
        }

        $scopes = scopes::parse($row->scope);
        if (!in_array(scopes::OPENID, $scopes, true)) {
            return http_response::oauth_error(
                403,
                'insufficient_scope',
                self::str('err_insufficientscope'),
                ['WWW-Authenticate' => self::challenge('insufficient_scope') . ', scope="openid"']
            );
        }

        $data = ['sub' => (string) $user->id] + claims::for_user($user, $scopes);
        return http_response::json(200, $data);
    }

    /**
     * Bearer token from the Authorization header, or null when there is none.
     *
     * @param array $server Server variables.
     * @return string|null
     */
    private static function header_token(array $server): ?string {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (empty($server[$key]) || !is_string($server[$key])) {
                continue;
            }
            if (preg_match('/^Bearer\s+([A-Za-z0-9\-._~+\/]+=*)$/i', trim($server[$key]), $matches)) {
                return $matches[1];
            }
        }
        return null;
    }

    /**
     * WWW-Authenticate challenge value.
     *
     * @param string|null $error Error code, or null when the request carried no credentials.
     * @return string
     */
    private static function challenge(?string $error): string {
        $value = 'Bearer realm="' . config::issuer() . '"';
        if ($error !== null) {
            $value .= ', error="' . $error . '"';
        }
        return $value;
    }

    /**
     * A string of this plugin.
     *
     * @param string $identifier String identifier.
     * @return string
     */
    private static function str(string $identifier): string {
        return get_string($identifier, config::COMPONENT);
    }
}
