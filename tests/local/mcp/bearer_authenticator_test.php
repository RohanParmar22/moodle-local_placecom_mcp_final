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
 * Tests for bearer token authentication at the MCP endpoint.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\mcp;

use local_placecom_mcp\event\mcp_access_refused;
use local_placecom_mcp\local\oauth\config;
use local_placecom_mcp\local\oauth\user_guard;

#[\PHPUnit\Framework\Attributes\CoversClass(bearer_authenticator::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(auth_context::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(auth_exception::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(mcp_access_refused::class)]
/**
 * Tests for {@see bearer_authenticator}.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_placecom_mcp\local\mcp\bearer_authenticator
 * @covers     \local_placecom_mcp\local\mcp\auth_context
 * @covers     \local_placecom_mcp\local\mcp\auth_exception
 * @covers     \local_placecom_mcp\event\mcp_access_refused
 */
final class bearer_authenticator_test extends \advanced_testcase {
    /**
     * Create a user with a connection and return the user and the plain access token.
     *
     * @param string $scope Token scope.
     * @param array $clientrecord Extra client fields.
     * @param array $grantrecord Extra grant fields.
     * @return array [user, grant record, client record]
     */
    private function create_connection(
        string $scope = 'openid moodle_mcp_read',
        array $clientrecord = [],
        array $grantrecord = []
    ): array {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_placecom_mcp');
        $user = $this->getDataGenerator()->create_user();
        $client = $generator->create_client($clientrecord + ['scope' => 'openid moodle_mcp_read moodle_mcp_write']);
        $grant = $generator->create_grant($grantrecord + ['clientid' => $client->id, 'userid' => $user->id, 'scope' => $scope]);
        return [$user, $grant, $client];
    }

    /**
     * Authenticate and return the exception raised.
     *
     * @param string|null $token Token.
     * @return auth_exception
     */
    private function auth_failure(?string $token): auth_exception {
        try {
            (new bearer_authenticator())->authenticate($token, false);
        } catch (auth_exception $e) {
            return $e;
        }
        $this->fail('Expected an auth_exception');
    }

    /**
     * Data provider for token extraction.
     *
     * @return array
     */
    public static function extraction_provider(): array {
        return [
            'server variable' => [['HTTP_AUTHORIZATION' => 'Bearer test_token_789'], null, 'test_token_789'],
            'redirect variable' => [['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer abc.def'], null, 'abc.def'],
            'header array lowercase' => [[], ['authorization' => 'Bearer tok123'], 'tok123'],
            'lowercase scheme' => [['HTTP_AUTHORIZATION' => 'bearer tok456'], null, 'tok456'],
            'basic scheme' => [['HTTP_AUTHORIZATION' => 'Basic dXNlcjpwYXNz'], null, null],
            'nothing' => [[], null, null],
            'malformed' => [['HTTP_AUTHORIZATION' => 'Bearer a b'], null, null],
        ];
    }

    /**
     * Tokens are read from the Authorization header only.
     *
     * @dataProvider extraction_provider
     * @param array $server Server variables.
     * @param array|null $headers Headers.
     * @param string|null $expected Expected token.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('extraction_provider')]
    public function test_extract_token(array $server, ?array $headers, ?string $expected): void {
        $this->assertSame($expected, bearer_authenticator::extract_token($server, $headers));
    }

    /**
     * Query string and body tokens are ignored.
     */
    public function test_extract_ignores_query_and_body(): void {
        $_GET['wstoken'] = 'fromquery';
        $_POST['wstoken'] = 'frombody';
        $_POST['access_token'] = 'frombody2';
        $_GET['access_token'] = 'fromquery2';
        try {
            $this->assertNull(bearer_authenticator::extract_token(['QUERY_STRING' => 'wstoken=fromquery'], []));
        } finally {
            unset($_GET['wstoken'], $_POST['wstoken'], $_POST['access_token'], $_GET['access_token']);
        }
    }

    /**
     * A missing token yields 401 with the resource metadata hint.
     */
    public function test_missing_token(): void {
        $this->resetAfterTest();
        $e = $this->auth_failure(null);
        $this->assertSame(401, $e->get_http_status());
        $this->assertSame(jsonrpc::UNAUTHORIZED, $e->get_rpc_code());
        $expected = 'Bearer resource_metadata="' . config::protected_resource_metadata_url() . '"';
        $this->assertSame($expected, $e->get_www_authenticate());

        $response = $e->to_response();
        $this->assertSame(401, $response->get_status());
        $this->assertSame($expected, $response->get_header('WWW-Authenticate'));
        $this->assertNull($response->get_json()['id']);
        $this->assertSame(jsonrpc::UNAUTHORIZED, $response->get_json()['error']['code']);
    }

    /**
     * Unknown tokens yield 401 invalid_token.
     */
    public function test_unknown_token(): void {
        $this->resetAfterTest();
        $e = $this->auth_failure(str_repeat('a', 64));
        $this->assertSame(401, $e->get_http_status());
        $this->assertStringContainsString('error="invalid_token"', $e->get_www_authenticate());
    }

