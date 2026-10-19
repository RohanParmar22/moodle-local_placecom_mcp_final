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
 * End-to-end test of the OAuth flow and the MCP endpoint working together.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp;

use local_placecom_mcp\local\client\client_manager;
use local_placecom_mcp\local\http_response;
use local_placecom_mcp\local\mcp\result_filter;
use local_placecom_mcp\local\mcp\server;
use local_placecom_mcp\local\oauth\authorize_controller;
use local_placecom_mcp\local\oauth\config;
use local_placecom_mcp\local\oauth\crypto;
use local_placecom_mcp\local\oauth\jwt;
use local_placecom_mcp\local\oauth\key_manager;
use local_placecom_mcp\local\oauth\token_controller;
use local_placecom_mcp\local\oauth\token_store;
use local_placecom_mcp\local\oauth\userinfo_controller;

#[\PHPUnit\Framework\Attributes\CoversClass(authorize_controller::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(token_controller::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(server::class)]
/**
 * Register a client, authorize with PKCE, exchange the code, use the MCP endpoint, refresh and revoke.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_placecom_mcp\local\oauth\authorize_controller
 * @covers     \local_placecom_mcp\local\oauth\token_controller
 * @covers     \local_placecom_mcp\local\mcp\server
 */
final class integration_test extends \advanced_testcase {
    /** @var string Redirect URI of the test client. */
    private const REDIRECT = 'https://assistant.example.com/oauth/callback';

    /**
     * Send one JSON-RPC message to the MCP server with a bearer token.
     *
     * @param string $token Plain access token.
     * @param array $message JSON-RPC message.
     * @return http_response
     */
    private function mcp(string $token, array $message): http_response {
        return (new server())->handle(
            'POST',
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            ['Content-Type' => 'application/json', 'MCP-Protocol-Version' => '2025-06-18'],
            json_encode($message)
        );
    }

    /**
     * POST a form body to the token endpoint.
     *
     * @param array $post Form body.
     * @return http_response
     */
    private function token(array $post): http_response {
        return (new token_controller())->handle('POST', [], $post);
    }

    /**
     * Query parameters of a redirect response.
     *
     * @param http_response $response 302 response.
     * @return array
     */
    private function redirect_params(http_response $response): array {
        $this->assertSame(302, $response->get_status());
        $location = (string) $response->get_header('Location');
        $this->assertStringStartsWith(self::REDIRECT . '?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
        return $params;
    }

    /**
     * The whole connection lifecycle as an AI assistant would drive it.
     */
    public function test_full_flow(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('enablewebservices', 1);
        // PHPUnit regenerates the site encryption key per test, so sign with a key created now.
        $key = (new key_manager())->rotate();

        // 1. An administrator registers a confidential client.
        $this->setAdminUser();
        $created = (new client_manager())->create((object) [
            'clientid' => 'e2e-assistant',
            'name' => 'E2E assistant',
            'redirecturis' => self::REDIRECT,
            'scope' => 'openid profile offline_access moodle_mcp_read',
            'requirepkce' => 1,
        ], true);
        $client = $created['client'];
        $secret = $created['secret'];
        $this->assertNotEmpty($secret);
        $hash = $DB->get_field('local_placecom_mcp_client', 'secrethash', ['clientid' => 'e2e-assistant']);
        $this->assertNotSame($secret, $hash);
        $this->assertTrue(password_verify($secret, $hash));

        // 2. A user authorizes it with PKCE and confirms the consent screen.
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Grace', 'lastname' => 'Hopper']);
        $this->setUser($user);
        $verifier = crypto::base64url_encode(random_bytes(48));
        $challenge = crypto::base64url_encode(hash('sha256', $verifier, true));
        $controller = new authorize_controller();
        $request = $controller->validate([
            'client_id' => 'e2e-assistant',
            'response_type' => 'code',
            'redirect_uri' => self::REDIRECT,
            'scope' => 'openid profile offline_access moodle_mcp_read moodle_mcp_write',
            'state' => 'state-123',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'nonce' => 'nonce-456',
            'prompt' => '',
        ]);
        // The write scope was not registered for this client, so it is narrowed away.
        $this->assertSame(['openid', 'profile', 'offline_access', 'moodle_mcp_read'], $request->get_scopes());
        $controller->check_user($request, $user);
        $this->assertTrue($controller->needs_consent($request, $user));
        $params = $this->redirect_params($controller->approve($request, $user, true));
        $this->assertSame('state-123', $params['state']);
        $this->assertSame(config::issuer(), $params['iss']);
        $this->assertFalse($controller->needs_consent($request, $user));
        $code = $params['code'];

        // 3. The assistant exchanges the code; a wrong verifier is refused first.
        $exchange = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::REDIRECT,
            'client_id' => 'e2e-assistant',
            'client_secret' => $secret,
        ];
        $bad = $this->token($exchange + ['code_verifier' => crypto::base64url_encode(random_bytes(48))]);
        $this->assertSame(400, $bad->get_status());
        $this->assertSame('invalid_grant', $bad->get_json()['error']);

        $response = $this->token($exchange + ['code_verifier' => $verifier]);
        $this->assertSame(200, $response->get_status(), (string) $response->get_body());
        $this->assertSame('no-store', $response->get_header('Cache-Control'));
        $tokens = $response->get_json();
        $this->assertSame('Bearer', $tokens['token_type']);
        $this->assertSame('openid profile offline_access moodle_mcp_read', $tokens['scope']);
        $this->assertNotEmpty($tokens['refresh_token']);
        $claims = jwt::decode_and_verify($tokens['id_token'], $key->publickey);
        $this->assertNotNull($claims);
        $this->assertSame((string) $user->id, $claims['sub']);
        $this->assertSame('e2e-assistant', $claims['aud']);
        $this->assertSame('nonce-456', $claims['nonce']);
        $this->assertSame(config::issuer(), $claims['iss']);
        $this->assertSame('Grace', $claims['given_name']);

        // Nothing secret is stored in plain text.
        $this->assertFalse($DB->record_exists('local_placecom_mcp_token', ['tokenhash' => $tokens['access_token']]));
        $hashed = ['tokenhash' => crypto::hash_token($tokens['access_token'])];
        $this->assertTrue($DB->record_exists('local_placecom_mcp_token', $hashed));
        $this->assertFalse($DB->record_exists('local_placecom_mcp_authcode', ['codehash' => $code]));

        // UserInfo accepts the access token.
        $bearer = ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens['access_token']];
        $info = (new userinfo_controller())->handle('GET', $bearer, []);
        $this->assertSame(200, $info->get_status());
        $this->assertSame((string) $user->id, $info->get_json()['sub']);

