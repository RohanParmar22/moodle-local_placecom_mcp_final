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
 * MCP server: HTTP handling and JSON-RPC dispatch.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\mcp;

use local_placecom_mcp\local\http_response;
use local_placecom_mcp\local\oauth\config;

/**
 * Stateless MCP server over Streamable HTTP (JSON responses only, no SSE, no sessions).
 *
 * Order of checks: CORS preflight, site and plugin gates, HTTP method, authentication, protocol
 * version header, body size, body parsing, dispatch. Nothing in the body is looked at before the
 * caller is authenticated.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class server {
    /** @var string Server name reported by initialize. */
    public const SERVER_NAME = 'Moodle MCP Server';

    /** @var string[] Earlier MCP protocol revisions, which open with an initialize handshake, latest first. */
    public const SUPPORTED_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];

    /** @var string The revision that carries version and capabilities in every request. */
    public const MODERN_VERSION = '2026-07-28';

    /** @var string[] Every revision the server answers, latest first. */
    public const ALL_VERSIONS = ['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26'];

    /** @var int How long, in milliseconds, clients may cache a server/discover or tools/list result. */
    public const MODERN_TTL_MS = 60000;

    /** @var int Largest accepted request body in bytes (1 MiB). */
    public const MAX_BODY = 1048576;

    /** @var string Fallback version when the plugin information cannot be read. */
    protected const FALLBACK_VERSION = '0.3.0';

    /** @var bearer_authenticator Authenticator. */
    protected bearer_authenticator $auth;

    /** @var tool_registry Tool registry. */
    protected tool_registry $registry;

    /** @var tool_executor Tool executor. */
    protected tool_executor $executor;

    /**
     * Constructor; collaborators may be injected for tests.
     *
     * @param bearer_authenticator|null $auth Authenticator.
     * @param tool_registry|null $registry Tool registry.
     * @param tool_executor|null $executor Tool executor.
     */
    public function __construct(
        ?bearer_authenticator $auth = null,
        ?tool_registry $registry = null,
        ?tool_executor $executor = null
    ) {
        $this->auth = $auth ?? new bearer_authenticator();
        $this->registry = $registry ?? new tool_registry();
        $this->executor = $executor ?? new tool_executor();
    }

    /**
     * Version reported in serverInfo: the plugin release.
     *
     * @return string
     */
    public static function server_version(): string {
        try {
            $info = \core_plugin_manager::instance()->get_plugin_info('local_placecom_mcp');
            $release = $info ? (string) ($info->release ?? '') : '';
        } catch (\Throwable $e) {
            $release = '';
        }
        return $release !== '' ? $release : self::FALLBACK_VERSION;
    }

    /**
     * Choose the protocol version for initialize.
     *
     * @param string|null $requested Version proposed by the client.
     * @return string The requested version when supported, else the latest supported one.
     */
    public static function negotiate_version(?string $requested): string {
        if ($requested !== null && in_array($requested, self::SUPPORTED_VERSIONS, true)) {
            return $requested;
        }
        return self::SUPPORTED_VERSIONS[0];
    }

    /**
     * Handle one HTTP request. Never throws.
     *
     * @param string $httpmethod HTTP method.
     * @param array $server Server variables.
     * @param array|null $headers Request headers keyed by name (getallheaders()), or null.
     * @param string $body Raw request body.
     * @param bool $login Whether to log the token owner in (false in some unit tests).
     * @return http_response
     */
    public function handle(
        string $httpmethod,
        array $server,
        ?array $headers,
        string $body,
        bool $login = true
    ): http_response {
        try {
            $response = $this->process(strtoupper($httpmethod), $server, $headers, $body, $login);
        } catch (\Throwable $e) {
            $response = $this->internal_error(null, $e);
        }
        return $response->with_headers(self::common_headers());
    }

    /**
     * Handle a request; may throw for unexpected failures.
     *
     * @param string $httpmethod Upper-case HTTP method.
     * @param array $server Server variables.
     * @param array|null $headers Request headers.
     * @param string $body Raw body.
     * @param bool $login Whether to log the token owner in.
     * @return http_response
     */
    protected function process(
        string $httpmethod,
        array $server,
        ?array $headers,
        string $body,
        bool $login
    ): http_response {
        global $CFG;

        if ($httpmethod === 'OPTIONS') {
            return http_response::empty(204, [
                'Access-Control-Allow-Methods' => 'POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'Authorization, Content-Type, Accept, Mcp-Protocol-Version, ' .
                    'Mcp-Session-Id, Mcp-Method, Mcp-Name',
                'Access-Control-Max-Age' => '86400',
            ]);
        }

        if (empty($CFG->enablewebservices)) {
            return $this->error_response(403, null, jsonrpc::FORBIDDEN, self::str('error_wsdisabled'));
        }
        if (!config::is_mcp_enabled()) {
            return $this->error_response(403, null, jsonrpc::FORBIDDEN, self::str('error_mcpdisabled'));
        }

        if ($httpmethod !== 'POST') {
            return $this->error_response(
                405,
                null,
                jsonrpc::INVALID_REQUEST,
                self::str('error_methodnotallowed'),
                ['Allow' => 'POST, OPTIONS']
            );
        }

        try {
            $ctx = $this->auth->authenticate(bearer_authenticator::extract_token($server, $headers), $login);
        } catch (auth_exception $e) {
            return $e->to_response();
        }

        $version = self::header_value($server, $headers, 'MCP-Protocol-Version');
        if ($version !== null && !in_array($version, self::ALL_VERSIONS, true)) {
            return $this->error_response(
                400,
                null,
                jsonrpc::UNSUPPORTED_PROTOCOL_VERSION,
                get_string('error_protocolversion', 'local_placecom_mcp', [
                    'version' => s($version),
                    'supported' => implode(', ', self::ALL_VERSIONS),
                ]),
                [],
                ['supported' => self::ALL_VERSIONS, 'requested' => $version]
            );
        }

        $modern = ($version === self::MODERN_VERSION);
        if (strlen($body) > self::MAX_BODY) {
            return $this->error_response(413, null, jsonrpc::INVALID_REQUEST, self::str('error_bodytoolarge'));
        }

        try {
            $request = request::parse($body);
        } catch (jsonrpc_exception $e) {
            return $this->error_response($e->get_http_status(), null, $e->get_rpc_code(), $e->get_rpc_message());
        }

        if ($request->is_notification() || $request->is_response()) {
            return http_response::empty(202);
        }

        if ($modern) {
            $problem = $this->modern_header_problem($server, $headers, $request);
            if ($problem !== null) {
                return $this->error_response(
                    400,
                    null,
                    jsonrpc::HEADER_MISMATCH,
                    get_string('error_headermismatch', 'local_placecom_mcp', $problem)
                );
            }
        }
        $id = $request->get_id();
        try {
            $result = $modern ? $this->dispatch_modern($request, $ctx) : $this->dispatch($request, $ctx);
        } catch (jsonrpc_exception $e) {
            return $this->error_response($e->get_http_status(), $id, $e->get_rpc_code(), $e->get_rpc_message());
        } catch (\Throwable $e) {
            return $this->internal_error($id, $e);
        }
        return $this->json_response(200, jsonrpc::result($id, $result));
    }

    /**
     * Run a JSON-RPC method.
     *
     * @param request $request Parsed request (not a notification).
     * @param auth_context $ctx Authenticated caller.
     * @return mixed The result member.
     * @throws jsonrpc_exception For unknown methods and invalid parameters.
     */
    protected function dispatch(request $request, auth_context $ctx) {
        switch ($request->get_method()) {
            case 'initialize':
                $params = $request->get_params() ?? [];
                $requested = isset($params['protocolVersion']) && is_string($params['protocolVersion'])
                    ? $params['protocolVersion'] : null;
                return [
                    'protocolVersion' => self::negotiate_version($requested),
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => ['name' => self::SERVER_NAME, 'version' => self::server_version()],
                    'instructions' => self::str('mcp_instructions'),
                ];
            case 'ping':
                return new \stdClass();
            case 'tools/list':
                return ['tools' => $this->registry->list_tools($ctx)];
            case 'tools/call':
                return $this->executor->call($request->get_tool_name(), $request->get_tool_arguments(), $ctx);
            default:
                throw new jsonrpc_exception(jsonrpc::METHOD_NOT_FOUND, self::str('error_methodnotfound'));
        }
    }

    /**
     * Check the headers that a 2026-07-28 request must mirror from its body.
     *
     * @param array $server Server variables.
     * @param array|null $headers Request headers.
     * @param request $request Parsed request (not a notification).
     * @return string|null Name of the header that is missing or does not match, null when all is well.
     */
    protected function modern_header_problem(array $server, ?array $headers, request $request): ?string {
        $params = $request->get_params() ?? [];
        $meta = $params['_meta'] ?? null;
        $metaversion = is_array($meta) ? ($meta['io.modelcontextprotocol/protocolVersion'] ?? null) : null;
        if ($metaversion !== self::MODERN_VERSION) {
            return 'MCP-Protocol-Version';
        }
        if (self::header_value($server, $headers, 'Mcp-Method') !== $request->get_method()) {
            return 'Mcp-Method';
        }
        if ($request->is_tool_call()) {
            $name = $params['name'] ?? null;
            if (!is_string($name) || self::header_value($server, $headers, 'Mcp-Name') !== $name) {
                return 'Mcp-Name';
            }
        }
        return null;
    }

    /**
     * Run a JSON-RPC method for a 2026-07-28 request.
     *
     * @param request $request Parsed request (not a notification).
     * @param auth_context $ctx Authenticated caller.
     * @return mixed The result member.
     * @throws jsonrpc_exception For unknown methods and invalid parameters.
     */
    protected function dispatch_modern(request $request, auth_context $ctx) {
        switch ($request->get_method()) {
            case 'server/discover':
                return [
                    'resultType' => 'complete',
                    'supportedVersions' => self::ALL_VERSIONS,
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'instructions' => self::str('mcp_instructions'),
                    '_meta' => [
                        'io.modelcontextprotocol/serverInfo' => [
                            'name' => self::SERVER_NAME,
                            'version' => self::server_version(),
                        ],
                    ],
                    'ttlMs' => self::MODERN_TTL_MS,
                    'cacheScope' => 'private',
                ];
            case 'tools/list':
                return [
                    'resultType' => 'complete',
                    'tools' => $this->registry->list_tools($ctx),
                    'ttlMs' => self::MODERN_TTL_MS,
                    'cacheScope' => 'private',
                ];
            case 'tools/call':
                return ['resultType' => 'complete'] +
                    $this->executor->call($request->get_tool_name(), $request->get_tool_arguments(), $ctx);
            default:
                throw new jsonrpc_exception(jsonrpc::METHOD_NOT_FOUND, self::str('error_methodnotfound'));
        }
    }

    /**
     * Response for an unexpected failure.
     *
     * @param string|int|null $id Request id, if known.
     * @param \Throwable $e The failure.
     * @return http_response
     */
    protected function internal_error($id, \Throwable $e): http_response {
        debugging('MCP request failed: ' . $e->getMessage(), DEBUG_DEVELOPER, $e->getTrace());
        try {
            $message = self::str('error_internal');
        } catch (\Throwable $stringerror) {
            $message = 'Internal error';
        }
        return $this->error_response(200, $id, jsonrpc::INTERNAL_ERROR, $message, [], jsonrpc::debug_data($e));
    }

    /**
     * Build a JSON-RPC error response.
     *
     * @param int $status HTTP status.
     * @param string|int|null $id Request id.
     * @param int $code JSON-RPC error code.
     * @param string $message Error message.
     * @param array $headers Extra headers.
     * @param array|null $data Optional error data.
     * @return http_response
     */
    protected function error_response(
        int $status,
        $id,
        int $code,
        string $message,
        array $headers = [],
        ?array $data = null
    ): http_response {
        return $this->json_response($status, jsonrpc::error($id, $code, $message, $data))->with_headers($headers);
    }

    /**
     * Build a JSON response, falling back to a fixed internal error document if encoding fails.
     *
     * @param int $status HTTP status.
     * @param array $payload JSON-RPC envelope.
     * @return http_response
     */
    protected function json_response(int $status, array $payload): http_response {
        try {
            $body = jsonrpc::encode($payload);
        } catch (\Throwable $e) {
            $status = 200;
            $body = '{"jsonrpc":"2.0","id":null,"error":{"code":' . jsonrpc::INTERNAL_ERROR .
                ',"message":"Internal error"}}';
        }
        return new http_response($status, ['Content-Type' => 'application/json; charset=utf-8'], $body);
    }

    /**
     * Headers sent with every MCP response.
     *
     * @return array<string, string>
     */
    public static function common_headers(): array {
        return [
            'Cache-Control' => 'private, must-revalidate, max-age=0',
            'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
            'Pragma' => 'no-cache',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Expose-Headers' => 'WWW-Authenticate',
        ];
    }

    /**
     * Read a request header from the header array (case-insensitive) or the server variables.
     *
     * @param array $server Server variables.
     * @param array|null $headers Request headers.
     * @param string $name Header name.
     * @return string|null
     */
    protected static function header_value(array $server, ?array $headers, string $name): ?string {
        if ($headers !== null) {
            foreach ($headers as $key => $value) {
                if (is_string($key) && strcasecmp($key, $name) === 0 && is_scalar($value)) {
                    return trim((string) $value);
                }
            }
        }
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($server[$key]) && is_scalar($server[$key])) {
            return trim((string) $server[$key]);
        }
        return null;
    }

    /**
     * Fetch a plugin string.
     *
     * @param string $identifier String identifier.
     * @return string
     */
    protected static function str(string $identifier): string {
        return get_string($identifier, 'local_placecom_mcp');
    }
}
