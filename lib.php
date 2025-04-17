<?php
// This file is part of the bank paymnts module for Moodle - http://moodle.org/
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

defined('MOODLE_INTERNAL') || die();

function local_quizessaygrader_extend_settings_navigation(settings_navigation $settingsnav, context $context) {
    global $PAGE;

    if (!has_capability('mod/quiz:grade', $context)) {
        return;
    }

    if ($context->contextlevel == CONTEXT_MODULE && $PAGE->cm->modname === 'quiz') {
        // Найдём родительский раздел, куда добавить пункт
        $modulenode = $settingsnav->get('modulesettings');

        if ($modulenode) {
            $url = new moodle_url('/local/quizessaygrader/index.php', [
                'id' => $PAGE->cm->course,
                'mod' => $PAGE->cm->id,
                'qid' => $PAGE->cm->instance,
            ]);

            $name = get_string('pluginmenutitle', 'local_quizessaygrader');

            $modulenode->add($name, $url, navigation_node::TYPE_SETTING, null, 'quizessaygrader');
        }
    }
    if ($coursenode = $settingsnav->find('courseadmin', navigation_node::TYPE_COURSE)) {
            // Ссылка на скрипт плагина
        $url = new moodle_url('/local/quizessaygrader/index.php', [
                'id' => $PAGE->course->id,

        ]);

                 // Добавление пункта меню
                         $coursenode->add(
                             get_string('pluginmenutitle', 'local_quizessaygrader'),
                             $url,
                             navigation_node::TYPE_SETTING,
                             null,
                             'local_quizessaygrader_menu',
                             new pix_icon('i/report', '') // можно заменить иконку при желании
                         );
    }
}

function log_message($message, $verbose = false, $force = false) {
    if ($verbose || $force) {
        echo str_replace('  ', '&nbsp;', $message) . '<br>';
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

function essaygrader_transfer_grades($sourceattemptid, $targetattemptid, $verbose = false, $dryrun = false, $gradetype = 0) {
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
        $sourcequba = question_engine::load_questions_usage_by_activity($sourceattempt->uniqueid);
        $targetquba = question_engine::load_questions_usage_by_activity($targetattempt->uniqueid);

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
                        log_message("        Эссе (слот {$slot->slot}): оценка $actualgrade (не переносится)", $verbose);
                        continue;
                    }
                    if (!is_null($targetgrade) && $targetgrade) {
                        $skippedalreadygraded++;
                        log_message("        Эссе (слот {$slot->slot}): пропущено (оценка уже существует)", $verbose);
                        continue;
                    }

                    if (!$dryrun) {
                        // Устанавливаем оценку через стандартный API.
                        $feedback = 'auto';
                        $targetqa->manual_grade($feedback, $actualgrade, FORMAT_HTML);
                        $count++;
                    }

                    log_message("        <b>Эссе (слот {$slot->slot}): перенесено {$actualgrade}/{$maxmark}</b>" .
                        ($dryrun ? " [тестовый режим]" : ""), $verbose);
                }
            } catch (Exception $e) {
                log_message("        Ошибка слота {$slot->slot}: " . $e->getMessage(), true);
                continue;
            }
        }

        if (!$dryrun && $count > 0) {
            // Сохраняем изменения.
            question_engine::save_questions_usage_by_activity($targetquba);

            // Пересчитываем суммарную оценку и обновляем попытку.
            $targetattempt->sumgrades = $targetquba->get_total_mark();
            $DB->update_record('quiz_attempts', $targetattempt);
        }

        log_message("      Итого: вопросов эссе: {$totalessays}, " .
            "перенесено: {$count}, " .
            "пропущено (оценка существует): {$skippedalreadygraded}" .
            ($dryrun ? " [тестовый режим]" : ""), true);

        return $count;
    } catch (Exception $e) {
        throw new moodle_exception('transferfailed', 'error', '', null, $e->getMessage());
    }
}
