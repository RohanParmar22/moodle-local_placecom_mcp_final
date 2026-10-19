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
 * Storage of authorization codes, grants, access tokens and refresh tokens.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\oauth;

use local_placecom_mcp\event\grant_revoked;

/**
 * All database access for credentials. Only SHA-256 hashes are stored; plaintext values are only returned.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class token_store {
    /** @var string Authorization code table. */
    public const CODE_TABLE = 'local_placecom_mcp_authcode';

    /** @var string Grant table. */
    public const GRANT_TABLE = 'local_placecom_mcp_grant';

    /** @var string Access token table. */
    public const TOKEN_TABLE = 'local_placecom_mcp_token';

    /** @var string Refresh token table. */
    public const REFRESH_TABLE = 'local_placecom_mcp_refresh';

    /** @var string Client table (owned by the client manager). */
    public const CLIENT_TABLE = 'local_placecom_mcp_client';

    /** @var int Minimum seconds between two updates of a grant's last access time. */
    public const TOUCH_INTERVAL = 60;

    /** @var int Seconds an expired code is kept so that replays can still be detected. */
    public const CODE_GRACE = 3600;

    /**
     * Create an authorization code.
     *
     * @param int $clientdbid Client row id.
     * @param int $userid User id.
     * @param string|null $redirecturi Redirect URI supplied in the request, null when omitted.
     * @param string $scope Granted scope.
     * @param string|null $nonce OIDC nonce.
     * @param string|null $codechallenge S256 code challenge.
     * @param int $authtime Time the user authenticated.
     * @return string Plaintext code.
     */
    public function create_code(
        int $clientdbid,
        int $userid,
        ?string $redirecturi,
        string $scope,
        ?string $nonce,
        ?string $codechallenge,
        int $authtime
    ): string {
        global $DB;
        $plain = crypto::random_token();
        $now = time();
        $DB->insert_record(self::CODE_TABLE, (object) [
            'codehash' => crypto::hash_token($plain),
            'clientid' => $clientdbid,
            'userid' => $userid,
            'redirecturi' => ($redirecturi === '') ? null : $redirecturi,
            'scope' => $scope,
            'nonce' => ($nonce === '') ? null : $nonce,
            'codechallenge' => ($codechallenge === '') ? null : $codechallenge,
            'authtime' => $authtime,
            'expires' => $now + config::CODE_LIFETIME,
            'timeused' => 0,
            'grantid' => null,
            'timecreated' => $now,
        ]);
        return $plain;
    }

    /**
     * Look up a code row by its plaintext value, without validity checks.
     *
     * @param string $plain Plaintext code.
     * @return \stdClass|null
     */
    public function find_code(string $plain): ?\stdClass {
        global $DB;
        if ($plain === '') {
            return null;
        }
        $record = $DB->get_record(self::CODE_TABLE, ['codehash' => crypto::hash_token($plain)]);
        return $record ?: null;
    }

    /**
     * Mark a code as exchanged and remember the grant created from it.
     *
     * @param int $codeid Code row id.
     * @param int $grantid Grant id.
     */
    public function mark_code_used(int $codeid, int $grantid): void {
        global $DB;
        $DB->update_record(self::CODE_TABLE, (object) ['id' => $codeid, 'timeused' => time(), 'grantid' => $grantid]);
    }

    /**
     * Create a grant (one connection of a user to a client).
     *
     * @param int $clientdbid Client row id.
     * @param int $userid User id.
     * @param string $scope Scope granted by the code.
     * @return int Grant id.
     */
    public function create_grant(int $clientdbid, int $userid, string $scope): int {
        global $DB;
        $now = time();
        return (int) $DB->insert_record(self::GRANT_TABLE, (object) [
            'clientid' => $clientdbid,
            'userid' => $userid,
            'scope' => $scope,
            'timecreated' => $now,
            'timemodified' => $now,
            'timeaccessed' => 0,
        ]);
    }

    /**
     * Issue an access token for a grant.
     *
     * @param int $grantid Grant id.
     * @param int $clientdbid Client row id.
     * @param int $userid User id.
     * @param string $scope Token scope.
     * @return array `['token' => plaintext, 'id' => row id, 'expires' => timestamp]`
     */
    public function issue_access_token(int $grantid, int $clientdbid, int $userid, string $scope): array {
        global $DB;
        $plain = crypto::random_token();
        $now = time();
        $expires = $now + config::access_lifetime();
        $id = $DB->insert_record(self::TOKEN_TABLE, (object) [
            'tokenhash' => crypto::hash_token($plain),
            'grantid' => $grantid,
            'clientid' => $clientdbid,
            'userid' => $userid,
            'scope' => $scope,
            'expires' => $expires,
            'timecreated' => $now,
        ]);
        $DB->set_field(self::GRANT_TABLE, 'timemodified', $now, ['id' => $grantid]);
        return ['token' => $plain, 'id' => (int) $id, 'expires' => $expires];
    }

    /**
     * Issue a refresh token for a grant.
     *
     * @param int $grantid Grant id.
     * @param int $clientdbid Client row id.
     * @param int $userid User id.
     * @param string $scope Grant scope.
     * @return string Plaintext refresh token.
     */
    public function issue_refresh_token(int $grantid, int $clientdbid, int $userid, string $scope): string {
        global $DB;
        $plain = crypto::random_token();
        $now = time();
        $DB->insert_record(self::REFRESH_TABLE, (object) [
            'tokenhash' => crypto::hash_token($plain),
            'grantid' => $grantid,
            'clientid' => $clientdbid,
            'userid' => $userid,
            'scope' => $scope,
            'expires' => $now + config::refresh_lifetime(),
            'timecreated' => $now,
        ]);
        return $plain;
    }

    /**
     * Find a live access token of an enabled client.
     *
     * This is the only way the MCP endpoint and the UserInfo endpoint authenticate a bearer token.
     *
     * @param string $plain Presented token.
     * @return \stdClass|null Fields id, grantid, clientid (row id), clientidentifier, userid, scope, expires.
     */
    public function find_access_token(string $plain): ?\stdClass {
        return $this->find_live(self::TOKEN_TABLE, $plain);
    }

    /**
     * Find a live refresh token of an enabled client.
     *
     * @param string $plain Presented refresh token.
     * @return \stdClass|null Same shape as {@see find_access_token()}.
     */
    public function find_refresh_token(string $plain): ?\stdClass {
        return $this->find_live(self::REFRESH_TABLE, $plain);
    }

    /**
     * Shared lookup for access and refresh tokens.
     *
     * @param string $table Token table.
     * @param string $plain Presented value.
     * @return \stdClass|null
     */
    private function find_live(string $table, string $plain): ?\stdClass {
        global $DB;
        if ($plain === '') {
            return null;
        }
        $sql = "SELECT t.id, t.grantid, t.clientid, c.clientid AS clientidentifier, t.userid, t.scope, t.expires
                  FROM {" . $table . "} t
                  JOIN {" . self::CLIENT_TABLE . "} c ON c.id = t.clientid
                 WHERE t.tokenhash = :tokenhash
                   AND t.expires > :now
                   AND c.enabled = 1";
        $record = $DB->get_record_sql($sql, ['tokenhash' => crypto::hash_token($plain), 'now' => time()]);
        if (!$record) {
            return null;
        }
        $record->id = (int) $record->id;
        $record->grantid = (int) $record->grantid;
        $record->clientid = (int) $record->clientid;
        $record->clientidentifier = (string) $record->clientidentifier;
        $record->userid = (int) $record->userid;
        $record->scope = (string) $record->scope;
        $record->expires = (int) $record->expires;
        return $record;
    }

    /**
     * Delete one refresh token.
     *
     * @param int $id Refresh token row id.
     */
    public function delete_refresh_token(int $id): void {
        global $DB;
        $DB->delete_records(self::REFRESH_TABLE, ['id' => $id]);
    }

    /**
     * Delete every access token of a grant.
     *
     * @param int $grantid Grant id.
     */
    public function delete_access_tokens_for_grant(int $grantid): void {
        global $DB;
        $DB->delete_records(self::TOKEN_TABLE, ['grantid' => $grantid]);
    }

    /**
     * Record use of a grant, at most once per {@see self::TOUCH_INTERVAL} seconds.
     *
     * @param int $grantid Grant id.
     */
    public function touch_grant(int $grantid): void {
        global $DB;
        $now = time();
        $DB->execute(
            "UPDATE {" . self::GRANT_TABLE . "}
                         SET timeaccessed = :now
                       WHERE id = :id AND timeaccessed < :threshold",
            ['now' => $now, 'id' => $grantid, 'threshold' => $now - self::TOUCH_INTERVAL]
        );
    }

    /**
     * A grant row.
     *
     * @param int $grantid Grant id.
     * @return \stdClass|null
     */
    public function get_grant(int $grantid): ?\stdClass {
        global $DB;
        $record = $DB->get_record(self::GRANT_TABLE, ['id' => $grantid]);
        return $record ?: null;
    }

    /**
     * Revoke a grant: its tokens, refresh tokens, codes, the (client, user) consent and the grant itself.
     *
     * @param int $grantid Grant id.
     * @param string $reason One of `admin`, `user`, `codereplay`, `clientdeleted`.
     * @return bool False when the grant does not exist.
     */
    public function revoke_grant(int $grantid, string $reason): bool {
        global $DB;
        $grant = $this->get_grant($grantid);
        if ($grant === null) {
            return false;
        }
        $clientidentifier = (string) $DB->get_field(self::CLIENT_TABLE, 'clientid', ['id' => $grant->clientid]);

        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records(self::TOKEN_TABLE, ['grantid' => $grantid]);
        $DB->delete_records(self::REFRESH_TABLE, ['grantid' => $grantid]);
        $DB->delete_records(self::CODE_TABLE, ['grantid' => $grantid]);
        (new consent_store())->delete((int) $grant->clientid, (int) $grant->userid);
        $DB->delete_records(self::GRANT_TABLE, ['id' => $grantid]);
        $transaction->allow_commit();

        $event = grant_revoked::create([
            'objectid' => $grantid,
            'relateduserid' => (int) $grant->userid,
            'context' => \context_system::instance(),
            'other' => [
                'clientid' => $clientidentifier,
                'reason' => $reason,
            ],
        ]);
        $event->add_record_snapshot(self::GRANT_TABLE, $grant);
        $event->trigger();
        return true;
    }

    /**
     * Revoke every grant of a user for one client, plus their codes and the consent.
     *
     * @param int $clientdbid Client row id.
     * @param int $userid User id.
     * @param string $reason Revocation reason.
     * @return int Number of grants revoked.
     */
    public function revoke_user_client(int $clientdbid, int $userid, string $reason): int {
        global $DB;
        $count = 0;
        $grantids = $DB->get_fieldset_select(
            self::GRANT_TABLE,
            'id',
            'clientid = :clientid AND userid = :userid',
            ['clientid' => $clientdbid, 'userid' => $userid]
        );
        foreach ($grantids as $grantid) {
            if ($this->revoke_grant((int) $grantid, $reason)) {
                $count++;
            }
        }
        $DB->delete_records(self::CODE_TABLE, ['clientid' => $clientdbid, 'userid' => $userid]);
        (new consent_store())->delete($clientdbid, $userid);
        return $count;
    }

    /**
     * Remove everything issued to a client (used before the client row is deleted).
     *
     * @param int $clientdbid Client row id.
     */
    public function delete_all_for_client(int $clientdbid): void {
        global $DB;
        $grantids = $DB->get_fieldset_select(self::GRANT_TABLE, 'id', 'clientid = :clientid', ['clientid' => $clientdbid]);
        foreach ($grantids as $grantid) {
            $this->revoke_grant((int) $grantid, 'clientdeleted');
        }
        $DB->delete_records(self::TOKEN_TABLE, ['clientid' => $clientdbid]);
        $DB->delete_records(self::REFRESH_TABLE, ['clientid' => $clientdbid]);
        $DB->delete_records(self::CODE_TABLE, ['clientid' => $clientdbid]);
        (new consent_store())->delete_for_client($clientdbid);
    }

    /**
     * Remove all data of a user without triggering events (user deletion and privacy requests).
     *
     * @param int $userid User id.
     */
    public function delete_all_for_user(int $userid): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records(self::CODE_TABLE, ['userid' => $userid]);
        $DB->delete_records(self::TOKEN_TABLE, ['userid' => $userid]);
        $DB->delete_records(self::REFRESH_TABLE, ['userid' => $userid]);
        $DB->delete_records(self::GRANT_TABLE, ['userid' => $userid]);
        (new consent_store())->delete_for_user($userid);
        $transaction->allow_commit();
    }

    /**
     * Housekeeping: delete expired codes (after a grace period), tokens, refresh tokens and empty grants.
     *
     * @param int $now Current time.
     * @return array Counts keyed `codes`, `accesstokens`, `refreshtokens`, `grants`.
     */
    public function delete_expired(int $now): array {
        global $DB;
        $result = [];

        $select = 'expires < :cutoff';
        $params = ['cutoff' => $now - self::CODE_GRACE];
        $result['codes'] = $DB->count_records_select(self::CODE_TABLE, $select, $params);
        $DB->delete_records_select(self::CODE_TABLE, $select, $params);

        $params = ['cutoff' => $now];
        $result['accesstokens'] = $DB->count_records_select(self::TOKEN_TABLE, $select, $params);
        $DB->delete_records_select(self::TOKEN_TABLE, $select, $params);

        $result['refreshtokens'] = $DB->count_records_select(self::REFRESH_TABLE, $select, $params);
        $DB->delete_records_select(self::REFRESH_TABLE, $select, $params);

        $sql = "SELECT g.id
                  FROM {" . self::GRANT_TABLE . "} g
                 WHERE NOT EXISTS (SELECT 1 FROM {" . self::TOKEN_TABLE . "} t WHERE t.grantid = g.id)
                   AND NOT EXISTS (SELECT 1 FROM {" . self::REFRESH_TABLE . "} r WHERE r.grantid = g.id)";
        $grantids = $DB->get_fieldset_sql($sql);
        foreach (array_chunk($grantids, 500) as $chunk) {
            $DB->delete_records_list(self::GRANT_TABLE, 'id', $chunk);
        }
        $result['grants'] = count($grantids);

        return $result;
    }
}
