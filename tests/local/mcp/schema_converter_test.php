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
 * Tests for the JSON Schema converter.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\mcp;

use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

#[\PHPUnit\Framework\Attributes\CoversClass(schema_converter::class)]
/**
 * Tests for {@see schema_converter}.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_placecom_mcp\local\mcp\schema_converter
 */
final class schema_converter_test extends \advanced_testcase {
    /**
     * Optional nullable text keeps its description and allows null.
     */
    public function test_nullable_text(): void {
        $schema = schema_converter::convert(new external_value(PARAM_TEXT, 'A note', VALUE_OPTIONAL, null, NULL_ALLOWED));
        $this->assertSame(['string', 'null'], $schema['type']);
        $this->assertSame('A note', $schema['description']);

        $schema = schema_converter::convert(new external_value(PARAM_TEXT, 'A note', VALUE_OPTIONAL, null, NULL_NOT_ALLOWED));
        $this->assertSame('string', $schema['type']);
    }

    /**
     * Data provider for scalar type mapping.
     *
     * @return array
     */
    public static function type_provider(): array {
        return [
            'text' => [PARAM_TEXT, 'string'],
            'raw' => [PARAM_RAW, 'string'],
            'int' => [PARAM_INT, 'integer'],
            'float' => [PARAM_FLOAT, 'number'],
            'bool' => [PARAM_BOOL, 'boolean'],
            'alphanumext' => [PARAM_ALPHANUMEXT, 'string'],
        ];
    }

    /**
     * Scalar types map to JSON types.
     *
     * @dataProvider type_provider
     * @param string $paramtype Moodle type.
     * @param string $expected JSON type.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('type_provider')]
    public function test_type_mapping(string $paramtype, string $expected): void {
        $schema = schema_converter::convert(new external_value($paramtype, '', VALUE_REQUIRED, null, NULL_NOT_ALLOWED));
        $this->assertSame($expected, $schema['type']);
    }

    /**
     * Object structures list exactly the required keys.
     */
    public function test_object_required_keys(): void {
        $desc = new external_single_structure([
            'name' => new external_value(PARAM_TEXT, 'Name', VALUE_REQUIRED),
            'age' => new external_value(PARAM_INT, 'Age', VALUE_OPTIONAL),
            'active' => new external_value(PARAM_BOOL, 'Active', VALUE_REQUIRED),
        ]);
        $schema = schema_converter::convert($desc);
        $this->assertSame('object', $schema['type']);
        $this->assertSame(['name', 'active'], $schema['required']);
        $this->assertSame(['string', 'null'], $schema['properties']['name']['type']);
        $this->assertSame(['integer', 'null'], $schema['properties']['age']['type']);
        $this->assertSame(['boolean', 'null'], $schema['properties']['active']['type']);
    }

    /**
     * Arrays of scalars.
     */
    public function test_array_of_text(): void {
        $schema = schema_converter::convert(new external_multiple_structure(new external_value(PARAM_TEXT, 'Tag')));
        $this->assertSame('array', $schema['type']);
        $this->assertSame('Tag', $schema['items']['description']);
        $this->assertContains('string', (array) $schema['items']['type']);
    }

    /**
     * Nested objects and arrays.
     */
    public function test_nested(): void {
        $desc = new external_single_structure([
            'user' => new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Id'),
                'name' => new external_value(PARAM_TEXT, 'Name'),
            ]),
            'courses' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Id'),
                'title' => new external_value(PARAM_TEXT, 'Title', VALUE_OPTIONAL),
            ]), 'Courses', VALUE_OPTIONAL),
        ]);
        $schema = schema_converter::convert($desc);
        $this->assertSame(['user'], $schema['required']);
        $this->assertSame(['id', 'name'], $schema['properties']['user']['required']);
        $this->assertSame('array', $schema['properties']['courses']['type']);
        $this->assertSame('Courses', $schema['properties']['courses']['description']);
        $this->assertArrayHasKey('title', $schema['properties']['courses']['items']['properties']);
        $this->assertSame(['id'], $schema['properties']['courses']['items']['required']);
        $this->assert_no_marker_keys($schema);
    }

    /**
     * Missing descriptions become an empty object schema that encodes as {}.
     */
    public function test_null_description(): void {
        $schema = schema_converter::convert(null);
        $this->assertSame('{"type":"object","properties":{}}', json_encode($schema));
        $schema = schema_converter::convert(new external_function_parameters([]));
        $this->assertSame('{"type":"object","properties":{}}', json_encode($schema));
    }

    /**
     * Defaults are only emitted for VALUE_DEFAULT with a non-null default.
     */
    public function test_defaults(): void {
        $schema = schema_converter::convert(new external_value(PARAM_INT, '', VALUE_DEFAULT, 5));
        $this->assertSame(5, $schema['default']);
        $schema = schema_converter::convert(new external_value(PARAM_INT, '', VALUE_DEFAULT, null));
        $this->assertArrayNotHasKey('default', $schema);
        $schema = schema_converter::convert(new external_value(PARAM_INT, '', VALUE_OPTIONAL, 5));
        $this->assertArrayNotHasKey('default', $schema);
        $schema = schema_converter::convert(new external_value(PARAM_BOOL, '', VALUE_DEFAULT, 1));
        $this->assertTrue($schema['default']);
    }

    /**
     * The output schema wraps the return schema in a result property.
     */
    public function test_output_schema(): void {
        $schema = schema_converter::output_schema(new external_value(PARAM_INT, 'Count', VALUE_REQUIRED, null, NULL_NOT_ALLOWED));
        $this->assertSame('object', $schema['type']);
        $this->assertSame(['result'], $schema['required']);
        $this->assertSame('integer', $schema['properties']['result']['type']);

        $schema = schema_converter::output_schema(null);
        $this->assertSame('{"type":"object","properties":{"result":{}},"required":["result"]}', json_encode($schema));
    }

    /**
     * Every allowlisted function converts without internal markers.
     */
    public function test_real_functions_have_no_markers(): void {
        foreach (allowlist::names() as $name) {
            $info = tool_registry::function_info($name);
            $this->assertNotNull($info, $name);
            $this->assert_no_marker_keys(schema_converter::convert($info->parameters_desc));
            $this->assert_no_marker_keys(schema_converter::output_schema($info->returns_desc));
        }
    }

    /**
     * Assert that a schema contains only JSON Schema keywords at every level.
     *
     * @param mixed $schema Schema node.
     */
    private function assert_no_marker_keys($schema): void {
        $allowed = ['type', 'description', 'properties', 'required', 'items', 'default'];
        if ($schema instanceof \stdClass) {
            $schema = (array) $schema;
            foreach ($schema as $child) {
                $this->assert_no_marker_keys($child);
            }
            return;
        }
        if (!is_array($schema)) {
            return;
        }
        foreach ($schema as $key => $value) {
            if ($key === 'properties') {
                foreach ((array) $value as $child) {
                    $this->assert_no_marker_keys($child);
                }
                continue;
            }
            if (is_string($key)) {
                $this->assertContains($key, $allowed);
            }
            if ($key === 'items') {
                $this->assert_no_marker_keys($value);
            }
        }
    }
}
