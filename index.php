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

require('../../config.php');

$id = required_param('id', PARAM_INT);    // ID курса.

require_login($id);

$cmid = optional_param('mod', 0, PARAM_INT); // ID модуля (quiz).
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

// Проверка прав доступа
require_capability('mod/quiz:grade', $context);

// Заголовки страницы
$PAGE->set_url('/local/quizessaygrader/index.php', ['id' => $id, 'mod' => $cmid, 'qid' => $qid]);
$PAGE->set_context($context);
if($cmid) {
    $PAGE->set_cm($cm);
}
$PAGE->set_title(get_string('pluginname', 'local_quizessaygrader'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'local_quizessaygrader'));

require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/lib/enrollib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/lib/gradelib.php');
require_once($CFG->dirroot . '/question/engine/lib.php');
require_once($CFG->dirroot . '/mod/quiz/classes/grade_calculator.php');
use mod_quiz\quiz_settings;

require_once($CFG->dirroot . '/local/quizessaygrader/lib.php');

$options = [
    'userid' => 0,
    'courseid' => $id,
    'quizid' => $qid,
    'verbose' => $verbose,
    'dryrun' => $dryrun,
    'maxusers' => 0,
];

$gradetype = get_config('local_quizessaygrader', 'gradetype');

$transaction = $DB->start_delegated_transaction();
$starttime = time();
$processedusers = 0;

log_message("Начало обработки в " . date('Y-m-d H:i:s') .
           ($options['dryrun'] ? " <font color=blue><b>[ ТЕСТОВЫЙ РЕЖИМ ]</b></font>" : ""), true);

// Получаем список курсов.
$courses = $DB->get_records_select(
    'course',
    $options['courseid'] > 0 ? 'id = ?' : '1=1',
    $options['courseid'] > 0 ? [$options['courseid']] : []
);

foreach ($courses as $course) {
    if ($options['maxusers'] > 0 && $processedusers >= $options['maxusers']) {
        break;
    }

    log_message("\nКурс: " . format_string($course->fullname) . " (ID: {$course->id})", true);

    // Получаем quiz в курсе.
    $quizzes = $DB->get_records_select(
        'quiz',
        $options['quizid'] > 0 ? 'course = ? AND id = ?' : 'course = ?',
        $options['quizid'] > 0 ? [$course->id, $options['quizid']] : [$course->id]
    );

    foreach ($quizzes as $quiz) {
        if ($options['maxusers'] > 0 && $processedusers >= $options['maxusers']) {
            break 2;
        }

        log_message("  Тест: {$quiz->name} (ID: {$quiz->id})", $options['verbose']);

        if (!quiz_has_essay_questions($quiz->id)) {
            continue;
        }

        // Получаем пользователей с попытками (упорядочиваем по attempt ASC - от старых к новым).
        $attempts = $DB->get_records_select(
            'quiz_attempts',
            $options['userid'] > 0 ? 'quiz = ? AND state = ? AND userid = ?' : 'quiz = ? AND state = ?',
            $options['userid'] > 0 ? [$quiz->id, 'finished', $options['userid']] : [$quiz->id, 'finished'],
            'userid, attempt ASC' // Сортируем по возрастанию номера попытки.
        );

        // Группируем попытки по пользователям (теперь первая попытка - самая ранняя).
        $usersattempts = [];
        foreach ($attempts as $attempt) {
            $usersattempts[$attempt->userid][] = $attempt;
        }

        foreach ($usersattempts as $userid => $userattempts) {
            if (!is_enrolled(context_course::instance($course->id), $userid)) {
                continue;
            }

            if ($options['maxusers'] > 0 && $processedusers >= $options['maxusers']) {
                break 3;
            }

            if (count($userattempts) < 2) {
                continue;
            }

            $user = $DB->get_record('user', ['id' => $userid], 'id, firstname, lastname');
            log_message("    Пользователь: {$user->firstname} {$user->lastname} (ID: {$user->id})", $options['verbose']);

            // Берем две последние попытки.
            $lastattempt = end($userattempts); // Самая новая попытка.
            prev($userattempts);
            $prevattempt = current($userattempts); // Предыдущая попытка.

            log_message("      Перенос оценок из попытки #{$prevattempt->attempt} в попытку #{$lastattempt->attempt}", $options['verbose']);

            try {
                $count = essaygrader_transfer_grades($prevattempt->id, $lastattempt->id, $options['verbose'], $options['dryrun'], $gradetype);
                if ($count > 0) {
                    $processedusers++;
                    log_message("      Успешно перенесено оценок: {$count}", $options['verbose']);
                }
            } catch (Exception $e) {
                log_message("      Ошибка: " . $e->getMessage(), true);
                continue;
            }
        }
    }
}

// Фиксируем изменения.
if (!$options['dryrun']) {
    $transaction->allow_commit();
    log_message("Изменения сохранены в базе данных", true);
} else {
    $DB->force_transaction_rollback();
    log_message("Транзакция отменена [тестовый режим]", true);
}

$totaltime = time() - $starttime;
log_message("\nОбработка завершена за {$totaltime} секунд", true);
log_message("Всего обработано пользователей: {$processedusers}", true);

echo '<hr>';

echo $OUTPUT->single_button($PAGE->url . '&dryrun=0&sesskey=' . sesskey(), get_string('apply'), 'get', ['type' => 'danger']);

if ($cmid) {
    $url = new moodle_url('/mod/quiz/report.php', ['id' => $cmid]);
    $text = get_string('back');
} else {
    $url = new moodle_url('/course/view.php', ['id' => $course->id]);
    $text = get_string('backtocourse', 'quiz');
}
echo $OUTPUT->single_button($url, $text, 'get', ['type' => 'primary']);

echo $OUTPUT->footer();
