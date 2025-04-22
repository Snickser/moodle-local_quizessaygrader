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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Library file for plugin 'local_quizessaygrader'.
 *
 * @package     local_quizessaygrader
 * @copyright   2025 Alex Orlov <snickser@gmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_quiz\quiz_settings;

/**
 * Extends the settings navigation with the quiz essay grader items.
 *
 * @param settings_navigation $settingsnav The settings navigation object.
 * @param context $context The current context.
 */
function local_quizessaygrader_extend_settings_navigation(settings_navigation $settingsnav, context $context) {
    global $PAGE;

    // Check if user has grading capabilities.
    if (!has_capability('mod/quiz:grade', $context) && !has_capability('mod/quiz:regrade', $context)) {
        return;
    }

    // Check if menu is enabled in plugin settings.
    if (!get_config('local_quizessaygrader', 'menu')) {
        return;
    }

    // Add to quiz module settings.
    if ($context->contextlevel == CONTEXT_MODULE && $PAGE->cm->modname === 'quiz') {
        // Find the parent section to add our item.
        $modulenode = $settingsnav->get('modulesettings');

        if ($modulenode) {
            $url = new moodle_url('/local/quizessaygrader/index.php', [
                'id' => $PAGE->cm->course,
                'mod' => $PAGE->cm->id,
                'qid' => $PAGE->cm->instance,
            ]);
            $name = get_string('pluginmenutitle', 'local_quizessaygrader');

            $modulenode->add($name, $url, navigation_node::TYPE_SETTING, null, 'local_quizessaygrader_menu');
        }
        // Add to course administration menu.
    } else if ($coursenode = $settingsnav->find('courseadmin', navigation_node::TYPE_COURSE)) {
        // Link to plugin script.
        $url = new moodle_url('/local/quizessaygrader/index.php', [
            'id' => $PAGE->course->id,
        ]);

        $coursenode->add(
            get_string('pluginmenutitle', 'local_quizessaygrader'),
            $url,
            navigation_node::TYPE_SETTING,
            null,
            'local_quizessaygrader_menu',
            new pix_icon('i/report', '')
        );
    }
}

/**
 * Outputs a log message either to CLI or HTML output.
 *
 * @param string $message The message to log.
 * @param bool $verbose Whether to output the message.
 * @param bool $force Whether to force output regardless of verbose setting.
 */
function local_quizessaygrader_log_message($message, $verbose = false, $force = false) {
    if ($verbose || $force) {
        if (defined('CLI_SCRIPT') && CLI_SCRIPT) {
            echo $message . "\n";
        } else {
            echo str_replace('  ', '&nbsp;', $message) . "<br>\n";
        }
    }
}

/**
 * Checks if a quiz contains any essay questions.
 *
 * @param int $quizid The ID of the quiz to check.
 * @return bool True if the quiz contains essay questions, false otherwise.
 */
function local_quizessaygrader_has_essay_questions($quizid) {
    global $DB;

    $sql = "SELECT COUNT(q.id)
            FROM {quiz_slots} qs
            JOIN {question_references} qr ON qr.itemid = qs.id
                AND qr.component = 'mod_quiz'
                AND qr.questionarea = 'slot'
            JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
            JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
            JOIN {question} q ON q.id = qv.questionid
            WHERE qs.quizid = ? AND q.qtype = 'essay'";

    return $DB->count_records_sql($sql, [$quizid]) > 0;
}

/**
 * Main function for grading essays in quizzes.
 *
 * @param int $courseid Optional course ID to limit processing.
 * @param int $quizid Optional quiz ID to limit processing.
 * @param int $userid Optional user ID to limit processing.
 * @param bool $verbose Whether to output verbose logging.
 * @param bool $dryrun Whether to perform a dry run without saving changes.
 * @return int Number of grades transferred.
 */
