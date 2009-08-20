<?php  // $Id$

    require('../../../../config.php');
    require('../../lib.php');
    require('assignment.class.php');

    $id     = required_param('id', PARAM_INT);      // Course Module ID
    $userid = required_param('userid', PARAM_INT);  // User ID
    $offset = optional_param('offset', 0, PARAM_INT);
    $mode   = optional_param('mode', '', PARAM_ALPHA);

    if (! $cm = get_coursemodule_from_id('assignment', $id)) {
        print_error('invalidcoursemodule');
    }

    if (! $assignment = $DB->get_record('assignment', array('id'=>$cm->instance))) {
        print_error('invalidid', 'assignment');
    }

    if (! $course = $DB->get_record('course', array('id'=>$assignment->course))) {
        print_error('coursemisconf', 'assignment');
    }

    if (! $user = $DB->get_record('user', array('id'=>$userid))) {
        print_error("invaliduserid");
    }

    require_login($course->id, false, $cm);

    if (!has_capability('mod/assignment:grade', get_context_instance(CONTEXT_MODULE, $cm->id))) {
        print_error('cannotviewassignment', 'assignment');
    }

    if ($assignment->assignmenttype != 'upload') {
        print_error('invalidtype', 'assignment');
    }

    $assignmentinstance = new assignment_upload($cm->id, $assignment, $cm, $course);

    $returnurl = "../../submissions.php?id={$assignmentinstance->cm->id}&amp;userid=$userid&amp;offset=$offset&amp;mode=single";

    if ($submission = $assignmentinstance->get_submission($user->id)
      and !empty($submission->data1)) {
        print_header(fullname($user,true).': '.$assignment->name);
        echo $OUTPUT->heading(get_string('notes', 'assignment').' - '.fullname($user,true));
        echo $OUTPUT->box(format_text($submission->data1, FORMAT_HTML), 'generalbox boxaligncenter boxwidthwide');
        if ($mode != 'single') {
            echo $OUTPUT->close_window_button();
        } else {
            echo $OUTPUT->continue_button($returnurl);
        }
        echo $OUTPUT->footer();
    } else {
        print_header(fullname($user,true).': '.$assignment->name);
        echo $OUTPUT->heading(get_string('notes', 'assignment').' - '.fullname($user,true));
        echo $OUTPUT->box(get_string('notesempty', 'assignment'), 'generalbox boxaligncenter boxwidthwide');
        if ($mode != 'single') {
            echo $OUTPUT->close_window_button();
        } else {
            echo $OUTPUT->continue_button($returnurl);
        }
        echo $OUTPUT->footer();
    }

?>
