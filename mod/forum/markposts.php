<?php // $Id$

      //  Set tracking option for the forum.

    require_once("../../config.php");
    require_once("lib.php");

    $f          = required_param('f',PARAM_INT); // The forum to mark
    $mark       = required_param('mark',PARAM_ALPHA); // Read or unread?
    $d          = optional_param('d',0,PARAM_INT); // Discussion to mark.
    $returnpage = optional_param('returnpage', 'index.php', PARAM_FILE);    // Page to return to.

    if (! $forum = $DB->get_record("forum", array("id" => $f))) {
        print_error('invalidforumid', 'forum');
    }

    if (! $course = $DB->get_record("course", array("id" => $forum->course))) {
        print_error('invalidcourseid');
    }

    if (!$cm = get_coursemodule_from_instance("forum", $forum->id, $course->id)) {
        print_error('invalidcoursemodule');
    }

    $user = $USER;

    require_course_login($course, false, $cm);

    if ($returnpage == 'index.php') {
        $returnto = forum_go_back_to($returnpage.'?id='.$course->id);
    } else {
        $returnto = forum_go_back_to($returnpage.'?f='.$forum->id);
    }

    if (isguest()) {   // Guests can't change forum
        $navigation = build_navigation('', $cm);
        print_header($course->shortname, $course->fullname, $navigation, '', '', true, "", navmenu($course, $cm));
        notice_yesno(get_string('noguesttracking', 'forum').'<br /><br />'.get_string('liketologin'),
                     get_login_url(), $returnto);
        echo $OUTPUT->footer();
        exit;
    }

    $info = new object();
    $info->name  = fullname($user);
    $info->forum = format_string($forum->name);

    if ($mark == 'read') {
        if (!empty($d)) {
            if (! $discussion = $DB->get_record('forum_discussions', array('id'=> $d, 'forum'=> $forum->id))) {
                print_error('invaliddiscussionid', 'forum');
            }

            if (forum_tp_mark_discussion_read($user, $d)) {
                add_to_log($course->id, "discussion", "mark read", "view.php?f=$forum->id", $d, $cm->id);
            }
        } else {
            // Mark all messages read in current group
            $currentgroup = groups_get_activity_group($cm);
            if(!$currentgroup) {
                // mark_forum_read requires ===false, while get_activity_group
                // may return 0
                $currentgroup=false;
            }
            if (forum_tp_mark_forum_read($user, $forum->id,$currentgroup)) {
                add_to_log($course->id, "forum", "mark read", "view.php?f=$forum->id", $forum->id, $cm->id);
            }
        }

/// FUTURE - Add ability to mark them as unread.
//    } else { // subscribe
//        if (forum_tp_start_tracking($forum->id, $user->id)) {
//            add_to_log($course->id, "forum", "mark unread", "view.php?f=$forum->id", $forum->id, $cm->id);
//            redirect($returnto, get_string("nowtracking", "forum", $info), 1);
//        } else {
//            print_error("Could not start tracking that forum", $_SERVER["HTTP_REFERER"]);
//        }
    }

    redirect($returnto);

?>
