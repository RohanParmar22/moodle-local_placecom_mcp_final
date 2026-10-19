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
 * Administration settings and pages of the Placecom MCP Connector.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_placecom_mcp\local\oauth\config;

// The category and the external pages are always added: each page checks its own capability,
// so managers without site configuration rights can still reach the client and connection pages.
$ADMIN->add('server', new admin_category('local_placecom_mcp', get_string('pluginname', 'local_placecom_mcp')));

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_placecom_mcp_settings', get_string('settings', 'local_placecom_mcp'));

    if ($ADMIN->fulltree) {
        $info = new stdClass();
        $info->mcp = config::endpoint_url('mcp.php');
        $info->discovery = config::endpoint_url('discovery.php');
        $info->resource = config::endpoint_url('protected_resource.php');
        $info->jwks = config::endpoint_url('jwks.php');
        $info->wsstatus = empty($CFG->enablewebservices)
            ? get_string('wsdisabledwarning', 'local_placecom_mcp')
            : get_string('wsenabled', 'local_placecom_mcp');
        $settings->add(new admin_setting_heading(
            'local_placecom_mcp/endpointsinfo',
            get_string('endpointsinfo', 'local_placecom_mcp'),
            get_string('endpointsinfo_desc', 'local_placecom_mcp', $info)
        ));

        $settings->add(new admin_setting_configcheckbox(
            'local_placecom_mcp/enabled',
            get_string('enabled', 'local_placecom_mcp'),
            get_string('enabled_desc', 'local_placecom_mcp'),
            1
        ));

        $settings->add(new admin_setting_configduration(
            'local_placecom_mcp/accesstokenlifetime',
            get_string('accesstokenlifetime', 'local_placecom_mcp'),
            get_string('accesstokenlifetime_desc', 'local_placecom_mcp'),
            HOURSECS
        ));

        $settings->add(new admin_setting_configduration(
            'local_placecom_mcp/refreshtokenlifetime',
            get_string('refreshtokenlifetime', 'local_placecom_mcp'),
            get_string('refreshtokenlifetime_desc', 'local_placecom_mcp'),
            WEEKSECS
        ));

        $settings->add(new admin_setting_configtext(
            'local_placecom_mcp/issuer',
            get_string('issuer', 'local_placecom_mcp'),
            get_string('issuer_desc', 'local_placecom_mcp'),
            '',
            PARAM_URL
        ));
    }

    $ADMIN->add('local_placecom_mcp', $settings);
}

$ADMIN->add('local_placecom_mcp', new admin_externalpage(
    'local_placecom_mcp_clients',
    get_string('manageclients', 'local_placecom_mcp'),
    new moodle_url('/local/placecom_mcp/clients.php'),
    'local/placecom_mcp:manage'
));

$ADMIN->add('local_placecom_mcp', new admin_externalpage(
    'local_placecom_mcp_grants',
    get_string('managegrants', 'local_placecom_mcp'),
    new moodle_url('/local/placecom_mcp/grants.php'),
    'local/placecom_mcp:manage'
));
