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
 * Tests for the event observer.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp;

#[\PHPUnit\Framework\Attributes\CoversClass(observer::class)]
/**
 * Tests for {@see observer}.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_placecom_mcp\observer
 */
final class observer_test extends \advanced_testcase {
    /**
     * Deleting a user removes that user's rows only.
     *
     * @return void
     */
    public function test_user_deleted(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_placecom_mcp');
        $client = $generator->create_client();
        $doomed = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        foreach ([$doomed, $other] as $user) {
            $generator->create_consent(['clientid' => $client->id, 'userid' => $user->id]);
            $generator->create_grant(['clientid' => $client->id, 'userid' => $user->id]);
            $generator->create_code(['clientid' => $client->id, 'userid' => $user->id]);
        }

        delete_user($doomed);

        $tables = ['consent', 'grant', 'authcode', 'token', 'refresh'];
        foreach ($tables as $table) {
            $this->assertSame(0, $DB->count_records('local_placecom_mcp_' . $table, ['userid' => $doomed->id]), $table);
            $this->assertSame(1, $DB->count_records('local_placecom_mcp_' . $table, ['userid' => $other->id]), $table);
        }
    }
}
