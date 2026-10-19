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
 * Scheduled task removing expired OAuth data.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\task;

use local_placecom_mcp\local\oauth\token_store;

/**
 * Hourly housekeeping: deletes expired authorisation codes, access tokens, refresh tokens and
 * connections that no longer have any token.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup extends \core\task\scheduled_task {
    /**
     * Task name shown in the scheduled tasks list.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_cleanup', 'local_placecom_mcp');
    }

    /**
     * Deletes the expired records and reports how many were removed.
     *
     * @return void
     */
    public function execute() {
        $counts = (new token_store())->delete_expired(time());
        foreach (['codes', 'accesstokens', 'refreshtokens', 'grants'] as $key) {
            mtrace('Deleted ' . $key . ': ' . (int) ($counts[$key] ?? 0));
        }
    }
}
