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
 * Proof Key for Code Exchange (RFC 7636) helpers.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\oauth;

/**
 * PKCE format checks and S256 verification. The `plain` method is deliberately not supported.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pkce {
    /** @var string Allowed shape of both the code challenge and the code verifier. */
    public const FORMAT = '/^[A-Za-z0-9\-._~]{43,128}$/';

    /** @var string The only supported transformation. */
    public const METHOD = 'S256';

    /**
     * Whether a challenge or verifier has a valid format.
     *
     * @param string $value Value to check.
     * @return bool
     */
    public static function is_valid_format(string $value): bool {
        return preg_match(self::FORMAT, $value) === 1;
    }

    /**
     * Whether BASE64URL(SHA256(verifier)) equals the stored challenge (constant-time comparison).
     *
     * @param string $verifier Code verifier from the token request.
     * @param string $challenge Code challenge stored with the authorization code.
     * @return bool
     */
    public static function verify(string $verifier, string $challenge): bool {
        return hash_equals($challenge, self::challenge_for($verifier));
    }

    /**
     * The S256 challenge for a verifier.
     *
     * @param string $verifier Code verifier.
     * @return string
     */
    public static function challenge_for(string $verifier): string {
        return crypto::base64url_encode(hash('sha256', $verifier, true));
    }
}
