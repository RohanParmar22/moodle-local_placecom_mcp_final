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
 * Management of registered OAuth clients.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\client;

use local_placecom_mcp\event\client_created;
use local_placecom_mcp\event\client_deleted;
use local_placecom_mcp\event\client_updated;
use local_placecom_mcp\local\oauth\scopes;
use local_placecom_mcp\local\oauth\token_store;

/**
 * Creates, reads, updates and deletes OAuth clients, and validates client settings.
 *
 * Client secrets are generated here, returned once in plain text and stored only as a password hash
 * plus a four character hint.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class client_manager {
    /** @var string Database table holding the clients. */
    public const TABLE = 'local_placecom_mcp_client';

    /** @var int Maximum number of redirect URIs per client. */
    public const MAX_REDIRECT_URIS = 10;

    /** @var string Pattern a client identifier must match (RFC 3986 unreserved characters). */
    public const CLIENTID_PATTERN = '/^[A-Za-z0-9._~-]{3,100}$/';

    /**
     * Loads a client by database id.
     *
     * @param int $id Database id.
     * @return client|null Null when no such client exists.
     */
    public function get(int $id): ?client {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['id' => $id]);
        return $record ? new client($record) : null;
    }

    /**
     * Loads a client by its public identifier (exact, case-sensitive match).
     *
     * @param string $clientid Public client identifier.
     * @return client|null Null when no such client exists.
     */
    public function get_by_clientid(string $clientid): ?client {
        global $DB;
        if ($clientid === '') {
            return null;
        }
        // Some databases compare case-insensitively, so confirm the match in PHP.
        $records = $DB->get_records(self::TABLE, ['clientid' => $clientid]);
        foreach ($records as $record) {
            if ($record->clientid === $clientid) {
                return new client($record);
            }
        }
        return null;
    }

    /**
     * Whether an identifier is already used, ignoring letter case.
     *
     * Identifiers are matched case-sensitively at runtime, but some databases enforce the unique index
     * case-insensitively, so new identifiers must differ from existing ones by more than case.
     *
     * @param string $clientid Candidate identifier.
     * @return bool
     */
    public function clientid_taken(string $clientid): bool {
        global $DB;
        $select = $DB->sql_equal('clientid', ':clientid', false);
        return $DB->record_exists_select(self::TABLE, $select, ['clientid' => $clientid]);
    }

    /**
     * All clients ordered by identifier.
     *
     * @return client[] Keyed by database id.
     */
    public function get_all(): array {
        global $DB;
        $clients = [];
        foreach ($DB->get_records(self::TABLE, null, 'clientid ASC, id ASC') as $record) {
            $clients[(int) $record->id] = new client($record);
        }
        return $clients;
    }

    /**
     * Registers a new client.
     *
     * @param \stdClass $data Fields clientid, name, redirecturis (one per line), scope and requirepkce.
     * @param bool $generatesecret True for a confidential client, false for a public client.
     * @return array ['client' => client, 'secret' => string|null]; the secret is only available here.
     * @throws \invalid_parameter_exception When the data is not valid.
     */
    public function create(\stdClass $data, bool $generatesecret): array {
        global $DB, $USER;

        $clientid = trim((string) ($data->clientid ?? ''));
        if (!self::validate_clientid($clientid)) {
            throw new \invalid_parameter_exception(get_string('error_clientidinvalid', 'local_placecom_mcp'));
        }
        if ($this->clientid_taken($clientid)) {
            throw new \invalid_parameter_exception(get_string('error_clientidexists', 'local_placecom_mcp'));
        }
        $redirecturis = $this->clean_redirect_uris((string) ($data->redirecturis ?? ''));
        $scope = $this->clean_scope((string) ($data->scope ?? ''));

        $now = time();
        $record = (object) [
            'clientid' => $clientid,
            'name' => trim((string) ($data->name ?? '')),
            'secrethash' => '',
            'secrethint' => null,
            'redirecturis' => implode("\n", $redirecturis),
            'scope' => $scope,
            'requirepkce' => ($generatesecret && isset($data->requirepkce) && empty($data->requirepkce)) ? 0 : 1,
            'enabled' => (!isset($data->enabled) || !empty($data->enabled)) ? 1 : 0,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => (int) $USER->id,
        ];

        $secret = null;
        if ($generatesecret) {
            $secret = self::generate_secret();
            $record->secrethash = password_hash($secret, PASSWORD_DEFAULT);
            $record->secrethint = substr($secret, -4);
        }

        $record->id = $DB->insert_record(self::TABLE, $record);

        $event = client_created::create([
            'objectid' => $record->id,
            'context' => \context_system::instance(),
            'other' => ['clientid' => $clientid],
        ]);
        $event->add_record_snapshot(self::TABLE, $record);
        $event->trigger();

        return ['client' => new client($record), 'secret' => $secret];
    }

    /**
     * Updates the editable settings of a client. Fields missing from $data are left unchanged.
     *
     * @param int $id Database id.
     * @param \stdClass $data Any of name, redirecturis, scope, requirepkce, enabled.
     * @return client The updated client.
     * @throws \invalid_parameter_exception When the client does not exist or the data is invalid.
     */
    public function update(int $id, \stdClass $data): client {
        global $DB, $USER;

        $existing = $DB->get_record(self::TABLE, ['id' => $id]);
        if (!$existing) {
            throw new \invalid_parameter_exception(get_string('clientnotfound', 'local_placecom_mcp'));
        }

        $record = (object) ['id' => $id];
        if (property_exists($data, 'name')) {
            $record->name = trim((string) $data->name);
        }
        if (property_exists($data, 'redirecturis')) {
            $record->redirecturis = implode("\n", $this->clean_redirect_uris((string) $data->redirecturis));
        }
        if (property_exists($data, 'scope')) {
            $record->scope = $this->clean_scope((string) $data->scope);
        }
        if (property_exists($data, 'requirepkce')) {
            // Public clients cannot turn PKCE off.
            $record->requirepkce = ($existing->secrethash === '' || !empty($data->requirepkce)) ? 1 : 0;
        }
        if (property_exists($data, 'enabled')) {
            $record->enabled = empty($data->enabled) ? 0 : 1;
        }
        $record->timemodified = time();
        $record->usermodified = (int) $USER->id;

        $DB->update_record(self::TABLE, $record);
        $this->trigger_updated($id, false);

        return $this->get($id);
    }

    /**
     * Replaces the secret of a client. A public client becomes a confidential client.
     *
     * @param int $id Database id.
     * @return string The new plain secret; it cannot be retrieved again.
     * @throws \invalid_parameter_exception When the client does not exist.
     */
    public function rotate_secret(int $id): string {
        global $DB, $USER;

        if (!$DB->record_exists(self::TABLE, ['id' => $id])) {
            throw new \invalid_parameter_exception(get_string('clientnotfound', 'local_placecom_mcp'));
        }
        $secret = self::generate_secret();
        $DB->update_record(self::TABLE, (object) [
            'id' => $id,
            'secrethash' => password_hash($secret, PASSWORD_DEFAULT),
            'secrethint' => substr($secret, -4),
            'timemodified' => time(),
            'usermodified' => (int) $USER->id,
        ]);
        $this->trigger_updated($id, true);

        return $secret;
    }

    /**
     * Enables or disables a client. A disabled client stops working immediately.
     *
     * @param int $id Database id.
     * @param bool $enabled New state.
     * @return void
     * @throws \invalid_parameter_exception When the client does not exist.
     */
    public function set_enabled(int $id, bool $enabled): void {
        global $DB, $USER;

        if (!$DB->record_exists(self::TABLE, ['id' => $id])) {
            throw new \invalid_parameter_exception(get_string('clientnotfound', 'local_placecom_mcp'));
        }
        $DB->update_record(self::TABLE, (object) [
            'id' => $id,
            'enabled' => $enabled ? 1 : 0,
            'timemodified' => time(),
            'usermodified' => (int) $USER->id,
        ]);
        $this->trigger_updated($id, false);
    }

    /**
     * Deletes a client together with every connection, token, code and consent issued for it.
     *
     * @param int $id Database id.
     * @return bool False when the client does not exist.
     */
    public function delete(int $id): bool {
        global $DB;

        $record = $DB->get_record(self::TABLE, ['id' => $id]);
        if (!$record) {
            return false;
        }

        $transaction = $DB->start_delegated_transaction();
        (new token_store())->delete_all_for_client($id);
        $DB->delete_records(self::TABLE, ['id' => $id]);

        $event = client_deleted::create([
            'objectid' => $id,
            'context' => \context_system::instance(),
            'other' => ['clientid' => $record->clientid],
        ]);
        $event->add_record_snapshot(self::TABLE, $record);
        $event->trigger();

        $transaction->allow_commit();
        return true;
    }

    /**
     * Checks the format of a client identifier.
     *
     * @param string $clientid Candidate identifier.
     * @return bool
     */
    public static function validate_clientid(string $clientid): bool {
        return preg_match(self::CLIENTID_PATTERN, $clientid) === 1;
    }

    /**
     * Splits the redirect URI text field into a list: one URI per line, trimmed, without blanks or duplicates.
     *
     * @param string $text Raw text.
     * @return string[]
     */
    public static function parse_redirect_uris(string $text): array {
        $uris = [];
        foreach (preg_split('/\R/', $text) as $line) {
            $line = trim($line);
            if ($line !== '' && !in_array($line, $uris, true)) {
                $uris[] = $line;
            }
        }
        return $uris;
    }

    /**
     * Checks a single redirect URI.
     *
     * The URI must be absolute, must not carry a fragment or user information, and must use https.
     * Plain http is allowed only for loopback hosts, as native applications commonly use them.
     *
     * @param string $uri Candidate redirect URI.
     * @return bool
     */
    public static function validate_redirect_uri(string $uri): bool {
        if ($uri === '' || preg_match('/\s/', $uri) || strpos($uri, '#') !== false) {
            return false;
        }
        $parts = parse_url($uri);
        if ($parts === false || empty($parts['scheme']) || !isset($parts['host']) || $parts['host'] === '') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme === 'https') {
            return true;
        }
        if ($scheme === 'http') {
            return in_array(strtolower($parts['host']), ['localhost', '127.0.0.1', '[::1]'], true);
        }
        return false;
    }

    /**
     * Checks a normalised scope string.
     *
     * @param string $normalised Output of scopes::normalise().
     * @return string|null Language string identifier describing the problem, or null when valid.
     */
    public static function validate_scope(string $normalised): ?string {
        $list = scopes::parse($normalised);
        if (empty($list)) {
            return 'error_scoperequired';
        }
        if (!empty(scopes::unknown($list))) {
            return 'error_scopeunknown';
        }
        if (!in_array(scopes::READ, $list, true)) {
            return 'error_scopereadrequired';
        }
        return null;
    }

    /**
     * Creates a new random client secret of 48 hexadecimal characters (192 bits).
     *
     * @return string
     */
    protected static function generate_secret(): string {
        return bin2hex(random_bytes(24));
    }

    /**
     * Parses and validates the redirect URI field.
     *
     * @param string $text Raw text, one URI per line.
     * @return string[]
     * @throws \invalid_parameter_exception When the list is empty, too long or has an invalid URI.
     */
    protected function clean_redirect_uris(string $text): array {
        $uris = self::parse_redirect_uris($text);
        if (empty($uris)) {
            throw new \invalid_parameter_exception(get_string('error_redirecturirequired', 'local_placecom_mcp'));
        }
        if (count($uris) > self::MAX_REDIRECT_URIS) {
            throw new \invalid_parameter_exception(
                get_string('error_redirecturitoomany', 'local_placecom_mcp', self::MAX_REDIRECT_URIS)
            );
        }
        foreach ($uris as $uri) {
            if (!self::validate_redirect_uri($uri)) {
                throw new \invalid_parameter_exception(get_string('error_redirecturiinvalid', 'local_placecom_mcp', $uri));
            }
        }
        return $uris;
    }

    /**
     * Normalises and validates the scope field.
     *
     * @param string $scope Raw scope text.
     * @return string Normalised scope string.
     * @throws \invalid_parameter_exception When the scope is not acceptable.
     */
    protected function clean_scope(string $scope): string {
        $normalised = scopes::normalise($scope);
        $error = self::validate_scope($normalised);
        if ($error !== null) {
            throw new \invalid_parameter_exception(get_string($error, 'local_placecom_mcp'));
        }
        return $normalised;
    }

    /**
     * Triggers the client_updated event.
     *
     * @param int $id Database id.
     * @param bool $secretrotated Whether the secret was replaced.
     * @return void
     */
    protected function trigger_updated(int $id, bool $secretrotated): void {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
        $other = ['clientid' => $record->clientid];
        if ($secretrotated) {
            $other['secretrotated'] = true;
        }
        $event = client_updated::create([
            'objectid' => $id,
            'context' => \context_system::instance(),
            'other' => $other,
        ]);
        $event->add_record_snapshot(self::TABLE, $record);
        $event->trigger();
    }
}