        // 4. MCP: initialize, initialized notification, tools/list, tools/call.
        $this->setUser(null);
        $init = $this->mcp($tokens['access_token'], [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'e2e', 'version' => '1']],
        ]);
        $this->assertSame(200, $init->get_status(), (string) $init->get_body());
        $result = $init->get_json()['result'];
        $this->assertSame('2025-06-18', $result['protocolVersion']);
        $this->assertSame(server::SERVER_NAME, $result['serverInfo']['name']);
        $this->assertArrayHasKey('tools', $result['capabilities']);
        $this->assertNull($init->get_header('Mcp-Session-Id'));

        $note = $this->mcp($tokens['access_token'], ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $this->assertSame(202, $note->get_status());

        $list = $this->mcp($tokens['access_token'], ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
        $this->assertSame(200, $list->get_status(), (string) $list->get_body());
        $names = array_column($list->get_json()['result']['tools'], 'name');
        $this->assertContains('core_webservice_get_site_info', $names);
        $this->assertNotContains('mod_forum_add_discussion', $names);
        $this->assertNotContains('mod_forum_add_discussion_post', $names);

        $call = $this->mcp($tokens['access_token'], [
            'jsonrpc' => '2.0', 'id' => 'call-1', 'method' => 'tools/call',
            'params' => ['name' => 'core_webservice_get_site_info', 'arguments' => new \stdClass()],
        ]);
        $this->assertSame(200, $call->get_status(), (string) $call->get_body());
        $rpc = $call->get_json();
        $this->assertSame('call-1', $rpc['id']);
        $this->assertArrayNotHasKey('isError', $rpc['result']);
        $site = $rpc['result']['structuredContent']['result'];
        $this->assertSame(result_filter::SITE_INFO_KEYS, array_keys($site));
        $this->assertEquals($user->id, $site['userid']);
        $this->assertSame($user->username, $site['username']);
        $this->assertSame(['result' => $site], json_decode($rpc['result']['content'][0]['text'], true));

        // A write tool cannot be called without the write scope.
        $write = $this->mcp($tokens['access_token'], [
            'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
            'params' => ['name' => 'mod_forum_add_discussion', 'arguments' => ['forumid' => 1,
                'subject' => 's', 'message' => 'm']],
        ]);
        $this->assertTrue($write->get_json()['result']['isError']);

        // 5. Refresh: new tokens, the previous access token stops working, no ID token.
        $refreshed = $this->token([
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokens['refresh_token'],
            'client_id' => 'e2e-assistant',
            'client_secret' => $secret,
        ]);
        $this->assertSame(200, $refreshed->get_status(), (string) $refreshed->get_body());
        $new = $refreshed->get_json();
        $this->assertNotSame($tokens['access_token'], $new['access_token']);
        $this->assertNotSame($tokens['refresh_token'], $new['refresh_token']);
        $this->assertArrayNotHasKey('id_token', $new);
        $old = $this->mcp($tokens['access_token'], ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'ping']);
        $this->assertSame(401, $old->get_status());
        $ping = $this->mcp($new['access_token'], ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'ping']);
        $this->assertSame(200, $ping->get_status());

        // The used refresh token cannot be replayed.
        $replay = $this->token([
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokens['refresh_token'],
            'client_id' => 'e2e-assistant',
            'client_secret' => $secret,
        ]);
        $this->assertSame('invalid_grant', $replay->get_json()['error']);

        // 6. The user disconnects the assistant: everything issued to it stops working.
        $this->assertSame(1, (new token_store())->revoke_user_client($client->get_id(), (int) $user->id, 'user'));
        $denied = $this->mcp($new['access_token'], ['jsonrpc' => '2.0', 'id' => 6, 'method' => 'ping']);
        $this->assertSame(401, $denied->get_status());
        $this->assertStringContainsString('resource_metadata=', (string) $denied->get_header('WWW-Authenticate'));
        $gone = $this->token([
            'grant_type' => 'refresh_token',
            'refresh_token' => $new['refresh_token'],
            'client_id' => 'e2e-assistant',
            'client_secret' => $secret,
        ]);
        $this->assertSame('invalid_grant', $gone->get_json()['error']);
        $this->assertSame(0, $DB->count_records('local_placecom_mcp_grant'));
        $this->assertSame(0, $DB->count_records('local_placecom_mcp_consent'));

        // Reusing the original code after the exchange is refused as well.
        $again = $this->token($exchange + ['code_verifier' => $verifier]);
        $this->assertSame('invalid_grant', $again->get_json()['error']);
    }
}
