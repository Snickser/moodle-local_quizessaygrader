<?php
// Genarated by DeepSeek.

define('CLI_SCRIPT', true);
require_once(__DIR__.'/../../config.php');
require_once($CFG->dirroot.'/mod/quiz/locallib.php');
require_once($CFG->libdir.'/clilib.php');

require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/lib/gradelib.php');
require_once($CFG->dirroot . '/question/engine/lib.php');
require_once($CFG->dirroot . '/mod/quiz/classes/grade_calculator.php');
use mod_quiz\quiz_settings;

// Настройки обработки ошибок
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Обработка параметров командной строки
[$options, $unrecognized] = cli_get_params([
    'help' => false,
    'courseid' => 0,
    'quizid' => 0,
    'userid' => 0,
    'verbose' => false,
    'dryrun' => false,
    'maxusers' => 0
], [
    'h' => 'help',
    'c' => 'courseid',
    'q' => 'quizid',
    'u' => 'userid',
    'v' => 'verbose',
    'd' => 'dryrun',
    'm' => 'maxusers'
]);

if ($options['help']) {
    echo "Скрипт для переноса оценок эссе между попытками

Параметры:
-h, --help        Вывести эту справку
-c, --courseid    Обработать только указанный курс
-q, --quizid      Обработать только указанный quiz
-u, --userid      Обработать только указанного пользователя
-v, --verbose     Подробный вывод
-d, --dryrun      Тестовый режим (без изменений в БД)
-m, --maxusers    Максимальное количество пользователей для обработки

Примеры:
php transfer_essay_grades.php
php transfer_essay_grades.php --courseid=2 --verbose
php transfer_essay_grades.php --quizid=5 --dryrun --maxusers=100
";
    exit(0);
}

function log_message($message, $verbose = false, $force = false) {
    if ($verbose || $force) {
        cli_writeln($message);
    }
}

