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
 * Tests for credential storage.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\oauth;

use local_placecom_mcp\event\grant_revoked;

#[\PHPUnit\Framework\Attributes\CoversClass(token_store::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(consent_store::class)]
/**
 * Tests for {@see token_store} and {@see consent_store}.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_placecom_mcp\local\oauth\token_store
 * @covers     \local_placecom_mcp\local\oauth\consent_store
 */
final class token_store_test extends \advanced_testcase {
    /** @var string[] Tables holding user credentials. */
    private const TABLES = [
        'local_placecom_mcp_authcode',
        'local_placecom_mcp_token',
        'local_placecom_mcp_refresh',
        'local_placecom_mcp_grant',
        'local_placecom_mcp_consent',
    ];

    /**
     * The plugin generator.
     *
     * @return \local_placecom_mcp_generator
     */
    private function generator(): \local_placecom_mcp_generator {
        return $this->getDataGenerator()->get_plugin_generator('local_placecom_mcp');
    }

    /**
     * Plaintext tokens and codes never reach the database; hashes are SHA-256.
     */
    public function test_plaintext_never_stored(): void {
        global $DB;
        $this->resetAfterTest();
        $client = $this->generator()->create_client();
        $user = $this->getDataGenerator()->create_user();
        $grant = $this->generator()->create_grant(['clientid' => $client->id, 'userid' => $user->id]);
        $code = $this->generator()->create_code(['clientid' => $client->id, 'userid' => $user->id]);

        $this->assertSame(64, strlen($grant->accesstoken));
        $this->assertSame(64, strlen($grant->refreshtoken));
        $this->assertSame(64, strlen($code->code));
        $plain = [$grant->accesstoken, $grant->refreshtoken, $code->code];

        foreach (['local_placecom_mcp_token', 'local_placecom_mcp_refresh', 'local_placecom_mcp_authcode'] as $table) {
            foreach ($DB->get_records($table) as $row) {
                foreach ((array) $row as $value) {
                    foreach ($plain as $secret) {
                        $this->assertNotSame($secret, (string) $value);
                        $this->assertStringNotContainsString($secret, (string) $value);
                    }
                }
            }
        }
        $this->assertSame(
            hash('sha256', $grant->accesstoken),
            $DB->get_field('local_placecom_mcp_token', 'tokenhash', ['id' => $grant->accesstokenid])
        );
        $this->assertSame(
            hash('sha256', $grant->refreshtoken),
            $DB->get_field('local_placecom_mcp_refresh', 'tokenhash', ['grantid' => $grant->id])
        );
        $this->assertSame(hash('sha256', $code->code), $code->codehash);
    }

    /**
     * Access token lookup honours expiry and the client's enabled flag.
     */
    public function test_find_access_token(): void {
        global $DB;
        $this->resetAfterTest();
        $client = $this->generator()->create_client(['clientid' => 'gemini']);
        $user = $this->getDataGenerator()->create_user();
        $grant = $this->generator()->create_grant(['clientid' => $client->id, 'userid' => $user->id,
            'scope' => 'openid moodle_mcp_read']);
        $store = new token_store();

        $found = $store->find_access_token($grant->accesstoken);
        $this->assertNotNull($found);
        $this->assertSame($grant->accesstokenid, $found->id);
        $this->assertSame((int) $grant->id, $found->grantid);
        $this->assertSame((int) $client->id, $found->clientid);
        $this->assertSame('gemini', $found->clientidentifier);
        $this->assertSame((int) $user->id, $found->userid);
        $this->assertSame('openid moodle_mcp_read', $found->scope);
        $this->assertGreaterThan(time(), $found->expires);

        $this->assertNull($store->find_access_token(''));
        $this->assertNull($store->find_access_token('unknown'));
        $this->assertNull($store->find_access_token(hash('sha256', $grant->accesstoken)));
        $this->assertNotNull($store->find_refresh_token($grant->refreshtoken));
        $this->assertNull($store->find_refresh_token($grant->accesstoken));
        $this->assertNull($store->find_access_token($grant->refreshtoken));

        $DB->set_field('local_placecom_mcp_client', 'enabled', 0, ['id' => $client->id]);
        $this->assertNull($store->find_access_token($grant->accesstoken));
        $this->assertNull($store->find_refresh_token($grant->refreshtoken));
        $DB->set_field('local_placecom_mcp_client', 'enabled', 1, ['id' => $client->id]);

        $DB->set_field('local_placecom_mcp_token', 'expires', time() - 1, ['id' => $grant->accesstokenid]);
        $this->assertNull($store->find_access_token($grant->accesstoken));
    }

