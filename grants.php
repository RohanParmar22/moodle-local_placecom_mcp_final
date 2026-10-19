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
 * Administration page listing connections (grants) of all users, with revocation.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_placecom_mcp\local\client\client_manager;
use local_placecom_mcp\local\oauth\token_store;
use local_placecom_mcp\table\grants_table;

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

admin_externalpage_setup('local_placecom_mcp_grants');

$baseurl = new moodle_url('/local/placecom_mcp/grants.php');
$store = new token_store();

if ($action === 'revoke') {
    $grant = $store->get_grant($id);
    if ($grant === null) {
        redirect(
            $baseurl,
            get_string('grantnotfound', 'local_placecom_mcp'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    if ($confirm && confirm_sesskey()) {
        if ($store->revoke_grant((int) $grant->id, 'admin')) {
            redirect(
                $baseurl,
                get_string('grantrevoked', 'local_placecom_mcp'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }
        redirect(
            $baseurl,
            get_string('grantnotfound', 'local_placecom_mcp'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    $user = core_user::get_user((int) $grant->userid);
    $client = (new client_manager())->get((int) $grant->clientid);
    $a = new stdClass();
    $a->user = $user ? fullname($user) : (string) $grant->userid;
    $a->client = $client ? $client->get_display_name() : (string) $grant->clientid;

    $continue = new moodle_url($baseurl, ['action' => 'revoke', 'id' => $grant->id, 'confirm' => 1, 'sesskey' => sesskey()]);
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('managegrants', 'local_placecom_mcp'));
    echo $OUTPUT->confirm(get_string('revoke_confirm', 'local_placecom_mcp', $a), $continue, $baseurl);
    echo $OUTPUT->footer();
    die();
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('managegrants', 'local_placecom_mcp'));

if (!$DB->record_exists('local_placecom_mcp_grant', [])) {
    echo $OUTPUT->notification(get_string('nogrants', 'local_placecom_mcp'), \core\output\notification::NOTIFY_INFO);
} else {
    $table = new grants_table('local_placecom_mcp_grants');
    $table->out(50, false);
}

echo $OUTPUT->footer();
