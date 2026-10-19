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
 * Test data generator.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_placecom_mcp\local\oauth\consent_store;
use local_placecom_mcp\local\oauth\pkce;
use local_placecom_mcp\local\oauth\token_store;

/**
 * Creates clients, consents, grants and authorization codes for tests.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_placecom_mcp_generator extends component_generator_base {
    /** @var string Scope given to clients when none is specified. */
    public const DEFAULT_SCOPE = 'openid profile email offline_access moodle_mcp_read';

    /** @var int Number of clients created, used for default identifiers. */
    protected int $clientcount = 0;

    /**
     * Reset the counters between tests.
     */
    public function reset() {
        $this->clientcount = 0;
    }

    /**
     * Create a client by direct database insert.
     *
     * Pass `secret => ''` for a public client; by default a random secret is generated. The returned record
     * carries the plaintext secret in `plainsecret` (null for public clients).
     *
     * @param array $record Field values.
     * @return stdClass
     */
    public function create_client(array $record = []): stdClass {
        global $DB;
        $this->clientcount++;
        $secret = array_key_exists('secret', $record) ? (string) $record['secret'] : bin2hex(random_bytes(24));
        unset($record['secret']);
        $now = time();
        $data = (object) array_merge([
            'clientid' => 'client' . $this->clientcount,
            'name' => '',
            'redirecturis' => 'https://example.com/callback',
            'scope' => self::DEFAULT_SCOPE,
            'requirepkce' => 1,
            'enabled' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => 0,
        ], $record);
        $data->secrethash = $secret === '' ? '' : password_hash($secret, PASSWORD_DEFAULT);
        $data->secrethint = $secret === '' ? null : substr($secret, -4);
        $data->id = $DB->insert_record('local_placecom_mcp_client', $data);
        $data->plainsecret = $secret === '' ? null : $secret;
        return $data;
    }

    /**
     * Create a consent.
     *
     * @param array $record Requires `clientid` (row id) and `userid`; optional `scope`.
     * @return stdClass
     */
    public function create_consent(array $record): stdClass {
        global $DB;
        $this->require_keys($record, ['clientid', 'userid']);
        $scope = $record['scope'] ?? $this->client_scope((int) $record['clientid']);
        $id = (new consent_store())->save((int) $record['clientid'], (int) $record['userid'], $scope);
        return $DB->get_record('local_placecom_mcp_consent', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Create a grant with an access token and (by default) a refresh token.
     *
     * @param array $record Requires `clientid` (row id) and `userid`; optional `scope`, `accessexpires`,
     *      `refresh` (bool), `refreshexpires`.
     * @return stdClass Grant record plus `accesstoken`, `accesstokenid` and `refreshtoken` (plaintext or null).
     */
    public function create_grant(array $record): stdClass {
        global $DB;
        $this->require_keys($record, ['clientid', 'userid']);
        $clientid = (int) $record['clientid'];
        $userid = (int) $record['userid'];
        $scope = $record['scope'] ?? $this->client_scope($clientid);
        $store = new token_store();

        $grantid = $store->create_grant($clientid, $userid, $scope);
        $access = $store->issue_access_token($grantid, $clientid, $userid, $scope);
        if (isset($record['accessexpires'])) {
            $DB->set_field('local_placecom_mcp_token', 'expires', (int) $record['accessexpires'], ['id' => $access['id']]);
        }
        $refresh = null;
        if ($record['refresh'] ?? true) {
            $refresh = $store->issue_refresh_token($grantid, $clientid, $userid, $scope);
            if (isset($record['refreshexpires'])) {
                $DB->set_field(
                    'local_placecom_mcp_refresh',
                    'expires',
                    (int) $record['refreshexpires'],
                    ['grantid' => $grantid]
                );
            }
        }

        $grant = $DB->get_record('local_placecom_mcp_grant', ['id' => $grantid], '*', MUST_EXIST);
        $grant->accesstoken = $access['token'];
        $grant->accesstokenid = $access['id'];
        $grant->refreshtoken = $refresh;
        return $grant;
    }

    /**
     * Create an authorization code.
     *
     * @param array $record Requires `clientid` (row id) and `userid`; optional `redirecturi`, `scope`, `nonce`,
     *      `codeverifier` (stores its S256 challenge), `authtime`, `expires`.
     * @return stdClass Code row plus `code` (plaintext).
     */
    public function create_code(array $record): stdClass {
        global $DB;
        $this->require_keys($record, ['clientid', 'userid']);
        $clientid = (int) $record['clientid'];
        $challenge = isset($record['codeverifier']) ? pkce::challenge_for((string) $record['codeverifier']) : null;
        $code = (new token_store())->create_code(
            $clientid,
            (int) $record['userid'],
            $record['redirecturi'] ?? null,
            $record['scope'] ?? $this->client_scope($clientid),
            $record['nonce'] ?? null,
            $challenge,
            (int) ($record['authtime'] ?? time())
        );
        $row = (new token_store())->find_code($code);
        if (isset($record['expires'])) {
            $DB->set_field('local_placecom_mcp_authcode', 'expires', (int) $record['expires'], ['id' => $row->id]);
            $row->expires = (int) $record['expires'];
        }
        $row->code = $code;
        return $row;
    }

    /**
     * Registered scope of a client.
     *
     * @param int $clientid Client row id.
     * @return string
     */
    protected function client_scope(int $clientid): string {
        global $DB;
        return (string) $DB->get_field('local_placecom_mcp_client', 'scope', ['id' => $clientid], MUST_EXIST);
    }

    /**
     * Make sure the record has the given keys.
     *
     * @param array $record Record.
     * @param string[] $keys Required keys.
     * @throws coding_exception
     */
    protected function require_keys(array $record, array $keys): void {
        foreach ($keys as $key) {
            if (!isset($record[$key])) {
                throw new coding_exception("The '{$key}' value is required.");
            }
        }
    }
}
