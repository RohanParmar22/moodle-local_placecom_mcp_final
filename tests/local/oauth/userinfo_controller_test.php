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
 * Tests for the UserInfo endpoint logic.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\oauth;

#[\PHPUnit\Framework\Attributes\CoversClass(userinfo_controller::class)]
/**
 * Tests for {@see userinfo_controller}.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_placecom_mcp\local\oauth\userinfo_controller
 */
final class userinfo_controller_test extends \advanced_testcase {
    /** @var \stdClass Client record. */
    private \stdClass $client;

    /** @var \stdClass Token owner. */
    private \stdClass $user;

    /**
     * Create a client and a user.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->client = $this->generator()->create_client();
        $this->user = $this->getDataGenerator()->create_user(['firstname' => 'Grace', 'email' => 'grace@example.com']);
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
     * A grant with the given scope for the default user.
     *
     * @param string $scope Scope.
     * @param array $extra Extra generator values.
     * @return \stdClass
     */
    private function grant(string $scope, array $extra = []): \stdClass {
        return $this->generator()->create_grant(array_merge(['clientid' => $this->client->id,
            'userid' => $this->user->id, 'scope' => $scope], $extra));
    }

    /**
     * Requests without a token get 401 with a realm challenge and no error attribute.
     */
    public function test_no_token(): void {
        $response = (new userinfo_controller())->handle('GET', [], []);
        $this->assertSame(401, $response->get_status());
        $this->assertSame('Bearer realm="' . config::issuer() . '"', $response->get_header('WWW-Authenticate'));
    }

    /**
     * A header token returns sub plus the claims of the token scope.
     */
    public function test_header_token(): void {
        $grant = $this->grant('openid email');
        $response = (new userinfo_controller())->handle(
            'GET',
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $grant->accesstoken],
            []
        );
        $this->assertSame(200, $response->get_status());
        $this->assertSame('no-store', $response->get_header('Cache-Control'));
        $this->assertSame(['sub' => (string) $this->user->id, 'email' => 'grace@example.com'], $response->get_json());

        $grant = $this->grant('openid profile');
        $response = (new userinfo_controller())->handle(
            'GET',
            ['REDIRECT_HTTP_AUTHORIZATION' => 'bearer ' . $grant->accesstoken],
            []
        );
        $data = $response->get_json();
        $this->assertSame('Grace', $data['given_name']);
        $this->assertArrayNotHasKey('email', $data);
    }

    /**
     * A form body token is accepted for POST.
     */
    public function test_body_token(): void {
        $grant = $this->grant('openid');
        $response = (new userinfo_controller())->handle('POST', [], ['access_token' => $grant->accesstoken]);
        $this->assertSame(200, $response->get_status());
        $this->assertSame(['sub' => (string) $this->user->id], $response->get_json());
    }

    /**
     * Sending the token twice is a 400.
     */
    public function test_header_and_body(): void {
        $grant = $this->grant('openid');
        $response = (new userinfo_controller())->handle(
            'POST',
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $grant->accesstoken],
            ['access_token' => $grant->accesstoken]
        );
        $this->assertSame(400, $response->get_status());
        $this->assertSame('invalid_request', $response->get_json()['error']);
    }

    /**
     * The query string is never read.
     */
    public function test_query_string_ignored(): void {
        $grant = $this->grant('openid');
        $_GET['access_token'] = $grant->accesstoken;
        $response = (new userinfo_controller())->handle('GET', ['QUERY_STRING' => 'access_token=' . $grant->accesstoken], []);
        unset($_GET['access_token']);
        $this->assertSame(401, $response->get_status());
    }

    /**
     * Expired tokens and refused users get invalid_token.
     */
    public function test_invalid_token(): void {
        global $DB;
        $expired = $this->grant('openid', ['accessexpires' => time() - 1]);
        $response = (new userinfo_controller())->handle(
            'GET',
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $expired->accesstoken],
            []
        );
        $this->assertSame(401, $response->get_status());
        $this->assertSame('invalid_token', $response->get_json()['error']);
        $this->assertSame(
            'Bearer realm="' . config::issuer() . '", error="invalid_token"',
            $response->get_header('WWW-Authenticate')
        );

        $grant = $this->grant('openid');
        $DB->set_field('user', 'suspended', 1, ['id' => $this->user->id]);
        $response = (new userinfo_controller())->handle(
            'GET',
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $grant->accesstoken],
            []
        );
        $this->assertSame(401, $response->get_status());
    }

    /**
     * Tokens without openid get 403 insufficient_scope.
     */
    public function test_insufficient_scope(): void {
        $grant = $this->grant('moodle_mcp_read');
        $response = (new userinfo_controller())->handle(
            'GET',
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $grant->accesstoken],
            []
        );
        $this->assertSame(403, $response->get_status());
        $this->assertSame('insufficient_scope', $response->get_json()['error']);
        $this->assertStringContainsString('error="insufficient_scope"', $response->get_header('WWW-Authenticate'));
    }

    /**
     * Other methods are refused.
     */
    public function test_method(): void {
        $response = (new userinfo_controller())->handle('PUT', [], []);
        $this->assertSame(405, $response->get_status());
        $this->assertSame('GET, POST', $response->get_header('Allow'));
    }
}
