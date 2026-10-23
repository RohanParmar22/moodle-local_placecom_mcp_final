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
 * Tests for the MCP server dispatcher.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\mcp;

use local_placecom_mcp\local\http_response;
use local_placecom_mcp\local\oauth\config;

#[\PHPUnit\Framework\Attributes\CoversClass(server::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(jsonrpc::class)]
/**
 * Tests for {@see server} and {@see jsonrpc}.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_placecom_mcp\local\mcp\server
 * @covers     \local_placecom_mcp\local\mcp\jsonrpc
 */
final class server_test extends \advanced_testcase {
    /** @var string|null Plain access token of the test connection. */
    private ?string $token = null;

    /**
     * Enable web services and create a connected user with read and write scope.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enablewebservices', 1);
        $generator = $this->getDataGenerator()->get_plugin_generator('local_placecom_mcp');
        $user = $this->getDataGenerator()->create_user();
        $client = $generator->create_client(['scope' => 'openid moodle_mcp_read moodle_mcp_write']);
        $grant = $generator->create_grant(['clientid' => $client->id, 'userid' => $user->id,
            'scope' => 'openid moodle_mcp_read moodle_mcp_write']);
        $this->token = $grant->accesstoken;
        $this->setUser($user);
    }

    /**
     * Send a request to a fresh server.
     *
     * @param string $body Body.
     * @param string $method HTTP method.
     * @param bool $auth Whether to send the bearer token.
     * @param array $headers Extra request headers.
     * @param server|null $server Server instance to use.
     * @return http_response
     */
    private function send(
        string $body,
        string $method = 'POST',
        bool $auth = true,
        array $headers = [],
        ?server $server = null
    ): http_response {
        if ($auth) {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }
        return ($server ?? new server())->handle($method, [], $headers, $body, false);
    }

    /**
     * Advertised constants.
     */
    public function test_constants(): void {
        $this->assertSame('Moodle MCP Server', server::SERVER_NAME);
        $this->assertSame(['2025-11-25', '2025-06-18', '2025-03-26'], server::SUPPORTED_VERSIONS);
        $this->assertSame(
            \core_plugin_manager::instance()->get_plugin_info('local_placecom_mcp')->release,
            server::server_version()
        );
        $this->assertSame('2025-06-18', server::negotiate_version('2025-06-18'));
        $this->assertSame('2025-11-25', server::negotiate_version('1999-01-01'));
        $this->assertSame('2025-11-25', server::negotiate_version(null));
    }

    /**
     * OPTIONS preflight needs no token and carries CORS headers.
     */
    public function test_options(): void {
        $response = $this->send('', 'OPTIONS', false);
        $this->assertSame(204, $response->get_status());
        $this->assertNull($response->get_body());
        $this->assertSame('*', $response->get_header('Access-Control-Allow-Origin'));
        $this->assertSame('POST, OPTIONS', $response->get_header('Access-Control-Allow-Methods'));
        $this->assertStringContainsString('Authorization', $response->get_header('Access-Control-Allow-Headers'));
        $this->assertStringContainsString('Mcp-Protocol-Version', $response->get_header('Access-Control-Allow-Headers'));
    }

    /**
     * Site and plugin gates.
     */
    public function test_gates(): void {
        set_config('enablewebservices', 0);
        $response = $this->send('{"jsonrpc":"2.0","method":"ping","id":1}');
        $this->assertSame(403, $response->get_status());
        $this->assertSame(jsonrpc::FORBIDDEN, $response->get_json()['error']['code']);

        set_config('enablewebservices', 1);
        set_config('enabled', 0, 'local_placecom_mcp');
        $response = $this->send('{"jsonrpc":"2.0","method":"ping","id":1}');
        $this->assertSame(403, $response->get_status());
        $this->assertSame(jsonrpc::FORBIDDEN, $response->get_json()['error']['code']);

        unset_config('enabled', 'local_placecom_mcp');
        $response = $this->send('{"jsonrpc":"2.0","method":"ping","id":1}');
        $this->assertSame(200, $response->get_status());
    }

    /**
     * Only POST is accepted.
     */
    public function test_get_not_allowed(): void {
        $response = $this->send('', 'GET');
        $this->assertSame(405, $response->get_status());
        $this->assertSame('POST, OPTIONS', $response->get_header('Allow'));
        $this->assertSame(jsonrpc::INVALID_REQUEST, $response->get_json()['error']['code']);
    }

