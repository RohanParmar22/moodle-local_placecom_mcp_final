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
 * Issuer, endpoint URLs and lifetimes of the authorization server.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\oauth;

/**
 * Static access to the plugin configuration used by the OAuth server and the MCP endpoint.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class config {
    /** @var int Authorization code lifetime in seconds. */
    public const CODE_LIFETIME = 30;

    /** @var int Default access (and ID) token lifetime in seconds. */
    public const DEFAULT_ACCESS_LIFETIME = 3600;

    /** @var int Default refresh token lifetime in seconds. */
    public const DEFAULT_REFRESH_LIFETIME = 604800;

    /** @var string Frankenstyle component name. */
    public const COMPONENT = 'local_placecom_mcp';

    /** @var string Path of the plugin below the issuer / site root. */
    public const PATH = '/local/placecom_mcp';

    /**
     * The issuer identifier: the issuer setting when set, otherwise the site URL, without trailing slashes.
     *
     * @return string
     */
    public static function issuer(): string {
        global $CFG;
        $issuer = trim((string) get_config(self::COMPONENT, 'issuer'));
        if ($issuer === '') {
            $issuer = (string) $CFG->wwwroot;
        }
        return rtrim($issuer, '/');
    }

    /**
     * Public URL of one of the plugin's scripts, built from the issuer.
     *
     * @param string $script Script file name, for example `token.php`.
     * @return string
     */
    public static function endpoint_url(string $script): string {
        return self::issuer() . self::PATH . '/' . ltrim($script, '/');
    }

    /**
     * URL of the MCP endpoint (the protected resource identifier).
     *
     * @return string
     */
    public static function mcp_resource_url(): string {
        return self::endpoint_url('mcp.php');
    }

    /**
     * URL of the RFC 9728 protected resource metadata document.
     *
     * @return string
     */
    public static function protected_resource_metadata_url(): string {
        return self::endpoint_url('protected_resource.php');
    }

    /**
     * Access token (and ID token) lifetime in seconds.
     *
     * @return int
     */
    public static function access_lifetime(): int {
        $value = (int) get_config(self::COMPONENT, 'accesstokenlifetime');
        return $value > 0 ? $value : self::DEFAULT_ACCESS_LIFETIME;
    }

    /**
     * Refresh token lifetime in seconds.
     *
     * @return int
     */
    public static function refresh_lifetime(): int {
        $value = (int) get_config(self::COMPONENT, 'refreshtokenlifetime');
        return $value > 0 ? $value : self::DEFAULT_REFRESH_LIFETIME;
    }

    /**
     * Whether the MCP endpoint is switched on. A setting that was never saved counts as on.
     *
     * @return bool
     */
    public static function is_mcp_enabled(): bool {
        $value = get_config(self::COMPONENT, 'enabled');
        return $value === false || (string) $value !== '0';
    }
}
