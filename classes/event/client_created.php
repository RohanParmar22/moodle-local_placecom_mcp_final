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
 * Event triggered when an OAuth client is registered.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\event;

/**
 * An administrator registered an OAuth client.
 *
 * Expected 'other' values:
 * - string clientid: public identifier of the client.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class client_created extends \core\event\base {
    /**
     * Sets the basic event properties.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_placecom_mcp_client';
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_client_created', 'local_placecom_mcp');
    }

    /**
     * Non-localised description for the logs.
     *
     * @return string
     */
    public function get_description() {
        $clientid = s($this->other['clientid']);
        return "The user with id '{$this->userid}' registered the OAuth client '{$clientid}' (id '{$this->objectid}').";
    }

    /**
     * Link to the client administration page.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/local/placecom_mcp/clients.php');
    }

    /**
     * Checks that the required data is present.
     *
     * @return void
     * @throws \coding_exception
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['clientid'])) {
            throw new \coding_exception('The \'clientid\' value must be set in other.');
        }
        if ($this->contextlevel != CONTEXT_SYSTEM) {
            throw new \coding_exception('Context level must be CONTEXT_SYSTEM.');
        }
    }

    /**
     * Clients are not part of course backups.
     *
     * @return int|array
     */
    public static function get_objectid_mapping() {
        return \core\event\base::NOT_MAPPED;
    }

    /**
     * Nothing in 'other' needs mapping on restore.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