    /**
     * Authentication happens before the body is looked at.
     */
    public function test_authentication_first(): void {
        $response = $this->send('this is not json', 'POST', false);
        $this->assertSame(401, $response->get_status());
        $this->assertSame(jsonrpc::UNAUTHORIZED, $response->get_json()['error']['code']);

        $body = '{"jsonrpc":"2.0","method":"tools/call","id":1,"params":{"name":"mod_forum_add_discussion"}}';
        $response = $this->send($body, 'POST', false);
        $this->assertSame(401, $response->get_status());
        $this->assertStringContainsString(
            'resource_metadata="' . config::protected_resource_metadata_url() . '"',
            $response->get_header('WWW-Authenticate')
        );
        $this->assertSame('WWW-Authenticate', $response->get_header('Access-Control-Expose-Headers'));
    }

    /**
     * Initialize negotiates the protocol version.
     */
    public function test_initialize(): void {
        $response = $this->send('{"jsonrpc":"2.0","method":"initialize","id":1,"params":{"protocolVersion":"2025-06-18",' .
            '"capabilities":{},"clientInfo":{"name":"test","version":"1"}}}');
        $this->assertSame(200, $response->get_status());
        $json = $response->get_json();
        $this->assertSame(1, $json['id']);
        $this->assertSame('2025-06-18', $json['result']['protocolVersion']);
        $this->assertSame(['tools' => ['listChanged' => false]], $json['result']['capabilities']);
        $this->assertSame(server::SERVER_NAME, $json['result']['serverInfo']['name']);
        $this->assertSame(server::server_version(), $json['result']['serverInfo']['version']);
        $this->assertNotEmpty($json['result']['instructions']);
        $this->assertNull($response->get_header('Mcp-Session-Id'));

        $response = $this->send('{"jsonrpc":"2.0","method":"initialize","id":2,"params":{"protocolVersion":"2024-01-01"}}');
        $this->assertSame(server::SUPPORTED_VERSIONS[0], $response->get_json()['result']['protocolVersion']);
    }

    /**
     * Unsupported protocol version headers are refused.
     */
    public function test_protocol_version_header(): void {
        $response = $this->send(
            '{"jsonrpc":"2.0","method":"ping","id":1}',
            'POST',
            true,
            ['MCP-Protocol-Version' => '1999-01-01']
        );
        $this->assertSame(400, $response->get_status());
        $this->assertSame(jsonrpc::UNSUPPORTED_PROTOCOL_VERSION, $response->get_json()['error']['code']);
        $data = $response->get_json()['error']['data'];
        $this->assertSame('1999-01-01', $data['requested']);
        $this->assertSame(server::ALL_VERSIONS, $data['supported']);
        $message = $response->get_json()['error']['message'];
        $this->assertStringContainsString('1999-01-01', $message);
        $this->assertStringContainsString(implode(', ', server::ALL_VERSIONS), $message);

        $response = $this->send(
            '{"jsonrpc":"2.0","method":"ping","id":1}',
            'POST',
            true,
            ['mcp-protocol-version' => '2025-06-18']
        );
        $this->assertSame(200, $response->get_status());
    }

