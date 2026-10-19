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
 * Tests for the OAuth client manager.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\client;

use local_placecom_mcp\event\client_created;
use local_placecom_mcp\event\client_deleted;
use local_placecom_mcp\event\client_updated;
use local_placecom_mcp\event\grant_revoked;
use local_placecom_mcp\local\oauth\token_store;

#[\PHPUnit\Framework\Attributes\CoversClass(client_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(client::class)]
/**
 * Tests for {@see client_manager} and {@see client}.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_placecom_mcp\local\client\client_manager
 * @covers     \local_placecom_mcp\local\client\client
 */
final class client_manager_test extends \advanced_testcase {
    /**
     * Valid input for a new client.
     *
     * @param string $clientid Client identifier.
     * @return \stdClass
     */
    private function client_data(string $clientid = 'assistant-one'): \stdClass {
        return (object) [
            'clientid' => $clientid,
            'name' => 'Assistant one',
            'redirecturis' => "https://example.com/callback\nhttps://example.org/cb\n\nhttps://example.com/callback",
            'scope' => 'openid, profile  moodle_mcp_read',
            'requirepkce' => 1,
        ];
    }

    /**
     * Creating a confidential client returns the secret once and stores only its hash.
     *
     * @return void
     */
    public function test_create_confidential(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $sink = $this->redirectEvents();
        $result = (new client_manager())->create($this->client_data(), true);
        $events = $sink->get_events();
        $sink->close();

        $secret = $result['secret'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{48}$/', $secret);
        /** @var client $client */
        $client = $result['client'];
        $this->assertFalse($client->is_public());
        $this->assertTrue($client->verify_secret($secret));
        $this->assertFalse($client->verify_secret('wrong'));
        $this->assertFalse($client->verify_secret(''));
        $this->assertSame(substr($secret, -4), $client->get_secret_hint());
        $this->assertSame(['https://example.com/callback', 'https://example.org/cb'], $client->get_redirect_uris());
        $this->assertTrue($client->has_redirect_uri('https://example.org/cb'));
        $this->assertFalse($client->has_redirect_uri('https://example.org/cb/'));
        $this->assertSame('openid profile moodle_mcp_read', $client->get_scope());
        $this->assertSame('Assistant one', $client->get_name());
        $this->assertTrue($client->is_enabled());
        $this->assertFalse(property_exists($client->to_record(), 'secrethash'));

        $row = $DB->get_record('local_placecom_mcp_client', ['id' => $client->get_id()]);
        $this->assertNotSame($secret, $row->secrethash);
        $this->assertStringNotContainsString($secret, json_encode($row));
        $this->assertTrue(password_verify($secret, $row->secrethash));

        $this->assertCount(1, $events);
        $this->assertInstanceOf(client_created::class, $events[0]);
        $this->assertSame('assistant-one', $events[0]->other['clientid']);
        $this->assertStringNotContainsString($secret, json_encode($events[0]->get_data()));
    }

    /**
     * Public clients always require PKCE.
     *
     * @return void
     */
    public function test_create_public_forces_pkce(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $data = $this->client_data();
        $data->requirepkce = 0;
        $result = (new client_manager())->create($data, false);

        $this->assertNull($result['secret']);
        $this->assertTrue($result['client']->is_public());
        $this->assertTrue($result['client']->requires_pkce());
        $this->assertNull($result['client']->get_secret_hint());
        $this->assertFalse($result['client']->verify_secret('anything'));
        $this->assertSame('1', (string) $result['client']->to_record()->requirepkce);
    }

    /**
     * Invalid data and duplicate identifiers are refused.
     *
     * @return void
     */
    public function test_create_invalid(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $manager = new client_manager();
        $manager->create($this->client_data('dup-client'), true);

        $bad = [];
        $bad[] = $this->client_data('dup-client');
        $bad[] = $this->client_data('DUP-CLIENT');
        $bad[] = $this->client_data('has space');
        $data = $this->client_data('x1y');
        $data->scope = 'openid moodle_mcp_write';
        $bad[] = $data;
        $data = $this->client_data('x2y');
        $data->redirecturis = 'http://example.com/cb';
        $bad[] = $data;
        foreach ($bad as $data) {
            try {
                $manager->create($data, true);
                $this->fail('Expected an exception for ' . $data->clientid);
            } catch (\invalid_parameter_exception $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
        $this->assertCount(1, $manager->get_all());
    }

    /**
     * Updating changes the editable fields and triggers an event.
     *
     * @return void
     */
    public function test_update(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $manager = new client_manager();
        $client = $manager->create($this->client_data(), true)['client'];

        $sink = $this->redirectEvents();
        $updated = $manager->update($client->get_id(), (object) [
            'name' => 'Renamed',
            'redirecturis' => 'https://new.example.com/cb',
            'scope' => "openid\nmoodle_mcp_read\tmoodle_mcp_write",
            'requirepkce' => 0,
            'enabled' => 0,
        ]);
        $events = $sink->get_events();
        $sink->close();

        $this->assertSame('assistant-one', $updated->get_clientid());
        $this->assertSame('Renamed', $updated->get_name());
        $this->assertSame(['https://new.example.com/cb'], $updated->get_redirect_uris());
        $this->assertSame('openid moodle_mcp_read moodle_mcp_write', $updated->get_scope());
        $this->assertFalse($updated->requires_pkce());
        $this->assertFalse($updated->is_enabled());
        $this->assertCount(1, $events);
        $this->assertInstanceOf(client_updated::class, $events[0]);
        $this->assertArrayNotHasKey('secretrotated', $events[0]->other);

        // A public client keeps PKCE on.
        $data = $this->client_data('public-one');
        $public = $manager->create($data, false)['client'];
        $public = $manager->update($public->get_id(), (object) ['requirepkce' => 0]);
        $this->assertTrue($public->requires_pkce());
        $this->assertSame('1', (string) $public->to_record()->requirepkce);
    }

    /**
     * Rotating the secret invalidates the old one.
     *
     * @return void
     */
    public function test_rotate_secret(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $manager = new client_manager();
        $result = $manager->create($this->client_data(), true);
        $old = $result['secret'];
        $id = $result['client']->get_id();

        $sink = $this->redirectEvents();
        $new = $manager->rotate_secret($id);
        $events = $sink->get_events();
        $sink->close();

        $this->assertNotSame($old, $new);
        $client = $manager->get($id);
        $this->assertFalse($client->verify_secret($old));
        $this->assertTrue($client->verify_secret($new));
        $this->assertSame(substr($new, -4), $client->get_secret_hint());
        $this->assertCount(1, $events);
        $this->assertTrue($events[0]->other['secretrotated']);

        // A public client becomes confidential.
        $public = $manager->create($this->client_data('public-two'), false)['client'];
        $secret = $manager->rotate_secret($public->get_id());
        $client = $manager->get($public->get_id());
        $this->assertFalse($client->is_public());
        $this->assertTrue($client->verify_secret($secret));
    }

    /**
     * Clients can be disabled and enabled again.
     *
     * @return void
     */
    public function test_set_enabled(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $manager = new client_manager();
        $id = $manager->create($this->client_data(), true)['client']->get_id();

        $manager->set_enabled($id, false);
        $this->assertFalse($manager->get($id)->is_enabled());
        $manager->set_enabled($id, true);
        $this->assertTrue($manager->get($id)->is_enabled());
    }

    /**
     * Lookups by identifier are exact.
     *
     * @return void
     */
    public function test_get_by_clientid(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $manager = new client_manager();
        $id = $manager->create($this->client_data('Mixed.Case'), true)['client']->get_id();

        $this->assertSame($id, $manager->get_by_clientid('Mixed.Case')->get_id());
        $this->assertNull($manager->get_by_clientid('mixed.case'));
        $this->assertNull($manager->get_by_clientid(''));
        $this->assertNull($manager->get($id + 100));
        $this->assertTrue($manager->clientid_taken('mixed.case'));
    }

    /**
     * Data provider for {@see self::test_validate_redirect_uri()}.
     *
     * @return array
     */
    public static function redirect_uri_provider(): array {
        return [
            'https' => ['https://example.com/callback', true],
            'https with port and query' => ['https://example.com:8443/cb?x=1', true],
            'http remote' => ['http://example.com/callback', false],
            'http localhost' => ['http://localhost:8080/callback', true],
            'http loopback v4' => ['http://127.0.0.1/callback', true],
            'http loopback v6' => ['http://[::1]:3000/callback', true],
            'fragment' => ['https://example.com/callback#frag', false],
            'relative' => ['/callback', false],
            'no scheme' => ['example.com/callback', false],
            'user info' => ['https://user:pass@example.com/callback', false],
            'other scheme' => ['ftp://example.com/callback', false],
            'empty' => ['', false],
        ];
    }

    /**
     * Redirect URI validation.
     *
     * @dataProvider redirect_uri_provider
     * @param string $uri URI to check.
     * @param bool $expected Expected result.
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('redirect_uri_provider')]
    public function test_validate_redirect_uri(string $uri, bool $expected): void {
        $this->assertSame($expected, client_manager::validate_redirect_uri($uri));
    }

    /**
     * Data provider for {@see self::test_validate_clientid()}.
     *
     * @return array
     */
    public static function clientid_provider(): array {
        return [
            'simple' => ['gemini', true],
            'all allowed characters' => ['A-z_0.9~', true],
            'too short' => ['ab', false],
            'maximum length' => [str_repeat('a', 100), true],
            'too long' => [str_repeat('a', 101), false],
            'space' => ['my client', false],
            'slash' => ['my/client', false],
            'empty' => ['', false],
        ];
    }

    /**
     * Client identifier validation.
     *
     * @dataProvider clientid_provider
     * @param string $clientid Identifier to check.
     * @param bool $expected Expected result.
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('clientid_provider')]
    public function test_validate_clientid(string $clientid, bool $expected): void {
        $this->assertSame($expected, client_manager::validate_clientid($clientid));
    }

    /**
     * Scope validation.
     *
     * @return void
     */
    public function test_validate_scope(): void {
        $this->assertSame('error_scoperequired', client_manager::validate_scope(''));
        $this->assertSame('error_scopeunknown', client_manager::validate_scope('openid moodle_mcp_read teacher.read'));
        $this->assertSame('error_scopereadrequired', client_manager::validate_scope('openid moodle_mcp_write'));
        $this->assertNull(client_manager::validate_scope('moodle_mcp_read'));
        $this->assertNull(client_manager::validate_scope('openid moodle_mcp_read moodle_mcp_write'));
    }

    /**
     * Deleting a client removes its data and leaves other clients untouched.
     *
     * @return void
     */
    public function test_delete(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_placecom_mcp');
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        $doomed = $generator->create_client(['clientid' => 'doomed']);
        $survivor = $generator->create_client(['clientid' => 'survivor']);
        $generator->create_consent(['clientid' => $doomed->id, 'userid' => $user1->id]);
        $doomedgrant = $generator->create_grant(['clientid' => $doomed->id, 'userid' => $user1->id]);
        $generator->create_consent(['clientid' => $survivor->id, 'userid' => $user2->id]);
        $survivorgrant = $generator->create_grant(['clientid' => $survivor->id, 'userid' => $user2->id]);

        $sink = $this->redirectEvents();
        $this->assertTrue((new client_manager())->delete((int) $doomed->id));
        $events = $sink->get_events();
        $sink->close();

        $this->assertFalse($DB->record_exists('local_placecom_mcp_client', ['id' => $doomed->id]));
        foreach (['consent', 'grant', 'token', 'refresh', 'authcode'] as $table) {
            $this->assertSame(0, $DB->count_records('local_placecom_mcp_' . $table, ['clientid' => $doomed->id]));
        }
        $store = new token_store();
        $this->assertNull($store->find_access_token($doomedgrant->accesstoken));

        $this->assertTrue($DB->record_exists('local_placecom_mcp_client', ['id' => $survivor->id]));
        $this->assertSame(1, $DB->count_records('local_placecom_mcp_consent', ['clientid' => $survivor->id]));
        $this->assertSame(1, $DB->count_records('local_placecom_mcp_token', ['clientid' => $survivor->id]));
        $this->assertSame(1, $DB->count_records('local_placecom_mcp_refresh', ['clientid' => $survivor->id]));
        $this->assertNotNull($store->find_access_token($survivorgrant->accesstoken));

        $classes = array_map('get_class', $events);
        $this->assertContains(client_deleted::class, $classes);
        $this->assertContains(grant_revoked::class, $classes);

        $this->assertFalse((new client_manager())->delete((int) $doomed->id));
    }
}
