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

/**
 * Settings file for plugin 'local_quizessaygrader'
 *
 * @package     local_quizessaygrader
 * @copyright   2025 Alex Orlov <snickser@gmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();


if ($hassiteconfig) {
    $settings = new admin_settingpage('local_quizessaygrader', get_string(
        'pluginname',
        'local_quizessaygrader'
    ));
    $ADMIN->add('localplugins', $settings);


    $plugininfo = \core_plugin_manager::instance()->get_plugin_info('local_quizessaygrader');
    $donate = get_string('donate', 'local_quizessaygrader', $plugininfo);

    $settings->add(new admin_setting_heading(
        'local_quizessaygrader_donate',
        '',
        $donate,
    ));

    $settings->add(new admin_setting_heading(
        'local_quizessaygrader',
        ' ',
        get_string('pluginname_help', 'local_quizessaygrader'),
    ));

    $options = [0 => get_string('highgradeletter', 'grades'),
                1 => get_string('real', 'grades'),
    ];
    $settings->add(new admin_setting_configselect(
        'local_quizessaygrader/gradetype',
        get_string('gradetype', 'local_quizessaygrader'),
        get_string('gradetype_desc', 'local_quizessaygrader'),
        0,
        $options
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_quizessaygrader/dryrun',
        get_string('dryrun', 'local_quizessaygrader'),
        '',
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_quizessaygrader/verbose',
        get_string('verbose', 'local_quizessaygrader'),
        '',
        1
    ));

}

