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
 * Tests for the privacy provider.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\database_table;
use core_privacy\local\metadata\types\external_location;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
/**
 * Tests for {@see provider}.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_placecom_mcp\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /** @var string[] Tables holding user data, without prefix. */
    private const TABLES = ['consent', 'grant', 'authcode', 'token', 'refresh'];

    /**
     * Creates a consent, a connection with tokens and a code for a user.
     *
     * @param \stdClass $client Client record from the generator.
     * @param \stdClass $user User record.
     * @return \stdClass The grant record with plain tokens, plus 'code'.
     */
    private function create_user_data(\stdClass $client, \stdClass $user): \stdClass {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_placecom_mcp');
        $generator->create_consent(['clientid' => $client->id, 'userid' => $user->id]);
        $grant = $generator->create_grant(['clientid' => $client->id, 'userid' => $user->id]);
        $grant->code = $generator->create_code(['clientid' => $client->id, 'userid' => $user->id])->code;
        return $grant;
    }

    /**
     * Counts the rows of a user in every table.
     *
     * @param int $userid User id.
     * @return int
     */
    private function count_rows(int $userid): int {
        global $DB;
        $count = 0;
        foreach (self::TABLES as $table) {
            $count += $DB->count_records('local_placecom_mcp_' . $table, ['userid' => $userid]);
        }
        return $count;
    }

    /**
     * Metadata declares the five tables and the external location.
     *
     * @return void
     */
    public function test_get_metadata(): void {
        $collection = provider::get_metadata(new collection('local_placecom_mcp'));
        $tables = [];
        $external = [];
        foreach ($collection->get_collection() as $item) {
            if ($item instanceof database_table) {
                $tables[] = $item->get_name();
                $this->assertArrayHasKey('userid', $item->get_privacy_fields());
            } else if ($item instanceof external_location) {
                $external[] = $item->get_name();
            }
        }
        foreach (self::TABLES as $table) {
            $this->assertContains('local_placecom_mcp_' . $table, $tables);
        }
        $this->assertCount(5, $tables);
        $this->assertSame(['mcpclient'], $external);
    }

    /**
     * Contexts are only reported for users with data.
     *
     * @return void
     */
    public function test_get_contexts_for_userid(): void {
        $this->resetAfterTest();
        $client = $this->getDataGenerator()->get_plugin_generator('local_placecom_mcp')->create_client();
        $user = $this->getDataGenerator()->create_user();
        $nodata = $this->getDataGenerator()->create_user();
        $this->create_user_data($client, $user);

        $contextlist = provider::get_contexts_for_userid((int) $user->id);
        $this->assertSame([(int) \context_user::instance($user->id)->id], array_map('intval', $contextlist->get_contextids()));
        $this->assertCount(0, provider::get_contexts_for_userid((int) $nodata->id));
    }

    /**
     * Users are only reported in their own user context.
     *
     * @return void
     */
    public function test_get_users_in_context(): void {
        $this->resetAfterTest();
        $client = $this->getDataGenerator()->get_plugin_generator('local_placecom_mcp')->create_client();
        $user = $this->getDataGenerator()->create_user();
        $nodata = $this->getDataGenerator()->create_user();
        $this->create_user_data($client, $user);

        $userlist = new userlist(\context_user::instance($user->id), 'local_placecom_mcp');
        provider::get_users_in_context($userlist);
        $this->assertSame([(int) $user->id], array_map('intval', $userlist->get_userids()));

        $userlist = new userlist(\context_user::instance($nodata->id), 'local_placecom_mcp');
        provider::get_users_in_context($userlist);
        $this->assertCount(0, $userlist);

        $userlist = new userlist(\context_system::instance(), 'local_placecom_mcp');
        provider::get_users_in_context($userlist);
        $this->assertCount(0, $userlist);
    }

    /**
     * Export contains the expected lists and never any secret or hash.
     *
     * @return void
     */
    public function test_export_user_data(): void {
        global $DB;
        $this->resetAfterTest();
        $client = $this->getDataGenerator()->get_plugin_generator('local_placecom_mcp')->create_client(
            ['clientid' => 'export-client', 'name' => 'Export assistant']
        );
        $user = $this->getDataGenerator()->create_user();
        $grant = $this->create_user_data($client, $user);
        $context = \context_user::instance($user->id);

        $this->export_context_data_for_user((int) $user->id, $context, 'local_placecom_mcp');
        $writer = writer::with_context($context);
        $this->assertTrue($writer->has_any_data());
        $data = $writer->get_data([get_string('pluginname', 'local_placecom_mcp')]);

        foreach (['consents', 'connections', 'accesstokens', 'refreshtokens', 'authorizationcodes'] as $key) {
            $this->assertCount(1, $data->{$key}, $key);
            $this->assertSame('export-client', $data->{$key}[0]->clientid);
            $this->assertSame('Export assistant', $data->{$key}[0]->clientname);
        }

        $json = json_encode($data);
        $this->assertStringNotContainsString('tokenhash', $json);
        $this->assertStringNotContainsString('codehash', $json);
        $this->assertStringNotContainsString($grant->accesstoken, $json);
        $this->assertStringNotContainsString($grant->refreshtoken, $json);
        $this->assertStringNotContainsString($grant->code, $json);
        foreach ($DB->get_fieldset_select('local_placecom_mcp_token', 'tokenhash', 'userid = ?', [$user->id]) as $hash) {
            $this->assertStringNotContainsString($hash, $json);
        }
    }

    /**
     * Nothing is exported for a user without data.
     *
     * @return void
     */
    public function test_export_no_data(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $context = \context_user::instance($user->id);
        $this->export_context_data_for_user((int) $user->id, $context, 'local_placecom_mcp');
        $this->assertFalse(writer::with_context($context)->has_any_data());
    }

    /**
     * Deleting for one user removes all their rows and keeps other users' rows.
     *
     * @return void
     */
    public function test_delete_data_for_user(): void {
        $this->resetAfterTest();
        $client = $this->getDataGenerator()->get_plugin_generator('local_placecom_mcp')->create_client();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->create_user_data($client, $user);
        $this->create_user_data($client, $other);
        $otherrows = $this->count_rows((int) $other->id);
        $this->assertGreaterThan(0, $otherrows);

        $contextlist = new approved_contextlist($user, 'local_placecom_mcp', [\context_user::instance($user->id)->id]);
        provider::delete_data_for_user($contextlist);

        $this->assertSame(0, $this->count_rows((int) $user->id));
        $this->assertSame($otherrows, $this->count_rows((int) $other->id));
    }

    /**
     * Approving a context of another user deletes nothing.
     *
     * @return void
     */
    public function test_delete_data_for_user_wrong_context(): void {
        $this->resetAfterTest();
        $client = $this->getDataGenerator()->get_plugin_generator('local_placecom_mcp')->create_client();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->create_user_data($client, $other);
        $otherrows = $this->count_rows((int) $other->id);

        $contextlist = new approved_contextlist($user, 'local_placecom_mcp', [\context_user::instance($other->id)->id]);
        provider::delete_data_for_user($contextlist);
        $this->assertSame($otherrows, $this->count_rows((int) $other->id));
    }

    /**
     * Deleting all data in a user context removes that user's rows; the system context is a no-op.
     *
     * @return void
     */
    public function test_delete_data_for_all_users_in_context(): void {
        $this->resetAfterTest();
        $client = $this->getDataGenerator()->get_plugin_generator('local_placecom_mcp')->create_client();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->create_user_data($client, $user);
        $this->create_user_data($client, $other);
        $userrows = $this->count_rows((int) $user->id);
        $otherrows = $this->count_rows((int) $other->id);

        provider::delete_data_for_all_users_in_context(\context_system::instance());
        $this->assertSame($userrows, $this->count_rows((int) $user->id));

        provider::delete_data_for_all_users_in_context(\context_user::instance($user->id));
        $this->assertSame(0, $this->count_rows((int) $user->id));
        $this->assertSame($otherrows, $this->count_rows((int) $other->id));
    }

    /**
     * Deleting for a user list only acts on the owner of the user context.
     *
     * @return void
     */
    public function test_delete_data_for_users(): void {
        $this->resetAfterTest();
        $client = $this->getDataGenerator()->get_plugin_generator('local_placecom_mcp')->create_client();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->create_user_data($client, $user);
        $this->create_user_data($client, $other);
        $otherrows = $this->count_rows((int) $other->id);
        $context = \context_user::instance($user->id);

        // A list that does not contain the owner changes nothing.
        provider::delete_data_for_users(new approved_userlist($context, 'local_placecom_mcp', [$other->id]));
        $this->assertGreaterThan(0, $this->count_rows((int) $user->id));

        provider::delete_data_for_users(new approved_userlist($context, 'local_placecom_mcp', [$user->id]));
        $this->assertSame(0, $this->count_rows((int) $user->id));
        $this->assertSame($otherrows, $this->count_rows((int) $other->id));

        // The system context holds no data.
        provider::delete_data_for_users(new approved_userlist(
            \context_system::instance(),
            'local_placecom_mcp',
            [$other->id]
        ));
        $this->assertSame($otherrows, $this->count_rows((int) $other->id));
    }
}
