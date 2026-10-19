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
 * Page where users review and disconnect the AI assistants connected to their account.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_placecom_mcp\local\client\client_manager;
use local_placecom_mcp\local\oauth\scopes;
use local_placecom_mcp\local\oauth\token_store;

$action = optional_param('action', '', PARAM_ALPHA);
$clientdbid = optional_param('clientid', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

require_login(null, false);
if (isguestuser()) {
    throw new moodle_exception('noguest');
}
require_capability('local/placecom_mcp:use', context_system::instance());

$pageurl = new moodle_url('/local/placecom_mcp/connections.php');
$title = get_string('myconnections', 'local_placecom_mcp');
$PAGE->set_url($pageurl);
$PAGE->set_context(context_user::instance($USER->id));
$PAGE->set_pagelayout('standard');
$PAGE->set_title($title);
$PAGE->set_heading(fullname($USER));
$PAGE->navbar->add(get_string('preferences'), new moodle_url('/user/preferences.php'));
$PAGE->navbar->add($title, $pageurl);

// Consents and connections of the current user, keyed by client database id.
$connections = [];
$consents = $DB->get_records('local_placecom_mcp_consent', ['userid' => $USER->id], 'id ASC');
foreach ($consents as $consent) {
    $cid = (int) $consent->clientid;
    $connections[$cid] = (object) [
        'scope' => $consent->scope,
        'since' => (int) $consent->timecreated,
        'lastused' => 0,
    ];
}
$grants = $DB->get_records('local_placecom_mcp_grant', ['userid' => $USER->id], 'timecreated ASC, id ASC');
foreach ($grants as $grant) {
    $cid = (int) $grant->clientid;
    if (!isset($connections[$cid])) {
        $connections[$cid] = (object) ['scope' => $grant->scope, 'since' => (int) $grant->timecreated, 'lastused' => 0];
    }
    $connections[$cid]->since = min($connections[$cid]->since ?: PHP_INT_MAX, (int) $grant->timecreated);
    $connections[$cid]->lastused = max($connections[$cid]->lastused, (int) $grant->timeaccessed);
}

$manager = new client_manager();

if ($action === 'disconnect') {
    $client = $manager->get($clientdbid);
    if ($client === null || !isset($connections[$clientdbid])) {
        redirect(
            $pageurl,
            get_string('clientnotfound', 'local_placecom_mcp'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    if ($confirm && confirm_sesskey()) {
        (new token_store())->revoke_user_client($client->get_id(), (int) $USER->id, 'user');
        redirect(
            $pageurl,
            get_string('disconnected', 'local_placecom_mcp', $client->get_display_name()),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    $continue = new moodle_url($pageurl, [
        'action' => 'disconnect',
        'clientid' => $client->get_id(),
        'confirm' => 1,
        'sesskey' => sesskey(),
    ]);
    echo $OUTPUT->header();
    echo $OUTPUT->heading($title);
    echo $OUTPUT->confirm(
        get_string('disconnect_confirm', 'local_placecom_mcp', $client->get_display_name()),
        $continue,
        $pageurl
    );
    echo $OUTPUT->footer();
    die();
}

echo $OUTPUT->header();
echo $OUTPUT->heading($title);
echo html_writer::tag('p', get_string('myconnections_intro', 'local_placecom_mcp'));

$table = new html_table();
$table->attributes['class'] = 'generaltable local_placecom_mcp_connections';
$table->head = [
    get_string('grantclient', 'local_placecom_mcp'),
    get_string('grantscope', 'local_placecom_mcp'),
    get_string('connectedsince', 'local_placecom_mcp'),
    get_string('lastused', 'local_placecom_mcp'),
    get_string('actions', 'local_placecom_mcp'),
];
$format = get_string('strftimedatetimeshort', 'core_langconfig');
foreach ($connections as $cid => $connection) {
    $client = $manager->get($cid);
    if ($client === null) {
        continue;
    }
    $descriptions = array_map(fn(string $scope): string => s(scopes::describe($scope)), scopes::parse($connection->scope));
    $button = new single_button(
        new moodle_url($pageurl, ['action' => 'disconnect', 'clientid' => $cid]),
        get_string('disconnect', 'local_placecom_mcp'),
        'get'
    );
    $table->data[] = [
        $client->get_display_name(),
        html_writer::alist($descriptions),
        $connection->since ? userdate($connection->since, $format) : get_string('never', 'local_placecom_mcp'),
        $connection->lastused ? userdate($connection->lastused, $format) : get_string('never', 'local_placecom_mcp'),
        $OUTPUT->render($button),
    ];
}

if (empty($table->data)) {
    echo $OUTPUT->notification(get_string('noconnections', 'local_placecom_mcp'), \core\output\notification::NOTIFY_INFO);
} else {
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
