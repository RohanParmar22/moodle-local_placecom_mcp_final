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
 * Authenticated MCP caller.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\mcp;

use local_placecom_mcp\local\oauth\scopes;

/**
 * Immutable description of who is calling the MCP endpoint and with which access token.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class auth_context {
    /** @var \stdClass The user record of the token owner. */
    protected \stdClass $user;

    /** @var \stdClass Access token row as returned by token_store::find_access_token(). */
    protected \stdClass $token;

    /** @var string[] Scopes of the access token. */
    protected array $scopes;

    /**
     * Constructor.
     *
     * @param \stdClass $user User record of the token owner.
     * @param \stdClass $token Access token row from token_store::find_access_token().
     */
    public function __construct(\stdClass $user, \stdClass $token) {
        $this->user = $user;
        $this->token = $token;
        $this->scopes = scopes::parse((string) ($token->scope ?? ''));
    }

    /**
     * The user record of the token owner.
     *
     * @return \stdClass
     */
    public function get_user(): \stdClass {
        return $this->user;
    }

    /**
     * Id of the token owner.
     *
     * @return int
     */
    public function get_userid(): int {
        return (int) $this->user->id;
    }

    /**
     * Database id of the access token row.
     *
     * @return int
     */
    public function get_tokenid(): int {
        return (int) $this->token->id;
    }

    /**
     * Id of the grant (connection) the token belongs to.
     *
     * @return int
     */
    public function get_grantid(): int {
        return (int) $this->token->grantid;
    }

    /**
     * Public identifier of the OAuth client that obtained the token.
     *
     * @return string
     */
    public function get_clientid(): string {
        return (string) $this->token->clientidentifier;
    }

    /**
     * Scopes granted to the token.
     *
     * @return string[]
     */
    public function get_scopes(): array {
        return $this->scopes;
    }

    /**
     * Whether the token carries a scope.
     *
     * @param string $scope Scope name.
     * @return bool
     */
    public function has_scope(string $scope): bool {
        return in_array($scope, $this->scopes, true);
    }

    /**
     * Whether the token may call write functions.
     *
     * @return bool
     */
    public function can_write(): bool {
        return $this->has_scope(scopes::WRITE);
    }
}
