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
 * Administration page to register or edit an OAuth client.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_placecom_mcp\form\client_form;
use local_placecom_mcp\local\client\client_manager;

$id = optional_param('id', 0, PARAM_INT);

admin_externalpage_setup('local_placecom_mcp_clients', '', null, new moodle_url('/local/placecom_mcp/editclient.php'));

$listurl = new moodle_url('/local/placecom_mcp/clients.php');
$pageurl = new moodle_url('/local/placecom_mcp/editclient.php', ['id' => $id]);
$PAGE->set_url($pageurl);

$manager = new client_manager();
$client = null;
if ($id) {
    $client = $manager->get($id);
    if ($client === null) {
        redirect(
            $listurl,
            get_string('clientnotfound', 'local_placecom_mcp'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

$mode = $client ? 'edit' : 'add';
$title = get_string($client ? 'editclient' : 'addclient', 'local_placecom_mcp');
$PAGE->set_title($title);
$PAGE->navbar->add($title);

$form = new client_form($pageurl, ['mode' => $mode, 'client' => $client]);

if ($form->is_cancelled()) {
    redirect($listurl);
}

if ($data = $form->get_data()) {
    try {
        if ($client === null) {
            $requirepkce = !empty($data->requirepkce);
            // Without PKCE a secret is mandatory, so the client is always confidential then.
            $generatesecret = $requirepkce ? !empty($data->generatesecret) : true;
            $result = $manager->create((object) [
                'clientid' => $data->clientid,
                'name' => $data->name,
                'redirecturis' => $data->redirecturis,
                'scope' => $data->scope,
                'requirepkce' => $requirepkce ? 1 : 0,
            ], $generatesecret);
            if ($result['secret'] !== null) {
                \core\notification::warning(get_string('secret_shownonce', 'local_placecom_mcp', $result['secret']));
            }
            redirect(
                $listurl,
                get_string('clientcreated', 'local_placecom_mcp'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        } else {
            $changes = (object) [
                'name' => $data->name,
                'redirecturis' => $data->redirecturis,
                'scope' => $data->scope,
                'enabled' => empty($data->enabled) ? 0 : 1,
            ];
            if (isset($data->requirepkce)) {
                $changes->requirepkce = empty($data->requirepkce) ? 0 : 1;
            }
            $manager->update($client->get_id(), $changes);
            redirect(
                $listurl,
                get_string('clientupdated', 'local_placecom_mcp'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }
    } catch (invalid_parameter_exception $e) {
        \core\notification::error($e->getMessage());
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading($title);
$form->display();
echo $OUTPUT->footer();
