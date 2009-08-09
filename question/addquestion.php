<?php  // $Id$

///////////////////////////////////////////////////////////////////////////
//                                                                       //
// NOTICE OF COPYRIGHT                                                   //
//                                                                       //
// Moodle - Modular Object-Oriented Dynamic Learning Environment         //
//          http://moodle.org                                            //
//                                                                       //
// Copyright (C) 1999 onwards Martin Dougiamas  http://dougiamas.com     //
//                                                                       //
// This program is free software; you can redistribute it and/or modify  //
// it under the terms of the GNU General Public License as published by  //
// the Free Software Foundation; either version 2 of the License, or     //
// (at your option) any later version.                                   //
//                                                                       //
// This program is distributed in the hope that it will be useful,       //
// but WITHOUT ANY WARRANTY; without even the implied warranty of        //
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the         //
// GNU General Public License for more details:                          //
//                                                                       //
//          http://www.gnu.org/copyleft/gpl.html                         //
//                                                                       //
///////////////////////////////////////////////////////////////////////////

/**
 * Shows a screen where the user can choose a question type, before being
 * redirected to question.php
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package questionbank
 *//** */

require_once(dirname(__FILE__) . '/../config.php');
require_once(dirname(__FILE__) . '/editlib.php');

// Read URL parameters.
$categoryid = required_param('category', PARAM_INT);
$cmid = optional_param('cmid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$returnurl = optional_param('returnurl', 0, PARAM_LOCALURL);
$appendqnumstring = optional_param('appendqnumstring', '', PARAM_ALPHA);

// Place to accumulate hidden params for the form we will print.
$hiddenparams = array('category' => $categoryid);

// Validate params.
if (!$category = $DB->get_record('question_categories', array('id' => $categoryid))) {
    print_error('categorydoesnotexist', 'question', $returnurl);
}

if ($cmid) {
    list($module, $cm) = get_module_from_cmid($cmid);
    require_login($cm->course, false, $cm);
    $thiscontext = get_context_instance(CONTEXT_MODULE, $cmid);
    $hiddenparams['cmid'] = $cmid;
} else if ($courseid) {
    require_login($courseid, false);
    $thiscontext = get_context_instance(CONTEXT_COURSE, $courseid);
    $module = null;
    $cm = null;
    $hiddenparams['courseid'] = $courseid;
} else {
    print_error('missingcourseorcmid', 'question');
}

// Check permissions.
$categorycontext = get_context_instance_by_id($category->contextid);
require_capability('moodle/question:add', $categorycontext);

// Ensure other optional params get passed on to question.php.
if (!empty($returnurl)) {
    $hiddenparams['returnurl'] = $returnurl;
}
if (!empty($appendqnumstring)) {
    $hiddenparams['appendqnumstring'] = $appendqnumstring;
}

$chooseqtype = get_string('chooseqtypetoadd', 'question');
if ($cm !== null) {
    $navlinks = array();
    if (stripos($returnurl, "$CFG->wwwroot/mod/{$cm->modname}/view.php")!== 0) {
        //don't need this link if returnurl returns to view.php
        $navlinks[] = array('name' => get_string('editinga', 'moodle', get_string('modulename', $cm->modname)), 'link' => $returnurl, 'type' => 'title');
    }
    $navlinks[] = array('name' => $chooseqtype, 'link' => '', 'type' => 'title');
    $navigation = build_navigation($navlinks, $cm);
    print_header_simple($chooseqtype, '', $navigation, '', '', true, update_module_button($cm->id, $cm->course, get_string('modulename', $cm->modname)));

} else {
    $navlinks = array();
    $navlinks[] = array('name' => get_string('editquestions', 'question'), 'link' => $returnurl, 'type' => 'title');
    $navlinks[] = array('name' => $chooseqtype, 'link' => '', 'type' => 'title');
    $navigation = build_navigation($navlinks);
    print_header_simple($chooseqtype, '', $navigation);
}

// Display a form to choose the question type.
print_box_start('generalbox boxwidthnormal boxaligncenter', 'chooseqtypebox');
print_choose_qtype_to_add_form($hiddenparams);
print_box_end();

echo $OUTPUT->footer();
?>
