<?php // $Id$

//  Moves, adds, updates, duplicates or deletes modules in a course

    require("../config.php");
    require_once("lib.php");

    require_login();

    $sectionreturn = optional_param('sr', '', PARAM_INT);
    $add           = optional_param('add', '', PARAM_ALPHA);
    $type          = optional_param('type', '', PARAM_ALPHA);
    $indent        = optional_param('indent', 0, PARAM_INT);
    $update        = optional_param('update', 0, PARAM_INT);
    $hide          = optional_param('hide', 0, PARAM_INT);
    $show          = optional_param('show', 0, PARAM_INT);
    $copy          = optional_param('copy', 0, PARAM_INT);
    $moveto        = optional_param('moveto', 0, PARAM_INT);
    $movetosection = optional_param('movetosection', 0, PARAM_INT);
    $delete        = optional_param('delete', 0, PARAM_INT);
    $course        = optional_param('course', 0, PARAM_INT);
    $groupmode     = optional_param('groupmode', -1, PARAM_INT);
    $cancelcopy    = optional_param('cancelcopy', 0, PARAM_BOOL);
    $confirm       = optional_param('confirm', 0, PARAM_BOOL);

    //check if we are adding / editing a module that has new forms using formslib
    if (!empty($add)) {
        $id          = required_param('id', PARAM_INT);
        $section     = required_param('section', PARAM_INT);
        $type        = optional_param('type', '', PARAM_ALPHA);
        $returntomod = optional_param('return', 0, PARAM_BOOL);

        redirect("$CFG->wwwroot/course/modedit.php?add=$add&type=$type&course=$id&section=$section&return=$returntomod");

    } else if (!empty($update)) {
        if (!$cm = get_coursemodule_from_id('', $update, 0, true)) {
            print_error('invalidcoursemodule');
        }
        $returntomod = optional_param('return', 0, PARAM_BOOL);
        redirect("$CFG->wwwroot/course/modedit.php?update=$update&return=$returntomod");

    } else if (!empty($delete)) {
        if (!$cm = get_coursemodule_from_id('', $delete, 0, true)) {
            print_error('invalidcoursemodule');
        }

        if (!$course = $DB->get_record('course', array('id'=>$cm->course))) {
            print_error('invalidcourseid');
        }
        require_login($course->id); // needed to setup proper $COURSE
        $context = get_context_instance(CONTEXT_COURSE, $course->id);
        $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
        require_capability('moodle/course:manageactivities', $context);

        $return = "$CFG->wwwroot/course/view.php?id=$cm->course#section-$cm->sectionnum";

        if (!$confirm or !confirm_sesskey()) {
            $fullmodulename = get_string('modulename', $cm->modname);

            $optionsyes = array('confirm'=>1, 'delete'=>$cm->id, 'sesskey'=>sesskey());
            $optionsno  = array('id'=>$cm->course);

            $strdeletecheck = get_string('deletecheck', '', $fullmodulename);
            $strdeletecheckfull = get_string('deletecheckfull', '', "$fullmodulename '$cm->name'");

            $PAGE->set_pagetype('mod-' . $cm->modname . '-delete');

            print_header_simple($strdeletecheck, '', build_navigation(array(array('name'=>$strdeletecheck, 'link'=>'', 'type'=>'misc'))));

            // print_simple_box_start('center', '60%', '#FFAAAA', 20, 'noticebox');
            echo $OUTPUT->box_start('noticebox');
            $formcontinue = html_form::make_button('mod.php', $optionsyes, get_string('yes'));
            $formcancel = html_form::make_button($return, $optionsno, get_string('no'), 'get');
            echo $OUTPUT->confirm($strdeletecheckfull, $formcontinue, $formcancel);
            echo $OUTPUT->box_end();
            echo $OUTPUT->footer();

            exit;
        }

        $modlib = "$CFG->dirroot/mod/$cm->modname/lib.php";

        if (file_exists($modlib)) {
            require_once($modlib);
        } else {
            print_error('modulemissingcode', '', '', $modlib);
        }

        $deleteinstancefunction = $cm->modname."_delete_instance";

        if (!$deleteinstancefunction($cm->instance)) {
            echo $OUTPUT->notification("Could not delete the $cm->modname (instance)");
        }

        // remove all module files in case modules forget to do that
        $fs = get_file_storage();
        $fs->delete_area_files($modcontext->id);

        if (!delete_course_module($cm->id)) {
            echo $OUTPUT->notification("Could not delete the $cm->modname (coursemodule)");
        }
        if (!delete_mod_from_section($cm->id, $cm->section)) {
            echo $OUTPUT->notification("Could not delete the $cm->modname from that section");
        }

        add_to_log($course->id, 'course', "delete mod",
                   "view.php?id=$cm->course",
                   "$cm->modname $cm->instance", $cm->id);

        rebuild_course_cache($course->id);

        redirect($return);
    }


    if ((!empty($movetosection) or !empty($moveto)) and confirm_sesskey()) {
        if (!$cm = get_coursemodule_from_id('', $USER->activitycopy, 0, true)) {
            print_error('invalidcoursemodule');
        }

        if (!empty($movetosection)) {
            if (!$section = $DB->get_record('course_sections', array('id'=>$movetosection, 'course'=>$cm->course))) {
                print_error('sectionnotexist');
            }
            $beforecm = NULL;

        } else {                      // normal moveto
            if (!$beforecm = get_coursemodule_from_id('', $moveto, $cm->course, true)) {
                print_error('invalidcoursemodule');
            }
            if (!$section = $DB->get_record('course_sections', array('id'=>$beforecm->section, 'course'=>$cm->course))) {
                print_error('sectionnotexist');
            }
        }

        require_login($section->course); // needed to setup proper $COURSE
        $context = get_context_instance(CONTEXT_COURSE, $section->course);
        require_capability('moodle/course:manageactivities', $context);

        if (!ismoving($section->course)) {
            print_error('needcopy', '', "view.php?id=$section->course");
        }

        moveto_module($cm, $section, $beforecm);

        unset($USER->activitycopy);
        unset($USER->activitycopycourse);
        unset($USER->activitycopyname);

        rebuild_course_cache($section->course);

        if (SITEID == $section->course) {
            redirect($CFG->wwwroot);
        } else {
            redirect("view.php?id=$section->course#section-$sectionreturn");
        }

    } else if (!empty($indent) and confirm_sesskey()) {
        $id = required_param('id', PARAM_INT);
        if (!$cm = get_coursemodule_from_id('', $id, 0, true)) {
            print_error('invalidcoursemodule');
        }

        require_login($cm->course); // needed to setup proper $COURSE
        $context = get_context_instance(CONTEXT_COURSE, $cm->course);
        require_capability('moodle/course:manageactivities', $context);

        $cm->indent += $indent;

        if ($cm->indent < 0) {
            $cm->indent = 0;
        }

        $DB->set_field('course_modules', 'indent', $cm->indent, array('id'=>$cm->id));

        rebuild_course_cache($cm->course);

        if (SITEID == $cm->course) {
            redirect($CFG->wwwroot);
        } else {
            redirect("view.php?id=$cm->course#section-$cm->sectionnum");
        }

    } else if (!empty($hide) and confirm_sesskey()) {
        if (!$cm = get_coursemodule_from_id('', $hide, 0, true)) {
            print_error('invalidcoursemodule');
        }

        require_login($cm->course); // needed to setup proper $COURSE
        $context = get_context_instance(CONTEXT_MODULE, $cm->id);
        require_capability('moodle/course:activityvisibility', $context);

        set_coursemodule_visible($cm->id, 0);

        rebuild_course_cache($cm->course);

        if (SITEID == $cm->course) {
            redirect($CFG->wwwroot);
        } else {
            redirect("view.php?id=$cm->course#section-$cm->sectionnum");
        }

    } else if (!empty($show) and confirm_sesskey()) {
        if (!$cm = get_coursemodule_from_id('', $show, 0, true)) {
            print_error('invalidcoursemodule');
        }

        require_login($cm->course); // needed to setup proper $COURSE
        $context = get_context_instance(CONTEXT_COURSE, $cm->course);
        require_capability('moodle/course:activityvisibility', $context);

        if (!$section = $DB->get_record('course_sections', array('id'=>$cm->section))) {
            print_error('sectionnotexist');
        }

        if (!$module = $DB->get_record('modules', array('id'=>$cm->module))) {
            print_error('moduledoesnotexist');
        }

        if ($module->visible and ($section->visible or (SITEID == $cm->course))) {
            set_coursemodule_visible($cm->id, 1);
            rebuild_course_cache($cm->course);
        }

        if (SITEID == $cm->course) {
            redirect($CFG->wwwroot);
        } else {
            redirect("view.php?id=$cm->course#section-$cm->sectionnum");
        }

    } else if ($groupmode > -1 and confirm_sesskey()) {
        $id = required_param('id', PARAM_INT);
        if (!$cm = get_coursemodule_from_id('', $id, 0, true)) {
            print_error('invalidcoursemodule');
        }

        require_login($cm->course); // needed to setup proper $COURSE
        $context = get_context_instance(CONTEXT_MODULE, $cm->id);
        require_capability('moodle/course:manageactivities', $context);

        set_coursemodule_groupmode($cm->id, $groupmode);

        rebuild_course_cache($cm->course);

        if (SITEID == $cm->course) {
            redirect($CFG->wwwroot);
        } else {
            redirect("view.php?id=$cm->course#section-$cm->sectionnum");
        }

    } else if (!empty($copy) and confirm_sesskey()) { // value = course module
        if (!$cm = get_coursemodule_from_id('', $copy, 0, true)) {
            print_error('invalidcoursemodule');
        }

        require_login($cm->course); // needed to setup proper $COURSE
        $context = get_context_instance(CONTEXT_COURSE, $cm->course);
        require_capability('moodle/course:manageactivities', $context);

        if (!$section = $DB->get_record('course_sections', array('id'=>$cm->section))) {
            print_error('sectionnotexist');
        }

        $USER->activitycopy       = $copy;
        $USER->activitycopycourse = $cm->course;
        $USER->activitycopyname   = $cm->name;

        redirect("view.php?id=$cm->course#section-$sectionreturn");

    } else if (!empty($cancelcopy) and confirm_sesskey()) { // value = course module

        $courseid = $USER->activitycopycourse;

        unset($USER->activitycopy);
        unset($USER->activitycopycourse);
        unset($USER->activitycopyname);

        redirect("view.php?id=$courseid");

    } else {
        print_error('unknowaction');
    }
?>
