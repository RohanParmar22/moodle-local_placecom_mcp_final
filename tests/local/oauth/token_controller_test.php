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
 * Tests for the token endpoint logic.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\oauth;

use local_placecom_mcp\event\token_issued;
use local_placecom_mcp\local\http_response;

#[\PHPUnit\Framework\Attributes\CoversClass(token_controller::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(client_authenticator::class)]
/**
 * Tests for {@see token_controller} and {@see client_authenticator}.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_placecom_mcp\local\oauth\token_controller
 * @covers     \local_placecom_mcp\local\oauth\client_authenticator
 */
final class token_controller_test extends \advanced_testcase {
    /** @var string RFC 7636 example verifier. */
    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

    /** @var string Redirect URI used throughout. */
    private const REDIRECT = 'https://example.com/callback';

    /** @var \stdClass Confidential client. */
    private \stdClass $client;

    /** @var \stdClass Token owner. */
    private \stdClass $user;

    /**
     * Create a confidential client and a user.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // PHPUnit regenerates the site encryption key for each test, so the key created at install time
        // cannot be decrypted here: create a signing key under the current encryption key.
        (new key_manager())->rotate();
        $this->client = $this->generator()->create_client(['clientid' => 'gemini']);
        $this->user = $this->getDataGenerator()->create_user(['firstname' => 'Ada', 'lastname' => 'Lovelace']);
    }

    /**
     * The plugin generator.
     *
     * @return \local_placecom_mcp_generator
     */
    private function generator(): \local_placecom_mcp_generator {
        return $this->getDataGenerator()->get_plugin_generator('local_placecom_mcp');
    }

    /**
     * Create a code for the default client and user.
     *
     * @param array $overrides Generator values.
     * @return \stdClass
     */
    private function code(array $overrides = []): \stdClass {
        return $this->generator()->create_code(array_merge([
            'clientid' => $this->client->id,
            'userid' => $this->user->id,
            'redirecturi' => self::REDIRECT,
            'codeverifier' => self::VERIFIER,
            'nonce' => 'nonce-1',
            'authtime' => 1700000000,
        ], $overrides));
    }

    /**
     * Body of a code exchange for the default client.
     *
     * @param string $code Plaintext code.
     * @param array $overrides Extra or replaced values ('' keeps the key with an empty value).
     * @return array
     */
    private function exchange_body(string $code, array $overrides = []): array {
        return array_merge([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => self::VERIFIER,
            'client_id' => $this->client->clientid,
            'client_secret' => $this->client->plainsecret,
        ], $overrides);
    }

    /**
     * POST to the controller.
     *
     * @param array $post Form body.
     * @param array $server Server variables.
     * @return http_response
     */
    private function post(array $post, array $server = []): http_response {
        return (new token_controller())->handle('POST', $server, $post);
    }

    /**
     * Assert an OAuth error response.
     *
     * @param http_response $response Response.
     * @param int $status Expected HTTP status.
     * @param string $error Expected error code.
     */
    private function assert_error(http_response $response, int $status, string $error): void {
        $this->assertSame($status, $response->get_status(), (string) $response->get_body());
        $this->assertSame($error, $response->get_json()['error'] ?? null, (string) $response->get_body());
        $this->assertSame('no-store', $response->get_header('Cache-Control'));
    }

    /**
     * Only POST is accepted; grant_type is validated.
     */
    public function test_method_and_grant_type(): void {
        $response = (new token_controller())->handle('GET', [], []);
        $this->assert_error($response, 405, 'invalid_request');
        $this->assertSame('POST', $response->get_header('Allow'));

        $this->assert_error($this->post(['client_id' => 'gemini']), 400, 'invalid_request');
        $this->assert_error($this->post(['grant_type' => 'password']), 400, 'unsupported_grant_type');
        $this->assert_error($this->post(['grant_type' => 'client_credentials']), 400, 'unsupported_grant_type');
    }