    /**
     * Build a 2026-07-28 request body.
     *
     * @param string $method JSON-RPC method.
     * @param array $params Extra params.
     * @return string
     */
    private function modern_body(string $method, array $params = []): string {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientInfo' => ['name' => 'test', 'version' => '1'],
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
        ];
        return json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
    }

    /**
     * The constants for the two request styles agree.
     */
    public function test_all_versions_match(): void {
        $this->assertSame(array_merge([server::MODERN_VERSION], server::SUPPORTED_VERSIONS), server::ALL_VERSIONS);
    }

    /**
     * The earlier revisions still work.
     */
    public function test_legacy_versions_still_work(): void {
        foreach (server::SUPPORTED_VERSIONS as $version) {
            $response = $this->send(
                '{"jsonrpc":"2.0","method":"ping","id":1}',
                'POST',
                true,
                ['MCP-Protocol-Version' => $version]
            );
            $this->assertSame(200, $response->get_status(), $version);
        }
    }

    /**
     * A 2026-07-28 server/discover request works without initialize.
     */
    public function test_modern_discover(): void {
        $response = $this->send(
            $this->modern_body('server/discover'),
            'POST',
            true,
            ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'server/discover']
        );
        $this->assertSame(200, $response->get_status());
        $result = $response->get_json()['result'];
        $this->assertSame('complete', $result['resultType']);
        $this->assertSame(server::ALL_VERSIONS, $result['supportedVersions']);
        $this->assertSame('private', $result['cacheScope']);
        $this->assertArrayHasKey('io.modelcontextprotocol/serverInfo', $result['_meta']);
    }

    /**
     * A 2026-07-28 tools/list result carries resultType and the cache fields.
     */
    public function test_modern_tools_list(): void {
        $response = $this->send(
            $this->modern_body('tools/list'),
            'POST',
            true,
            ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/list']
        );
        $this->assertSame(200, $response->get_status());
        $result = $response->get_json()['result'];
        $this->assertSame('complete', $result['resultType']);
        $this->assertSame(server::MODERN_TTL_MS, $result['ttlMs']);
        $this->assertSame('private', $result['cacheScope']);
        $this->assertIsArray($result['tools']);
    }

    /**
     * Headers that are missing or disagree with the body are refused.
     */
    public function test_modern_header_mismatch(): void {
        $response = $this->send(
            $this->modern_body('tools/list'),
            'POST',
            true,
            ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'server/discover']
        );
        $this->assertSame(400, $response->get_status());
        $this->assertSame(jsonrpc::HEADER_MISMATCH, $response->get_json()['error']['code']);

        $response = $this->send(
            $this->modern_body('tools/list'),
            'POST',
            true,
            ['MCP-Protocol-Version' => '2026-07-28']
        );
        $this->assertSame(400, $response->get_status());
        $this->assertSame(jsonrpc::HEADER_MISMATCH, $response->get_json()['error']['code']);

        $response = $this->send(
            '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}',
            'POST',
            true,
            ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/list']
        );
        $this->assertSame(400, $response->get_status());
        $this->assertSame(jsonrpc::HEADER_MISMATCH, $response->get_json()['error']['code']);
    }

    /**
     * A tools/call request must name the same tool in the Mcp-Name header.
     */
    public function test_modern_tool_name_header(): void {
        $body = $this->modern_body('tools/call', ['name' => 'core_webservice_get_site_info']);
        $response = $this->send(
            $body,
            'POST',
            true,
            ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => 'another_tool']
        );
        $this->assertSame(400, $response->get_status());
        $this->assertSame(jsonrpc::HEADER_MISMATCH, $response->get_json()['error']['code']);

        $response = $this->send(
            $body,
            'POST',
            true,
            ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call']
        );
        $this->assertSame(400, $response->get_status());
        $this->assertSame(jsonrpc::HEADER_MISMATCH, $response->get_json()['error']['code']);
    }

    /**
     * The initialize handshake does not exist in 2026-07-28.
     */
    public function test_modern_initialize_not_found(): void {
        $response = $this->send(
            $this->modern_body('initialize'),
            'POST',
            true,
            ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'initialize']
        );
        $this->assertSame(jsonrpc::METHOD_NOT_FOUND, $response->get_json()['error']['code']);
    }
    /**
     * Notifications and client responses get 202 without body.
     */
    public function test_notifications(): void {
        foreach (
            ['{"jsonrpc":"2.0","method":"notifications/initialized"}',
                '{"jsonrpc":"2.0","method":"notifications/cancelled","params":{"requestId":1}}',
                '{"jsonrpc":"2.0","id":9,"result":{}}'] as $body
        ) {
            $response = $this->send($body);
            $this->assertSame(202, $response->get_status(), $body);
            $this->assertNull($response->get_body());
        }
    }

    /**
     * Ping returns an empty object.
     */
    public function test_ping(): void {
        $response = $this->send('{"jsonrpc":"2.0","method":"ping","id":"p1"}');
        $this->assertSame(200, $response->get_status());
        $this->assertStringContainsString('"result":{}', $response->get_body());
        $this->assertStringContainsString('"id":"p1"', $response->get_body());
    }

    /**
     * Unknown methods give -32601 with HTTP 200.
     */
    public function test_unknown_method(): void {
        $response = $this->send('{"jsonrpc":"2.0","method":"resources/list","id":4}');
        $this->assertSame(200, $response->get_status());
        $this->assertSame(jsonrpc::METHOD_NOT_FOUND, $response->get_json()['error']['code']);
        $this->assertSame(4, $response->get_json()['id']);
    }

    /**
     * Body errors.
     */
    public function test_body_errors(): void {
        $response = $this->send('{"jsonrpc":');
        $this->assertSame(400, $response->get_status());
        $this->assertSame(jsonrpc::PARSE_ERROR, $response->get_json()['error']['code']);

        $response = $this->send('');
        $this->assertSame(400, $response->get_status());
        $this->assertSame(jsonrpc::INVALID_REQUEST, $response->get_json()['error']['code']);

        $response = $this->send(str_repeat(' ', server::MAX_BODY + 1));
        $this->assertSame(413, $response->get_status());
    }

    /**
     * Tool listing and calling through the server.
     */
    public function test_tools(): void {
        $response = $this->send('{"jsonrpc":"2.0","method":"tools/list","id":1}');
        $this->assertCount(25, $response->get_json()['result']['tools']);

        $response = $this->send('{"jsonrpc":"2.0","method":"tools/call","id":2,' .
            '"params":{"name":"core_webservice_get_site_info","arguments":{}}}');
        $json = $response->get_json();
        $this->assertSame(200, $response->get_status());
        $this->assertArrayHasKey('structuredContent', $json['result']);

        $response = $this->send('{"jsonrpc":"2.0","method":"tools/call","id":3,"params":{"name":"core_user_get_users"}}');
        $this->assertSame(jsonrpc::INVALID_PARAMS, $response->get_json()['error']['code']);
        $this->assertSame(3, $response->get_json()['id']);

        $response = $this->send('{"jsonrpc":"2.0","method":"tools/call","id":4,"params":{}}');
        $this->assertSame(jsonrpc::INVALID_PARAMS, $response->get_json()['error']['code']);
    }

    /**
     * Every response carries the no-cache and CORS headers.
     */
    public function test_common_headers(): void {
        $response = $this->send('{"jsonrpc":"2.0","method":"ping","id":1}');
        $this->assertSame('application/json; charset=utf-8', $response->get_header('Content-Type'));
        $this->assertSame('private, must-revalidate, max-age=0', $response->get_header('Cache-Control'));
        $this->assertSame('Thu, 01 Jan 1970 00:00:00 GMT', $response->get_header('Expires'));
        $this->assertSame('no-cache', $response->get_header('Pragma'));
        $this->assertSame('*', $response->get_header('Access-Control-Allow-Origin'));
    }

    /**
     * JSON is encoded with unescaped Unicode and slashes.
     */
    public function test_unicode_round_trip(): void {
        $data = ['text' => '日本語 テキスト 🎉', 'url' => 'https://example.com/a/b', 'n' => 1.0];
        $json = jsonrpc::encode($data);
        $this->assertStringContainsString('日本語 テキスト 🎉', $json);
        $this->assertStringContainsString('https://example.com/a/b', $json);
        $this->assertStringContainsString('1.0', $json);
        $this->assertSame($data, json_decode($json, true));

        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32603, 'message' => 'x']],
            jsonrpc::error(1, jsonrpc::INTERNAL_ERROR, 'x')
        );
    }

    /**
     * Unexpected failures give -32603 without internal data when debugging is off.
     */
    public function test_internal_error(): void {
        global $CFG;
        $CFG->debug = DEBUG_NORMAL;
        $CFG->debugdeveloper = false;
        $registry = new class extends tool_registry {
            /**
             * Fail on purpose.
             *
             * @param auth_context $ctx Caller.
             * @return array
             */
            public function list_tools(auth_context $ctx): array {
                throw new \RuntimeException('secret detail');
            }
        };
        $server = new server(null, $registry);
        $response = $this->send('{"jsonrpc":"2.0","method":"tools/list","id":8}', 'POST', true, [], $server);
        $json = $response->get_json();
        $this->assertSame(200, $response->get_status());
        $this->assertSame(8, $json['id']);
        $this->assertSame(jsonrpc::INTERNAL_ERROR, $json['error']['code']);
        $this->assertArrayNotHasKey('data', $json['error']);
        $this->assertStringNotContainsString('secret detail', $response->get_body());
    }
}
