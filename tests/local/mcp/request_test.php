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
 * Tests for the JSON-RPC message parser.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\mcp;

#[\PHPUnit\Framework\Attributes\CoversClass(request::class)]
/**
 * Tests for {@see request}.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_placecom_mcp\local\mcp\request
 */
final class request_test extends \advanced_testcase {
    /**
     * Parse a body and return the protocol exception it raises.
     *
     * @param string $body Request body.
     * @return jsonrpc_exception
     */
    private function parse_failure(string $body): jsonrpc_exception {
        try {
            request::parse($body);
        } catch (jsonrpc_exception $e) {
            return $e;
        }
        $this->fail('Expected a jsonrpc_exception for: ' . $body);
    }

    /**
     * A complete request exposes all its members.
     */
    public function test_valid_request(): void {
        $request = request::parse('{"jsonrpc":"2.0","method":"tools/list","id":1,"params":{}}');
        $this->assertSame('2.0', $request->get_jsonrpc());
        $this->assertSame('tools/list', $request->get_method());
        $this->assertSame(1, $request->get_id());
        $this->assertTrue($request->has_id());
        $this->assertSame([], $request->get_params());
        $this->assertFalse($request->is_notification());
        $this->assertFalse($request->is_response());
        $this->assertFalse($request->is_tool_call());
    }

    /**
     * A message without id is a notification.
     */
    public function test_notification_without_id(): void {
        $request = request::parse('{"jsonrpc":"2.0","method":"initialize"}');
        $this->assertNull($request->get_id());
        $this->assertFalse($request->has_id());
        $this->assertNull($request->get_params());
        $this->assertTrue($request->is_notification());
    }

    /**
     * Params are optional.
     */
    public function test_request_without_params(): void {
        $request = request::parse('{"jsonrpc":"2.0","method":"ping","id":7}');
        $this->assertNull($request->get_params());
        $this->assertSame([], $request->get_tool_arguments());
    }

    /**
     * String ids are preserved.
     */
    public function test_string_id(): void {
        $request = request::parse('{"jsonrpc":"2.0","method":"ping","id":"request-123"}');
        $this->assertSame('request-123', $request->get_id());
    }

    /**
     * Data provider of invalid envelopes.
     *
     * @return array
     */
    public static function invalid_provider(): array {
        return [
            'missing jsonrpc' => ['{"method":"ping","id":1}', jsonrpc::INVALID_REQUEST, 'error_jsonrpcversion'],
            'jsonrpc 1.0' => ['{"jsonrpc":"1.0","method":"ping","id":1}', jsonrpc::INVALID_REQUEST, 'error_jsonrpcversion'],
            'missing method' => ['{"jsonrpc":"2.0","id":1}', jsonrpc::INVALID_REQUEST, 'error_methodrequired'],
            'empty method' => ['{"jsonrpc":"2.0","method":"","id":1}', jsonrpc::INVALID_REQUEST, 'error_methodrequired'],
            'integer method' => ['{"jsonrpc":"2.0","method":123,"id":1}', jsonrpc::INVALID_REQUEST, 'error_methodrequired'],
            'invalid json' => ['{"jsonrpc":"2.0",', jsonrpc::PARSE_ERROR, 'error_invalidjson'],
            'batch' => ['[{"jsonrpc":"2.0","method":"ping","id":1}]', jsonrpc::INVALID_REQUEST, 'error_batchnotsupported'],
            'empty body' => ['   ', jsonrpc::INVALID_REQUEST, 'error_emptybody'],
            'scalar' => ['"hello"', jsonrpc::INVALID_REQUEST, 'error_notobject'],
            'object id' => ['{"jsonrpc":"2.0","method":"ping","id":{"a":1}}', jsonrpc::INVALID_REQUEST, 'error_invalidid'],
            'params list' => ['{"jsonrpc":"2.0","method":"ping","id":1,"params":[1,2]}', jsonrpc::INVALID_PARAMS,
                'error_paramsnotobject'],
        ];
    }

    /**
     * Invalid envelopes are rejected with standard codes.
     *
     * @dataProvider invalid_provider
     * @param string $body Request body.
     * @param int $code Expected JSON-RPC code.
     * @param string $identifier Expected language string.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_provider')]
    public function test_invalid_messages(string $body, int $code, string $identifier): void {
        $e = $this->parse_failure($body);
        $this->assertSame($code, $e->get_rpc_code());
        $this->assertSame(400, $e->get_http_status());
        $this->assertSame(get_string($identifier, 'local_placecom_mcp'), $e->get_rpc_message());
    }

    /**
     * Nested params are preserved.
     */
    public function test_nested_params(): void {
        $body = '{"jsonrpc":"2.0","method":"tools/call","id":2,"params":{"name":"test_function",' .
            '"arguments":{"param1":"value1","count":3,"nested":{"deep":true}}}}';
        $request = request::parse($body);
        $this->assertTrue($request->is_tool_call());
        $this->assertSame('test_function', $request->get_tool_name());
        $this->assertSame(
            ['param1' => 'value1', 'count' => 3, 'nested' => ['deep' => true]],
            $request->get_tool_arguments()
        );
    }

    /**
     * Tool name extraction failures.
     */
    public function test_tool_name_missing(): void {
        $request = request::parse('{"jsonrpc":"2.0","method":"tools/call","id":3,"params":{}}');
        try {
            $request->get_tool_name();
            $this->fail('Expected exception');
        } catch (jsonrpc_exception $e) {
            $this->assertSame(jsonrpc::INVALID_PARAMS, $e->get_rpc_code());
            $this->assertSame(get_string('error_toolnamemissing', 'local_placecom_mcp'), $e->get_rpc_message());
        }
    }

    /**
     * Arguments must be an object.
     */
    public function test_arguments_not_object(): void {
        $request = request::parse('{"jsonrpc":"2.0","method":"tools/call","id":3,"params":{"name":"x","arguments":[1]}}');
        $this->expectException(jsonrpc_exception::class);
        $request->get_tool_arguments();
    }

    /**
     * Empty argument objects give an empty array.
     */
    public function test_empty_arguments_object(): void {
        $request = request::parse('{"jsonrpc":"2.0","method":"tools/call","id":3,"params":{"name":"x","arguments":{}}}');
        $this->assertSame([], $request->get_tool_arguments());
    }

    /**
     * Messages with result or error and no method are client responses.
     */
    public function test_response_classification(): void {
        $request = request::parse('{"jsonrpc":"2.0","id":5,"result":{}}');
        $this->assertTrue($request->is_response());
        $this->assertFalse($request->is_notification());
        $this->assertNull($request->get_method());

        $request = request::parse('{"jsonrpc":"2.0","id":5,"error":{"code":1,"message":"x"}}');
        $this->assertTrue($request->is_response());
    }
}
