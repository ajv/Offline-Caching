<?php  // $Id$
       // index.php - the front page.

///////////////////////////////////////////////////////////////////////////
//                                                                       //
// NOTICE OF COPYRIGHT                                                   //
//                                                                       //
// Moodle - Modular Object-Oriented Dynamic Learning Environment         //
//          http://moodle.org                                            //
//                                                                       //
// Copyright (C) 1999 onwards  Martin Dougiamas  http://moodle.com       //
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


    if (!file_exists('./config.php')) {
        header('Location: install.php');
        die;
    }

    require_once('config.php');
    require_once($CFG->dirroot .'/course/lib.php');
    require_once($CFG->libdir .'/filelib.php');

    redirect_if_major_upgrade_required();

    if ($CFG->forcelogin) {
        require_login();
    } else {
        user_accesstime_log();
    }

/// If the site is currently under maintenance, then print a message
    if (!empty($CFG->maintenance_enabled) and !has_capability('moodle/site:config', get_context_instance(CONTEXT_SYSTEM))) {
        print_maintenance_message();
    }

    if (has_capability('moodle/site:config', get_context_instance(CONTEXT_SYSTEM))) {
        if (moodle_needs_upgrading()) {
            redirect($CFG->wwwroot .'/'. $CFG->admin .'/index.php');
        }
    } else if (!empty($CFG->mymoodleredirect)) {    // Redirect logged-in users to My Moodle overview if required
        if (isloggedin() && !isguestuser()) {
            redirect($CFG->wwwroot .'/my/index.php');
        }
    }


    if (get_moodle_cookie() == '') {
        set_moodle_cookie('nobody');   // To help search for cookies on login page
    }

    if (!empty($USER->id)) {
        add_to_log(SITEID, 'course', 'view', 'view.php?id='.SITEID, SITEID);
    }

    $PAGE->set_pagetype('site-index');
    $PAGE->set_course($SITE);

    if (empty($CFG->langmenu)) {
        $langmenu = '';
    } else {
        $currlang = current_language();
        $langs = get_list_of_languages();

        $select = moodle_select::make_popup_form($CFG->wwwroot .'/index.php', 'lang', $langs, 'chooselang', $currlang);
        $select->nothinglabel = false;
        $select->set_label(get_accesshide(get_string('language')));
        $langmenu = $OUTPUT->select($select);
    }
    $PAGE->set_other_editing_capability('moodle/course:manageactivities');
    $PAGE->set_url('');
    $PAGE->set_docs_path('');
    $PAGE->set_generaltype('home');
    $editing = $PAGE->user_is_editing();
    $PAGE->set_title($SITE->fullname);
    $PAGE->set_heading($SITE->fullname);
    echo $OUTPUT->header('', user_login_string($SITE) . $langmenu);

