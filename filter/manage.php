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
 * Lets users configure which filters are active in a sub-context.
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package moodlecore
 *//** */

require_once(dirname(__FILE__) . '/../config.php');
require_once($CFG->libdir . '/adminlib.php');

$contextid = required_param('contextid',PARAM_INT);
$forfilter = optional_param('filter', '', PARAM_SAFEPATH);

if (!$context = get_context_instance_by_id($contextid)) {
    print_error('wrongcontextid', 'error');
}

// This is a policy decision, rather than something that would be impossible to implement.
if (!in_array($context->contextlevel, array(CONTEXT_COURSECAT, CONTEXT_COURSE, CONTEXT_MODULE))) {
    print_error('cannotcustomisefiltersblockuser', 'error');
}

$isfrontpage = $context->contextlevel == CONTEXT_COURSE && $context->instanceid == SITEID;
$contextname = print_context_name($context);
$baseurl = $CFG->wwwroot . '/filter/manage.php?contextid=' . $context->id;

if ($context->contextlevel == CONTEXT_COURSECAT) {
    $course = clone($SITE);
} else if ($context->contextlevel == CONTEXT_COURSE) {
    $course = $DB->get_record('course', array('id' => $context->instanceid));
} else {
    // Must be module context.
    $course = $DB->get_record_sql('SELECT c.* FROM {course} c JOIN {context} ctx ON c.id = ctx.instanceid WHERE ctx.id = ?',
            array(get_parent_contextid($context)));
}
if (!$course) {
    print_error('invalidcourse', 'error');
}

/// Check login and permissions.
require_login($course);
require_capability('moodle/filter:manage', $context);

/// Get the list of available filters.
$availablefilters = filter_get_available_in_context($context);
if (!$isfrontpage && empty($availablefilters)) {
    print_error('nofiltersenabled', 'error');
}

// If we are handling local settings for a particular filter, start processing.
if ($forfilter) {
    if (!filter_has_local_settings($forfilter)) {
        print_error('filterdoesnothavelocalconfig', 'error', $forfilter);
    }
    require_once($CFG->dirroot . '/filter/local_settings_form.php');
    require_once($CFG->dirroot . '/' . $forfilter . '/filterlocalsettings.php');
    $formname = basename($forfilter) . '_filter_local_settings_form';
    $settingsform = new $formname($CFG->wwwroot . '/filter/manage.php', $forfilter, $context);
    if ($settingsform->is_cancelled()) {
        redirect($baseurl);
    } else if ($data = $settingsform->get_data()) {
        $settingsform->save_changes($data);
        redirect($baseurl);
    }
}

/// Process any form submission.
if ($forfilter == '' && optional_param('savechanges', false, PARAM_BOOL) && confirm_sesskey()) {
    foreach ($availablefilters as $filter => $filterinfo) {
        $newstate = optional_param(str_replace('/', '_', $filter), false, PARAM_INT);
        if ($newstate !== false && $newstate != $filterinfo->localstate) {
            filter_set_local_state($filter, $context->id, $newstate);
        }
    }
    redirect($CFG->wwwroot . '/filter/manage.php?contextid=' . $context->id, get_string('changessaved'), 1);
}

/// These are needed early because of tabs.php
$assignableroles = get_assignable_roles($context, ROLENAME_BOTH);
$overridableroles = get_overridable_roles($context, ROLENAME_BOTH);

/// Work out an appropriate page title.
if ($forfilter) {
    $a = new stdClass;
    $a->filter = filter_get_name($forfilter);
    $a->context = $contextname;
    $title = get_string('filtersettingsforin', 'filters', $a);
} else {
    $title = get_string('filtersettingsin', 'filters', $contextname);
}
$straction = get_string('filters', 'admin'); // Used by tabs.php

/// Print the header and tabs
if ($context->contextlevel == CONTEXT_COURSE and $context->instanceid == SITEID) {
    admin_externalpage_setup('frontpagefilters');
    admin_externalpage_print_header();
} else {
    $currenttab = 'filters';
    include_once($CFG->dirroot . '/' . $CFG->admin . '/roles/tabs.php');
}

/// Print heading.
print_heading_with_help($title, 'localfiltersettings');

if (empty($availablefilters)) {
    echo '<p class="centerpara">' . get_string('nofiltersenabled', 'filters') . "</p>\n";
} else if ($forfilter) {
    $current = filter_get_local_config($forfilter, $contextid);
    $settingsform->set_data((object) $current);
    $settingsform->display();
} else {
    $settingscol = false;
    foreach ($availablefilters as $filter => $notused) {
        $hassettings = filter_has_local_settings($filter);
        $availablefilters[$filter]->hassettings = $hassettings;
        $settingscol = $settingscol || $hassettings;
    }

    $strsettings = get_string('settings');
    $stroff = get_string('off', 'filters');
    $stron = get_string('on', 'filters');
    $strdefaultoff = get_string('defaultx', 'filters', $stroff);
    $strdefaulton = get_string('defaultx', 'filters', $stron);
    $activechoices = array(
        TEXTFILTER_INHERIT => '',
        TEXTFILTER_OFF => $stroff,
        TEXTFILTER_ON => $stron,
    );

    echo '<form action="' . $baseurl . '" method="post">';
    echo "\n<div>\n";
    echo '<input type="hidden" name="sesskey" value="' . sesskey() . '" />';

    $table = new stdClass;
    $table->head  = array(get_string('filter'), get_string('isactive', 'filters'));
    $table->align = array('left', 'left');
    if ($settingscol) {
        $table->head[] = $strsettings;
        $table->align[] = 'left';
    }
    $table->width = ' ';
    $table->data = array();

    // iterate through filters adding to display table
    foreach ($availablefilters as $filter => $filterinfo) {
        $row = array();

        // Filter name.
        $row[] = filter_get_name($filter);

        // Default/on/off choice.
        if ($filterinfo->inheritedstate == TEXTFILTER_ON) {
            $activechoices[TEXTFILTER_INHERIT] = $strdefaulton;
        } else {
            $activechoices[TEXTFILTER_INHERIT] = $strdefaultoff;
        }
        $row[] = choose_from_menu($activechoices, str_replace('/', '_', $filter),
                $filterinfo->localstate, '', '', '', true);

        // Settings link, if required
        if ($settingscol) {
            $settings = '';
            if ($filterinfo->hassettings) {
                $settings = '<a href="' . $baseurl . '&amp;filter=' . $filter . '">' . $strsettings . '</a>';
            }
            $row[] = $settings;
        }

        $table->data[] = $row;
    }

    print_table($table);
    echo '<div class="buttons">' . "\n";
    echo '<input type="submit" name="savechanges" value="' . get_string('savechanges') . '" />';
    echo "\n</div>\n";
    echo "</div>\n";
    echo "</form>\n";

}

/// Appropriate back link.
if (!$isfrontpage && ($url = get_context_url($context))) {
    echo '<div class="backlink"><a href="' . $url . '">' .
        get_string('backto', '', $contextname) . '</a></div>';
}

echo $OUTPUT->footer();
?>
