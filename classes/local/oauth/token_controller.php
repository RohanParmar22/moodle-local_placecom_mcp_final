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
 * Token endpoint logic.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\oauth;

use local_placecom_mcp\event\token_issued;
use local_placecom_mcp\local\client\client;
use local_placecom_mcp\local\http_response;

/**
 * Handles the `authorization_code` and `refresh_token` grants.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class token_controller {
    /** @var string Authorization code grant type. */
    public const GRANT_CODE = 'authorization_code';

    /** @var string Refresh token grant type. */
    public const GRANT_REFRESH = 'refresh_token';

    /** @var token_store Credential storage. */
    private token_store $store;

    /**
     * Create the controller.
     */
    public function __construct() {
        $this->store = new token_store();
    }

    /**
     * Handle a token request. Never throws.
     *
     * @param string $httpmethod HTTP method.
     * @param array $server Server variables (`$_SERVER`).
     * @param array $post Form body (`$_POST`).
     * @return http_response
     */
    public function handle(string $httpmethod, array $server, array $post): http_response {
        try {
            $response = $this->process($httpmethod, $server, $post);
        } catch (oauth_exception $e) {
            $response = $e->to_response();
        } catch (\Throwable $e) {
            debugging('Token endpoint failure: ' . $e->getMessage(), DEBUG_DEVELOPER, $e->getTrace());
            $response = http_response::oauth_error(500, 'server_error', self::str('err_servererror'));
        }
        return $response->with_headers(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    /**
     * Validate the request and dispatch to the grant handler.
     *
     * @param string $httpmethod HTTP method.
     * @param array $server Server variables.
     * @param array $post Form body.
     * @return http_response
     * @throws oauth_exception
     */
    private function process(string $httpmethod, array $server, array $post): http_response {
        if (strtoupper($httpmethod) !== 'POST') {
            throw new oauth_exception('invalid_request', self::str('err_methodnotallowed'), 405, ['Allow' => 'POST']);
        }
        $granttype = self::param($post, 'grant_type');
        if ($granttype === '') {
            throw new oauth_exception('invalid_request', self::str('err_granttypemissing'));
        }
        if ($granttype !== self::GRANT_CODE && $granttype !== self::GRANT_REFRESH) {
            throw new oauth_exception('unsupported_grant_type', self::str('err_granttypeunsupported'));
        }
        $client = (new client_authenticator())->authenticate($server, $post);
        if ($granttype === self::GRANT_CODE) {
            return $this->exchange_code($client, $post);
        }
        return $this->refresh($client, $post);
    }

    /**
     * The authorization code grant.
     *
     * @param client $client Authenticated client.
     * @param array $post Form body.
     * @return http_response
     * @throws oauth_exception
     */
    private function exchange_code(client $client, array $post): http_response {
        global $DB;

        $code = self::param($post, 'code');
        if ($code === '') {
            throw new oauth_exception('invalid_request', self::str('err_codemissing'));
        }
        $row = $this->store->find_code($code);
        if ($row === null || (int) $row->clientid !== $client->get_id()) {
            throw self::invalid_grant('err_codeinvalid');
        }
        if ((int) $row->timeused > 0) {
            // Replay: revoke everything issued from the first exchange (RFC 6749 section 4.1.2).
            if (!empty($row->grantid)) {
                $this->store->revoke_grant((int) $row->grantid, 'codereplay');
            }
            throw self::invalid_grant('err_codereused');
        }
        if ((int) $row->expires < time()) {
            throw self::invalid_grant('err_codeexpired');
        }
        if ($row->redirecturi !== null && self::param($post, 'redirect_uri') !== (string) $row->redirecturi) {
            throw self::invalid_grant('err_redirectmismatch');
        }

        $verifier = self::param($post, 'code_verifier');
        if ($row->codechallenge !== null && $row->codechallenge !== '') {
            if ($verifier === '') {
                throw self::invalid_grant('err_verifiermissing');
            }
            if (!pkce::is_valid_format($verifier)) {
                throw self::invalid_grant('err_verifierinvalid');
            }
            if (!pkce::verify($verifier, (string) $row->codechallenge)) {
                throw self::invalid_grant('err_verifiermismatch');
            }
        } else if ($verifier !== '') {
            throw self::invalid_grant('err_verifierunexpected');
        }

        $grantscopes = scopes::parse($row->scope);
        $issuedscopes = $this->requested_scopes($post, $grantscopes);
        $user = $this->load_user((int) $row->userid);

        $signingkey = null;
        if (in_array(scopes::OPENID, $issuedscopes, true)) {
            $signingkey = $this->signing_key();
        }

        $issuedscope = scopes::join($issuedscopes);
        $transaction = $DB->start_delegated_transaction();
        try {
            $grantid = $this->store->create_grant($client->get_id(), (int) $user->id, (string) $row->scope);
            $this->store->mark_code_used((int) $row->id, $grantid);
            $access = $this->store->issue_access_token($grantid, $client->get_id(), (int) $user->id, $issuedscope);
            $refresh = null;
            if (!in_array(scopes::OPENID, $issuedscopes, true) || in_array(scopes::OFFLINE, $issuedscopes, true)) {
                $refresh = $this->store->issue_refresh_token(
                    $grantid,
                    $client->get_id(),
                    (int) $user->id,
                    (string) $row->scope
                );
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        $body = $this->token_body($access, $issuedscope, $refresh);
        if ($signingkey !== null) {
            $now = time();
            $idclaims = [
                'iss' => config::issuer(),
                'sub' => (string) $user->id,
                'aud' => $client->get_clientid(),
                'iat' => $now,
                'exp' => $now + config::access_lifetime(),
                'auth_time' => (int) $row->authtime,
            ];
            if ($row->nonce !== null && $row->nonce !== '') {
                $idclaims['nonce'] = (string) $row->nonce;
            }
            $idclaims += claims::for_user($user, $issuedscopes);
            $body['id_token'] = jwt::encode($idclaims, $signingkey->privatekeypem, $signingkey->kid);
        }

        $this->trigger_issued($access, $grantid, $client, (int) $user->id, $issuedscope, self::GRANT_CODE);
        return http_response::json(200, $body);
    }

    /**
     * The refresh token grant (with rotation).
     *
     * @param client $client Authenticated client.
     * @param array $post Form body.
     * @return http_response
     * @throws oauth_exception
     */
    private function refresh(client $client, array $post): http_response {
        global $DB;

        $plain = self::param($post, 'refresh_token');
        if ($plain === '') {
            throw new oauth_exception('invalid_request', self::str('err_refreshmissing'));
        }
        $row = $this->store->find_refresh_token($plain);
        if ($row === null || $row->clientid !== $client->get_id()) {
            throw self::invalid_grant('err_refreshinvalid');
        }
        $grant = $this->store->get_grant($row->grantid);
        if ($grant === null) {
            throw self::invalid_grant('err_refreshinvalid');
        }
        $user = $this->load_user($row->userid);
        $issuedscopes = $this->requested_scopes($post, scopes::parse($row->scope));
        $issuedscope = scopes::join($issuedscopes);

        $transaction = $DB->start_delegated_transaction();
        try {
            $this->store->delete_refresh_token($row->id);
            $this->store->delete_access_tokens_for_grant($row->grantid);
            $access = $this->store->issue_access_token($row->grantid, $client->get_id(), $row->userid, $issuedscope);
            $refresh = $this->store->issue_refresh_token(
                $row->grantid,
                $client->get_id(),
                $row->userid,
                (string) $grant->scope
            );
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        $this->trigger_issued($access, $row->grantid, $client, (int) $user->id, $issuedscope, self::GRANT_REFRESH);
        return http_response::json(200, $this->token_body($access, $issuedscope, $refresh));
    }

    /**
     * Scopes to issue: the optional `scope` parameter, which must be a non-empty subset of the allowed scopes.
     *
     * @param array $post Form body.
     * @param string[] $allowed Scopes attached to the code or refresh token.
     * @return string[]
     * @throws oauth_exception `invalid_scope`.
     */
    private function requested_scopes(array $post, array $allowed): array {
        $requested = scopes::parse(self::param($post, 'scope'));
        if (empty($requested)) {
            return $allowed;
        }
        if (!scopes::is_subset($requested, $allowed)) {
            throw new oauth_exception('invalid_scope', self::str('err_scopenotsubset'));
        }
        return $requested;
    }

    /**
     * Load the token owner and make sure they may still use assistants.
     *
     * @param int $userid User id.
     * @return \stdClass
     * @throws oauth_exception `invalid_grant`.
     */
    private function load_user(int $userid): \stdClass {
        $user = \core_user::get_user($userid);
        if (!$user || user_guard::check($user) !== user_guard::OK || !user_guard::can_use((int) $user->id)) {
            throw self::invalid_grant('err_userinvalid');
        }
        return $user;
    }

    /**
     * The active signing key, as a server error when none is available.
     *
     * @return \stdClass
     * @throws oauth_exception `server_error` (500).
     */
    private function signing_key(): \stdClass {
        try {
            return (new key_manager())->get_signing_key();
        } catch (\moodle_exception $e) {
            debugging(get_string('error_nokey_debug', config::COMPONENT), DEBUG_DEVELOPER);
            throw new oauth_exception('server_error', self::str('err_servererror'), 500);
        }
    }

    /**
     * The token response body.
     *
     * @param array $access Result of {@see token_store::issue_access_token()}.
     * @param string $scope Issued scope.
     * @param string|null $refresh Plaintext refresh token, or null.
     * @return array
     */
    private function token_body(array $access, string $scope, ?string $refresh): array {
        $body = [
            'access_token' => $access['token'],
            'token_type' => 'Bearer',
            'expires_in' => config::access_lifetime(),
            'scope' => $scope,
        ];
        if ($refresh !== null) {
            $body['refresh_token'] = $refresh;
        }
        return $body;
    }

    /**
     * Trigger the token issued event (never carries the token itself).
     *
     * @param array $access Result of {@see token_store::issue_access_token()}.
     * @param int $grantid Grant id.
     * @param client $client Client.
     * @param int $userid Token owner.
     * @param string $scope Issued scope.
     * @param string $granttype Grant type used.
     */
    private function trigger_issued(
        array $access,
        int $grantid,
        client $client,
        int $userid,
        string $scope,
        string $granttype
    ): void {
        token_issued::create([
            'objectid' => $access['id'],
            'userid' => $userid,
            'relateduserid' => $userid,
            'context' => \context_system::instance(),
            'other' => [
                'clientid' => $client->get_clientid(),
                'scope' => $scope,
                'grantid' => $grantid,
                'granttype' => $granttype,
                'expires' => $access['expires'],
            ],
        ])->trigger();
    }

    /**
     * An `invalid_grant` error.
     *
     * @param string $identifier Description string identifier.
     * @return oauth_exception
     */
    private static function invalid_grant(string $identifier): oauth_exception {
        return new oauth_exception('invalid_grant', self::str($identifier));
    }

    /**
     * A string request parameter, '' when missing or not a string.
     *
     * @param array $params Parameters.
     * @param string $name Parameter name.
     * @return string
     */
    private static function param(array $params, string $name): string {
        return isset($params[$name]) && is_string($params[$name]) ? $params[$name] : '';
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
