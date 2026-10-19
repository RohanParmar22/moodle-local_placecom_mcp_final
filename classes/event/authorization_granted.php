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
 * Authorization granted event.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\event;

/**
 * Triggered when a user approves an assistant's authorization request (with or without the consent screen).
 *
 * The event never carries tokens, codes, secrets or their hashes.
 *
 * @property-read array $other {
 *      Extra information about the event.
 *      - string clientid: public client identifier.
 *      - string scope: granted scope.
 *      - bool consentshown: whether the consent screen was confirmed.
 * }
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class authorization_granted extends \core\event\base {
    /**
     * Initialise the event data.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_placecom_mcp_consent';
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_authorization_granted', 'local_placecom_mcp');
    }

    /**
     * Non-localised description of what happened.
     *
     * @return string
     */
    public function get_description() {
        $how = !empty($this->other['consentshown']) ? 'on the consent screen' : 'using an earlier consent';
        return "The user with id '{$this->userid}' authorized the OAuth client '" . s($this->other['clientid']) .
            "' {$how} with scope '" . s($this->other['scope']) . "'.";
    }

    /**
     * Validate the event data.
     *
     * @throws \coding_exception
     */
    protected function validate_data() {
        parent::validate_data();
        if (empty($this->data['objectid'])) {
            // Approval based on an earlier consent that no longer has a row: the event has no object.
            $this->data['objecttable'] = null;
        }
        if (empty($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }
        foreach (['clientid', 'scope', 'consentshown'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("The '{$key}' value must be set in other.");
            }
        }
    }

    /**
     * Object id mapping for restore.
     *
     * @return int
     */
    public static function get_objectid_mapping() {
        return \core\event\base::NOT_MAPPED;
    }

    /**
     * Other data mapping for restore.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
