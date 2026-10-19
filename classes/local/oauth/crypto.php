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
 * Small cryptographic helpers.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\oauth;

/**
 * Random token generation, token hashing and base64url coding.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class crypto {
    /**
     * A new 256-bit random token as 64 lowercase hex characters.
     *
     * @return string
     */
    public static function random_token(): string {
        return bin2hex(random_bytes(32));
    }

    /**
     * The value stored at rest for a token, code or refresh token: SHA-256 as hex.
     *
     * @param string $plain Plaintext value.
     * @return string 64 hex characters.
     */
    public static function hash_token(string $plain): string {
        return hash('sha256', $plain);
    }

    /**
     * Base64url encoding without padding (RFC 4648 section 5).
     *
     * @param string $bin Binary data.
     * @return string
     */
    public static function base64url_encode(string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /**
     * Base64url decoding; padding is optional.
     *
     * @param string $s Encoded value.
     * @return string|false Decoded binary data or false when the input is not valid base64url.
     */
    public static function base64url_decode(string $s): string|false {
        if (!preg_match('/^[A-Za-z0-9_-]*={0,2}$/', $s)) {
            return false;
        }
        $s = rtrim($s, '=');
        $remainder = strlen($s) % 4;
        if ($remainder === 1) {
            return false;
        }
        if ($remainder > 0) {
            $s .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($s, '-_', '+/'), true);
    }
}
