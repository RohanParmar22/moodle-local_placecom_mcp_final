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
 * Token endpoint client authentication.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\oauth;

use local_placecom_mcp\local\client\client;
use local_placecom_mcp\local\client\client_manager;

/**
 * Authenticates clients with `client_secret_basic`, `client_secret_post` or `none` (public clients).
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class client_authenticator {
    /**
     * Authenticate the client of a token request.
     *
     * @param array $server Server variables (`$_SERVER`).
     * @param array $post Form body (`$_POST`).
     * @return client
     * @throws oauth_exception `invalid_request` or `invalid_client`.
     */
    public function authenticate(array $server, array $post): client {
        $basic = self::read_basic_credentials($server);
        $postid = self::string_param($post, 'client_id');
        $postsecret = self::string_param($post, 'client_secret');

        if ($basic !== null) {
            // A body client_id that repeats the Basic identity is tolerated; a second credential is not.
            if ($postsecret !== '' || ($postid !== '' && $postid !== $basic[0])) {
                throw new oauth_exception('invalid_request', get_string('err_multipleauth', config::COMPONENT));
            }
            [$clientid, $secret] = $basic;
        } else {
            $clientid = $postid;
            $secret = $postsecret;
        }

        if ($clientid === '') {
            throw $this->failure($basic !== null);
        }

        $client = (new client_manager())->get_by_clientid($clientid);
        if ($client === null || !$client->is_enabled()) {
            throw $this->failure($basic !== null);
        }
        if ($client->is_public()) {
            if ($secret !== '') {
                throw $this->failure($basic !== null);
            }
        } else if ($secret === '' || !$client->verify_secret($secret)) {
            throw $this->failure($basic !== null);
        }
        return $client;
    }

    /**
     * Build the `invalid_client` error; 401 with a Basic challenge when Basic authentication was attempted.
     *
     * @param bool $basicused Whether the client used the Authorization header.
     * @return oauth_exception
     */
    private function failure(bool $basicused): oauth_exception {
        $description = get_string('err_clientauth', config::COMPONENT);
        if ($basicused) {
            return new oauth_exception(
                'invalid_client',
                $description,
                401,
                ['WWW-Authenticate' => 'Basic realm="' . config::issuer() . '"']
            );
        }
        return new oauth_exception('invalid_client', $description, 400);
    }

    /**
     * Credentials from HTTP Basic authentication, or null when the request does not use it.
     *
     * Both parts are form-urlencoded before base64 encoding (RFC 6749 section 2.3.1), so they are decoded.
     *
     * @param array $server Server variables.
     * @return string[]|null `[clientid, secret]`
     */
    private static function read_basic_credentials(array $server): ?array {
        if (isset($server['PHP_AUTH_USER']) && is_string($server['PHP_AUTH_USER'])) {
            $password = isset($server['PHP_AUTH_PW']) && is_string($server['PHP_AUTH_PW']) ? $server['PHP_AUTH_PW'] : '';
            return [urldecode($server['PHP_AUTH_USER']), urldecode($password)];
        }
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (empty($server[$key]) || !is_string($server[$key])) {
                continue;
            }
            $header = trim($server[$key]);
            if (!preg_match('/^Basic\s+(\S+)$/i', $header, $matches)) {
                continue;
            }
            $decoded = base64_decode($matches[1], true);
            if ($decoded === false || strpos($decoded, ':') === false) {
                // Basic was attempted but is unusable: an empty identity yields a 401 invalid_client.
                return ['', ''];
            }
            [$id, $secret] = explode(':', $decoded, 2);
            return [urldecode($id), urldecode($secret)];
        }
        return null;
    }

    /**
     * A string request parameter, '' when missing or not a string.
     *
     * @param array $params Parameters.
     * @param string $name Parameter name.
     * @return string
     */
    private static function string_param(array $params, string $name): string {
        return isset($params[$name]) && is_string($params[$name]) ? $params[$name] : '';
    }
}
