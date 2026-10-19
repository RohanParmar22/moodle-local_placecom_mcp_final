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
 * Minimal RS256 JSON Web Token support.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\oauth;

/**
 * Encodes and verifies compact RS256 JWTs (RFC 7519 / RFC 7515).
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class jwt {
    /** @var int JSON flags used for header and payload. */
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * Sign claims as a compact JWT.
     *
     * @param array $claims Payload claims.
     * @param string $privatepem PEM encoded RSA private key.
     * @param string $kid Key identifier placed in the header.
     * @return string
     * @throws \coding_exception When encoding or signing fails.
     */
    public static function encode(array $claims, string $privatepem, string $kid): string {
        $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid], self::JSON_FLAGS);
        $payload = json_encode($claims, self::JSON_FLAGS);
        if ($header === false || $payload === false) {
            throw new \coding_exception('Unable to encode JWT');
        }
        $input = crypto::base64url_encode($header) . '.' . crypto::base64url_encode($payload);
        $key = openssl_pkey_get_private($privatepem);
        $signature = '';
        if ($key === false || !openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new \coding_exception('Unable to sign JWT');
        }
        return $input . '.' . crypto::base64url_encode($signature);
    }

    /**
     * Verify an RS256 JWT and return its claims.
     *
     * @param string $jwt Compact JWT.
     * @param string $publicpem PEM encoded RSA public key.
     * @return array|null Claims, or null when the token is malformed or the signature does not verify.
     */
    public static function decode_and_verify(string $jwt, string $publicpem): ?array {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $s] = $parts;
        $headerjson = crypto::base64url_decode($h);
        $payloadjson = crypto::base64url_decode($p);
        $signature = crypto::base64url_decode($s);
        if ($headerjson === false || $payloadjson === false || $signature === false) {
            return null;
        }
        $header = json_decode($headerjson, true);
        if (!is_array($header) || ($header['alg'] ?? null) !== 'RS256') {
            return null;
        }
        $key = openssl_pkey_get_public($publicpem);
        if ($key === false || openssl_verify($h . '.' . $p, $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            return null;
        }
        $claims = json_decode($payloadjson, true);
        return is_array($claims) ? $claims : null;
    }
}