    /**
     * Revoking a grant removes its tokens and the consent and logs the revocation.
     */
    public function test_revoke_grant(): void {
        global $DB;
        $this->resetAfterTest();
        $client = $this->generator()->create_client();
        $user = $this->getDataGenerator()->create_user();
        $grant = $this->generator()->create_grant(['clientid' => $client->id, 'userid' => $user->id]);
        $this->generator()->create_consent(['clientid' => $client->id, 'userid' => $user->id]);
        $store = new token_store();

        $sink = $this->redirectEvents();
        $this->assertTrue($store->revoke_grant((int) $grant->id, 'admin'));
        $events = array_values(array_filter($sink->get_events(), static function ($event): bool {
            return $event instanceof grant_revoked;
        }));
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertSame((int) $user->id, (int) $events[0]->relateduserid);
        $this->assertSame((int) $grant->id, (int) $events[0]->objectid);
        $this->assertSame($client->clientid, $events[0]->other['clientid']);
        $this->assertSame('admin', $events[0]->other['reason']);
        $this->assertStringNotContainsString($grant->accesstoken, json_encode($events[0]->get_data()));

        $this->assertNull($store->find_access_token($grant->accesstoken));
        $this->assertNull($store->find_refresh_token($grant->refreshtoken));
        foreach (self::TABLES as $table) {
            $this->assertSame(0, $DB->count_records($table), $table);
        }
        $this->assertFalse($store->revoke_grant((int) $grant->id, 'admin'));
    }

    /**
     * Revoking a (user, client) pair leaves the user's other clients alone.
     */
    public function test_revoke_user_client(): void {
        $this->resetAfterTest();
        $client1 = $this->generator()->create_client();
        $client2 = $this->generator()->create_client();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $a = $this->generator()->create_grant(['clientid' => $client1->id, 'userid' => $user->id]);
        $b = $this->generator()->create_grant(['clientid' => $client1->id, 'userid' => $user->id]);
        $c = $this->generator()->create_grant(['clientid' => $client2->id, 'userid' => $user->id]);
        $d = $this->generator()->create_grant(['clientid' => $client1->id, 'userid' => $other->id]);
        $this->generator()->create_consent(['clientid' => $client1->id, 'userid' => $user->id]);
        $this->generator()->create_consent(['clientid' => $client2->id, 'userid' => $user->id]);
        $this->generator()->create_code(['clientid' => $client1->id, 'userid' => $user->id]);
        $store = new token_store();
        $consents = new consent_store();

        $this->assertSame(2, $store->revoke_user_client((int) $client1->id, (int) $user->id, 'user'));
        $this->assertNull($store->find_access_token($a->accesstoken));
        $this->assertNull($store->find_access_token($b->accesstoken));
        $this->assertNotNull($store->find_access_token($c->accesstoken));
        $this->assertNotNull($store->find_access_token($d->accesstoken));
        $this->assertNull($consents->get((int) $client1->id, (int) $user->id));
        $this->assertNotNull($consents->get((int) $client2->id, (int) $user->id));
        $this->assertSame(0, $store->revoke_user_client((int) $client1->id, (int) $user->id, 'user'));
    }

