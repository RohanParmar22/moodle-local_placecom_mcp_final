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
 * User state and capability checks.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\oauth;

/**
 * Decides whether a user account may currently authorize assistants or use issued tokens.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_guard {
    /** @var string The user passes every check. */
    public const OK = '';

    /** @var string The account is deleted. */
    public const DELETED = 'deleted';

    /** @var string The account is suspended. */
    public const SUSPENDED = 'suspended';

    /** @var string The account is not confirmed. */
    public const UNCONFIRMED = 'unconfirmed';

    /** @var string The account uses the "No login" authentication method. */
    public const NOLOGIN = 'nologin';

    /** @var string The password has expired. */
    public const PASSWORD_EXPIRED = 'password_expired';

    /** @var string The account is locked out after failed logins. */
    public const LOCKED_OUT = 'locked_out';

    /** @var string The guest user. */
    public const GUEST = 'guest';

    /** @var string The site is in maintenance mode and the user has no maintenance access. */
    public const MAINTENANCE = 'maintenance';

    /** @var string Capability required to connect and use assistants. */
    public const USE_CAPABILITY = 'local/placecom_mcp:use';

    /**
     * First failing user-state check, or {@see self::OK}.
     *
     * @param \stdClass $user Full user record.
     * @return string One of the reason constants.
     */
    public static function check(\stdClass $user): string {
        global $CFG;
        require_once($CFG->libdir . '/authlib.php');

        if (empty($user->id) || isguestuser($user)) {
            return self::GUEST;
        }
        if (!empty($user->deleted)) {
            return self::DELETED;
        }
        if (!empty($user->suspended)) {
            return self::SUSPENDED;
        }
        if (empty($user->confirmed)) {
            return self::UNCONFIRMED;
        }
        if (($user->auth ?? '') === 'nologin') {
            return self::NOLOGIN;
        }
        if (isset($user->mnethostid) && login_is_lockedout($user)) {
            return self::LOCKED_OUT;
        }
        if (self::password_expired($user)) {
            return self::PASSWORD_EXPIRED;
        }
        if (
            !empty($CFG->maintenance_enabled)
                && !has_capability('moodle/site:maintenanceaccess', \context_system::instance(), $user)
        ) {
            return self::MAINTENANCE;
        }
        return self::OK;
    }

    /**
     * Whether the user holds the `local/placecom_mcp:use` capability in the system context.
     *
     * @param int $userid User id.
     * @return bool
     */
    public static function can_use(int $userid): bool {
        if ($userid <= 0) {
            return false;
        }
        return has_capability(self::USE_CAPABILITY, \context_system::instance(), $userid);
    }

    /**
     * Whether the user's authentication plugin enforces password expiry and the password has expired.
     *
     * @param \stdClass $user User record.
     * @return bool
     */
    private static function password_expired(\stdClass $user): bool {
        $auth = (string) ($user->auth ?? '');
        if ($auth === '' || !exists_auth_plugin($auth)) {
            return false;
        }
        $plugin = get_auth_plugin($auth);
        if (empty($plugin->config->expiration) || (int) $plugin->config->expiration !== 1) {
            return false;
        }
        return (int) $plugin->password_expire($user->username) < 0;
    }
}
