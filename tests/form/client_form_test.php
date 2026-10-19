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
 * Tests for the OAuth client form.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\form;

use local_placecom_mcp\local\client\client_manager;

#[\PHPUnit\Framework\Attributes\CoversClass(client_form::class)]
/**
 * Tests for {@see client_form} validation.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_placecom_mcp\form\client_form
 */
final class client_form_test extends \advanced_testcase {
    /**
     * Submits the add form with the baseline values overridden by $overrides and returns the errors.
     *
     * @param array $overrides Values replacing the baseline.
     * @return array Validation errors keyed by element.
     */
    private function validate_add(array $overrides = []): array {
        $data = array_merge([
            'id' => 0,
            'clientid' => 'form-test-client',
            'name' => '',
            'redirecturis' => 'https://example.com/callback',
            'scope' => 'openid moodle_mcp_read',
            'requirepkce' => 1,
            'generatesecret' => 1,
        ], $overrides);
        client_form::mock_submit($data);
        $form = new client_form(null, ['mode' => 'add', 'client' => null]);
        return $form->validation($data, []);
    }

    /**
     * The baseline input is valid and get_data() returns it.
     *
     * @return void
     */
    public function test_baseline_valid(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->assertSame([], $this->validate_add());

        $form = new client_form(null, ['mode' => 'add', 'client' => null]);
        $data = $form->get_data();
        $this->assertNotNull($data);
        $this->assertSame('form-test-client', $data->clientid);
    }

    /**
     * Data provider for {@see self::test_validation()}.
     *
     * @return array
     */
    public static function validation_provider(): array {
        $many = implode("\n", array_map(fn(int $i): string => 'https://example.com/cb' . $i, range(1, 11)));
        return [
            'blank redirect URI' => [['redirecturis' => ''], 'redirecturis'],
            'blank scope' => [['scope' => ''], 'scope'],
            'write without read' => [['scope' => 'openid profile moodle_mcp_write'], 'scope'],
            'unknown scope' => [['scope' => 'openid moodle_mcp_read teacher.read'], 'scope'],
            'http remote redirect' => [['redirecturis' => 'http://example.com/callback'], 'redirecturis'],
            'too many redirect URIs' => [['redirecturis' => $many], 'redirecturis'],
            'blank client id' => [['clientid' => ''], 'clientid'],
            'client id with space' => [['clientid' => 'form test client'], 'clientid'],
            'public client without PKCE' => [['requirepkce' => 0, 'generatesecret' => 0], 'generatesecret'],
        ];
    }

    /**
     * Invalid input produces an error on the expected element.
     *
     * @dataProvider validation_provider
     * @param array $overrides Values replacing the baseline.
     * @param string $element Element expected to have an error.
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('validation_provider')]
    public function test_validation(array $overrides, string $element): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $errors = $this->validate_add($overrides);
        $this->assertArrayHasKey($element, $errors);
    }

    /**
     * Accepted variants produce no scope or redirect URI error.
     *
     * @return void
     */
    public function test_valid_variants(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $errors = $this->validate_add(['scope' => 'openid profile email offline_access moodle_mcp_read']);
        $this->assertArrayNotHasKey('scope', $errors);
        $this->assertArrayNotHasKey('redirecturis', $errors);

        $errors = $this->validate_add(['scope' => 'openid moodle_mcp_read moodle_mcp_write']);
        $this->assertArrayNotHasKey('scope', $errors);

        $errors = $this->validate_add(['scope' => 'openid,moodle_mcp_read']);
        $this->assertArrayNotHasKey('scope', $errors);

        $errors = $this->validate_add(['redirecturis' => 'http://localhost:8080/callback']);
        $this->assertArrayNotHasKey('redirecturis', $errors);

        $errors = $this->validate_add(['redirecturis' => "https://example.com/a\nhttps://example.com/b"]);
        $this->assertArrayNotHasKey('redirecturis', $errors);

        // Without PKCE a secret is generated, which is fine.
        $errors = $this->validate_add(['requirepkce' => 0, 'generatesecret' => 1]);
        $this->assertSame([], $errors);
    }

    /**
     * An identifier already in use is refused.
     *
     * @return void
     */
    public function test_duplicate_clientid(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        (new client_manager())->create((object) [
            'clientid' => 'form-test-client',
            'redirecturis' => 'https://example.com/callback',
            'scope' => 'openid moodle_mcp_read',
        ], true);

        $errors = $this->validate_add();
        $this->assertArrayHasKey('clientid', $errors);
    }

    /**
     * In edit mode the identifier is not validated again.
     *
     * @return void
     */
    public function test_edit_mode(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $client = (new client_manager())->create((object) [
            'clientid' => 'form-test-client',
            'redirecturis' => 'https://example.com/callback',
            'scope' => 'openid moodle_mcp_read',
        ], true)['client'];

        $data = [
            'id' => $client->get_id(),
            'name' => 'Edited',
            'redirecturis' => 'https://example.com/callback',
            'scope' => 'openid moodle_mcp_read',
            'requirepkce' => 1,
            'enabled' => 1,
        ];
        client_form::mock_submit($data);
        $form = new client_form(null, ['mode' => 'edit', 'client' => $client]);
        $this->assertSame([], $form->validation($data, []));
    }
}