    /**
     * Deleting a client's data leaves other clients intact (survivor check).
     */
    public function test_delete_all_for_client(): void {
        global $DB;
        $this->resetAfterTest();
        $doomed = $this->generator()->create_client();
        $survivor = $this->generator()->create_client();
        $user = $this->getDataGenerator()->create_user();
        $gone = $this->generator()->create_grant(['clientid' => $doomed->id, 'userid' => $user->id]);
        $kept = $this->generator()->create_grant(['clientid' => $survivor->id, 'userid' => $user->id]);
        $this->generator()->create_consent(['clientid' => $doomed->id, 'userid' => $user->id]);
        $this->generator()->create_consent(['clientid' => $survivor->id, 'userid' => $user->id]);
        $this->generator()->create_code(['clientid' => $doomed->id, 'userid' => $user->id]);
        $this->generator()->create_code(['clientid' => $survivor->id, 'userid' => $user->id]);
        $store = new token_store();

        $sink = $this->redirectEvents();
        $store->delete_all_for_client((int) $doomed->id);
        $sink->close();

        foreach (self::TABLES as $table) {
            $this->assertSame(0, $DB->count_records($table, ['clientid' => $doomed->id]), $table);
            $this->assertSame(1, $DB->count_records($table, ['clientid' => $survivor->id]), $table);
        }
        $this->assertNull($store->find_access_token($gone->accesstoken));
        $this->assertNotNull($store->find_access_token($kept->accesstoken));
        $this->assertNotNull($store->find_refresh_token($kept->refreshtoken));
        $this->assertTrue($DB->record_exists('local_placecom_mcp_client', ['id' => $doomed->id]));
    }

    /**
     * Deleting a user's data leaves other users intact and triggers no events.
     */
    public function test_delete_all_for_user(): void {
        global $DB;
        $this->resetAfterTest();
        $client = $this->generator()->create_client();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        foreach ([$user, $other] as $u) {
            $this->generator()->create_grant(['clientid' => $client->id, 'userid' => $u->id]);
            $this->generator()->create_consent(['clientid' => $client->id, 'userid' => $u->id]);
            $this->generator()->create_code(['clientid' => $client->id, 'userid' => $u->id]);
        }

        $sink = $this->redirectEvents();
        (new token_store())->delete_all_for_user((int) $user->id);
        $this->assertSame(0, $sink->count());
        $sink->close();

        foreach (self::TABLES as $table) {
            $this->assertSame(0, $DB->count_records($table, ['userid' => $user->id]), $table);
            $this->assertSame(1, $DB->count_records($table, ['userid' => $other->id]), $table);
        }
    }

    /**
     * Expired rows are removed; grants without tokens go too; codes have an hour of grace.
     */
    public function test_delete_expired(): void {
        global $DB;
        $this->resetAfterTest();
        $client = $this->generator()->create_client();
        $user = $this->getDataGenerator()->create_user();
        $now = time();
        $live = $this->generator()->create_grant(['clientid' => $client->id, 'userid' => $user->id]);
        $dead = $this->generator()->create_grant(['clientid' => $client->id, 'userid' => $user->id,
            'accessexpires' => $now - 10, 'refreshexpires' => $now - 10]);
        $refreshonly = $this->generator()->create_grant(['clientid' => $client->id, 'userid' => $user->id,
            'accessexpires' => $now - 10]);
        $this->generator()->create_code(['clientid' => $client->id, 'userid' => $user->id, 'expires' => $now - 7200]);
        $recent = $this->generator()->create_code(['clientid' => $client->id, 'userid' => $user->id,
            'expires' => $now - 60]);

        $counts = (new token_store())->delete_expired($now);
        $this->assertSame(['codes' => 1, 'accesstokens' => 2, 'refreshtokens' => 1, 'grants' => 1], $counts);
        $this->assertTrue($DB->record_exists('local_placecom_mcp_grant', ['id' => $live->id]));
        $this->assertTrue($DB->record_exists('local_placecom_mcp_grant', ['id' => $refreshonly->id]));
        $this->assertFalse($DB->record_exists('local_placecom_mcp_grant', ['id' => $dead->id]));
        $this->assertTrue($DB->record_exists('local_placecom_mcp_authcode', ['id' => $recent->id]));
        $this->assertSame(1, $DB->count_records('local_placecom_mcp_authcode'));
    }

