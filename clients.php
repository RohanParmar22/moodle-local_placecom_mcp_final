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
 * Administration page listing OAuth clients, with delete, rotate-secret and enable/disable actions.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_placecom_mcp\local\client\client_manager;
use local_placecom_mcp\table\clients_table;

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

admin_externalpage_setup('local_placecom_mcp_clients');

$baseurl = new moodle_url('/local/placecom_mcp/clients.php');
$manager = new client_manager();

if ($action !== '') {
    $client = $manager->get($id);
    if ($client === null) {
        redirect(
            $baseurl,
            get_string('clientnotfound', 'local_placecom_mcp'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    switch ($action) {
        case 'enable':
        case 'disable':
            require_sesskey();
            $manager->set_enabled($client->get_id(), $action === 'enable');
            redirect(
                $baseurl,
                get_string('clientupdated', 'local_placecom_mcp'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;

        case 'rotate':
            if ($confirm && confirm_sesskey()) {
                $secret = $manager->rotate_secret($client->get_id());
                \core\notification::warning(get_string('secret_shownonce', 'local_placecom_mcp', $secret));
                redirect(
                    $baseurl,
                    get_string('clientupdated', 'local_placecom_mcp'),
                    null,
                    \core\output\notification::NOTIFY_SUCCESS
                );
            }
            $message = get_string('rotatesecret_confirm', 'local_placecom_mcp', s($client->get_clientid()));
            break;

        case 'delete':
            if ($confirm && confirm_sesskey()) {
                if ($manager->delete($client->get_id())) {
                    redirect(
                        $baseurl,
                        get_string('clientdeleted', 'local_placecom_mcp'),
                        null,
                        \core\output\notification::NOTIFY_SUCCESS
                    );
                }
                redirect(
                    $baseurl,
                    get_string('clientnotfound', 'local_placecom_mcp'),
                    null,
                    \core\output\notification::NOTIFY_ERROR
                );
            }
            $message = get_string('deleteclient_confirm', 'local_placecom_mcp', s($client->get_clientid()));
            break;

        default:
            redirect($baseurl);
    }

    $continue = new moodle_url($baseurl, [
        'action' => $action,
        'id' => $client->get_id(),
        'confirm' => 1,
        'sesskey' => sesskey(),
    ]);
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('manageclients', 'local_placecom_mcp'));
    echo $OUTPUT->confirm($message, $continue, $baseurl);
    echo $OUTPUT->footer();
    die();
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('manageclients', 'local_placecom_mcp'));
echo $OUTPUT->single_button(
    new moodle_url('/local/placecom_mcp/editclient.php'),
    get_string('addclient', 'local_placecom_mcp'),
    'get'
);

$table = new clients_table('local_placecom_mcp_clients');
$table->out(50, false);

echo $OUTPUT->footer();
