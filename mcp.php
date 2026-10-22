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
 * MCP endpoint (Streamable HTTP, JSON-RPC 2.0 over POST).
 *
 * Authentication is by OAuth bearer access token issued by this plugin; no Moodle session is used.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Moodle defines NO_MOODLE_COOKIES itself for web service requests (lib/setup.php).
define('WS_SERVER', true);

// phpcs:ignore moodle.Files.RequireLogin.Missing -- Bearer token endpoint; the MCP server authenticates every request.
require(__DIR__ . '/../../config.php');

// Debug output would corrupt the JSON responses.
$CFG->debugdisplay = 0;
ini_set('display_errors', '0');

$body = file_get_contents('php://input', false, null, 0, \local_placecom_mcp\local\mcp\server::MAX_BODY + 1);
$headers = function_exists('getallheaders') ? getallheaders() : null;

$server = new \local_placecom_mcp\local\mcp\server();
$server->handle(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    $_SERVER,
    is_array($headers) ? $headers : null,
    $body === false ? '' : $body
)->send();
die();
