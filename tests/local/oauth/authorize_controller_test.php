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
 * Tests for the authorization endpoint logic.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\oauth;

use local_placecom_mcp\event\authorization_denied;
use local_placecom_mcp\event\authorization_granted;
use local_placecom_mcp\local\http_response;

#[\PHPUnit\Framework\Attributes\CoversClass(authorize_controller::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(authorization_request::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(authorization_error::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(http_response::class)]
/**
 * Tests for {@see authorize_controller}.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_placecom_mcp\local\oauth\authorize_controller
 * @covers     \local_placecom_mcp\local\oauth\authorization_request
 * @covers     \local_placecom_mcp\local\oauth\authorization_error
 * @covers     \local_placecom_mcp\local\http_response
 */
final class authorize_controller_test extends \advanced_testcase {
    /** @var string A valid S256 challenge. */
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    /**
     * The plugin generator.
     *
     * @return \local_placecom_mcp_generator
     */
    private function generator(): \local_placecom_mcp_generator {
        return $this->getDataGenerator()->get_plugin_generator('local_placecom_mcp');
    }

    /**
     * Valid request parameters for the given client, overridden by $overrides.
     *
     * @param \stdClass $client Client record.
     * @param array $overrides Values to replace ('' removes a parameter).
     * @return array
     */
    private function params(\stdClass $client, array $overrides = []): array {
        $params = array_fill_keys(authorize_controller::PARAMS, '');
        $params = array_merge($params, [
            'client_id' => $client->clientid,
            'response_type' => 'code',
            'redirect_uri' => 'https://example.com/callback',
            'scope' => 'openid profile email offline_access moodle_mcp_read',
            'state' => 'xyz',
            'code_challenge' => self::CHALLENGE,
            'code_challenge_method' => 'S256',
        ]);
        return array_merge($params, $overrides);
    }

    /**
     * Run validate() and return the error it raises.
     *
     * @param array $params Parameters.
     * @return authorization_error
     */
    private function validation_error(array $params): authorization_error {
        try {
            (new authorize_controller())->validate($params);
        } catch (authorization_error $e) {
            return $e;
        }
        $this->fail('Validation was expected to fail');
    }

    /**
     * Query parameters of a redirect response.
     *
     * @param http_response $response Response.
     * @return array
     */
    private function redirect_params(http_response $response): array {
        $this->assertSame(302, $response->get_status());
        $query = parse_url($response->get_header('Location'), PHP_URL_QUERY);
        parse_str((string) $query, $result);
        return $result;
    }

    /**
     * Errors before the redirect URI is trusted are 400 JSON.
     */
    public function test_non_redirect_errors(): void {
        $this->resetAfterTest();
        $client = $this->generator()->create_client([
            'redirecturis' => "https://example.com/callback\nhttps://example.com/other",
        ]);
        $disabled = $this->generator()->create_client(['enabled' => 0]);

        $cases = [
            [['client_id' => ''], 'invalid_request'],
            [['client_id' => 'nosuchclient'], 'invalid_client'],
            [['client_id' => $disabled->clientid], 'invalid_client'],
            [['redirect_uri' => 'https://example.com/callback#frag'], 'invalid_request'],
            [['redirect_uri' => 'https://evil.example.com/callback'], 'invalid_request'],
            [['redirect_uri' => 'https://example.com/callback/'], 'invalid_request'],
            [['redirect_uri' => ''], 'invalid_request'],
        ];
        foreach ($cases as [$overrides, $expected]) {
            $e = $this->validation_error($this->params($client, $overrides));
            $this->assertFalse($e->is_redirectable(), json_encode($overrides));
            $response = $e->to_response();
            $this->assertSame(400, $response->get_status());
            $this->assertSame($expected, $response->get_json()['error'], json_encode($overrides));
            $this->assertNull($response->get_header('Location'));
        }
    }

    /**
     * With one registered URI the redirect_uri may be omitted.
     */
    public function test_redirect_uri_omitted(): void {
        $this->resetAfterTest();
        $client = $this->generator()->create_client();
        $request = (new authorize_controller())->validate($this->params($client, ['redirect_uri' => '']));
        $this->assertSame('https://example.com/callback', $request->get_redirect_uri());
        $this->assertNull($request->get_supplied_redirect_uri());
    }

