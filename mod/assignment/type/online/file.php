<?php  // $Id$

    require("../../../../config.php");
    require("../../lib.php");
    require("assignment.class.php");
 
    $id     = required_param('id', PARAM_INT);      // Course Module ID
    $userid = required_param('userid', PARAM_INT);  // User ID

    if (! $cm = get_coursemodule_from_id('assignment', $id)) {
        print_error('invalidcoursemodule');
    }

    if (! $assignment = $DB->get_record("assignment", array("id"=>$cm->instance))) {
        print_error('invalidid', 'assignment');
    }

    if (! $course = $DB->get_record("course", array("id"=>$assignment->course))) {
        print_error('coursemisconf', 'assignment');
    }

    if (! $user = $DB->get_record("user", array("id"=>$userid))) {
        print_error('usermisconf', 'assignment');
    }

    require_login($course->id, false, $cm);

    if (($USER->id != $user->id) && !has_capability('mod/assignment:grade', get_context_instance(CONTEXT_MODULE, $cm->id))) {
        print_error('cannotviewassignment', 'assignment');
    }

    if ($assignment->assignmenttype != 'online') {
        print_error('invalidtype', 'assignment');
    }

    $assignmentinstance = new assignment_online($cm->id, $assignment, $cm, $course);

    if ($submission = $assignmentinstance->get_submission($user->id)) {
        print_header(fullname($user,true).': '.$assignment->name);

        echo $OUTPUT->box_start('generalbox boxaligcenter', 'dates');
        echo '<table>';
        if ($assignment->timedue) {
            echo '<tr><td class="c0">'.get_string('duedate','assignment').':</td>';
            echo '    <td class="c1">'.userdate($assignment->timedue).'</td></tr>';
        }
        echo '<tr><td class="c0">'.get_string('lastedited').':</td>';
        echo '    <td class="c1">'.userdate($submission->timemodified);
        /// Decide what to count
            if ($CFG->assignment_itemstocount == ASSIGNMENT_COUNT_WORDS) {
                echo ' ('.get_string('numwords', '', count_words(format_text($submission->data1, $submission->data2))).')</td></tr>';
            } else if ($CFG->assignment_itemstocount == ASSIGNMENT_COUNT_LETTERS) {
                echo ' ('.get_string('numletters', '', count_letters(format_text($submission->data1, $submission->data2))).')</td></tr>';
            }
        echo '</table>';
        echo $OUTPUT->box_end();

        echo $OUTPUT->box(format_text($submission->data1, $submission->data2), 'generalbox boxaligncenter boxwidthwide');
        echo $OUTPUT->close_window_button();
        echo $OUTPUT->footer();
    } else {
        print_string('emptysubmission', 'assignment');
    }

?>
