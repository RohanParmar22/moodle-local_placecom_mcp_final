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
 * Conversion of Moodle external descriptions to JSON Schema.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\mcp;

use core_external\external_description;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Turns the parameter and return descriptions of external functions into JSON Schema documents
 * for the MCP inputSchema and outputSchema members.
 *
 * Requiredness is passed up the recursion as a separate value, so no bookkeeping key can ever leak
 * into the produced schema.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class schema_converter {
    /**
     * Convert a description to a JSON Schema.
     *
     * @param external_description|null $desc Description, or null for "no parameters / no return value".
     * @return array JSON Schema as an array (empty objects are \stdClass so they encode as {}).
     */
    public static function convert(?external_description $desc): array {
        if ($desc === null) {
            return ['type' => 'object', 'properties' => new \stdClass()];
        }
        [$schema] = self::convert_node($desc);
        return $schema;
    }

    /**
     * Wrap the return description in the object schema used for structuredContent.
     *
     * @param external_description|null $returns Return description, or null when the function returns raw data.
     * @return array
     */
    public static function output_schema(?external_description $returns): array {
        $result = $returns === null ? new \stdClass() : self::convert($returns);
        return [
            'type' => 'object',
            'properties' => ['result' => $result],
            'required' => ['result'],
        ];
    }

    /**
     * Convert one node.
     *
     * @param external_description $desc Node to convert.
     * @return array Two members: the schema (array) and whether the node is required in its parent (bool).
     */
    protected static function convert_node(external_description $desc): array {
        $required = ($desc->required === VALUE_REQUIRED);

        if ($desc instanceof external_value) {
            return [self::convert_value($desc), $required];
        }

        if ($desc instanceof external_single_structure) {
            $schema = ['type' => 'object'];
            if ((string) $desc->desc !== '') {
                $schema['description'] = (string) $desc->desc;
            }
            $properties = [];
            $requiredkeys = [];
            foreach ($desc->keys as $key => $child) {
                if (!($child instanceof external_description)) {
                    continue;
                }
                [$childschema, $childrequired] = self::convert_node($child);
                $properties[(string) $key] = $childschema;
                if ($childrequired) {
                    $requiredkeys[] = (string) $key;
                }
            }
            $schema['properties'] = $properties ? $properties : new \stdClass();
            if ($requiredkeys) {
                $schema['required'] = $requiredkeys;
            }
            return [$schema, $required];
        }

        if ($desc instanceof external_multiple_structure) {
            [$items] = self::convert_node($desc->content);
            $schema = ['type' => 'array', 'items' => $items];
            if ((string) $desc->desc !== '') {
                $schema['description'] = (string) $desc->desc;
            }
            return [$schema, $required];
        }

        // Unknown description class: describe it loosely instead of failing the whole listing.
        $schema = [];
        if ((string) $desc->desc !== '') {
            $schema['description'] = (string) $desc->desc;
        }
        return [$schema, $required];
    }

    /**
     * Convert a scalar value description.
     *
     * @param external_value $desc Scalar description.
     * @return array
     */
    protected static function convert_value(external_value $desc): array {
        $type = self::json_type((string) $desc->type);
        $schema = ['type' => $type];
        if ((string) $desc->desc !== '') {
            $schema['description'] = (string) $desc->desc;
        }
        if ($desc->required === VALUE_DEFAULT && $desc->default !== null) {
            $schema['default'] = self::cast_default($type, $desc->default);
        }
        if ($desc->allownull === NULL_ALLOWED) {
            $schema['type'] = [$type, 'null'];
        }
        return $schema;
    }

    /**
     * Map a Moodle PARAM_* type to a JSON Schema type.
     *
     * @param string $paramtype Moodle parameter type.
     * @return string
     */
    public static function json_type(string $paramtype): string {
        switch ($paramtype) {
            case PARAM_INT:
                return 'integer';
            case PARAM_FLOAT:
                return 'number';
            case PARAM_BOOL:
                return 'boolean';
            default:
                return 'string';
        }
    }

    /**
     * Cast a default value to the JSON type of its schema where that is unambiguous.
     *
     * @param string $type JSON Schema type.
     * @param mixed $value Default from the description.
     * @return mixed
     */
    protected static function cast_default(string $type, $value) {
        if (!is_scalar($value)) {
            return $value;
        }
        switch ($type) {
            case 'integer':
                return is_numeric($value) ? (int) $value : $value;
            case 'number':
                return is_numeric($value) ? (float) $value : $value;
            case 'boolean':
                return (bool) $value;
            default:
                return (string) $value;
        }
    }
}
