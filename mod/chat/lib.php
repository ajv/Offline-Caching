<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Library of functions and constants for module chat
 *
 * @package   mod-chat
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Include portfoliolib.php */
require_once($CFG->libdir.'/portfoliolib.php');

$CFG->chat_ajax_debug  = false;
$CFG->chat_use_cache   = false;

// The HTML head for the message window to start with (<!-- nix --> is used to get some browsers starting with output
global $CHAT_HTMLHEAD;
$CHAT_HTMLHEAD = "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.0 Transitional//EN\" \"http://www.w3.org/TR/REC-html40/loose.dtd\"><html><head></head>\n<body>\n\n".padding(200);

// The HTML head for the message window to start with (with js scrolling)
global $CHAT_HTMLHEAD_JS;
$CHAT_HTMLHEAD_JS = <<<EOD
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.0 Transitional//EN" "http://www.w3.org/TR/REC-html40/loose.dtd">
<html><head><script type="text/javascript">
//<![CDATA[
function move(){
    if (scroll_active)
        window.scroll(1,400000);
    window.setTimeout("move()",100);
}
var scroll_active = true;
move();
//]]>
</script>
</head>
<body onBlur="scroll_active = true" onFocus="scroll_active = false">
EOD;
global $CHAT_HTMLHEAD_JS;
$CHAT_HTMLHEAD_JS .= padding(200);

// The HTML code for standard empty pages (e.g. if a user was kicked out)
global $CHAT_HTMLHEAD_OUT;
$CHAT_HTMLHEAD_OUT = "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.0 Transitional//EN\" \"http://www.w3.org/TR/REC-html40/loose.dtd\"><html><head><title>You are out!</title></head><body></body></html>";

// The HTML head for the message input page
global $CHAT_HTMLHEAD_MSGINPUT;
$CHAT_HTMLHEAD_MSGINPUT = "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.0 Transitional//EN\" \"http://www.w3.org/TR/REC-html40/loose.dtd\"><html><head><title>Message Input</title></head><body>";

// The HTML code for the message input page, with JavaScript
global $CHAT_HTMLHEAD_MSGINPUT_JS;
$CHAT_HTMLHEAD_MSGINPUT_JS = <<<EOD
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.0 Transitional//EN" "http://www.w3.org/TR/REC-html40/loose.dtd">
<html>
    <head><title>Message Input</title>
    <script type="text/javascript">
    //<![CDATA[
    scroll_active = true;
    function empty_field_and_submit(){
        document.fdummy.arsc_message.value=document.f.arsc_message.value;
        document.fdummy.submit();
        document.f.arsc_message.focus();
        document.f.arsc_message.select();
        return false;
    }
    //]]>
    </script>
    </head><body OnLoad="document.f.arsc_message.focus();document.f.arsc_message.select();">;
EOD;

// Dummy data that gets output to the browser as needed, in order to make it show output
global $CHAT_DUMMY_DATA;
$CHAT_DUMMY_DATA = padding(200);

/**
 * @param int $n
 * @return string
 */
function padding($n){
    $str = '';
    for($i=0; $i<$n; $i++){
        $str.="<!-- nix -->\n";
    }
    return $str;
}

/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will create a new instance and return the id number
 * of the new instance.
 *
 * @global object
 * @param object $chat
 * @return int
 */
function chat_add_instance($chat) {
    global $DB;

    $chat->timemodified = time();

    if ($returnid = $DB->insert_record("chat", $chat)) {

        $event = NULL;
        $event->name        = $chat->name;
        $event->description = format_module_intro('chat', $chat, $chat->coursemodule);
        $event->courseid    = $chat->course;
        $event->groupid     = 0;
        $event->userid      = 0;
        $event->modulename  = 'chat';
        $event->instance    = $returnid;
        $event->eventtype   = $chat->schedule;
        $event->timestart   = $chat->chattime;
        $event->timeduration = 0;

        add_event($event);
    }

    return $returnid;
}

/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will update an existing instance with new data.
 *
 * @global object
 * @param object $chat
 * @return int
 */
function chat_update_instance($chat) {
    global $DB;

    $chat->timemodified = time();
    $chat->id = $chat->instance;


    if ($returnid = $DB->update_record("chat", $chat)) {

        $event = new object();

        if ($event->id = $DB->get_field('event', 'id', array('modulename'=>'chat', 'instance'=>$chat->id))) {

            $event->name        = $chat->name;
            $event->description = format_module_intro('chat', $chat, $chat->coursemodule);
            $event->timestart   = $chat->chattime;

            update_event($event);
        }
    }

    return $returnid;
}

