<?php  // $Id$

    require_once('../../../config.php');
    require_once('../lib.php');

    $id      = required_param('id', PARAM_INT);
    $groupid = optional_param('groupid', 0, PARAM_INT);  // only for teachers
    $message = optional_param('message', '', PARAM_CLEAN);
    $refresh = optional_param('refresh', '', PARAM_RAW); // force refresh
    $last    = optional_param('last', 0, PARAM_INT);     // last time refresh or sending
    $newonly = optional_param('newonly', 0, PARAM_BOOL); // show only new messages

    if (!$chat = $DB->get_record('chat', array('id'=>$id))) {
        print_error('invalidid', 'chat');
    }

    if (!$course = $DB->get_record('course', array('id'=>$chat->course))) {
        print_error('invalidcourseid');
    }

    if (!$cm = get_coursemodule_from_instance('chat', $chat->id, $course->id)) {
        print_error('invalidcoursemodule');
    }

    $context = get_context_instance(CONTEXT_MODULE, $cm->id);
    require_login($course->id, false, $cm);
    require_capability('mod/chat:chat',$context);

/// Check to see if groups are being used here
     if ($groupmode = groups_get_activity_groupmode($cm)) {   // Groups are being used
        if ($groupid = groups_get_activity_group($cm)) {
            if (!$group = groups_get_group($groupid, false)) {
                print_error('invalidgroupid');
            }
            $groupname = ': '.$group->name;
        } else {
            $groupname = ': '.get_string('allparticipants');
        }
    } else {
        $groupid = 0;
        $groupname = '';
    }

    $strchat  = get_string('modulename', 'chat'); // must be before current_language() in chat_login_user() to force course language!!!
    $strchats = get_string('modulenameplural', 'chat');
    $stridle  = get_String('idle', 'chat');
    if (!$chat_sid = chat_login_user($chat->id, 'basic', $groupid, $course)) {
        print_error('cantlogin', 'chat');
    }

    if (!$chatusers = chat_get_users($chat->id, $groupid, $cm->groupingid)) {
        print_error('errornousers', 'chat');
    }

    $DB->set_field('chat_users', 'lastping', time(), array('sid'=>$chat_sid));

    if (!isset($SESSION->chatprefs)) {
        $SESSION->chatprefs = array();
    }
    if (!isset($SESSION->chatprefs[$chat->id])) {
        $SESSION->chatprefs[$chat->id] = array();
        $SESSION->chatprefs[$chat->id]['chatentered'] = time();
    }
    $chatentered = $SESSION->chatprefs[$chat->id]['chatentered'];

    $refreshedmessage = '';

    if (!empty($refresh) and data_submitted()) {
        $refreshedmessage = $message;

        chat_delete_old_users();

    } else if (empty($refresh) and data_submitted() and confirm_sesskey()) {

        if ($message!='') {
            $newmessage = new object();
            $newmessage->chatid = $chat->id;
            $newmessage->userid = $USER->id;
            $newmessage->groupid = $groupid;
            $newmessage->systrem = 0;
            $newmessage->message = $message;
            $newmessage->timestamp = time();
            $DB->insert_record('chat_messages', $newmessage);
            $DB->insert_record('chat_messages_current', $newmessage);

            $DB->set_field('chat_users', 'lastmessageping', time(), array('sid'=>$chat_sid));

            add_to_log($course->id, 'chat', 'talk', "view.php?id=$cm->id", $chat->id, $cm->id);
        }

        chat_delete_old_users();

        redirect('index.php?id='.$id.'&amp;newonly='.$newonly.'&amp;last='.$last);
    }


    print_header("$strchat: $course->shortname: ".format_string($chat->name,true)."$groupname", '', '', 'message');

    echo '<div id="mod-chat-gui_basic">';
    echo '<h1>'.get_string('participants').'</h1>';
    echo '<div id="participants"><ul>';
    foreach($chatusers as $chu) {
        echo '<li>';
        print_user_picture($chu->id, $course->id, $chu->picture, 24, false, false, '', false);
        echo '<div class="userinfo">';
        echo fullname($chu).' ';
        if ($idle = time() - $chu->lastmessageping) {
            echo '<span class="idle">'.$stridle.' '.format_time($idle).'</span>';
        } else {
            echo '<span class="idle" />';
        }
        echo '</div>';
        echo '</li>';
    }
    echo '</ul></div>';
    echo '<div id="send">';
    echo '<form id="editing" method="post" action="index.php">';

    $usehtmleditor = can_use_html_editor();
    echo '<h1><label for="message">'.get_string('sendmessage', 'message').'</label></h1>';
    echo '<div>';
    echo '<input type="text" id="message" name="message" value="'.s($refreshedmessage, true).'" size="60" />';
    echo '</div><div>';
    echo '<input type="hidden" name="id" value="'.$id.'" />';
    echo '<input type="hidden" name="groupid" value="'.$groupid.'" />';
    echo '<input type="hidden" name="last" value="'.time().'" />';
    echo '<input type="hidden" name="sesskey" value="'.sesskey().'" />';
    echo '<input type="submit" value="'.get_string('submit').'" />&nbsp;';
    echo '<input type="submit" name="refresh" value="'.get_string('refresh').'" />';
    echo '<input type="checkbox" name="newonly" id="newonly" '.($newonly?'checked="checked" ':'').'/><label for="newonly">'.get_string('newonlymsg', 'message').'</label>';
    echo '</div>';
    echo '</form>';
    echo '</div>';

    echo '<div id="messages">';
    echo '<h1>'.get_string('messages', 'chat').'</h1>';

    $allmessages = array();
    $options = new object();
    $options->para = false;
    $options->newlines = true;

    $params = array('last'=>$last, 'groupid'=>$groupid, 'chatid'=>$chat->id, 'chatentered'=>$chatentered);

    if ($newonly) {
        $lastsql = "AND timestamp > :last";
    } else {
        $lastsql = "";
    }

    $groupselect = $groupid ? "AND (groupid=:groupid OR groupid=0)" : "";

    $messages = $DB->get_records_select("chat_messages_current",
                        "chatid = :chatid AND timestamp > :chatentered $lastsql $groupselect", $params,
                        "timestamp DESC");

    if ($messages) {
        foreach ($messages as $message) {
            $allmessages[] = chat_format_message($message, $course->id, $USER);
        }
    }

    if (empty($allmessages)) {
        echo get_string('nomessagesfound', 'message');
    } else {
        foreach ($allmessages as $message) {
            echo $message->basic;
        }
    }

    echo '</div></div>';

    echo $OUTPUT->footer();



?>
