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
 * Tests for the JWT helper.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\oauth;

#[\PHPUnit\Framework\Attributes\CoversClass(jwt::class)]
/**
 * Tests for {@see jwt}.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_placecom_mcp\local\oauth\jwt
 */
final class jwt_test extends \advanced_testcase {
    /**
     * A fresh RSA key pair.
     *
     * @return string[] `[privatepem, publicpem]`
     */
    private function keypair(): array {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $private = '';
        openssl_pkey_export($key, $private);
        return [$private, openssl_pkey_get_details($key)['key']];
    }

    /**
     * Encode then verify returns the claims; the header is as expected.
     */
    public function test_round_trip(): void {
        [$private, $public] = $this->keypair();
        $claims = ['iss' => 'https://example.com/moodle', 'sub' => '42', 'name' => 'Zoë 東京'];
        $token = jwt::encode($claims, $private, 'kid-1');

        $parts = explode('.', $token);
        $this->assertCount(3, $parts);
        $header = json_decode(crypto::base64url_decode($parts[0]), true);
        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'kid-1'], $header);
        $this->assertStringContainsString('https://example.com/moodle', crypto::base64url_decode($parts[1]));
        $this->assertSame($claims, jwt::decode_and_verify($token, $public));
    }

    /**
     * Tampering or a different key makes verification fail.
     */
    public function test_tampering_and_wrong_key(): void {
        [$private, $public] = $this->keypair();
        [, $otherpublic] = $this->keypair();
        $token = jwt::encode(['sub' => '1'], $private, 'k');
        [$h, $p, $s] = explode('.', $token);

        $forged = crypto::base64url_encode(json_encode(['sub' => '2']));
        $this->assertNull(jwt::decode_and_verify("$h.$forged.$s", $public));

        $sig = crypto::base64url_decode($s);
        $sig[0] = chr(ord($sig[0]) ^ 1);
        $this->assertNull(jwt::decode_and_verify("$h.$p." . crypto::base64url_encode($sig), $public));

        $this->assertNull(jwt::decode_and_verify($token, $otherpublic));
        $this->assertNull(jwt::decode_and_verify("$h.$p", $public));
        $this->assertNull(jwt::decode_and_verify('a.b.c', $public));

        $none = crypto::base64url_encode(json_encode(['alg' => 'none', 'typ' => 'JWT']));
        $this->assertNull(jwt::decode_and_verify("$none.$p.$s", $public));
    }
}
