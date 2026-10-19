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
 * Event: a valid MCP access token was refused because of its owner's account state.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\event;

/**
 * Triggered when the MCP endpoint refuses a valid access token, for example because the owner is
 * suspended, deleted or the site is in maintenance mode. The token itself is never recorded.
 *
 * @property-read array $other {
 *      Extra information about the event.
 *
 *      - string clientid: public identifier of the OAuth client.
 *      - string reason: refusal reason (a user_guard constant).
 * }
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mcp_access_refused extends \core\event\base {
    /**
     * Initialise the event data.
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_mcp_access_refused', 'local_placecom_mcp');
    }

    /**
     * Non-localised description, free of secrets.
     *
     * @return string
     */
    public function get_description() {
        $clientid = s($this->other['clientid']);
        $reason = s($this->other['reason']);
        return "The MCP endpoint refused the access token of the user with id '{$this->userid}' " .
            "issued to the client '{$clientid}' (reason: {$reason}).";
    }

    /**
     * Validate the event data.
     *
     * @throws \coding_exception
     */
    protected function validate_data() {
        parent::validate_data();
        foreach (['clientid', 'reason'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("The '{$key}' value must be set in other.");
            }
        }
    }

    /**
     * This event has no object.
     *
     * @return int|false
     */
    public static function get_objectid_mapping() {
        return \core\event\base::NOT_MAPPED;
    }

    /**
     * Nothing in other needs mapping on restore.
     *
     * @return array|false
     */
    public static function get_other_mapping() {
        return false;
    }
}
