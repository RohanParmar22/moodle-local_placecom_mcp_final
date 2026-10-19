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
 * JSON Web Key Set with the public keys used to sign ID tokens.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);

// phpcs:ignore moodle.Files.RequireLogin.Missing -- Public metadata document, no user context.
require(__DIR__ . '/../../config.php');

// Debug output must never corrupt the JSON document; debugging goes to the error log instead.
$CFG->debugdisplay = 0;

\local_placecom_mcp\local\oauth\metadata::respond(static function (): array {
    return \local_placecom_mcp\local\oauth\metadata::jwks();
})->send();
die();
