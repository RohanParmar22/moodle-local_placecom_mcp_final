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
 * Tests for the cleanup scheduled task.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\task;

#[\PHPUnit\Framework\Attributes\CoversClass(cleanup::class)]
/**
 * Tests for {@see cleanup}.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_placecom_mcp\task\cleanup
 */
final class cleanup_test extends \advanced_testcase {
    /**
     * Expired data is removed, live data is kept.
     *
     * @return void
     */
    public function test_execute(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_placecom_mcp');
        $user = $this->getDataGenerator()->create_user();
        $client = $generator->create_client();
        $now = time();

        // Live grant: valid access and refresh token.
        $live = $generator->create_grant(['clientid' => $client->id, 'userid' => $user->id]);
        // Access token expired, refresh token still valid: grant survives.
        $refreshonly = $generator->create_grant([
            'clientid' => $client->id,
            'userid' => $user->id,
            'accessexpires' => $now - 10,
        ]);
        // Both expired: grant removed.
        $dead = $generator->create_grant([
            'clientid' => $client->id,
            'userid' => $user->id,
            'accessexpires' => $now - 10,
            'refreshexpires' => $now - 10,
        ]);

        $oldcode = $generator->create_code(['clientid' => $client->id, 'userid' => $user->id]);
        $DB->set_field('local_placecom_mcp_authcode', 'expires', $now - 2 * HOURSECS, ['id' => $oldcode->id]);
        $recentcode = $generator->create_code(['clientid' => $client->id, 'userid' => $user->id]);
        $DB->set_field('local_placecom_mcp_authcode', 'expires', $now - 60, ['id' => $recentcode->id]);
        $freshcode = $generator->create_code(['clientid' => $client->id, 'userid' => $user->id]);

        $task = new cleanup();
        $this->assertNotEmpty($task->get_name());
        ob_start();
        $task->execute();
        $output = ob_get_clean();

        $this->assertStringContainsString('codes: 1', $output);
        $this->assertStringContainsString('accesstokens: 2', $output);
        $this->assertStringContainsString('refreshtokens: 1', $output);
        $this->assertStringContainsString('grants: 1', $output);

        $this->assertTrue($DB->record_exists('local_placecom_mcp_grant', ['id' => $live->id]));
        $this->assertTrue($DB->record_exists('local_placecom_mcp_grant', ['id' => $refreshonly->id]));
        $this->assertFalse($DB->record_exists('local_placecom_mcp_grant', ['id' => $dead->id]));
        $this->assertSame(1, $DB->count_records('local_placecom_mcp_token'));
        $this->assertSame(2, $DB->count_records('local_placecom_mcp_refresh'));

        $this->assertFalse($DB->record_exists('local_placecom_mcp_authcode', ['id' => $oldcode->id]));
        $this->assertTrue($DB->record_exists('local_placecom_mcp_authcode', ['id' => $recentcode->id]));
        $this->assertTrue($DB->record_exists('local_placecom_mcp_authcode', ['id' => $freshcode->id]));
    }
}
