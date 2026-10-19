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
 * OAuth 2.0 / OpenID Connect authorization endpoint with the consent screen.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_placecom_mcp\form\consent_form;
use local_placecom_mcp\local\oauth\authorization_error;
use local_placecom_mcp\local\oauth\authorize_controller;

// phpcs:ignore moodle.Files.RequireLogin.Missing -- The request is validated first; require_login() follows below.
require(__DIR__ . '/../../config.php');

$params = authorize_controller::read_params();
$context = context_system::instance();
$PAGE->set_url(new moodle_url('/local/placecom_mcp/authorize.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('login');

$controller = new authorize_controller();

// 1. Validate the request before anything else; invalid requests never reach the login page.
try {
    $request = $controller->validate($params);
} catch (authorization_error $e) {
    authorize_controller::log_failure($params, $e, isloggedin() ? (int) $USER->id : null);
    $e->to_response()->send();
    die();
}
$PAGE->set_url($request->get_url());

// 2. Make sure a real user is logged in.
if (!isloggedin() || isguestuser()) {
    if ($request->has_prompt('none')) {
        $controller->deny($request, $USER, 'login_required')->send();
        die();
    }
    $SESSION->wantsurl = $request->get_url()->out(false);
    redirect(get_login_url());
}
require_login(null, false);

// 3. Account state and capability.
try {
    $controller->check_user($request, $USER);
} catch (authorization_error $e) {
    authorize_controller::log_failure($params, $e, (int) $USER->id);
    $e->to_response()->send();
    die();
}

// 4. Consent.
if (!$controller->needs_consent($request, $USER)) {
    $controller->approve($request, $USER, false)->send();
    die();
}
if ($request->has_prompt('none')) {
    $controller->deny($request, $USER, 'consent_required')->send();
    die();
}

$form = new consent_form($request->get_url(), ['request' => $request]);
if ($form->is_cancelled()) {
    require_sesskey();
    $controller->deny($request, $USER, 'access_denied')->send();
    die();
}
if ($form->get_data()) {
    require_sesskey();
    $controller->approve($request, $USER, true)->send();
    die();
}

$title = get_string('consent_title', 'local_placecom_mcp');
$PAGE->set_title($title);
$PAGE->set_heading($title);

echo $OUTPUT->header();
echo $OUTPUT->heading($title);
$form->display();
echo $OUTPUT->footer();
