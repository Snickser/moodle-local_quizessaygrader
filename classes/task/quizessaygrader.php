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

require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/lib/enrollib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/lib/gradelib.php');
require_once($CFG->dirroot . '/question/engine/lib.php');
require_once($CFG->dirroot . '/mod/quiz/classes/grade_calculator.php');
use mod_quiz\quiz_settings;

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
     */
    public function execute() {
        global $DB, $CFG;

        // Unfortunately this may take a long time, it should not be interrupted,
        // otherwise users get duplicate notification.
        \core_php_time_limit::raise();
        \raise_memory_limit(MEMORY_HUGE);

        $options = [
            'courseid' => 0,
            'quizid' => 0,
            'userid' => 0,
            'verbose' => get_config('local_quizessaygrader', 'verbose'),
            'dryrun' => get_config('local_quizessaygrader', 'dryrun'),
            'maxusers' => 0,
        ];

        $gradetype = get_config('local_quizessaygrader', 'gradetype');

        $transaction = $DB->start_delegated_transaction();
        $starttime = time();
        $processedusers = 0;

        self::log_message("Начало обработки в " . date('Y-m-d H:i:s') .
               ($options['dryrun'] ? " (ТЕСТОВЫЙ РЕЖИМ)" : ""), true);

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

            self::log_message("\nКурс: " . format_string($course->fullname) . " (ID: {$course->id})", true);

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

                self::log_message("  Тест: {$quiz->name} (ID: {$quiz->id})", $options['verbose']);

                if (!self::quiz_has_essay_questions($quiz->id)) {
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
                    if (!is_enrolled(\context_course::instance($course->id), $userid)) {
                        continue;
                    }

                    if ($options['maxusers'] > 0 && $processedusers >= $options['maxusers']) {
                        break 3;
                    }

                    if (count($userattempts) < 2) {
                        continue;
                    }

                    $user = $DB->get_record('user', ['id' => $userid], 'id, firstname, lastname');
                    self::log_message("    Пользователь: {$user->firstname} {$user->lastname} (ID: {$user->id})", $options['verbose']);

                    // Берем две последние попытки.
                    $lastattempt = end($userattempts); // Самая новая попытка.
                    prev($userattempts);
                    $prevattempt = current($userattempts); // Предыдущая попытка.

                    self::log_message("      Перенос оценок из попытки #{$prevattempt->attempt} в попытку #{$lastattempt->attempt}", $options['verbose']);

                    try {
                        $count = self::transfer_essay_grades($prevattempt->id, $lastattempt->id, $options['verbose'], $options['dryrun'], $gradetype);
                        if ($count > 0) {
                            $processedusers++;
                            self::log_message("      Успешно перенесено оценок: {$count}", $options['verbose']);
                        }
                    } catch (Exception $e) {
                        self::log_message("      Ошибка: " . $e->getMessage(), true);
                        continue;
                    }
                }
            }
        }

        // Фиксируем изменения.
        if (!$options['dryrun']) {
            $transaction->allow_commit();
            self::log_message("Изменения сохранены в базе данных", true);
        } else {
            $DB->force_transaction_rollback();
            self::log_message("Транзакция отменена (тестовый режим)", true);
        }

        $totaltime = time() - $starttime;
        self::log_message("\nОбработка завершена за {$totaltime} секунд", true);
        self::log_message("Всего обработано пользователей: {$processedusers}", true);
    }

    private function log_message($message, $verbose = false, $force = false) {
        if ($verbose || $force) {
            cli_writeln($message);
        }
    }

    private function quiz_has_essay_questions($quizid) {
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

    private function transfer_essay_grades($sourceattemptid, $targetattemptid, $verbose = false, $dryrun = false, $gradetype = 0) {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->libdir . '/gradelib.php');

        try {
            // Получаем данные о попытках.
            $sourceattempt = $DB->get_record('quiz_attempts', ['id' => $sourceattemptid], '*', MUST_EXIST);
            $targetattempt = $DB->get_record('quiz_attempts', ['id' => $targetattemptid], '*', MUST_EXIST);
            $quiz = $DB->get_record('quiz', ['id' => $targetattempt->quiz], '*', MUST_EXIST);
            $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, MUST_EXIST);

            // Загружаем usage для попыток.
            $sourcequba = \question_engine::load_questions_usage_by_activity($sourceattempt->uniqueid);
            $targetquba = \question_engine::load_questions_usage_by_activity($targetattempt->uniqueid);

            $count = 0;
            $totalessays = 0;
            $skippedalreadygraded = 0;

            // Получаем слоты вопросов.
            $slots = $DB->get_records('quiz_slots', ['quizid' => $quiz->id], 'slot');

            foreach ($slots as $slot) {
                try {
                    $sourceqa = $sourcequba->get_question_attempt($slot->slot);
                    $question = $sourceqa->get_question();

                    if ($question->get_type_name() == 'essay') {
                        $totalessays++;

                        // Получаем оценку из исходной попытки
                        $grade = $sourceqa->get_fraction();
                        $maxmark = $sourceqa->get_max_mark();
                        $actualgrade = $grade * $maxmark;

                        // Проверяем оценку в целевой попытке
                        $targetqa = $targetquba->get_question_attempt($slot->slot);
                        $targetgrade = $targetqa->get_fraction();

                        // Условия пропуска
                        $maxgrade = $maxmark;
                        if ($gradetype) {
                            $maxgrade = $actualgrade;
                        }
                        if (is_null($grade) || $actualgrade <= 0 || $actualgrade < $maxgrade) {
                            self::log_message("        Эссе (слот {$slot->slot}): оценка $actualgrade (не переносится)", $verbose);
                            continue;
                        }
                        if (!is_null($targetgrade) && $targetgrade) {
                            $skippedalreadygraded++;
                            self::log_message("        Эссе (слот {$slot->slot}): пропущено (оценка уже существует)", $verbose);
                            continue;
                        }

                        // Получаем feedback из последнего шага.
                        $feedback = '';
                        $laststep = $sourceqa->get_last_step();
                        if ($laststep) {
                            $stepdata = $laststep->get_all_data();
                            if (isset($stepdata['-feedback'])) {
                                $feedback = $stepdata['-feedback'];
                            } else if (isset($stepdata['-comment'])) {
                                $feedback = $stepdata['-comment'];
                            }
                        }

                        if (!$dryrun) {
                            // Устанавливаем оценку через стандартный API.
                            $targetqa->manual_grade($feedback, $actualgrade, FORMAT_HTML);
                            $count++;
                        }

                        self::log_message("        Эссе (слот {$slot->slot}): перенесено {$actualgrade}/{$maxmark}" .
                        ($dryrun ? " (тестовый режим)" : ""), $verbose);
                    }
                } catch (Exception $e) {
                    self::log_message("        Ошибка слота {$slot->slot}: " . $e->getMessage(), true);
                    continue;
                }
            }

            if (!$dryrun && $count > 0) {
                // Сохраняем изменения.
                question_engine::save_questions_usage_by_activity($targetquba);

                // Пересчитываем суммарную оценку и обновляем попытку.
                $targetattempt->sumgrades = $targetquba->get_total_mark();
                $targetattempt->state = 'finished';
                $targetattempt->timefinish = time();
                $DB->update_record('quiz_attempts', $targetattempt);
            }

            self::log_message("      Итого: вопросов эссе: {$totalessays}, " .
            "перенесено: {$count}, " .
            "пропущено (оценка существует): {$skippedalreadygraded}" .
            ($dryrun ? " (тестовый режим)" : ""), true);

            return $count;
        } catch (Exception $e) {
            throw new moodle_exception('transferfailed', 'error', '', null, $e->getMessage());
        }
    }
}
