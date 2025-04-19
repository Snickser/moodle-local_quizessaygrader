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
 * Scheduled task for automatically grading quiz essay questions.
 *
 * This task handles the automatic processing and grading of essay questions in quizzes
 * according to configured rules and criteria.
 *
 * @package   local_quizessaygrader
 * @copyright 2025 Alex Orlov <snickser@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quizessaygrader extends \core\task\scheduled_task {
    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string The name of this task
     */
    public function get_name() {
        return get_string('pluginname', 'local_quizessaygrader');
    }

    /**
     * Execute the task to automatically grade quiz essay questions.
     *
     * This function:
     * 1. Sets up the execution environment with appropriate time and memory limits
     * 2. Retrieves configuration settings for verbosity and dry-run mode
     * 3. Calls the main essay grading function
     * 4. Outputs processing statistics
     *
     * @return bool Always returns true unless there is a fatal error
     * @throws \moodle_exception If there are problems executing the task
     */
    public function execute() {
        global $CFG;
        require_once($CFG->dirroot . '/local/quizessaygrader/lib.php');

        // Prevent timeouts and memory issues during processing.
        \core_php_time_limit::raise();
        \raise_memory_limit(MEMORY_HUGE);

        // Get plugin configuration settings.
        $verbose = get_config('local_quizessaygrader', 'verbose');
        $dryrun = get_config('local_quizessaygrader', 'dryrun');

        // Process essay questions and get count of processed items.
        $count = essaygrader(0, 0, 0, $verbose, $dryrun);

        // Output processing statistics.
        mtrace("Processed: $count");

        return true;
    }
}
