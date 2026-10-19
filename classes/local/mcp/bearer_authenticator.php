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
 * Bearer token authentication for the MCP endpoint.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\mcp;

use local_placecom_mcp\event\mcp_access_refused;
use local_placecom_mcp\local\oauth\config;
use local_placecom_mcp\local\oauth\scopes;
use local_placecom_mcp\local\oauth\token_store;
use local_placecom_mcp\local\oauth\user_guard;

/**
 * Reads the bearer token of an MCP request and turns it into an authenticated caller.
 *
 * Only access tokens issued by this plugin's own authorization server are accepted; Moodle web
 * service tokens are never consulted. The token is read from the Authorization header only, never
 * from the query string or the request body.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bearer_authenticator {
    /** @var string Accepted Authorization header shape (RFC 6750 section 2.1). */
    public const HEADER_PATTERN = '/^Bearer\s+([A-Za-z0-9\-._~+\/]+=*)$/i';

    /** @var token_store Token storage. */
    protected token_store $tokenstore;

    /**
     * Constructor.
     *
     * @param token_store|null $tokenstore Token storage; a new instance when null.
     */
    public function __construct(?token_store $tokenstore = null) {
        $this->tokenstore = $tokenstore ?? new token_store();
    }

    /**
     * Extract the bearer token from the request headers.
     *
     * Sources, in order: the header array (for example from getallheaders(), matched case-insensitively),
     * then HTTP_AUTHORIZATION and REDIRECT_HTTP_AUTHORIZATION from the server variables (CGI/FPM).
     *
     * @param array $server Server variables ($_SERVER).
     * @param array|null $headers Request headers keyed by name, or null when unavailable.
     * @return string|null The token, or null when no well-formed bearer credential was sent.
     */
    public static function extract_token(array $server, ?array $headers = null): ?string {
        $candidates = [];
        if ($headers !== null) {
            foreach ($headers as $name => $value) {
                if (is_string($name) && strcasecmp($name, 'Authorization') === 0 && is_string($value)) {
                    $candidates[] = $value;
                }
            }
        }
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (isset($server[$key]) && is_string($server[$key])) {
                $candidates[] = $server[$key];
            }
        }
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            if (preg_match(self::HEADER_PATTERN, $candidate, $matches)) {
                return $matches[1];
            }
            // An Authorization header with another scheme is not a bearer credential.
            return null;
        }
        return null;
    }

    /**
     * Authenticate a request and, optionally, log the token owner in for this request.
     *
     * @param string|null $token Bearer token, or null when none was presented.
     * @param bool $login Whether to make the token owner the current user (false in some unit tests).
     * @return auth_context
     * @throws auth_exception When the request must be refused.
     */
    public function authenticate(?string $token, bool $login = true): auth_context {
        global $DB;

        if ($token === null || $token === '') {
            throw new auth_exception(
                401,
                jsonrpc::UNAUTHORIZED,
                self::str('error_unauthorized'),
                self::challenge()
            );
        }

        $row = $this->tokenstore->find_access_token($token);
        if ($row === null) {
            throw new auth_exception(
                401,
                jsonrpc::UNAUTHORIZED,
                self::str('error_unauthorized'),
                self::challenge('invalid_token')
            );
        }

        $user = $DB->get_record('user', ['id' => $row->userid]);
        $reason = $user ? user_guard::check($user) : user_guard::DELETED;
        if ($reason !== user_guard::OK) {
            $this->refuse((int) $row->userid, (string) $row->clientidentifier, $reason);
            if ($reason === user_guard::MAINTENANCE) {
                throw new auth_exception(503, jsonrpc::FORBIDDEN, self::str('error_maintenance'));
            }
            throw new auth_exception(
                401,
                jsonrpc::UNAUTHORIZED,
                self::str('error_unauthorized'),
                self::challenge('invalid_token')
            );
        }

        $context = new auth_context($user, $row);
        if (!$context->has_scope(scopes::READ)) {
            $header = 'Bearer error="insufficient_scope", scope="' . scopes::READ . '", resource_metadata="' .
                config::protected_resource_metadata_url() . '"';
            throw new auth_exception(403, jsonrpc::FORBIDDEN, self::str('error_insufficientscope'), $header);
        }

        if ($login) {
            \core\session\manager::set_user($user);
            set_login_session_preferences();
            enrol_check_plugins($user, false);
        }

        if (!user_guard::can_use((int) $user->id)) {
            throw new auth_exception(403, jsonrpc::FORBIDDEN, self::str('error_nocapability'));
        }

        $this->tokenstore->touch_grant((int) $row->grantid);
        return $context;
    }

    /**
     * Build the WWW-Authenticate challenge pointing clients at the protected resource metadata.
     *
     * @param string|null $error Optional RFC 6750 error code.
     * @return string
     */
    public static function challenge(?string $error = null): string {
        $header = 'Bearer resource_metadata="' . config::protected_resource_metadata_url() . '"';
        if ($error !== null) {
            $header .= ', error="' . $error . '"';
        }
        return $header;
    }

    /**
     * Log a refusal of a valid token whose owner may not use Moodle right now.
     *
     * @param int $userid Token owner.
     * @param string $clientid Public client identifier.
     * @param string $reason Reason constant from user_guard.
     */
    protected function refuse(int $userid, string $clientid, string $reason): void {
        mcp_access_refused::create([
            'userid' => $userid,
            'context' => \context_system::instance(),
            'other' => ['clientid' => $clientid, 'reason' => $reason],
        ])->trigger();
    }

    /**
     * Fetch a plugin string.
     *
     * @param string $identifier String identifier.
     * @return string
     */
    protected static function str(string $identifier): string {
        return get_string($identifier, 'local_placecom_mcp');
    }
}
