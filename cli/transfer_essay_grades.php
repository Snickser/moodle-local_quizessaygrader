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

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/../lib.php');

// Обработка параметров командной строки
[$options, $unrecognized] = cli_get_params([
    'help' => false,
    'source' => 0,
    'target' => 0,
    'verbose' => false,
    'dryrun' => false,
], [
    'h' => 'help',
    's' => 'source',
    't' => 'target',
    'v' => 'verbose',
    'd' => 'dryrun',
]);

if ($options['help'] || !$options['source'] || !$options['target']) {
    echo "Transfer essay grades between attempts

Options:
-h, --help        Print this help
-s, --source      Source attempt ID
-t, --target      Target attempt ID
-v, --verbose     Verbose output
-d, --dryrun      Dry run (no changes)

Example:
php transfer_essay_grades.php --source=123 --target=456
";
    exit(0);
}

try {
    $count = essaygrader_transfer_grades(
        $options['source'],
        $options['target'],
        $options['verbose'],
        $options['dryrun']
    );
    cli_writeln("Successfully transferred {$count} essay grades");
    exit(0);
} catch (Exception $e) {
    cli_error("Error: " . $e->getMessage());
}