    /**
     * Client authentication matrix.
     */
    public function test_client_authentication(): void {
        $secret = $this->client->plainsecret;
        $basic = ['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('gemini:' . $secret)];
        $wrongbasic = ['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('gemini:wrong')];

        // Basic OK and post OK (the code exchange then succeeds).
        $code = $this->code();
        $body = $this->exchange_body($code->code);
        unset($body['client_id'], $body['client_secret']);
        $this->assertSame(200, $this->post($body, $basic)->get_status());
        $this->assertSame(200, $this->post($this->exchange_body($this->code()->code))->get_status());
        $phpauth = ['PHP_AUTH_USER' => 'gemini', 'PHP_AUTH_PW' => $secret];
        $this->assertSame(200, $this->post(array_diff_key(
            $this->exchange_body($this->code()->code),
            ['client_id' => 1, 'client_secret' => 1]
        ), $phpauth)->get_status());

        // Both methods at once.
        $this->assert_error($this->post($this->exchange_body('x'), $basic), 400, 'invalid_request');

        // No credentials.
        $this->assert_error($this->post(['grant_type' => 'authorization_code', 'code' => 'x']), 400, 'invalid_client');

        // Wrong secret: 400 in the body, 401 with a Basic challenge in the header.
        $this->assert_error($this->post($this->exchange_body('x', ['client_secret' => 'wrong'])), 400, 'invalid_client');
        $response = $this->post(['grant_type' => 'authorization_code', 'code' => 'x'], $wrongbasic);
        $this->assert_error($response, 401, 'invalid_client');
        $this->assertSame('Basic realm="' . config::issuer() . '"', $response->get_header('WWW-Authenticate'));

        // Confidential client without its secret.
        $this->assert_error($this->post($this->exchange_body('x', ['client_secret' => ''])), 400, 'invalid_client');

        // Unknown client.
        $this->assert_error($this->post($this->exchange_body('x', ['client_id' => 'nobody'])), 400, 'invalid_client');

        // Public client: id alone is fine, a secret is refused.
        $public = $this->generator()->create_client(['clientid' => 'pub', 'secret' => '']);
        $code = $this->generator()->create_code(['clientid' => $public->id, 'userid' => $this->user->id,
            'codeverifier' => self::VERIFIER]);
        $this->assert_error($this->post(['grant_type' => 'authorization_code', 'code' => $code->code,
            'code_verifier' => self::VERIFIER, 'client_id' => 'pub', 'client_secret' => 'abc']), 400, 'invalid_client');
        $this->assertSame(200, $this->post(['grant_type' => 'authorization_code', 'code' => $code->code,
            'code_verifier' => self::VERIFIER, 'client_id' => 'pub'])->get_status());

        // Disabled client.
        $disabled = $this->generator()->create_client(['clientid' => 'off', 'enabled' => 0]);
        $this->assert_error($this->post(['grant_type' => 'authorization_code', 'code' => 'x', 'client_id' => 'off',
            'client_secret' => $disabled->plainsecret]), 400, 'invalid_client');
    }

    /**
     * A successful exchange returns the documented JSON and a usable access token.
     */
    public function test_code_exchange_success(): void {
        global $DB;
        set_config('accesstokenlifetime', 1800, 'local_placecom_mcp');
        $code = $this->code();
        $response = $this->post($this->exchange_body($code->code));

        $this->assertSame(200, $response->get_status(), $response->get_body());
        $this->assertSame('no-store', $response->get_header('Cache-Control'));
        $this->assertSame('no-cache', $response->get_header('Pragma'));
        $data = $response->get_json();
        $this->assertSame('Bearer', $data['token_type']);
        $this->assertSame(1800, $data['expires_in']);
        $this->assertSame('openid profile email offline_access moodle_mcp_read', $data['scope']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $data['access_token']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $data['refresh_token']);
        $this->assertArrayHasKey('id_token', $data);

        $token = (new token_store())->find_access_token($data['access_token']);
        $this->assertNotNull($token);
        $this->assertEquals($this->user->id, $token->userid);
        $this->assertSame('gemini', $token->clientidentifier);
        $this->assertNotNull((new token_store())->find_refresh_token($data['refresh_token']));

        $grant = $DB->get_record('local_placecom_mcp_grant', ['id' => $token->grantid]);
        $this->assertSame($code->scope, $grant->scope);
        $this->assertGreaterThan(0, (int) $DB->get_field('local_placecom_mcp_authcode', 'timeused', ['id' => $code->id]));
    }

    /**
     * The ID token verifies against the JWKS and carries the expected claims.
     */
    public function test_id_token(): void {
        $code = $this->code();
        $before = time();
        $data = $this->post($this->exchange_body($code->code))->get_json();

        $header = json_decode(crypto::base64url_decode(explode('.', $data['id_token'])[0]), true);
        $jwks = (new key_manager())->get_jwks()['keys'];
        $this->assertContains($header['kid'], array_column($jwks, 'kid'));
        $publickey = null;
        foreach ((new key_manager())->list_keys() as $key) {
            if ($key->kid === $header['kid']) {
                $publickey = $key->publickey;
            }
        }
        $claims = jwt::decode_and_verify($data['id_token'], $publickey);
        $this->assertNotNull($claims);
        $this->assertSame(config::issuer(), $claims['iss']);
        $this->assertSame((string) $this->user->id, $claims['sub']);
        $this->assertSame('gemini', $claims['aud']);
        $this->assertGreaterThanOrEqual($before, $claims['iat']);
        $this->assertSame($claims['iat'] + config::access_lifetime(), $claims['exp']);
        $this->assertSame(1700000000, $claims['auth_time']);
        $this->assertSame('nonce-1', $claims['nonce']);
        $this->assertSame('Ada', $claims['given_name']);
        $this->assertSame('Lovelace', $claims['family_name']);
        $this->assertSame($this->user->email, $claims['email']);
    }

    /**
     * Refresh token issuance depends on openid and offline_access.
     */
    public function test_refresh_token_rules(): void {
        $nooffline = $this->code(['scope' => 'openid profile moodle_mcp_read']);
        $data = $this->post($this->exchange_body($nooffline->code))->get_json();
        $this->assertArrayNotHasKey('refresh_token', $data);
        $this->assertArrayHasKey('id_token', $data);

        $noopenid = $this->code(['scope' => 'moodle_mcp_read', 'nonce' => null]);
        $data = $this->post($this->exchange_body($noopenid->code))->get_json();
        $this->assertArrayHasKey('refresh_token', $data);
        $this->assertArrayNotHasKey('id_token', $data);
    }

    /**
     * Invalid codes are refused with invalid_grant.
     */
    public function test_invalid_codes(): void {
        $this->assert_error($this->post($this->exchange_body('')), 400, 'invalid_request');
        $this->assert_error($this->post($this->exchange_body('unknown')), 400, 'invalid_grant');

        $expired = $this->code(['expires' => time() - 1]);
        $this->assert_error($this->post($this->exchange_body($expired->code)), 400, 'invalid_grant');

        $other = $this->generator()->create_client();
        $foreign = $this->generator()->create_code(['clientid' => $other->id, 'userid' => $this->user->id,
            'codeverifier' => self::VERIFIER]);
        $this->assert_error($this->post($this->exchange_body($foreign->code)), 400, 'invalid_grant');

        $code = $this->code();
        $this->assert_error($this->post($this->exchange_body(
            $code->code,
            ['redirect_uri' => 'https://example.com/other']
        )), 400, 'invalid_grant');
        $this->assert_error($this->post($this->exchange_body($code->code, ['redirect_uri' => ''])), 400, 'invalid_grant');

        // No redirect URI at authorization: none needed at exchange.
        $noredirect = $this->code(['redirecturi' => null]);
        $this->assertSame(200, $this->post($this->exchange_body($noredirect->code, ['redirect_uri' => '']))->get_status());
    }

    /**
     * PKCE failures are invalid_grant.
     */
    public function test_pkce(): void {
        $code = $this->code();
        $this->assert_error($this->post($this->exchange_body($code->code, ['code_verifier' => ''])), 400, 'invalid_grant');
        $this->assert_error(
            $this->post($this->exchange_body($code->code, ['code_verifier' => 'short'])),
            400,
            'invalid_grant'
        );
        $this->assert_error($this->post($this->exchange_body(
            $code->code,
            ['code_verifier' => str_repeat('a', 43)]
        )), 400, 'invalid_grant');

        $nochallenge = $this->generator()->create_code(['clientid' => $this->client->id, 'userid' => $this->user->id,
            'redirecturi' => self::REDIRECT]);
        $this->assert_error($this->post($this->exchange_body($nochallenge->code)), 400, 'invalid_grant');
        $this->assertSame(200, $this->post($this->exchange_body($nochallenge->code, ['code_verifier' => '']))
            ->get_status());
    }

    /**
     * Replaying a code fails and revokes the tokens issued from the first exchange.
     */
    public function test_code_replay(): void {
        $code = $this->code();
        $first = $this->post($this->exchange_body($code->code))->get_json();
        $store = new token_store();
        $this->assertNotNull($store->find_access_token($first['access_token']));

        $this->assert_error($this->post($this->exchange_body($code->code)), 400, 'invalid_grant');
        $this->assertNull($store->find_access_token($first['access_token']));
        $this->assertNull($store->find_refresh_token($first['refresh_token']));
    }

    /**
     * The optional scope parameter must be a subset.
     */
    public function test_scope_parameter(): void {
        $code = $this->code();
        $data = $this->post($this->exchange_body($code->code, ['scope' => 'moodle_mcp_read']))->get_json();
        $this->assertSame('moodle_mcp_read', $data['scope']);
        $this->assertArrayNotHasKey('id_token', $data);
        $token = (new token_store())->find_access_token($data['access_token']);
        $this->assertSame('moodle_mcp_read', $token->scope);
        $this->assertSame($code->scope, (new token_store())->get_grant($token->grantid)->scope);

        $code = $this->code();
        $this->assert_error(
            $this->post($this->exchange_body($code->code, ['scope' => 'moodle_mcp_read moodle_mcp_write'])),
            400,
            'invalid_scope'
        );
    }

    /**
     * Users who can no longer connect get invalid_grant.
     */
    public function test_user_checks(): void {
        global $DB;
        $code = $this->code();
        $DB->set_field('user', 'suspended', 1, ['id' => $this->user->id]);
        $this->assert_error($this->post($this->exchange_body($code->code)), 400, 'invalid_grant');
    }

    /**
     * Refresh rotates both tokens and revokes the previous access token.
     */
    public function test_refresh(): void {
        $first = $this->post($this->exchange_body($this->code()->code))->get_json();
        $store = new token_store();
        $refreshbody = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $first['refresh_token'],
            'client_id' => 'gemini',
            'client_secret' => $this->client->plainsecret,
        ];

        $response = $this->post($refreshbody + ['scope' => 'openid moodle_mcp_read']);
        $this->assertSame(200, $response->get_status(), $response->get_body());
        $second = $response->get_json();
        $this->assertSame('openid moodle_mcp_read', $second['scope']);
        $this->assertArrayHasKey('refresh_token', $second);
        $this->assertArrayNotHasKey('id_token', $second);

        $this->assertNull($store->find_access_token($first['access_token']));
        $this->assertNull($store->find_refresh_token($first['refresh_token']));
        $this->assertNotNull($store->find_access_token($second['access_token']));
        $newrefresh = $store->find_refresh_token($second['refresh_token']);
        $this->assertSame('openid profile email offline_access moodle_mcp_read', $newrefresh->scope);

        // The old refresh token no longer works; the new one does, with the full grant scope.
        $this->assert_error($this->post($refreshbody), 400, 'invalid_grant');
        $refreshbody['refresh_token'] = $second['refresh_token'];
        $third = $this->post($refreshbody)->get_json();
        $this->assertSame('openid profile email offline_access moodle_mcp_read', $third['scope']);

        // Superset scope.
        $refreshbody['refresh_token'] = $third['refresh_token'];
        $this->assert_error($this->post($refreshbody + ['scope' => 'moodle_mcp_write']), 400, 'invalid_scope');
    }

    /**
     * Invalid refresh requests.
     */
    public function test_refresh_failures(): void {
        global $DB;
        $this->assert_error($this->post(['grant_type' => 'refresh_token', 'client_id' => 'gemini',
            'client_secret' => $this->client->plainsecret]), 400, 'invalid_request');
        $base = ['grant_type' => 'refresh_token', 'client_id' => 'gemini', 'client_secret' => $this->client->plainsecret];
        $this->assert_error($this->post($base + ['refresh_token' => 'unknown']), 400, 'invalid_grant');

        $expired = $this->generator()->create_grant(['clientid' => $this->client->id, 'userid' => $this->user->id,
            'refreshexpires' => time() - 1]);
        $this->assert_error($this->post($base + ['refresh_token' => $expired->refreshtoken]), 400, 'invalid_grant');

        $other = $this->generator()->create_client();
        $foreign = $this->generator()->create_grant(['clientid' => $other->id, 'userid' => $this->user->id]);
        $this->assert_error($this->post($base + ['refresh_token' => $foreign->refreshtoken]), 400, 'invalid_grant');

        $grant = $this->generator()->create_grant(['clientid' => $this->client->id, 'userid' => $this->user->id]);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'user']);
        assign_capability('local/placecom_mcp:use', CAP_PROHIBIT, $roleid, \context_system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assert_error($this->post($base + ['refresh_token' => $grant->refreshtoken]), 400, 'invalid_grant');
    }

