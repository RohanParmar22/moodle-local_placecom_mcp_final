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
 * Tests for the metadata documents and configuration helpers.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\oauth;

#[\PHPUnit\Framework\Attributes\CoversClass(metadata::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(config::class)]
/**
 * Tests for {@see metadata} and {@see config}.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_placecom_mcp\local\oauth\metadata
 * @covers     \local_placecom_mcp\local\oauth\config
 */
final class metadata_test extends \advanced_testcase {
    /**
     * Discovery advertises the expected values with the site URL as issuer.
     */
    public function test_discovery(): void {
        global $CFG;
        $this->resetAfterTest();
        $doc = metadata::discovery();
        $base = $CFG->wwwroot . '/local/placecom_mcp/';

        $this->assertSame($CFG->wwwroot, $doc['issuer']);
        $this->assertSame($base . 'authorize.php', $doc['authorization_endpoint']);
        $this->assertSame($base . 'token.php', $doc['token_endpoint']);
        $this->assertSame($base . 'userinfo.php', $doc['userinfo_endpoint']);
        $this->assertSame($base . 'jwks.php', $doc['jwks_uri']);
        $this->assertSame(scopes::ALL, $doc['scopes_supported']);
        $this->assertSame(claims::SUPPORTED, $doc['claims_supported']);
        $this->assertSame(['code'], $doc['response_types_supported']);
        $this->assertSame(['query'], $doc['response_modes_supported']);
        $this->assertSame(['authorization_code', 'refresh_token'], $doc['grant_types_supported']);
        $this->assertSame(['public'], $doc['subject_types_supported']);
        $this->assertSame(['RS256'], $doc['id_token_signing_alg_values_supported']);
        $this->assertSame(
            ['client_secret_basic', 'client_secret_post', 'none'],
            $doc['token_endpoint_auth_methods_supported']
        );
        $this->assertSame(['S256'], $doc['code_challenge_methods_supported']);
        $this->assertFalse($doc['claims_parameter_supported']);
        $this->assertFalse($doc['request_parameter_supported']);
        $this->assertFalse($doc['request_uri_parameter_supported']);
        $this->assertTrue($doc['authorization_response_iss_parameter_supported']);
    }

    /**
     * An issuer override changes every URL; trailing slashes are trimmed.
     */
    public function test_issuer_override(): void {
        $this->resetAfterTest();
        set_config('issuer', '  https://proxy.example.com/moodle//  ', 'local_placecom_mcp');
        $this->assertSame('https://proxy.example.com/moodle', config::issuer());

        $doc = metadata::discovery();
        $this->assertSame('https://proxy.example.com/moodle', $doc['issuer']);
        foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint', 'jwks_uri'] as $key) {
            $this->assertStringStartsWith('https://proxy.example.com/moodle/local/placecom_mcp/', $doc[$key]);
        }

        $resource = metadata::protected_resource();
        $this->assertSame('https://proxy.example.com/moodle/local/placecom_mcp/mcp.php', $resource['resource']);
        $this->assertSame(['https://proxy.example.com/moodle'], $resource['authorization_servers']);
        $this->assertSame(
            'https://proxy.example.com/moodle/local/placecom_mcp/protected_resource.php',
            config::protected_resource_metadata_url()
        );
    }

    /**
     * Protected resource metadata names the MCP endpoint and its scopes.
     */
    public function test_protected_resource(): void {
        global $CFG;
        $this->resetAfterTest();
        $doc = metadata::protected_resource();
        $this->assertSame($CFG->wwwroot . '/local/placecom_mcp/mcp.php', $doc['resource']);
        $this->assertSame([$CFG->wwwroot], $doc['authorization_servers']);
        $this->assertSame(['moodle_mcp_read', 'moodle_mcp_write'], $doc['scopes_supported']);
        $this->assertSame(['header'], $doc['bearer_methods_supported']);
        $this->assertSame(get_string('pluginname', 'local_placecom_mcp'), $doc['resource_name']);
    }

    /**
     * respond() encodes with unescaped slashes and sets cache and CORS headers; failures become 500.
     */
    public function test_respond(): void {
        $this->resetAfterTest();
        $response = metadata::respond([metadata::class, 'discovery']);
        $this->assertSame(200, $response->get_status());
        $this->assertStringContainsString('https://', $response->get_body());
        $this->assertStringNotContainsString('\\/', $response->get_body());
        $this->assertSame('application/json; charset=utf-8', $response->get_header('Content-Type'));
        $this->assertSame('public, max-age=300', $response->get_header('Cache-Control'));
        $this->assertSame('*', $response->get_header('Access-Control-Allow-Origin'));

        $jwks = metadata::respond([metadata::class, 'jwks']);
        $this->assertCount(1, $jwks->get_json()['keys']);

        $failure = metadata::respond(static function (): array {
            throw new \RuntimeException('secret detail');
        });
        $this->assertDebuggingCalled();
        $this->assertSame(500, $failure->get_status());
        $this->assertSame('server_error', $failure->get_json()['error']);
        $this->assertStringNotContainsString('secret detail', $failure->get_body());
    }

    /**
     * Lifetimes fall back to defaults; the MCP switch defaults to on.
     */
    public function test_config(): void {
        $this->resetAfterTest();
        $this->assertSame(config::DEFAULT_ACCESS_LIFETIME, config::access_lifetime());
        $this->assertSame(config::DEFAULT_REFRESH_LIFETIME, config::refresh_lifetime());
        set_config('accesstokenlifetime', 0, 'local_placecom_mcp');
        set_config('refreshtokenlifetime', -5, 'local_placecom_mcp');
        $this->assertSame(config::DEFAULT_ACCESS_LIFETIME, config::access_lifetime());
        $this->assertSame(config::DEFAULT_REFRESH_LIFETIME, config::refresh_lifetime());
        set_config('accesstokenlifetime', 600, 'local_placecom_mcp');
        $this->assertSame(600, config::access_lifetime());

        unset_config('enabled', 'local_placecom_mcp');
        $this->assertTrue(config::is_mcp_enabled());
        set_config('enabled', 0, 'local_placecom_mcp');
        $this->assertFalse(config::is_mcp_enabled());
        set_config('enabled', 1, 'local_placecom_mcp');
        $this->assertTrue(config::is_mcp_enabled());
    }
}