    /**
     * Last access time is written at most once a minute.
     */
    public function test_touch_grant(): void {
        global $DB;
        $this->resetAfterTest();
        $client = $this->generator()->create_client();
        $user = $this->getDataGenerator()->create_user();
        $grant = $this->generator()->create_grant(['clientid' => $client->id, 'userid' => $user->id]);
        $store = new token_store();

        $store->touch_grant((int) $grant->id);
        $first = (int) $DB->get_field('local_placecom_mcp_grant', 'timeaccessed', ['id' => $grant->id]);
        $this->assertGreaterThan(0, $first);

        $recent = time() - 30;
        $DB->set_field('local_placecom_mcp_grant', 'timeaccessed', $recent, ['id' => $grant->id]);
        $store->touch_grant((int) $grant->id);
        $this->assertEquals($recent, $DB->get_field('local_placecom_mcp_grant', 'timeaccessed', ['id' => $grant->id]));

        $DB->set_field('local_placecom_mcp_grant', 'timeaccessed', time() - 120, ['id' => $grant->id]);
        $store->touch_grant((int) $grant->id);
        $this->assertGreaterThanOrEqual(
            time() - 1,
            (int) $DB->get_field('local_placecom_mcp_grant', 'timeaccessed', ['id' => $grant->id])
        );
    }

    /**
     * Codes: creation, lookup and marking as used.
     */
    public function test_codes(): void {
        $this->resetAfterTest();
        $client = $this->generator()->create_client();
        $user = $this->getDataGenerator()->create_user();
        $store = new token_store();
        $plain = $store->create_code((int) $client->id, (int) $user->id, null, 'openid', 'n-1', null, 12345);
        $row = $store->find_code($plain);
        $this->assertNotNull($row);
        $this->assertNull($row->redirecturi);
        $this->assertNull($row->codechallenge);
        $this->assertSame('n-1', $row->nonce);
        $this->assertEquals(12345, $row->authtime);
        $this->assertEquals(config::CODE_LIFETIME, $row->expires - $row->timecreated);
        $this->assertEquals(0, $row->timeused);
        $this->assertNull($store->find_code(''));
        $this->assertNull($store->find_code('nope'));

        $grantid = $store->create_grant((int) $client->id, (int) $user->id, 'openid');
        $store->mark_code_used((int) $row->id, $grantid);
        $row = $store->find_code($plain);
        $this->assertGreaterThan(0, (int) $row->timeused);
        $this->assertEquals($grantid, $row->grantid);
    }

    /**
     * Consents: upsert and subset matching.
     */
    public function test_consent_store(): void {
        global $DB;
        $this->resetAfterTest();
        $client = $this->generator()->create_client();
        $user = $this->getDataGenerator()->create_user();
        $consents = new consent_store();

        $this->assertFalse($consents->has_consent((int) $client->id, (int) $user->id, ['openid']));
        $id = $consents->save((int) $client->id, (int) $user->id, 'openid profile');
        $this->assertTrue($consents->has_consent((int) $client->id, (int) $user->id, ['openid']));
        $this->assertTrue($consents->has_consent((int) $client->id, (int) $user->id, ['profile', 'openid']));
        $this->assertFalse($consents->has_consent((int) $client->id, (int) $user->id, ['openid', 'email']));

        $this->assertSame($id, $consents->save((int) $client->id, (int) $user->id, 'openid email'));
        $this->assertSame(1, $DB->count_records('local_placecom_mcp_consent'));
        $this->assertSame('openid email', $consents->get((int) $client->id, (int) $user->id)->scope);

        $consents->delete((int) $client->id, (int) $user->id);
        $this->assertNull($consents->get((int) $client->id, (int) $user->id));
    }
}