/**
 * Given an ID of an instance of this module,
 * this function will permanently delete the instance
 * and any data that depends on it.
 *
 * @global object
 * @param int $id
 * @return bool
 */
function chat_delete_instance($id) {
    global $DB;


    if (! $chat = $DB->get_record('chat', array('id'=>$id))) {
        return false;
    }

    $result = true;

    # Delete any dependent records here #

    if (! $DB->delete_records('chat', array('id'=>$chat->id))) {
        $result = false;
    }
    if (! $DB->delete_records('chat_messages', array('chatid'=>$chat->id))) {
        $result = false;
    }
    if (! $DB->delete_records('chat_messages_current', array('chatid'=>$chat->id))) {
        $result = false;
    }
    if (! $DB->delete_records('chat_users', array('chatid'=>$chat->id))) {
        $result = false;
    }

    if (! $DB->delete_records('event', array('modulename'=>'chat', 'instance'=>$chat->id))) {
        $result = false;
    }

    return $result;
}

/**
 * Return a small object with summary information about what a
 * user has done with a given particular instance of this module
 * Used for user activity reports.
 * <code>
 * $return->time = the time they did it
 * $return->info = a short text description
 * </code>
 *
 * @param object $course
 * @param object $user
 * @param object $mod
 * @param object $chat
 * @return void
 */
function chat_user_outline($course, $user, $mod, $chat) {
    return NULL;
}

/**
 * Print a detailed representation of what a  user has done with
 * a given particular instance of this module, for user activity reports.
 *
 * @param object $course
 * @param object $user
 * @param object $mod
 * @param object $chat
 * @return bool
 */
function chat_user_complete($course, $user, $mod, $chat) {
    return true;
}

/**
 * Given a course and a date, prints a summary of all chat rooms past and present
 * This function is called from course/lib.php: print_recent_activity()
 *
 * @global object
 * @global object
 * @global object
 * @param object $course
 * @param array $viewfullnames
 * @param int|string $timestart Timestamp
 * @return bool
 */
