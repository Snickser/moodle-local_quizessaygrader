<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_quizessaygrader\task;

/**
 * Send expiry notifications task.
 *
 * @package   local_quizessaygrader
 * @copyright 2025 Alex Orlov <snickser@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

class quizessaygrader extends \core\task\scheduled_task {
    /**
     * Name for this task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('pluginname', 'local_quizessaygrader');
    }

    /**
     * Run task for autocommits.
     *
     * @return string
     */
    public function execute() {
        global $CFG;
        require_once($CFG->dirroot . '/local/quizessaygrader/lib.php');

        // Unfortunately this may take a long time, it should not be interrupted,
        // otherwise users get duplicate notification.
        \core_php_time_limit::raise();
        \raise_memory_limit(MEMORY_HUGE);

        $verbose = get_config('local_quizessaygrader', 'verbose');
        $dryrun  = get_config('local_quizessaygrader', 'dryrun');

        $count = essaygrader(0, 0, 0, $verbose, $dryrun);

        mtrace("Processed: $count");

        return true;
    }
}
