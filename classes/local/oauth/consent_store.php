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
 * Storage of user consents.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\oauth;

/**
 * One consent row per (client, user): the scope the user last approved on the consent screen.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class consent_store {
    /** @var string Consent table. */
    public const TABLE = 'local_placecom_mcp_consent';

    /**
     * Whether the user has already approved at least the given scopes for the client.
     *
     * @param int $clientdbid Client row id.
     * @param int $userid User id.
     * @param string[] $scopes Scopes being requested.
     * @return bool
     */
    public function has_consent(int $clientdbid, int $userid, array $scopes): bool {
        $record = $this->get($clientdbid, $userid);
        if ($record === null) {
            return false;
        }
        return scopes::is_subset($scopes, scopes::parse($record->scope));
    }

    /**
     * Insert or update the consent of a user for a client.
     *
     * @param int $clientdbid Client row id.
     * @param int $userid User id.
     * @param string $scope Approved scope string.
     * @return int Consent row id.
     */
    public function save(int $clientdbid, int $userid, string $scope): int {
        global $DB;
        $now = time();
        $existing = $this->get($clientdbid, $userid);
        if ($existing !== null) {
            $DB->update_record(self::TABLE, (object) [
                'id' => $existing->id,
                'scope' => $scope,
                'timemodified' => $now,
            ]);
            return (int) $existing->id;
        }
        return (int) $DB->insert_record(self::TABLE, (object) [
            'clientid' => $clientdbid,
            'userid' => $userid,
            'scope' => $scope,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * The consent row of a user for a client.
     *
     * @param int $clientdbid Client row id.
     * @param int $userid User id.
     * @return \stdClass|null
     */
    public function get(int $clientdbid, int $userid): ?\stdClass {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['clientid' => $clientdbid, 'userid' => $userid]);
        return $record ?: null;
    }

    /**
     * Delete the consent of a user for a client.
     *
     * @param int $clientdbid Client row id.
     * @param int $userid User id.
     */
    public function delete(int $clientdbid, int $userid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['clientid' => $clientdbid, 'userid' => $userid]);
    }

    /**
     * Delete every consent given to a client.
     *
     * @param int $clientdbid Client row id.
     */
    public function delete_for_client(int $clientdbid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['clientid' => $clientdbid]);
    }

    /**
     * Delete every consent given by a user.
     *
     * @param int $userid User id.
     */
    public function delete_for_user(int $userid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['userid' => $userid]);
    }
}