function chat_print_recent_activity($course, $viewfullnames, $timestart) {
    global $CFG, $USER, $DB;

    // this is approximate only, but it is really fast ;-)
    $timeout = $CFG->chat_old_ping * 10;

    if (!$mcms = $DB->get_records_sql("SELECT cm.id, MAX(chm.timestamp) AS lasttime
                                         FROM {course_modules} cm
                                         JOIN {modules} md        ON md.id = cm.module
                                         JOIN {chat} ch           ON ch.id = cm.instance
                                         JOIN {chat_messages} chm ON chm.chatid = ch.id
                                        WHERE chm.timestamp > ? AND ch.course = ? AND md.name = 'chat'
                                     GROUP BY cm.id
                                     ORDER BY lasttime ASC", array($timestart, $course->id))) {
         return false;
    }

    $past     = array();
    $current  = array();
    $modinfo =& get_fast_modinfo($course); // reference needed because we might load the groups

    foreach ($mcms as $cmid=>$mcm) {
        if (!array_key_exists($cmid, $modinfo->cms)) {
            continue;
        }
        $cm = $modinfo->cms[$cmid];
        $cm->lasttime = $mcm->lasttime;
        if (!$modinfo->cms[$cm->id]->uservisible) {
            continue;
        }

        if (groups_get_activity_groupmode($cm) != SEPARATEGROUPS
         or has_capability('moodle/site:accessallgroups', get_context_instance(CONTEXT_MODULE, $cm->id))) {
            if ($timeout > time() - $cm->lasttime) {
                $current[] = $cm;
            } else {
                $past[] = $cm;
            }

            continue;
        }

        if (is_null($modinfo->groups)) {
            $modinfo->groups = groups_get_user_groups($course->id); // load all my groups and cache it in modinfo
        }

        // verify groups in separate mode
        if (!$mygroupids = $modinfo->groups[$cm->groupingid]) {
            continue;
        }

        // ok, last post was not for my group - we have to query db to get last message from one of my groups
        // only minor problem is that the order will not be correct
        $mygroupids = implode(',', $mygroupids);
        $cm->mygroupids = $mygroupids;

        if (!$mcm = $DB->get_record_sql("SELECT cm.id, MAX(chm.timestamp) AS lasttime
                                           FROM {course_modules} cm
                                           JOIN {chat} ch           ON ch.id = cm.instance
                                           JOIN {chat_messages_current} chm ON chm.chatid = ch.id
                                          WHERE chm.timestamp > ? AND cm.id = ? AND
                                                (chm.groupid IN ($mygroupids) OR chm.groupid = 0)
                                       GROUP BY cm.id", array($timestart, $cm->id))) {
             continue;
        }

        $cm->lasttime = $mcm->lasttime;
        if ($timeout > time() - $cm->lasttime) {
            $current[] = $cm;
        } else {
            $past[] = $cm;
        }
    }

    if (!$past and !$current) {
        return false;
    }

    $strftimerecent = get_string('strftimerecent');

    if ($past) {
        print_headline(get_string('pastchats', 'chat').':');

        foreach ($past as $cm) {
            $link = $CFG->wwwroot.'/mod/chat/view.php?id='.$cm->id;
            $date = userdate($cm->lasttime, $strftimerecent);
            echo '<div class="head"><div class="date">'.$date.'</div></div>';
            echo '<div class="info"><a href="'.$link.'">'.format_string($cm->name,true).'</a></div>';
        }
    }

    if ($current) {
        print_headline(get_string('currentchats', 'chat').':');

        $oldest = floor((time()-$CFG->chat_old_ping)/10)*10;  // better db caching

        $timeold    = time() - $CFG->chat_old_ping;
        $timeold    = floor($timeold/10)*10;  // better db caching
        $timeoldext = time() - ($CFG->chat_old_ping*10); // JSless gui_basic needs much longer timeouts
        $timeoldext = floor($timeoldext/10)*10;  // better db caching

        $params = array('timeold'=>$timeold, 'timeoldext'=>$timeoldext, 'cmid'=>$cm->id);

        $timeout = "AND (chu.version<>'basic' AND chu.lastping>:timeold) OR (chu.version='basic' AND chu.lastping>:timeoldext)";

        foreach ($current as $cm) {
            //count users first
            if (isset($cm->mygroupids)) {
                $groupselect = "AND (chu.groupid IN ({$cm->mygroupids}) OR chu.groupid = 0)";
            } else {
                $groupselect = "";
            }

            if (!$users = $DB->get_records_sql("SELECT u.id, u.firstname, u.lastname, u.email, u.picture
                                                  FROM {course_modules} cm
                                                  JOIN {chat} ch        ON ch.id = cm.instance
                                                  JOIN {chat_users} chu ON chu.chatid = ch.id
                                                  JOIN {user} u         ON u.id = chu.userid
                                                 WHERE cm.id = :cmid $timeout $groupselect
                                              GROUP BY u.id, u.firstname, u.lastname, u.email, u.picture", $params)) {
            }

            $link = $CFG->wwwroot.'/mod/chat/view.php?id='.$cm->id;
            $date = userdate($cm->lasttime, $strftimerecent);

            echo '<div class="head"><div class="date">'.$date.'</div></div>';
            echo '<div class="info"><a href="'.$link.'">'.format_string($cm->name,true).'</a></div>';
            echo '<div class="userlist">';
            if ($users) {
                echo '<ul>';
                    foreach ($users as $user) {
                        echo '<li>'.fullname($user, $viewfullnames).'</li>';
                    }
                echo '</ul>';
            }
            echo '</div>';
        }
    }

    return true;
}

/**
 * Function to be run periodically according to the moodle cron
 * This function searches for things that need to be done, such
 * as sending out mail, toggling flags etc ...
 *
 * @global object
 * @return bool
 */
function chat_cron () {
    global $DB;

    chat_update_chat_times();

    chat_delete_old_users();

    /// Delete old messages with a
    /// single SQL query.
    $subselect = "SELECT c.keepdays
                    FROM {chat} c
                   WHERE c.id = {chat_messages}.chatid";

    $sql = "DELETE
              FROM {chat_messages}
             WHERE ($subselect) > 0 AND timestamp < ( ".time()." -($subselect) * 24 * 3600)";

    $DB->execute($sql);

    $sql = "DELETE
              FROM {chat_messages_current}
             WHERE timestamp < ( ".time()." - 8 * 3600)";

    $DB->execute($sql);

    return true;
}

/**
 * Returns the users with data in one chat
 * (users with records in chat_messages, students)
 *
 * @global object
 * @param int $chatid
 * @param int $groupid
 * @return array
 */
function chat_get_participants($chatid, $groupid=0) {
    global $DB;

    $params = array('groupid'=>$groupid, 'chatid'=>$chatid);

    if ($groupid) {
        $groupselect = " AND (c.groupid=:groupid OR c.groupid='0')";
    } else {
        $groupselect = "";
    }

    //Get students
    $students = $DB->get_records_sql("SELECT DISTINCT u.id, u.id
                                        FROM {user} u, {chat_messages} c
                                       WHERE c.chatid = :chatid $groupselect
                                             AND u.id = c.userid", $params);

    //Return students array (it contains an array of unique users)
    return ($students);
}

/**
 * This standard function will check all instances of this module
 * and make sure there are up-to-date events created for each of them.
 * If courseid = 0, then every chat event in the site is checked, else
 * only chat events belonging to the course specified are checked.
 * This function is used, in its new format, by restore_refresh_events()
 *
 * @global object
 * @param int $courseid
 * @return bool
 */
function chat_refresh_events($courseid = 0) {
    global $DB;

    if ($courseid) {
        if (! $chats = $DB->get_records("chat", array("course"=>$courseid))) {
            return true;
        }
    } else {
        if (! $chats = $DB->get_records("chat")) {
            return true;
        }
    }
    $moduleid = $DB->get_field('modules', 'id', array('name'=>'chat'));

    foreach ($chats as $chat) {
        $cm = get_coursemodule_from_id('chat', $chat->id);
        $event = new object();
        $event->name        = $chat->name;
        $event->description = format_module_intro('chat', $chat, $cm->id);
        $event->timestart   = $chat->chattime;

        if ($event->id = $DB->get_field('event', 'id', array('modulename'=>'chat', 'instance'=>$chat->id))) {
            update_event($event);

        } else {
            $event->courseid    = $chat->course;
            $event->groupid     = 0;
            $event->userid      = 0;
            $event->modulename  = 'chat';
            $event->instance    = $chat->id;
            $event->eventtype   = $chat->schedule;
            $event->timeduration = 0;
            $event->visible     = $DB->get_field('course_modules', 'visible', array('module'=>$moduleid, 'instance'=>$chat->id));

            add_event($event);
        }
    }
    return true;
}


//////////////////////////////////////////////////////////////////////
/// Functions that require some SQL

/**
 * @global object
 * @param int $chatid
 * @param int $groupid
 * @param int $groupingid
 * @return array
 */
function chat_get_users($chatid, $groupid=0, $groupingid=0) {
    global $DB;

    $params = array('chatid'=>$chatid, 'groupid'=>$groupid, 'groupingid'=>$groupingid);

    if ($groupid) {
        $groupselect = " AND (c.groupid=:groupid OR c.groupid='0')";
    } else {
        $groupselect = "";
    }

    if (!empty($CFG->enablegroupings) && !(empty($groupingid))) {
        $groupingjoin = "JOIN {groups_members} gm ON u.id = gm.userid
                         JOIN {groupings_groups} gg ON gm.groupid = gg.groupid AND gg.groupingid = :groupingid ";

    } else {
        $groupingjoin = '';
    }

    return $DB->get_records_sql("SELECT 
        DISTINCT u.id, u.firstname, u.lastname, u.picture, c.lastmessageping, c.firstping, u.imagealt 
        FROM {chat_users} c JOIN {user} u ON u.id = c.userid $groupingjoin 
        WHERE c.chatid = :chatid $groupselect
        ORDER BY c.firstping ASC", $params);
}

/**
 * @global object
 * @param int $chatid
 * @param int $groupid
 * @return array
 */
function chat_get_latest_message($chatid, $groupid=0) {
    global $DB;

    $params = array('chatid'=>$chatid, 'groupid'=>$groupid);

    if ($groupid) {
        $groupselect = "AND (groupid=:groupid OR groupid=0)";
    } else {
        $groupselect = "";
    }

    $sql = "SELECT * 
        FROM {chat_messages_current} WHERE chatid = :chatid $groupselect
        ORDER BY timestamp DESC";

    // return the lastest one message
    return $DB->get_record_sql($sql, $params, true);
}


//////////////////////////////////////////////////////////////////////
// login if not already logged in

/**
 * login if not already logged in
 *
 * @global object
 * @global object
 * @param int $chatid
 * @param string $version
 * @param int $groupid
 * @param object $course
 * @return bool|int Returns the chat users sid or false
 */
function chat_login_user($chatid, $version, $groupid, $course) {
    global $USER, $DB;

    if (($version != 'sockets') and $chatuser = $DB->get_record('chat_users', array('chatid'=>$chatid, 'userid'=>$USER->id, 'groupid'=>$groupid))) {
        // this will update logged user information
        $chatuser->version  = $version;
        $chatuser->ip       = $USER->lastip;
        $chatuser->lastping = time();
        $chatuser->lang     = current_language();

        // Sometimes $USER->lastip is not setup properly
        // during login. Update with current value if possible
        // or provide a dummy value for the db
        if (empty($chatuser->ip)) {
            $chatuser->ip = getremoteaddr();
            if (empty($chatuser->ip)) {
                $chatuser->ip = '';
            }
        }

        if (($chatuser->course != $course->id) or ($chatuser->userid != $USER->id)) {
            return false;
        }
        $DB->update_record('chat_users', $chatuser);

    } else {
        $chatuser = new object();
        $chatuser->chatid   = $chatid;
        $chatuser->userid   = $USER->id;
        $chatuser->groupid  = $groupid;
        $chatuser->version  = $version;
        $chatuser->ip       = $USER->lastip;
        $chatuser->lastping = $chatuser->firstping = $chatuser->lastmessageping = time();
        $chatuser->sid      = random_string(32);
        $chatuser->course   = $course->id; //caching - needed for current_language too
        $chatuser->lang     = current_language(); //caching - to resource intensive to find out later

        // Sometimes $USER->lastip is not setup properly
        // during login. Update with current value if possible
        // or provide a dummy value for the db
        if (empty($chatuser->ip)) {
            $chatuser->ip = getremoteaddr();
            if (empty($chatuser->ip)) {
                $chatuser->ip = '';
            }
        }


        $DB->insert_record('chat_users', $chatuser);

        if ($version == 'sockets') {
            // do not send 'enter' message, chatd will do it
        } else {
            $message = new object();
            $message->chatid    = $chatuser->chatid;
            $message->userid    = $chatuser->userid;
            $message->groupid   = $groupid;
            $message->message   = 'enter';
            $message->system    = 1;
            $message->timestamp = time();

            $DB->insert_record('chat_messages', $message);
            $DB->insert_record('chat_messages_current', $message);
        }
    }

    return $chatuser->sid;
}

/**
 * Delete the old and in the way
 *
 * @global object
 * @global object
 */
function chat_delete_old_users() {
// Delete the old and in the way
    global $CFG, $DB;

    $timeold = time() - $CFG->chat_old_ping;
    $timeoldext = time() - ($CFG->chat_old_ping*10); // JSless gui_basic needs much longer timeouts

    $query = "(version<>'basic' AND lastping<?) OR (version='basic' AND lastping<?)";
    $params = array($timeold, $timeoldext);

    if ($oldusers = $DB->get_records_select('chat_users', $query, $params) ) {
        $DB->delete_records_select('chat_users', $query, $params);
        foreach ($oldusers as $olduser) {
            $message = new object();
            $message->chatid    = $olduser->chatid;
            $message->userid    = $olduser->userid;
            $message->groupid   = $olduser->groupid;
            $message->message   = 'exit';
            $message->system    = 1;
            $message->timestamp = time();

            $DB->insert_record('chat_messages', $message);
            $DB->insert_record('chat_messages_current', $message);
        }
    }
}

/**
 * Updates chat records so that the next chat time is correct
 *
 * @global object
 * @param int $chatid
 * @return void
 */
function chat_update_chat_times($chatid=0) {
/// Updates chat records so that the next chat time is correct
    global $DB;

    $timenow = time();

    $params = array('timenow'=>$timenow, 'chatid'=>$chatid);

    if ($chatid) {
        if (!$chats[] = $DB->get_record_select("chat", "id = :chatid AND chattime <= :timenow AND schedule > 0", $params)) {
            return;
        }
    } else {
        if (!$chats = $DB->get_records_select("chat", "chattime <= :timenow AND schedule > 0", $params)) {
            return;
        }
    }

    foreach ($chats as $chat) {
        switch ($chat->schedule) {
            case 1: // Single event - turn off schedule and disable
                    $chat->chattime = 0;
                    $chat->schedule = 0;
                    break;
            case 2: // Repeat daily
                    while ($chat->chattime <= $timenow) {
                        $chat->chattime += 24 * 3600;
                    }
                    break;
            case 3: // Repeat weekly
                    while ($chat->chattime <= $timenow) {
                        $chat->chattime += 7 * 24 * 3600;
                    }
                    break;
        }
        $DB->update_record("chat", $chat);

        $event = new object();           // Update calendar too

        $cond = "modulename='chat' AND instance = :chatid AND timestart <> :chattime";
        $params = array('chattime'=>$chat->chattime, 'chatid'=>$chatid);

        if ($event->id = $DB->get_field_select('event', 'id', $cond, $params)) {
            $event->timestart   = $chat->chattime;
            update_event($event);
        }
    }
}

/**
 * @global object
 * @global object
 * @param object $message
 * @param int $courseid
 * @param object $sender
 * @param object $currentuser
 * @param string $chat_lastrow
 * @return bool|string Returns HTML or false
 */
function chat_format_message_manually($message, $courseid, $sender, $currentuser, $chat_lastrow=NULL) {
    global $CFG, $USER;

    $output = new object();
    $output->beep = false;       // by default
    $output->refreshusers = false; // by default

    // Use get_user_timezone() to find the correct timezone for displaying this message:
    // It's either the current user's timezone or else decided by some Moodle config setting
    // First, "reset" $USER->timezone (which could have been set by a previous call to here)
    // because otherwise the value for the previous $currentuser will take precedence over $CFG->timezone
    $USER->timezone = 99;
    $tz = get_user_timezone($currentuser->timezone);

    // Before formatting the message time string, set $USER->timezone to the above.
    // This will allow dst_offset_on (called by userdate) to work correctly, otherwise the
    // message times appear off because DST is not taken into account when it should be.
    $USER->timezone = $tz;
    $message->strtime = userdate($message->timestamp, get_string('strftimemessage', 'chat'), $tz);

    $message->picture = print_user_picture($sender->id, 0, $sender->picture, false, true, false);
    if ($courseid) {
        $message->picture = "<a onclick=\"window.open('$CFG->wwwroot/user/view.php?id=$sender->id&amp;course=$courseid')\" href=\"$CFG->wwwroot/user/view.php?id=$sender->id&amp;course=$courseid\">$message->picture</a>";
    }

    //Calculate the row class
    if ($chat_lastrow !== NULL) {
        $rowclass = ' class="r'.$chat_lastrow.'" ';
    } else {
        $rowclass = '';
    }

    // Start processing the message

    if(!empty($message->system)) {
        // System event
        $output->text = $message->strtime.': '.get_string('message'.$message->message, 'chat', fullname($sender));
        $output->html  = '<table class="chat-event"><tr'.$rowclass.'><td class="picture">'.$message->picture.'</td><td class="text">';
        $output->html .= '<span class="event">'.$output->text.'</span></td></tr></table>';
        $output->basic = '<dl><dt class="event">'.$message->strtime.': '.get_string('message'.$message->message, 'chat', fullname($sender)).'</dt></dl>';

        if($message->message == 'exit' or $message->message == 'enter') {
            $output->refreshusers = true; //force user panel refresh ASAP
        }
        return $output;
    }

    // It's not a system event

    $text = $message->message;

    /// Parse the text to clean and filter it

    $options = new object();
    $options->para = false;
    $text = format_text($text, FORMAT_MOODLE, $options, $courseid);

    // And now check for special cases
    $special = false;

    if (substr($text, 0, 5) == 'beep ') {
        /// It's a beep!
        $special = true;
        $beepwho = trim(substr($text, 5));

        if ($beepwho == 'all') {   // everyone
            $outinfo = $message->strtime.': '.get_string('messagebeepseveryone', 'chat', fullname($sender));
            $outmain = '';
            $output->beep = true;  // (eventually this should be set to
                                   //  to a filename uploaded by the user)

        } else if ($beepwho == $currentuser->id) {  // current user
            $outinfo = $message->strtime.': '.get_string('messagebeepsyou', 'chat', fullname($sender));
            $outmain = '';
            $output->beep = true;

        } else {  //something is not caught?
            return false;
        }
    } else if (substr($text, 0, 1) == '/') {     /// It's a user command
        // support some IRC commands
        $pattern = '#(^\/)(\w+).*#';
        preg_match($pattern, trim($text), $matches);
        $command = $matches[2];
        switch ($command){
        case 'me':
            $special = true;
            $outinfo = $message->strtime;
            $outmain = '*** <b>'.$sender->firstname.' '.substr($text, 4).'</b>';
            break;
        }
    } elseif (substr($text, 0, 2) == 'To') {
        $pattern = '#To[[:space:]](.*):(.*)#';
        preg_match($pattern, trim($text), $matches);
        $special = true;
        $outinfo = $message->strtime;
        $outmain = $sender->firstname.' '.get_string('saidto', 'chat').' <i>'.$matches[1].'</i>: '.$matches[2];
    }

    if(!$special) {
        $outinfo = $message->strtime.' '.$sender->firstname;
        $outmain = $text;
    }

    /// Format the message as a small table

    $output->text  = strip_tags($outinfo.': '.$outmain);

    $output->html  = "<table class=\"chat-message\"><tr$rowclass><td class=\"picture\" valign=\"top\">$message->picture</td><td class=\"text\">";
    $output->html .= "<span class=\"title\">$outinfo</span>";
    if ($outmain) {
        $output->html .= ": $outmain";
        $output->basic = '<dl><dt class="title">'.$outinfo.':</dt><dd class="text">'.$outmain.'</dd></dl>';
    } else {
        $output->basic = '<dl><dt class="title">'.$outinfo.'</dt></dl>';
    }
    $output->html .= "</td></tr></table>";
    return $output;
}

/**
 * @global object
 * @param object $message
 * @param int $courseid
 * @param object $currentuser
 * @param string $chat_lastrow
 * @return bool|string Returns HTML or false
 */
function chat_format_message($message, $courseid, $currentuser, $chat_lastrow=NULL) {
/// Given a message object full of information, this function
/// formats it appropriately into text and html, then
/// returns the formatted data.
    global $DB;

    static $users;     // Cache user lookups

    if (isset($users[$message->userid])) {
        $user = $users[$message->userid];
    } else if ($user = $DB->get_record('user', array('id'=>$message->userid), 'id,picture,firstname,lastname')) {
        $users[$message->userid] = $user;
    } else {
        return NULL;
    }
    return chat_format_message_manually($message, $courseid, $user, $currentuser, $chat_lastrow);
}

/**
 * @return array
 */
function chat_get_view_actions() {
    return array('view','view all','report');
}

/**
 * @return array
 */
function chat_get_post_actions() {
    return array('talk');
}

/**
 * @global object
 * @global object
 * @param array $courses
 * @param array $htmlarray Passed by reference
 */
function chat_print_overview($courses, &$htmlarray) {
    global $USER, $CFG;

    if (empty($courses) || !is_array($courses) || count($courses) == 0) {
        return array();
    }

    if (!$chats = get_all_instances_in_courses('chat',$courses)) {
        return;
    }

    $strchat = get_string('modulename', 'chat');
    $strnextsession  = get_string('nextsession', 'chat');

    foreach ($chats as $chat) {
        if ($chat->chattime and $chat->schedule) {  // A chat is scheduled
            $str = '<div class="chat overview"><div class="name">'.
                   $strchat.': <a '.($chat->visible?'':' class="dimmed"').
                   ' href="'.$CFG->wwwroot.'/mod/chat/view.php?id='.$chat->coursemodule.'">'.
                   $chat->name.'</a></div>';
            $str .= '<div class="info">'.$strnextsession.': '.userdate($chat->chattime).'</div></div>';

            if (empty($htmlarray[$chat->course]['chat'])) {
                $htmlarray[$chat->course]['chat'] = $str;
            } else {
                $htmlarray[$chat->course]['chat'] .= $str;
            }
        }
    }
}


/**
 * Implementation of the function for printing the form elements that control
 * whether the course reset functionality affects the chat.
 *
 * @param object $mform form passed by reference
 */
function chat_reset_course_form_definition(&$mform) {
    $mform->addElement('header', 'chatheader', get_string('modulenameplural', 'chat'));
    $mform->addElement('advcheckbox', 'reset_chat', get_string('removemessages','chat'));
}

/**
 * Course reset form defaults.
 *
 * @param object $course
 * @return array
 */
function chat_reset_course_form_defaults($course) {
    return array('reset_chat'=>1);
}

/**
 * Actual implementation of the rest coures functionality, delete all the
 * chat messages for course $data->courseid.
 *
 * @global object
 * @global object
 * @param object $data the data submitted from the reset course.
 * @return array status array
 */
function chat_reset_userdata($data) {
    global $CFG, $DB;

    $componentstr = get_string('modulenameplural', 'chat');
    $status = array();

    if (!empty($data->reset_chat)) {
        $chatessql = "SELECT ch.id
                        FROM {chat} ch
                       WHERE ch.course=?";
        $params = array($data->courseid);

        $DB->delete_records_select('chat_messages', "chatid IN ($chatessql)", $params);
        $DB->delete_records_select('chat_messages_current', "chatid IN ($chatessql)", $params);
        $DB->delete_records_select('chat_users', "chatid IN ($chatessql)", $params);
        $status[] = array('component'=>$componentstr, 'item'=>get_string('removemessages', 'chat'), 'error'=>false);
    }

    /// updating dates - shift may be negative too
    if ($data->timeshift) {
        shift_course_mod_dates('chat', array('chattime'), $data->timeshift, $data->courseid);
        $status[] = array('component'=>$componentstr, 'item'=>get_string('datechanged'), 'error'=>false);
    }

    return $status;
}

/**
 * Returns all other caps used in module
 *
 * @return array
 */
function chat_get_extra_capabilities() {
    return array('moodle/site:accessallgroups', 'moodle/site:viewfullnames');
}

/**
 * @package   mod-chat
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_portfolio_caller extends portfolio_module_caller_base {
    /** @var object */
    private $chat;
    /** @var int Timestamp */
    protected $start;
    /** @var int Timestamp */
    protected $end;
    /**
     * @return array
     */
    public static function expected_callbackargs() {
        return array(
            'id'    => true,
            'start' => false,
            'end'   => false,
        );
    }
    /**
     * @global object
     */
    public function load_data() {
        global $DB;

        if (!$this->cm = get_coursemodule_from_id('chat', $this->id)) {
            throw new portfolio_caller_exception('invalidid', 'chat');
        }
        $this->chat = $DB->get_record('chat', array('id' => $this->cm->instance));
        $select = 'chatid = ?';
        $params = array($this->chat->id);
        if ($this->start && $this->end) {
            $select .= ' AND timestamp >= ? AND timestamp <= ?';
            $params[] = $this->start;
            $params[] = $this->end;
        }
        $this->messages = $DB->get_records_select(
                'chat_messages',
                $select,
                $params,
                'timestamp ASC'
            );
        $select .= ' AND userid = ?';
        $params[] = $this->user->id;
        $this->participated = $DB->record_exists_select(
            'chat_messages',
            $select,
            $params
        );
    }
    /**
     * @return array
     */
    public static function supported_formats() {
        return array(PORTFOLIO_FORMAT_PLAINHTML);
    }
    /**
     *
     */
    public function expected_time() {
        return portfolio_expected_time_db(count($this->messages));
    }
    /**
     * @return string
     */
    public function get_sha1() {
        $str = '';
        ksort($this->messages);
        foreach ($this->messages as $m) {
            $str .= implode('', (array)$m);
        }
        return sha1($str);
    }

    /**
     * @return bool
     */
    public function check_permissions() {
        $context = get_context_instance(CONTEXT_MODULE, $this->cm->id);
        return has_capability('mod/chat:exportsession', $context)
            || ($this->participated
                && has_capability('mod/chat:exportparticipatedsession', $context));
    }
    
    /**
     * @todo Document this function
     */
    public function prepare_package() {
        $content = '';
        $lasttime = 0;
        $sessiongap = 5 * 60;    // 5 minutes silence means a new session
        foreach ($this->messages as $message) {  // We are walking FORWARDS through messages
            $m = clone $message; // grrrrrr - this causes the sha1 to change as chat_format_message changes what it's passed.
            $formatmessage = chat_format_message($m, null, $this->user);
            if (!isset($formatmessage->html)) {
                continue;
            }
            if (empty($lasttime) || (($message->timestamp - $lasttime) > $sessiongap)) {
                $content .= '<hr />';
                $content .= userdate($message->timestamp);
            }
            $content .= $formatmessage->html;
            $lasttime = $message->timestamp;
        }
        $content = preg_replace('/\<img[^>]*\>/', '', $content);

        $this->exporter->write_new_file($content, clean_filename($this->cm->name . '-session.html'), false);
    }

    /**
     * @return string
     */
    public static function display_name() {
        return get_string('modulename', 'chat');
    }

    /**
     * @global object
     * @return string
     */
    public function get_return_url() {
        global $CFG;

        return $CFG->wwwroot . '/mod/chat/report.php?id='
            . $this->cm->id . ((isset($this->start))
                ? '&start=' . $this->start . '&end=' . $this->end
                : '');
    }
}

/**
 * @param string $feature FEATURE_xx constant for requested feature
 * @return mixed True if module supports feature, null if doesn't know
 */
function chat_supports($feature) {
    switch($feature) {
        case FEATURE_GROUPS:                  return true;
        case FEATURE_GROUPINGS:               return true;
        case FEATURE_GROUPMEMBERSONLY:        return true;
        case FEATURE_MOD_INTRO:               return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS: return false;
        case FEATURE_GRADE_HAS_GRADE:         return false;
        case FEATURE_GRADE_OUTCOMES:          return true;

        default: return null;
    }
}
