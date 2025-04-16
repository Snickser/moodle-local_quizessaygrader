<?php
define('CLI_SCRIPT', true);

require(__DIR__.'/../../../config.php');
require_once($CFG->libdir.'/clilib.php');
require_once(__DIR__.'/../lib.php');

// Обработка параметров командной строки
list($options, $unrecognized) = cli_get_params([
    'help' => false,
    'source' => 0,
    'target' => 0,
    'verbose' => false,
    'dryrun' => false
], [
    'h' => 'help',
    's' => 'source',
    't' => 'target',
    'v' => 'verbose',
    'd' => 'dryrun'
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
