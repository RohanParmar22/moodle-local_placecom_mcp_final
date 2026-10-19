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
 * Table of connections (grants) for administrators.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\table;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/tablelib.php');

use html_writer;
use moodle_url;

/**
 * Lists every user's connections to AI assistants, with their status and a revoke action.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grants_table extends \table_sql {
    /**
     * Sets up columns, SQL and paging.
     *
     * @param string $uniqueid Unique id of the table.
     */
    public function __construct(string $uniqueid) {
        parent::__construct($uniqueid);

        $columns = ['user', 'client', 'scope', 'timecreated', 'timeaccessed', 'status', 'actions'];
        $headers = [
            get_string('grantuser', 'local_placecom_mcp'),
            get_string('grantclient', 'local_placecom_mcp'),
            get_string('grantscope', 'local_placecom_mcp'),
            get_string('granttimecreated', 'local_placecom_mcp'),
            get_string('granttimeaccessed', 'local_placecom_mcp'),
            get_string('grantstatus', 'local_placecom_mcp'),
            get_string('actions', 'local_placecom_mcp'),
        ];
        $this->define_columns($columns);
        $this->define_headers($headers);
        $this->define_baseurl(new moodle_url('/local/placecom_mcp/grants.php'));
        $this->no_sorting('scope');
        $this->no_sorting('status');
        $this->no_sorting('actions');
        $this->sortable(true, 'timecreated', SORT_DESC);
        $this->collapsible(false);
        $this->set_attribute('class', 'generaltable local_placecom_mcp_grants');

        $userfields = \core_user\fields::for_name()->including('id')->get_sql('u', false, '', 'userid');
        $fields = "g.id, g.scope, g.timecreated, g.timeaccessed, c.clientid AS clientidentifier, c.name AS clientname,
                   (SELECT MAX(t.expires) FROM {local_placecom_mcp_token} t WHERE t.grantid = g.id) AS tokenexpires,
                   (SELECT MAX(r.expires) FROM {local_placecom_mcp_refresh} r WHERE r.grantid = g.id) AS refreshexpires"
                   . $userfields->selects;
        $from = "{local_placecom_mcp_grant} g
                 JOIN {local_placecom_mcp_client} c ON c.id = g.clientid
                 JOIN {user} u ON u.id = g.userid
                 {$userfields->joins}";
        $this->set_sql($fields, $from, '1 = 1', $userfields->params);
        $this->set_count_sql("SELECT COUNT(1) FROM {local_placecom_mcp_grant} g
                                JOIN {local_placecom_mcp_client} c ON c.id = g.clientid
                                JOIN {user} u ON u.id = g.userid");
    }

    /**
     * Maps the sortable pseudo columns to SQL.
     *
     * @return string
     */
    public function get_sql_sort() {
        $sort = parent::get_sql_sort();
        $sort = preg_replace('/\buser\b/', 'u.lastname', $sort);
        $sort = preg_replace('/\bclient\b/', 'c.clientid', $sort);
        $sort = preg_replace('/\btimecreated\b/', 'g.timecreated', $sort);
        return preg_replace('/\btimeaccessed\b/', 'g.timeaccessed', $sort);
    }

    /**
     * User column, linked to the profile.
     *
     * @param \stdClass $row Table row.
     * @return string
     */
    public function col_user($row) {
        $user = clone $row;
        $user->id = $row->userid;
        return html_writer::link(new moodle_url('/user/profile.php', ['id' => $row->userid]), fullname($user));
    }

    /**
     * Client column: display name and identifier.
     *
     * @param \stdClass $row Table row.
     * @return string
     */
    public function col_client($row) {
        $name = trim((string) $row->clientname) === '' ? $row->clientidentifier : $row->clientname;
        $out = format_string($name, true, ['context' => \context_system::instance()]);
        if ($name !== $row->clientidentifier) {
            $out .= ' ' . html_writer::tag('code', s($row->clientidentifier));
        }
        return $out;
    }

    /**
     * Scope column.
     *
     * @param \stdClass $row Table row.
     * @return string
     */
    public function col_scope($row) {
        return s($row->scope);
    }

    /**
     * Creation time column.
     *
     * @param \stdClass $row Table row.
     * @return string
     */
    public function col_timecreated($row) {
        return userdate($row->timecreated, get_string('strftimedatetimeshort', 'core_langconfig'));
    }

    /**
     * Last access column.
     *
     * @param \stdClass $row Table row.
     * @return string
     */
    public function col_timeaccessed($row) {
        if (empty($row->timeaccessed)) {
            return '-';
        }
        return userdate($row->timeaccessed, get_string('strftimedatetimeshort', 'core_langconfig'));
    }

    /**
     * Status column: active while any access or refresh token is still valid.
     *
     * @param \stdClass $row Table row.
     * @return string
     */
    public function col_status($row) {
        $expires = max((int) $row->tokenexpires, (int) $row->refreshexpires);
        $active = $expires > time();
        return html_writer::span(
            get_string($active ? 'status_active' : 'status_expired', 'local_placecom_mcp'),
            $active ? 'badge badge-success bg-success' : 'badge badge-secondary bg-secondary'
        );
    }

    /**
     * Revoke link leading to a confirmation page.
     *
     * @param \stdClass $row Table row.
     * @return string
     */
    public function col_actions($row) {
        return html_writer::link(
            new moodle_url('/local/placecom_mcp/grants.php', ['action' => 'revoke', 'id' => $row->id]),
            get_string('revoke', 'local_placecom_mcp')
        );
    }
}