function local_quizessaygrader_run($courseid = 0, $quizid = 0, $userid = 0, $verbose = 0, $dryrun = 1) {
    global $DB, $CFG;

    require_once($CFG->dirroot . '/mod/quiz/locallib.php');
    require_once($CFG->libdir . '/clilib.php');
    require_once($CFG->dirroot . '/lib/enrollib.php');
    require_once($CFG->dirroot . '/mod/quiz/locallib.php');
    require_once($CFG->dirroot . '/lib/gradelib.php');
    require_once($CFG->dirroot . '/question/engine/lib.php');
    require_once($CFG->dirroot . '/mod/quiz/classes/grade_calculator.php');

    $options = [
        'userid' => $userid,
        'courseid' => $courseid,
        'quizid' => $quizid,
        'verbose' => get_config('local_quizessaygrader', 'verbose'),
        'dryrun' => $dryrun,
        'maxusers' => 0,
    ];

    $gradetype = get_config('local_quizessaygrader', 'gradetype');

    // Start database transaction.
    $transaction = $DB->start_delegated_transaction();
    $starttime = time();
    $processedusers = 0;

    local_quizessaygrader_log_message(get_string(
        'log_11',
        'local_quizessaygrader',
        ['time' => date('Y-m-d H:i:s'),
        'test' => ($options['dryrun'] ? get_string(
            'log_12',
            'local_quizessaygrader'
        ) : "")]
    ), $verbose);

    // Get the list of courses.
    $courses = $DB->get_records_select(
        'course',
        $options['courseid'] > 0 ? 'id = ?' : '1=1',
        $options['courseid'] > 0 ? [$options['courseid']] : []
    );

    foreach ($courses as $course) {
        // Check if we've reached max users limit.
        if ($options['maxusers'] > 0 && $processedusers >= $options['maxusers']) {
            break;
        }

        local_quizessaygrader_log_message(get_string(
            'log_10',
            'local_quizessaygrader',
            ['name' => format_string($course->fullname), 'id' => $course->id]
        ), $verbose);

        // Get quizzes in the course.
        $quizzes = $DB->get_records_select(
            'quiz',
            $options['quizid'] > 0 ? 'course = ? AND id = ?' : 'course = ?',
            $options['quizid'] > 0 ? [$course->id, $options['quizid']] : [$course->id]
        );

        foreach ($quizzes as $quiz) {
            if ($options['maxusers'] > 0 && $processedusers >= $options['maxusers']) {
                break 2;
            }

            local_quizessaygrader_log_message(get_string(
                'log_09',
                'local_quizessaygrader',
                ['name' => $quiz->name, 'id' => $quiz->id]
            ), $options['verbose']);

            // Skip if quiz has no essay questions.
            if (!local_quizessaygrader_has_essay_questions($quiz->id)) {
                continue;
            }

            // Get users with attempts (ordered by attempt ASC - from oldest to newest).
            $attempts = $DB->get_records_select(
                'quiz_attempts',
                $options['userid'] > 0 ? 'quiz = ? AND state = ? AND userid = ?' : 'quiz = ? AND state = ?',
                $options['userid'] > 0 ? [$quiz->id, 'finished', $options['userid']] : [$quiz->id, 'finished'],
                'userid, attempt ASC'
            );

            // Group attempts by users (now the first attempt is the earliest).
            $usersattempts = [];
            foreach ($attempts as $attempt) {
                    $usersattempts[$attempt->userid][] = $attempt;
            }

            foreach ($usersattempts as $userid => $userattempts) {
                // Skip if user is not enrolled in course.
                if (!is_enrolled(context_course::instance($course->id), $userid)) {
                    continue;
                }

                if ($options['maxusers'] > 0 && $processedusers >= $options['maxusers']) {
                    break 3;
                }

                // Skip if user has less than 2 attempts.
                if (count($userattempts) < 2) {
                    continue;
                }

                $user = $DB->get_record('user', ['id' => $userid], 'id, firstname, lastname');
                local_quizessaygrader_log_message(
                    get_string(
                        'log_08',
                        'local_quizessaygrader',
                        ['firstname' => $user->firstname, 'lastname' => $user->lastname, 'id' => $user->id]
                    ),
                    $options['verbose']
                );

                // Take the two most recent attempts.
                $lastattempt = end($userattempts); // The newest attempt.
                prev($userattempts);
                $prevattempt = current($userattempts); // The previous attempt.

                local_quizessaygrader_log_message(get_string(
                    'log_07',
                    'local_quizessaygrader',
                    ['prev' => $prevattempt->attempt, 'last' => $lastattempt->attempt]
                ), $options['verbose']);

                try {
                    $count = local_quizessaygrader_transfer_grades(
                        $prevattempt->id,
                        $lastattempt->id,
                        $options['verbose'],
                        $options['dryrun'],
                        $gradetype
                    );
                    if ($count > 0) {
                        $processedusers++;
                        local_quizessaygrader_log_message(
                            get_string('log_06', 'local_quizessaygrader', $count),
                            $options['verbose']
                        );
                    }
                } catch (Exception $e) {
                    local_quizessaygrader_log_message(get_string('log_05', 'local_quizessaygrader', $e->getMessage()), $verbose);
                    continue;
                }
            }
        }
    }

    // Commit or rollback changes based on dryrun setting.
    if (!$options['dryrun']) {
        $transaction->allow_commit();
        local_quizessaygrader_log_message(get_string('log_04', 'local_quizessaygrader'), $verbose);
    } else {
        $DB->force_transaction_rollback();
        local_quizessaygrader_log_message(get_string('log_03', 'local_quizessaygrader'), $verbose);
    }

    $totaltime = time() - $starttime;
    local_quizessaygrader_log_message(get_string('log_02', 'local_quizessaygrader', $totaltime), $verbose);
    local_quizessaygrader_log_message(get_string('log_01', 'local_quizessaygrader', $processedusers), $verbose);

    return $count;
}

