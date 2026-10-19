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
 * OAuth scope catalogue and helpers.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\oauth;

/**
 * The fixed set of scopes this server knows, plus parsing, narrowing and description helpers.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scopes {
    /** @var string OpenID Connect scope. */
    public const OPENID = 'openid';

    /** @var string Scope that allows refresh tokens for OpenID requests. */
    public const OFFLINE = 'offline_access';

    /** @var string Scope required to use the MCP endpoint at all. */
    public const READ = 'moodle_mcp_read';

    /** @var string Scope required for MCP tools that change data. */
    public const WRITE = 'moodle_mcp_write';

    /** @var string[] Every scope the server supports, in catalogue order. */
    public const ALL = [
        'openid',
        'profile',
        'email',
        'offline_access',
        'address',
        'phone',
        'moodle_mcp_read',
        'moodle_mcp_write',
    ];

    /**
     * Split a scope string on whitespace, dropping empty members and duplicates (first occurrence wins).
     *
     * @param string|null $scope Space separated scope string.
     * @return string[]
     */
    public static function parse(?string $scope): array {
        if ($scope === null) {
            return [];
        }
        $parts = preg_split('/\s+/', $scope, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique($parts ?: []));
    }

    /**
     * Join scopes into a single-space separated string.
     *
     * @param string[] $scopes Scopes.
     * @return string
     */
    public static function join(array $scopes): string {
        return implode(' ', $scopes);
    }

    /**
     * Normalise admin input: commas, tabs, newlines and runs of whitespace become one space; duplicates go.
     *
     * @param string $input Raw input.
     * @return string
     */
    public static function normalise(string $input): string {
        return self::join(self::parse(str_replace(',', ' ', $input)));
    }

    /**
     * Members of the list that are not in the catalogue.
     *
     * @param string[] $scopes Scopes to check.
     * @return string[]
     */
    public static function unknown(array $scopes): array {
        return array_values(array_diff($scopes, self::ALL));
    }

    /**
     * Requested scopes that are also registered for the client, in request order.
     *
     * @param string[] $requested Scopes from the request.
     * @param string[] $registered Scopes registered for the client.
     * @return string[] Possibly empty list.
     */
    public static function narrow(array $requested, array $registered): array {
        $result = [];
        foreach ($requested as $scope) {
            if (in_array($scope, $registered, true) && !in_array($scope, $result, true)) {
                $result[] = $scope;
            }
        }
        return $result;
    }

    /**
     * Whether every member of the first list is in the second.
     *
     * @param string[] $subset Candidate subset.
     * @param string[] $of Superset.
     * @return bool
     */
    public static function is_subset(array $subset, array $of): bool {
        foreach ($subset as $scope) {
            if (!in_array($scope, $of, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Human readable description of a scope, or the raw scope name when there is none.
     *
     * @param string $scope Scope name.
     * @return string
     */
    public static function describe(string $scope): string {
        $identifier = 'scope_' . $scope;
        if (preg_match('/^[a-z0-9_]+$/', $scope) && get_string_manager()->string_exists($identifier, config::COMPONENT)) {
            return get_string($identifier, config::COMPONENT);
        }
        return $scope;
    }
}
