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
 * Shows the result of has_capability for every capability for a user in a context.
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package roles
 *//** */

    require_once(dirname(__FILE__) . '/../../config.php');
    require_once($CFG->dirroot . '/' . $CFG->admin . '/roles/lib.php');

    $contextid = required_param('contextid',PARAM_INT);
    $userid = optional_param('userid', 0, PARAM_INT); // needed for user tabs
    $courseid = optional_param('courseid', 0, PARAM_INT); // needed for user tabs
    $returnurl      = optional_param('returnurl', null, PARAM_LOCALURL);

    $urlparams = array('contextid' => $contextid);
    if (!empty($userid)) {
        $urlparams['userid'] = $userid;
    }
    if ($courseid && $courseid != SITEID) {
        $urlparams['courseid'] = $courseid;
    }
    if ($returnurl) {
        $urlparams['returnurl'] = $returnurl;
    }
    $PAGE->set_url($CFG->admin . '/roles/check.php', $urlparams);

    if (! $context = get_context_instance_by_id($contextid)) {
        print_error('wrongcontextid', 'error');
    }
    $isfrontpage = $context->contextlevel == CONTEXT_COURSE && $context->instanceid == SITEID;
    $contextname = print_context_name($context);

    if ($context->contextlevel == CONTEXT_COURSE) {
        $courseid = $context->instanceid;
        if (!$course = $DB->get_record('course', array('id'=>$courseid))) {
            print_error('invalidcourse', 'error');
        }

    } else if (!empty($courseid)){ // we need this for user tabs in user context
        if (!$course = $DB->get_record('course', array('id'=>$courseid))) {
            print_error('invalidcourse', 'error');
        }

    } else {
        $courseid = SITEID;
        $course = clone($SITE);
    }

/// Check login and permissions.
    require_login($course);
    $canview = has_any_capability(array('moodle/role:assign', 'moodle/role:safeoverride',
            'moodle/role:override', 'moodle/role:manage'), $context);
    if (!$canview) {
        print_error('nopermissions', 'error', '', get_string('checkpermissions', 'role'));
    }

/// These are needed early because of tabs.php
    $assignableroles = get_assignable_roles($context, ROLENAME_BOTH);
    $overridableroles = get_overridable_roles($context, ROLENAME_BOTH);

/// Get the user_selector we will need.
/// Teachers within a course just get to see the same list of people they can
/// assign roles to. Admins (people with moodle/role:manage) can run this report for any user.
    $options = array('context' => $context, 'roleid' => 0);
    if (has_capability('moodle/role:manage', $context)) {
        $userselector = new potential_assignees_course_and_above('reportuser', $options);
    } else {
        $userselector = roles_get_potential_user_selector($context, 'reportuser', $options);
    }
    $userselector->set_multiselect(false);
    $userselector->set_rows(10);

/// Work out an appropriate page title.
    $title = get_string('checkpermissionsin', 'role', $contextname);
    $straction = get_string('checkpermissions', 'role'); // Used by tabs.php

/// Print the header and tabs
    if ($context->contextlevel == CONTEXT_USER) {
        $user = $DB->get_record('user', array('id' => $userid));
        $fullname = fullname($user, has_capability('moodle/site:viewfullnames', $context));

        /// course header
        $navlinks = array();
        if ($courseid != SITEID) {
            if (has_capability('moodle/course:viewparticipants', get_context_instance(CONTEXT_COURSE, $courseid))) {
                $navlinks[] = array('name' => get_string('participants'), 'link' => "$CFG->wwwroot/user/index.php?id=$courseid", 'type' => 'misc');
            }
            $navlinks[] = array('name' => $fullname, 'link' => "$CFG->wwwroot/user/view.php?id=$userid&amp;course=$courseid", 'type' => 'misc');
            $navlinks[] = array('name' => $straction, 'link' => null, 'type' => 'misc');
            $navigation = build_navigation($navlinks);

            print_header($title, $fullname, $navigation, '', '', true, '&nbsp;', navmenu($course));

        /// site header
        } else {
            $navlinks[] = array('name' => $fullname, 'link' => "$CFG->wwwroot/user/view.php?id=$userid&amp;course=$courseid", 'type' => 'misc');
            $navlinks[] = array('name' => $straction, 'link' => null, 'type' => 'misc');
            $navigation = build_navigation($navlinks);
            print_header($title, $course->fullname, $navigation, "", "", true, "&nbsp;", navmenu($course));
        }

        $showroles = 1;
        $currenttab = 'check';
        include_once($CFG->dirroot.'/user/tabs.php');

    } else if ($context->contextlevel == CONTEXT_SYSTEM) {
        admin_externalpage_setup('checkpermissions', '', array('contextid' => $contextid));
        admin_externalpage_print_header();

    } else if ($context->contextlevel == CONTEXT_COURSE and $context->instanceid == SITEID) {
        admin_externalpage_setup('frontpageroles', '', array('contextid' => $contextid), $CFG->wwwroot . '/' . $CFG->admin . '/roles/check.php');
        admin_externalpage_print_header();
        $currenttab = 'check';
        include_once('tabs.php');

    } else {
        $currenttab = 'check';
        include_once('tabs.php');
    }

/// Print heading.
    print_heading_with_help($title, 'checkpermissions');

/// If a user has been chosen, show all the permissions for this user.
    $reportuser = $userselector->get_selected_user();
    if (!is_null($reportuser)) {
        print_box_start('generalbox boxaligncenter boxwidthwide');
        echo $OUTPUT->heading(get_string('permissionsforuser', 'role', fullname($reportuser)), 3);

        $table = new explain_capability_table($context, $reportuser, $contextname);
        $table->display();
        print_box_end();

        $selectheading = get_string('selectanotheruser', 'role');
    } else {
        $selectheading = get_string('selectauser', 'role');
    }

/// Show UI for choosing a user to report on.
    print_box_start('generalbox boxwidthnormal boxaligncenter', 'chooseuser');
    echo '<form method="get" action="' . $CFG->wwwroot . '/' . $CFG->admin . '/roles/check.php" >';

/// Hidden fields.
    echo '<input type="hidden" name="contextid" value="' . $context->id . '" />';
    if (!empty($userid)) {
        echo '<input type="hidden" name="userid" value="' . $userid . '" />';
    }
    if ($courseid && $courseid != SITEID) {
        echo '<input type="hidden" name="courseid" value="' . $courseid . '" />';
    }

/// User selector.
    echo $OUTPUT->heading('<label for="reportuser">' . $selectheading . '</label>', 3);
    $userselector->display(); 

/// Submit button and the end of the form.
    echo '<p id="chooseusersubmit"><input type="submit" value="' . get_string('showthisuserspermissions', 'role') . '" /></p>';
    echo '</form>';
    print_box_end();

/// Appropriate back link.
    if (!$isfrontpage && ($url = get_context_url($context))) {
        echo '<div class="backlink"><a href="' . $url . '">' .
            get_string('backto', '', $contextname) . '</a></div>';
        } else if ($returnurl) {
            echo '<div class="backlink"><a href="' . $CFG->wwwroot . '/' . $returnurl . '">' .
                get_string('backtopageyouwereon') . '</a></div>';
    }

    echo $OUTPUT->footer();
?>
