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
 * Strings for component 'local_quizessaygrader', language 'en'.
 *
 * @package   local_quizessaygrader
 * @copyright 2025 Alex Orlov <snickser@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['donate'] = '<div>Plugin version: {$a->release} ({$a->versiondisk})<br>
You can find new versions of the plugin at <a href=https://github.com/Snickser/moodle-local_quizessaygrader>GitHub.com</a>
<img src="https://img.shields.io/github/v/release/Snickser/moodle-local_quizessaygrader.svg"><br>
Please send me some <a href="https://yoomoney.ru/fundraise/143H2JO3LLE.240720">donate</a>😊</div>
BTC 1GFTTPCgRTC8yYL1gU7wBZRfhRNRBdLZsq<br>
TRX TRGMc3b63Lus6ehLasbbHxsb2rHky5LbPe<br>
ETH 0x1bce7aadef39d328d262569e6194febe597cb2c9<br>
<iframe src="https://yoomoney.ru/quickpay/fundraise/button?billNumber=143H2JO3LLE.240720"
width="330" height="50" frameborder="0" allowtransparency="true" scrolling="no"></iframe>';
$string['dryrun'] = 'Test mode';
$string['dryrun_desc'] = 'Affects the scheduled task, in this mode only a report is displayed (if enabled), no grade transfer is performed.';
$string['eventmode'] = 'Enable event mode';
$string['eventmode_desc'] = 'In this mode, grades are processed immediately after the student submits their work. Preferred mode.';
$string['gradetype'] = 'Grade type';
$string['gradetype_desc'] = 'Specifies which grade to copy, only the maximum grade or any grade greater than zero.';
$string['log_01'] = 'Total users processed: {$a}';
$string['log_02'] = 'Processing completed in {$a} seconds';
$string['log_03'] = 'Transaction rolled back [test mode]';
$string['log_04'] = 'Changes saved to database';
$string['log_05'] = '      Error: {$a}';
$string['log_06'] = '      Successfully transferred grades: {$a}';
$string['log_07'] = '      Transferring grades from attempt {$a->prev} to attempt {$a->last}';
$string['log_08'] = '    User: {$a->firstname} {$a->lastname} (ID: {$a->id})';
$string['log_09'] = '  Quiz: {$a->name} (ID: {$a->id})';
$string['log_10'] = 'Course: {$a->name} (ID: {$a->id})';
$string['log_11'] = 'Processing started at {$a->time}{$a->test}';
$string['log_12'] = ' <font color=blue><b>[ TEST MODE ]</b></font>';
$string['log_13'] = '      Summary: essay questions: {$a->total}, transferred: {$a->count}, skipped (grade exists): {$a->skip}{$a->test}';
$string['log_14'] = ' [test mode]';
$string['log_15'] = '        Slot {$a->slot} error: {$a->error}';
$string['log_16'] = '        <b>Essay (slot {$a->slot}): transferred {$a->grade}/{$a->max}</b>{$a->test}';
$string['log_17'] = '        Essay (slot {$a}): skipped (grade already exists)';
$string['log_18'] = '        Essay (slot {$a->slot}): grade {$a->grade} (not transferred)';
$string['menumode'] = 'Enable menu mode';
$string['menumode_desc'] = 'Adds a link to manual processing of grades to the course and quiz menu.';
$string['pluginmenutitle'] = 'Quiz Essay Grade Copy Tool';
$string['pluginname'] = 'Quiz Essay Grade Copy Tool';
$string['pluginname_help'] = 'A plugin that makes teachers jobs easier automatically carries over students successful essay grades to assignments from the previous attempt. You can manually enable a scheduled task to run or use automatic "event" mode, or manual "menu" mode.';
$string['privacy:metadata'] = 'The quizessaygrader local plugin does not store any personal data.';
$string['transferessaygrades'] = 'Transfer Essay Grades';
$string['transferfailed'] = 'An error occurred during execution';
$string['verbose'] = 'More verbose output';
$string['verbose_desc'] = 'Extended information about the work results is displayed in sheduled and menu mode.';