    /**
     * The token issued event carries metadata only, never the token.
     */
    public function test_token_issued_event(): void {
        $sink = $this->redirectEvents();
        $data = $this->post($this->exchange_body($this->code()->code))->get_json();
        $events = array_values(array_filter($sink->get_events(), static function ($event): bool {
            return $event instanceof token_issued;
        }));
        $sink->close();

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame('gemini', $event->other['clientid']);
        $this->assertSame($data['scope'], $event->other['scope']);
        $this->assertSame('authorization_code', $event->other['granttype']);
        $this->assertGreaterThan(0, $event->other['grantid']);
        $this->assertEquals($this->user->id, $event->relateduserid);
        $this->assertEquals($this->user->id, $event->userid);

        $serialised = json_encode($event->get_data()) . $event->get_description();
        foreach (['access_token', 'refresh_token', 'id_token'] as $key) {
            $this->assertStringNotContainsString($data[$key], $serialised);
        }
        $this->assertStringNotContainsString(hash('sha256', $data['access_token']), $serialised);
    }

    /**
     * Without a signing key, OpenID exchanges fail with server_error and leave the code unused.
     */
    public function test_no_signing_key(): void {
        global $DB;
        $DB->delete_records('local_placecom_mcp_key');
        $code = $this->code();
        $response = $this->post($this->exchange_body($code->code));
        $this->assertDebuggingCalled();
        $this->assert_error($response, 500, 'server_error');
        $this->assertEquals(0, $DB->get_field('local_placecom_mcp_authcode', 'timeused', ['id' => $code->id]));
    }
}
