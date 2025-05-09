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
 * Index file for plugin 'local_quizessaygrader'.
 *
 * @package     local_quizessaygrader
 * @copyright   2025 Alex Orlov <snickser@gmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');

$id = required_param('id', PARAM_INT);    // ID course.

require_login($id);

$cmid = optional_param('mod', 0, PARAM_INT); // ID modules (quiz).
$qid = optional_param('qid', 0, PARAM_INT); // ID quiz.

$verbose = optional_param('verbose', 1, PARAM_INT);
$dryrun = optional_param('dryrun', 1, PARAM_INT);

if (!$dryrun) {
    require_sesskey();
}

$course = get_course($id);
if ($cmid) {
    $cm = get_coursemodule_from_id('quiz', $cmid, $id, false, MUST_EXIST);
    $context = context_module::instance($cm->id);
} else {
    $context = context_course::instance($id);
}

// Check permission.
require_capability('mod/quiz:grade', $context);

// Set page header.
$PAGE->set_url('/local/quizessaygrader/index.php', ['id' => $id, 'mod' => $cmid, 'qid' => $qid]);
$PAGE->set_context($context);
if ($cmid) {
    $PAGE->set_cm($cm);
}
$PAGE->set_title(get_string('pluginname', 'local_quizessaygrader'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'local_quizessaygrader'));

// Execute.
local_quizessaygrader_run($id, $qid, 0, $verbose, $dryrun);

echo '<hr>';

echo $OUTPUT->single_button($PAGE->url . '&dryrun=0&sesskey=' . sesskey(), get_string('apply'), 'get', ['type' => 'danger']);

if ($cmid) {
    $url = new moodle_url('/mod/quiz/report.php', ['id' => $cmid]);
} else {
    $url = new moodle_url('/course/view.php', ['id' => $course->id]);
}
echo $OUTPUT->single_button($url, get_string('back'), 'get', ['type' => 'primary']);

echo $OUTPUT->footer();
