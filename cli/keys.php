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
 * CLI tool to list, rotate and delete the ID token signing keys.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_placecom_mcp\local\oauth\key_manager;

$component = 'local_placecom_mcp';

[$options, $unrecognised] = cli_get_params(
    ['help' => false, 'list' => false, 'rotate' => false, 'delete' => '', 'force' => false],
    ['h' => 'help']
);

if ($unrecognised) {
    cli_error(get_string('cli_unknownoption', $component, implode(PHP_EOL . '  ', $unrecognised)));
}

$keys = new key_manager();

if (!empty($options['list'])) {
    $records = $keys->list_keys();
    if (empty($records)) {
        cli_writeln(get_string('cli_nokeys', $component));
        exit(0);
    }
    foreach ($records as $record) {
        $status = !empty($record->active) ? 'active' : 'retired';
        $retired = !empty($record->timeretired) ? userdate($record->timeretired) : '-';
        cli_writeln(implode("\t", [$record->kid, $status, userdate($record->timecreated), $retired]));
    }
    exit(0);
}

if (!empty($options['rotate'])) {
    $active = array_filter($keys->list_keys(), static function ($record): bool {
        return !empty($record->active);
    });
    if ($active && empty($options['force'])) {
        cli_error(get_string('cli_keyexists', $component));
    }
    try {
        $record = $keys->rotate();
    } catch (moodle_exception $e) {
        cli_problem($e->getMessage());
        if (!empty($e->debuginfo)) {
            cli_problem($e->debuginfo);
        }
        cli_error(get_string('cli_opensslhint', $component, (string) ($CFG->opensslcnf ?? '')));
    }
    $preview = substr(str_replace(["\r", "\n"], '', $record->publickey), 0, 60);
    cli_writeln(get_string('cli_keygenerated', $component, (object) [
        'kid' => $record->kid,
        'algorithm' => key_manager::ALGORITHM,
        'bits' => key_manager::KEY_BITS,
        'preview' => $preview,
    ]));
    cli_writeln(get_string('cli_rotatenote', $component));
    exit(0);
}

if ($options['delete'] !== '' && $options['delete'] !== false && $options['delete'] !== true) {
    $kid = (string) $options['delete'];
    try {
        $deleted = $keys->delete_key($kid);
    } catch (moodle_exception $e) {
        cli_error($e->getMessage());
    }
    if (!$deleted) {
        cli_error(get_string('cli_keynotfound', $component, $kid));
    }
    cli_writeln(get_string('cli_keydeleted', $component, $kid));
    exit(0);
}

echo get_string('cli_help', $component) . PHP_EOL;
exit(0);