    /**
     * Core web service tokens are never accepted.
     */
    public function test_core_webservice_token_rejected(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $service = $DB->get_record('external_services', ['shortname' => MOODLE_OFFICIAL_MOBILE_SERVICE], '*', MUST_EXIST);
        $token = \core_external\util::generate_token(
            EXTERNAL_TOKEN_PERMANENT,
            $service,
            (int) $user->id,
            \context_system::instance()
        );
        $e = $this->auth_failure($token);
        $this->assertSame(401, $e->get_http_status());
    }

    /**
     * Expired tokens and tokens of disabled clients are refused.
     */
    public function test_expired_and_disabled(): void {
        $this->resetAfterTest();
        [, $grant] = $this->create_connection('openid moodle_mcp_read', [], ['accessexpires' => time() - 10]);
        $this->assertSame(401, $this->auth_failure($grant->accesstoken)->get_http_status());

        [, $grant] = $this->create_connection('openid moodle_mcp_read', ['enabled' => 0]);
        $this->assertSame(401, $this->auth_failure($grant->accesstoken)->get_http_status());
    }

    /**
     * Data provider for refused account states.
     *
     * @return array
     */
    public static function refused_user_provider(): array {
        return [
            'suspended' => [['suspended' => 1], user_guard::SUSPENDED],
            'deleted' => [['deleted' => 1], user_guard::DELETED],
            'nologin' => [['auth' => 'nologin'], user_guard::NOLOGIN],
        ];
    }

    /**
     * Refused account states give 401 and log an event without the token.
     *
     * @dataProvider refused_user_provider
     * @param array $changes User fields to change.
     * @param string $reason Expected reason.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refused_user_provider')]
    public function test_refused_user(array $changes, string $reason): void {
        global $DB;
        $this->resetAfterTest();
        [$user, $grant, $client] = $this->create_connection();
        $DB->update_record('user', (object) (['id' => $user->id] + $changes));

        $sink = $this->redirectEvents();
        $e = $this->auth_failure($grant->accesstoken);
        $events = $sink->get_events();
        $sink->close();

        $this->assertSame(401, $e->get_http_status());
        $refused = array_values(array_filter($events, static function ($event): bool {
            return $event instanceof mcp_access_refused;
        }));
        $this->assertCount(1, $refused);
        $this->assertSame((int) $user->id, (int) $refused[0]->userid);
        $this->assertSame($reason, $refused[0]->other['reason']);
        $this->assertSame($client->clientid, $refused[0]->other['clientid']);
        $this->assertStringNotContainsString($grant->accesstoken, json_encode($refused[0]->get_data()));
        $this->assertStringNotContainsString($grant->accesstoken, $refused[0]->get_description());
    }

    /**
     * Maintenance mode gives 503.
     */
    public function test_maintenance(): void {
        $this->resetAfterTest();
        [, $grant] = $this->create_connection();
        set_config('maintenance_enabled', 1);
        $e = $this->auth_failure($grant->accesstoken);
        $this->assertSame(503, $e->get_http_status());
        $this->assertSame(jsonrpc::FORBIDDEN, $e->get_rpc_code());
    }

    /**
     * Tokens without the read scope get 403 insufficient_scope.
     */
    public function test_missing_read_scope(): void {
        $this->resetAfterTest();
        [, $grant] = $this->create_connection('openid profile');
        $e = $this->auth_failure($grant->accesstoken);
        $this->assertSame(403, $e->get_http_status());
        $this->assertSame(jsonrpc::FORBIDDEN, $e->get_rpc_code());
        $this->assertStringContainsString('error="insufficient_scope"', $e->get_www_authenticate());
        $this->assertStringContainsString('scope="moodle_mcp_read"', $e->get_www_authenticate());
    }

    /**
     * A user without the use capability gets 403, not 401.
     */
    public function test_capability_prohibited(): void {
        global $CFG;
        $this->resetAfterTest();
        [, $grant] = $this->create_connection();
        assign_capability(
            'local/placecom_mcp:use',
            CAP_PROHIBIT,
            $CFG->defaultuserroleid,
            \context_system::instance()->id,
            true
        );
        $e = $this->auth_failure($grant->accesstoken);
        $this->assertSame(403, $e->get_http_status());
        $this->assertNull($e->get_www_authenticate());
    }

    /**
     * Success logs the user in and returns the caller context.
     */
    public function test_success(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        [$user, $grant, $client] = $this->create_connection('openid moodle_mcp_read');
        $DB->set_field('local_placecom_mcp_grant', 'timeaccessed', 0, ['id' => $grant->id]);

        $ctx = (new bearer_authenticator())->authenticate($grant->accesstoken, true);
        $this->assertSame((int) $user->id, (int) $USER->id);
        $this->assertSame((int) $user->id, $ctx->get_userid());
        $this->assertSame((int) $grant->id, $ctx->get_grantid());
        $this->assertSame($client->clientid, $ctx->get_clientid());
        $this->assertTrue($ctx->has_scope('moodle_mcp_read'));
        $this->assertFalse($ctx->can_write());
        $this->assertGreaterThan(0, (int) $DB->get_field('local_placecom_mcp_grant', 'timeaccessed', ['id' => $grant->id]));

        [, $grant] = $this->create_connection('openid moodle_mcp_read moodle_mcp_write');
        $ctx = (new bearer_authenticator())->authenticate($grant->accesstoken, false);
        $this->assertTrue($ctx->can_write());
    }
}
