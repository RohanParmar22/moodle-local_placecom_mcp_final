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
 * OpenID Connect user claims.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\oauth;

/**
 * Maps a Moodle user record to OpenID Connect standard claims, grouped by scope.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class claims {
    /** @var string[] Claims advertised in discovery; superset of everything this server emits. */
    public const SUPPORTED = [
        'sub',
        'iss',
        'aud',
        'exp',
        'iat',
        'auth_time',
        'nonce',
        'name',
        'given_name',
        'family_name',
        'middle_name',
        'nickname',
        'preferred_username',
        'profile',
        'picture',
        'website',
        'zoneinfo',
        'locale',
        'updated_at',
        'email',
        'address',
        'phone_number',
    ];

    /**
     * User claims for the granted scopes. Never includes `sub`; empty values are left out.
     *
     * @param \stdClass $user Full user record.
     * @param string[] $scopes Granted scopes.
     * @return array
     */
    public static function for_user(\stdClass $user, array $scopes): array {
        if (empty($user->id) || !empty($user->deleted)) {
            return [];
        }
        $claims = [];

        if (in_array('profile', $scopes, true)) {
            self::add($claims, 'name', fullname($user));
            self::add($claims, 'given_name', $user->firstname ?? '');
            self::add($claims, 'family_name', $user->lastname ?? '');
            self::add($claims, 'middle_name', $user->middlename ?? '');
            self::add($claims, 'nickname', $user->alternatename ?? '');
            self::add($claims, 'preferred_username', $user->username ?? '');
            self::add($claims, 'profile', (new \moodle_url('/user/profile.php', ['id' => $user->id]))->out(false));
            self::add($claims, 'picture', self::picture_url($user));
            self::add($claims, 'website', $user->url ?? '');
            self::add($claims, 'zoneinfo', \core_date::get_user_timezone($user));
            self::add($claims, 'locale', str_replace('_', '-', (string) ($user->lang ?? '')));
            if (isset($user->timemodified)) {
                $claims['updated_at'] = (int) $user->timemodified;
            }
        }

        if (in_array('email', $scopes, true)) {
            self::add($claims, 'email', $user->email ?? '');
        }

        if (in_array('address', $scopes, true)) {
            $address = self::address($user);
            if ($address !== null) {
                $claims['address'] = $address;
            }
        }

        if (in_array('phone', $scopes, true)) {
            $phone = trim((string) ($user->phone1 ?? ''));
            if ($phone === '') {
                $phone = trim((string) ($user->phone2 ?? ''));
            }
            self::add($claims, 'phone_number', $phone);
        }

        return $claims;
    }

    /**
     * Add a string claim unless it is empty.
     *
     * @param array $claims Claims being built.
     * @param string $name Claim name.
     * @param mixed $value Claim value.
     */
    private static function add(array &$claims, string $name, $value): void {
        if ($value === null) {
            return;
        }
        $value = (string) $value;
        if (trim($value) === '') {
            return;
        }
        $claims[$name] = $value;
    }

    /**
     * OIDC address claim object, or null when the user has no address data.
     *
     * @param \stdClass $user User record.
     * @return array|null
     */
    private static function address(\stdClass $user): ?array {
        $street = trim((string) ($user->address ?? ''));
        $locality = trim((string) ($user->city ?? ''));
        $countrycode = trim((string) ($user->country ?? ''));
        $country = '';
        if ($countrycode !== '') {
            $countries = get_string_manager()->get_list_of_countries();
            $country = $countries[$countrycode] ?? $countrycode;
        }
        $address = [];
        if ($street !== '') {
            $address['street_address'] = $street;
        }
        if ($locality !== '') {
            $address['locality'] = $locality;
        }
        if ($country !== '') {
            $address['country'] = $country;
        }
        if (empty($address)) {
            return null;
        }
        $address['formatted'] = implode(', ', array_filter([$street, $locality, $country], static function ($part): bool {
            return $part !== '';
        }));
        return $address;
    }

    /**
     * URL of the user's large profile picture.
     *
     * @param \stdClass $user User record.
     * @return string
     */
    private static function picture_url(\stdClass $user): string {
        // A private page in system context keeps the global $PAGE untouched.
        $page = new \moodle_page();
        $page->set_context(\context_system::instance());
        $picture = new \user_picture($user);
        $picture->size = 1;
        return $picture->get_url($page)->out(false);
    }
}
