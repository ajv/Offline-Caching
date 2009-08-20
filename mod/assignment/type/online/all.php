<?php // $Id$

//===================================================
// all.php
//
// Displays a complete list of online assignments
// for the course. Rather like what happened in 
// the old Journal activity.
// Howard Miller 2008
// See MDL-14045
//===================================================

    require_once("../../../../config.php");
    require_once("{$CFG->dirroot}/mod/assignment/lib.php");
    require_once($CFG->libdir.'/gradelib.php');
    require_once('assignment.class.php');

    // get parameter
    $id = required_param('id', PARAM_INT);   // course

    if (!$course = $DB->get_record('course', array('id'=>$id))) {
        print_error('invalidcourse');
    }

    require_course_login($course);

    // check for view capability at course level
    $context = get_context_instance(CONTEXT_COURSE,$course->id);
    require_capability('mod/assignment:view',$context);

    // various strings
    $str = new stdClass; 
    $str->assignments = get_string("modulenameplural", "assignment");
    $str->duedate = get_string('duedate','assignment');
    $str->duedateno = get_string('duedateno','assignment');
    $str->editmysubmission = get_string('editmysubmission','assignment');
    $str->emptysubmission = get_string('emptysubmission','assignment');
    $str->noassignments = get_string('noassignments','assignment');
    $str->onlinetext = get_string('typeonline','assignment');
    $str->submitted = get_string('submitted','assignment');
    $str->topic = get_string('topic');
    $str->week = get_string('week');

    // build navigation
    $navlinks = array();
    $navlinks[] = array('name' => $str->assignments, 
        'link' => "$CFG->wwwroot/mod/assignment/index.php?id=$id", 'type' => 'activity');
    $navlinks[] = array('name' => $str->onlinetext, 'link' => '', 'type' => 'activity');
    $navigation = build_navigation($navlinks);


    // get all the assignments in the course
    $assignments = get_all_instances_in_course('assignment',$course, $USER->id );

    // get correct text for course type
    if ($course->format=='weeks') {
        $courseformat = $str->week;

    } else if ($course->format=='topics') {
        $courseformat = $str->topic;

    } else {
        $courseformat = '';
    }

    // array to hold display data
    $views = array();

    // loop over assignments finding online ones
    foreach( $assignments as $assignment ) {
        // only interested in online assignments
        if ($assignment->assignmenttype != 'online') {
            continue;
        } 

        // check we are allowed to view this
        $context = get_context_instance(CONTEXT_MODULE, $assignment->coursemodule);
        if (!has_capability('mod/assignment:view',$context)) {
            continue;
        }

        // create instance of assignment class to get 
        // submitted assignments
        $onlineinstance = new assignment_online( $assignment->coursemodule );
        $submitted = $onlineinstance->submittedlink(true);
        $submission = $onlineinstance->get_submission();

        // submission (if there is one)
        if (empty($submission)) {
            $submissiontext = $str->emptysubmission;
            if (!empty($assignment->timedue)) {
                $submissiondate = "{$str->duedate} ".userdate( $assignment->timedue );

            } else {
                $submissiondate = $str->duedateno;
            }

        } else {
            $submissiontext = format_text( $submission->data1, $submission->data2 );
            $submissiondate  = "{$str->submitted} ".userdate( $submission->timemodified );
        }

        // edit link
        $editlink = "<a href=\"{$CFG->wwwroot}/mod/assignment/view.php?".
            "id={$assignment->coursemodule}&amp;edit=1\">{$str->editmysubmission}</a>";

        // format options for description
        $formatoptions = new stdClass;
        $formatoptions->noclean = true;

        // object to hold display data for assignment
        $view = new stdClass;

        // start to build view object
        if (!empty($courseformat)) {
            $view->section = "$courseformat {$assignment->section}";
        } else {
            $view->section = '';
        }

        $view->name = $assignment->name;
        $view->submitted = $submitted;
        $view->description = format_module_intro('assignment', $assignment, $assignment->coursemodule);
        $view->editlink = $editlink;
        $view->submissiontext = $submissiontext;
        $view->submissiondate = $submissiondate;
        $view->cm = $assignment->coursemodule;

        $views[] = $view;
    }

//===================
// DISPLAY
//===================

    print_header_simple($str->assignments, "", $navigation, "", "", true, "", navmenu($course));

    foreach ($views as $view) {
        echo $OUTPUT->container_start('clearfix generalbox assignment');

        // info bit
        echo $OUTPUT->heading("$view->section - $view->name", 3, 'mdl-left');
        if (!empty($view->submitted)) {
            echo '<div class="reportlink">'.$view->submitted.'</div>';
        }

        // description part
        echo '<div class="description">'.$view->description.'</div>';
       
        //submission part
        echo $OUTPUT->container_start('generalbox submission');
        echo '<div class="submissiondate">'.$view->submissiondate.'</div>';
        echo "<p>$view->submissiontext</p>\n";
        echo "<p>$view->editlink</p>\n";
        echo $OUTPUT->container_end();

        // feedback part
        $onlineinstance = new assignment_online( $view->cm );
        $onlineinstance->view_feedback();

        echo $OUTPUT->container_end();
    }

    echo $OUTPUT->footer();
?>
