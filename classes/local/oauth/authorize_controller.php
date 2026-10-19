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
 * Authorization endpoint logic.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\oauth;

use core_text;
use local_placecom_mcp\event\authorization_denied;
use local_placecom_mcp\event\authorization_granted;
use local_placecom_mcp\local\client\client_manager;
use local_placecom_mcp\local\http_response;

/**
 * Validates authorization requests, checks the user, and approves or denies them.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class authorize_controller {
    /** @var string[] Request parameters read by the endpoint. */
    public const PARAMS = [
        'client_id',
        'response_type',
        'redirect_uri',
        'scope',
        'state',
        'code_challenge',
        'code_challenge_method',
        'nonce',
        'prompt',
    ];

    /** @var int Maximum stored nonce length. */
    public const MAX_NONCE = 255;

    /**
     * Read the authorization parameters from GET or POST. Missing or non-string values become ''.
     *
     * @return array
     */
    public static function read_params(): array {
        $params = [];
        foreach (self::PARAMS as $name) {
            try {
                $value = optional_param($name, '', PARAM_RAW);
            } catch (\Throwable $e) {
                $value = '';
            }
            $params[$name] = is_string($value) ? $value : '';
        }
        return $params;
    }

    /**
     * Validate an authorization request.
     *
     * @param array $params Raw parameters as returned by {@see read_params()}.
     * @return authorization_request
     * @throws authorization_error
     */
    public function validate(array $params): authorization_request {
        $param = static function (string $name) use ($params): string {
            return isset($params[$name]) && is_string($params[$name]) ? $params[$name] : '';
        };

        // 1. Client.
        $clientid = $param('client_id');
        if ($clientid === '') {
            throw new authorization_error('invalid_request', self::str('err_clientidmissing'));
        }
        $client = (new client_manager())->get_by_clientid($clientid);
        if ($client === null || !$client->is_enabled()) {
            throw new authorization_error('invalid_client', self::str('err_unknownclient'));
        }

        // 2. Redirect URI. Until it is trusted, errors cannot be redirected.
        $supplied = $param('redirect_uri');
        if ($supplied !== '') {
            if (strpos($supplied, '#') !== false) {
                throw new authorization_error('invalid_request', self::str('err_redirecturifragment'));
            }
            if (!$client->has_redirect_uri($supplied)) {
                throw new authorization_error('invalid_request', self::str('err_redirecturimismatch'));
            }
            $redirecturi = $supplied;
        } else {
            $registered = $client->get_redirect_uris();
            if (count($registered) !== 1) {
                throw new authorization_error('invalid_request', self::str('err_redirecturimissing'));
            }
            $redirecturi = (string) reset($registered);
        }
        $state = $param('state') === '' ? null : $param('state');
        $fail = static function (string $error, string $identifier) use ($redirecturi, $state): authorization_error {
            return new authorization_error($error, self::str($identifier), $redirecturi, $state);
        };

        // 3. Response type.
        $responsetype = $param('response_type');
        if ($responsetype === '') {
            throw $fail('invalid_request', 'err_responsetypemissing');
        }
        if ($responsetype !== 'code') {
            throw $fail('unsupported_response_type', 'err_responsetypeunsupported');
        }

        // 4. Scope: grant what is registered.
        $requested = scopes::parse($param('scope'));
        $granted = empty($requested) ? $client->get_scopes() : scopes::narrow($requested, $client->get_scopes());
        if (empty($granted)) {
            throw $fail('invalid_scope', 'err_scopeinvalid');
        }

        // 5. State is mandatory for OpenID Connect requests.
        if (in_array(scopes::OPENID, $granted, true) && $state === null) {
            throw $fail('invalid_request', 'err_staterequired');
        }

        // 6. PKCE (S256 only).
        $challenge = $param('code_challenge');
        if ($challenge !== '') {
            if ($param('code_challenge_method') !== pkce::METHOD) {
                throw $fail('invalid_request', 'err_pkcemethod');
            }
            if (!pkce::is_valid_format($challenge)) {
                throw $fail('invalid_request', 'err_pkcechallengeformat');
            }
        } else if ($client->requires_pkce() || $client->is_public()) {
            throw $fail('invalid_request', 'err_pkcerequired');
        }

        // 7. Prompt.
        $prompts = scopes::parse($param('prompt'));
        if (in_array('none', $prompts, true) && count($prompts) > 1) {
            throw $fail('invalid_request', 'err_promptinvalid');
        }

        // 8. Nonce.
        $nonce = $param('nonce');
        if (core_text::strlen($nonce) > self::MAX_NONCE) {
            throw $fail('invalid_request', 'err_nonce');
        }

        return new authorization_request(
            $client,
            $redirecturi,
            $supplied === '' ? null : $supplied,
            $granted,
            $state,
            $challenge === '' ? null : $challenge,
            $nonce === '' ? null : $nonce,
            $prompts,
            $params
        );
    }

    /**
     * Refuse users whose account state or permissions do not allow connecting assistants.
     *
     * @param authorization_request $req Validated request.
     * @param \stdClass $user Logged in user.
     * @throws authorization_error Redirectable `access_denied`.
     */
    public function check_user(authorization_request $req, \stdClass $user): void {
        if (user_guard::check($user) !== user_guard::OK) {
            throw new authorization_error(
                'access_denied',
                self::str('err_accessdenied'),
                $req->get_redirect_uri(),
                $req->get_state()
            );
        }
        if (!user_guard::can_use((int) $user->id)) {
            throw new authorization_error(
                'access_denied',
                self::str('err_nopermission'),
                $req->get_redirect_uri(),
                $req->get_state()
            );
        }
    }

    /**
     * Whether the consent screen must be shown.
     *
     * @param authorization_request $req Validated request.
     * @param \stdClass $user Logged in user.
     * @return bool
     */
    public function needs_consent(authorization_request $req, \stdClass $user): bool {
        if ($req->has_prompt('consent')) {
            return true;
        }
        return !(new consent_store())->has_consent($req->get_client()->get_id(), (int) $user->id, $req->get_scopes());
    }

    /**
     * Approve the request: issue an authorization code and redirect back to the client.
     *
     * @param authorization_request $req Validated request.
     * @param \stdClass $user Approving user.
     * @param bool $consentshown Whether the user just confirmed the consent screen.
     * @return http_response 302 redirect carrying `code`, `state` and `iss`.
     */
    public function approve(authorization_request $req, \stdClass $user, bool $consentshown): http_response {
        $client = $req->get_client();
        $consents = new consent_store();
        if ($consentshown) {
            $consentid = $consents->save($client->get_id(), (int) $user->id, $req->get_scope());
        } else {
            $existing = $consents->get($client->get_id(), (int) $user->id);
            $consentid = $existing ? (int) $existing->id : null;
        }

        $authtime = !empty($user->currentlogin) ? (int) $user->currentlogin : time();
        $code = (new token_store())->create_code(
            $client->get_id(),
            (int) $user->id,
            $req->get_supplied_redirect_uri(),
            $req->get_scope(),
            $req->get_nonce(),
            $req->get_code_challenge(),
            $authtime
        );

        authorization_granted::create([
            'objectid' => $consentid,
            'userid' => (int) $user->id,
            'relateduserid' => (int) $user->id,
            'context' => \context_system::instance(),
            'other' => [
                'clientid' => $client->get_clientid(),
                'scope' => $req->get_scope(),
                'consentshown' => $consentshown,
            ],
        ])->trigger();

        return http_response::redirect(http_response::build_url($req->get_redirect_uri(), [
            'code' => $code,
            'state' => $req->get_state(),
            'iss' => config::issuer(),
        ]));
    }

    /**
     * Deny the request and redirect the error back to the client.
     *
     * @param authorization_request $req Validated request.
     * @param \stdClass $user Current user (id 0 when not logged in).
     * @param string $error OAuth error code: `access_denied`, `consent_required` or `login_required`.
     * @return http_response 302 redirect carrying `error`, `error_description`, `state` and `iss`.
     */
    public function deny(authorization_request $req, \stdClass $user, string $error = 'access_denied'): http_response {
        $descriptions = [
            'access_denied' => 'err_accessdenied',
            'consent_required' => 'err_consentrequired',
            'login_required' => 'err_loginrequired',
        ];
        $identifier = $descriptions[$error] ?? 'err_accessdenied';

        authorization_denied::create([
            'userid' => (int) ($user->id ?? 0),
            'context' => \context_system::instance(),
            'other' => [
                'clientid' => $req->get_client()->get_clientid(),
                'scope' => $req->get_scope(),
                'error' => $error,
            ],
        ])->trigger();

        $exception = new authorization_error($error, self::str($identifier), $req->get_redirect_uri(), $req->get_state());
        return $exception->to_response();
    }

    /**
     * Log a failed authorization request (only when a client id was given).
     *
     * @param array $params Raw request parameters.
     * @param authorization_error $e The error.
     * @param int|null $userid Current user id, or null when not logged in.
     */
    public static function log_failure(array $params, authorization_error $e, ?int $userid): void {
        $clientid = isset($params['client_id']) && is_string($params['client_id']) ? $params['client_id'] : '';
        if ($clientid === '') {
            return;
        }
        $scope = isset($params['scope']) && is_string($params['scope']) ? $params['scope'] : '';
        authorization_denied::create([
            'userid' => (int) ($userid ?? 0),
            'context' => \context_system::instance(),
            'other' => [
                'clientid' => core_text::substr($clientid, 0, 100),
                'scope' => core_text::substr($scope, 0, 1333),
                'error' => $e->get_error(),
            ],
        ])->trigger();
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
