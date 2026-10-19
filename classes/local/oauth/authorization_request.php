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
 * Validated authorization request.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\oauth;

use local_placecom_mcp\local\client\client;

/**
 * Immutable result of {@see authorize_controller::validate()}.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class authorization_request {
    /** @var client The requesting client. */
    private client $client;

    /** @var string Effective redirect URI. */
    private string $redirecturi;

    /** @var string|null Redirect URI as supplied in the request, null when omitted. */
    private ?string $suppliedredirecturi;

    /** @var string[] Granted (narrowed) scopes. */
    private array $scopes;

    /** @var string|null State value. */
    private ?string $state;

    /** @var string|null S256 code challenge. */
    private ?string $codechallenge;

    /** @var string|null OIDC nonce. */
    private ?string $nonce;

    /** @var string[] Values of the prompt parameter. */
    private array $prompts;

    /** @var array Non-empty raw request parameters. */
    private array $params;

    /**
     * Create the request. Only {@see authorize_controller::validate()} should call this.
     *
     * @param client $client Requesting client.
     * @param string $redirecturi Effective redirect URI.
     * @param string|null $suppliedredirecturi Supplied redirect URI or null.
     * @param string[] $scopes Granted scopes.
     * @param string|null $state State or null.
     * @param string|null $codechallenge Code challenge or null.
     * @param string|null $nonce Nonce or null.
     * @param string[] $prompts Prompt values.
     * @param array $params Raw request parameters.
     */
    public function __construct(
        client $client,
        string $redirecturi,
        ?string $suppliedredirecturi,
        array $scopes,
        ?string $state,
        ?string $codechallenge,
        ?string $nonce,
        array $prompts,
        array $params
    ) {
        $this->client = $client;
        $this->redirecturi = $redirecturi;
        $this->suppliedredirecturi = $suppliedredirecturi;
        $this->scopes = array_values($scopes);
        $this->state = $state;
        $this->codechallenge = $codechallenge;
        $this->nonce = $nonce;
        $this->prompts = array_values($prompts);
        $this->params = array_filter($params, static function ($value): bool {
            return is_string($value) && $value !== '';
        });
    }

    /**
     * The requesting client.
     *
     * @return client
     */
    public function get_client(): client {
        return $this->client;
    }

    /**
     * Effective redirect URI (supplied or the single registered one).
     *
     * @return string
     */
    public function get_redirect_uri(): string {
        return $this->redirecturi;
    }

    /**
     * Redirect URI as supplied in the request.
     *
     * @return string|null
     */
    public function get_supplied_redirect_uri(): ?string {
        return $this->suppliedredirecturi;
    }

    /**
     * Granted scopes.
     *
     * @return string[]
     */
    public function get_scopes(): array {
        return $this->scopes;
    }

    /**
     * Granted scopes as a space separated string.
     *
     * @return string
     */
    public function get_scope(): string {
        return scopes::join($this->scopes);
    }

    /**
     * State value.
     *
     * @return string|null
     */
    public function get_state(): ?string {
        return $this->state;
    }

    /**
     * S256 code challenge.
     *
     * @return string|null
     */
    public function get_code_challenge(): ?string {
        return $this->codechallenge;
    }

    /**
     * OIDC nonce.
     *
     * @return string|null
     */
    public function get_nonce(): ?string {
        return $this->nonce;
    }

    /**
     * Values of the prompt parameter.
     *
     * @return string[]
     */
    public function get_prompts(): array {
        return $this->prompts;
    }

    /**
     * Whether the prompt parameter contains a value.
     *
     * @param string $prompt Prompt value, for example `none` or `consent`.
     * @return bool
     */
    public function has_prompt(string $prompt): bool {
        return in_array($prompt, $this->prompts, true);
    }

    /**
     * The non-empty raw request parameters (for the login return URL and the consent form).
     *
     * @return array
     */
    public function get_params(): array {
        return $this->params;
    }

    /**
     * URL of the authorization endpoint carrying the original parameters.
     *
     * @return \moodle_url
     */
    public function get_url(): \moodle_url {
        return new \moodle_url(config::PATH . '/authorize.php', $this->params);
    }
}