/// Print Section
    if ($SITE->numsections > 0) {

        if (!$section = $DB->get_record('course_sections', array('course'=>$SITE->id, 'section'=>1))) {
            $DB->delete_records('course_sections', array('course'=>$SITE->id, 'section'=>1)); // Just in case
            $section->course = $SITE->id;
            $section->section = 1;
            $section->summary = '';
            $section->sequence = '';
            $section->visible = 1;
            $section->id = $DB->insert_record('course_sections', $section);
        }

        if (!empty($section->sequence) or !empty($section->summary) or $editing) {
            echo $OUTPUT->box_start('generalbox sitetopic');

            /// If currently moving a file then show the current clipboard
            if (ismoving($SITE->id)) {
                $stractivityclipboard = strip_tags(get_string('activityclipboard', '', $USER->activitycopyname));
                echo '<p><font size="2">';
                echo "$stractivityclipboard&nbsp;&nbsp;(<a href=\"course/mod.php?cancelcopy=true&amp;sesskey=".sesskey()."\">". get_string('cancel') .'</a>)';
                echo '</font></p>';
            }

            $context = get_context_instance(CONTEXT_COURSE, SITEID);
            $summarytext = file_rewrite_pluginfile_urls($section->summary, 'pluginfile.php', $context->id, 'course_section', $section->id);
            $summaryformatoptions = new object();
            $summaryformatoptions->noclean = true;

            echo format_text($summarytext, FORMAT_HTML, $summaryformatoptions);

            if ($editing) {
                $streditsummary = get_string('editsummary');
                echo "<a title=\"$streditsummary\" ".
                     " href=\"course/editsection.php?id=$section->id\"><img src=\"" . $OUTPUT->old_icon_url('t/edit') . "\" ".
                     " class=\"iconsmall\" alt=\"$streditsummary\" /></a><br /><br />";
            }

            get_all_mods($SITE->id, $mods, $modnames, $modnamesplural, $modnamesused);
            print_section($SITE, $section, $mods, $modnamesused, true);

            if ($editing) {
                print_section_add_menus($SITE, $section->section, $modnames);
            }
            echo $OUTPUT->box_end();
        }
    }

    if (isloggedin() and !isguest() and isset($CFG->frontpageloggedin)) {
        $frontpagelayout = $CFG->frontpageloggedin;
    } else {
        $frontpagelayout = $CFG->frontpage;
    }

    foreach (explode(',',$frontpagelayout) as $v) {
        switch ($v) {     /// Display the main part of the front page.
            case FRONTPAGENEWS:
                if ($SITE->newsitems) { // Print forums only when needed
                    require_once($CFG->dirroot .'/mod/forum/lib.php');

                    if (! $newsforum = forum_get_course_forum($SITE->id, 'news')) {
                        print_error('cannotfindorcreateforum', 'forum');
                    }

                    if (!empty($USER->id)) {
                        $SESSION->fromdiscussion = $CFG->wwwroot;
                        $subtext = '';
                        if (forum_is_subscribed($USER->id, $newsforum)) {
                            if (!forum_is_forcesubscribed($newsforum)) {
                                $subtext = get_string('unsubscribe', 'forum');
                            }
                        } else {
                            $subtext = get_string('subscribe', 'forum');
                        }
                        echo $OUTPUT->heading($newsforum->name, 2, 'headingblock header');
                        echo '<div class="subscribelink"><a href="mod/forum/subscribe.php?id='.$newsforum->id.'">'.$subtext.'</a></div>';
                    } else {
                        echo $OUTPUT->heading($newsforum->name, 2, 'headingblock header');
                    }

                    forum_print_latest_discussions($SITE, $newsforum, $SITE->newsitems, 'plain', 'p.modified DESC');
                }
            break;

            case FRONTPAGECOURSELIST:

                if (isloggedin() and !has_capability('moodle/site:config', get_context_instance(CONTEXT_SYSTEM)) and !isguest() and empty($CFG->disablemycourses)) {
                    echo $OUTPUT->heading(get_string('mycourses'), 2, 'headingblock header');
                    print_my_moodle();
                } else if ((!has_capability('moodle/site:config', get_context_instance(CONTEXT_SYSTEM)) and !isguest()) or ($DB->count_records('course') <= FRONTPAGECOURSELIMIT)) {
                    // admin should not see list of courses when there are too many of them
                    echo $OUTPUT->heading(get_string('availablecourses'), 2, 'headingblock header');
                    print_courses(0);
                }
            break;

            case FRONTPAGECATEGORYNAMES:

                echo $OUTPUT->heading(get_string('categories'), 2, 'headingblock header');
                echo $OUTPUT->box_start('generalbox categorybox');
                print_whole_category_list(NULL, NULL, NULL, -1, false);
                echo $OUTPUT->box_end();
                print_course_search('', false, 'short');
            break;

            case FRONTPAGECATEGORYCOMBO:

                echo $OUTPUT->heading(get_string('categories'), 2, 'headingblock header');
                echo $OUTPUT->box_start('generalbox categorybox');
                print_whole_category_list(NULL, NULL, NULL, -1, true);
                echo $OUTPUT->box_end();
                print_course_search('', false, 'short');
            break;

            case FRONTPAGETOPICONLY:    // Do nothing!!  :-)
            break;

        }
        echo '<br />';
    }

    echo $OUTPUT->footer();
