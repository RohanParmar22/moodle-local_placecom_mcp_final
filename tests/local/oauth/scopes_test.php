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
 * Tests for the scope helpers.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\oauth;

#[\PHPUnit\Framework\Attributes\CoversClass(scopes::class)]
/**
 * Tests for {@see scopes}.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_placecom_mcp\local\oauth\scopes
 */
final class scopes_test extends \advanced_testcase {
    /**
     * Narrowing cases: requested, registered, expected.
     *
     * @return array
     */
    public static function narrow_provider(): array {
        return [
            'write dropped' => [
                'openid profile email moodle_mcp_read moodle_mcp_write',
                'openid profile email moodle_mcp_read',
                ['openid', 'profile', 'email', 'moodle_mcp_read'],
            ],
            'all registered' => [
                'openid profile email moodle_mcp_read moodle_mcp_write',
                'openid profile email moodle_mcp_read moodle_mcp_write',
                ['openid', 'profile', 'email', 'moodle_mcp_read', 'moodle_mcp_write'],
            ],
            'disjoint' => [
                'address phone',
                'openid moodle_mcp_read',
                [],
            ],
            'request order kept' => [
                'moodle_mcp_read openid',
                'openid profile moodle_mcp_read',
                ['moodle_mcp_read', 'openid'],
            ],
        ];
    }

    /**
     * Requested scopes are narrowed to the registered ones in request order.
     *
     * @dataProvider narrow_provider
     * @param string $requested Requested scope string.
     * @param string $registered Registered scope string.
     * @param array $expected Expected result.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('narrow_provider')]
    public function test_narrow(string $requested, string $registered, array $expected): void {
        $this->assertSame($expected, scopes::narrow(scopes::parse($requested), scopes::parse($registered)));
    }

    /**
     * Parsing splits on any whitespace and removes duplicates.
     */
    public function test_parse(): void {
        $this->assertSame([], scopes::parse(null));
        $this->assertSame([], scopes::parse(''));
        $this->assertSame([], scopes::parse("  \t\n "));
        $this->assertSame(['openid', 'profile'], scopes::parse("openid\tprofile"));
        $this->assertSame(['openid', 'profile', 'email'], scopes::parse("  openid \n profile\r\nemail  openid "));
        $this->assertSame('openid profile', scopes::join(['openid', 'profile']));
    }

    /**
     * Admin input is normalised.
     */
    public function test_normalise(): void {
        $this->assertSame('openid profile email', scopes::normalise('openid, profile,email'));
        $this->assertSame('openid moodle_mcp_read', scopes::normalise("openid\tmoodle_mcp_read\nopenid"));
        $this->assertSame('', scopes::normalise(" ,\n "));
    }

    /**
     * Unknown scopes are reported; subsets are recognised.
     */
    public function test_unknown_and_subset(): void {
        $this->assertSame([], scopes::unknown(scopes::ALL));
        $this->assertSame(['teacher.read'], scopes::unknown(['openid', 'teacher.read']));
        $this->assertTrue(scopes::is_subset(['openid'], ['openid', 'profile']));
        $this->assertTrue(scopes::is_subset([], ['openid']));
        $this->assertFalse(scopes::is_subset(['openid', 'email'], ['openid', 'profile']));
        $this->assertCount(8, scopes::ALL);
        $this->assertContains(scopes::READ, scopes::ALL);
        $this->assertContains(scopes::WRITE, scopes::ALL);
    }

    /**
     * Known scopes get a description; unknown ones are shown raw.
     */
    public function test_describe(): void {
        foreach (scopes::ALL as $scope) {
            $this->assertSame(get_string('scope_' . $scope, 'local_placecom_mcp'), scopes::describe($scope));
        }
        $this->assertSame('teacher.read', scopes::describe('teacher.read'));
        $this->assertSame('something_else', scopes::describe('something_else'));
    }
}
