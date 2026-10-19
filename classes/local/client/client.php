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
 * Registered OAuth client value object.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\client;

/**
 * Immutable view of one row of the local_placecom_mcp_client table.
 *
 * The secret hash never leaves this object: callers verify a presented secret with
 * {@see self::verify_secret()} and {@see self::to_record()} omits the hash.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class client {
    /** @var int Database id. */
    private int $id;

    /** @var string Public client identifier. */
    private string $clientid;

    /** @var string Display name, may be empty. */
    private string $name;

    /** @var string Password hash of the secret, empty for public clients. */
    private string $secrethash;

    /** @var string|null Last four characters of the secret. */
    private ?string $secrethint;

    /** @var string[] Registered redirect URIs. */
    private array $redirecturis;

    /** @var string[] Registered scopes. */
    private array $scopes;

    /** @var bool Whether PKCE was configured as mandatory. */
    private bool $requirepkce;

    /** @var bool Whether the client may be used. */
    private bool $enabled;

    /** @var int Creation time. */
    private int $timecreated;

    /** @var int Last modification time. */
    private int $timemodified;

    /** @var int Id of the user who last modified the client. */
    private int $usermodified;

    /**
     * Builds the value object from a database record.
     *
     * @param \stdClass $record Row of local_placecom_mcp_client.
     */
    public function __construct(\stdClass $record) {
        $this->id = (int) ($record->id ?? 0);
        $this->clientid = (string) ($record->clientid ?? '');
        $this->name = (string) ($record->name ?? '');
        $this->secrethash = (string) ($record->secrethash ?? '');
        $this->secrethint = isset($record->secrethint) && $record->secrethint !== '' ? (string) $record->secrethint : null;
        $this->redirecturis = client_manager::parse_redirect_uris((string) ($record->redirecturis ?? ''));
        $scope = trim((string) ($record->scope ?? ''));
        $this->scopes = $scope === '' ? [] : array_values(array_unique(preg_split('/\s+/', $scope)));
        $this->requirepkce = !empty($record->requirepkce);
        $this->enabled = !isset($record->enabled) || !empty($record->enabled);
        $this->timecreated = (int) ($record->timecreated ?? 0);
        $this->timemodified = (int) ($record->timemodified ?? 0);
        $this->usermodified = (int) ($record->usermodified ?? 0);
    }

    /**
     * Named constructor, equivalent to the constructor.
     *
     * @param \stdClass $record Row of local_placecom_mcp_client.
     * @return self
     */
    public static function from_record(\stdClass $record): self {
        return new self($record);
    }

    /**
     * Database id of the client.
     *
     * @return int
     */
    public function get_id(): int {
        return $this->id;
    }

    /**
     * Public client identifier (the OAuth client_id).
     *
     * @return string
     */
    public function get_clientid(): string {
        return $this->clientid;
    }

    /**
     * Display name, falling back to the client identifier when no name was set.
     *
     * @return string Raw, unformatted text.
     */
    public function get_name(): string {
        return trim($this->name) === '' ? $this->clientid : $this->name;
    }

    /**
     * Display name prepared for output.
     *
     * @return string
     */
    public function get_display_name(): string {
        return format_string($this->get_name(), true, ['context' => \context_system::instance()]);
    }

    /**
     * Registered redirect URIs in their stored order.
     *
     * @return string[]
     */
    public function get_redirect_uris(): array {
        return $this->redirecturis;
    }

    /**
     * Whether the given URI is byte-for-byte equal to one of the registered URIs.
     *
     * @param string $uri Redirect URI presented by the client.
     * @return bool
     */
    public function has_redirect_uri(string $uri): bool {
        foreach ($this->redirecturis as $registered) {
            if ($registered === $uri) {
                return true;
            }
        }
        return false;
    }

    /**
     * Registered scopes.
     *
     * @return string[]
     */
    public function get_scopes(): array {
        return $this->scopes;
    }

    /**
     * Registered scopes as a space separated string.
     *
     * @return string
     */
    public function get_scope(): string {
        return implode(' ', $this->scopes);
    }

    /**
     * Whether authorisation requests must use PKCE. Always true for public clients.
     *
     * @return bool
     */
    public function requires_pkce(): bool {
        return $this->requirepkce || $this->is_public();
    }

    /**
     * Whether this is a public client (no secret).
     *
     * @return bool
     */
    public function is_public(): bool {
        return $this->secrethash === '';
    }

    /**
     * Whether the client is enabled.
     *
     * @return bool
     */
    public function is_enabled(): bool {
        return $this->enabled;
    }

    /**
     * Last four characters of the secret, if known.
     *
     * @return string|null
     */
    public function get_secret_hint(): ?string {
        return $this->secrethint;
    }

    /**
     * Creation time.
     *
     * @return int
     */
    public function get_timecreated(): int {
        return $this->timecreated;
    }

    /**
     * Time of the last change.
     *
     * @return int
     */
    public function get_timemodified(): int {
        return $this->timemodified;
    }

    /**
     * Checks a presented client secret.
     *
     * @param string $secret Secret presented by the client.
     * @return bool False for public clients and for empty or wrong secrets.
     */
    public function verify_secret(string $secret): bool {
        if ($this->is_public() || $secret === '') {
            return false;
        }
        return password_verify($secret, $this->secrethash);
    }

    /**
     * Plain record of the client without the secret hash.
     *
     * @return \stdClass
     */
    public function to_record(): \stdClass {
        return (object) [
            'id' => $this->id,
            'clientid' => $this->clientid,
            'name' => $this->name,
            'secrethint' => $this->secrethint,
            'redirecturis' => implode("\n", $this->redirecturis),
            'scope' => $this->get_scope(),
            'requirepkce' => $this->requirepkce ? 1 : 0,
            'enabled' => $this->enabled ? 1 : 0,
            'ispublic' => $this->is_public() ? 1 : 0,
            'timecreated' => $this->timecreated,
            'timemodified' => $this->timemodified,
            'usermodified' => $this->usermodified,
        ];
    }
}
