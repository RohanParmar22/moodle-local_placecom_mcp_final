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
 * OAuth 2.0 token endpoint (authorization code and refresh token grants).
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);

// phpcs:ignore moodle.Files.RequireLogin.Missing -- Clients authenticate with their own credentials.
require(__DIR__ . '/../../config.php');

// Debug output must never corrupt the JSON response; debugging goes to the error log instead.
$CFG->debugdisplay = 0;

$controller = new \local_placecom_mcp\local\oauth\token_controller();
$controller->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER, $_POST)->send();
die();
