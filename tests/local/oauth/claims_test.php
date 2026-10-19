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
 * Tests for OpenID Connect user claims.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\oauth;

#[\PHPUnit\Framework\Attributes\CoversClass(claims::class)]
/**
 * Tests for {@see claims}.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_placecom_mcp\local\oauth\claims
 */
final class claims_test extends \advanced_testcase {
    /**
     * Create a user and return the full record.
     *
     * @param array $fields User fields.
     * @return \stdClass
     */
    private function user(array $fields): \stdClass {
        $user = $this->getDataGenerator()->create_user($fields);
        return \core_user::get_user($user->id);
    }

    /**
     * The profile scope maps the Moodle fields.
     */
    public function test_profile_claims(): void {
        $this->resetAfterTest();
        $user = $this->user([
            'username' => 'jdoe',
            'firstname' => 'Jane',
            'lastname' => 'Doe',
            'middlename' => 'Q',
            'alternatename' => 'JD',
            'lang' => 'en',
            'timezone' => 'Europe/London',
        ]);
        // Moodle 4.5+ no longer has the user.url column; the mapping still applies when a record carries it.
        $user->url = 'https://jane.example.com';
        $claims = claims::for_user($user, ['openid', 'profile']);

        $this->assertSame(fullname($user), $claims['name']);
        $this->assertSame('Jane', $claims['given_name']);
        $this->assertSame('Doe', $claims['family_name']);
        $this->assertSame('Q', $claims['middle_name']);
        $this->assertSame('JD', $claims['nickname']);
        $this->assertSame('jdoe', $claims['preferred_username']);
        $this->assertSame((new \moodle_url('/user/profile.php', ['id' => $user->id]))->out(false), $claims['profile']);
        $this->assertStringStartsWith('http', $claims['picture']);
        $this->assertSame('https://jane.example.com', $claims['website']);
        $this->assertSame('Europe/London', $claims['zoneinfo']);
        $this->assertSame('en', $claims['locale']);
        $this->assertSame((int) $user->timemodified, $claims['updated_at']);
        $this->assertArrayNotHasKey('email', $claims);
        $this->assertArrayNotHasKey('sub', $claims);
        $this->assertArrayNotHasKey('gender', $claims);
        $this->assertArrayNotHasKey('birthdate', $claims);
    }

    /**
     * Empty fields are left out rather than sent as null or ''.
     */
    public function test_empty_fields_omitted(): void {
        $this->resetAfterTest();
        $user = $this->user(['middlename' => '', 'alternatename' => '']);
        $user->url = '';
        $claims = claims::for_user($user, ['profile']);
        $this->assertArrayNotHasKey('middle_name', $claims);
        $this->assertArrayNotHasKey('nickname', $claims);
        $this->assertArrayNotHasKey('website', $claims);
        $this->assertNotContains(null, $claims);
        $this->assertNotContains('', $claims);
    }

    /**
     * The email scope adds only the address.
     */
    public function test_email(): void {
        $this->resetAfterTest();
        $user = $this->user(['email' => 'jane@example.com']);
        $this->assertSame(['email' => 'jane@example.com'], claims::for_user($user, ['openid', 'email']));
    }

    /**
     * Address is an object with country name; omitted when empty.
     */
    public function test_address(): void {
        $this->resetAfterTest();
        $user = $this->user(['address' => '1 Main Street', 'city' => 'Springfield', 'country' => 'NZ']);
        $country = get_string_manager()->get_list_of_countries()['NZ'];
        $claims = claims::for_user($user, ['address']);
        $this->assertSame([
            'street_address' => '1 Main Street',
            'locality' => 'Springfield',
            'country' => $country,
            'formatted' => '1 Main Street, Springfield, ' . $country,
        ], $claims['address']);

        $cityonly = $this->user(['address' => '', 'city' => 'Oslo', 'country' => '']);
        $this->assertSame(['locality' => 'Oslo', 'formatted' => 'Oslo'], claims::for_user($cityonly, ['address'])['address']);

        $none = $this->user(['address' => '', 'city' => '', 'country' => '']);
        $this->assertSame([], claims::for_user($none, ['address']));
    }

    /**
     * Phone falls back to the second number; omitted when both are empty.
     */
    public function test_phone(): void {
        $this->resetAfterTest();
        $both = $this->user(['phone1' => ' 111 ', 'phone2' => '222']);
        $this->assertSame(['phone_number' => '111'], claims::for_user($both, ['phone']));
        $second = $this->user(['phone1' => '', 'phone2' => '222']);
        $this->assertSame(['phone_number' => '222'], claims::for_user($second, ['phone']));
        $neither = $this->user(['phone1' => '', 'phone2' => '']);
        $this->assertSame([], claims::for_user($neither, ['phone']));
    }

    /**
     * With every scope, each emitted claim is advertised in discovery; unknown scopes are ignored.
     */
    public function test_supported_superset(): void {
        $this->resetAfterTest();
        $user = $this->user([
            'middlename' => 'M', 'alternatename' => 'N', 'address' => 'A',
            'city' => 'C', 'country' => 'FR', 'phone1' => '1',
        ]);
        $user->url = 'https://x.example';
        $claims = claims::for_user($user, array_merge(scopes::ALL, ['teacher.read']));
        foreach (array_keys($claims) as $name) {
            $this->assertContains($name, claims::SUPPORTED);
        }
        $this->assertSame([], claims::for_user($user, ['openid', 'teacher.read', 'moodle_mcp_read']));
    }

    /**
     * Deleted or unknown users have no claims.
     */
    public function test_deleted_user(): void {
        $this->resetAfterTest();
        $user = $this->user([]);
        $user->deleted = 1;
        $this->assertSame([], claims::for_user($user, scopes::ALL));
        $this->assertSame([], claims::for_user((object) ['id' => 0], scopes::ALL));
    }
}
