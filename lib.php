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
 * Library callbacks of the Placecom MCP Connector.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Adds a "Connected AI assistants" link to the current user's own profile.
 *
 * @param \core_user\output\myprofile\tree $tree Profile tree.
 * @param stdClass $user Profile owner.
 * @param bool $iscurrentuser Whether the viewer is the profile owner.
 * @param stdClass|null $course Course of the profile view, if any.
 * @return void
 */
function local_placecom_mcp_myprofile_navigation(\core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course): void {
    if (!$iscurrentuser || isguestuser($user) || empty($user->id)) {
        return;
    }
    if (!\local_placecom_mcp\local\oauth\user_guard::can_use((int) $user->id)) {
        return;
    }
    // The privacy category is normally provided by tool_dataprivacy; create it when it is missing.
    $categories = $tree->__get('categories');
    if (!array_key_exists('privacyandpolicies', $categories)) {
        $tree->add_category(new \core_user\output\myprofile\category(
            'privacyandpolicies',
            get_string('privacyandpolicies', 'admin'),
            'contact'
        ));
    }
    $node = new \core_user\output\myprofile\node(
        'privacyandpolicies',
        'local_placecom_mcp_connections',
        get_string('myconnections', 'local_placecom_mcp'),
        null,
        new moodle_url('/local/placecom_mcp/connections.php')
    );
    $tree->add_node($node);
}
