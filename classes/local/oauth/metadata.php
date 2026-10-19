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
 * Public metadata documents.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\oauth;

use local_placecom_mcp\local\http_response;

/**
 * Builds the discovery, protected resource and JWKS documents.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class metadata {
    /**
     * OpenID Provider / OAuth authorization server metadata.
     *
     * @return array
     */
    public static function discovery(): array {
        return [
            'issuer' => config::issuer(),
            'authorization_endpoint' => config::endpoint_url('authorize.php'),
            'token_endpoint' => config::endpoint_url('token.php'),
            'userinfo_endpoint' => config::endpoint_url('userinfo.php'),
            'jwks_uri' => config::endpoint_url('jwks.php'),
            'scopes_supported' => scopes::ALL,
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
            'claims_supported' => claims::SUPPORTED,
            'code_challenge_methods_supported' => [pkce::METHOD],
            'claims_parameter_supported' => false,
            'request_parameter_supported' => false,
            'request_uri_parameter_supported' => false,
            'authorization_response_iss_parameter_supported' => true,
        ];
    }

    /**
     * RFC 9728 protected resource metadata for the MCP endpoint.
     *
     * @return array
     */
    public static function protected_resource(): array {
        return [
            'resource' => config::mcp_resource_url(),
            'authorization_servers' => [config::issuer()],
            'scopes_supported' => [scopes::READ, scopes::WRITE],
            'bearer_methods_supported' => ['header'],
            'resource_name' => get_string('pluginname', config::COMPONENT),
        ];
    }

    /**
     * JSON Web Key Set.
     *
     * @return array
     */
    public static function jwks(): array {
        return (new key_manager())->get_jwks();
    }

    /**
     * Wrap a document builder into a cacheable, CORS-enabled JSON response.
     *
     * @param callable $builder Function returning the document array.
     * @return http_response 200 with the document, or 500 `server_error` on any failure.
     */
    public static function respond(callable $builder): http_response {
        $cors = ['Access-Control-Allow-Origin' => '*'];
        try {
            $data = $builder();
            return http_response::json(200, $data, ['Cache-Control' => 'public, max-age=300'] + $cors);
        } catch (\Throwable $e) {
            debugging('Metadata endpoint failure: ' . $e->getMessage(), DEBUG_DEVELOPER, $e->getTrace());
            return http_response::oauth_error(500, 'server_error', get_string('err_servererror', config::COMPONENT), $cors);
        }
    }
}
