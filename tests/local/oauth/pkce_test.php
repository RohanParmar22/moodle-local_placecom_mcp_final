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
 * Tests for PKCE helpers.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\oauth;

#[\PHPUnit\Framework\Attributes\CoversClass(pkce::class)]
/**
 * Tests for {@see pkce}.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_placecom_mcp\local\oauth\pkce
 */
final class pkce_test extends \advanced_testcase {
    /**
     * The RFC 7636 appendix B example verifies.
     */
    public function test_rfc_vector(): void {
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $challenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
        $this->assertSame($challenge, pkce::challenge_for($verifier));
        $this->assertTrue(pkce::verify($verifier, $challenge));
        $this->assertFalse(pkce::verify($verifier . 'x', $challenge));
        $this->assertFalse(pkce::verify($verifier, strtolower($challenge)));
    }

    /**
     * Format cases: value, expected validity.
     *
     * @return array
     */
    public static function format_provider(): array {
        return [
            '42 chars' => [str_repeat('a', 42), false],
            '43 chars' => [str_repeat('a', 43), true],
            '128 chars' => [str_repeat('Z', 128), true],
            '129 chars' => [str_repeat('Z', 129), false],
            'unreserved punctuation' => [str_repeat('-._~', 11), true],
            'plus' => [str_repeat('a', 42) . '+', false],
            'slash' => [str_repeat('a', 42) . '/', false],
            'equals' => [str_repeat('a', 42) . '=', false],
            'space' => [str_repeat('a', 42) . ' ', false],
            'empty' => ['', false],
        ];
    }

    /**
     * Challenge / verifier format check.
     *
     * @dataProvider format_provider
     * @param string $value Value to check.
     * @param bool $valid Expected validity.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('format_provider')]
    public function test_format(string $value, bool $valid): void {
        $this->assertSame($valid, pkce::is_valid_format($value));
    }
}