function quiz_has_essay_questions($quizid) {
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

try {
    $transaction = $DB->start_delegated_transaction();
    $starttime = time();
    $processed_users = 0;

    log_message("Начало обработки в " . date('Y-m-d H:i:s') . 
               ($options['dryrun'] ? " (ТЕСТОВЫЙ РЕЖИМ)" : ""), true);

    // Получаем список курсов
    $courses = $DB->get_records_select('course', 
        $options['courseid'] > 0 ? 'id = ?' : '1=1', 
        $options['courseid'] > 0 ? [$options['courseid']] : []
    );

    foreach ($courses as $course) {
        if ($options['maxusers'] > 0 && $processed_users >= $options['maxusers']) {
            break;
        }

        log_message("\nКурс: ".format_string($course->fullname)." (ID: {$course->id})", true);

        // Получаем quiz в курсе
        $quizzes = $DB->get_records_select('quiz', 
            $options['quizid'] > 0 ? 'course = ? AND id = ?' : 'course = ?', 
            $options['quizid'] > 0 ? [$course->id, $options['quizid']] : [$course->id]
        );

        foreach ($quizzes as $quiz) {
            if ($options['maxusers'] > 0 && $processed_users >= $options['maxusers']) {
                break 2;
            }

            log_message("  Тест: {$quiz->name} (ID: {$quiz->id})", $options['verbose']);

            if (!quiz_has_essay_questions($quiz->id)) {
                log_message("    Нет вопросов типа 'эссе'", $options['verbose']);
                continue;
            }

            log_message("    Найдены вопросы типа 'эссе'", $options['verbose']);

            // Получаем пользователей с попытками (упорядочиваем по attempt ASC - от старых к новым)
            $attempts = $DB->get_records_select('quiz_attempts', 
                $options['userid'] > 0 ? 'quiz = ? AND state = ? AND userid = ?' : 'quiz = ? AND state = ?',
                $options['userid'] > 0 ? [$quiz->id, 'finished', $options['userid']] : [$quiz->id, 'finished'],
                'userid, attempt ASC' // Сортируем по возрастанию номера попытки
            );

            // Группируем попытки по пользователям (теперь первая попытка - самая ранняя)
            $users_attempts = [];
            foreach ($attempts as $attempt) {
                $users_attempts[$attempt->userid][] = $attempt;
            }

            foreach ($users_attempts as $userid => $user_attempts) {
                if ($options['maxusers'] > 0 && $processed_users >= $options['maxusers']) {
                    break 3;
                }

                if (count($user_attempts) < 2) {
                    log_message("    У пользователя ID {$userid} меньше 2 попыток", $options['verbose']);
                    continue;
                }

                $user = $DB->get_record('user', ['id' => $userid], 'id, firstname, lastname');
                log_message("    Пользователь: {$user->firstname} {$user->lastname} (ID: {$user->id})", $options['verbose']);

                // Теперь:
                // $user_attempts[0] - самая ранняя попытка (первая)
                // $user_attempts[1] - следующая попытка
                // ...
                // end($user_attempts) - самая последняя попытка

                // Берем две последние попытки
                $last_attempt = end($user_attempts); // Самая новая попытка
                prev($user_attempts);
                $prev_attempt = current($user_attempts); // Предыдущая попытка

                log_message("      Перенос оценок из попытки #{$prev_attempt->attempt} в попытку #{$last_attempt->attempt}", $options['verbose']);

                try {
                    $count = transfer_essay_grades($prev_attempt->id, $last_attempt->id, $options['verbose'], $options['dryrun']);
                    if ($count > 0) {
                        $processed_users++;
                        log_message("      Успешно перенесено оценок: {$count}", $options['verbose']);
                    } else {
                        log_message("      Нет оценок для переноса", $options['verbose']);
                    }
                } catch (Exception $e) {
                    log_message("      Ошибка: " . $e->getMessage(), true);
                    continue;
                }
            }
        }
    }

    // Фиксируем изменения
    if (!$options['dryrun']) {
        $transaction->allow_commit();
        log_message("Изменения сохранены в базе данных", true);
    } else {
        $DB->force_transaction_rollback();
        log_message("Транзакция отменена (тестовый режим)", true);
    }

    $totaltime = time() - $starttime;
    log_message("\nОбработка завершена за {$totaltime} секунд", true);
    log_message("Всего обработано пользователей: {$processed_users}", true);

} catch (Exception $e) {
    $DB->force_transaction_rollback();
    log_message("Критическая ошибка: " . $e->getMessage(), true);
    exit(1);
}

/**
 * Переносит оценки за эссе между попытками с проверками:
 * - только оценки > 0
 * - только если в целевой попытке еще нет оценки
 * - с корректной обработкой шагов вопросов
 */
function transfer_essay_grades($source_attempt_id, $target_attempt_id, $verbose = false, $dryrun = false) {
    global $DB, $CFG;
    
    try {
        // Получаем данные о попытках
        $source_attempt = $DB->get_record('quiz_attempts', ['id' => $source_attempt_id], '*', MUST_EXIST);
        $target_attempt = $DB->get_record('quiz_attempts', ['id' => $target_attempt_id], '*', MUST_EXIST);
        $quiz = $DB->get_record('quiz', ['id' => $target_attempt->quiz], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, MUST_EXIST);

        // Загружаем usage для попыток
        $source_quba = question_engine::load_questions_usage_by_activity($source_attempt->uniqueid);
        $target_quba = question_engine::load_questions_usage_by_activity($target_attempt->uniqueid);
	
	// Загружаем все шаги пользователей для обеих попыток
	$source_quba->preload_all_step_users();
	$target_quba->preload_all_step_users();
	
        $count = 0;
        $total_essays = 0;
        $skipped_already_graded = 0;

        // Получаем слоты вопросов
        $slots = $DB->get_records('quiz_slots', ['quizid' => $quiz->id], 'slot');

        foreach ($slots as $slot) {
            try {
                $source_qa = $source_quba->get_question_attempt($slot->slot);
                $question = $source_qa->get_question();
                
                if ($question->get_type_name() == 'essay') {
                    $total_essays++;
                    
                    // Получаем оценку из исходной попытки
                    $grade = $source_qa->get_fraction();
                    $max_mark = $source_qa->get_max_mark();
                    $actual_grade = $grade * $max_mark;

                    // Проверяем оценку в целевой попытке
                    $target_qa = $target_quba->get_question_attempt($slot->slot);
                    $target_grade = $target_qa->get_fraction();
                    
                    // Условия пропуска:
                    if (is_null($grade) || $actual_grade <= 0) {
                        log_message("        Эссе (слот {$slot->slot}): оценка 0 (не переносится)", $verbose);
                        continue;
                    }
                    
                    if (!is_null($target_grade) && $target_grade) {
                        $skipped_already_graded++;
                        log_message("        Эссе (слот {$slot->slot}): пропущено (оценка уже существует)", $verbose);
                        continue;
                    }

                    // Получаем feedback из последнего шага
                    $feedback = '';
                    $last_step = $source_qa->get_last_step();
                    if ($last_step) {
                        $step_data = $last_step->get_all_data();
                        if (isset($step_data['-feedback'])) {
                            $feedback = $step_data['-feedback'];
                        } elseif (isset($step_data['-comment'])) {
                            $feedback = $step_data['-comment'];
                        }
                    }

                    if (!$dryrun) {
                        // Устанавливаем оценку через стандартный API
                        $target_qa->manual_grade(
                            $feedback,
                            $actual_grade,
                            FORMAT_HTML
                        );
                        
                        $count++;
                    }

                    log_message("        Эссе (слот {$slot->slot}): перенесено {$actual_grade}/{$max_mark}" . 
                              ($dryrun ? " (тестовый режим)" : ""), $verbose);
                }
            } catch (Exception $e) {
                log_message("        Ошибка слота {$slot->slot}: " . $e->getMessage(), true);
                continue;
            }
        }
	
        if (!$dryrun && $count > 0) {
            // Сохраняем изменения
            question_engine::save_questions_usage_by_activity($target_quba);

            // Обновляем статус попытки
	    $target_attempt->sumgrades = $target_quba->get_total_mark();
	    $target_attempt->timefinish = time();
            $DB->update_record('quiz_attempts', $target_attempt);
            
            // Правильное обновление итоговой оценки
	    $quizobj = new quiz_settings($quiz, $cm, $quiz->course);
	    $grade_calculator = $quizobj->get_grade_calculator();
	    $grade_calculator->recompute_final_grade($target_attempt->userid);

	}

        log_message("      Итого: вопросов эссе: {$total_essays}, " .
                  "перенесено: {$count}, " .
                  "пропущено (оценка существует): {$skipped_already_graded}" . 
                  ($dryrun ? " (тестовый режим)" : ""), true);

        return $count;

    } catch (Exception $e) {
        throw new moodle_exception('transferfailed', 'error', '', null, $e->getMessage());
    }
}
