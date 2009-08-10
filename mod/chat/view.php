<?php  // $Id$

/// This page prints a particular instance of chat

    require_once(dirname(__FILE__) . '/../../config.php');
    require_once($CFG->dirroot . '/mod/chat/lib.php');

    $id   = optional_param('id', 0, PARAM_INT);
    $c    = optional_param('c', 0, PARAM_INT);
    $edit = optional_param('edit', -1, PARAM_BOOL);

    if ($id) {
        if (! $cm = get_coursemodule_from_id('chat', $id)) {
            print_error('invalidcoursemodule');
        }

        if (! $course = $DB->get_record('course', array('id'=>$cm->course))) {
            print_error('coursemisconf');
        }

        chat_update_chat_times($cm->instance);

        if (! $chat = $DB->get_record('chat', array('id'=>$cm->instance))) {
            print_error('invalidid', 'chat');
        }

    } else {
        chat_update_chat_times($c);

        if (! $chat = $DB->get_record('chat', array('id'=>$c))) {
            print_error('coursemisconf');
        }
        if (! $course = $DB->get_record('course', array('id'=>$chat->course))) {
            print_error('coursemisconf');
        }
        if (! $cm = get_coursemodule_from_instance('chat', $chat->id, $course->id)) {
            print_error('invalidcoursemodule');
        }
    }


    require_course_login($course, true, $cm);

    $context = get_context_instance(CONTEXT_MODULE, $cm->id);

    // show some info for guests
    if (isguestuser()) {
        $navigation = build_navigation('', $cm);
        print_header_simple(format_string($chat->name), '', $navigation,
                      '', '', true, '', navmenu($course, $cm));

        notice_yesno(get_string('noguests', 'chat').'<br /><br />'.get_string('liketologin'),
                get_login_url(), $CFG->wwwroot.'/course/view.php?id='.$course->id);

        echo $OUTPUT->footer();
        exit;

    }

    add_to_log($course->id, 'chat', 'view', "view.php?id=$cm->id", $chat->id, $cm->id);

// Initialize $PAGE, compute blocks
    $PAGE->set_url('mod/chat/view.php', array('id' => $cm->id));

/// Print the page header
    $strenterchat    = get_string('enterchat', 'chat');
    $stridle         = get_string('idle', 'chat');
    $strcurrentusers = get_string('currentusers', 'chat');
    $strnextsession  = get_string('nextsession', 'chat');

    if (($edit != -1) and $PAGE->user_allowed_editing()) {
        $USER->editing = $edit;
    }

    $title = $course->shortname . ': ' . format_string($chat->name);

    $buttons = '<table><tr><td>'.update_module_button($cm->id, $course->id, get_string('modulename', 'chat')).'</td>';
    if ($PAGE->user_allowed_editing() && !empty($CFG->showblocksonmodpages)) {
        $buttons .= '<td><form '.$CFG->frametarget.' method="get" action="view.php"><div>'.
                '<input type="hidden" name="id" value="'.$cm->id.'" />'.
                '<input type="hidden" name="edit" value="'.($PAGE->user_is_editing()?'off':'on').'" />'.
                '<input type="submit" value="'.get_string($PAGE->user_is_editing()?'blockseditoff':'blocksediton').'" /></div></form></td>';
    }
    $buttons .= '</tr></table>';

    $navigation = build_navigation(array(), $cm);
    print_header($title, $course->fullname, $navigation, '', '', true, $buttons, navmenu($course, $cm));

    /// Check to see if groups are being used here
    $groupmode = groups_get_activity_groupmode($cm);
    $currentgroup = groups_get_activity_group($cm, true);
    groups_print_activity_menu($cm, "view.php?id=$cm->id");

    if ($currentgroup) {
        $groupselect = " AND groupid = '$currentgroup'";
        $groupparam = "&amp;groupid=$currentgroup";
    } else {
        $groupselect = "";
        $groupparam = "";
    }

    if ($chat->studentlogs or has_capability('mod/chat:readlog',$context)) {
        if ($msg = $DB->get_records_select('chat_messages', "chatid = ? $groupselect", array($chat->id))) {
            echo '<div class="reportlink">';
            echo "<a href=\"report.php?id=$cm->id\">".
                get_string('viewreport', 'chat').'</a>';
            echo '</div>';
        }
    }


    echo $OUTPUT->heading(format_string($chat->name));

    if (has_capability('mod/chat:chat',$context)) {
        /// Print the main part of the page
        echo $OUTPUT->box_start('generalbox', 'enterlink');
        // users with screenreader set, will only see 1 link, to the manual refresh page
        // for better accessibility
        if (!empty($USER->screenreader)) {
            $chattarget = "/mod/chat/gui_basic/index.php?id=$chat->id$groupparam";
        } else {
            $chattarget = "/mod/chat/gui_$CFG->chat_method/index.php?id=$chat->id$groupparam";
        }

        echo '<p>';
        link_to_popup_window ($chattarget,
                "chat$course->id$chat->id$groupparam", "$strenterchat", 500, 700, get_string('modulename', 'chat'));
        echo '</p>';

        if ($CFG->enableajax) {
            echo '<p>';
            link_to_popup_window ("/mod/chat/gui_ajax/index.php?id=$chat->id$groupparam",
                "chat$course->id$chat->id$groupparam", get_string('ajax_gui', 'message'), 500, 700, get_string('modulename', 'chat'));
            echo '</p>';
        }

        // if user is using screen reader, then there is no need to display this link again
        if ($CFG->chat_method == 'header_js' && empty($USER->screenreader)) {
            // show frame/js-less alternative
            echo '<p>(';
            link_to_popup_window ("/mod/chat/gui_basic/index.php?id=$chat->id$groupparam",
                "chat$course->id$chat->id$groupparam", get_string('noframesjs', 'message'), 500, 700, get_string('modulename', 'chat'));
            echo ')</p>';
        }

        echo $OUTPUT->box_end();

    } else {
        echo $OUTPUT->box_start('generalbox', 'notallowenter');
        echo '<p>'.get_string('notallowenter', 'chat').'</p>';
        echo $OUTPUT->box_end();
    }

    if ($chat->chattime and $chat->schedule) {  // A chat is scheduled
        echo "<p class=\"nextchatsession\">$strnextsession: ".userdate($chat->chattime).' ('.usertimezone($USER->timezone).')</p>';
    } else {
        echo '<br />';
    }

    if ($chat->intro) {
        echo $OUTPUT->box(format_module_intro('chat', $chat, $cm->id), 'generalbox', 'intro');
    }

    chat_delete_old_users();

    if ($chatusers = chat_get_users($chat->id, $currentgroup, $cm->groupingid)) {
        $timenow = time();
        print_simple_box_start('center');
        echo $OUTPUT->heading($strcurrentusers);
        echo '<table id="chatcurrentusers">';
        foreach ($chatusers as $chatuser) {
            $lastping = $timenow - $chatuser->lastmessageping;
            echo '<tr><td class="chatuserimage">';
            echo "<a href=\"$CFG->wwwroot/user/view.php?id=$chatuser->id&amp;course=$chat->course\">";
            print_user_picture($chatuser, 0, $chatuser->picture, false, false, false);
            echo '</a></td><td class="chatuserdetails">';
            echo '<p>';
            echo fullname($chatuser).'<br />';
            echo "<span class=\"idletime\">$stridle: ".format_time($lastping)."</span>";
            echo '</p>';
            echo '</td></tr>';
        }
        echo '</table>';
        print_simple_box_end();
    }

    echo $OUTPUT->footer();

?>
