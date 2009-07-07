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

require_once '../../config.php';

$courseid = required_param('id', PARAM_INT);

/// basic access checks
if (!$course = $DB->get_record('course', array('id' => $courseid))) {
    print_error('nocourseid');
}
require_login($course);
$context = get_context_instance(CONTEXT_COURSE, $course->id);

/// find all accessible reports
$reports = get_plugin_list('gradereport');     // Get all installed reports

foreach ($reports as $plugin => $plugindir) {                      // Remove ones we can't see
    if (!has_capability('gradereport/'.$plugin.':view', $context)) {
        unset($reports[$key]);
    }
}

if (empty($reports)) {
    print_error('noreports', 'debug', $CFG->wwwroot.'/course/view.php?id='.$course->id); // TODO: localize
}

if (!isset($USER->grade_last_report)) {
    $USER->grade_last_report = array();
}

if (!empty($USER->grade_last_report[$course->id])) {
    $last = $USER->grade_last_report[$course->id];
} else {
    $last = null;
}

if (!array_key_exists($last, $reports)) {
    $last = null;
}

if (empty($last)) {
    if (array_key_exists('grader', $reports)) {
        $last = 'grader';

    } else if (array_key_exists('user', $reports)) {
        $last = 'user';

    } else {
        $last = key(reset($reports));
    }
}

//redirect to last or guessed report
redirect($CFG->wwwroot.'/grade/report/'.$last.'/index.php?id='.$course->id);