    /**
     * Errors after the redirect URI is trusted are redirects carrying error, state and iss.
     */
    public function test_redirect_errors(): void {
        $this->resetAfterTest();
        $client = $this->generator()->create_client(['scope' => 'openid profile moodle_mcp_read']);
        $nopkce = $this->generator()->create_client(['requirepkce' => 0]);
        $public = $this->generator()->create_client(['requirepkce' => 0, 'secret' => '']);

        $cases = [
            [$client, ['response_type' => ''], 'invalid_request'],
            [$client, ['response_type' => 'token'], 'unsupported_response_type'],
            [$client, ['response_type' => 'code id_token'], 'unsupported_response_type'],
            [$client, ['scope' => 'address phone'], 'invalid_scope'],
            [$client, ['state' => ''], 'invalid_request'],
            [$client, ['code_challenge' => ''], 'invalid_request'],
            [$client, ['code_challenge_method' => ''], 'invalid_request'],
            [$client, ['code_challenge_method' => 'plain'], 'invalid_request'],
            [$nopkce, ['code_challenge_method' => 'plain'], 'invalid_request'],
            [$client, ['code_challenge' => 'tooshort'], 'invalid_request'],
            [$public, ['code_challenge' => '', 'code_challenge_method' => ''], 'invalid_request'],
            [$client, ['prompt' => 'none consent'], 'invalid_request'],
            [$client, ['nonce' => str_repeat('n', 256)], 'invalid_request'],
        ];
        foreach ($cases as [$c, $overrides, $expected]) {
            $e = $this->validation_error($this->params($c, $overrides));
            $this->assertTrue($e->is_redirectable(), json_encode($overrides));
            $response = $e->to_response();
            $params = $this->redirect_params($response);
            $this->assertStringStartsWith('https://example.com/callback?', $response->get_header('Location'));
            $this->assertSame($expected, $params['error'], json_encode($overrides));
            $this->assertNotEmpty($params['error_description']);
            $this->assertSame(config::issuer(), $params['iss']);
            if (($overrides['state'] ?? 'xyz') !== '') {
                $this->assertSame('xyz', $params['state']);
            } else {
                $this->assertArrayNotHasKey('state', $params);
            }
        }
    }

    /**
     * Requested scopes are narrowed to the registered ones; no scope means the registered scopes.
     */
    public function test_scope_narrowing(): void {
        $this->resetAfterTest();
        $client = $this->generator()->create_client();
        $controller = new authorize_controller();

        $request = $controller->validate($this->params($client, [
            'scope' => 'openid profile email moodle_mcp_read moodle_mcp_write',
        ]));
        $this->assertSame(['openid', 'profile', 'email', 'moodle_mcp_read'], $request->get_scopes());
        $this->assertSame('openid profile email moodle_mcp_read', $request->get_scope());

        $request = $controller->validate($this->params($client, ['scope' => '']));
        $this->assertSame(['openid', 'profile', 'email', 'offline_access', 'moodle_mcp_read'], $request->get_scopes());

        // Without openid the state is optional.
        $request = $controller->validate($this->params($client, ['scope' => 'moodle_mcp_read', 'state' => '']));
        $this->assertNull($request->get_state());
    }

    /**
     * A valid request exposes its values.
     */
    public function test_valid_request(): void {
        $this->resetAfterTest();
        $client = $this->generator()->create_client(['clientid' => 'gemini']);
        $request = (new authorize_controller())->validate($this->params($client, [
            'nonce' => 'n-0S6',
            'prompt' => 'consent',
        ]));
        $this->assertSame('gemini', $request->get_client()->get_clientid());
        $this->assertSame('https://example.com/callback', $request->get_supplied_redirect_uri());
        $this->assertSame('xyz', $request->get_state());
        $this->assertSame(self::CHALLENGE, $request->get_code_challenge());
        $this->assertSame('n-0S6', $request->get_nonce());
        $this->assertTrue($request->has_prompt('consent'));
        $this->assertFalse($request->has_prompt('none'));
        $this->assertNotContains('', $request->get_params());
        $this->assertSame('n-0S6', $request->get_params()['nonce']);
        $url = $request->get_url()->out(false);
        $this->assertStringContainsString('/local/placecom_mcp/authorize.php?', $url);
        $this->assertStringContainsString('client_id=gemini', $url);
    }

    /**
     * Consent is needed when none is stored, not when a superset is stored, and always with prompt=consent.
     */
    public function test_needs_consent(): void {
        $this->resetAfterTest();
        $client = $this->generator()->create_client();
        $user = $this->getDataGenerator()->create_user();
        $controller = new authorize_controller();
        $request = $controller->validate($this->params($client, ['scope' => 'openid moodle_mcp_read']));

        $this->assertTrue($controller->needs_consent($request, $user));
        $this->generator()->create_consent(['clientid' => $client->id, 'userid' => $user->id]);
        $this->assertFalse($controller->needs_consent($request, $user));

        $forced = $controller->validate($this->params($client, ['scope' => 'openid moodle_mcp_read',
            'prompt' => 'consent']));
        $this->assertTrue($controller->needs_consent($forced, $user));
    }

