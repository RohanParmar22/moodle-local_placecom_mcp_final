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
 * Table of registered OAuth clients.
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
 * Lists the registered OAuth clients with their management actions.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class clients_table extends \table_sql {
    /**
     * Sets up columns, SQL and paging.
     *
     * @param string $uniqueid Unique id of the table.
     */
    public function __construct(string $uniqueid) {
        parent::__construct($uniqueid);

        $columns = ['clientid', 'name', 'secret', 'redirecturis', 'scope', 'requirepkce', 'enabled', 'actions'];
        $headers = [
            get_string('clientid', 'local_placecom_mcp'),
            get_string('clientname', 'local_placecom_mcp'),
            get_string('clientsecret', 'local_placecom_mcp'),
            get_string('redirecturis', 'local_placecom_mcp'),
            get_string('scope', 'local_placecom_mcp'),
            get_string('requirepkce', 'local_placecom_mcp'),
            get_string('clientenabled', 'local_placecom_mcp'),
            get_string('actions', 'local_placecom_mcp'),
        ];
        $this->define_columns($columns);
        $this->define_headers($headers);
        $this->define_baseurl(new moodle_url('/local/placecom_mcp/clients.php'));
        $this->no_sorting('secret');
        $this->no_sorting('redirecturis');
        $this->no_sorting('actions');
        $this->sortable(true, 'clientid', SORT_ASC);
        $this->collapsible(false);
        $this->set_attribute('class', 'generaltable local_placecom_mcp_clients');

        $this->set_sql(
            'id, clientid, name, secrethash, secrethint, redirecturis, scope, requirepkce, enabled',
            '{local_placecom_mcp_client}',
            '1 = 1'
        );
        $this->set_count_sql('SELECT COUNT(1) FROM {local_placecom_mcp_client}');
    }

    /**
     * Client identifier column.
     *
     * @param \stdClass $row Table row.
     * @return string
     */
    public function col_clientid($row) {
        return html_writer::tag('code', s($row->clientid));
    }

    /**
     * Display name column.
     *
     * @param \stdClass $row Table row.
     * @return string
     */
    public function col_name($row) {
        return format_string((string) $row->name, true, ['context' => \context_system::instance()]);
    }

    /**
     * Masked secret column.
     *
     * @param \stdClass $row Table row.
     * @return string
     */
    public function col_secret($row) {
        if ((string) $row->secrethash === '') {
            return html_writer::tag('em', get_string('publicclient', 'local_placecom_mcp'));
        }
        $hint = $row->secrethint === null || $row->secrethint === '' ? '????' : $row->secrethint;
        return html_writer::tag('code', '********' . s($hint));
    }

    /**
     * Redirect URIs column, one per line.
     *
     * @param \stdClass $row Table row.
     * @return string
     */
    public function col_redirecturis($row) {
        $uris = \local_placecom_mcp\local\client\client_manager::parse_redirect_uris((string) $row->redirecturis);
        return implode(html_writer::empty_tag('br'), array_map('s', $uris));
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
     * PKCE column. Public clients always require PKCE.
     *
     * @param \stdClass $row Table row.
     * @return string
     */
    public function col_requirepkce($row) {
        $required = !empty($row->requirepkce) || (string) $row->secrethash === '';
        return get_string($required ? 'yes' : 'no');
    }

    /**
     * Enabled column.
     *
     * @param \stdClass $row Table row.
     * @return string
     */
    public function col_enabled($row) {
        return get_string(!empty($row->enabled) ? 'yes' : 'no');
    }

    /**
     * Action links. Links that change state carry the session key or lead to a confirmation page.
     *
     * @param \stdClass $row Table row.
     * @return string
     */
    public function col_actions($row) {
        $base = '/local/placecom_mcp/clients.php';
        $links = [];
        $links[] = html_writer::link(
            new moodle_url('/local/placecom_mcp/editclient.php', ['id' => $row->id]),
            get_string('edit')
        );
        $links[] = html_writer::link(
            new moodle_url($base, ['action' => 'rotate', 'id' => $row->id]),
            get_string('rotatesecret', 'local_placecom_mcp')
        );
        if (!empty($row->enabled)) {
            $links[] = html_writer::link(
                new moodle_url($base, ['action' => 'disable', 'id' => $row->id, 'sesskey' => sesskey()]),
                get_string('disableclient', 'local_placecom_mcp')
            );
        } else {
            $links[] = html_writer::link(
                new moodle_url($base, ['action' => 'enable', 'id' => $row->id, 'sesskey' => sesskey()]),
                get_string('enableclient', 'local_placecom_mcp')
            );
        }
        $links[] = html_writer::link(
            new moodle_url($base, ['action' => 'delete', 'id' => $row->id]),
            get_string('delete')
        );
        return implode(' | ', $links);
    }
}
