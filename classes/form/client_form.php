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
 * Form to register or edit an OAuth client.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use local_placecom_mcp\local\client\client;
use local_placecom_mcp\local\client\client_manager;
use local_placecom_mcp\local\oauth\scopes;

/**
 * Add/edit form for OAuth clients.
 *
 * Custom data: 'mode' ('add' or 'edit') and 'client' (client|null, required in edit mode).
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class client_form extends \moodleform {
    /** @var string Scope suggested for new clients. */
    public const DEFAULT_SCOPE = 'openid profile email offline_access moodle_mcp_read';

    /**
     * Defines the form elements.
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;
        $isadd = $this->is_add_mode();

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('text', 'clientid', get_string('clientid', 'local_placecom_mcp'), ['size' => 50, 'maxlength' => 100]);
        $mform->setType('clientid', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('clientid', 'clientid', 'local_placecom_mcp');
        if ($isadd) {
            $mform->addRule('clientid', get_string('error_clientidrequired', 'local_placecom_mcp'), 'required', null, 'client');
        } else {
            $mform->freeze('clientid');
        }

        $mform->addElement('text', 'name', get_string('clientname', 'local_placecom_mcp'), ['size' => 50, 'maxlength' => 255]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addHelpButton('name', 'clientname', 'local_placecom_mcp');

        $mform->addElement(
            'textarea',
            'redirecturis',
            get_string('redirecturis', 'local_placecom_mcp'),
            ['rows' => 4, 'cols' => 70]
        );
        $mform->setType('redirecturis', PARAM_RAW);
        $mform->addHelpButton('redirecturis', 'redirecturis', 'local_placecom_mcp');

        $mform->addElement('text', 'scope', get_string('scope', 'local_placecom_mcp'), ['size' => 70]);
        $mform->setType('scope', PARAM_RAW);
        $mform->setDefault('scope', self::DEFAULT_SCOPE);
        $mform->addHelpButton('scope', 'scope', 'local_placecom_mcp');

        $mform->addElement('advcheckbox', 'requirepkce', get_string('requirepkce', 'local_placecom_mcp'));
        $mform->setDefault('requirepkce', 1);
        $mform->addHelpButton('requirepkce', 'requirepkce', 'local_placecom_mcp');

        if ($isadd) {
            $mform->addElement('advcheckbox', 'generatesecret', get_string('generatesecret', 'local_placecom_mcp'));
            $mform->setDefault('generatesecret', 1);
            $mform->addHelpButton('generatesecret', 'generatesecret', 'local_placecom_mcp');
            $mform->disabledIf('generatesecret', 'requirepkce', 'notchecked');
        } else {
            $mform->addElement('advcheckbox', 'enabled', get_string('clientenabled', 'local_placecom_mcp'));
            $mform->setDefault('enabled', 1);
        }

        $this->add_action_buttons(true, get_string($isadd ? 'addclient' : 'savechanges', $isadd ? 'local_placecom_mcp' : 'core'));

        $client = $this->_customdata['client'] ?? null;
        if (!$isadd && $client instanceof client) {
            $record = $client->to_record();
            $this->set_data([
                'id' => $record->id,
                'clientid' => $record->clientid,
                'name' => $record->name,
                'redirecturis' => $record->redirecturis,
                'scope' => $record->scope,
                'requirepkce' => $client->requires_pkce() ? 1 : 0,
                'enabled' => $record->enabled,
            ]);
            if ($client->is_public()) {
                // PKCE cannot be switched off for a client without a secret.
                $mform->freeze('requirepkce');
            }
        }
    }

    /**
     * Validates the submitted values.
     *
     * @param array $data Submitted values.
     * @param array $files Uploaded files (unused).
     * @return array Errors keyed by element name.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $manager = new client_manager();

        if ($this->is_add_mode()) {
            $clientid = trim((string) ($data['clientid'] ?? ''));
            if ($clientid === '') {
                $errors['clientid'] = get_string('error_clientidrequired', 'local_placecom_mcp');
            } else if (!client_manager::validate_clientid($clientid)) {
                $errors['clientid'] = get_string('error_clientidinvalid', 'local_placecom_mcp');
            } else if ($manager->clientid_taken($clientid)) {
                $errors['clientid'] = get_string('error_clientidexists', 'local_placecom_mcp');
            }
        }

        $uris = client_manager::parse_redirect_uris((string) ($data['redirecturis'] ?? ''));
        if (empty($uris)) {
            $errors['redirecturis'] = get_string('error_redirecturirequired', 'local_placecom_mcp');
        } else if (count($uris) > client_manager::MAX_REDIRECT_URIS) {
            $errors['redirecturis'] = get_string(
                'error_redirecturitoomany',
                'local_placecom_mcp',
                client_manager::MAX_REDIRECT_URIS
            );
        } else {
            foreach ($uris as $uri) {
                if (!client_manager::validate_redirect_uri($uri)) {
                    $errors['redirecturis'] = get_string('error_redirecturiinvalid', 'local_placecom_mcp', s($uri));
                    break;
                }
            }
        }

        $scopeerror = client_manager::validate_scope(scopes::normalise((string) ($data['scope'] ?? '')));
        if ($scopeerror !== null) {
            $errors['scope'] = get_string($scopeerror, 'local_placecom_mcp');
        }

        if (
            $this->is_add_mode() && empty($data['requirepkce'])
                && array_key_exists('generatesecret', $data) && empty($data['generatesecret'])
        ) {
            $errors['generatesecret'] = get_string('error_publicclientpkce', 'local_placecom_mcp');
        }

        return $errors;
    }

    /**
     * Whether the form registers a new client.
     *
     * @return bool
     */
    protected function is_add_mode(): bool {
        return ($this->_customdata['mode'] ?? 'add') !== 'edit';
    }
}