    /**
     * Approval issues a code, keeps the existing query, saves consent only when shown and logs the grant.
     */
    public function test_approve(): void {
        global $DB;
        $this->resetAfterTest();
        $client = $this->generator()->create_client(['redirecturis' => 'https://example.com/cb?tenant=a&x=1']);
        $user = $this->getDataGenerator()->create_user();
        $controller = new authorize_controller();
        $request = $controller->validate($this->params($client, ['redirect_uri' => 'https://example.com/cb?tenant=a&x=1',
            'nonce' => 'abc']));

        $sink = $this->redirectEvents();
        $response = $controller->approve($request, $user, false);
        $events = $sink->get_events();
        $sink->close();

        $location = $response->get_header('Location');
        $this->assertStringStartsWith('https://example.com/cb?tenant=a&x=1&code=', $location);
        $this->assertStringNotContainsString('&amp;', $location);
        $params = $this->redirect_params($response);
        $this->assertSame('a', $params['tenant']);
        $this->assertSame('xyz', $params['state']);
        $this->assertSame(config::issuer(), $params['iss']);
        $this->assertSame(0, $DB->count_records('local_placecom_mcp_consent'));

        $code = (new token_store())->find_code($params['code']);
        $this->assertNotNull($code);
        $this->assertEquals($user->id, $code->userid);
        $this->assertSame('https://example.com/cb?tenant=a&x=1', $code->redirecturi);
        $this->assertSame(self::CHALLENGE, $code->codechallenge);
        $this->assertSame('abc', $code->nonce);
        $this->assertSame($request->get_scope(), $code->scope);

        $this->assertCount(1, $events);
        $this->assertInstanceOf(authorization_granted::class, $events[0]);
        $this->assertFalse($events[0]->other['consentshown']);
        $this->assertSame($client->clientid, $events[0]->other['clientid']);
        $this->assertStringNotContainsString($params['code'], json_encode($events[0]->get_data()));

        $sink = $this->redirectEvents();
        $controller->approve($request, $user, true);
        $events = $sink->get_events();
        $sink->close();
        $consent = (new consent_store())->get((int) $client->id, (int) $user->id);
        $this->assertNotNull($consent);
        $this->assertSame($request->get_scope(), $consent->scope);
        $this->assertTrue($events[0]->other['consentshown']);
        $this->assertEquals($consent->id, $events[0]->objectid);
    }

    /**
     * Denial redirects with the error and logs it.
     */
    public function test_deny(): void {
        $this->resetAfterTest();
        $client = $this->generator()->create_client();
        $user = $this->getDataGenerator()->create_user();
        $controller = new authorize_controller();
        $request = $controller->validate($this->params($client));

        $sink = $this->redirectEvents();
        $response = $controller->deny($request, $user);
        $events = $sink->get_events();
        $sink->close();

        $params = $this->redirect_params($response);
        $this->assertSame('access_denied', $params['error']);
        $this->assertSame('xyz', $params['state']);
        $this->assertSame(config::issuer(), $params['iss']);
        $this->assertArrayNotHasKey('code', $params);
        $this->assertCount(1, $events);
        $this->assertInstanceOf(authorization_denied::class, $events[0]);
        $this->assertSame('access_denied', $events[0]->other['error']);
        $this->assertEquals($user->id, $events[0]->userid);

        $params = $this->redirect_params($controller->deny($request, $user, 'consent_required'));
        $this->assertSame('consent_required', $params['error']);
    }

    /**
     * Suspended users and users without the capability are refused with access_denied.
     */
    public function test_check_user(): void {
        global $DB;
        $this->resetAfterTest();
        $client = $this->generator()->create_client();
        $controller = new authorize_controller();
        $request = $controller->validate($this->params($client));

        $ok = $this->getDataGenerator()->create_user();
        $controller->check_user($request, \core_user::get_user($ok->id));

        $suspended = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $nocap = $this->getDataGenerator()->create_user();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'user']);
        assign_capability('local/placecom_mcp:use', CAP_PROHIBIT, $roleid, \context_system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        foreach ([$suspended, $nocap] as $user) {
            try {
                $controller->check_user($request, \core_user::get_user($user->id));
                $this->fail('User should have been refused');
            } catch (authorization_error $e) {
                $this->assertTrue($e->is_redirectable());
                $this->assertSame('access_denied', $e->get_error());
                $this->assertSame('access_denied', $this->redirect_params($e->to_response())['error']);
            }
        }
    }

    /**
     * Failures are logged only when a client id was supplied.
     */
    public function test_log_failure(): void {
        $this->resetAfterTest();
        $error = new authorization_error('invalid_client', 'x');
        $sink = $this->redirectEvents();
        authorize_controller::log_failure(['client_id' => ''], $error, null);
        $this->assertSame(0, $sink->count());
        authorize_controller::log_failure(['client_id' => str_repeat('c', 150), 'scope' => 'openid'], $error, null);
        $events = $sink->get_events();
        $sink->close();
        $this->assertCount(1, $events);
        $this->assertSame(100, strlen($events[0]->other['clientid']));
        $this->assertSame('invalid_client', $events[0]->other['error']);
        $this->assertEquals(0, $events[0]->userid);
    }
}
