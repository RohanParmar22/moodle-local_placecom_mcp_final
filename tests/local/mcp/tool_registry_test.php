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
 * Tests for the MCP tool listing.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\mcp;

#[\PHPUnit\Framework\Attributes\CoversClass(tool_registry::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(allowlist::class)]
/**
 * Tests for {@see tool_registry} and {@see allowlist}.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_placecom_mcp\local\mcp\tool_registry
 * @covers     \local_placecom_mcp\local\mcp\allowlist
 */
final class tool_registry_test extends \advanced_testcase {
    /** @var string[] The two write functions. */
    private const WRITES = ['mod_forum_add_discussion', 'mod_forum_add_discussion_post'];

    /**
     * Build a caller context for the admin user without touching the token store.
     *
     * @param string $scope Token scope.
     * @return auth_context
     */
    private function context_for(string $scope): auth_context {
        $admin = get_admin();
        $token = (object) [
            'id' => 1,
            'grantid' => 1,
            'clientid' => 1,
            'clientidentifier' => 'testclient',
            'userid' => $admin->id,
            'scope' => $scope,
            'expires' => time() + 3600,
        ];
        return new auth_context($admin, $token);
    }

    /**
     * The allowlist has 25 functions, two of them write.
     */
    public function test_allowlist(): void {
        $this->assertCount(25, allowlist::FUNCTIONS);
        $writes = array_keys(array_filter(allowlist::FUNCTIONS, static function (string $type): bool {
            return $type === allowlist::WRITE;
        }));
        sort($writes);
        $this->assertSame(self::WRITES, $writes);
        $this->assertTrue(allowlist::is_allowed('core_webservice_get_site_info'));
        $this->assertFalse(allowlist::is_allowed('core_user_get_users'));
        $this->assertTrue(allowlist::is_write('mod_forum_add_discussion'));
        $this->assertFalse(allowlist::is_write('core_course_get_categories'));
        $this->assertTrue(allowlist::is_write('core_course_get_categories', (object) ['type' => 'write']));
        $names = allowlist::names();
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);
    }

    /**
     * Without the write scope 23 tools are listed and no write tool.
     */
    public function test_read_only_listing(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $tools = (new tool_registry())->list_tools($this->context_for('openid profile email moodle_mcp_read'));
        $names = array_column($tools, 'name');
        $this->assertCount(23, $tools);
        foreach (self::WRITES as $write) {
            $this->assertNotContains($write, $names);
        }
    }

    /**
     * With the write scope all 25 tools are listed, sorted, with complete definitions.
     */
    public function test_write_listing(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $tools = (new tool_registry())->list_tools($this->context_for('openid moodle_mcp_read moodle_mcp_write'));
        $this->assertCount(25, $tools);
        $names = array_column($tools, 'name');
        $this->assertSame(allowlist::names(), $names);

        foreach ($tools as $tool) {
            $this->assertIsString($tool['description']);
            $this->assertSame('object', $tool['inputSchema']['type']);
            $this->assertSame('object', $tool['outputSchema']['type']);
            $this->assertArrayHasKey('result', $tool['outputSchema']['properties']);
            $this->assertSame(!in_array($tool['name'], self::WRITES, true), $tool['annotations']['readOnlyHint']);
        }
        $this->assertNotFalse(json_encode($tools));
    }

    /**
     * The site information output schema only advertises the six kept keys.
     */
    public function test_site_info_output_schema(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $tools = (new tool_registry())->list_tools($this->context_for('moodle_mcp_read'));
        $siteinfo = null;
        foreach ($tools as $tool) {
            if ($tool['name'] === result_filter::SITE_INFO_FUNCTION) {
                $siteinfo = $tool;
            }
        }
        $this->assertNotNull($siteinfo);
        $properties = array_keys($siteinfo['outputSchema']['properties']['result']['properties']);
        $this->assertEqualsCanonicalizing(result_filter::SITE_INFO_KEYS, $properties);
        $this->assertNotContains('userprivateaccesskey', $siteinfo['outputSchema']['properties']['result']['required'] ?? []);
    }
}
