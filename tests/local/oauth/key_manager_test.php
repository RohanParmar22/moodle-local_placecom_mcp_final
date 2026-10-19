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
 * Tests for signing key management.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\oauth;

#[\PHPUnit\Framework\Attributes\CoversClass(key_manager::class)]
/**
 * Tests for {@see key_manager}.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_placecom_mcp\local\oauth\key_manager
 */
final class key_manager_test extends \advanced_testcase {
    /**
     * RFC 7638 thumbprint computed independently from the DER structure via json_encode.
     *
     * @param string $publicpem Public key.
     * @return string
     */
    private function expected_thumbprint(string $publicpem): string {
        $details = openssl_pkey_get_details(openssl_pkey_get_public($publicpem));
        $jwk = [
            'e' => rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '='),
            'kty' => 'RSA',
            'n' => rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '='),
        ];
        $json = json_encode($jwk, JSON_UNESCAPED_SLASHES);
        return rtrim(strtr(base64_encode(hash('sha256', $json, true)), '+/', '-_'), '=');
    }

    /**
     * Installation created exactly one active key.
     */
    public function test_install_created_active_key(): void {
        global $DB;
        $this->resetAfterTest();
        $this->assertSame(1, $DB->count_records('local_placecom_mcp_key', ['active' => 1]));
        $manager = new key_manager();
        $before = $manager->ensure_active_key();
        $this->assertNotNull($before);
        $this->assertObjectNotHasProperty('privatekey', $before);
        $again = $manager->ensure_active_key();
        $this->assertSame($before->kid, $again->kid);
        $this->assertSame(1, $DB->count_records('local_placecom_mcp_key'));
    }

    /**
     * Without any key, ensure_active_key() creates one.
     */
    public function test_ensure_creates_when_missing(): void {
        global $DB;
        $this->resetAfterTest();
        $DB->delete_records('local_placecom_mcp_key');
        $record = (new key_manager())->ensure_active_key();
        $this->assertNotNull($record);
        $this->assertEquals(1, $record->active);
        $this->assertSame(1, $DB->count_records('local_placecom_mcp_key'));
    }

    /**
     * Rotation retires the old key, keeps both in the JWKS, and the new key signs.
     */
    public function test_rotate_and_jwks(): void {
        global $DB;
        $this->resetAfterTest();
        $manager = new key_manager();
        $old = $manager->ensure_active_key();
        $new = $manager->rotate();

        $this->assertNotSame($old->kid, $new->kid);
        $this->assertSame(43, strlen($new->kid));
        $this->assertSame($this->expected_thumbprint($new->publickey), $new->kid);
        $this->assertSame(key_manager::thumbprint($new->publickey), $new->kid);

        $oldrow = $DB->get_record('local_placecom_mcp_key', ['kid' => $old->kid]);
        $this->assertEquals(0, $oldrow->active);
        $this->assertGreaterThan(0, (int) $oldrow->timeretired);
        $newrow = $DB->get_record('local_placecom_mcp_key', ['kid' => $new->kid]);
        $this->assertEquals(1, $newrow->active);
        $this->assertEquals(0, $newrow->timeretired);
        $this->assertSame(1, $DB->count_records('local_placecom_mcp_key', ['active' => 1]));

        // Private keys are encrypted at rest.
        foreach ($DB->get_records('local_placecom_mcp_key') as $row) {
            $this->assertFalse(strpos($row->privatekey, 'BEGIN'));
        }

        $jwks = $manager->get_jwks();
        $this->assertSame([$old->kid, $new->kid], array_column($jwks['keys'], 'kid'));
        foreach ($jwks['keys'] as $jwk) {
            $this->assertSame(['kty', 'use', 'alg', 'n', 'e', 'kid'], array_keys($jwk));
            $this->assertSame('RSA', $jwk['kty']);
            $this->assertSame('sig', $jwk['use']);
            $this->assertSame('RS256', $jwk['alg']);
            $this->assertSame('AQAB', $jwk['e']);
        }

        $signing = $manager->get_signing_key();
        $this->assertSame($new->kid, $signing->kid);
        $this->assertSame('RS256', $signing->algorithm);
        $this->assertStringContainsString('PRIVATE KEY', $signing->privatekeypem);
        $token = jwt::encode(['sub' => '1'], $signing->privatekeypem, $signing->kid);
        $this->assertSame(['sub' => '1'], jwt::decode_and_verify($token, $new->publickey));
        $this->assertNull(jwt::decode_and_verify($token, $old->publickey));
    }

    /**
     * Keys are listed oldest first without private material.
     */
    public function test_list_keys(): void {
        $this->resetAfterTest();
        $manager = new key_manager();
        $manager->rotate();
        $list = $manager->list_keys();
        $this->assertCount(2, $list);
        foreach ($list as $record) {
            $this->assertObjectNotHasProperty('privatekey', $record);
        }
        $this->assertEquals(0, $list[0]->active);
        $this->assertEquals(1, $list[1]->active);
    }

    /**
     * The active key cannot be deleted; retired keys can; unknown kids return false.
     */
    public function test_delete_key(): void {
        $this->resetAfterTest();
        $manager = new key_manager();
        $old = $manager->ensure_active_key();
        $new = $manager->rotate();

        $this->assertFalse($manager->delete_key('nosuchkid'));
        try {
            $manager->delete_key($new->kid);
            $this->fail('Deleting the active key must fail');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_deleteactivekey', $e->errorcode);
        }
        $this->assertTrue($manager->delete_key($old->kid));
        $this->assertSame([$new->kid], array_column($manager->get_jwks()['keys'], 'kid'));
    }

    /**
     * Without an active key, get_signing_key() throws.
     */
    public function test_no_signing_key(): void {
        global $DB;
        $this->resetAfterTest();
        $DB->delete_records('local_placecom_mcp_key');
        $this->expectException(\moodle_exception::class);
        (new key_manager())->get_signing_key();
    }

    /**
     * Generating an inactive key leaves the active key in place.
     */
    public function test_generate_inactive(): void {
        global $DB;
        $this->resetAfterTest();
        $manager = new key_manager();
        // PHPUnit regenerates the site encryption key for each test, so the install-time key cannot be
        // decrypted here: start from a key created under the current encryption key.
        $DB->delete_records('local_placecom_mcp_key');
        $active = $manager->ensure_active_key();
        $extra = $manager->generate(false);
        $this->assertEquals(0, $extra->active);
        $this->assertSame($active->kid, $manager->get_signing_key()->kid);
        $this->assertCount(2, $manager->get_jwks()['keys']);
    }
}
