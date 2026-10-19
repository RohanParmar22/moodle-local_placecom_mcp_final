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
 * Consent screen form.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use html_writer;
use local_placecom_mcp\local\oauth\authorization_request;
use local_placecom_mcp\local\oauth\config;
use local_placecom_mcp\local\oauth\scopes;

/**
 * Asks the user to allow or refuse an assistant's access. Custom data: `['request' => authorization_request]`.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class consent_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        /** @var authorization_request $request */
        $request = $this->_customdata['request'];

        $clientname = $request->get_client()->get_display_name();
        $mform->addElement('html', html_writer::tag('p', get_string('consent_question', config::COMPONENT, $clientname)));
        $mform->addElement('html', html_writer::tag('p', html_writer::tag(
            'strong',
            get_string('consent_dataheading', config::COMPONENT)
        )));

        $items = [];
        foreach ($request->get_scopes() as $scope) {
            $items[] = s(scopes::describe($scope));
        }
        if (empty($items)) {
            $items[] = s(get_string('consent_login', config::COMPONENT));
        }
        $mform->addElement('html', html_writer::alist($items));

        foreach ($request->get_params() as $name => $value) {
            $mform->addElement('hidden', $name, $value);
            $mform->setType($name, PARAM_RAW);
        }

        $buttons = [
            $mform->createElement('submit', 'submitbutton', get_string('consent_confirm', config::COMPONENT)),
            $mform->createElement('cancel', 'cancel', get_string('consent_cancel', config::COMPONENT)),
        ];
        $mform->addGroup($buttons, 'buttonar', '', [' '], false);
        $mform->closeHeaderBefore('buttonar');
    }
}