/**
 * Transfers grades from one quiz attempt to another for essay questions.
 *
 * @param int $sourceattemptid The source attempt ID to copy grades from.
 * @param int $targetattemptid The target attempt ID to copy grades to.
 * @param bool $verbose Whether to output verbose logging.
 * @param bool $dryrun Whether to perform a dry run without saving changes.
 * @param int $gradetype The type of grading to apply.
 * @return int Number of grades transferred.
 * @throws moodle_exception If grade transfer fails.
 */
function local_quizessaygrader_transfer_grades($sourceattemptid, $targetattemptid, $verbose = false, $dryrun = false, $gradetype = 0) {
    global $DB, $CFG;
    require_once($CFG->dirroot . '/mod/quiz/locallib.php');
    require_once($CFG->libdir . '/gradelib.php');

    try {
        // Get attempt data.
        $sourceattempt = $DB->get_record('quiz_attempts', ['id' => $sourceattemptid], '*', MUST_EXIST);
        $targetattempt = $DB->get_record('quiz_attempts', ['id' => $targetattemptid], '*', MUST_EXIST);
        $quiz = $DB->get_record('quiz', ['id' => $targetattempt->quiz], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, MUST_EXIST);

        // Load question usage for attempts.
        $sourcequba = question_engine::load_questions_usage_by_activity($sourceattempt->uniqueid);
        $targetquba = question_engine::load_questions_usage_by_activity($targetattempt->uniqueid);

        $count = 0;
        $totalessays = 0;
        $skippedalreadygraded = 0;

        // Get question slots.
        $slots = $DB->get_records('quiz_slots', ['quizid' => $quiz->id], 'slot');

        foreach ($slots as $slot) {
            try {
                $sourceqa = $sourcequba->get_question_attempt($slot->slot);
                $question = $sourceqa->get_question();

                if ($question->get_type_name() == 'essay') {
                    $totalessays++;

                    // Get grade from source attempt.
                    $grade = $sourceqa->get_fraction();
                    $maxmark = $sourceqa->get_max_mark();
                    $actualgrade = $grade * $maxmark;

                    // Check grade in target attempt.
                    $targetqa = $targetquba->get_question_attempt($slot->slot);
                    $targetgrade = $targetqa->get_fraction();

                    // Skip conditions.
                    $maxgrade = $maxmark;
                    if ($gradetype) {
                        $maxgrade = $actualgrade;
                    }
                    if (is_null($grade) || $actualgrade <= 0 || $actualgrade < $maxgrade) {
                        local_quizessaygrader_log_message(get_string(
                            'log_18',
                            'local_quizessaygrader',
                            ['slot' => $slot->slot, 'grade' => $actualgrade]
                        ), $verbose);
                        continue;
                    }
                    if (!is_null($targetgrade) && $targetgrade) {
                        $skippedalreadygraded++;
                        local_quizessaygrader_log_message(get_string(
                            'log_17',
                            'local_quizessaygrader',
                            $slot->slot
                        ), $verbose);
                        continue;
                    }

                    // Get feedback from last step.
                    $feedback = 'auto';
                    $laststep = $sourceqa->get_last_step();
                    if ($laststep->has_behaviour_var('comment')) {
                        $text = $laststep->get_behaviour_var('comment');
                        if (strlen($text)) {
                            $feedback = $text;
                        }
                    } else if ($laststep->has_behaviour_var('feedback')) {
                        $feedback = $laststep->get_behaviour_var('feedback');
                    }

                    if (!$dryrun) {
                        // Set grade using standard API.
                        $targetqa->manual_grade($feedback, $actualgrade, FORMAT_HTML);
                        $count++;
                    }

                    local_quizessaygrader_log_message(get_string(
                        'log_16',
                        'local_quizessaygrader',
                        ['slot' => $slot->slot, 'grade' => $actualgrade, 'max' => $maxmark,
                        'test' => ($dryrun ? get_string(
                            'log_14',
                            'local_quizessaygrader'
                        ) : "")]
                    ), $verbose);
                }
            } catch (Exception $e) {
                local_quizessaygrader_log_message(get_string(
                    'log_15',
                    'local_quizessaygrader',
                    ['slot' => $slot->slot, 'error' => $e->getMessage()]
                ), $verbose);
                continue;
            }
        }

        if (!$dryrun && $count > 0) {
            // Save changes.
            question_engine::save_questions_usage_by_activity($targetquba);

            // Recalculate total grade.
            $targetattempt->sumgrades = $targetquba->get_total_mark();
            $DB->update_record('quiz_attempts', $targetattempt);
        }

        local_quizessaygrader_log_message(get_string(
            'log_13',
            'local_quizessaygrader',
            ['total' => $totalessays, 'count' => $count, 'skip' => $skippedalreadygraded,
            'test' => ($dryrun ? get_string(
                'log_14',
                'local_quizessaygrader'
            ) : "")]
        ), $verbose);

        return $count;
    } catch (Exception $e) {
        throw new moodle_exception('transferfailed', 'local_quizessaygrader', '', null, $e->getMessage());
    }
}

/**
 * Event observer for quiz attempt submissions.
 *
 * @param \mod_quiz\event\attempt_submitted $event The quiz attempt submitted event.
 * @return int Number of grades transferred.
 */
function local_quizessaygrader_attempt_submitted(\mod_quiz\event\attempt_submitted $event) {
    global $DB, $CFG;

    // Get event data.
    $eventdata = $event->get_data();

    // Execute if event processing is enabled.
    if (get_config('local_quizessaygrader', 'event')) {
        $count = local_quizessaygrader_run($eventdata['courseid'], $eventdata['other']['quizid'], $eventdata['userid'], false, false);
    }

    return $count;
}
