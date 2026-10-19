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
 * Privacy API implementation for the Placecom MCP Connector.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_placecom_mcp\local\oauth\token_store;

/**
 * Describes, exports and deletes the personal data held by the plugin.
 *
 * All personal data belongs to the user context of the person concerned: their consents, their
 * connections (grants) and the codes and tokens issued for those connections. Secrets are only ever
 * stored as hashes and are never exported.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** @var string[] Tables holding user data, without the plugin prefix. */
    private const USER_TABLES = ['consent', 'grant', 'authcode', 'token', 'refresh'];

    /**
     * Describes the stored personal data and the data sent to connected assistants.
     *
     * @param collection $collection Collection to add to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $fields = [
            'consent' => ['userid', 'clientid', 'scope', 'timecreated'],
            'grant' => ['userid', 'clientid', 'scope', 'timecreated', 'timeaccessed'],
            'authcode' => ['userid', 'clientid', 'redirecturi', 'scope', 'nonce', 'expires'],
            'token' => ['userid', 'clientid', 'tokenhash', 'scope', 'expires'],
            'refresh' => ['userid', 'clientid', 'tokenhash', 'scope', 'expires'],
        ];
        foreach ($fields as $table => $columns) {
            $described = [];
            foreach ($columns as $column) {
                $described[$column] = 'privacy:metadata:field:' . $column;
            }
            $collection->add_database_table('local_placecom_mcp_' . $table, $described, 'privacy:metadata:' . $table);
        }

        $collection->add_external_location_link('mcpclient', [
            'userid' => 'privacy:metadata:mcpclient:userid',
            'username' => 'privacy:metadata:mcpclient:username',
            'fullname' => 'privacy:metadata:mcpclient:fullname',
            'email' => 'privacy:metadata:mcpclient:email',
            'picture' => 'privacy:metadata:mcpclient:picture',
            'address' => 'privacy:metadata:mcpclient:address',
            'phone' => 'privacy:metadata:mcpclient:phone',
            'moodledata' => 'privacy:metadata:mcpclient:moodledata',
        ], 'privacy:metadata:mcpclient');

        return $collection;
    }

    /**
     * Returns the user context of the user when they have any data stored by the plugin.
     *
     * @param int $userid User id.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        if (self::user_has_data($userid)) {
            $contextlist->add_user_context($userid);
        }
        return $contextlist;
    }

    /**
     * Adds the owner of a user context when they have data stored by the plugin.
     *
     * @param userlist $userlist List to fill.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_user) {
            return;
        }
        if (self::user_has_data((int) $context->instanceid)) {
            $userlist->add_user((int) $context->instanceid);
        }
    }

    /**
     * Exports the consents, connections, codes and tokens of the user. Hashes, nonces and redirect
     * URIs are not exported.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_user || (int) $context->instanceid !== $userid) {
                continue;
            }
            $data = self::collect_export($userid);
            if ($data === null) {
                continue;
            }
            writer::with_context($context)->export_data([get_string('pluginname', 'local_placecom_mcp')], $data);
        }
    }

    /**
     * Deletes all data held in a context. Only user contexts hold data.
     *
     * @param \context $context The context.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        if ($context instanceof \context_user) {
            (new token_store())->delete_all_for_user((int) $context->instanceid);
        }
    }

    /**
     * Deletes the data of one user in the approved contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_user && (int) $context->instanceid === $userid) {
                (new token_store())->delete_all_for_user($userid);
            }
        }
    }

    /**
     * Deletes the data of the listed users in one context.
     *
     * @param approved_userlist $userlist Approved users.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_user) {
            return;
        }
        $owner = (int) $context->instanceid;
        if (in_array($owner, array_map('intval', $userlist->get_userids()), true)) {
            (new token_store())->delete_all_for_user($owner);
        }
    }

    /**
     * Whether any table of the plugin has a row for the user.
     *
     * @param int $userid User id.
     * @return bool
     */
    private static function user_has_data(int $userid): bool {
        global $DB;
        foreach (self::USER_TABLES as $table) {
            if ($DB->record_exists('local_placecom_mcp_' . $table, ['userid' => $userid])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Builds the export object for a user.
     *
     * @param int $userid User id.
     * @return \stdClass|null Null when the user has no data.
     */
    private static function collect_export(int $userid): ?\stdClass {
        $data = (object) [
            'consents' => [],
            'connections' => [],
            'accesstokens' => [],
            'refreshtokens' => [],
            'authorizationcodes' => [],
        ];

        foreach (self::fetch($userid, 'consent', 'x.scope, x.timecreated') as $row) {
            $data->consents[] = (object) [
                'clientid' => $row->clientidentifier,
                'clientname' => $row->clientname,
                'scope' => $row->scope,
                'timecreated' => transform::datetime($row->timecreated),
            ];
        }
        foreach (self::fetch($userid, 'grant', 'x.scope, x.timecreated, x.timeaccessed') as $row) {
            $data->connections[] = (object) [
                'clientid' => $row->clientidentifier,
                'clientname' => $row->clientname,
                'scope' => $row->scope,
                'timecreated' => transform::datetime($row->timecreated),
                'timeaccessed' => $row->timeaccessed ? transform::datetime($row->timeaccessed) : '-',
            ];
        }
        $lists = ['token' => 'accesstokens', 'refresh' => 'refreshtokens', 'authcode' => 'authorizationcodes'];
        foreach ($lists as $table => $key) {
            foreach (self::fetch($userid, $table, 'x.scope, x.expires') as $row) {
                $data->{$key}[] = (object) [
                    'clientid' => $row->clientidentifier,
                    'clientname' => $row->clientname,
                    'scope' => $row->scope,
                    'expires' => transform::datetime($row->expires),
                ];
            }
        }

        foreach ((array) $data as $list) {
            if (!empty($list)) {
                return $data;
            }
        }
        return null;
    }

    /**
     * Reads the rows of one table for a user together with the client identifier and name.
     *
     * @param int $userid User id.
     * @param string $table Table name without the plugin prefix.
     * @param string $fields Extra fields of alias x to select.
     * @return \stdClass[]
     */
    private static function fetch(int $userid, string $table, string $fields): array {
        global $DB;
        $sql = "SELECT x.id, {$fields}, c.clientid AS clientidentifier, c.name AS clientname
                  FROM {local_placecom_mcp_{$table}} x
             LEFT JOIN {local_placecom_mcp_client} c ON c.id = x.clientid
                 WHERE x.userid = :userid
              ORDER BY x.id ASC";
        $rows = $DB->get_records_sql($sql, ['userid' => $userid]);
        foreach ($rows as $row) {
            $row->clientidentifier = (string) ($row->clientidentifier ?? '');
            $row->clientname = (string) ($row->clientname ?? '');
        }
        return array_values($rows);
    }
}
