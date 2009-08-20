<?php // $Id$

//  Remove oneself or someone else from a course, unassigning all
//  roles one might have
//
//  This will not delete any of their data from the course,
//  but will remove them from the participant list and prevent
//  any course email being sent to them.

    require_once("../config.php");
    require_once("lib.php");

    $id      = required_param('id', PARAM_INT);               //course
    $userid  = optional_param('user', 0, PARAM_INT);          //course
    $confirm = optional_param('confirm', 0, PARAM_BOOL);

    if($userid == $USER->id){
        // the rest of this code assumes $userid=0 means 
        // you are unassigning yourself, so set this for the
        // correct capabiliy checks & language later
        $userid = 0;
    }

    if (!$course = $DB->get_record('course', array('id'=>$id))) {
        print_error('invalidcourseid');
    }

    if (! $context = get_context_instance(CONTEXT_COURSE, $course->id)) {
        print_error('invalidcontext');
    }

    require_login($course->id);

    if ($course->metacourse) {
        print_error('cantunenrollfrommetacourse', '', $CFG->wwwroot.'/course/view.php?id='.$course->id);
    }

    if ($userid) {   // Unenrolling someone else
        require_capability('moodle/role:assign', $context, NULL, false);

        $roles = get_user_roles($context, $userid, false);

        // verify user may unassign all roles at course context
        foreach($roles as $role) {
            if (!user_can_assign($context, $role->roleid)) {
                print_error('cannotunassignrolefrom', '', '',
                        $role->roleid);
            }
        }

    } else {         // Unenrol yourself
        require_capability('moodle/role:unassignself', $context, NULL, false);
    }

    if (!empty($USER->access['rsw'][$context->path])) {
        print_error('cantunenrollinthisrole', '',
                    $CFG->wwwroot.'/course/view.php?id='.$course->id);
    }

    if ($confirm and confirm_sesskey()) {
        if ($userid) {
            if (! role_unassign(0, $userid, 0, $context->id)) {
                print_error("unenrolerror");
            }

            add_to_log($course->id, 'course', 'unenrol',
                    "view.php?id=$course->id", $course->id);
            redirect($CFG->wwwroot.'/user/index.php?id='.$course->id);

        } else {
            if (! role_unassign(0, $USER->id, 0, $context->id)) {
                print_error("unenrolerror");
            }

            // force a refresh of mycourses
            unset($USER->mycourses);
            add_to_log($course->id, 'course', 'unenrol',
                    "view.php?id=$course->id", $course->id);

            redirect($CFG->wwwroot);
        }
    }


    $strunenrol = get_string('unenrol');
    $navlinks = array();
    $navlinks[] = array('name' => $strunenrol, 'link' => null, 'type' => 'misc');
    $navigation = build_navigation($navlinks);

    print_header("$course->shortname: $strunenrol", $course->fullname, $navigation);

    if ($userid) {
        if (!$user = $DB->get_record('user', array('id'=>$userid))) {
            print_error('nousers');
        }
        $strunenrolsure  = get_string('unenrolsure', '', fullname($user, true));
        echo $OUTPUT->confirm($strunenrolsure, "unenrol.php?id=$id&user=$user->id&confirm=yes", $PAGE->url);
    } else {
        $strunenrolsure  = get_string('unenrolsure', '', get_string("yourself"));
        echo $OUTPUT->confirm($strunenrolsure, "unenrol.php?id=$id&confirm=yes", $PAGE->url);
    }

    echo $OUTPUT->footer();

?>
