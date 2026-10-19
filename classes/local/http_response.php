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
 * HTTP response value object shared by the OAuth and MCP endpoints.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local;

/**
 * Immutable description of an HTTP response (status, headers and body).
 *
 * Controllers build instances of this class instead of writing to the output directly, so that
 * every endpoint can be exercised from unit tests. Endpoint scripts call {@see send()}.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class http_response {
    /** @var int HTTP status code. */
    private int $status;

    /** @var array<string, string> Header values keyed by header name. */
    private array $headers = [];

    /** @var string|null Response body, null when there is none. */
    private ?string $body;

    /**
     * Create a response.
     *
     * @param int $status HTTP status code.
     * @param array $headers Header values keyed by header name.
     * @param string|null $body Response body or null for none.
     */
    public function __construct(int $status, array $headers = [], ?string $body = null) {
        $this->status = $status;
        $this->body = $body;
        foreach ($headers as $name => $value) {
            $this->set_header((string) $name, (string) $value);
        }
    }

    /**
     * Build a JSON response.
     *
     * Pass a stdClass (for example `(object) []`) wherever an empty JSON object is required.
     *
     * @param int $status HTTP status code.
     * @param mixed $data Data to encode.
     * @param array $headers Extra headers.
     * @return self
     * @throws \coding_exception When the data cannot be encoded.
     */
    public static function json(int $status, $data, array $headers = []): self {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new \coding_exception('Unable to encode the response as JSON: ' . json_last_error_msg());
        }
        $response = new self($status, ['Content-Type' => 'application/json; charset=utf-8'], $body);
        return $response->with_headers($headers);
    }

    /**
     * Build an OAuth 2.0 error response (RFC 6749 section 5.2).
     *
     * @param int $status HTTP status code.
     * @param string $error OAuth error code.
     * @param string $description Human readable description.
     * @param array $headers Extra headers.
     * @return self
     */
    public static function oauth_error(int $status, string $error, string $description, array $headers = []): self {
        $data = ['error' => $error, 'error_description' => $description];
        $nocache = ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache'];
        return self::json($status, $data, $nocache)->with_headers($headers);
    }

    /**
     * Build a 302 redirect response.
     *
     * @param string $url Absolute target URL (not HTML escaped).
     * @return self
     */
    public static function redirect(string $url): self {
        return new self(302, ['Location' => $url, 'Cache-Control' => 'no-store']);
    }

    /**
     * Build a response without a body.
     *
     * @param int $status HTTP status code.
     * @param array $headers Headers.
     * @return self
     */
    public static function empty(int $status, array $headers = []): self {
        return new self($status, $headers, null);
    }

    /**
     * Return a copy of this response with additional or replaced headers.
     *
     * Header names are matched case-insensitively; a new value replaces an existing one.
     *
     * @param array $headers Header values keyed by header name.
     * @return self
     */
    public function with_headers(array $headers): self {
        $copy = clone $this;
        foreach ($headers as $name => $value) {
            $copy->set_header((string) $name, (string) $value);
        }
        return $copy;
    }

    /**
     * Set a header on this instance, replacing any header with the same name in any letter case.
     *
     * @param string $name Header name.
     * @param string $value Header value.
     */
    private function set_header(string $name, string $value): void {
        foreach (array_keys($this->headers) as $existing) {
            if (strcasecmp($existing, $name) === 0) {
                unset($this->headers[$existing]);
            }
        }
        // Header values never legitimately contain line breaks; strip them to prevent header injection.
        $this->headers[$name] = str_replace(["\r", "\n"], '', $value);
    }

    /**
     * HTTP status code.
     *
     * @return int
     */
    public function get_status(): int {
        return $this->status;
    }

    /**
     * All headers keyed by name.
     *
     * @return array<string, string>
     */
    public function get_headers(): array {
        return $this->headers;
    }

    /**
     * A single header value, looked up case-insensitively.
     *
     * @param string $name Header name.
     * @return string|null The value or null when not set.
     */
    public function get_header(string $name): ?string {
        foreach ($this->headers as $existing => $value) {
            if (strcasecmp($existing, $name) === 0) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Response body.
     *
     * @return string|null
     */
    public function get_body(): ?string {
        return $this->body;
    }

    /**
     * Body decoded as an associative array (intended for tests).
     *
     * @return array|null Decoded data or null when the body is empty or not a JSON object/array.
     */
    public function get_json(): ?array {
        if ($this->body === null || $this->body === '') {
            return null;
        }
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Emit the status line, headers and body. Does not terminate the script.
     */
    public function send(): void {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        if ($this->body !== null) {
            echo $this->body;
        }
    }

    /**
     * Append query parameters to a URL using a plain `&` separator (never HTML escaped).
     *
     * Parameters whose value is null or an empty string are left out.
     *
     * @param string $base Base URL, which may already contain a query string.
     * @param array $params Parameters to add.
     * @return string
     */
    public static function build_url(string $base, array $params): string {
        $params = array_filter($params, static function ($value): bool {
            return $value !== null && $value !== '';
        });
        if (empty($params)) {
            return $base;
        }
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $separator = (strpos($base, '?') === false) ? '?' : '&';
        if (substr($base, -1) === '?' || substr($base, -1) === '&') {
            $separator = '';
        }
        return $base . $separator . $query;
    }
}
