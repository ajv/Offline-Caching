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
 * List of file folders in course
 *
 * @package   mod-folder
 * @copyright 2009 onwards Martin Dougiamas (http://dougiamas.com)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');

$id = required_param('id', PARAM_INT); // course id

$course = $DB->get_record('course', array('id'=>$id), '*', MUST_EXIST);

require_course_login($course, true);

add_to_log($course->id, 'folder', 'view all', "index.php?id=$course->id", '');

$strfolder       = get_string('modulename', 'folder');
$strfolders      = get_string('modulenameplural', 'folder');
$strweek         = get_string('week');
$strtopic        = get_string('topic');
$strname         = get_string('name');
$strintro        = get_string('moduleintro');
$strlastmodified = get_string('lastmodified');

$PAGE->set_url('mod/folder/index.php', array('id' => $course->id));
$PAGE->set_title($course->shortname.': '.$strfolders);
$PAGE->set_heading($course->fullname);
$navlinks = array(array('name' => $strfolders, 'link' => '', 'type' => 'activityinstance'));
echo $OUTPUT->header(build_navigation($navlinks), navmenu($course));

if (!$folders = get_all_instances_in_course('folder', $course)) {
    notice(get_string('thereareno', 'moodle', $strfolders), "$CFG->wwwroot/course/view.php?id=$course->id");
    exit;
}

$table = new html_table();
$table->set_classes(array('generaltable', 'mod_index'));

if ($course->format == 'weeks') {
    $table->head  = array ($strweek, $strname, $strintro);
    $table->align = array ('center', 'left', 'left');
} else if ($course->format == 'topics') {
    $table->head  = array ($strtopic, $strname, $strintro);
    $table->align = array ('center', 'left', 'left');
} else {
    $table->head  = array ($strlastmodified, $strname, $strintro);
    $table->align = array ('left', 'left', 'left');
}

$modinfo = get_fast_modinfo($course);
$currentsection = '';
foreach ($folders as $folder) {
    $cm = $modinfo->cms[$folder->coursemodule];
    if ($course->format == 'weeks' or $course->format == 'topics') {
        $printsection = '';
        if ($folder->section !== $currentsection) {
            if ($folder->section) {
                $printsection = $folder->section;
            }
            if ($currentsection !== '') {
                $table->data[] = 'hr';
            }
            $currentsection = $folder->section;
        }
    } else {
        $printsection = '<span class="smallinfo">'.userdate($folder->timemodified)."</span>";
    }

    $class = $folder->visible ? '' : 'class="dimmed"'; // hidden modules are dimmed
    $table->data[] = array (
        $printsection,
        "<a $class href=\"view.php?id=$cm->id\">".format_string($folder->name)."</a>",
        format_module_intro('folder', $folder, $cm->id));
}

echo $OUTPUT->table($table);

echo $OUTPUT->footer();
