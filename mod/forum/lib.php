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
 * @package   mod-forum
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Include required files */
require_once($CFG->libdir.'/filelib.php');
require_once($CFG->libdir.'/eventslib.php');
require_once($CFG->libdir.'/portfoliolib.php');
require_once($CFG->libdir . '/completionlib.php');

/// CONSTANTS ///////////////////////////////////////////////////////////

define('FORUM_MODE_FLATOLDEST', 1);
define('FORUM_MODE_FLATNEWEST', -1);
define('FORUM_MODE_THREADED', 2);
define('FORUM_MODE_NESTED', 3);

define('FORUM_FORCESUBSCRIBE', 1);
define('FORUM_INITIALSUBSCRIBE', 2);
define('FORUM_DISALLOWSUBSCRIBE',3);

define('FORUM_TRACKING_OFF', 0);
define('FORUM_TRACKING_OPTIONAL', 1);
define('FORUM_TRACKING_ON', 2);

define('FORUM_UNSET_POST_RATING', -999);

define ('FORUM_AGGREGATE_NONE', 0); //no ratings
define ('FORUM_AGGREGATE_AVG', 1);
define ('FORUM_AGGREGATE_COUNT', 2);
define ('FORUM_AGGREGATE_MAX', 3);
define ('FORUM_AGGREGATE_MIN', 4);
define ('FORUM_AGGREGATE_SUM', 5);

/// STANDARD FUNCTIONS ///////////////////////////////////////////////////////////

/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will create a new instance and return the id number
 * of the new instance.
 *
 * @global object
 * @global object
 * @param object $forum add forum instance (with magic quotes)
 * @return int intance id
 */
function forum_add_instance($forum) {
    global $CFG, $DB;

    $forum->timemodified = time();

    if (empty($forum->assessed)) {
        $forum->assessed = 0;
    }

    if (empty($forum->ratingtime) or empty($forum->assessed)) {
        $forum->assesstimestart  = 0;
        $forum->assesstimefinish = 0;
    }

    $forum->id = $DB->insert_record('forum', $forum);

    if ($forum->type == 'single') {  // Create related discussion.
        $discussion = new object();
        $discussion->course        = $forum->course;
        $discussion->forum         = $forum->id;
        $discussion->name          = $forum->name;
        $discussion->intro         = $forum->intro;
        $discussion->assessed      = $forum->assessed;
        $discussion->messageformat = $forum->messageformat;
        $discussion->mailnow       = false;
        $discussion->groupid       = -1;

        $message = '';

        if (! forum_add_discussion($discussion, null, $message)) {
            print_error('cannotadd', 'forum');
        }
    }

    if ($forum->forcesubscribe == FORUM_INITIALSUBSCRIBE) {
    /// all users should be subscribed initially
    /// Note: forum_get_potential_subscribers should take the forum context,
    /// but that does not exist yet, becuase the forum is only half build at this
    /// stage. However, because the forum is brand new, we know that there are
    /// no role assignments or overrides in the forum context, so using the
    /// course context gives the same list of users.
        $users = forum_get_potential_subscribers(get_context_instance(CONTEXT_COURSE, $forum->course), 0, 'u.id, u.email', '');
        foreach ($users as $user) {
            forum_subscribe($user->id, $forum->id);
        }
    }

    forum_grade_item_update($forum);

    return $forum->id;
}


/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will update an existing instance with new data.
 *
 * @global object
 * @param object $forum forum instance (with magic quotes)
 * @return bool success
 */
function forum_update_instance($forum) {
    global $DB;

    $forum->timemodified = time();
    $forum->id           = $forum->instance;

    if (empty($forum->assessed)) {
        $forum->assessed = 0;
    }

    if (empty($forum->ratingtime) or empty($forum->assessed)) {
        $forum->assesstimestart  = 0;
        $forum->assesstimefinish = 0;
    }

    $oldforum = $DB->get_record('forum', array('id'=>$forum->id));

    // MDL-3942 - if the aggregation type or scale (i.e. max grade) changes then recalculate the grades for the entire forum
    // if  scale changes - do we need to recheck the ratings, if ratings higher than scale how do we want to respond?
    // for count and sum aggregation types the grade we check to make sure they do not exceed the scale (i.e. max score) when calculating the grade
    if (($oldforum->assessed<>$forum->assessed) or ($oldforum->scale<>$forum->scale)) {
        forum_update_grades($forum); // recalculate grades for the forum
    }

    if ($forum->type == 'single') {  // Update related discussion and post.
        if (! $discussion = $DB->get_record('forum_discussions', array('forum'=>$forum->id))) {
            if ($discussions = $DB->get_records('forum_discussions', array('forum'=>$forum->id), 'timemodified ASC')) {
                notify('Warning! There is more than one discussion in this forum - using the most recent');
                $discussion = array_pop($discussions);
            } else {
                // try to recover by creating initial discussion - MDL-16262
                $discussion = new object();
                $discussion->course          = $forum->course;
                $discussion->forum           = $forum->id;
                $discussion->name            = $forum->name;
                $discussion->intro           = $forum->intro;
                $discussion->assessed        = $forum->assessed;
                $discussion->messageformat   = $forum->messageformat;
                $discussion->mailnow         = false;
                $discussion->groupid         = -1;

                forum_add_discussion($discussion, null, $message);

                if (! $discussion = $DB->get_record('forum_discussions', array('forum'=>$forum->id))) {
                    print_error('cannotadd', 'forum');
                }
            }
        }
        if (! $post = $DB->get_record('forum_posts', array('id'=>$discussion->firstpost))) {
            print_error('cannotfindfirstpost', 'forum');
        }

        $post->subject  = $forum->name;
        $post->message  = $forum->intro;
        $post->modified = $forum->timemodified;

        $DB->update_record('forum_posts', $post);
        $discussion->name = $forum->name;
        $DB->update_record('forum_discussions', $discussion);
    }

    $DB->update_record('forum', $forum);

    forum_grade_item_update($forum);

    return true;
}


/**
 * Given an ID of an instance of this module,
 * this function will permanently delete the instance
 * and any data that depends on it.
 *
 * @global object
 * @param int $id forum instance id
 * @return bool success
 */
function forum_delete_instance($id) {
    global $DB;

    if (!$forum = $DB->get_record('forum', array('id'=>$id))) {
        return false;
    }
    if (!$cm = get_coursemodule_from_instance('forum', $forum->id)) {
        return false;
    }
    if (!$course = $DB->get_record('course', array('id'=>$cm->course))) {
        return false;
    }

    $context = get_context_instance(CONTEXT_MODULE, $cm->id);

    // now get rid of all files
    $fs = get_file_storage();
    $fs->delete_area_files($context->id);

    $result = true;

    if ($discussions = $DB->get_records('forum_discussions', array('forum'=>$forum->id))) {
        foreach ($discussions as $discussion) {
            if (!forum_delete_discussion($discussion, true, $course, $cm, $forum)) {
                $result = false;
            }
        }
    }

    if (!$DB->delete_records('forum_subscriptions', array('forum'=>$forum->id))) {
        $result = false;
    }

    forum_tp_delete_read_records(-1, -1, -1, $forum->id);

    if (!$DB->delete_records('forum', array('id'=>$forum->id))) {
        $result = false;
    }

    forum_grade_item_delete($forum);

    return $result;
}


/**
 * Indicates API features that the forum supports.
 *
 * @uses FEATURE_GROUPS
 * @uses FEATURE_GROUPINGS
 * @uses FEATURE_GROUPMEMBERSONLY
 * @uses FEATURE_MOD_INTRO
 * @uses FEATURE_COMPLETION_TRACKS_VIEWS
 * @uses FEATURE_COMPLETION_HAS_RULES
 * @uses FEATURE_GRADE_HAS_GRADE
 * @uses FEATURE_GRADE_OUTCOMES
 * @param string $feature
 * @return mixed True if yes (some features may use other values)
 */
function forum_supports($feature) {
    switch($feature) {
        case FEATURE_GROUPS:                  return true;
        case FEATURE_GROUPINGS:               return true;
        case FEATURE_GROUPMEMBERSONLY:        return true;
        case FEATURE_MOD_INTRO:               return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS: return true;
        case FEATURE_COMPLETION_HAS_RULES:    return true;
        case FEATURE_GRADE_HAS_GRADE:         return true;
        case FEATURE_GRADE_OUTCOMES:          return true;

        default: return null;
    }
}


/**
 * Obtains the automatic completion state for this forum based on any conditions
 * in forum settings.
 *
 * @global object
 * @global object
 * @param object $course Course
 * @param object $cm Course-module
 * @param int $userid User ID
 * @param bool $type Type of comparison (or/and; can be used as return value if no conditions)
 * @return bool True if completed, false if not. (If no conditions, then return
 *   value depends on comparison type)
 */
function forum_get_completion_state($course,$cm,$userid,$type) {
    global $CFG,$DB;

    // Get forum details
    if (!($forum=$DB->get_record('forum',array('id'=>$cm->instance)))) {
        throw new Exception("Can't find forum {$cm->instance}");
    }

    $result=$type; // Default return value

    $postcountparams=array('userid'=>$userid,'forumid'=>$forum->id);
    $postcountsql="
SELECT
    COUNT(1)
FROM
    {forum_posts} fp
    INNER JOIN {forum_discussions} fd ON fp.discussion=fd.id
WHERE
    fp.userid=:userid AND fd.forum=:forumid";

    if ($forum->completiondiscussions) {
        $value = $forum->completiondiscussions <=
                 $DB->count_records('forum_discussions',array('forum'=>$forum->id,'userid'=>$userid));
        if ($type == COMPLETION_AND) {
            $result = $result && $value;
        } else {
            $result = $result || $value;
        }
    }
    if ($forum->completionreplies) {
        $value = $forum->completionreplies <=
                 $DB->get_field_sql( $postcountsql.' AND fp.parent<>0',$postcountparams);
        if ($type==COMPLETION_AND) {
            $result = $result && $value;
        } else {
            $result = $result || $value;
        }
    }
    if ($forum->completionposts) {
        $value = $forum->completionposts <= $DB->get_field_sql($postcountsql,$postcountparams);
        if ($type == COMPLETION_AND) {
            $result = $result && $value;
        } else {
            $result = $result || $value;
        }
    }

    return $result;
}


/**
 * Function to be run periodically according to the moodle cron
 * Finds all posts that have yet to be mailed out, and mails them
 * out to all subscribers
 *
 * @global object
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @uses CONTEXT_COURSE
 * @uses SITEID
 * @uses FORMAT_PLAIN
 * @return void
 */
function forum_cron() {
    global $CFG, $USER, $DB;

    $site = get_site();

    // all users that are subscribed to any post that needs sending
    $users = array();

    // status arrays
    $mailcount  = array();
    $errorcount = array();

    // caches
    $discussions     = array();
    $forums          = array();
    $courses         = array();
    $coursemodules   = array();
    $subscribedusers = array();


    // Posts older than 2 days will not be mailed.  This is to avoid the problem where
    // cron has not been running for a long time, and then suddenly people are flooded
    // with mail from the past few weeks or months
    $timenow   = time();
    $endtime   = $timenow - $CFG->maxeditingtime;
    $starttime = $endtime - 48 * 3600;   // Two days earlier

    if ($posts = forum_get_unmailed_posts($starttime, $endtime, $timenow)) {
        // Mark them all now as being mailed.  It's unlikely but possible there
        // might be an error later so that a post is NOT actually mailed out,
        // but since mail isn't crucial, we can accept this risk.  Doing it now
        // prevents the risk of duplicated mails, which is a worse problem.

        if (!forum_mark_old_posts_as_mailed($endtime)) {
            mtrace('Errors occurred while trying to mark some posts as being mailed.');
            return false;  // Don't continue trying to mail them, in case we are in a cron loop
        }

        // checking post validity, and adding users to loop through later
        foreach ($posts as $pid => $post) {

            $discussionid = $post->discussion;
            if (!isset($discussions[$discussionid])) {
                if ($discussion = $DB->get_record('forum_discussions', array('id'=> $post->discussion))) {
                    $discussions[$discussionid] = $discussion;
                } else {
                    mtrace('Could not find discussion '.$discussionid);
                    unset($posts[$pid]);
                    continue;
                }
            }
            $forumid = $discussions[$discussionid]->forum;
            if (!isset($forums[$forumid])) {
                if ($forum = $DB->get_record('forum', array('id' => $forumid))) {
                    $forums[$forumid] = $forum;
                } else {
                    mtrace('Could not find forum '.$forumid);
                    unset($posts[$pid]);
                    continue;
                }
            }
            $courseid = $forums[$forumid]->course;
            if (!isset($courses[$courseid])) {
                if ($course = $DB->get_record('course', array('id' => $courseid))) {
                    $courses[$courseid] = $course;
                } else {
                    mtrace('Could not find course '.$courseid);
                    unset($posts[$pid]);
                    continue;
                }
            }
            if (!isset($coursemodules[$forumid])) {
                if ($cm = get_coursemodule_from_instance('forum', $forumid, $courseid)) {
                    $coursemodules[$forumid] = $cm;
                } else {
                    mtrace('Could not course module for forum '.$forumid);
                    unset($posts[$pid]);
                    continue;
                }
            }


            // caching subscribed users of each forum
            if (!isset($subscribedusers[$forumid])) {
                $modcontext = get_context_instance(CONTEXT_MODULE, $coursemodules[$forumid]->id);
                if ($subusers = forum_subscribed_users($courses[$courseid], $forums[$forumid], 0, $modcontext)) {
                    foreach ($subusers as $postuser) {
                        // do not try to mail users with stopped email
                        if ($postuser->emailstop) {
                            if (!empty($CFG->forum_logblocked)) {
                                add_to_log(SITEID, 'forum', 'mail blocked', '', '', 0, $postuser->id);
                            }
                            continue;
                        }
                        // this user is subscribed to this forum
                        $subscribedusers[$forumid][$postuser->id] = $postuser->id;
                        // this user is a user we have to process later
                        $users[$postuser->id] = $postuser;
                    }
                    unset($subusers); // release memory
                }
            }

            $mailcount[$pid] = 0;
            $errorcount[$pid] = 0;
        }
    }

    if ($users && $posts) {

        $urlinfo = parse_url($CFG->wwwroot);
        $hostname = $urlinfo['host'];

        foreach ($users as $userto) {

            @set_time_limit(120); // terminate if processing of any account takes longer than 2 minutes

            // set this so that the capabilities are cached, and environment matches receiving user
            cron_setup_user($userto);

            mtrace('Processing user '.$userto->id);

            // init caches
            $userto->viewfullnames = array();
            $userto->canpost       = array();
            $userto->markposts     = array();
            $userto->enrolledin    = array();

            // reset the caches
            foreach ($coursemodules as $forumid=>$unused) {
                $coursemodules[$forumid]->cache       = new object();
                $coursemodules[$forumid]->cache->caps = array();
                unset($coursemodules[$forumid]->uservisible);
            }

            foreach ($posts as $pid => $post) {

                // Set up the environment for the post, discussion, forum, course
                $discussion = $discussions[$post->discussion];
                $forum      = $forums[$discussion->forum];
                $course     = $courses[$forum->course];
                $cm         =& $coursemodules[$forum->id];

                // Do some checks  to see if we can bail out now
                if (!isset($subscribedusers[$forum->id][$userto->id])) {
                    continue; // user does not subscribe to this forum
                }

                // Verify user is enrollend in course - if not do not send any email
                if (!isset($userto->enrolledin[$course->id])) {
                    $userto->enrolledin[$course->id] = has_capability('moodle/course:view', get_context_instance(CONTEXT_COURSE, $course->id));
                }
                if (!$userto->enrolledin[$course->id]) {
                    // oops - this user should not receive anything from this course
                    continue;
                }

                // Get info about the sending user
                if (array_key_exists($post->userid, $users)) { // we might know him/her already
                    $userfrom = $users[$post->userid];
                } else if ($userfrom = $DB->get_record('user', array('id' => $post->userid))) {
                    $users[$userfrom->id] = $userfrom; // fetch only once, we can add it to user list, it will be skipped anyway
                } else {
                    mtrace('Could not find user '.$post->userid);
                    continue;
                }

                // setup global $COURSE properly - needed for roles and languages
                cron_setup_user($userto, $course);

                // Fill caches
                if (!isset($userto->viewfullnames[$forum->id])) {
                    $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
                    $userto->viewfullnames[$forum->id] = has_capability('moodle/site:viewfullnames', $modcontext);
                }
                if (!isset($userto->canpost[$discussion->id])) {
                    $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
                    $userto->canpost[$discussion->id] = forum_user_can_post($forum, $discussion, $userto, $cm, $course, $modcontext);
                }
                if (!isset($userfrom->groups[$forum->id])) {
                    if (!isset($userfrom->groups)) {
                        $userfrom->groups = array();
                        $users[$userfrom->id]->groups = array();
                    }
                    $userfrom->groups[$forum->id] = groups_get_all_groups($course->id, $userfrom->id, $cm->groupingid);
                    $users[$userfrom->id]->groups[$forum->id] = $userfrom->groups[$forum->id];
                }

                // Make sure groups allow this user to see this email
                if ($discussion->groupid > 0 and $groupmode = groups_get_activity_groupmode($cm, $course)) {   // Groups are being used
                    if (!groups_group_exists($discussion->groupid)) { // Can't find group
                        continue;                           // Be safe and don't send it to anyone
                    }

                    if (!groups_is_member($discussion->groupid) and !has_capability('moodle/site:accessallgroups', $modcontext)) {
                        // do not send posts from other groups when in SEPARATEGROUPS or VISIBLEGROUPS
                        continue;
                    }
                }

                // Make sure we're allowed to see it...
                if (!forum_user_can_see_post($forum, $discussion, $post, NULL, $cm)) {
                    mtrace('user '.$userto->id. ' can not see '.$post->id);
                    continue;
                }

                // OK so we need to send the email.

                // Does the user want this post in a digest?  If so postpone it for now.
                if ($userto->maildigest > 0) {
                    // This user wants the mails to be in digest form
                    $queue = new object();
                    $queue->userid       = $userto->id;
                    $queue->discussionid = $discussion->id;
                    $queue->postid       = $post->id;
                    $queue->timemodified = $post->created;
                    $DB->insert_record('forum_queue', $queue);
                    continue;
                }


                // Prepare to actually send the post now, and build up the content

                $cleanforumname = str_replace('"', "'", strip_tags(format_string($forum->name)));

                $userfrom->customheaders = array (  // Headers to make emails easier to track
                           'Precedence: Bulk',
                           'List-Id: "'.$cleanforumname.'" <moodleforum'.$forum->id.'@'.$hostname.'>',
                           'List-Help: '.$CFG->wwwroot.'/mod/forum/view.php?f='.$forum->id,
                           'Message-ID: <moodlepost'.$post->id.'@'.$hostname.'>',
                           'In-Reply-To: <moodlepost'.$post->parent.'@'.$hostname.'>',
                           'References: <moodlepost'.$post->parent.'@'.$hostname.'>',
                           'X-Course-Id: '.$course->id,
                           'X-Course-Name: '.format_string($course->fullname, true)
                );


                $postsubject = "$course->shortname: ".format_string($post->subject,true);
                $posttext = forum_make_mail_text($course, $cm, $forum, $discussion, $post, $userfrom, $userto);
                $posthtml = forum_make_mail_html($course, $cm, $forum, $discussion, $post, $userfrom, $userto);

                // Send the post now!

                mtrace('Sending ', '');

                $eventdata = new object();
                $eventdata->component        = 'mod/forum';
                $eventdata->name             = 'posts';
                $eventdata->userfrom         = $userfrom;
                $eventdata->userto           = $userto;
                $eventdata->subject          = $postsubject;
                $eventdata->fullmessage      = $posttext;
                $eventdata->fullmessageformat = FORMAT_PLAIN;
                $eventdata->fullmessagehtml  = $posthtml;
                $eventdata->smallmessage     = '';
                if ( events_trigger('message_send', $eventdata) > 0){

                    mtrace("Error: mod/forum/cron.php: Could not send out mail for id $post->id to user $userto->id".
                         " ($userto->email) .. not trying again.");
                    add_to_log($course->id, 'forum', 'mail error', "discuss.php?d=$discussion->id#p$post->id",
                               substr(format_string($post->subject,true),0,30), $cm->id, $userto->id);
                    $errorcount[$post->id]++;
                } else if ($mailresult === 'emailstop') {
                    // should not be reached anymore - see check above
                } else {
                    $mailcount[$post->id]++;

                // Mark post as read if forum_usermarksread is set off
                    if (!$CFG->forum_usermarksread) {
                        $userto->markposts[$post->id] = $post->id;
                    }
                }

                mtrace('post '.$post->id. ': '.$post->subject);
            }

            // mark processed posts as read
            forum_tp_mark_posts_read($userto, $userto->markposts);
        }
    }

    if ($posts) {
        foreach ($posts as $post) {
            mtrace($mailcount[$post->id]." users were sent post $post->id, '$post->subject'");
            if ($errorcount[$post->id]) {
                $DB->set_field("forum_posts", "mailed", "2", array("id" => "$post->id"));
            }
        }
    }

    // release some memory
    unset($subscribedusers);
    unset($mailcount);
    unset($errorcount);

    cron_setup_user();

    $sitetimezone = $CFG->timezone;

    // Now see if there are any digest mails waiting to be sent, and if we should send them

    mtrace('Starting digest processing...');

    @set_time_limit(300); // terminate if not able to fetch all digests in 5 minutes

    if (!isset($CFG->digestmailtimelast)) {    // To catch the first time
        set_config('digestmailtimelast', 0);
    }

    $timenow = time();
    $digesttime = usergetmidnight($timenow, $sitetimezone) + ($CFG->digestmailtime * 3600);

    // Delete any really old ones (normally there shouldn't be any)
    $weekago = $timenow - (7 * 24 * 3600);
    $DB->delete_records_select('forum_queue', "timemodified < ?", array($weekago));
    mtrace ('Cleaned old digest records');

    if ($CFG->digestmailtimelast < $digesttime and $timenow > $digesttime) {

        mtrace('Sending forum digests: '.userdate($timenow, '', $sitetimezone));

        $digestposts_rs = $DB->get_recordset_select('forum_queue', "timemodified < ?", array($digesttime));

        if ($digestposts_rs->valid()) {

            // We have work to do
            $usermailcount = 0;

            //caches - reuse the those filled before too
            $discussionposts = array();
            $userdiscussions = array();

            foreach ($digestposts_rs as $digestpost) {
                if (!isset($users[$digestpost->userid])) {
                    if ($user = $DB->get_record('user', array('id' => $digestpost->userid))) {
                        $users[$digestpost->userid] = $user;
                    } else {
                        continue;
                    }
                }
                $postuser = $users[$digestpost->userid];
                if ($postuser->emailstop) {
                    if (!empty($CFG->forum_logblocked)) {
                        add_to_log(SITEID, 'forum', 'mail blocked', '', '', 0, $postuser->id);
                    }
                    continue;
                }

                if (!isset($posts[$digestpost->postid])) {
                    if ($post = $DB->get_record('forum_posts', array('id' => $digestpost->postid))) {
                        $posts[$digestpost->postid] = $post;
                    } else {
                        continue;
                    }
                }
                $discussionid = $digestpost->discussionid;
                if (!isset($discussions[$discussionid])) {
                    if ($discussion = $DB->get_record('forum_discussions', array('id' => $discussionid))) {
                        $discussions[$discussionid] = $discussion;
                    } else {
                        continue;
                    }
                }
                $forumid = $discussions[$discussionid]->forum;
                if (!isset($forums[$forumid])) {
                    if ($forum = $DB->get_record('forum', array('id' => $forumid))) {
                        $forums[$forumid] = $forum;
                    } else {
                        continue;
                    }
                }

                $courseid = $forums[$forumid]->course;
                if (!isset($courses[$courseid])) {
                    if ($course = $DB->get_record('course', array('id' => $courseid))) {
                        $courses[$courseid] = $course;
                    } else {
                        continue;
                    }
                }

                if (!isset($coursemodules[$forumid])) {
                    if ($cm = get_coursemodule_from_instance('forum', $forumid, $courseid)) {
                        $coursemodules[$forumid] = $cm;
                    } else {
                        continue;
                    }
                }
                $userdiscussions[$digestpost->userid][$digestpost->discussionid] = $digestpost->discussionid;
                $discussionposts[$digestpost->discussionid][$digestpost->postid] = $digestpost->postid;
            }
            $digestposts_rs->close(); /// Finished iteration, let's close the resultset

            // Data collected, start sending out emails to each user
            foreach ($userdiscussions as $userid => $thesediscussions) {

                @set_time_limit(120); // terminate if processing of any account takes longer than 2 minutes

                cron_setup_user();

                mtrace(get_string('processingdigest', 'forum', $userid), '... ');

                // First of all delete all the queue entries for this user
                $DB->delete_records_select('forum_queue', "userid = ? AND timemodified < ?", array($userid, $digesttime));
                $userto = $users[$userid];

                // Override the language and timezone of the "current" user, so that
                // mail is customised for the receiver.
                cron_setup_user($userto);

                // init caches
                $userto->viewfullnames = array();
                $userto->canpost       = array();
                $userto->markposts     = array();

                $postsubject = get_string('digestmailsubject', 'forum', format_string($site->shortname, true));

                $headerdata = new object();
                $headerdata->sitename = format_string($site->fullname, true);
                $headerdata->userprefs = $CFG->wwwroot.'/user/edit.php?id='.$userid.'&amp;course='.$site->id;

                $posttext = get_string('digestmailheader', 'forum', $headerdata)."\n\n";
                $headerdata->userprefs = '<a target="_blank" href="'.$headerdata->userprefs.'">'.get_string('digestmailprefs', 'forum').'</a>';

                $posthtml = "<head>";
                foreach ($CFG->stylesheets as $stylesheet) {
                    $posthtml .= '<link rel="stylesheet" type="text/css" href="'.$stylesheet.'" />'."\n";
                }
                $posthtml .= "</head>\n<body id=\"email\">\n";
                $posthtml .= '<p>'.get_string('digestmailheader', 'forum', $headerdata).'</p><br /><hr size="1" noshade="noshade" />';

                foreach ($thesediscussions as $discussionid) {

                    @set_time_limit(120);   // to be reset for each post

                    $discussion = $discussions[$discussionid];
                    $forum      = $forums[$discussion->forum];
                    $course     = $courses[$forum->course];
                    $cm         = $coursemodules[$forum->id];

                    //override language
                    cron_setup_user($userto, $course);

                    // Fill caches
                    if (!isset($userto->viewfullnames[$forum->id])) {
                        $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
                        $userto->viewfullnames[$forum->id] = has_capability('moodle/site:viewfullnames', $modcontext);
                    }
                    if (!isset($userto->canpost[$discussion->id])) {
                        $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
                        $userto->canpost[$discussion->id] = forum_user_can_post($forum, $discussion, $userto, $cm, $course, $modcontext);
                    }

                    $strforums      = get_string('forums', 'forum');
                    $canunsubscribe = ! forum_is_forcesubscribed($forum);
                    $canreply       = $userto->canpost[$discussion->id];

                    $posttext .= "\n \n";
                    $posttext .= '=====================================================================';
                    $posttext .= "\n \n";
                    $posttext .= "$course->shortname -> $strforums -> ".format_string($forum->name,true);
                    if ($discussion->name != $forum->name) {
                        $posttext  .= " -> ".format_string($discussion->name,true);
                    }
                    $posttext .= "\n";

                    $posthtml .= "<p><font face=\"sans-serif\">".
                    "<a target=\"_blank\" href=\"$CFG->wwwroot/course/view.php?id=$course->id\">$course->shortname</a> -> ".
                    "<a target=\"_blank\" href=\"$CFG->wwwroot/mod/forum/index.php?id=$course->id\">$strforums</a> -> ".
                    "<a target=\"_blank\" href=\"$CFG->wwwroot/mod/forum/view.php?f=$forum->id\">".format_string($forum->name,true)."</a>";
                    if ($discussion->name == $forum->name) {
                        $posthtml .= "</font></p>";
                    } else {
                        $posthtml .= " -> <a target=\"_blank\" href=\"$CFG->wwwroot/mod/forum/discuss.php?d=$discussion->id\">".format_string($discussion->name,true)."</a></font></p>";
                    }
                    $posthtml .= '<p>';

                    $postsarray = $discussionposts[$discussionid];
                    sort($postsarray);

                    foreach ($postsarray as $postid) {
                        $post = $posts[$postid];

                        if (array_key_exists($post->userid, $users)) { // we might know him/her already
                            $userfrom = $users[$post->userid];
                        } else if ($userfrom = $DB->get_record('user', array('id' => $post->userid))) {
                            $users[$userfrom->id] = $userfrom; // fetch only once, we can add it to user list, it will be skipped anyway
                        } else {
                            mtrace('Could not find user '.$post->userid);
                            continue;
                        }

                        if (!isset($userfrom->groups[$forum->id])) {
                            if (!isset($userfrom->groups)) {
                                $userfrom->groups = array();
                                $users[$userfrom->id]->groups = array();
                            }
                            $userfrom->groups[$forum->id] = groups_get_all_groups($course->id, $userfrom->id, $cm->groupingid);
                            $users[$userfrom->id]->groups[$forum->id] = $userfrom->groups[$forum->id];
                        }

                        $userfrom->customheaders = array ("Precedence: Bulk");

                        if ($userto->maildigest == 2) {
                            // Subjects only
                            $by = new object();
                            $by->name = fullname($userfrom);
                            $by->date = userdate($post->modified);
                            $posttext .= "\n".format_string($post->subject,true).' '.get_string("bynameondate", "forum", $by);
                            $posttext .= "\n---------------------------------------------------------------------";

                            $by->name = "<a target=\"_blank\" href=\"$CFG->wwwroot/user/view.php?id=$userfrom->id&amp;course=$course->id\">$by->name</a>";
                            $posthtml .= '<div><a target="_blank" href="'.$CFG->wwwroot.'/mod/forum/discuss.php?d='.$discussion->id.'#p'.$post->id.'">'.format_string($post->subject,true).'</a> '.get_string("bynameondate", "forum", $by).'</div>';

                        } else {
                            // The full treatment
                            $posttext .= forum_make_mail_text($course, $cm, $forum, $discussion, $post, $userfrom, $userto, true);
                            $posthtml .= forum_make_mail_post($course, $cm, $forum, $discussion, $post, $userfrom, $userto, false, $canreply, true, false);

                        // Create an array of postid's for this user to mark as read.
                            if (!$CFG->forum_usermarksread) {
                                $userto->markposts[$post->id] = $post->id;
                            }
                        }
                    }
                    if ($canunsubscribe) {
                        $posthtml .= "\n<div class='mdl-right'><font size=\"1\"><a href=\"$CFG->wwwroot/mod/forum/subscribe.php?id=$forum->id\">".get_string("unsubscribe", "forum")."</a></font></div>";
                    } else {
                        $posthtml .= "\n<div class='mdl-right'><font size=\"1\">".get_string("everyoneissubscribed", "forum")."</font></div>";
                    }
                    $posthtml .= '<hr size="1" noshade="noshade" /></p>';
                }
                $posthtml .= '</body>';

                if ($userto->mailformat != 1) {
                    // This user DOESN'T want to receive HTML
                    $posthtml = '';
                }

                $eventdata = new object();
                $eventdata->component        = 'mod/forum';
                $eventdata->name             = 'digests';
                $eventdata->userfrom         = $site->shortname;
                $eventdata->userto           = $userto;
                $eventdata->subject          = $postsubject;
                $eventdata->fullmessage      = $posttext;
                $eventdata->fullmessageformat = FORMAT_PLAIN;
                $eventdata->fullmessagehtml  = $posthtml;
                $eventdata->smallmessage     = '';
                if ( events_trigger('message_send', $eventdata) > 0){
                    mtrace("ERROR!");
                    echo "Error: mod/forum/cron.php: Could not send out digest mail to user $userto->id ($userto->email)... not trying again.\n";
                    add_to_log($course->id, 'forum', 'mail digest error', '', '', $cm->id, $userto->id);
                } else if ($mailresult === 'emailstop') {
                    // should not happen anymore - see check above
                } else {
                    mtrace("success.");
                    $usermailcount++;

                    // Mark post as read if forum_usermarksread is set off
                    forum_tp_mark_posts_read($userto, $userto->markposts);
                }
            }
        }
    /// We have finishied all digest emails, update $CFG->digestmailtimelast
        set_config('digestmailtimelast', $timenow);
    }

    cron_setup_user();

    if (!empty($usermailcount)) {
        mtrace(get_string('digestsentusers', 'forum', $usermailcount));
    }

    if (!empty($CFG->forum_lastreadclean)) {
        $timenow = time();
        if ($CFG->forum_lastreadclean + (24*3600) < $timenow) {
            set_config('forum_lastreadclean', $timenow);
            mtrace('Removing old forum read tracking info...');
            forum_tp_clean_read_records();
        }
    } else {
        set_config('forum_lastreadclean', time());
    }


    return true;
}

/**
 * Builds and returns the body of the email notification in plain text.
 *
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @param object $course
 * @param object $cm
 * @param object $forum
 * @param object $discussion
 * @param object $post
 * @param object $userfrom
 * @param object $userto
 * @param boolean $bare
 * @return string The email body in plain text format.
 */
function forum_make_mail_text($course, $cm, $forum, $discussion, $post, $userfrom, $userto, $bare = false) {
    global $CFG, $USER;

    if (!isset($userto->viewfullnames[$forum->id])) {
        $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
        $viewfullnames = has_capability('moodle/site:viewfullnames', $modcontext, $userto->id);
    } else {
        $viewfullnames = $userto->viewfullnames[$forum->id];
    }

    if (!isset($userto->canpost[$discussion->id])) {
        $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
        $canreply = forum_user_can_post($forum, $discussion, $userto, $cm, $course, $modcontext);
    } else {
        $canreply = $userto->canpost[$discussion->id];
    }

    $by = New stdClass;
    $by->name = fullname($userfrom, $viewfullnames);
    $by->date = userdate($post->modified, "", $userto->timezone);

    $strbynameondate = get_string('bynameondate', 'forum', $by);

    $strforums = get_string('forums', 'forum');

    $canunsubscribe = ! forum_is_forcesubscribed($forum);

    $posttext = '';

    if (!$bare) {
        $posttext  = "$course->shortname -> $strforums -> ".format_string($forum->name,true);

        if ($discussion->name != $forum->name) {
            $posttext  .= " -> ".format_string($discussion->name,true);
        }
    }

    $posttext .= "\n---------------------------------------------------------------------\n";
    $posttext .= format_string($post->subject,true);
    if ($bare) {
        $posttext .= " ($CFG->wwwroot/mod/forum/discuss.php?d=$discussion->id#p$post->id)";
    }
    $posttext .= "\n".$strbynameondate."\n";
    $posttext .= "---------------------------------------------------------------------\n";
    $posttext .= format_text_email($post->message, $post->messageformat);
    $posttext .= "\n\n";
    $posttext .= forum_print_attachments($post, $cm, "text");

    if (!$bare && $canreply) {
        $posttext .= "---------------------------------------------------------------------\n";
        $posttext .= get_string("postmailinfo", "forum", $course->shortname)."\n";
        $posttext .= "$CFG->wwwroot/mod/forum/post.php?reply=$post->id\n";
    }
    if (!$bare && $canunsubscribe) {
        $posttext .= "\n---------------------------------------------------------------------\n";
        $posttext .= get_string("unsubscribe", "forum");
        $posttext .= ": $CFG->wwwroot/mod/forum/subscribe.php?id=$forum->id\n";
    }

    return $posttext;
}

/**
 * Builds and returns the body of the email notification in html format.
 *
 * @global object
 * @param object $course
 * @param object $cm
 * @param object $forum
 * @param object $discussion
 * @param object $post
 * @param object $userfrom
 * @param object $userto
 * @return string The email text in HTML format
 */
function forum_make_mail_html($course, $cm, $forum, $discussion, $post, $userfrom, $userto) {
    global $CFG;

    if ($userto->mailformat != 1) {  // Needs to be HTML
        return '';
    }

    if (!isset($userto->canpost[$discussion->id])) {
        $canreply = forum_user_can_post($forum, $discussion, $userto);
    } else {
        $canreply = $userto->canpost[$discussion->id];
    }

    $strforums = get_string('forums', 'forum');
    $canunsubscribe = ! forum_is_forcesubscribed($forum);

    $posthtml = '<head>';
    foreach ($CFG->stylesheets as $stylesheet) {
        $posthtml .= '<link rel="stylesheet" type="text/css" href="'.$stylesheet.'" />'."\n";
    }
    $posthtml .= '</head>';
    $posthtml .= "\n<body id=\"email\">\n\n";

    $posthtml .= '<div class="navbar">'.
    '<a target="_blank" href="'.$CFG->wwwroot.'/course/view.php?id='.$course->id.'">'.$course->shortname.'</a> &raquo; '.
    '<a target="_blank" href="'.$CFG->wwwroot.'/mod/forum/index.php?id='.$course->id.'">'.$strforums.'</a> &raquo; '.
    '<a target="_blank" href="'.$CFG->wwwroot.'/mod/forum/view.php?f='.$forum->id.'">'.format_string($forum->name,true).'</a>';
    if ($discussion->name == $forum->name) {
        $posthtml .= '</div>';
    } else {
        $posthtml .= ' &raquo; <a target="_blank" href="'.$CFG->wwwroot.'/mod/forum/discuss.php?d='.$discussion->id.'">'.
                     format_string($discussion->name,true).'</a></div>';
    }
    $posthtml .= forum_make_mail_post($course, $cm, $forum, $discussion, $post, $userfrom, $userto, false, $canreply, true, false);

    if ($canunsubscribe) {
        $posthtml .= '<hr /><div class="mdl-align unsubscribelink">
                      <a href="'.$CFG->wwwroot.'/mod/forum/subscribe.php?id='.$forum->id.'">'.get_string('unsubscribe', 'forum').'</a>&nbsp;
                      <a href="'.$CFG->wwwroot.'/mod/forum/unsubscribeall.php">'.get_string('unsubscribeall', 'forum').'</a></div>';
    }

    $posthtml .= '</body>';

    return $posthtml;
}


/**
 *
 * @param object $course
 * @param object $user
 * @param object $mod TODO this is not used in this function, refactor
 * @param object $forum
 * @return object A standard object with 2 variables: info (number of posts for this user) and time (last modified)
 */
function forum_user_outline($course, $user, $mod, $forum) {
    if ($count = forum_count_user_posts($forum->id, $user->id)) {
        if ($count->postcount > 0) {
            $result = new object();
            $result->info = get_string("numposts", "forum", $count->postcount);
            $result->time = $count->lastpost;
            return $result;
        }
    }
    return NULL;
}


/**
 * @global object
 * @global object
 * @param object $coure
 * @param object $user
 * @param object $mod
 * @param object $forum
 */
function forum_user_complete($course, $user, $mod, $forum) {
    global $CFG,$USER;

    if ($posts = forum_get_user_posts($forum->id, $user->id)) {

        if (!$cm = get_coursemodule_from_instance('forum', $forum->id, $course->id)) {
            print_error('invalidcoursemodule');
        }
        $discussions = forum_get_user_involved_discussions($forum->id, $user->id);

        // preload all user ratings for these discussions - one query only and minimal memory
        $cm->cache->ratings = array();
        $cm->cache->myratings = array();
        if ($postratings = forum_get_all_user_ratings($user->id, $discussions)) {
            foreach ($postratings as $pr) {
                if (!isset($cm->cache->ratings[$pr->postid])) {
                    $cm->cache->ratings[$pr->postid] = array();
                }
                $cm->cache->ratings[$pr->postid][$pr->id] = $pr->rating;

                if ($pr->userid == $USER->id) {
                    $cm->cache->myratings[$pr->postid] = $pr->rating;
                }
            }
            unset($postratings);
        }

        foreach ($posts as $post) {
            if (!isset($discussions[$post->discussion])) {
                continue;
            }
            $discussion = $discussions[$post->discussion];

            $ratings = null;

            if ($forum->assessed) {
                if ($scale = make_grades_menu($forum->scale)) {
                    $ratings =new object();
                    $ratings->scale = $scale;
                    $ratings->assesstimestart = $forum->assesstimestart;
                    $ratings->assesstimefinish = $forum->assesstimefinish;
                    $ratings->allow = false;
                }
            }
            forum_print_post($post, $discussion, $forum, $cm, $course, false, false, false, $ratings);
        }
    } else {
        echo "<p>".get_string("noposts", "forum")."</p>";
    }
}






/**
 * @global object
 * @global object
 * @global object
 * @param array $courses
 * @param array $htmlarray
 */
function forum_print_overview($courses,&$htmlarray) {
    global $USER, $CFG, $DB;

    $LIKE = $DB->sql_ilike();

    if (empty($courses) || !is_array($courses) || count($courses) == 0) {
        return array();
    }

    if (!$forums = get_all_instances_in_courses('forum',$courses)) {
        return;
    }


    // get all forum logs in ONE query (much better!)
    $params = array();
    $sql = "SELECT instance,cmid,l.course,COUNT(l.id) as count FROM {log} l "
        ." JOIN {course_modules} cm ON cm.id = cmid "
        ." WHERE (";
    foreach ($courses as $course) {
        $sql .= '(l.course = ? AND l.time > ?) OR ';
        $params[] = $course->id;
        $params[] = $course->lastaccess;
    }
    $sql = substr($sql,0,-3); // take off the last OR

    $sql .= ") AND l.module = 'forum' AND action $LIKE 'add post%' "
        ." AND userid != ? GROUP BY cmid,l.course,instance";

    $params[] = $USER->id;

    if (!$new = $DB->get_records_sql($sql, $params)) {
        $new = array(); // avoid warnings
    }

    // also get all forum tracking stuff ONCE.
    $trackingforums = array();
    foreach ($forums as $forum) {
        if (forum_tp_can_track_forums($forum)) {
            $trackingforums[$forum->id] = $forum;
        }
    }

    if (count($trackingforums) > 0) {
        $cutoffdate = isset($CFG->forum_oldpostdays) ? (time() - ($CFG->forum_oldpostdays*24*60*60)) : 0;
        $sql = 'SELECT d.forum,d.course,COUNT(p.id) AS count '.
            ' FROM {forum_posts} p '.
            ' JOIN {forum_discussions} d ON p.discussion = d.id '.
            ' LEFT JOIN {forum_read} r ON r.postid = p.id AND r.userid = ? WHERE (';
        $params = array($USER->id);

        foreach ($trackingforums as $track) {
            $sql .= '(d.forum = ? AND (d.groupid = -1 OR d.groupid = 0 OR d.groupid = ?)) OR ';
            $params[] = $track->id;
            $params[] = get_current_group($track->course);
        }
        $sql = substr($sql,0,-3); // take off the last OR
        $sql .= ') AND p.modified >= ? AND r.id is NULL GROUP BY d.forum,d.course';
        $params[] = $cutoffdate;

        if (!$unread = $DB->get_records_sql($sql, $params)) {
            $unread = array();
        }
    } else {
        $unread = array();
    }

    if (empty($unread) and empty($new)) {
        return;
    }

    $strforum = get_string('modulename','forum');
    $strnumunread = get_string('overviewnumunread','forum');
    $strnumpostssince = get_string('overviewnumpostssince','forum');

    foreach ($forums as $forum) {
        $str = '';
        $count = 0;
        $thisunread = 0;
        $showunread = false;
        // either we have something from logs, or trackposts, or nothing.
        if (array_key_exists($forum->id, $new) && !empty($new[$forum->id])) {
            $count = $new[$forum->id]->count;
        }
        if (array_key_exists($forum->id,$unread)) {
            $thisunread = $unread[$forum->id]->count;
            $showunread = true;
        }
        if ($count > 0 || $thisunread > 0) {
            $str .= '<div class="overview forum"><div class="name">'.$strforum.': <a title="'.$strforum.'" href="'.$CFG->wwwroot.'/mod/forum/view.php?f='.$forum->id.'">'.
                $forum->name.'</a></div>';
            $str .= '<div class="info">';
            $str .= $count.' '.$strnumpostssince;
            if (!empty($showunread)) {
                $str .= '<br />'.$thisunread .' '.$strnumunread;
            }
            $str .= '</div></div>';
        }
        if (!empty($str)) {
            if (!array_key_exists($forum->course,$htmlarray)) {
                $htmlarray[$forum->course] = array();
            }
            if (!array_key_exists('forum',$htmlarray[$forum->course])) {
                $htmlarray[$forum->course]['forum'] = ''; // initialize, avoid warnings
            }
            $htmlarray[$forum->course]['forum'] .= $str;
        }
    }
}

/**
 * Given a course and a date, prints a summary of all the new
 * messages posted in the course since that date
 *
 * @global object
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @uses VISIBLEGROUPS
 * @param object $course
 * @param bool $viewfullnames capability
 * @param int $timestart
 * @return bool success
 */
function forum_print_recent_activity($course, $viewfullnames, $timestart) {
    global $CFG, $USER, $DB;

    // do not use log table if possible, it may be huge and is expensive to join with other tables

    if (!$posts = $DB->get_records_sql("SELECT p.*, f.type AS forumtype, d.forum, d.groupid,
                                              d.timestart, d.timeend, d.userid AS duserid,
                                              u.firstname, u.lastname, u.email, u.picture
                                         FROM {forum_posts} p
                                              JOIN {forum_discussions} d ON d.id = p.discussion
                                              JOIN {forum} f             ON f.id = d.forum
                                              JOIN {user} u              ON u.id = p.userid
                                        WHERE p.created > ? AND f.course = ?
                                     ORDER BY p.id ASC", array($timestart, $course->id))) { // order by initial posting date
         return false;
    }

    $modinfo =& get_fast_modinfo($course);

    $groupmodes = array();
    $cms    = array();

    $strftimerecent = get_string('strftimerecent');

    $printposts = array();
    foreach ($posts as $post) {
        if (!isset($modinfo->instances['forum'][$post->forum])) {
            // not visible
            continue;
        }
        $cm = $modinfo->instances['forum'][$post->forum];
        if (!$cm->uservisible) {
            continue;
        }
        $context = get_context_instance(CONTEXT_MODULE, $cm->id);

        if (!has_capability('mod/forum:viewdiscussion', $context)) {
            continue;
        }

        if (!empty($CFG->forum_enabletimedposts) and $USER->id != $post->duserid
          and (($post->timestart > 0 and $post->timestart > time()) or ($post->timeend > 0 and $post->timeend < time()))) {
            if (!has_capability('mod/forum:viewhiddentimedposts', $context)) {
                continue;
            }
        }

        $groupmode = groups_get_activity_groupmode($cm, $course);

        if ($groupmode) {
            if ($post->groupid == -1 or $groupmode == VISIBLEGROUPS or has_capability('moodle/site:accessallgroups', $context)) {
                // oki (Open discussions have groupid -1)
            } else {
                // separate mode
                if (isguestuser()) {
                    // shortcut
                    continue;
                }

                if (is_null($modinfo->groups)) {
                    $modinfo->groups = groups_get_user_groups($course->id); // load all my groups and cache it in modinfo
                }

                if (!array_key_exists($post->groupid, $modinfo->groups[0])) {
                    continue;
                }
            }
        }

        $printposts[] = $post;
    }
    unset($posts);

    if (!$printposts) {
        return false;
    }

    print_headline(get_string('newforumposts', 'forum').':', 3);
    echo "\n<ul class='unlist'>\n";

    foreach ($printposts as $post) {
        $subjectclass = empty($post->parent) ? ' bold' : '';

        echo '<li><div class="head">'.
               '<div class="date">'.userdate($post->modified, $strftimerecent).'</div>'.
               '<div class="name">'.fullname($post, $viewfullnames).'</div>'.
             '</div>';
        echo '<div class="info'.$subjectclass.'">';
        if (empty($post->parent)) {
            echo '"<a href="'.$CFG->wwwroot.'/mod/forum/discuss.php?d='.$post->discussion.'">';
        } else {
            echo '"<a href="'.$CFG->wwwroot.'/mod/forum/discuss.php?d='.$post->discussion.'&amp;parent='.$post->parent.'#p'.$post->id.'">';
        }
        $post->subject = break_up_long_words(format_string($post->subject, true));
        echo $post->subject;
        echo "</a>\"</div></li>\n";
    }

    echo "</ul>\n";

    return true;
}

/**
 * Return grade for given user or all users.
 *
 * @global object
 * @global object
 * @param object $forum
 * @param int $userid optional user id, 0 means all users
 * @return array array of grades, false if none
 */
function forum_get_user_grades($forum, $userid=0) {
    global $CFG, $DB;

    $params= array();
    if ($userid) {
        $params[] = $userid;
        $user = "AND u.id = ?";
    } else {
        $user = "";
    }

    $params[] = $forum->id;

    $aggtype = $forum->assessed;
    switch ($aggtype) {
        case FORUM_AGGREGATE_COUNT :
            $sql = "SELECT u.id, u.id AS userid, COUNT(fr.rating) AS rawgrade
                      FROM {user} u, {forum_posts} fp,
                           {forum_ratings} fr, {forum_discussions} fd
                     WHERE u.id = fp.userid AND fp.discussion = fd.id AND fr.post = fp.id
                           AND fr.userid != u.id AND fd.forum = ?
                           $user
                  GROUP BY u.id";
            break;
        case FORUM_AGGREGATE_MAX :
            $sql = "SELECT u.id, u.id AS userid, MAX(fr.rating) AS rawgrade
                      FROM {user} u, {forum_posts} fp,
                           {forum_ratings} fr, {forum_discussions} fd
                     WHERE u.id = fp.userid AND fp.discussion = fd.id AND fr.post = fp.id
                           AND fr.userid != u.id AND fd.forum = ?
                           $user
                  GROUP BY u.id";
            break;
        case FORUM_AGGREGATE_MIN :
            $sql = "SELECT u.id, u.id AS userid, MIN(fr.rating) AS rawgrade
                      FROM {user} u, {forum_posts} fp,
                           {forum_ratings} fr, {forum_discussions} fd
                     WHERE u.id = fp.userid AND fp.discussion = fd.id AND fr.post = fp.id
                           AND fr.userid != u.id AND fd.forum = ?
                           $user
                  GROUP BY u.id";
            break;
        case FORUM_AGGREGATE_SUM :
            $sql = "SELECT u.id, u.id AS userid, SUM(fr.rating) AS rawgrade
                     FROM {user} u, {forum_posts} fp,
                          {forum_ratings} fr, {forum_discussions} fd
                    WHERE u.id = fp.userid AND fp.discussion = fd.id AND fr.post = fp.id
                          AND fr.userid != u.id AND fd.forum = ?
                          $user
                 GROUP BY u.id";
            break;
        default : //avg
            $sql = "SELECT u.id, u.id AS userid, AVG(fr.rating) AS rawgrade
                      FROM {user} u, {forum_posts} fp,
                           {forum_ratings} fr, {forum_discussions} fd
                     WHERE u.id = fp.userid AND fp.discussion = fd.id AND fr.post = fp.id
                           AND fr.userid != u.id AND fd.forum = ?
                           $user
                  GROUP BY u.id";
            break;
    }

    if ($results = $DB->get_records_sql($sql, $params)) {
        // it could throw off the grading if count and sum returned a rawgrade higher than scale
        // so to prevent it we review the results and ensure that rawgrade does not exceed the scale, if it does we set rawgrade = scale (i.e. full credit)
        foreach ($results as $rid=>$result) {
            if ($forum->scale >= 0) {
                //numeric
                if ($result->rawgrade > $forum->scale) {
                    $results[$rid]->rawgrade = $forum->scale;
                }
            } else {
                //scales
                if ($scale = $DB->get_record('scale', array('id' => -$forum->scale))) {
                    $scale = explode(',', $scale->scale);
                    $max = count($scale);
                    if ($result->rawgrade > $max) {
                        $results[$rid]->rawgrade = $max;
                    }
                }
            }
        }
    }

    return $results;
}

/**
 * Update activity grades
 *
 * @global object
 * @global object
 * @param object $forum
 * @param int $userid specific user only, 0 means all
 * @param boolean $nullifnone return null if grade does not exist
 * @return void
 */
function forum_update_grades($forum, $userid=0, $nullifnone=true) {
    global $CFG, $DB;
    require_once($CFG->libdir.'/gradelib.php');

    if (!$forum->assessed) {
        forum_grade_item_update($forum);

    } else if ($grades = forum_get_user_grades($forum, $userid)) {
        forum_grade_item_update($forum, $grades);

    } else if ($userid and $nullifnone) {
        $grade = new object();
        $grade->userid   = $userid;
        $grade->rawgrade = NULL;
        forum_grade_item_update($forum, $grade);

    } else {
        forum_grade_item_update($forum);
    }
}

/**
 * Update all grades in gradebook.
 * @global object
 */
function forum_upgrade_grades() {
    global $DB;

    $sql = "SELECT COUNT('x')
              FROM {forum} f, {course_modules} cm, {modules} m
             WHERE m.name='forum' AND m.id=cm.module AND cm.instance=f.id";
    $count = $DB->count_records_sql($sql);

    $sql = "SELECT f.*, cm.idnumber AS cmidnumber, f.course AS courseid
              FROM {forum} f, {course_modules} cm, {modules} m
             WHERE m.name='forum' AND m.id=cm.module AND cm.instance=f.id";
    if ($rs = $DB->get_recordset_sql($sql)) {
        $pbar = new progress_bar('forumupgradegrades', 500, true);
        $i=0;
        foreach ($rs as $forum) {
            $i++;
            upgrade_set_timeout(60*5); // set up timeout, may also abort execution
            forum_update_grades($forum, 0, false);
            $pbar->update($i, $count, "Updating Forum grades ($i/$count).");
        }
        $rs->close();
    }
}

/**
 * Create/update grade item for given forum
 *
 * @global object
 * @uses GRADE_TYPE_NONE
 * @uses GRADE_TYPE_VALUE
 * @uses GRADE_TYPE_SCALE
 * @param object $forum object with extra cmidnumber
 * @param mixed $grades optional array/object of grade(s); 'reset' means reset grades in gradebook
 * @return int 0 if ok
 */
function forum_grade_item_update($forum, $grades=NULL) {
    global $CFG;
    if (!function_exists('grade_update')) { //workaround for buggy PHP versions
        require_once($CFG->libdir.'/gradelib.php');
    }

    $params = array('itemname'=>$forum->name, 'idnumber'=>$forum->cmidnumber);

    if (!$forum->assessed or $forum->scale == 0) {
        $params['gradetype'] = GRADE_TYPE_NONE;

    } else if ($forum->scale > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax']  = $forum->scale;
        $params['grademin']  = 0;

    } else if ($forum->scale < 0) {
        $params['gradetype'] = GRADE_TYPE_SCALE;
        $params['scaleid']   = -$forum->scale;
    }

    if ($grades  === 'reset') {
        $params['reset'] = true;
        $grades = NULL;
    }

    return grade_update('mod/forum', $forum->course, 'mod', 'forum', $forum->id, 0, $grades, $params);
}

/**
 * Delete grade item for given forum
 *
 * @global object
 * @param object $forum object
 * @return object grade_item
 */
function forum_grade_item_delete($forum) {
    global $CFG;
    require_once($CFG->libdir.'/gradelib.php');

    return grade_update('mod/forum', $forum->course, 'mod', 'forum', $forum->id, 0, NULL, array('deleted'=>1));
}


/**
 * Returns the users with data in one forum
 * (users with records in forum_subscriptions, forum_posts and forum_ratings, students)
 *
 * @global object
 * @global object
 * @param int $forumid
 * @return mixed array or false if none
 */
function forum_get_participants($forumid) {

    global $CFG, $DB;

    //Get students from forum_subscriptions
    $st_subscriptions = $DB->get_records_sql("SELECT DISTINCT u.id, u.id
                                         FROM {user} u,
                                              {forum_subscriptions} s
                                         WHERE s.forum = ? AND
                                               u.id = s.userid", array($forumid));
    //Get students from forum_posts
    $st_posts = $DB->get_records_sql("SELECT DISTINCT u.id, u.id
                                 FROM {user} u,
                                      {forum_discussions} d,
                                      {forum_posts} p
                                 WHERE d.forum = ? AND
                                       p.discussion = d.id AND
                                       u.id = p.userid", array($forumid));

    //Get students from forum_ratings
    $st_ratings = $DB->get_records_sql("SELECT DISTINCT u.id, u.id
                                   FROM {user} u,
                                        {forum_discussions} d,
                                        {forum_posts} p,
                                        {forum_ratings} r
                                   WHERE d.forum = ? AND
                                         p.discussion = d.id AND
                                         r.post = p.id AND
                                         u.id = r.userid", array($forumid));

    //Add st_posts to st_subscriptions
    if ($st_posts) {
        foreach ($st_posts as $st_post) {
            $st_subscriptions[$st_post->id] = $st_post;
        }
    }
    //Add st_ratings to st_subscriptions
    if ($st_ratings) {
        foreach ($st_ratings as $st_rating) {
            $st_subscriptions[$st_rating->id] = $st_rating;
        }
    }
    //Return st_subscriptions array (it contains an array of unique users)
    return ($st_subscriptions);
}

/**
 * This function returns if a scale is being used by one forum
 *
 * @global object
 * @param int $forumid
 * @param int $scaleid negative number
 * @return bool
 */
function forum_scale_used ($forumid,$scaleid) {
    global $DB;
    $return = false;

    $rec = $DB->get_record("forum",array("id" => "$forumid","scale" => "-$scaleid"));

    if (!empty($rec) && !empty($scaleid)) {
        $return = true;
    }

    return $return;
}

/**
 * Checks if scale is being used by any instance of forum
 *
 * This is used to find out if scale used anywhere
 *
 * @global object
 * @param $scaleid int
 * @return boolean True if the scale is used by any forum
 */
function forum_scale_used_anywhere($scaleid) {
    global $DB;
    if ($scaleid and $DB->record_exists('forum', array('scale' => -$scaleid))) {
        return true;
    } else {
        return false;
    }
}

// SQL FUNCTIONS ///////////////////////////////////////////////////////////

/**
 * Gets a post with all info ready for forum_print_post
 * Most of these joins are just to get the forum id
 *
 * @global object
 * @global object
 * @param int $postid
 * @return mixed array of posts or false
 */
function forum_get_post_full($postid) {
    global $CFG, $DB;

    return $DB->get_record_sql("SELECT p.*, d.forum, u.firstname, u.lastname, u.email, u.picture, u.imagealt
                             FROM {forum_posts} p
                                  JOIN {forum_discussions} d ON p.discussion = d.id
                                  LEFT JOIN {user} u ON p.userid = u.id
                            WHERE p.id = ?", array($postid));
}

/**
 * Gets posts with all info ready for forum_print_post
 * We pass forumid in because we always know it so no need to make a
 * complicated join to find it out.
 *
 * @global object
 * @global object
 * @return mixed array of posts or false
 */
function forum_get_discussion_posts($discussion, $sort, $forumid) {
    global $CFG, $DB;

    return $DB->get_records_sql("SELECT p.*, $forumid AS forum, u.firstname, u.lastname, u.email, u.picture, u.imagealt
                              FROM {forum_posts} p
                         LEFT JOIN {user} u ON p.userid = u.id
                             WHERE p.discussion = ?
                               AND p.parent > 0 $sort", array($discussion));
}

/**
 * Gets all posts in discussion including top parent.
 *
 * @global object
 * @global object
 * @global object
 * @param int $discussionid
 * @param string $sort
 * @param bool $tracking does user track the forum?
 * @return array of posts
 */
function forum_get_all_discussion_posts($discussionid, $sort, $tracking=false) {
    global $CFG, $DB, $USER;

    $tr_sel  = "";
    $tr_join = "";
    $params = array();

    if ($tracking) {
        $now = time();
        $cutoffdate = $now - ($CFG->forum_oldpostdays * 24 * 3600);
        $tr_sel  = ", fr.id AS postread";
        $tr_join = "LEFT JOIN {forum_read} fr ON (fr.postid = p.id AND fr.userid = ?)";
        $params[] = $USER->id;
    }

    $params[] = $discussionid;
    if (!$posts = $DB->get_records_sql("SELECT p.*, u.firstname, u.lastname, u.email, u.picture, u.imagealt $tr_sel
                                     FROM {forum_posts} p
                                          LEFT JOIN {user} u ON p.userid = u.id
                                          $tr_join
                                    WHERE p.discussion = ?
                                 ORDER BY $sort", $params)) {
        return array();
    }

    foreach ($posts as $pid=>$p) {
        if ($tracking) {
            if (forum_tp_is_post_old($p)) {
                 $posts[$pid]->postread = true;
            }
        }
        if (!$p->parent) {
            continue;
        }
        if (!isset($posts[$p->parent])) {
            continue; // parent does not exist??
        }
        if (!isset($posts[$p->parent]->children)) {
            $posts[$p->parent]->children = array();
        }
        $posts[$p->parent]->children[$pid] =& $posts[$pid];
    }

    return $posts;
}

/**
 * Gets posts with all info ready for forum_print_post
 * We pass forumid in because we always know it so no need to make a
 * complicated join to find it out.
 *
 * @global object
 * @global object
 * @param int $parent
 * @param int $forumid
 * @return array
 */
function forum_get_child_posts($parent, $forumid) {
    global $CFG, $DB;

    return $DB->get_records_sql("SELECT p.*, $forumid AS forum, u.firstname, u.lastname, u.email, u.picture, u.imagealt
                              FROM {forum_posts} p
                         LEFT JOIN {user} u ON p.userid = u.id
                             WHERE p.parent = ?
                          ORDER BY p.created ASC", array($parent));
}

/**
 * An array of forum objects that the user is allowed to read/search through.
 *
 * @global object
 * @global object
 * @global object
 * @param int $userid
 * @param int $courseid if 0, we look for forums throughout the whole site.
 * @return array of forum objects, or false if no matches
 *         Forum objects have the following attributes:
 *         id, type, course, cmid, cmvisible, cmgroupmode, accessallgroups,
 *         viewhiddentimedposts
 */
function forum_get_readable_forums($userid, $courseid=0) {

    global $CFG, $DB, $USER;
    require_once($CFG->dirroot.'/course/lib.php');

    if (!$forummod = $DB->get_record('modules', array('name' => 'forum'))) {
        print_error('notinstalled', 'forum');
    }

    if ($courseid) {
        $courses = $DB->get_records('course', array('id' => $courseid));
    } else {
        // If no course is specified, then the user can see SITE + his courses.
        // And admins can see all courses, so pass the $doanything flag enabled
        $courses1 = $DB->get_records('course', array('id' => SITEID));
        $courses2 = get_my_courses($userid, null, null, true);
        $courses = array_merge($courses1, $courses2);
    }
    if (!$courses) {
        return array();
    }

    $readableforums = array();

    foreach ($courses as $course) {

        $modinfo =& get_fast_modinfo($course);
        if (is_null($modinfo->groups)) {
            $modinfo->groups = groups_get_user_groups($course->id, $userid);
        }

        if (empty($modinfo->instances['forum'])) {
            // hmm, no forums?
            continue;
        }

        $courseforums = $DB->get_records('forum', array('course' => $course->id));

        foreach ($modinfo->instances['forum'] as $forumid => $cm) {
            if (!$cm->uservisible or !isset($courseforums[$forumid])) {
                continue;
            }
            $context = get_context_instance(CONTEXT_MODULE, $cm->id);
            $forum = $courseforums[$forumid];

            if (!has_capability('mod/forum:viewdiscussion', $context)) {
                continue;
            }

         /// group access
            if (groups_get_activity_groupmode($cm, $course) == SEPARATEGROUPS and !has_capability('moodle/site:accessallgroups', $context)) {
                if (is_null($modinfo->groups)) {
                    $modinfo->groups = groups_get_user_groups($course->id, $USER->id);
                }
                if (empty($CFG->enablegroupings)) {
                    $forum->onlygroups = $modinfo->groups[0];
                    $forum->onlygroups[] = -1;
                } else if (isset($modinfo->groups[$cm->groupingid])) {
                    $forum->onlygroups = $modinfo->groups[$cm->groupingid];
                    $forum->onlygroups[] = -1;
                } else {
                    $forum->onlygroups = array(-1);
                }
            }

        /// hidden timed discussions
            $forum->viewhiddentimedposts = true;
            if (!empty($CFG->forum_enabletimedposts)) {
                if (!has_capability('mod/forum:viewhiddentimedposts', $context)) {
                    $forum->viewhiddentimedposts = false;
                }
            }

        /// qanda access
            if ($forum->type == 'qanda'
                    && !has_capability('mod/forum:viewqandawithoutposting', $context)) {

                // We need to check whether the user has posted in the qanda forum.
                $forum->onlydiscussions = array();  // Holds discussion ids for the discussions
                                                    // the user is allowed to see in this forum.
                if ($discussionspostedin = forum_discussions_user_has_posted_in($forum->id, $USER->id)) {
                    foreach ($discussionspostedin as $d) {
                        $forum->onlydiscussions[] = $d->id;
                    }
                }
            }

            $readableforums[$forum->id] = $forum;
        }

        unset($modinfo);

    } // End foreach $courses

    //print_object($courses);
    //print_object($readableforums);

    return $readableforums;
}

/**
 * Returns a list of posts found using an array of search terms.
 *
 * @global object
 * @global object
 * @global object
 * @param array $searchterms array of search terms, e.g. word +word -word
 * @param int $courseid if 0, we search through the whole site
 * @param int $limitfrom
 * @param int $limitnum
 * @param int &$totalcount
 * @param string $extrasql
 * @return array|bool Array of posts found or false
 */
function forum_search_posts($searchterms, $courseid=0, $limitfrom=0, $limitnum=50,
                            &$totalcount, $extrasql='') {
    global $CFG, $DB, $USER;
    require_once($CFG->libdir.'/searchlib.php');

    $forums = forum_get_readable_forums($USER->id, $courseid);

    if (count($forums) == 0) {
        $totalcount = 0;
        return false;
    }

    $now = round(time(), -2); // db friendly

    $fullaccess = array();
    $where = array();
    $params = array();

    foreach ($forums as $forumid => $forum) {
        $select = array();

        if (!$forum->viewhiddentimedposts) {
            $select[] = "(d.userid = :userid OR (d.timestart < : AND (d.timeend = 0 OR d.timeend > :timeend)))";
            $params = array('userid'=>$USER->id, 'timestart'=>$now, 'timeend'=>$now);
        }

        $cm = get_coursemodule_from_instance('forum', $forumid);
        $context = get_context_instance(CONTEXT_MODULE, $cm->id);

        if ($forum->type == 'qanda'
            && !has_capability('mod/forum:viewqandawithoutposting', $context)) {
            if (!empty($forum->onlydiscussions)) {
                list($discussionid_sql, $discussionid_params) = $DB->get_in_or_equal($forum->onlydiscussions, SQL_PARAMS_NAMED, 'qanda0');
                $params = array_merge($params, $discussionid_params);
                $select[] = "(d.id $discussionid_sql OR p.parent = 0)";
            } else {
                $select[] = "p.parent = 0";
            }
        }

        if (!empty($forum->onlygroups)) {
            list($groupid_sql, $groupid_params) = $DB->get_in_or_equal($forum->onlygroups, SQL_PARAMS_NAMED, 'grps0');
            $params = array_merge($params, $groupid_params);
            $select[] = "d.groupid $groupid_sql";
        }

        if ($select) {
            $selects = implode(" AND ", $select);
            $where[] = "(d.forum = :forum AND $selects)";
            $params['forum'] = $forumid;
        } else {
            $fullaccess[] = $forumid;
        }
    }

    if ($fullaccess) {
        list($fullid_sql, $fullid_params) = $DB->get_in_or_equal($fullaccess, SQL_PARAMS_NAMED, 'fula0');
        $params = array_merge($params, $fullid_params);
        $where[] = "(d.forum $fullid_sql)";
    }

    $selectdiscussion = "(".implode(" OR ", $where).")";

    $messagesearch = '';
    $searchstring = '';

    // Need to concat these back together for parser to work.
    foreach($searchterms as $searchterm){
        if ($searchstring != '') {
            $searchstring .= ' ';
        }
        $searchstring .= $searchterm;
    }

    // We need to allow quoted strings for the search. The quotes *should* be stripped
    // by the parser, but this should be examined carefully for security implications.
    $searchstring = str_replace("\\\"","\"",$searchstring);
    $parser = new search_parser();
    $lexer = new search_lexer($parser);

    if ($lexer->parse($searchstring)) {
        $parsearray = $parser->get_parsed_array();
    // Experimental feature under 1.8! MDL-8830
    // Use alternative text searches if defined
    // This feature only works under mysql until properly implemented for other DBs
    // Requires manual creation of text index for forum_posts before enabling it:
    // CREATE FULLTEXT INDEX foru_post_tix ON [prefix]forum_posts (subject, message)
    // Experimental feature under 1.8! MDL-8830
        if (!empty($CFG->forum_usetextsearches)) {
            list($messagesearch, $msparams) = search_generate_text_SQL($parsearray, 'p.message', 'p.subject',
                                                 'p.userid', 'u.id', 'u.firstname',
                                                 'u.lastname', 'p.modified', 'd.forum');
        } else {
            list($messagesearch, $msparams) = search_generate_SQL($parsearray, 'p.message', 'p.subject',
                                                 'p.userid', 'u.id', 'u.firstname',
                                                 'u.lastname', 'p.modified', 'd.forum');
        }
        $params = array_merge($params, $msparams);
    }

    $fromsql = "{forum_posts} p,
                  {forum_discussions} d,
                  {user} u";

    $selectsql = " $messagesearch
               AND p.discussion = d.id
               AND p.userid = u.id
               AND $selectdiscussion
                   $extrasql";

    $countsql = "SELECT COUNT(*)
                   FROM $fromsql
                  WHERE $selectsql";

    $searchsql = "SELECT p.*,
                         d.forum,
                         u.firstname,
                         u.lastname,
                         u.email,
                         u.picture,
                         u.imagealt
                    FROM $fromsql
                   WHERE $selectsql
                ORDER BY p.modified DESC";

    $totalcount = $DB->count_records_sql($countsql, $params);

    return $DB->get_records_sql($searchsql, $params, $limitfrom, $limitnum);
}

/**
 * Returns a list of ratings for all posts in discussion
 *
 * @global object
 * @global object
 * @param object $discussion
 * @return array of ratings or false
 */
function forum_get_all_discussion_ratings($discussion) {
    global $CFG, $DB;
    return $DB->get_records_sql("SELECT r.id, r.userid, p.id AS postid, r.rating
                              FROM {forum_ratings} r,
                                   {forum_posts} p
                             WHERE r.post = p.id AND p.discussion = ?
                             ORDER BY p.id ASC", array($discussion->id));
}

/**
 * Returns a list of ratings for one specific user for all posts in discussion
 * @global object
 * @global object
 * @param object $discussions the discussions for which we return all ratings
 * @param int $userid the user for who we return all ratings
 * @return array
 */
function forum_get_all_user_ratings($userid, $discussions) {
    global $CFG, $DB;


    foreach ($discussions as $discussion) {
     if (!isset($discussionsid)){
         $discussionsid = $discussion->id;
     }
     else {
         $discussionsid .= ",".$discussion->id;
     }
    }

    $sql = "SELECT r.id, r.userid, p.id AS postid, r.rating
                              FROM {forum_ratings} r,
                                   {forum_posts} p
                             WHERE r.post = p.id AND p.userid = :userid";


    $params = array();
    $params['userid'] = $userid;
    //postgres compability
    if (!isset($discussionsid)) {
       $sql .=" AND p.discussion IN (".$discussionsid.")";
    }
    $sql .=" ORDER BY p.id ASC";

    return $DB->get_records_sql($sql, $params);


}

/**
 * Returns a list of ratings for a particular post - sorted.
 *
 * @global object
 * @global object
 * @param int $postid
 * @param string $sort
 * @return array Array of ratings or false
 */
function forum_get_ratings($postid, $sort="u.firstname ASC") {
    global $CFG, $DB;
    return $DB->get_records_sql("SELECT u.*, r.rating, r.time
                              FROM {forum_ratings} r,
                                   {user} u
                             WHERE r.post = ?
                               AND r.userid = u.id
                             ORDER BY $sort", array($postid));
}

/**
 * Returns a list of all new posts that have not been mailed yet
 *
 * @global object
 * @global object
 * @param int $starttime posts created after this time
 * @param int $endtime posts created before this
 * @param int $now used for timed discussions only
 * @return array
 */
function forum_get_unmailed_posts($starttime, $endtime, $now=null) {
    global $CFG, $DB;

    $params = array($starttime, $endtime);
    if (!empty($CFG->forum_enabletimedposts)) {
        if (empty($now)) {
            $now = time();
        }
        $timedsql = "AND (d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?))";
        $params[] = $now;
        $params[] = $now;
    } else {
        $timedsql = "";
    }

    return $DB->get_records_sql("SELECT p.*, d.course, d.forum
                              FROM {forum_posts} p
                                   JOIN {forum_discussions} d ON d.id = p.discussion
                             WHERE p.mailed = 0
                                   AND p.created >= ?
                                   AND (p.created < ? OR p.mailnow = 1)
                                   $timedsql
                          ORDER BY p.modified ASC", $params);
}

/**
 * Marks posts before a certain time as being mailed already
 *
 * @global object
 * @global object
 * @param int $endtime
 * @param int $now Defaults to time()
 * @return bool
 */
function forum_mark_old_posts_as_mailed($endtime, $now=null) {
    global $CFG, $DB;
    if (empty($now)) {
        $now = time();
    }

    if (empty($CFG->forum_enabletimedposts)) {
        return $DB->execute("UPDATE {forum_posts}
                               SET mailed = '1'
                             WHERE (created < ? OR mailnow = 1)
                                   AND mailed = 0", array($endtime));

    } else {
        return $DB->execute("UPDATE {forum_posts}
                               SET mailed = '1'
                             WHERE discussion NOT IN (SELECT d.id
                                                        FROM {forum_discussions} d
                                                       WHERE d.timestart > ?)
                                   AND (created < ? OR mailnow = 1)
                                   AND mailed = 0", array($now, $endtime));
    }
}

/**
 * Get all the posts for a user in a forum suitable for forum_print_post
 *
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @return array
 */
function forum_get_user_posts($forumid, $userid) {
    global $CFG, $DB;

    $timedsql = "";
    $params = array($forumid, $userid);

    if (!empty($CFG->forum_enabletimedposts)) {
        $cm = get_coursemodule_from_instance('forum', $forumid);
        if (!has_capability('mod/forum:viewhiddentimedposts' , get_context_instance(CONTEXT_MODULE, $cm->id))) {
            $now = time();
            $timedsql = "AND (d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?))";
            $params[] = $now;
            $params[] = $now;
        }
    }

    return $DB->get_records_sql("SELECT p.*, d.forum, u.firstname, u.lastname, u.email, u.picture, u.imagealt
                              FROM {forum} f
                                   JOIN {forum_discussions} d ON d.forum = f.id
                                   JOIN {forum_posts} p       ON p.discussion = d.id
                                   JOIN {user} u              ON u.id = p.userid
                             WHERE f.id = ?
                                   AND p.userid = ?
                                   $timedsql
                          ORDER BY p.modified ASC", $params);
}

/**
 * Get all the discussions user participated in
 *
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @param int $forumid
 * @param int $userid
 * @return array Array or false
 */
function forum_get_user_involved_discussions($forumid, $userid) {
    global $CFG, $DB;

    $timedsql = "";
    $params = array($forumid, $userid);
    if (!empty($CFG->forum_enabletimedposts)) {
        $cm = get_coursemodule_from_instance('forum', $forumid);
        if (!has_capability('mod/forum:viewhiddentimedposts' , get_context_instance(CONTEXT_MODULE, $cm->id))) {
            $now = time();
            $timedsql = "AND (d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?))";
            $params[] = $now;
            $params[] = $now;
        }
    }

    return $DB->get_records_sql("SELECT DISTINCT d.*
                              FROM {forum} f
                                   JOIN {forum_discussions} d ON d.forum = f.id
                                   JOIN {forum_posts} p       ON p.discussion = d.id
                             WHERE f.id = ?
                                   AND p.userid = ?
                                   $timedsql", $params);
}

/**
 * Get all the posts for a user in a forum suitable for forum_print_post
 *
 * @global object
 * @global object
 * @param int $forumid
 * @param int $userid
 * @return array of counts or false
 */
function forum_count_user_posts($forumid, $userid) {
    global $CFG, $DB;

    $timedsql = "";
    $params = array($forumid, $userid);
    if (!empty($CFG->forum_enabletimedposts)) {
        $cm = get_coursemodule_from_instance('forum', $forumid);
        if (!has_capability('mod/forum:viewhiddentimedposts' , get_context_instance(CONTEXT_MODULE, $cm->id))) {
            $now = time();
            $timedsql = "AND (d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?))";
            $params[] = $now;
            $params[] = $now;
        }
    }

    return $DB->get_record_sql("SELECT COUNT(p.id) AS postcount, MAX(p.modified) AS lastpost
                             FROM {forum} f
                                  JOIN {forum_discussions} d ON d.forum = f.id
                                  JOIN {forum_posts} p       ON p.discussion = d.id
                                  JOIN {user} u              ON u.id = p.userid
                            WHERE f.id = ?
                                  AND p.userid = ?
                                  $timedsql", $params);
}

/**
 * Given a log entry, return the forum post details for it.
 *
 * @global object
 * @global object
 * @param object $log
 * @return array|null
 */
function forum_get_post_from_log($log) {
    global $CFG, $DB;

    if ($log->action == "add post") {

        return $DB->get_record_sql("SELECT p.*, f.type AS forumtype, d.forum, d.groupid,
                                           u.firstname, u.lastname, u.email, u.picture
                                 FROM {forum_discussions} d,
                                      {forum_posts} p,
                                      {forum} f,
                                      {user} u
                                WHERE p.id = ?
                                  AND d.id = p.discussion
                                  AND p.userid = u.id
                                  AND u.deleted <> '1'
                                  AND f.id = d.forum", array($log->info));


    } else if ($log->action == "add discussion") {

        return $DB->get_record_sql("SELECT p.*, f.type AS forumtype, d.forum, d.groupid,
                                           u.firstname, u.lastname, u.email, u.picture
                                 FROM {forum_discussions} d,
                                      {forum_posts} p,
                                      {forum} f,
                                      {user} u
                                WHERE d.id = ?
                                  AND d.firstpost = p.id
                                  AND p.userid = u.id
                                  AND u.deleted <> '1'
                                  AND f.id = d.forum", array($log->info));
    }
    return NULL;
}

/**
 * Given a discussion id, return the first post from the discussion
 *
 * @global object
 * @global object
 * @param int $dicsussionid
 * @return array
 */
function forum_get_firstpost_from_discussion($discussionid) {
    global $CFG, $DB;

    return $DB->get_record_sql("SELECT p.*
                             FROM {forum_discussions} d,
                                  {forum_posts} p
                            WHERE d.id = ?
                              AND d.firstpost = p.id ", array($discussionid));
}

/**
 * Returns an array of counts of replies to each discussion
 *
 * @global object
 * @global object
 * @param int $forumid
 * @param string $forumsort
 * @param int $limit
 * @param int $page
 * @param int $perpage
 * @return array
 */
function forum_count_discussion_replies($forumid, $forumsort="", $limit=-1, $page=-1, $perpage=0) {
    global $CFG, $DB;

    if ($limit > 0) {
        $limitfrom = 0;
        $limitnum  = $limit;
    } else if ($page != -1) {
        $limitfrom = $page*$perpage;
        $limitnum  = $perpage;
    } else {
        $limitfrom = 0;
        $limitnum  = 0;
    }

    if ($forumsort == "") {
        $orderby = "";
        $groupby = "";

    } else {
        $orderby = "ORDER BY $forumsort";
        $groupby = ", ".strtolower($forumsort);
        $groupby = str_replace('desc', '', $groupby);
        $groupby = str_replace('asc', '', $groupby);
    }

    if (($limitfrom == 0 and $limitnum == 0) or $forumsort == "") {
        $sql = "SELECT p.discussion, COUNT(p.id) AS replies, MAX(p.id) AS lastpostid
                  FROM {forum_posts} p
                       JOIN {forum_discussions} d ON p.discussion = d.id
                 WHERE p.parent > 0 AND d.forum = ?
              GROUP BY p.discussion";
        return $DB->get_records_sql($sql, array($forumid));

    } else {
        $sql = "SELECT p.discussion, (COUNT(p.id) - 1) AS replies, MAX(p.id) AS lastpostid
                  FROM {forum_posts} p
                       JOIN {forum_discussions} d ON p.discussion = d.id
                 WHERE d.forum = ?
              GROUP BY p.discussion $groupby
              $orderby";
        return $DB->get_records_sql("SELECT * FROM ($sql) sq", array($forumid), $limitfrom, $limitnum);
    }
}

/**
 * @global object
 * @global object
 * @global object
 * @staticvar array $cache
 * @param object $forum
 * @param object $cm
 * @param object $course
 * @return mixed
 */
function forum_count_discussions($forum, $cm, $course) {
    global $CFG, $DB, $USER;

    static $cache = array();

    $now = round(time(), -2); // db cache friendliness

    $params = array($course->id);

    if (!isset($cache[$course->id])) {
        if (!empty($CFG->forum_enabletimedposts)) {
            $timedsql = "AND d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?)";
            $params[] = $now;
            $params[] = $now;
        } else {
            $timedsql = "";
        }

        $sql = "SELECT f.id, COUNT(d.id) as dcount
                  FROM {forum} f
                       JOIN {forum_discussions} d ON d.forum = f.id
                 WHERE f.course = ?
                       $timedsql
              GROUP BY f.id";

        if ($counts = $DB->get_records_sql($sql, $params)) {
            foreach ($counts as $count) {
                $counts[$count->id] = $count->dcount;
            }
            $cache[$course->id] = $counts;
        } else {
            $cache[$course->id] = array();
        }
    }

    if (empty($cache[$course->id][$forum->id])) {
        return 0;
    }

    $groupmode = groups_get_activity_groupmode($cm, $course);

    if ($groupmode != SEPARATEGROUPS) {
        return $cache[$course->id][$forum->id];
    }

    if (has_capability('moodle/site:accessallgroups', get_context_instance(CONTEXT_MODULE, $cm->id))) {
        return $cache[$course->id][$forum->id];
    }

    require_once($CFG->dirroot.'/course/lib.php');

    $modinfo =& get_fast_modinfo($course);
    if (is_null($modinfo->groups)) {
        $modinfo->groups = groups_get_user_groups($course->id, $USER->id);
    }

    if (empty($CFG->enablegroupings)) {
        $mygroups = $modinfo->groups[0];
    } else {
        if (array_key_exists($cm->groupingid, $modinfo->groups)) {
            $mygroups = $modinfo->groups[$cm->groupingid];
        } else {
            $mygroups = false; // Will be set below
        }
    }

    // add all groups posts
    if (empty($mygroups)) {
        $mygroups = array(-1=>-1);
    } else {
        $mygroups[-1] = -1;
    }

    list($mygroups_sql, $params) = $DB->get_in_or_equal($mygroups);
    $params[] = $forum->id;

    if (!empty($CFG->forum_enabletimedposts)) {
        $timedsql = "AND d.timestart < $now AND (d.timeend = 0 OR d.timeend > $now)";
        $params[] = $now;
        $params[] = $now;
    } else {
        $timedsql = "";
    }

    $sql = "SELECT COUNT(d.id)
              FROM {forum_discussions} d
             WHERE d.groupid $mygroups_sql AND d.forum = ?
                   $timedsql";

    return $DB->get_field_sql($sql, $params);
}

/**
 * How many unrated posts are in the given discussion for a given user?
 *
 * @global object
 * @global object
 * @param int $discussionid
 * @param int $userid
 * @return mixed
 */
function forum_count_unrated_posts($discussionid, $userid) {
    global $CFG, $DB;
    if ($posts = $DB->get_record_sql("SELECT count(*) as num
                                   FROM {forum_posts}
                                  WHERE parent > 0
                                    AND discussion = ?
                                    AND userid <> ? ", array($discussionid, $userid))) {

        if ($rated = $DB->get_record_sql("SELECT count(*) as num
                                       FROM {forum_posts} p,
                                            {forum_ratings} r
                                      WHERE p.discussion = ?
                                        AND p.id = r.post
                                        AND r.userid = ?", array($discussionid, $userid))) {
            $difference = $posts->num - $rated->num;
            if ($difference > 0) {
                return $difference;
            } else {
                return 0;    // Just in case there was a counting error
            }
        } else {
            return $posts->num;
        }
    } else {
        return 0;
    }
}

/**
 * Get all discussions in a forum
 *
 * @global object
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @uses VISIBLEGROUPS
 * @param object $cm
 * @param string $forumsort
 * @param bool $fullpost
 * @param int $unused
 * @param int $limit
 * @param bool $userlastmodified
 * @param int $page
 * @param int $perpage
 * @return array
 */
function forum_get_discussions($cm, $forumsort="d.timemodified DESC", $fullpost=true, $unused=-1, $limit=-1, $userlastmodified=false, $page=-1, $perpage=0) {
    global $CFG, $DB, $USER;

    $timelimit = '';

    $modcontext = null;

    $now = round(time(), -2);
    $params = array($cm->instance);

    $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);

    if (!has_capability('mod/forum:viewdiscussion', $modcontext)) { /// User must have perms to view discussions
        return array();
    }

    if (!empty($CFG->forum_enabletimedposts)) { /// Users must fulfill timed posts

        if (!has_capability('mod/forum:viewhiddentimedposts', $modcontext)) {
            $timelimit = " AND ((d.timestart <= ? AND (d.timeend = 0 OR d.timeend > ?))";
            $params[] = $now;
            $params[] = $now;
            if (isloggedin()) {
                $timelimit .= " OR d.userid = ?";
                $params[] = $USER->id;
            }
            $timelimit .= ")";
        }
    }

    if ($limit > 0) {
        $limitfrom = 0;
        $limitnum  = $limit;
    } else if ($page != -1) {
        $limitfrom = $page*$perpage;
        $limitnum  = $perpage;
    } else {
        $limitfrom = 0;
        $limitnum  = 0;
    }

    $groupmode    = groups_get_activity_groupmode($cm);
    $currentgroup = groups_get_activity_group($cm);

    if ($groupmode) {
        if (empty($modcontext)) {
            $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
        }

        if ($groupmode == VISIBLEGROUPS or has_capability('moodle/site:accessallgroups', $modcontext)) {
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = ? OR d.groupid = -1)";
                $params[] = $currentgroup;
            } else {
                $groupselect = "";
            }

        } else {
            //seprate groups without access all
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = ? OR d.groupid = -1)";
                $params[] = $currentgroup;
            } else {
                $groupselect = "AND d.groupid = -1";
            }
        }
    } else {
        $groupselect = "";
    }


    if (empty($forumsort)) {
        $forumsort = "d.timemodified DESC";
    }
    if (empty($fullpost)) {
        $postdata = "p.id,p.subject,p.modified,p.discussion,p.userid";
    } else {
        $postdata = "p.*";
    }

    if (empty($userlastmodified)) {  // We don't need to know this
        $umfields = "";
        $umtable  = "";
    } else {
        $umfields = ", um.firstname AS umfirstname, um.lastname AS umlastname";
        $umtable  = " LEFT JOIN {user} um ON (d.usermodified = um.id)";
    }

    $sql = "SELECT $postdata, d.name, d.timemodified, d.usermodified, d.groupid, d.timestart, d.timeend,
                   u.firstname, u.lastname, u.email, u.picture, u.imagealt $umfields
              FROM {forum_discussions} d
                   JOIN {forum_posts} p ON p.discussion = d.id
                   JOIN {user} u ON p.userid = u.id
                   $umtable
             WHERE d.forum = ? AND p.parent = 0
                   $timelimit $groupselect
          ORDER BY $forumsort";
    return $DB->get_records_sql($sql, $params, $limitfrom, $limitnum);
}

/**
 *
 * @global object
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @uses VISIBLEGROUPS
 * @param object $cm
 * @return array
 */
function forum_get_discussions_unread($cm) {
    global $CFG, $DB, $USER;

    $now = round(time(), -2);
    $params = array($cutoffdate);
    $groupmode    = groups_get_activity_groupmode($cm);
    $currentgroup = groups_get_activity_group($cm);

    if ($groupmode) {
        $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);

        if ($groupmode == VISIBLEGROUPS or has_capability('moodle/site:accessallgroups', $modcontext)) {
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = ? OR d.groupid = -1)";
                $params[] = $currentgroup;
            } else {
                $groupselect = "";
            }

        } else {
            //seprate groups without access all
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = ? OR d.groupid = -1)";
                $params[] = $currentgroup;
            } else {
                $groupselect = "AND d.groupid = -1";
            }
        }
    } else {
        $groupselect = "";
    }

    $cutoffdate = $now - ($CFG->forum_oldpostdays*24*60*60);

    if (!empty($CFG->forum_enabletimedposts)) {
        $timedsql = "AND d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?)";
        $params[] = $now;
        $params[] = $now;
    } else {
        $timedsql = "";
    }

    $sql = "SELECT d.id, COUNT(p.id) AS unread
              FROM {forum_discussions} d
                   JOIN {forum_posts} p     ON p.discussion = d.id
                   LEFT JOIN {forum_read} r ON (r.postid = p.id AND r.userid = $USER->id)
             WHERE d.forum = {$cm->instance}
                   AND p.modified >= ? AND r.id is NULL
                   $groupselect
                   $timedsql
          GROUP BY d.id";
    if ($unreads = $DB->get_records_sql($sql, $params)) {
        foreach ($unreads as $unread) {
            $unreads[$unread->id] = $unread->unread;
        }
        return $unreads;
    } else {
        return array();
    }
}

/**
 * @global object
 * @global object
 * @global object
 * @uses CONEXT_MODULE
 * @uses VISIBLEGROUPS
 * @param object $cm
 * @return array
 */
function forum_get_discussions_count($cm) {
    global $CFG, $DB, $USER;

    $now = round(time(), -2);
    $params = array($cm->instance);
    $groupmode    = groups_get_activity_groupmode($cm);
    $currentgroup = groups_get_activity_group($cm);

    if ($groupmode) {
        $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);

        if ($groupmode == VISIBLEGROUPS or has_capability('moodle/site:accessallgroups', $modcontext)) {
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = ? OR d.groupid = -1)";
                $params[] = $currentgroup;
            } else {
                $groupselect = "";
            }

        } else {
            //seprate groups without access all
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = ? OR d.groupid = -1)";
                $params[] = $currentgroup;
            } else {
                $groupselect = "AND d.groupid = -1";
            }
        }
    } else {
        $groupselect = "";
    }

    $cutoffdate = $now - ($CFG->forum_oldpostdays*24*60*60);

    $timelimit = "";

    if (!empty($CFG->forum_enabletimedposts)) {

        $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);

        if (!has_capability('mod/forum:viewhiddentimedposts', $modcontext)) {
            $timelimit = " AND ((d.timestart <= ? AND (d.timeend = 0 OR d.timeend > ?))";
            $params[] = $now;
            $params[] = $now;
            if (isloggedin()) {
                $timelimit .= " OR d.userid = ?";
                $params[] = $USER->id;
            }
            $timelimit .= ")";
        }
    }

    $sql = "SELECT COUNT(d.id)
              FROM {forum_discussions} d
                   JOIN {forum_posts} p ON p.discussion = d.id
             WHERE d.forum = ? AND p.parent = 0
                   $groupselect $timelimit";

    return $DB->get_field_sql($sql, $params);
}


/**
 * Get all discussions started by a particular user in a course (or group)
 * This function no longer used ...
 *
 * @todo Remove this function if no longer used
 * @global object
 * @global object
 * @param int $courseid
 * @param int $userid
 * @param int $groupid
 * @return array
 */
function forum_get_user_discussions($courseid, $userid, $groupid=0) {
    global $CFG, $DB;
    $params = array($courseid, $userid);
    if ($groupid) {
        $groupselect = " AND d.groupid = ? ";
        $params[] = $groupid;
    } else  {
        $groupselect = "";
    }

    return $DB->get_records_sql("SELECT p.*, d.groupid, u.firstname, u.lastname, u.email, u.picture, u.imagealt,
                                   f.type as forumtype, f.name as forumname, f.id as forumid
                              FROM {forum_discussions} d,
                                   {forum_posts} p,
                                   {user} u,
                                   {forum} f
                             WHERE d.course = ?
                               AND p.discussion = d.id
                               AND p.parent = 0
                               AND p.userid = u.id
                               AND u.id = ?
                               AND d.forum = f.id $groupselect
                          ORDER BY p.created DESC", $params);
}

/**
 * Get the list of potential subscribers to a forum.
 *
 * @param object $forumcontext the forum context.
 * @param integer $groupid the id of a group, or 0 for all groups.
 * @param string $fields the list of fields to return for each user. As for get_users_by_capability.
 * @param string $sort sort order. As for get_users_by_capability.
 * @return array list of users.
 */
function forum_get_potential_subscribers($forumcontext, $groupid, $fields, $sort) {
    return get_users_by_capability($forumcontext, 'mod/forum:initialsubscriptions', $fields, $sort, '', '', $groupid, '', false, true);
}

/**
 * Returns list of user objects that are subscribed to this forum
 *
 * @global object
 * @global object
 * @param object $course the course
 * @param forum $forum the forum
 * @param integer $groupid group id, or 0 for all.
 * @param object $context the forum context, to save re-fetching it where possible.
 * @return array list of users.
 */
function forum_subscribed_users($course, $forum, $groupid=0, $context = NULL) {
    global $CFG, $DB;
    $params = array($forum->id);

    if ($groupid) {
        $grouptables = ", {groups_members} gm ";
        $groupselect = "AND gm.groupid = ? AND u.id = gm.userid";
        $params[] = $groupid;
    } else  {
        $grouptables = '';
        $groupselect = '';
    }

    $fields ="u.id,
              u.username,
              u.firstname,
              u.lastname,
              u.maildisplay,
              u.mailformat,
              u.maildigest,
              u.emailstop,
              u.imagealt,
              u.email,
              u.city,
              u.country,
              u.lastaccess,
              u.lastlogin,
              u.picture,
              u.timezone,
              u.theme,
              u.lang,
              u.trackforums,
              u.mnethostid";

    if (forum_is_forcesubscribed($forum)) {
        if (empty($context)) {
            $cm = get_coursemodule_from_instance('forum', $forum->id, $course->id);
            $context = get_context_instance(CONTEXT_MODULE, $cm->id);
        }
        $sort = "u.email ASC";
        $results = forum_get_potential_subscribers($context, $groupid, $fields, $sort);
    } else {
        $results = $DB->get_records_sql("SELECT $fields
                              FROM {user} u,
                                   {forum_subscriptions} s $grouptables
                             WHERE s.forum = ?
                               AND s.userid = u.id
                               AND u.deleted = 0  $groupselect
                          ORDER BY u.email ASC", $params);
    }

    static $guestid = null;

    if (is_null($guestid)) {
        if ($guest = guest_user()) {
            $guestid = $guest->id;
        } else {
            $guestid = 0;
        }
    }

    // Guest user should never be subscribed to a forum.
    unset($results[$guestid]);

    return $results;
}



// OTHER FUNCTIONS ///////////////////////////////////////////////////////////


/**
 * @global object
 * @global object
 * @param int $courseid
 * @param string $type
 */
function forum_get_course_forum($courseid, $type) {
// How to set up special 1-per-course forums
    global $CFG, $DB;

    if ($forums = $DB->get_records_select("forum", "course = ? AND type = ?", array($courseid, $type), "id ASC")) {
        // There should always only be ONE, but with the right combination of
        // errors there might be more.  In this case, just return the oldest one (lowest ID).
        foreach ($forums as $forum) {
            return $forum;   // ie the first one
        }
    }

    // Doesn't exist, so create one now.
    $forum->course = $courseid;
    $forum->type = "$type";
    switch ($forum->type) {
        case "news":
            $forum->name  = get_string("namenews", "forum");
            $forum->intro = get_string("intronews", "forum");
            $forum->forcesubscribe = FORUM_FORCESUBSCRIBE;
            $forum->assessed = 0;
            if ($courseid == SITEID) {
                $forum->name  = get_string("sitenews");
                $forum->forcesubscribe = 0;
            }
            break;
        case "social":
            $forum->name  = get_string("namesocial", "forum");
            $forum->intro = get_string("introsocial", "forum");
            $forum->assessed = 0;
            $forum->forcesubscribe = 0;
            break;
        default:
            notify("That forum type doesn't exist!");
            return false;
            break;
    }

    $forum->timemodified = time();
    $forum->id = $DB->insert_record("forum", $forum);

    if (! $module = $DB->get_record("modules", array("name" => "forum"))) {
        notify("Could not find forum module!!");
        return false;
    }
    $mod = new object();
    $mod->course = $courseid;
    $mod->module = $module->id;
    $mod->instance = $forum->id;
    $mod->section = 0;
    if (! $mod->coursemodule = add_course_module($mod) ) {   // assumes course/lib.php is loaded
        notify("Could not add a new course module to the course '" . format_string($course->fullname) . "'");
        return false;
    }
    if (! $sectionid = add_mod_to_section($mod) ) {   // assumes course/lib.php is loaded
        notify("Could not add the new course module to that section");
        return false;
    }
    if (! $DB->set_field("course_modules", "section", $sectionid, array("id" => $mod->coursemodule))) {
        notify("Could not update the course module with the correct section");
        return false;
    }
    include_once("$CFG->dirroot/course/lib.php");
    rebuild_course_cache($courseid);

    return $DB->get_record("forum", array("id" => "$forum->id"));
}


/**
 * Given the data about a posting, builds up the HTML to display it and
 * returns the HTML in a string.  This is designed for sending via HTML email.
 *
 * @global object
 * @param object $course
 * @param object $cm
 * @param object $forum
 * @param object $discussion
 * @param object $post
 * @param object $userform
 * @param object $userto
 * @param bool $ownpost
 * @param bool $reply
 * @param bool $link
 * @param bool $rate
 * @param string $footer
 * @return string
 */
function forum_make_mail_post($course, $cm, $forum, $discussion, $post, $userfrom, $userto,
                              $ownpost=false, $reply=false, $link=false, $rate=false, $footer="") {

    global $CFG;

    if (!isset($userto->viewfullnames[$forum->id])) {
        if (!$cm = get_coursemodule_from_instance('forum', $forum->id, $course->id)) {
            print_error('invalidcoursemodule');
        }
        $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
        $viewfullnames = has_capability('moodle/site:viewfullnames', $modcontext, $userto->id);
    } else {
        $viewfullnames = $userto->viewfullnames[$forum->id];
    }

    // format the post body
    $options = new object();
    $options->para = true;
    $formattedtext = format_text($post->message, $post->messageformat, $options, $course->id);

    $output = '<table border="0" cellpadding="3" cellspacing="0" class="forumpost">';

    $output .= '<tr class="header"><td width="35" valign="top" class="picture left">';
    $output .= print_user_picture($userfrom, $course->id, $userfrom->picture, false, true);
    $output .= '</td>';

    if ($post->parent) {
        $output .= '<td class="topic">';
    } else {
        $output .= '<td class="topic starter">';
    }
    $output .= '<div class="subject">'.format_string($post->subject).'</div>';

    $fullname = fullname($userfrom, $viewfullnames);
    $by = new object();
    $by->name = '<a href="'.$CFG->wwwroot.'/user/view.php?id='.$userfrom->id.'&amp;course='.$course->id.'">'.$fullname.'</a>';
    $by->date = userdate($post->modified, '', $userto->timezone);
    $output .= '<div class="author">'.get_string('bynameondate', 'forum', $by).'</div>';

    $output .= '</td></tr>';

    $output .= '<tr><td class="left side" valign="top">';

    if (isset($userfrom->groups)) {
        $groups = $userfrom->groups[$forum->id];
    } else {
        if (!$cm = get_coursemodule_from_instance('forum', $forum->id, $course->id)) {
            print_error('invalidcoursemodule');
        }
        $group = groups_get_all_groups($course->id, $userfrom->id, $cm->groupingid);
    }

    if ($groups) {
        $output .= print_group_picture($groups, $course->id, false, true, true);
    } else {
        $output .= '&nbsp;';
    }

    $output .= '</td><td class="content">';

    $attachments = forum_print_attachments($post, $cm, 'html');
    if ($attachments !== '') {
        $output .= '<div class="attachments">';
        $output .= $attachments;
        $output .= '</div>';
    }

    $output .= $formattedtext;

// Commands
    $commands = array();

    if ($post->parent) {
        $commands[] = '<a target="_blank" href="'.$CFG->wwwroot.'/mod/forum/discuss.php?d='.
                      $post->discussion.'&amp;parent='.$post->parent.'">'.get_string('parent', 'forum').'</a>';
    }

    if ($reply) {
        $commands[] = '<a target="_blank" href="'.$CFG->wwwroot.'/mod/forum/post.php?reply='.$post->id.'">'.
                      get_string('reply', 'forum').'</a>';
    }

    $output .= '<div class="commands">';
    $output .= implode(' | ', $commands);
    $output .= '</div>';

// Context link to post if required
    if ($link) {
        $output .= '<div class="link">';
        $output .= '<a target="_blank" href="'.$CFG->wwwroot.'/mod/forum/discuss.php?d='.$post->discussion.'#p'.$post->id.'">'.
                     get_string('postincontext', 'forum').'</a>';
        $output .= '</div>';
    }

    if ($footer) {
        $output .= '<div class="footer">'.$footer.'</div>';
    }
    $output .= '</td></tr></table>'."\n\n";

    return $output;
}

/**
 * Print a forum post
 *
 * @global object
 * @global object
 * @uses FORUM_MODE_THREADED
 * @uses PORTFOLIO_FORMAT_PLAINHTML
 * @uses PORTFOLIO_FORMAT_FILE
 * @uses PORTFOLIO_FORMAT_RICHHTML
 * @uses PORTFOLIO_ADD_TEXT_LINK
 * @uses CONTEXT_MODULE
 * @param object $post The post to print.
 * @param object $discussion
 * @param object $forum
 * @param object $cm
 * @param object $course
 * @param boolean $ownpost Whether this post belongs to the current user.
 * @param boolean $reply Whether to print a 'reply' link at the bottom of the message.
 * @param boolean $link Just print a shortened version of the post as a link to the full post.
 * @param object $ratings -- I don't really know --
 * @param string $footer Extra stuff to print after the message.
 * @param string $highlight Space-separated list of terms to highlight.
 * @param int $post_read true, false or -99. If we already know whether this user
 *          has read this post, pass that in, otherwise, pass in -99, and this
 *          function will work it out.
 * @param boolean $dummyifcantsee When forum_user_can_see_post says that
 *          the current user can't see this post, if this argument is true
 *          (the default) then print a dummy 'you can't see this post' post.
 *          If false, don't output anything at all.
 * @param bool|null $istracked
 */
function forum_print_post($post, $discussion, $forum, &$cm, $course, $ownpost=false, $reply=false, $link=false,
                          $ratings=NULL, $footer="", $highlight="", $post_read=null, $dummyifcantsee=true, $istracked=null) {

    global $USER, $CFG;

    static $stredit, $strdelete, $strreply, $strparent, $strprune;
    static $strpruneheading, $displaymode;
    static $strmarkread, $strmarkunread;

    $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);

    $post->course = $course->id;
    $post->forum  = $forum->id;
    $post->message = file_rewrite_pluginfile_urls($post->message, 'pluginfile.php', $modcontext->id, 'forum_post', $post->id);

    // caching
    if (!isset($cm->cache)) {
        $cm->cache = new object();
    }

    if (!isset($cm->cache->caps)) {
        $cm->cache->caps = array();
        $cm->cache->caps['mod/forum:viewdiscussion']   = has_capability('mod/forum:viewdiscussion', $modcontext);
        $cm->cache->caps['moodle/site:viewfullnames']  = has_capability('moodle/site:viewfullnames', $modcontext);
        $cm->cache->caps['mod/forum:editanypost']      = has_capability('mod/forum:editanypost', $modcontext);
        $cm->cache->caps['mod/forum:splitdiscussions'] = has_capability('mod/forum:splitdiscussions', $modcontext);
        $cm->cache->caps['mod/forum:deleteownpost']    = has_capability('mod/forum:deleteownpost', $modcontext);
        $cm->cache->caps['mod/forum:deleteanypost']    = has_capability('mod/forum:deleteanypost', $modcontext);
        $cm->cache->caps['mod/forum:viewanyrating']    = has_capability('mod/forum:viewanyrating', $modcontext);
        $cm->cache->caps['mod/forum:exportpost']       = has_capability('mod/forum:exportpost', $modcontext);
        $cm->cache->caps['mod/forum:exportownpost']    = has_capability('mod/forum:exportownpost', $modcontext);
    }

    if (!isset($cm->uservisible)) {
        $cm->uservisible = coursemodule_visible_for_user($cm);
    }

    if (!forum_user_can_see_post($forum, $discussion, $post, NULL, $cm)) {
        if (!$dummyifcantsee) {
            return;
        }
        echo '<a id="p'.$post->id.'"></a>';
        echo '<table cellspacing="0" class="forumpost">';
        echo '<tr class="header"><td class="picture left">';
        //        print_user_picture($post->userid, $courseid, $post->picture);
        echo '</td>';
        if ($post->parent) {
            echo '<td class="topic">';
        } else {
            echo '<td class="topic starter">';
        }
        echo '<div class="subject">'.get_string('forumsubjecthidden','forum').'</div>';
        echo '<div class="author">';
        print_string('forumauthorhidden','forum');
        echo '</div></td></tr>';

        echo '<tr><td class="left side">';
        echo '&nbsp;';

        // Actual content

        echo '</td><td class="content">'."\n";
        echo get_string('forumbodyhidden','forum');
        echo '</td></tr></table>';
        return;
    }

    if (empty($stredit)) {
        $stredit         = get_string('edit', 'forum');
        $strdelete       = get_string('delete', 'forum');
        $strreply        = get_string('reply', 'forum');
        $strparent       = get_string('parent', 'forum');
        $strpruneheading = get_string('pruneheading', 'forum');
        $strprune        = get_string('prune', 'forum');
        $displaymode     = get_user_preferences('forum_displaymode', $CFG->forum_displaymode);
        $strmarkread     = get_string('markread', 'forum');
        $strmarkunread   = get_string('markunread', 'forum');

    }

    $read_style = '';
    // ignore trackign status if not tracked or tracked param missing
    if ($istracked) {
        if (is_null($post_read)) {
            debugging('fetching post_read info');
            $post_read = forum_tp_is_post_read($USER->id, $post);
        }

        if ($post_read) {
            $read_style = ' read';
        } else {
            $read_style = ' unread';
            echo '<a name="unread"></a>';
        }
    }

    echo '<a id="p'.$post->id.'"></a>';
    echo '<table cellspacing="0" class="forumpost'.$read_style.'">';

    // Picture
    $postuser = new object();
    $postuser->id        = $post->userid;
    $postuser->firstname = $post->firstname;
    $postuser->lastname  = $post->lastname;
    $postuser->imagealt  = $post->imagealt;
    $postuser->picture   = $post->picture;

    echo '<tr class="header"><td class="picture left">';
    print_user_picture($postuser, $course->id);
    echo '</td>';

    if ($post->parent) {
        echo '<td class="topic">';
    } else {
        echo '<td class="topic starter">';
    }

    if (!empty($post->subjectnoformat)) {
        echo '<div class="subject">'.$post->subject.'</div>';
    } else {
        echo '<div class="subject">'.format_string($post->subject).'</div>';
    }

    echo '<div class="author">';
    $fullname = fullname($postuser, $cm->cache->caps['moodle/site:viewfullnames']);
    $by = new object();
    $by->name = '<a href="'.$CFG->wwwroot.'/user/view.php?id='.
                $post->userid.'&amp;course='.$course->id.'">'.$fullname.'</a>';
    $by->date = userdate($post->modified);
    print_string('bynameondate', 'forum', $by);
    echo '</div></td></tr>';

    echo '<tr><td class="left side">';
    if (isset($cm->cache->usersgroups)) {
        $groups = array();
        if (isset($cm->cache->usersgroups[$post->userid])) {
            foreach ($cm->cache->usersgroups[$post->userid] as $gid) {
                $groups[$gid] = $cm->cache->groups[$gid];
            }
        }
    } else {
        $groups = groups_get_all_groups($course->id, $post->userid, $cm->groupingid);
    }

    if ($groups) {
        print_group_picture($groups, $course->id, false, false, true);
    } else {
        echo '&nbsp;';
    }

// Actual content

    echo '</td><td class="content">'."\n";

    list($attachments, $attachedimages) = forum_print_attachments($post, $cm, 'separateimages');

    if ($attachments !== '') {
        echo '<div class="attachments">';
        echo $attachments;
        echo '</div>';
    }

    $options = new object();
    $options->para    = false;
    $options->trusted = $post->messagetrust;
    if ($link and (strlen(strip_tags($post->message)) > $CFG->forum_longpost)) {
        // Print shortened version
        echo format_text(forum_shorten_post($post->message), $post->messageformat, $options, $course->id);
        $numwords = count_words(strip_tags($post->message));
        echo '<div class="posting"><a href="'.$CFG->wwwroot.'/mod/forum/discuss.php?d='.$post->discussion.'">';
        echo get_string('readtherest', 'forum');
        echo '</a> ('.get_string('numwords', '', $numwords).')...</div>';
    } else {
        // Print whole message
        echo '<div class="posting">';
        if ($highlight) {
            echo highlight($highlight, format_text($post->message, $post->messageformat, $options, $course->id));
        } else {
            echo format_text($post->message, $post->messageformat, $options, $course->id);
        }
        echo '</div>';
        echo $attachedimages;
    }


// Commands

    $commands = array();

    if ($istracked) {
        // SPECIAL CASE: The front page can display a news item post to non-logged in users.
        // Don't display the mark read / unread controls in this case.
        if ($CFG->forum_usermarksread and isloggedin()) {
            if ($post_read) {
                $mcmd = '&amp;mark=unread&amp;postid='.$post->id;
                $mtxt = $strmarkunread;
            } else {
                $mcmd = '&amp;mark=read&amp;postid='.$post->id;
                $mtxt = $strmarkread;
            }
            if ($displaymode == FORUM_MODE_THREADED) {
                $commands[] = '<a href="'.$CFG->wwwroot.'/mod/forum/discuss.php?d='.
                              $post->discussion.'&amp;parent='.$post->id.$mcmd.'">'.$mtxt.'</a>';
            } else {
                $commands[] = '<a href="'.$CFG->wwwroot.'/mod/forum/discuss.php?d='.
                              $post->discussion.$mcmd.'#p'.$post->id.'">'.$mtxt.'</a>';
            }
        }
    }

    if ($post->parent) {  // Zoom in to the parent specifically
        if ($displaymode == FORUM_MODE_THREADED) {
            $commands[] = '<a href="'.$CFG->wwwroot.'/mod/forum/discuss.php?d='.
                      $post->discussion.'&amp;parent='.$post->parent.'">'.$strparent.'</a>';
        } else {
            $commands[] = '<a href="'.$CFG->wwwroot.'/mod/forum/discuss.php?d='.
                      $post->discussion.'#p'.$post->parent.'">'.$strparent.'</a>';
        }
    }

    $age = time() - $post->created;
    // Hack for allow to edit news posts those are not displayed yet until they are displayed
    if (!$post->parent and $forum->type == 'news' and $discussion->timestart > time()) {
        $age = 0;
    }
    $editanypost = $cm->cache->caps['mod/forum:editanypost'];

    if ($ownpost or $editanypost) {
        if (($age < $CFG->maxeditingtime) or $editanypost) {
            $commands[] =  '<a href="'.$CFG->wwwroot.'/mod/forum/post.php?edit='.$post->id.'">'.$stredit.'</a>';
        }
    }

    if ($cm->cache->caps['mod/forum:splitdiscussions']
                && $post->parent && $forum->type != 'single') {

        $commands[] = '<a href="'.$CFG->wwwroot.'/mod/forum/post.php?prune='.$post->id.
                      '" title="'.$strpruneheading.'">'.$strprune.'</a>';
    }

    if (($ownpost and $age < $CFG->maxeditingtime
                and $cm->cache->caps['mod/forum:deleteownpost'])
                or $cm->cache->caps['mod/forum:deleteanypost']) {
        $commands[] = '<a href="'.$CFG->wwwroot.'/mod/forum/post.php?delete='.$post->id.'">'.$strdelete.'</a>';
    }

    if ($reply) {
        $commands[] = '<a href="'.$CFG->wwwroot.'/mod/forum/post.php?reply='.$post->id.'">'.$strreply.'</a>';
    }

    if ($cm->cache->caps['mod/forum:exportpost'] || ($ownpost && $cm->cache->caps['mod/forum:exportownpost'])) {
        $p = array(
            'postid' => $post->id,
        );
        $button = new portfolio_add_button();
        $button->set_callback_options('forum_portfolio_caller', array('postid' => $post->id));
        if (empty($attachments)) {
            $button->set_formats(PORTFOLIO_FORMAT_PLAINHTML);
        } else {
            $button->set_formats(PORTFOLIO_FORMAT_FILE, PORTFOLIO_FORMAT_RICHHTML);
        }

        $porfoliohtml = $button->to_html(PORTFOLIO_ADD_TEXT_LINK);
        if(!empty($porfoliohtml)){
            $commands[] = $porfoliohtml;
        }
    }

    echo '<div class="commands">';
    echo implode(' | ', $commands);
    echo '</div>';


// Ratings

    $ratingsmenuused = false;
    if (!empty($ratings) and isloggedin()) {
        echo '<div class="ratings">';
        $useratings = true;
        if ($ratings->assesstimestart and $ratings->assesstimefinish) {
            if ($post->created < $ratings->assesstimestart or $post->created > $ratings->assesstimefinish) {
                $useratings = false;
            }
        }
        if ($useratings) {
            $mypost = ($USER->id == $post->userid);

            $canviewallratings = $cm->cache->caps['mod/forum:viewanyrating'];

            if (isset($cm->cache->ratings)) {
                if (isset($cm->cache->ratings[$post->id])) {
                    $allratings = $cm->cache->ratings[$post->id];
                } else {
                    $allratings = array(); // no reatings present yet
                }
            } else {
                $allratings = NULL; // not preloaded
            }

            if (isset($cm->cache->myratings)) {
                if (isset($cm->cache->myratings[$post->id])) {
                    $myrating = $cm->cache->myratings[$post->id];
                } else {
                    $myrating = FORUM_UNSET_POST_RATING; // no reatings present yet
                }
            } else {
                $myrating = NULL; // not preloaded
            }

            if ($canviewallratings and !$mypost) {
                echo '<span class="forumpostratingtext">' .
                     forum_print_ratings($post->id, $ratings->scale, $forum->assessed, $canviewallratings, $allratings, true) .
                     '</span>';
                if (!empty($ratings->allow)) {
                    echo '&nbsp;';
                    forum_print_rating_menu($post->id, $USER->id, $ratings->scale, $myrating);
                    $ratingsmenuused = true;
                }

            } else if ($mypost) {
                echo '<span class="forumpostratingtext">' .
                     forum_print_ratings($post->id, $ratings->scale, $forum->assessed, true, $allratings, true) .
                     '</span>';

            } else if (!empty($ratings->allow) ) {
                forum_print_rating_menu($post->id, $USER->id, $ratings->scale, $myrating);
                $ratingsmenuused = true;
            }
        }
        echo '</div>';
    }

// Link to post if required

    if ($link) {
        echo '<div class="link">';
        if ($post->replies == 1) {
            $replystring = get_string('repliesone', 'forum', $post->replies);
        } else {
            $replystring = get_string('repliesmany', 'forum', $post->replies);
        }
        echo '<a href="'.$CFG->wwwroot.'/mod/forum/discuss.php?d='.$post->discussion.'">'.
             get_string('discussthistopic', 'forum').'</a>&nbsp;('.$replystring.')';
        echo '</div>';
    }

    if ($footer) {
        echo '<div class="footer">'.$footer.'</div>';
    }
    echo '</td></tr></table>'."\n\n";

    if ($istracked && !$CFG->forum_usermarksread && !$post_read) {
        forum_tp_mark_post_read($USER->id, $post, $forum->id);
    }

    return $ratingsmenuused;
}


/**
 * This function prints the overview of a discussion in the forum listing.
 * It needs some discussion information and some post information, these
 * happen to be combined for efficiency in the $post parameter by the function
 * that calls this one: forum_print_latest_discussions()
 *
 * @global object
 * @global object
 * @param object $post The post object (passed by reference for speed).
 * @param object $forum The forum object.
 * @param int $group Current group.
 * @param string $datestring Format to use for the dates.
 * @param boolean $cantrack Is tracking enabled for this forum.
 * @param boolean $forumtracked Is the user tracking this forum.
 * @param boolean $canviewparticipants True if user has the viewparticipants permission for this course
 */
function forum_print_discussion_header(&$post, $forum, $group=-1, $datestring="",
                                        $cantrack=true, $forumtracked=true, $canviewparticipants=true, $modcontext=NULL) {

    global $USER, $CFG, $OUTPUT;

    static $rowcount;
    static $strmarkalldread;

    if (empty($modcontext)) {
        if (!$cm = get_coursemodule_from_instance('forum', $forum->id, $forum->course)) {
            print_error('invalidcoursemodule');
        }
        $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
    }

    if (!isset($rowcount)) {
        $rowcount = 0;
        $strmarkalldread = get_string('markalldread', 'forum');
    } else {
        $rowcount = ($rowcount + 1) % 2;
    }

    $post->subject = format_string($post->subject,true);

    echo "\n\n";
    echo '<tr class="discussion r'.$rowcount.'">';

    // Topic
    echo '<td class="topic starter">';
    echo '<a href="'.$CFG->wwwroot.'/mod/forum/discuss.php?d='.$post->discussion.'">'.$post->subject.'</a>';
    echo "</td>\n";

    // Picture
    $postuser = new object;
    $postuser->id = $post->userid;
    $postuser->firstname = $post->firstname;
    $postuser->lastname = $post->lastname;
    $postuser->imagealt = $post->imagealt;
    $postuser->picture = $post->picture;

    echo '<td class="picture">';
    print_user_picture($postuser, $forum->course);
    echo "</td>\n";

    // User name
    $fullname = fullname($post, has_capability('moodle/site:viewfullnames', $modcontext));
    echo '<td class="author">';
    echo '<a href="'.$CFG->wwwroot.'/user/view.php?id='.$post->userid.'&amp;course='.$forum->course.'">'.$fullname.'</a>';
    echo "</td>\n";

    // Group picture
    if ($group !== -1) {  // Groups are active - group is a group data object or NULL
        echo '<td class="picture group">';
        if (!empty($group->picture) and empty($group->hidepicture)) {
            print_group_picture($group, $forum->course, false, false, true);
        } else if (isset($group->id)) {
            if($canviewparticipants) {
                echo '<a href="'.$CFG->wwwroot.'/user/index.php?id='.$forum->course.'&amp;group='.$group->id.'">'.$group->name.'</a>';
            } else {
                echo $group->name;
            }
        }
        echo "</td>\n";
    }

    if (has_capability('mod/forum:viewdiscussion', $modcontext)) {   // Show the column with replies
        echo '<td class="replies">';
        echo '<a href="'.$CFG->wwwroot.'/mod/forum/discuss.php?d='.$post->discussion.'">';
        echo $post->replies.'</a>';
        echo "</td>\n";

        if ($cantrack) {
            echo '<td class="replies">';
            if ($forumtracked) {
                if ($post->unread > 0) {
                    echo '<span class="unread">';
                    echo '<a href="'.$CFG->wwwroot.'/mod/forum/discuss.php?d='.$post->discussion.'#unread">';
                    echo $post->unread;
                    echo '</a>';
                    echo '<a title="'.$strmarkalldread.'" href="'.$CFG->wwwroot.'/mod/forum/markposts.php?f='.
                         $forum->id.'&amp;d='.$post->discussion.'&amp;mark=read&amp;returnpage=view.php">' .
                         '<img src="'.$OUTPUT->old_icon_url('t/clear') . '" class="iconsmall" alt="'.$strmarkalldread.'" /></a>';
                    echo '</span>';
                } else {
                    echo '<span class="read">';
                    echo $post->unread;
                    echo '</span>';
                }
            } else {
                echo '<span class="read">';
                echo '-';
                echo '</span>';
            }
            echo "</td>\n";
        }
    }

    echo '<td class="lastpost">';
    $usedate = (empty($post->timemodified)) ? $post->modified : $post->timemodified;  // Just in case
    $parenturl = (empty($post->lastpostid)) ? '' : '&amp;parent='.$post->lastpostid;
    $usermodified = new object();
    $usermodified->id        = $post->usermodified;
    $usermodified->firstname = $post->umfirstname;
    $usermodified->lastname  = $post->umlastname;
    echo '<a href="'.$CFG->wwwroot.'/user/view.php?id='.$post->usermodified.'&amp;course='.$forum->course.'">'.
         fullname($usermodified).'</a><br />';
    echo '<a href="'.$CFG->wwwroot.'/mod/forum/discuss.php?d='.$post->discussion.$parenturl.'">'.
          userdate($usedate, $datestring).'</a>';
    echo "</td>\n";

    echo "</tr>\n\n";

}


/**
 * Given a post object that we already know has a long message
 * this function truncates the message nicely to the first
 * sane place between $CFG->forum_longpost and $CFG->forum_shortpost
 *
 * @global object
 * @param string $message
 * @return string
 */
function forum_shorten_post($message) {

   global $CFG;

   $i = 0;
   $tag = false;
   $length = strlen($message);
   $count = 0;
   $stopzone = false;
   $truncate = 0;

   for ($i=0; $i<$length; $i++) {
       $char = $message[$i];

       switch ($char) {
           case "<":
               $tag = true;
               break;
           case ">":
               $tag = false;
               break;
           default:
               if (!$tag) {
                   if ($stopzone) {
                       if ($char == ".") {
                           $truncate = $i+1;
                           break 2;
                       }
                   }
                   $count++;
               }
               break;
       }
       if (!$stopzone) {
           if ($count > $CFG->forum_shortpost) {
               $stopzone = true;
           }
       }
   }

   if (!$truncate) {
       $truncate = $i;
   }

   return substr($message, 0, $truncate);
}


/**
 * Print the multiple ratings on a post given to the current user by others.
 * Forumid prevents the double lookup of the forumid in discussion to determine the aggregate type
 * Scale is an array of ratings
 *
 * @uses FORUM_AGGREGATE_AVG
 * @uses FORUM_AGGREGATE_COUNT
 * @uses FORUM_AGGREGATE_MAX
 * @uses FORUM_AGGREGATE_MIN
 * @uses FORUM_AGGREGATE_SUM
 * @param int $postid
 * @param array $scale
 * @param int $aggregatetype
 * @param bool $link
 * @param array $ratings
 * @param bool $return
 * @return string|void
 */
function forum_print_ratings($postid, $scale, $aggregatetype, $link=true, $ratings=null, $return=false) {

    $strratings = '';

    switch ($aggregatetype) {
        case FORUM_AGGREGATE_AVG :
            $agg        = forum_get_ratings_mean($postid, $scale, $ratings);
            $strratings = get_string("aggregateavg", "forum");
            break;
        case FORUM_AGGREGATE_COUNT :
            $agg        = forum_get_ratings_count($postid, $scale, $ratings);
            $strratings = get_string("aggregatecount", "forum");
            break;
        case FORUM_AGGREGATE_MAX :
            $agg        = forum_get_ratings_max($postid, $scale, $ratings);
            $strratings = get_string("aggregatemax", "forum");
            break;
        case FORUM_AGGREGATE_MIN :
            $agg        = forum_get_ratings_min($postid, $scale, $ratings);
            $strratings = get_string("aggregatemin", "forum");
            break;
        case FORUM_AGGREGATE_SUM :
            $agg        = forum_get_ratings_sum($postid, $scale, $ratings);
            $strratings = get_string("aggregatesum", "forum");
            break;
    }

    if ($agg !== "") {

        if (empty($strratings)) {
            $strratings = get_string("ratings", "forum");
        }

        $strratings .= ': ';

        if ($link) {
            $strratings .= link_to_popup_window ("/mod/forum/report.php?id=$postid", "ratings", $agg, 400, 600, null, null, true);
        } else {
            $strratings .= "$agg ";
        }

        if ($return) {
            return $strratings;
        } else {
            echo $strratings;
        }
    }
}


/**
 * Return the mean rating of a post given to the current user by others.
 *
 * @global object
 * @param int $postid
 * @param array $scal Scale is an array of possible ratings in the scale
 * @param array $ratings Ratings is an optional simple array of actual ratings (just integers)
 * @return string
 */
function forum_get_ratings_mean($postid, $scale, $ratings=NULL) {
    global $DB;
    if (is_null($ratings)) {
        $ratings = array();
        if ($rates = $DB->get_records("forum_ratings", array("post" => $postid))) {
            foreach ($rates as $rate) {
                $ratings[] = $rate->rating;
            }
        }
    }

    $count = count($ratings);

    if ($count == 0 ) {
        return "";

    } else if ($count == 1) {
        $rating = reset($ratings);
        return $scale[$rating];

    } else {
        $total = 0;
        foreach ($ratings as $rating) {
            $total += $rating;
        }
        $mean = round( ((float)$total/(float)$count) + 0.001);  // Little fudge factor so that 0.5 goes UP

        if (isset($scale[$mean])) {
            return $scale[$mean]." ($count)";
        } else {
            return "$mean ($count)";    // Should never happen, hopefully
        }
    }
}

/**
 * Return the count of the ratings of a post given to the current user by others.
 *
 * For numerical grades, the scale index is the same as the real grade value from interval {0..n}
 * and $scale looks like Array( 0 => '0/n', 1 => '1/n', ..., n => 'n/n' )
 *
 * For scales, the index is the order of the scale item {1..n}
 * and $scale looks like Array( 1 => 'poor', 2 => 'weak', 3 => 'good' )
 * In case of no ratings done yet, we have nothing to display.
 *
 * @global object
 * @param int $postid
 * @param array $scale Possible ratings in the scale - the end of the scale is the highest or max grade
 * @param array $ratings An optional simple array of actual ratings (just integers)
 * @return string
 */
function forum_get_ratings_count($postid, $scale, $ratings=NULL) {
    global $DB;
    if (is_null($ratings)) {
        $ratings = array();
        if ($rates = $DB->get_records("forum_ratings", array("post" => $postid))) {
            foreach ($rates as $rate) {
                $ratings[] = $rate->rating;
            }
        }
    }

    $count = count($ratings);
    if (! array_key_exists(0, $scale)) {
        $scaleused = true;
    } else {
        $scaleused = false;
    }

    if ($count == 0) {
        if ($scaleused) {    // If no rating given yet and we use a scale
            return get_string('noratinggiven', 'forum');
        } else {
            return '';
        }
    }

    $maxgradeidx = max(array_keys($scale)); // For numerical grades, the index is the same as the real grade value {0..n}
                                            // and $scale looks like Array( 0 => '0/n', 1 => '1/n', ..., n => 'n/n' )
                                            // For scales, the index is the order of the scale item {1..n}
                                            // and $scale looks like Array( 1 => 'poor', 2 => 'weak', 3 => 'good' )

    if ($count > $maxgradeidx) {      // The count exceeds the max grade
        $a = new stdClass();
        $a->count = $count;
        $a->grade = $scale[$maxgradeidx];
        return get_string('aggregatecountformat', 'forum', $a);
    } else {                                // Display the count and the aggregated grade for this post
        $a = new stdClass();
        $a->count = $count;
        $a->grade = $scale[$count];
        return get_string('aggregatecountformat', 'forum', $a);
    }
}

/**
 * Return the max rating of a post given to the current user by others.
 *
 * @global object
 * @param int $postid
 * @param array $scale Scale is an array of possible ratings in the scale
 * @param array $ratings Ratings is an optional simple array of actual ratings (just integers)
 */
function forum_get_ratings_max($postid, $scale, $ratings=NULL) {

    global $DB;
    if (is_null($ratings)) {
        $ratings = array();
        if ($rates = $DB->get_records("forum_ratings", array("post" => $postid))) {
            foreach ($rates as $rate) {
                $ratings[] = $rate->rating;
            }
        }
    }

    $count = count($ratings);

    if ($count == 0 ) {
        return "";

    } else if ($count == 1) { //this works for max
        $rating = reset($ratings);
        return $scale[$rating];

    } else {
        $max = max($ratings);

        if (isset($scale[$max])) {
            return $scale[$max]." ($count)";
        } else {
            return "$max ($count)";    // Should never happen, hopefully
        }
    }
}

/**
 * Return the min rating of a post given to the current user by others.
 *
 * @global object
 * @param int postid
 * @param array $scale Scale is an array of possible ratings in the scale
 * @param array $ratings  Ratings is an optional simple array of actual ratings (just integers)
 * @return string
 */
function forum_get_ratings_min($postid, $scale,  $ratings=NULL) {
    global $DB;
    if (is_null($ratings)) {
        $ratings = array();
        if ($rates = $DB->get_records("forum_ratings", array("post" => $postid))) {
            foreach ($rates as $rate) {
                $ratings[] = $rate->rating;
            }
        }
    }

    $count = count($ratings);

    if ($count == 0 ) {
        return "";

    } else if ($count == 1) {
        $rating = reset($ratings);
        return $scale[$rating]; //this works for min

    } else {
        $min = min($ratings);

        if (isset($scale[$min])) {
            return $scale[$min]." ($count)";
        } else {
            return "$min ($count)";    // Should never happen, hopefully
        }
    }
}


/**
 * Return the sum or total of ratings of a post given to the current user by others.
 *
 * @global object
 * @param int $postid
 * @param array $scale Scale is an array of possible ratings in the scale
 * @param array $ratings Ratings is an optional simple array of actual ratings (just integers)
 * @return string
 */
function forum_get_ratings_sum($postid, $scale, $ratings=NULL) {
    global $DB;
    if (is_null($ratings)) {
        $ratings = array();
        if ($rates = $DB->get_records("forum_ratings", array("post" => $postid))) {
            foreach ($rates as $rate) {
                $ratings[] = $rate->rating;
            }
        }
    }

    $count = count($ratings);
    $scalecount = count($scale)-1; //this should give us the last element of the scale aka the max grade with  $scale[$scalecount]

    if ($count == 0 ) {
        return "";

    } else if ($count == 1) { //this works for max.
        $rating = reset($ratings);
        return $scale[$rating];

    } else {
        $total = 0;
        foreach ($ratings as $rating) {
            $total += $rating;
        }
        if ($total > $scale[$scalecount]) { //if the total exceeds the max grade then set it to the max grade
            $total = $scale[$scalecount];
        }
        if (isset($scale[$total])) {
            return $scale[$total]." ($count)";
        } else {
            return "$total ($count)";    // Should never happen, hopefully
        }
    }
}

/**
 * Return a summary of post ratings given to the current user by others.
 *
 * @global object
 * @param int $postid
 * @param array $scale Scale is an array of possible ratings in the scale
 * @param array $ratings Ratings is an optional simple array of actual ratings (just integers)
 * @return string
 */
function forum_get_ratings_summary($postid, $scale, $ratings=NULL) {
    global $DB;
    if (is_null($ratings)) {
        $ratings = array();
        if ($rates = $DB->get_records("forum_ratings", array("post" => $postid))) {
            foreach ($rates as $rate) {
                $rating[] = $rate->rating;
            }
        }
    }


    if (!$count = count($ratings)) {
        return "";
    }


    foreach ($scale as $key => $scaleitem) {
        $sumrating[$key] = 0;
    }

    foreach ($ratings as $rating) {
        $sumrating[$rating]++;
    }

    $summary = "";
    foreach ($scale as $key => $scaleitem) {
        $summary = $sumrating[$key].$summary;
        if ($key > 1) {
            $summary = "/$summary";
        }
    }
    return $summary;
}

/**
 * Print the menu of ratings as part of a larger form.
 * If the post has already been - set that value.
 *
 * @global object
 * @param int $postid
 * @param int $userid
 * @param array $scale is an array of ratings
 * @param int $myrating
 */
function forum_print_rating_menu($postid, $userid, $scale, $myrating=NULL) {

    static $strrate;
    global $DB;

    if (is_null($myrating)) {
        if (!$rating = $DB->get_record("forum_ratings", array("userid" => $userid, "post" => $postid))) {
            $myrating = FORUM_UNSET_POST_RATING;
        } else {
            $myrating = $rating->rating;
        }
    }

    if (empty($strrate)) {
        $strrate = get_string("rate", "forum");
    }
    $scale = array(FORUM_UNSET_POST_RATING => $strrate.'...') + $scale;
    choose_from_menu($scale, $postid, $myrating, '', '', '0', false, false, 0, '', false, false, 'forumpostratingmenu');
}

/**
 * Print the drop down that allows the user to select how they want to have
 * the discussion displayed.
 *
 * @param int $id forum id if $forumtype is 'single',
 *              discussion id for any other forum type
 * @param mixed $mode forum layout mode
 * @param string $forumtype optional
 */
function forum_print_mode_form($id, $mode, $forumtype='') {
    if ($forumtype == 'single') {
        echo '<div class="forummode">';
        popup_form("view.php?f=$id&amp;mode=", forum_get_layout_modes(), "mode", $mode, "");
        echo '</div>';
    } else {
        popup_form("discuss.php?d=$id&amp;mode=", forum_get_layout_modes(), "mode", $mode, "");
    }
}

/**
 * @global object
 * @param object $course
 * @param string $search
 * @return string
 */
function forum_search_form($course, $search='') {
    global $CFG;

    $output  = '<div class="forumsearch">';
    $output .= '<form action="'.$CFG->wwwroot.'/mod/forum/search.php" style="display:inline">';
    $output .= '<fieldset class="invisiblefieldset">';
    $output .= helpbutton('search', get_string('search'), 'moodle', true, false, '', true);
    $output .= '<input name="search" type="text" size="18" value="'.s($search, true).'" alt="search" />';
    $output .= '<input value="'.get_string('searchforums', 'forum').'" type="submit" />';
    $output .= '<input name="id" type="hidden" value="'.$course->id.'" />';
    $output .= '</fieldset>';
    $output .= '</form>';
    $output .= '</div>';

    return $output;
}


/**
 * @global object
 * @global object
 */
function forum_set_return() {
    global $CFG, $SESSION;

    if (! isset($SESSION->fromdiscussion)) {
        if (!empty($_SERVER['HTTP_REFERER'])) {
            $referer = $_SERVER['HTTP_REFERER'];
        } else {
            $referer = "";
        }
        // If the referer is NOT a login screen then save it.
        if (! strncasecmp("$CFG->wwwroot/login", $referer, 300)) {
            $SESSION->fromdiscussion = $_SERVER["HTTP_REFERER"];
        }
    }
}


/**
 * @global object
 * @param string $default
 * @return string
 */
function forum_go_back_to($default) {
    global $SESSION;

    if (!empty($SESSION->fromdiscussion)) {
        $returnto = $SESSION->fromdiscussion;
        unset($SESSION->fromdiscussion);
        return $returnto;
    } else {
        return $default;
    }
}

/**
 * Given a discussion object that is being moved to $forumto,
 * this function checks all posts in that discussion
 * for attachments, and if any are found, these are
 * moved to the new forum directory.
 *
 * @global object
 * @param object $discussion
 * @param int $forumfrom source forum id
 * @param int $forumto target forum id
 * @return bool success
 */
function forum_move_attachments($discussion, $forumfrom, $forumto) {
    global $DB;

    $fs = get_file_storage();

    $newcm = get_coursemodule_from_instance('forum', $forumto);
    $oldcm = get_coursemodule_from_instance('forum', $forumfrom);

    $newcontext = get_context_instance(CONTEXT_MODULE, $newcm->id);
    $oldcontext = get_context_instance(CONTEXT_MODULE, $oldcm->id);

    // loop through all posts, better not use attachment flag ;-)
    if ($posts = $DB->get_records('forum_posts', array('discussion'=>$discussion->id), '', 'id, attachment')) {
        foreach ($posts as $post) {
            if ($oldfiles = $fs->get_area_files($oldcontext->id, 'forum_attachment', $post->id, "id", false)) {
                foreach ($oldfiles as $oldfile) {
                    $file_record = new object();
                    $file_record->contextid = $newcontext->id;
                    $fs->create_file_from_storedfile($file_record, $oldfile);
                }
                $fs->delete_area_files($oldcontext->id, 'forum_attachment', $post->id);
                if ($post->attachment != '1') {
                    //weird - let's fix it
                    $post->attachment = '1';
                    $DB->update_record('forum_posts', $post);
                }
            } else {
                if ($post->attachment != '') {
                    //weird - let's fix it
                    $post->attachment = '';
                    $DB->update_record('forum_posts', $post);
                }
            }
        }
    }

    return true;
}

/**
 * Returns attachments as formated text/html optionally with separate images
 *
 * @global object
 * @global object
 * @global object
 * @param object $post
 * @param object $cm
 * @param string $type html/text/separateimages
 * @return mixed string or array of (html text withouth images and image HTML)
 */
function forum_print_attachments($post, $cm, $type) {
    global $CFG, $DB, $USER, $OUTPUT;

    if (empty($post->attachment)) {
        return $type !== 'separateimages' ? '' : array('', '');
    }

    if (!in_array($type, array('separateimages', 'html', 'text'))) {
        return $type !== 'separateimages' ? '' : array('', '');
    }

    if (!$context = get_context_instance(CONTEXT_MODULE, $cm->id)) {
        return $type !== 'separateimages' ? '' : array('', '');
    }
    $strattachment = get_string('attachment', 'forum');

    $fs = get_file_storage();
    $browser = get_file_browser();

    $imagereturn = '';
    $output = '';

    $canexport = (has_capability('mod/forum:exportpost', $context) || ($post->userid == $USER->id && has_capability('mod/forum:exportownpost', $context)));

    if ($files = $fs->get_area_files($context->id, 'forum_attachment', $post->id, "timemodified", false)) {
        $button = new portfolio_add_button();
        foreach ($files as $file) {
            $filename = $file->get_filename();
            $mimetype = $file->get_mimetype();
            $iconimage = '<img src="'.$OUTPUT->old_icon_url(file_mimetype_icon($mimetype)).'" class="icon" alt="'.$mimetype.'" />';
            $path = file_encode_url($CFG->wwwroot.'/pluginfile.php', '/'.$context->id.'/forum_attachment/'.$post->id.'/'.$filename);

            if ($type == 'html') {
                $output .= "<a href=\"$path\">$iconimage</a> ";
                $output .= "<a href=\"$path\">".s($filename)."</a>";
                if ($canexport) {
                    $button->set_callback_options('forum_portfolio_caller', array('postid' => $post->id, 'attachment' => $file->get_id()));
                    $button->set_formats(portfolio_format_from_file($file));
                    $output .= $button->to_html(PORTFOLIO_ADD_ICON_LINK);
                }
                $output .= "<br />";

            } else if ($type == 'text') {
                $output .= "$strattachment ".s($filename).":\n$path\n";

            } else { //'returnimages'
                if (in_array($mimetype, array('image/gif', 'image/jpeg', 'image/png'))) {
                    // Image attachments don't get printed as links
                    $imagereturn .= "<br /><img src=\"$path\" alt=\"\" />";
                    if ($canexport) {
                        $button->set_callback_options('forum_portfolio_caller', array('postid' => $post->id, 'attachment' => $file->get_id()));
                        $button->set_formats(portfolio_format_from_file($file));
                        $imagereturn .= $button->to_html(PORTFOLIO_ADD_ICON_LINK);
                    }
                } else {
                    $output .= "<a href=\"$path\">$iconimage</a> ";
                    $output .= filter_text("<a href=\"$path\">".s($filename)."</a>");
                    if ($canexport) {
                        $button->set_callback_options('forum_portfolio_caller', array('postid' => $post->id, 'attachment' => $file->get_id()));
                        $button->set_formats(portfolio_format_from_file($file));
                        $output .= $button->to_html(PORTFOLIO_ADD_ICON_LINK);
                    }
                    $output .= '<br />';
                }
            }
        }
    }

    if ($type !== 'separateimages') {
        return $output;

    } else {
        return array($output, $imagereturn);
    }
}

/**
 * Lists all browsable file areas
 *
 * @param object $course
 * @param object $cm
 * @param object $context
 * @return array
 */
function forum_get_file_areas($course, $cm, $context) {
    $areas = array();
    return $areas;
}

/**
 * Serves the forum attachments. Implements needed access control ;-)
 *
 * @param object $course
 * @param object $cminfo
 * @param object $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @return bool false if file not found, does not return if found - justsend the file
 */
function forum_pluginfile($course, $cminfo, $context, $filearea, $args, $forcedownload) {
    global $CFG, $DB;

    if (!$cminfo->uservisible) {
        return false;
    }

    $fileareas = array('forum_attachment', 'forum_post');
    if (!in_array($filearea, $fileareas)) {
        return false;
    }

    $postid = (int)array_shift($args);

    if (!$post = $DB->get_record('forum_posts', array('id'=>$postid))) {
        return false;
    }

    if (!$discussion = $DB->get_record('forum_discussions', array('id'=>$post->discussion))) {
        return false;
    }

    if (!$forum = $DB->get_record('forum', array('id'=>$cminfo->instance))) {
        return false;
    }

    $fs = get_file_storage();
    $relativepath = '/'.implode('/', $args);
    $fullpath = $context->id.$filearea.$postid.$relativepath;
    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
        return false;
    }

    // Make sure groups allow this user to see this file
    if ($discussion->groupid > 0 and $groupmode = groups_get_activity_groupmode($cminfo, $course)) {   // Groups are being used
        if (!groups_group_exists($discussion->groupid)) { // Can't find group
            return false;                           // Be safe and don't send it to anyone
        }

        if (!groups_is_member($discussion->groupid) and !has_capability('moodle/site:accessallgroups', $context)) {
            // do not send posts from other groups when in SEPARATEGROUPS or VISIBLEGROUPS
            return false;
        }
    }

    // Make sure we're allowed to see it...
    if (!forum_user_can_see_post($forum, $discussion, $post, NULL, $cminfo)) {
        return false;
    }


    // finally send the file
    send_stored_file($file, 0, 0, true); // download MUST be forced - security!
}

/**
 * If successful, this function returns the name of the file
 *
 * @global object
 * @param object $post is a full post record, including course and forum
 * @param object $forum
 * @param object $cm
 * @param mixed $mform
 * @param string $message
 * @return bool
 */
function forum_add_attachment($post, $forum, $cm, $mform=null, &$message=null) {
    global $DB;

    if (empty($mform)) {
        return false;
    }

    $context = get_context_instance(CONTEXT_MODULE, $cm->id);

    $info = file_get_draft_area_info($post->attachments);
    $present = ($info['filecount']>0) ? '1' : '';
    file_save_draft_area_files($post->attachments, $context->id, 'forum_attachment', $post->id);

    $DB->set_field('forum_posts', 'attachment', $present, array('id'=>$post->id));

    return true;
}

/**
 * Add a new post in an existing discussion.
 *
 * @global object
 * @global object
 * @global object
 * @param object $post
 * @param mixed $mform
 * @param string $message
 * @return int
 */
function forum_add_new_post($post, $mform, &$message) {
    global $USER, $CFG, $DB;

    $message = $post->message;
    $discussion = $DB->get_record('forum_discussions', array('id' => $post->discussion));
    $forum      = $DB->get_record('forum', array('id' => $discussion->forum));
    $cm         = get_coursemodule_from_instance('forum', $forum->id);
    $context    = get_context_instance(CONTEXT_MODULE, $cm->id);

    $post->created    = $post->modified = time();
    $post->mailed     = "0";
    $post->userid     = $USER->id;
    $post->attachment = "";

    $post->id = $DB->insert_record("forum_posts", $post);
    $message = file_save_draft_area_files($post->itemid, $context->id, 'forum_post', $post->id, array('subdirs'=>true), $message);
    $DB->set_field('forum_posts', 'message', $message, array('id'=>$post->id));
    forum_add_attachment($post, $forum, $cm, $mform, $message);

    // Update discussion modified date
    $DB->set_field("forum_discussions", "timemodified", $post->modified, array("id" => $post->discussion));
    $DB->set_field("forum_discussions", "usermodified", $post->userid, array("id" => $post->discussion));

    if (forum_tp_can_track_forums($forum) && forum_tp_is_tracked($forum)) {
        forum_tp_mark_post_read($post->userid, $post, $post->forum);
    }

    return $post->id;
}

/**
 * Update a post
 *
 * @global object
 * @global object
 * @global object
 * @param object $post
 * @param mixed $mform
 * @param string $message
 * @return bool
 */
function forum_update_post($post, $mform, &$message) {
    global $USER, $CFG, $DB;

    $discussion = $DB->get_record('forum_discussions', array('id' => $post->discussion));
    $forum      = $DB->get_record('forum', array('id' => $discussion->forum));
    $cm         = get_coursemodule_from_instance('forum', $forum->id);
    $context    = get_context_instance(CONTEXT_MODULE, $cm->id);

    $post->modified = time();

    $DB->update_record('forum_posts', $post);

    $discussion->timemodified = $post->modified; // last modified tracking
    $discussion->usermodified = $post->userid;   // last modified tracking

    if (!$post->parent) {   // Post is a discussion starter - update discussion title and times too
        $discussion->name      = $post->subject;
        $discussion->timestart = $post->timestart;
        $discussion->timeend   = $post->timeend;
    }
    $post->message = file_save_draft_area_files($post->itemid, $context->id, 'forum_post', $post->id, array('subdirs'=>true), $post->message);
    $DB->set_field('forum_posts', 'message', $post->message, array('id'=>$post->id));

    $DB->update_record('forum_discussions', $discussion);

    forum_add_attachment($post, $forum, $cm, $mform, $message);

    if (forum_tp_can_track_forums($forum) && forum_tp_is_tracked($forum)) {
        forum_tp_mark_post_read($post->userid, $post, $post->forum);
    }

    return true;
}

/**
 * Given an object containing all the necessary data,
 * create a new discussion and return the id
 *
 * @global object
 * @global object
 * @global object
 * @param object $post
 * @param mixed $mform
 * @param string $message
 * @return object
 */
function forum_add_discussion($discussion, $mform=null, &$message=null) {
    global $USER, $CFG, $DB;

    $timenow = time();

    // The first post is stored as a real post, and linked
    // to from the discuss entry.

    $forum = $DB->get_record('forum', array('id'=>$discussion->forum));
    $cm    = get_coursemodule_from_instance('forum', $forum->id);
    $context = get_context_instance(CONTEXT_MODULE, $cm->id);

    $post = new object();
    $post->discussion    = 0;
    $post->parent        = 0;
    $post->userid        = $USER->id;
    $post->created       = $timenow;
    $post->modified      = $timenow;
    $post->mailed        = 0;
    $post->subject       = $discussion->name;
    $post->message       = $discussion->intro;
    $post->messageformat = $discussion->messageformat;
    $post->messagetrust  = $discussion->messagetrust;
    $post->attachments   = $discussion->attachments;
    $post->forum         = $forum->id;     // speedup
    $post->course        = $forum->course; // speedup
    $post->mailnow       = $discussion->mailnow;

    $post->id = $DB->insert_record("forum_posts", $post);

    $text = file_save_draft_area_files($discussion->itemid, $context->id, 'forum_post', $post->id, array('subdirs'=>true), $post->message);
    $DB->set_field('forum_posts', 'message', $text, array('id'=>$post->id));

    // Now do the main entry for the discussion, linking to this first post

    $discussion->firstpost    = $post->id;
    $discussion->timemodified = $timenow;
    $discussion->usermodified = $post->userid;
    $discussion->userid       = $USER->id;

    $post->discussion = $DB->insert_record("forum_discussions", $discussion);

    // Finally, set the pointer on the post.
    $DB->set_field("forum_posts", "discussion", $post->discussion, array("id"=>$post->id));

    if (!empty($cm->id)) {
        forum_add_attachment($post, $forum, $cm, $mform, $message);
    }

    if (forum_tp_can_track_forums($forum) && forum_tp_is_tracked($forum)) {
        forum_tp_mark_post_read($post->userid, $post, $post->forum);
    }

    return $post->discussion;
}


/**
 * Deletes a discussion and handles all associated cleanup.
 *
 * @global object
 * @param object $discussion Discussion to delete
 * @param bool $fulldelete True when deleting entire forum
 * @param object $course Course
 * @param object $cm Course-module
 * @param object $forum Forum
 * @return bool
 */
function forum_delete_discussion($discussion, $fulldelete, $course, $cm, $forum) {
    global $DB;
    $result = true;

    if ($posts = $DB->get_records("forum_posts", array("discussion" => $discussion->id))) {
        foreach ($posts as $post) {
            $post->course = $discussion->course;
            $post->forum  = $discussion->forum;
            if (!forum_delete_post($post, 'ignore', $course, $cm, $forum, $fulldelete)) {
                $result = false;
            }
        }
    }

    forum_tp_delete_read_records(-1, -1, $discussion->id);

    if (!$DB->delete_records("forum_discussions", array("id"=>$discussion->id))) {
        $result = false;
    }

    // Update completion state if we are tracking completion based on number of posts
    // But don't bother when deleting whole thing
    if (!$fulldelete) {
        $completion = new completion_info($course);
        if ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC &&
           ($forum->completiondiscussions || $forum->completionreplies || $forum->completionposts)) {
            $completion->update_state($cm, COMPLETION_INCOMPLETE, $discussion->userid);
        }
    }

    return $result;
}


/**
 * Deletes a single forum post.
 *
 * @global object
 * @param object $post Forum post object
 * @param mixed $children Whether to delete children. If false, returns false
 *   if there are any children (without deleting the post). If true,
 *   recursively deletes all children. If set to special value 'ignore', deletes
 *   post regardless of children (this is for use only when deleting all posts
 *   in a disussion).
 * @param object $course Course
 * @param object $cm Course-module
 * @param object $forum Forum
 * @param bool $skipcompletion True to skip updating completion state if it
 *   would otherwise be updated, i.e. when deleting entire forum anyway.
 * @return bool
 */
function forum_delete_post($post, $children, $course, $cm, $forum, $skipcompletion=false) {
    global $DB;

    $context = get_context_instance(CONTEXT_MODULE, $cm->id);

    if ($children != 'ignore' && ($childposts = $DB->get_records('forum_posts', array('parent'=>$post->id)))) {
       if ($children) {
           foreach ($childposts as $childpost) {
               forum_delete_post($childpost, true, $course, $cm, $forum, $skipcompletion);
           }
       } else {
           return false;
       }
    }

    //delete ratings
    $DB->delete_records("forum_ratings", array("post" => $post->id));

    //delete attachments
    $fs = get_file_storage();
    $fs->delete_area_files($context->id, 'forum_attachment', $post->id);

    if ($DB->delete_records("forum_posts", array("id" => $post->id))) {

        forum_tp_delete_read_records(-1, $post->id);

    // Just in case we are deleting the last post
        forum_discussion_update_last_post($post->discussion);

        // Update completion state if we are tracking completion based on number of posts
        // But don't bother when deleting whole thing

        if (!$skipcompletion) {
            $completion = new completion_info($course);
            if ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC &&
               ($forum->completiondiscussions || $forum->completionreplies || $forum->completionposts)) {
                $completion->update_state($cm, COMPLETION_INCOMPLETE, $post->userid);
            }
        }

        return true;
    }
    return false;
}

/**
 * @global object
 * @param object $post
 * @param bool $children
 * @return int
 */
function forum_count_replies($post, $children=true) {
    global $DB;
    $count = 0;

    if ($children) {
        if ($childposts = $DB->get_records('forum_posts', array('parent' => $post->id))) {
           foreach ($childposts as $childpost) {
               $count ++;                   // For this child
               $count += forum_count_replies($childpost, true);
           }
        }
    } else {
        $count += $DB->count_records('forum_posts', array('parent' => $post->id));
    }

    return $count;
}


/**
 * @global object
 * @param int $forumid
 * @param mixed $value
 * @return bool
 */
function forum_forcesubscribe($forumid, $value=1) {
    global $DB;
    return $DB->set_field("forum", "forcesubscribe", $value, array("id" => $forumid));
}

/**
 * @global object
 * @param object $forum
 * @return bool
 */
function forum_is_forcesubscribed($forum) {
    global $DB;
    if (isset($forum->forcesubscribe)) {    // then we use that
        return ($forum->forcesubscribe == FORUM_FORCESUBSCRIBE);
    } else {   // Check the database
       return ($DB->get_field('forum', 'forcesubscribe', array('id' => $forum)) == FORUM_FORCESUBSCRIBE);
    }
}

/**
 * @global object
 * @param int $userid
 * @param object $forum
 * @return bool
 */
function forum_is_subscribed($userid, $forum) {
    global $DB;
    if (is_numeric($forum)) {
        $forum = $DB->get_record('forum', array('id' => $forum));
    }
    if (forum_is_forcesubscribed($forum)) {
        return true;
    }
    return $DB->record_exists("forum_subscriptions", array("userid" => $userid, "forum" => $forum->id));
}

function forum_get_subscribed_forums($course) {
    global $USER, $CFG, $DB;
    $sql = "SELECT f.id
              FROM {forum} f
                   LEFT JOIN {forum_subscriptions} fs ON (fs.forum = f.id AND fs.userid = ?)
             WHERE f.forcesubscribe <> ".FORUM_DISALLOWSUBSCRIBE."
                   AND (f.forcesubscribe = ".FORUM_FORCESUBSCRIBE." OR fs.id IS NOT NULL)";
    if ($subscribed = $DB->get_records_sql($sql, array($USER->id))) {
        foreach ($subscribed as $s) {
            $subscribed[$s->id] = $s->id;
        }
        return $subscribed;
    } else {
        return array();
    }
}

/**
 * Adds user to the subscriber list
 *
 * @global object
 * @param int $userid
 * @param int $forumid
 */
function forum_subscribe($userid, $forumid) {
    global $DB;

    if ($DB->record_exists("forum_subscriptions", array("userid"=>$userid, "forum"=>$forumid))) {
        return true;
    }

    $sub = new object();
    $sub->userid  = $userid;
    $sub->forum = $forumid;

    return $DB->insert_record("forum_subscriptions", $sub);
}

/**
 * Removes user from the subscriber list
 *
 * @global object
 * @param int $userid
 * @param int $forumid
 */
function forum_unsubscribe($userid, $forumid) {
    global $DB;
    return $DB->delete_records("forum_subscriptions", array("userid"=>$userid, "forum"=>$forumid));
}

/**
 * Given a new post, subscribes or unsubscribes as appropriate.
 * Returns some text which describes what happened.
 *
 * @global objec
 * @param object $post
 * @param object $forum
 */
function forum_post_subscription($post, $forum) {

    global $USER;

    $action = '';
    $subscribed = forum_is_subscribed($USER->id, $forum);

    if ($forum->forcesubscribe == FORUM_FORCESUBSCRIBE) { // database ignored
        return "";

    } elseif (($forum->forcesubscribe == FORUM_DISALLOWSUBSCRIBE)
        && !has_capability('moodle/course:manageactivities', get_context_instance(CONTEXT_COURSE, $forum->course), $USER->id)) {
        if ($subscribed) {
            $action = 'unsubscribe'; // sanity check, following MDL-14558
        } else {
            return "";
        }

    } else { // go with the user's choice
        if (isset($post->subscribe)) {
            // no change
            if ((!empty($post->subscribe) && $subscribed)
                || (empty($post->subscribe) && !$subscribed)) {
                return "";

            } elseif (!empty($post->subscribe) && !$subscribed) {
                $action = 'subscribe';

            } elseif (empty($post->subscribe) && $subscribed) {
                $action = 'unsubscribe';
            }
        }
    }

    $info = new object();
    $info->name  = fullname($USER);
    $info->forum = format_string($forum->name);

    switch ($action) {
        case 'subscribe':
            forum_subscribe($USER->id, $post->forum);
            return "<p>".get_string("nowsubscribed", "forum", $info)."</p>";
        case 'unsubscribe':
            forum_unsubscribe($USER->id, $post->forum);
            return "<p>".get_string("nownotsubscribed", "forum", $info)."</p>";
    }
}

/**
 * Generate and return the subscribe or unsubscribe link for a forum.
 *
 * @global object
 * @global object
 * @global object
 * @param object $forum the forum. Fields used are $forum->id and $forum->forcesubscribe.
 * @param object $context the context object for this forum.
 * @param array $messages text used for the link in its various states
 *      (subscribed, unsubscribed, forcesubscribed or cantsubscribe).
 *      Any strings not passed in are taken from the $defaultmessages array
 *      at the top of the function.
 * @param bool $cantaccessagroup
 * @param bool $fakelink
 * @param bool $backtoindex
 * @param array $subscribed_forums
 * @return string
 */
function forum_get_subscribe_link($forum, $context, $messages = array(), $cantaccessagroup = false, $fakelink=true, $backtoindex=false, $subscribed_forums=null) {
    global $CFG, $USER, $PAGE;
    $defaultmessages = array(
        'subscribed' => get_string('unsubscribe', 'forum'),
        'unsubscribed' => get_string('subscribe', 'forum'),
        'cantaccessgroup' => get_string('no'),
        'forcesubscribed' => get_string('everyoneissubscribed', 'forum'),
        'cantsubscribe' => get_string('disallowsubscribe','forum')
    );
    $messages = $messages + $defaultmessages;

    if (forum_is_forcesubscribed($forum)) {
        return $messages['forcesubscribed'];
    } else if ($forum->forcesubscribe == FORUM_DISALLOWSUBSCRIBE && !has_capability('mod/forum:managesubscriptions', $context)) {
        return $messages['cantsubscribe'];
    } else if ($cantaccessagroup) {
        return $messages['cantaccessgroup'];
    } else {
        if (is_null($subscribed_forums)) {
            $subscribed = forum_is_subscribed($USER->id, $forum);
        } else {
            $subscribed = !empty($subscribed_forums[$forum->id]);
        }
        if ($subscribed) {
            $linktext = $messages['subscribed'];
            $linktitle = get_string('subscribestop', 'forum');
        } else {
            $linktext = $messages['unsubscribed'];
            $linktitle = get_string('subscribestart', 'forum');
        }

        $options = array();
        if ($backtoindex) {
            $backtoindexlink = '&amp;backtoindex=1';
            $options['backtoindex'] = 1;
        } else {
            $backtoindexlink = '';
        }
        $link = '';

        if ($fakelink) {
            $PAGE->requires->js('mod/forum/forum.js');
            $PAGE->requires->js_function_call('forum_produce_subscribe_link', Array($forum->id, $backtoindexlink, $linktext, $linktitle));
            $link = "<noscript>";
        }
        $options ['id'] = $forum->id;
        $link .= print_single_button($CFG->wwwroot . '/mod/forum/subscribe.php',
                $options, $linktext, 'post', '_self', true, $linktitle);
        if ($fakelink) {
            $link .= '</noscript>';
        }

        return $link;
    }
}


/**
 * Generate and return the track or no track link for a forum.
 *
 * @global object
 * @global object
 * @global object
 * @param object $forum the forum. Fields used are $forum->id and $forum->forcesubscribe.
 * @param array $messages
 * @param bool $fakelink
 * @return string
 */
function forum_get_tracking_link($forum, $messages=array(), $fakelink=true) {
    global $CFG, $USER, $PAGE;

    static $strnotrackforum, $strtrackforum;

    if (isset($messages['trackforum'])) {
         $strtrackforum = $messages['trackforum'];
    }
    if (isset($messages['notrackforum'])) {
         $strnotrackforum = $messages['notrackforum'];
    }
    if (empty($strtrackforum)) {
        $strtrackforum = get_string('trackforum', 'forum');
    }
    if (empty($strnotrackforum)) {
        $strnotrackforum = get_string('notrackforum', 'forum');
    }

    if (forum_tp_is_tracked($forum)) {
        $linktitle = $strnotrackforum;
        $linktext = $strnotrackforum;
    } else {
        $linktitle = $strtrackforum;
        $linktext = $strtrackforum;
    }

    $link = '';
    if ($fakelink) {
        $PAGE->requires->js('mod/forum/forum.js');
        $PAGE->requires->js_function_call('forum_produce_tracking_link', Array($forum->id, $linktext, $linktitle));
        // use <noscript> to print button in case javascript is not enabled
        $link .= '<noscript>';
    }
    $link .= print_single_button($CFG->wwwroot . '/mod/forum/settracking.php?id=' . $forum->id,
            '', $linktext, 'post', '_self', true, $linktitle);
    if ($fakelink) {
        $link .= '</noscript>';
    }

    return $link;
}



/**
 * Returns true if user created new discussion already
 *
 * @global object
 * @global object
 * @param int $forumid
 * @param int $userid
 * @return bool
 */
function forum_user_has_posted_discussion($forumid, $userid) {
    global $CFG, $DB;

    $sql = "SELECT 'x'
              FROM {forum_discussions} d, {forum_posts} p
             WHERE d.forum = ? AND p.discussion = d.id AND p.parent = 0 and p.userid = ?";

    return $DB->record_exists_sql($sql, array($forumid, $userid));
}

/**
 * @global object
 * @global object
 * @param int $forumid
 * @param int $userid
 * @return array
 */
function forum_discussions_user_has_posted_in($forumid, $userid) {
    global $CFG, $DB;

    $haspostedsql = "SELECT d.id AS id,
                            d.*
                       FROM {forum_posts} p,
                            {forum_discussions} d
                      WHERE p.discussion = d.id
                        AND d.forum = ?
                        AND p.userid = ?";

    return $DB->get_records_sql($haspostedsql, array($forumid, $userid));
}

/**
 * @global object
 * @global object
 * @param int $forumid
 * @param int $did
 * @param int $userid
 * @return bool
 */
function forum_user_has_posted($forumid, $did, $userid) {
    global $DB;

    if (empty($did)) {
        // posted in any forum discussion?
        $sql = "SELECT 'x'
                  FROM {forum_posts} p
                  JOIN {forum_discussions} d ON d.id = p.discussion
                 WHERE p.userid = :userid AND d.forum = :forumid";
        return $DB->record_exists_sql($sql, array('forumid'=>$forumid,'userid'=>$userid));
    } else {
    return $DB->record_exists('forum_posts', array('discussion'=>$did,'userid'=>$userid));
}
}

/**
 * @global object
 * @param object $forum
 * @param object $currentgroup
 * @param int $unused
 * @param object $cm
 * @param object $context
 * @return bool
 */
function forum_user_can_post_discussion($forum, $currentgroup=null, $unused=-1, $cm=NULL, $context=NULL) {
// $forum is an object
    global $USER;

    // shortcut - guest and not-logged-in users can not post
    if (isguestuser() or !isloggedin()) {
        return false;
    }

    if (!$cm) {
        debugging('missing cm', DEBUG_DEVELOPER);
        if (!$cm = get_coursemodule_from_instance('forum', $forum->id, $forum->course)) {
            print_error('invalidcoursemodule');
        }
    }

    if (!$context) {
        $context = get_context_instance(CONTEXT_MODULE, $cm->id);
    }

    if ($currentgroup === null) {
        $currentgroup = groups_get_activity_group($cm);
    }

    $groupmode = groups_get_activity_groupmode($cm);

    if ($forum->type == 'news') {
        $capname = 'mod/forum:addnews';
    } else {
        $capname = 'mod/forum:startdiscussion';
    }

    if (!has_capability($capname, $context)) {
        return false;
    }

    if ($forum->type == 'eachuser') {
        if (forum_user_has_posted_discussion($forum->id, $USER->id)) {
            return false;
        }
    }

    if (!$groupmode or has_capability('moodle/site:accessallgroups', $context)) {
        return true;
    }

    if ($currentgroup) {
        return groups_is_member($currentgroup);
    } else {
        // no group membership and no accessallgroups means no new discussions
        // reverted to 1.7 behaviour in 1.9+,  buggy in 1.8.0-1.9.0
        return false;
    }
}

/**
 * This function checks whether the user can reply to posts in a forum
 * discussion. Use forum_user_can_post_discussion() to check whether the user
 * can start discussions.
 *
 * @global object
 * @global object
 * @uses DEBUG_DEVELOPER
 * @uses CONTEXT_MODULE
 * @uses VISIBLEGROUPS
 * @param object $forum forum object
 * @param object $discussion
 * @param object $user
 * @param object $cm
 * @param object $course
 * @param object $context
 * @return bool
 */
function forum_user_can_post($forum, $discussion, $user=NULL, $cm=NULL, $course=NULL, $context=NULL) {
    global $USER, $DB;
    if (empty($user)) {
        $user = $USER;
    }

    // shortcut - guest and not-logged-in users can not post
    if (isguestuser($user) or empty($user->id)) {
        return false;
    }

    if (!isset($discussion->groupid)) {
        debugging('incorrect discussion parameter', DEBUG_DEVELOPER);
        return false;
    }

    if (!$cm) {
        debugging('missing cm', DEBUG_DEVELOPER);
        if (!$cm = get_coursemodule_from_instance('forum', $forum->id, $forum->course)) {
            print_error('invalidcoursemodule');
        }
    }

    if (!$course) {
        debugging('missing course', DEBUG_DEVELOPER);
        if (!$course = $DB->get_record('course', array('id' => $forum->course))) {
            print_error('invalidcourseid');
        }
    }

    if (!$context) {
        $context = get_context_instance(CONTEXT_MODULE, $cm->id);
    }

    // normal users with temporary guest access can not post
    if (has_capability('moodle/legacy:guest', $context, $user->id, false)) {
        return false;
    }

    if ($forum->type == 'news') {
        $capname = 'mod/forum:replynews';
    } else {
        $capname = 'mod/forum:replypost';
    }

    if (!has_capability($capname, $context, $user->id, false)) {
        return false;
    }

    if (!$groupmode = groups_get_activity_groupmode($cm, $course)) {
        return true;
    }

    if (has_capability('moodle/site:accessallgroups', $context)) {
        return true;
    }

    if ($groupmode == VISIBLEGROUPS) {
        if ($discussion->groupid == -1) {
            // allow students to reply to all participants discussions - this was not possible in Moodle <1.8
            return true;
        }
        return groups_is_member($discussion->groupid);

    } else {
        //separate groups
        if ($discussion->groupid == -1) {
            return false;
        }
        return groups_is_member($discussion->groupid);
    }
}


/**
 * checks to see if a user can view a particular post
 *
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @uses SEPARATEGROUPS
 * @param object $post
 * @param object $course
 * @param object $cm
 * @param object $forum
 * @param object $discussion
 * @param object $user
 */
function forum_user_can_view_post($post, $course, $cm, $forum, $discussion, $user=NULL){

    global $CFG, $USER;

    if (!$user){
        $user = $USER;
    }

    $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
    if (!has_capability('mod/forum:viewdiscussion', $modcontext)) {
        return false;
    }

// If it's a grouped discussion, make sure the user is a member
    if ($discussion->groupid > 0) {
        $groupmode = groups_get_activity_groupmode($cm);
        if ($groupmode == SEPARATEGROUPS) {
            return groups_is_member($discussion->groupid) || has_capability('moodle/site:accessallgroups', $modcontext);
        }
    }
    return true;
}


/**
 * @global object
 * @global object
 * @uses DEBUG_DEVELOPER
 * @param object $forum
 * @param object $discussion
 * @param object $context
 * @param object $user
 * @return bool
 */
function forum_user_can_see_discussion($forum, $discussion, $context, $user=NULL) {
    global $USER, $DB;

    if (empty($user) || empty($user->id)) {
        $user = $USER;
    }

    // retrieve objects (yuk)
    if (is_numeric($forum)) {
        debugging('missing full forum', DEBUG_DEVELOPER);
        if (!$forum = $DB->get_record('forum',array('id'=>$forum))) {
            return false;
        }
    }
    if (is_numeric($discussion)) {
        debugging('missing full discussion', DEBUG_DEVELOPER);
        if (!$discussion = $DB->get_record('forum_discussions',array('id'=>$discussion))) {
            return false;
        }
    }

    if (!has_capability('mod/forum:viewdiscussion', $context)) {
        return false;
    }

    if ($forum->type == 'qanda' &&
            !forum_user_has_posted($forum->id, $discussion->id, $user->id) &&
            !has_capability('mod/forum:viewqandawithoutposting', $context)) {
        return false;
    }
    return true;
}


/**
 * @global object
 * @global object
 * @param object $forum
 * @param object $discussion
 * @param object $post
 * @param object $user
 * @param object $cm
 * @return bool
 */
function forum_user_can_see_post($forum, $discussion, $post, $user=NULL, $cm=NULL) {
    global $USER, $DB;

    // retrieve objects (yuk)
    if (is_numeric($forum)) {
        debugging('missing full forum', DEBUG_DEVELOPER);
        if (!$forum = $DB->get_record('forum',array('id'=>$forum))) {
            return false;
        }
    }

    if (is_numeric($discussion)) {
        debugging('missing full discussion', DEBUG_DEVELOPER);
        if (!$discussion = $DB->get_record('forum_discussions',array('id'=>$discussion))) {
            return false;
        }
    }
    if (is_numeric($post)) {
        debugging('missing full post', DEBUG_DEVELOPER);
        if (!$post = $DB->get_record('forum_posts',array('id'=>$post))) {
            return false;
        }
    }
    if (!isset($post->id) && isset($post->parent)) {
        $post->id = $post->parent;
    }

    if (!$cm) {
        debugging('missing cm', DEBUG_DEVELOPER);
        if (!$cm = get_coursemodule_from_instance('forum', $forum->id, $forum->course)) {
            print_error('invalidcoursemodule');
        }
    }

    if (empty($user) || empty($user->id)) {
        $user = $USER;
    }

    if (isset($cm->cache->caps['mod/forum:viewdiscussion'])) {
        if (!$cm->cache->caps['mod/forum:viewdiscussion']) {
            return false;
        }
    } else {
        $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
        if (!has_capability('mod/forum:viewdiscussion', $modcontext, $user->id)) {
            return false;
        }
    }

    if (isset($cm->uservisible)) {
        if (!$cm->uservisible) {
            return false;
        }
    } else {
        if (!coursemodule_visible_for_user($cm, $user->id)) {
            return false;
        }
    }

    if ($forum->type == 'qanda') {
        $firstpost = forum_get_firstpost_from_discussion($discussion->id);
        $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);

        return (forum_user_has_posted($forum->id,$discussion->id,$user->id) ||
                $firstpost->id == $post->id ||
                has_capability('mod/forum:viewqandawithoutposting', $modcontext, $user->id, false));
    }
    return true;
}


/**
 * Prints the discussion view screen for a forum.
 *
 * @global object
 * @global object
 * @param object $course The current course object.
 * @param object $forum Forum to be printed.
 * @param int $maxdiscussions .
 * @param string $displayformat The display format to use (optional).
 * @param string $sort Sort arguments for database query (optional).
 * @param int $groupmode Group mode of the forum (optional).
 * @param void $unused (originally current group)
 * @param int $page Page mode, page to display (optional).
 * @param int $perpage The maximum number of discussions per page(optional)
 *
 */
function forum_print_latest_discussions($course, $forum, $maxdiscussions=-1, $displayformat='plain', $sort='',
                                        $currentgroup=-1, $groupmode=-1, $page=-1, $perpage=100, $cm=NULL) {
    global $CFG, $USER, $OUTPUT;

    if (!$cm) {
        if (!$cm = get_coursemodule_from_instance('forum', $forum->id, $forum->course)) {
            print_error('invalidcoursemodule');
        }
    }
    $context = get_context_instance(CONTEXT_MODULE, $cm->id);

    if (empty($sort)) {
        $sort = "d.timemodified DESC";
    }

    $olddiscussionlink = false;

 // Sort out some defaults
    if ($perpage <= 0) {
        $perpage = 0;
        $page    = -1;
    }

    if ($maxdiscussions == 0) {
        // all discussions - backwards compatibility
        $page    = -1;
        $perpage = 0;
        if ($displayformat == 'plain') {
            $displayformat = 'header';  // Abbreviate display by default
        }

    } else if ($maxdiscussions > 0) {
        $page    = -1;
        $perpage = $maxdiscussions;
    }

    $fullpost = false;
    if ($displayformat == 'plain') {
        $fullpost = true;
    }


// Decide if current user is allowed to see ALL the current discussions or not

// First check the group stuff
    if ($currentgroup == -1 or $groupmode == -1) {
        $groupmode    = groups_get_activity_groupmode($cm, $course);
        $currentgroup = groups_get_activity_group($cm);
    }

// If the user can post discussions, then this is a good place to put the
// button for it. We do not show the button if we are showing site news
// and the current user is a guest.

    if (forum_user_can_post_discussion($forum, $currentgroup, $groupmode, $cm, $context) ||
        ($forum->type != 'news'
         and (isguestuser() or !isloggedin() or has_capability('moodle/legacy:guest', $context, NULL, false))) ) {

        echo '<div class="singlebutton forumaddnew">';
        echo "<form id=\"newdiscussionform\" method=\"get\" action=\"$CFG->wwwroot/mod/forum/post.php\">";
        echo '<div>';
        echo "<input type=\"hidden\" name=\"forum\" value=\"$forum->id\" />";
        echo '<input type="submit" value="';
        echo ($forum->type == 'news') ? get_string('addanewtopic', 'forum')
            : (($forum->type == 'qanda')
               ? get_string('addanewquestion','forum')
               : get_string('addanewdiscussion', 'forum'));
        echo '" />';
        echo '</div>';
        echo '</form>';
        echo "</div>\n";

    } else if (isguestuser() or !isloggedin() or $forum->type == 'news') {
        // no button and no info

    } else if ($groupmode and has_capability('mod/forum:startdiscussion', $context)) {
        // inform users why they can not post new discussion
        if ($currentgroup) {
            notify(get_string('cannotadddiscussion', 'forum'));
        } else {
            notify(get_string('cannotadddiscussionall', 'forum'));
        }
    }

// Get all the recent discussions we're allowed to see

    $getuserlastmodified = ($displayformat == 'header');

    if (! $discussions = forum_get_discussions($cm, $sort, $fullpost, null, $maxdiscussions, $getuserlastmodified, $page, $perpage) ) {
        echo '<div class="forumnodiscuss">';
        if ($forum->type == 'news') {
            echo '('.get_string('nonews', 'forum').')';
        } else if ($forum->type == 'qanda') {
            echo '('.get_string('noquestions','forum').')';
        } else {
            echo '('.get_string('nodiscussions', 'forum').')';
        }
        echo "</div>\n";
        return;
    }

// If we want paging
    if ($page != -1) {
        ///Get the number of discussions found
        $numdiscussions = forum_get_discussions_count($cm);

        ///Show the paging bar
        print_paging_bar($numdiscussions, $page, $perpage, "view.php?f=$forum->id&amp;");
        if ($numdiscussions > 1000) {
            // saves some memory on sites with very large forums
            $replies = forum_count_discussion_replies($forum->id, $sort, $maxdiscussions, $page, $perpage);
        } else {
            $replies = forum_count_discussion_replies($forum->id);
        }

    } else {
        $replies = forum_count_discussion_replies($forum->id);

        if ($maxdiscussions > 0 and $maxdiscussions <= count($discussions)) {
            $olddiscussionlink = true;
        }
    }

    $canviewparticipants = has_capability('moodle/course:viewparticipants',$context);

    $strdatestring = get_string('strftimerecentfull');

    // Check if the forum is tracked.
    if ($cantrack = forum_tp_can_track_forums($forum)) {
        $forumtracked = forum_tp_is_tracked($forum);
    } else {
        $forumtracked = false;
    }

    if ($forumtracked) {
        $unreads = forum_get_discussions_unread($cm);
    } else {
        $unreads = array();
    }

    if ($displayformat == 'header') {
        echo '<table cellspacing="0" class="forumheaderlist">';
        echo '<thead>';
        echo '<tr>';
        echo '<th class="header topic" scope="col">'.get_string('discussion', 'forum').'</th>';
        echo '<th class="header author" colspan="2" scope="col">'.get_string('startedby', 'forum').'</th>';
        if ($groupmode > 0) {
            echo '<th class="header group" scope="col">'.get_string('group').'</th>';
        }
        if (has_capability('mod/forum:viewdiscussion', $context)) {
            echo '<th class="header replies" scope="col">'.get_string('replies', 'forum').'</th>';
            // If the forum can be tracked, display the unread column.
            if ($cantrack) {
                echo '<th class="header replies" scope="col">'.get_string('unread', 'forum');
                if ($forumtracked) {
                    echo '&nbsp;<a title="'.get_string('markallread', 'forum').
                         '" href="'.$CFG->wwwroot.'/mod/forum/markposts.php?f='.
                         $forum->id.'&amp;mark=read&amp;returnpage=view.php">'.
                         '<img src="'.$OUTPUT->old_icon_url('t/clear') . '" class="iconsmall" alt="'.get_string('markallread', 'forum').'" /></a>';
                }
                echo '</th>';
            }
        }
        echo '<th class="header lastpost" scope="col">'.get_string('lastpost', 'forum').'</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
    }

    foreach ($discussions as $discussion) {
        if (!empty($replies[$discussion->discussion])) {
            $discussion->replies = $replies[$discussion->discussion]->replies;
            $discussion->lastpostid = $replies[$discussion->discussion]->lastpostid;
        } else {
            $discussion->replies = 0;
        }

        // SPECIAL CASE: The front page can display a news item post to non-logged in users.
        // All posts are read in this case.
        if (!$forumtracked) {
            $discussion->unread = '-';
        } else if (empty($USER)) {
            $discussion->unread = 0;
        } else {
            if (empty($unreads[$discussion->discussion])) {
                $discussion->unread = 0;
            } else {
                $discussion->unread = $unreads[$discussion->discussion];
            }
        }

        if (!empty($USER->id)) {
            $ownpost = ($discussion->userid == $USER->id);
        } else {
            $ownpost=false;
        }
        // Use discussion name instead of subject of first post
        $discussion->subject = $discussion->name;

        switch ($displayformat) {
            case 'header':
                if ($groupmode > 0) {
                    if (isset($groups[$discussion->groupid])) {
                        $group = $groups[$discussion->groupid];
                    } else {
                        $group = $groups[$discussion->groupid] = groups_get_group($discussion->groupid);
                    }
                } else {
                    $group = -1;
                }
                forum_print_discussion_header($discussion, $forum, $group, $strdatestring, $cantrack, $forumtracked,
                    $canviewparticipants, $context);
            break;
            default:
                $link = false;

                if ($discussion->replies) {
                    $link = true;
                } else {
                    $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
                    $link = forum_user_can_post($forum, $discussion, $USER, $cm, $course, $modcontext);
                }

                $discussion->forum = $forum->id;

                forum_print_post($discussion, $discussion, $forum, $cm, $course, $ownpost, 0, $link, false);
            break;
        }
    }

    if ($displayformat == "header") {
        echo '</tbody>';
        echo '</table>';
    }

    if ($olddiscussionlink) {
        if ($forum->type == 'news') {
            $strolder = get_string('oldertopics', 'forum');
        } else {
            $strolder = get_string('olderdiscussions', 'forum');
        }
        echo '<div class="forumolddiscuss">';
        echo '<a href="'.$CFG->wwwroot.'/mod/forum/view.php?f='.$forum->id.'&amp;showall=1">';
        echo $strolder.'</a> ...</div>';
    }

    if ($page != -1) { ///Show the paging bar
        print_paging_bar($numdiscussions, $page, $perpage, "view.php?f=$forum->id&amp;");
    }
}


/**
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @uses FORUM_MODE_FLATNEWEST
 * @uses FORUM_MODE_FLATOLDEST
 * @uses FORUM_MODE_THREADED
 * @uses FORUM_MODE_NESTED
 * @param object $course
 * @param object $cm
 * @param object $forum
 * @param object $discussion
 * @param object $post
 * @param object $mode
 * @param mixed $canreply
 * @param bool $cancreate
 */
function forum_print_discussion($course, $cm, $forum, $discussion, $post, $mode, $canreply=NULL, $canrate=false) {

    global $USER, $CFG, $DB, $PAGE, $OUTPUT;

    if (!empty($USER->id)) {
        $ownpost = ($USER->id == $post->userid);
    } else {
        $ownpost = false;
    }
    $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
    if ($canreply === NULL) {
        $reply = forum_user_can_post($forum, $discussion, $USER, $cm, $course, $modcontext);
    } else {
        $reply = $canreply;
    }

    // $cm holds general cache for forum functions
    $cm->cache = new object();
    $cm->cache->groups      = groups_get_all_groups($course->id, 0, $cm->groupingid);
    $cm->cache->usersgroups = array();

    $posters = array();

    // preload all posts - TODO: improve...
    if ($mode == FORUM_MODE_FLATNEWEST) {
        $sort = "p.created DESC";
    } else {
        $sort = "p.created ASC";
    }

    $forumtracked = forum_tp_is_tracked($forum);
    $posts = forum_get_all_discussion_posts($discussion->id, $sort, $forumtracked);
    $post = $posts[$post->id];

    foreach ($posts as $pid=>$p) {
        $posters[$p->userid] = $p->userid;
    }

    // preload all groups of ppl that posted in this discussion
    if ($postersgroups = groups_get_all_groups($course->id, $posters, $cm->groupingid, 'gm.id, gm.groupid, gm.userid')) {
        foreach($postersgroups as $pg) {
            if (!isset($cm->cache->usersgroups[$pg->userid])) {
                $cm->cache->usersgroups[$pg->userid] = array();
            }
            $cm->cache->usersgroups[$pg->userid][$pg->groupid] = $pg->groupid;
        }
        unset($postersgroups);
    }

    $ratings = NULL;
    $ratingsmenuused = false;
    $ratingsformused = false;
    if ($forum->assessed and isloggedin()) {
        if ($scale = make_grades_menu($forum->scale)) {
            $ratings =new object();
            $ratings->scale = $scale;
            $ratings->assesstimestart = $forum->assesstimestart;
            $ratings->assesstimefinish = $forum->assesstimefinish;
            $ratings->allow = $canrate;

            if ($ratings->allow) {
                echo '<form id="form" method="post" action="rate.php">';
                echo '<div class="ratingform">';
                echo '<input type="hidden" name="forumid" value="'.$forum->id.'" />';
                $ratingsformused = true;
            }
          // preload all ratings - one query only and minimal memory
            $cm->cache->ratings = array();
            $cm->cache->myratings = array();
            if ($postratings = forum_get_all_discussion_ratings($discussion)) {
                foreach ($postratings as $pr) {
                    if (!isset($cm->cache->ratings[$pr->postid])) {
                        $cm->cache->ratings[$pr->postid] = array();
                    }
                    $cm->cache->ratings[$pr->postid][$pr->id] = $pr->rating;
                    if ($pr->userid == $USER->id) {
                        $cm->cache->myratings[$pr->postid] = $pr->rating;
                    }
                }
                unset($postratings);
            }
        }
    }

    $post->forum = $forum->id;   // Add the forum id to the post object, later used by forum_print_post
    $post->forumtype = $forum->type;

    $post->subject = format_string($post->subject);

    $postread = !empty($post->postread);

    if (forum_print_post($post, $discussion, $forum, $cm, $course, $ownpost, $reply, false, $ratings,
                         '', '', $postread, true, $forumtracked)) {
        $ratingsmenuused = true;
    }

    switch ($mode) {
        case FORUM_MODE_FLATOLDEST :
        case FORUM_MODE_FLATNEWEST :
        default:
            if (forum_print_posts_flat($course, $cm, $forum, $discussion, $post, $mode, $ratings, $reply, $forumtracked, $posts)) {
                $ratingsmenuused = true;
            }
            break;

        case FORUM_MODE_THREADED :
            if (forum_print_posts_threaded($course, $cm, $forum, $discussion, $post, 0, $ratings, $reply, $forumtracked, $posts)) {
                $ratingsmenuused = true;
            }
            break;

        case FORUM_MODE_NESTED :
            if (forum_print_posts_nested($course, $cm, $forum, $discussion, $post, $ratings, $reply, $forumtracked, $posts)) {
                $ratingsmenuused = true;
            }
            break;
    }

    if ($ratingsformused) {
        if ($ratingsmenuused) {
            echo '<div class="ratingsubmit">';
            echo '<input type="submit" id="forumpostratingsubmit" value="'.get_string('sendinratings', 'forum').'" />';
            if (ajaxenabled() && !empty($CFG->forum_ajaxrating)) { /// AJAX enabled, standard submission form
                $PAGE->requires->js_function_call('add_menu_listeners', array($OUTPUT->old_icon_url('i/loading_small')))->on_dom_ready();
            }
            if ($forum->scale < 0) {
                if ($scale = $DB->get_record("scale", array("id" => abs($forum->scale)))) {
                    print_scale_menu_helpbutton($course->id, $scale );
                }
            }
            echo '</div>';
        }
        echo '</div>';
        echo '</form>';
    }
}


/**
 * @global object
 * @global object
 * @uses FORUM_MODE_FLATNEWEST
 * @param object $course
 * @param object $cm
 * @param object $forum
 * @param object $discussion
 * @param object $post
 * @param object $mode
 * @param object $ratings
 * @param bool $reply
 * @param bool $forumtracked
 * @param array $posts
 */
function forum_print_posts_flat($course, &$cm, $forum, $discussion, $post, $mode, $ratings, $reply, $forumtracked, $posts) {
    global $USER, $CFG;

    $link  = false;
    $ratingsmenuused = false;

    if ($mode == FORUM_MODE_FLATNEWEST) {
        $sort = "ORDER BY created DESC";
    } else {
        $sort = "ORDER BY created ASC";
    }

    foreach ($posts as $post) {
        if (!$post->parent) {
            continue;
        }
        $post->subject = format_string($post->subject);
        $ownpost = ($USER->id == $post->userid);

        $postread = !empty($post->postread);

        if (forum_print_post($post, $discussion, $forum, $cm, $course, $ownpost, $reply, $link, $ratings,
                             '', '', $postread, true, $forumtracked)) {
            $ratingsmenuused = true;
        }
    }

    return $ratingsmenuused;
}


/**
 * @todo Document this function
 *
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 */
function forum_print_posts_threaded($course, &$cm, $forum, $discussion, $parent, $depth, $ratings, $reply, $forumtracked, $posts) {
    global $USER, $CFG;

    $link  = false;
    $ratingsmenuused = false;

    if (!empty($posts[$parent->id]->children)) {
        $posts = $posts[$parent->id]->children;

        $modcontext       = get_context_instance(CONTEXT_MODULE, $cm->id);
        $canviewfullnames = has_capability('moodle/site:viewfullnames', $modcontext);

        foreach ($posts as $post) {

            echo '<div class="indent">';
            if ($depth > 0) {
                $ownpost = ($USER->id == $post->userid);
                $post->subject = format_string($post->subject);

                $postread = !empty($post->postread);

                if (forum_print_post($post, $discussion, $forum, $cm, $course, $ownpost, $reply, $link, $ratings,
                                     '', '', $postread, true, $forumtracked)) {
                    $ratingsmenuused = true;
                }
            } else {
                if (!forum_user_can_see_post($forum, $discussion, $post, NULL, $cm)) {
                    echo "</div>\n";
                    continue;
                }
                $by = new object();
                $by->name = fullname($post, $canviewfullnames);
                $by->date = userdate($post->modified);

                if ($forumtracked) {
                    if (!empty($post->postread)) {
                        $style = '<span class="forumthread read">';
                    } else {
                        $style = '<span class="forumthread unread">';
                    }
                } else {
                    $style = '<span class="forumthread">';
                }
                echo $style."<a name=\"$post->id\"></a>".
                     "<a href=\"discuss.php?d=$post->discussion&amp;parent=$post->id\">".format_string($post->subject,true)."</a> ";
                print_string("bynameondate", "forum", $by);
                echo "</span>";
            }

            if (forum_print_posts_threaded($course, $cm, $forum, $discussion, $post, $depth-1, $ratings, $reply, $forumtracked, $posts)) {
                $ratingsmenuused = true;
            }
            echo "</div>\n";
        }
    }
    return $ratingsmenuused;
}

/**
 * @todo Document this function
 * @global object
 * @global object
 */
function forum_print_posts_nested($course, &$cm, $forum, $discussion, $parent, $ratings, $reply, $forumtracked, $posts) {
    global $USER, $CFG;

    $link  = false;
    $ratingsmenuused = false;

    if (!empty($posts[$parent->id]->children)) {
        $posts = $posts[$parent->id]->children;

        foreach ($posts as $post) {

            echo '<div class="indent">';
            if (empty($USER->id)) {
                $ownpost = false;
            } else {
                $ownpost = ($USER->id == $post->userid);
            }

            $post->subject = format_string($post->subject);
            $postread = !empty($post->postread);

            if (forum_print_post($post, $discussion, $forum, $cm, $course, $ownpost, $reply, $link, $ratings,
                                 '', '', $postread, true, $forumtracked)) {
                $ratingsmenuused = true;
            }
            if (forum_print_posts_nested($course, $cm, $forum, $discussion, $post, $ratings, $reply, $forumtracked, $posts)) {
                $ratingsmenuused = true;
            }
            echo "</div>\n";
        }
    }
    return $ratingsmenuused;
}

/**
 * Returns all forum posts since a given time in specified forum.
 *
 * @todo Document this functions args
 * @global object
 * @global object
 * @global object
 * @global object
 */
function forum_get_recent_mod_activity(&$activities, &$index, $timestart, $courseid, $cmid, $userid=0, $groupid=0)  {
    global $CFG, $COURSE, $USER, $DB;

    if ($COURSE->id == $courseid) {
        $course = $COURSE;
    } else {
        $course = $DB->get_record('course', array('id' => $courseid));
    }

    $modinfo =& get_fast_modinfo($course);

    $cm = $modinfo->cms[$cmid];
    $params = array($timestart, $cm->instance);

    if ($userid) {
        $userselect = "AND u.id = ?";
        $params[] = $userid;
    } else {
        $userselect = "";
    }

    if ($groupid) {
        $groupselect = "AND gm.groupid = ?";
        $groupjoin   = "JOIN {groups_members} gm ON  gm.userid=u.id";
        $params[] = $groupid;
    } else {
        $groupselect = "";
        $groupjoin   = "";
    }

    if (!$posts = $DB->get_records_sql("SELECT p.*, f.type AS forumtype, d.forum, d.groupid,
                                              d.timestart, d.timeend, d.userid AS duserid,
                                              u.firstname, u.lastname, u.email, u.picture, u.imagealt
                                         FROM {forum_posts} p
                                              JOIN {forum_discussions} d ON d.id = p.discussion
                                              JOIN {forum} f             ON f.id = d.forum
                                              JOIN {user} u              ON u.id = p.userid
                                              $groupjoin
                                        WHERE p.created > ? AND f.id = ?
                                              $userselect $groupselect
                                     ORDER BY p.id ASC", $params)) { // order by initial posting date
         return;
    }

    $groupmode       = groups_get_activity_groupmode($cm, $course);
    $cm_context      = get_context_instance(CONTEXT_MODULE, $cm->id);
    $viewhiddentimed = has_capability('mod/forum:viewhiddentimedposts', $cm_context);
    $accessallgroups = has_capability('moodle/site:accessallgroups', $cm_context);

    if (is_null($modinfo->groups)) {
        $modinfo->groups = groups_get_user_groups($course->id); // load all my groups and cache it in modinfo
    }

    $printposts = array();
    foreach ($posts as $post) {

        if (!empty($CFG->forum_enabletimedposts) and $USER->id != $post->duserid
          and (($post->timestart > 0 and $post->timestart > time()) or ($post->timeend > 0 and $post->timeend < time()))) {
            if (!$viewhiddentimed) {
                continue;
            }
        }

        if ($groupmode) {
            if ($post->groupid == -1 or $groupmode == VISIBLEGROUPS or $accessallgroups) {
                // oki (Open discussions have groupid -1)
            } else {
                // separate mode
                if (isguestuser()) {
                    // shortcut
                    continue;
                }

                if (!array_key_exists($post->groupid, $modinfo->groups[0])) {
                    continue;
                }
            }
        }

        $printposts[] = $post;
    }

    if (!$printposts) {
        return;
    }

    $aname = format_string($cm->name,true);

    foreach ($printposts as $post) {
        $tmpactivity = new object();

        $tmpactivity->type         = 'forum';
        $tmpactivity->cmid         = $cm->id;
        $tmpactivity->name         = $aname;
        $tmpactivity->sectionnum   = $cm->sectionnum;
        $tmpactivity->timestamp    = $post->modified;

        $tmpactivity->content = new object();
        $tmpactivity->content->id         = $post->id;
        $tmpactivity->content->discussion = $post->discussion;
        $tmpactivity->content->subject    = format_string($post->subject);
        $tmpactivity->content->parent     = $post->parent;

        $tmpactivity->user = new object();
        $tmpactivity->user->id        = $post->userid;
        $tmpactivity->user->firstname = $post->firstname;
        $tmpactivity->user->lastname  = $post->lastname;
        $tmpactivity->user->picture   = $post->picture;
        $tmpactivity->user->imagealt  = $post->imagealt;
        $tmpactivity->user->email     = $post->email;

        $activities[$index++] = $tmpactivity;
    }

    return;
}

/**
 * @todo Document this function
 * @global object
 */
function forum_print_recent_mod_activity($activity, $courseid, $detail, $modnames, $viewfullnames) {
    global $CFG, $OUTPUT;

    if ($activity->content->parent) {
        $class = 'reply';
    } else {
        $class = 'discussion';
    }

    echo '<table border="0" cellpadding="3" cellspacing="0" class="forum-recent">';

    echo "<tr><td class=\"userpicture\" valign=\"top\">";
    print_user_picture($activity->user, $courseid);
    echo "</td><td class=\"$class\">";

    echo '<div class="title">';
    if ($detail) {
        $aname = s($activity->name);
        echo "<img src=\"" . $OUTPUT->mod_icon_url('icon', $activity->type) . "\" ".
             "class=\"icon\" alt=\"{$aname}\" />";
    }
    echo "<a href=\"$CFG->wwwroot/mod/forum/discuss.php?d={$activity->content->discussion}"
         ."#p{$activity->content->id}\">{$activity->content->subject}</a>";
    echo '</div>';

    echo '<div class="user">';
    $fullname = fullname($activity->user, $viewfullnames);
    echo "<a href=\"$CFG->wwwroot/user/view.php?id={$activity->user->id}&amp;course=$courseid\">"
         ."{$fullname}</a> - ".userdate($activity->timestamp);
    echo '</div>';
      echo "</td></tr></table>";

    return;
}

/**
 * recursively sets the discussion field to $discussionid on $postid and all its children
 * used when pruning a post
 *
 * @global object
 * @param int $postid
 * @param int $discussionid
 * @return bool
 */
function forum_change_discussionid($postid, $discussionid) {
    global $DB;
    $DB->set_field('forum_posts', 'discussion', $discussionid, array('id' => $postid));
    if ($posts = $DB->get_records('forum_posts', array('parent' => $postid))) {
        foreach ($posts as $post) {
            forum_change_discussionid($post->id, $discussionid);
        }
    }
    return true;
}

/**
 * Prints the editing button on subscribers page
 *
 * @global object
 * @global object
 * @param int $courseid
 * @param int $forumid
 * @return string
 */
function forum_update_subscriptions_button($courseid, $forumid) {
    global $CFG, $USER;

    if (!empty($USER->subscriptionsediting)) {
        $string = get_string('turneditingoff');
        $edit = "off";
    } else {
        $string = get_string('turneditingon');
        $edit = "on";
    }

    return "<form $CFG->frametarget method=\"get\" action=\"$CFG->wwwroot/mod/forum/subscribers.php\">".
           "<input type=\"hidden\" name=\"id\" value=\"$forumid\" />".
           "<input type=\"hidden\" name=\"edit\" value=\"$edit\" />".
           "<input type=\"submit\" value=\"$string\" /></form>";
}

/**
 * This function gets run whenever a role is assigned to a user in a context
 *
 * @param integer $userid
 * @param object $context
 * @param itn $roleid
 * @return bool
 */
function forum_role_assign($userid, $context, $roleid) {
    // check to see if this role comes with mod/forum:initialsubscriptions
    $cap = role_context_capabilities($roleid, $context, 'mod/forum:initialsubscriptions');
    $cap1 = role_context_capabilities($roleid, $context, 'moodle/course:view');
    // we are checking the role because has_capability() will pull this capability out
    // from other roles this user might have and resolve them, which is no good
    // the role needs course view to
    if (isset($cap['mod/forum:initialsubscriptions']) && $cap['mod/forum:initialsubscriptions'] == CAP_ALLOW &&
        isset($cap1['moodle/course:view']) && $cap1['moodle/course:view'] == CAP_ALLOW) {
        return forum_add_user_default_subscriptions($userid, $context);
    } else {
        // MDL-8981, do not subscribe to forum
        return true;
    }
}


/**
 * This function gets run whenever a role is assigned to a user in a context
 *
 * @param integer $userid
 * @param object $context
 * @return bool
 */
function forum_role_unassign($userid, $context) {
    if (empty($context->contextlevel)) {
        return false;
    }

    forum_remove_user_subscriptions($userid, $context);
    forum_remove_user_tracking($userid, $context);

    return true;
}


/**
 * Add subscriptions for new users
 *
 * @global object
 * @uses CONTEXT_SYSTEM
 * @uses CONTEXT_COURSE
 * @uses CONTEXT_COURSECAT
 * @uses FORUM_INITIALSUBSCRIBE
 * @param int $userid
 * @param object $context
 * @return bool
 */
function forum_add_user_default_subscriptions($userid, $context) {
    global $DB;
    if (empty($context->contextlevel)) {
        return false;
    }

    switch ($context->contextlevel) {

        case CONTEXT_SYSTEM:   // For the whole site
             $rs = $DB->get_recordset('course',null,'','id');
             foreach ($rs as $course) {
                 $subcontext = get_context_instance(CONTEXT_COURSE, $course->id);
                 forum_add_user_default_subscriptions($userid, $subcontext);
             }
             $rs->close();
             break;

        case CONTEXT_COURSECAT:   // For a whole category
             $rs = $DB->get_recordset('course', array('category' => $context->instanceid),'','id');
             foreach ($rs as $course) {
                 $subcontext = get_context_instance(CONTEXT_COURSE, $course->id);
                 forum_add_user_default_subscriptions($userid, $subcontext);
             }
             $rs->close();
             if ($categories = $DB->get_records('course_categories', array('parent' => $context->instanceid))) {
                 foreach ($categories as $category) {
                     $subcontext = get_context_instance(CONTEXT_COURSECAT, $category->id);
                     forum_add_user_default_subscriptions($userid, $subcontext);
                 }
             }
             break;


        case CONTEXT_COURSE:   // For a whole course
             if ($course = $DB->get_record('course', array('id' => $context->instanceid))) {
                 if ($forums = get_all_instances_in_course('forum', $course, $userid, false)) {
                     foreach ($forums as $forum) {
                         if ($forum->forcesubscribe != FORUM_INITIALSUBSCRIBE) {
                             continue;
                         }
                         if ($modcontext = get_context_instance(CONTEXT_MODULE, $forum->coursemodule)) {
                             if (has_capability('mod/forum:viewdiscussion', $modcontext, $userid)) {
                                 forum_subscribe($userid, $forum->id);
                             }
                         }
                     }
                 }
             }
             break;

        case CONTEXT_MODULE:   // Just one forum
             if ($cm = get_coursemodule_from_id('forum', $context->instanceid)) {
                 if ($forum = $DB->get_record('forum', array('id' => $cm->instance))) {
                     if ($forum->forcesubscribe != FORUM_INITIALSUBSCRIBE) {
                         continue;
                     }
                     if (has_capability('mod/forum:viewdiscussion', $context, $userid)) {
                         forum_subscribe($userid, $forum->id);
                     }
                 }
             }
             break;
    }

    return true;
}


/**
 * Remove subscriptions for a user in a context
 *
 * @global object
 * @global object
 * @uses CONTEXT_SYSTEM
 * @uses CONTEXT_COURSECAT
 * @uses CONTEXT_COURSE
 * @uses CONTEXT_MODULE
 * @param int $userid
 * @param object $context
 * @return bool
 */
function forum_remove_user_subscriptions($userid, $context) {

    global $CFG, $DB;

    if (empty($context->contextlevel)) {
        return false;
    }

    switch ($context->contextlevel) {

        case CONTEXT_SYSTEM:   // For the whole site
            //if ($courses = get_my_courses($userid)) {
            // find all courses in which this user has a forum subscription
            if ($courses = $DB->get_records_sql("SELECT c.id
                                                  FROM {course} c,
                                                       {forum_subscriptions} fs,
                                                       {forum} f
                                                       WHERE c.id = f.course AND f.id = fs.forum AND fs.userid = ?
                                                       GROUP BY c.id", array($userid))) {

                foreach ($courses as $course) {
                    $subcontext = get_context_instance(CONTEXT_COURSE, $course->id);
                    forum_remove_user_subscriptions($userid, $subcontext);
                }
            }
            break;

        case CONTEXT_COURSECAT:   // For a whole category
             if ($courses = $DB->get_records('course', array('category' => $context->instanceid), '', 'id')) {
                 foreach ($courses as $course) {
                     $subcontext = get_context_instance(CONTEXT_COURSE, $course->id);
                     forum_remove_user_subscriptions($userid, $subcontext);
                 }
             }
             if ($categories = $DB->get_records('course_categories', array('parent' => $context->instanceid), '', 'id')) {
                 foreach ($categories as $category) {
                     $subcontext = get_context_instance(CONTEXT_COURSECAT, $category->id);
                     forum_remove_user_subscriptions($userid, $subcontext);
                 }
             }
             break;

        case CONTEXT_COURSE:   // For a whole course
             if ($course = $DB->get_record('course', array('id' => $context->instanceid), 'id')) {
                // find all forums in which this user has a subscription, and its coursemodule id
                if ($forums = $DB->get_records_sql("SELECT f.id, cm.id as coursemodule
                                                     FROM {forum} f,
                                                          {modules} m,
                                                          {course_modules} cm,
                                                          {forum_subscriptions} fs
                                                    WHERE fs.userid = ? AND f.course = ?
                                                          AND fs.forum = f.id AND cm.instance = f.id
                                                          AND cm.module = m.id AND m.name = 'forum'", array($userid, $context->instanceid))) {

                     foreach ($forums as $forum) {
                         if ($modcontext = get_context_instance(CONTEXT_MODULE, $forum->coursemodule)) {
                             if (!has_capability('mod/forum:viewdiscussion', $modcontext, $userid)) {
                                 forum_unsubscribe($userid, $forum->id);
                             }
                         }
                     }
                 }
             }
             break;

        case CONTEXT_MODULE:   // Just one forum
             if ($cm = get_coursemodule_from_id('forum', $context->instanceid)) {
                 if ($forum = $DB->get_record('forum', array('id' => $cm->instance))) {
                     if (!has_capability('mod/forum:viewdiscussion', $context, $userid)) {
                         forum_unsubscribe($userid, $forum->id);
                     }
                 }
             }
             break;
    }

    return true;
}

// Functions to do with read tracking.

/**
 * Remove post tracking for a user in a context
 *
 * @global object
 * @global object
 * @uses CONTEXT_SYSTEM
 * @uses CONTEXT_COURSECAT
 * @uses CONTEXT_COURSE
 * @uses CONTEXT_MODULE
 * @param int $userid
 * @param object $context
 * @return bool
 */
function forum_remove_user_tracking($userid, $context) {

    global $CFG, $DB;

    if (empty($context->contextlevel)) {
        return false;
    }

    switch ($context->contextlevel) {

        case CONTEXT_SYSTEM:   // For the whole site
            // find all courses in which this user has tracking info
            $allcourses = array();
            if ($courses = $DB->get_records_sql("SELECT c.id
                                                  FROM {course} c,
                                                       {forum_read} fr,
                                                       {forum} f
                                                       WHERE c.id = f.course AND f.id = fr.forumid AND fr.userid = ?
                                                       GROUP BY c.id", array($userid))) {

                $allcourses = $allcourses + $courses;
            }
            if ($courses = $DB->get_records_sql("SELECT c.id
                                              FROM {course} c,
                                                   {forum_track_prefs} ft,
                                                   {forum} f
                                             WHERE c.id = f.course AND f.id = ft.forumid AND ft.userid = ?", array($userid))) {

                $allcourses = $allcourses + $courses;
            }
            foreach ($allcourses as $course) {
                $subcontext = get_context_instance(CONTEXT_COURSE, $course->id);
                forum_remove_user_tracking($userid, $subcontext);
            }
            break;

        case CONTEXT_COURSECAT:   // For a whole category
             if ($courses = $DB->get_records('course', array('category' => $context->instanceid), '', 'id')) {
                 foreach ($courses as $course) {
                     $subcontext = get_context_instance(CONTEXT_COURSE, $course->id);
                     forum_remove_user_tracking($userid, $subcontext);
                 }
             }
             if ($categories = $DB->get_records('course_categories', array('parent' => $context->instanceid), '', 'id')) {
                 foreach ($categories as $category) {
                     $subcontext = get_context_instance(CONTEXT_COURSECAT, $category->id);
                     forum_remove_user_tracking($userid, $subcontext);
                 }
             }
             break;

        case CONTEXT_COURSE:   // For a whole course
             if ($course = $DB->get_record('course', array('id' => $context->instanceid), 'id')) {
                // find all forums in which this user has reading tracked
                if ($forums = $DB->get_records_sql("SELECT f.id, cm.id as coursemodule
                                                 FROM {forum} f,
                                                      {modules} m,
                                                      {course_modules} cm,
                                                      {forum_read} fr
                                                WHERE fr.userid = ? AND f.course = ?
                                                      AND fr.forumid = f.id AND cm.instance = f.id
                                                      AND cm.module = m.id AND m.name = 'forum'", array($userid, $context->instanceid))) {

                     foreach ($forums as $forum) {
                         if ($modcontext = get_context_instance(CONTEXT_MODULE, $forum->coursemodule)) {
                             if (!has_capability('mod/forum:viewdiscussion', $modcontext, $userid)) {
                                forum_tp_delete_read_records($userid, -1, -1, $forum->id);
                             }
                         }
                     }
                 }

                // find all forums in which this user has a disabled tracking
                if ($forums = $DB->get_records_sql("SELECT f.id, cm.id as coursemodule
                                                 FROM {forum} f,
                                                      {modules} m,
                                                      {course_modules} cm,
                                                      {forum_track_prefs} ft
                                                WHERE ft.userid = ? AND f.course = ?
                                                      AND ft.forumid = f.id AND cm.instance = f.id
                                                      AND cm.module = m.id AND m.name = 'forum'", array($userid, $context->instanceid))) {

                     foreach ($forums as $forum) {
                         if ($modcontext = get_context_instance(CONTEXT_MODULE, $forum->coursemodule)) {
                             if (!has_capability('mod/forum:viewdiscussion', $modcontext, $userid)) {
                                $DB->delete_records('forum_track_prefs', array('userid' => $userid, 'forumid' => $forum->id));
                             }
                         }
                     }
                 }
             }
             break;

        case CONTEXT_MODULE:   // Just one forum
             if ($cm = get_coursemodule_from_id('forum', $context->instanceid)) {
                 if ($forum = $DB->get_record('forum', array('id' => $cm->instance))) {
                     if (!has_capability('mod/forum:viewdiscussion', $context, $userid)) {
                        $DB->delete_records('forum_track_prefs', array('userid' => $userid, 'forumid' => $forum->id));
                        forum_tp_delete_read_records($userid, -1, -1, $forum->id);
                     }
                 }
             }
             break;
    }

    return true;
}

/**
 * Mark posts as read.
 *
 * @global object
 * @global object
 * @param object $user object
 * @param array $postids array of post ids
 * @return boolean success
 */
function forum_tp_mark_posts_read($user, $postids) {
    global $CFG, $DB;

    if (!forum_tp_can_track_forums(false, $user)) {
        return true;
    }

    $status = true;

    $now = time();
    $cutoffdate = $now - ($CFG->forum_oldpostdays * 24 * 3600);

    if (empty($postids)) {
        return true;

    } else if (count($postids) > 200) {
        while ($part = array_splice($postids, 0, 200)) {
            $status = forum_tp_mark_posts_read($user, $part) && $status;
        }
        return $status;
    }

    list($usql, $params) = $DB->get_in_or_equal($postids);
    $params[] = $user->id;

    $sql = "SELECT id
              FROM {forum_read}
             WHERE postid $usql AND userid = ?";
    if ($existing = $DB->get_records_sql($sql, $params)) {
        $existing = array_keys($existing);
    } else {
        $existing = array();
    }

    $new = array_diff($postids, $existing);

    if ($new) {
        list($usql, $new_params) = $DB->get_in_or_equal($new);
        $params = array($user->id, $now, $now, $user->id, $cutoffdate);
        $params = array_merge($params, $new_params);

        $sql = "INSERT INTO {forum_read} (userid, postid, discussionid, forumid, firstread, lastread)

                SELECT ?, p.id, p.discussion, d.forum, ?, ?
                  FROM {forum_posts} p
                       JOIN {forum_discussions} d       ON d.id = p.discussion
                       JOIN {forum} f                   ON f.id = d.forum
                       LEFT JOIN {forum_track_prefs} tf ON (tf.userid = ? AND tf.forumid = f.id)
                 WHERE p.id $usql
                       AND p.modified >= ?
                       AND (f.trackingtype = ".FORUM_TRACKING_ON."
                            OR (f.trackingtype = ".FORUM_TRACKING_OPTIONAL." AND tf.id IS NULL))";
        $status = $DB->execute($sql, $params) && $status;
    }

    if ($existing) {
        list($usql, $new_params) = $DB->get_in_or_equal($existing);
        $params = array($now, $user->id);
        $params = array_merge($params, $new_params);

        $sql = "UPDATE {forum_read}
                   SET lastread = ?
                 WHERE userid = ? AND postid $usql";
        $status = $DB->execute($sql, $params) && $status;
    }

    return $status;
}

/**
 * Mark post as read.
 * @global object
 * @global object
 * @param int $userid
 * @param int $postid
 */
function forum_tp_add_read_record($userid, $postid) {
    global $CFG, $DB;

    $now = time();
    $cutoffdate = $now - ($CFG->forum_oldpostdays * 24 * 3600);

    if (!$DB->record_exists('forum_read', array('userid' => $userid, 'postid' => $postid))) {
        $sql = "INSERT INTO {forum_read} (userid, postid, discussionid, forumid, firstread, lastread)

                SELECT ?, p.id, p.discussion, d.forum, ?, ?
                  FROM {forum_posts} p
                       JOIN {forum_discussions} d ON d.id = p.discussion
                 WHERE p.id = ? AND p.modified >= ?";
        return $DB->execute($sql, array($userid, $now, $now, $postid, $cutoffdate));

    } else {
        $sql = "UPDATE {forum_read}
                   SET lastread = ?
                 WHERE userid = ? AND postid = ?";
        return $DB->execute($sql, array($now, $userid, $userid));
    }
}

/**
 * Returns all records in the 'forum_read' table matching the passed keys, indexed
 * by userid.
 *
 * @global object
 * @param int $userid
 * @param int $postid
 * @param int $discussionid
 * @param int $forumid
 * @return array
 */
function forum_tp_get_read_records($userid=-1, $postid=-1, $discussionid=-1, $forumid=-1) {
    global $DB;
    $select = '';
    $params = array();

    if ($userid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'userid = ?';
        $params[] = $userid;
    }
    if ($postid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'postid = ?';
        $params[] = $postid;
    }
    if ($discussionid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'discussionid = ?';
        $params[] = $discussionid;
    }
    if ($forumid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'forumid = ?';
        $params[] = $forumid;
    }

    return $DB->get_records_select('forum_read', $select, $params);
}

/**
 * Returns all read records for the provided user and discussion, indexed by postid.
 *
 * @global object
 * @param inti $userid
 * @param int $discussionid
 */
function forum_tp_get_discussion_read_records($userid, $discussionid) {
    global $DB;
    $select = 'userid = ? AND discussionid = ?';
    $fields = 'postid, firstread, lastread';
    return $DB->get_records_select('forum_read', $select, array($userid, $discussionid), '', $fields);
}

/**
 * If its an old post, do nothing. If the record exists, the maintenance will clear it up later.
 * 
 * @return bool
 */
function forum_tp_mark_post_read($userid, $post, $forumid) {
    if (!forum_tp_is_post_old($post)) {
        return forum_tp_add_read_record($userid, $post->id);
    } else {
        return true;
    }
}

/**
 * Marks a whole forum as read, for a given user
 *
 * @global object
 * @global object
 * @param object $user
 * @param int $forumid
 * @param int|bool $groupid
 * @return bool
 */
function forum_tp_mark_forum_read($user, $forumid, $groupid=false) {
    global $CFG, $DB;

    $cutoffdate = time() - ($CFG->forum_oldpostdays*24*60*60);

    $groupsel = "";
    $params = array($user->id, $forumid, $cutoffdate);

    if ($groupid !== false) {
        $groupsel = " AND (d.groupid = ? OR d.groupid = -1)";
        $params[] = $groupid;
    }

    $sql = "SELECT p.id
              FROM {forum_posts} p
                   LEFT JOIN {forum_discussions} d ON d.id = p.discussion
                   LEFT JOIN {forum_read} r        ON (r.postid = p.id AND r.userid = ?)
             WHERE d.forum = ?
                   AND p.modified >= ? AND r.id is NULL
                   $groupsel";

    if ($posts = $DB->get_records_sql($sql, $params)) {
        $postids = array_keys($posts);
        return forum_tp_mark_posts_read($user, $postids);
    }

    return true;
}

/**
 * Marks a whole discussion as read, for a given user
 *
 * @global object
 * @global object
 * @param object $user
 * @param int $discussionid
 * @return bool
 */
function forum_tp_mark_discussion_read($user, $discussionid) {
    global $CFG, $DB;

    $cutoffdate = time() - ($CFG->forum_oldpostdays*24*60*60);

    $sql = "SELECT p.id
              FROM {forum_posts} p
                   LEFT JOIN {forum_read} r ON (r.postid = p.id AND r.userid = ?)
             WHERE p.discussion = ?
                   AND p.modified >= ? AND r.id is NULL";

    if ($posts = $DB->get_records_sql($sql, array($user->id, $discussionid, $cutoffdate))) {
        $postids = array_keys($posts);
        return forum_tp_mark_posts_read($user, $postids);
    }

    return true;
}

/**
 * @global object
 * @param int $userid
 * @param object $post
 */
function forum_tp_is_post_read($userid, $post) {
    global $DB;
    return (forum_tp_is_post_old($post) ||
            $DB->record_exists('forum_read', array('userid' => $userid, 'postid' => $post->id)));
}

/**
 * @global object
 * @param object $post
 * @param int $time Defautls to time()
 */
function forum_tp_is_post_old($post, $time=null) {
    global $CFG;

    if (is_null($time)) {
        $time = time();
    }
    return ($post->modified < ($time - ($CFG->forum_oldpostdays * 24 * 3600)));
}

/**
 * Returns the count of records for the provided user and discussion.
 *
 * @global object
 * @global object
 * @param int $userid
 * @param int $discussionid
 * @return bool
 */
function forum_tp_count_discussion_read_records($userid, $discussionid) {
    global $CFG, $DB;

    $cutoffdate = isset($CFG->forum_oldpostdays) ? (time() - ($CFG->forum_oldpostdays*24*60*60)) : 0;

    $sql = 'SELECT COUNT(DISTINCT p.id) '.
           'FROM {forum_discussions} d '.
           'LEFT JOIN {forum_read} r ON d.id = r.discussionid AND r.userid = ? '.
           'LEFT JOIN {forum_posts} p ON p.discussion = d.id '.
                'AND (p.modified < ? OR p.id = r.postid) '.
           'WHERE d.id = ? ';

    return ($DB->count_records_sql($sql, array($userid, $cutoffdate, $discussionid)));
}

/**
 * Returns the count of records for the provided user and discussion.
 *
 * @global object
 * @global object
 * @param int $userid
 * @param int $discussionid
 * @return int
 */
function forum_tp_count_discussion_unread_posts($userid, $discussionid) {
    global $CFG, $DB;

    $cutoffdate = isset($CFG->forum_oldpostdays) ? (time() - ($CFG->forum_oldpostdays*24*60*60)) : 0;

    $sql = 'SELECT COUNT(p.id) '.
           'FROM {forum_posts} p '.
           'LEFT JOIN {forum_read} r ON r.postid = p.id AND r.userid = ? '.
           'WHERE p.discussion = ? '.
                'AND p.modified >= ? AND r.id is NULL';

    return $DB->count_records_sql($sql, array($userid, $cutoffdate, $discussionid));
}

/**
 * Returns the count of posts for the provided forum and [optionally] group.
 * @global object
 * @global object
 * @param int $forumid
 * @param int|bool $groupid
 * @return int
 */
function forum_tp_count_forum_posts($forumid, $groupid=false) {
    global $CFG, $DB;
    $params = array($forumid);
    $sql = 'SELECT COUNT(*) '.
           'FROM {forum_posts} fp,{forum_discussions} fd '.
           'WHERE fd.forum = ? AND fp.discussion = fd.id';
    if ($groupid !== false) {
        $sql .= ' AND (fd.groupid = ? OR fd.groupid = -1)';
        $params[] = $groupid;
    }
    $count = $DB->count_records_sql($sql, $params);


    return $count;
}

/**
 * Returns the count of records for the provided user and forum and [optionally] group.
 * @global object
 * @global object
 * @param int $userid
 * @param int $forumid
 * @param int|bool $groupid
 * @return int
 */
function forum_tp_count_forum_read_records($userid, $forumid, $groupid=false) {
    global $CFG, $DB;

    $cutoffdate = time() - ($CFG->forum_oldpostdays*24*60*60);

    $groupsel = '';
    $params = array($userid, $forumid, $cutoffdate);
    if ($groupid !== false) {
        $groupsel = "AND (d.groupid = ? OR d.groupid = -1)";
        $params[] = $groupid;
    }

    $sql = "SELECT COUNT(p.id)
              FROM  {forum_posts} p
                    JOIN {forum_discussions} d ON d.id = p.discussion
                    LEFT JOIN {forum_read} r   ON (r.postid = p.id AND r.userid= ?)
              WHERE d.forum = ?
                    AND (p.modified < $cutoffdate OR (p.modified >= ? AND r.id IS NOT NULL))
                    $groupsel";

    return $DB->get_field_sql($sql, $params);
}

/**
 * Returns the count of records for the provided user and course.
 * Please note that group access is ignored!
 *
 * @global object
 * @global object
 * @param int $userid
 * @param int $courseid
 * @return array
 */
function forum_tp_get_course_unread_posts($userid, $courseid) {
    global $CFG, $DB;

    $now = round(time(), -2); // db cache friendliness
    $cutoffdate = $now - ($CFG->forum_oldpostdays*24*60*60);
    $params = array($userid, $userid, $courseid, $cutoffdate);

    if (!empty($CFG->forum_enabletimedposts)) {
        $timedsql = "AND d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?)";
        $params[] = $now;
        $params[] = $now;
    } else {
        $timedsql = "";
    }

    $sql = "SELECT f.id, COUNT(p.id) AS unread
              FROM {forum_posts} p
                   JOIN {forum_discussions} d       ON d.id = p.discussion
                   JOIN {forum} f                   ON f.id = d.forum
                   JOIN {course} c                  ON c.id = f.course
                   LEFT JOIN {forum_read} r         ON (r.postid = p.id AND r.userid = ?)
                   LEFT JOIN {forum_track_prefs} tf ON (tf.userid = ? AND tf.forumid = f.id)
             WHERE f.course = ?
                   AND p.modified >= ? AND r.id is NULL
                   AND (f.trackingtype = ".FORUM_TRACKING_ON."
                        OR (f.trackingtype = ".FORUM_TRACKING_OPTIONAL." AND tf.id IS NULL))
                   $timedsql
          GROUP BY f.id";

    if ($return = $DB->get_records_sql($sql, $params)) {
        return $return;
    }

    return array();
}

/**
 * Returns the count of records for the provided user and forum and [optionally] group.
 *
 * @global object
 * @global object
 * @global object
 * @param object $cm
 * @param object $course
 * @return int
 */
function forum_tp_count_forum_unread_posts($cm, $course) {
    global $CFG, $USER, $DB;

    static $readcache = array();

    $forumid = $cm->instance;

    if (!isset($readcache[$course->id])) {
        $readcache[$course->id] = array();
        if ($counts = forum_tp_get_course_unread_posts($USER->id, $course->id)) {
            foreach ($counts as $count) {
                $readcache[$course->id][$count->id] = $count->unread;
            }
        }
    }

    if (empty($readcache[$course->id][$forumid])) {
        // no need to check group mode ;-)
        return 0;
    }

    $groupmode = groups_get_activity_groupmode($cm, $course);

    if ($groupmode != SEPARATEGROUPS) {
        return $readcache[$course->id][$forumid];
    }

    if (has_capability('moodle/site:accessallgroups', get_context_instance(CONTEXT_MODULE, $cm->id))) {
        return $readcache[$course->id][$forumid];
    }

    require_once($CFG->dirroot.'/course/lib.php');

    $modinfo =& get_fast_modinfo($course);
    if (is_null($modinfo->groups)) {
        $modinfo->groups = groups_get_user_groups($course->id, $USER->id);
    }

    if (empty($CFG->enablegroupings)) {
        $mygroups = $modinfo->groups[0];
    } else {
        $mygroups = $modinfo->groups[$cm->groupingid];
    }

    // add all groups posts
    if (empty($mygroups)) {
        $mygroups = array(-1=>-1);
    } else {
        $mygroups[-1] = -1;
    }

    list ($groups_sql, $groups_params) = $DB->get_in_or_equal($mygroups);

    $now = round(time(), -2); // db cache friendliness
    $cutoffdate = $now - ($CFG->forum_oldpostdays*24*60*60);
    $params = array($USER->id, $forumid, $cutoffdate);

    if (!empty($CFG->forum_enabletimedposts)) {
        $timedsql = "AND d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?)";
        $params[] = $now;
        $params[] = $now;
    } else {
        $timedsql = "";
    }

    $params = array_merge($params, $groups_params);

    $sql = "SELECT COUNT(p.id)
              FROM {forum_posts} p
                   JOIN {forum_discussions} d ON p.discussion = d.id
                   LEFT JOIN {forum_read} r   ON (r.postid = p.id AND r.userid = ?)
             WHERE d.forum = ?
                   AND p.modified >= ? AND r.id is NULL
                   $timedsql
                   AND d.groupid $groups_sql";

    return $DB->get_field_sql($sql, $params);
}

/**
 * Deletes read records for the specified index. At least one parameter must be specified.
 *
 * @global object
 * @param int $userid
 * @param int $postid
 * @param int $discussionid
 * @param int $forumid
 * @return bool
 */
function forum_tp_delete_read_records($userid=-1, $postid=-1, $discussionid=-1, $forumid=-1) {
    global $DB;
    $params = array();

    $select = '';
    if ($userid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'userid = ?';
        $params[] = $userid;
    }
    if ($postid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'postid = ?';
        $params[] = $postid;
    }
    if ($discussionid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'discussionid = ?';
        $params[] = $discussionid;
    }
    if ($forumid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'forumid = ?';
        $params[] = $forumid;
    }
    if ($select == '') {
        return false;
    }
    else {
        return $DB->delete_records_select('forum_read', $select, $params);
    }
}
/**
 * Get a list of forums not tracked by the user.
 *
 * @global object
 * @global object
 * @param int $userid The id of the user to use.
 * @param int $courseid The id of the course being checked.
 * @return mixed An array indexed by forum id, or false.
 */
function forum_tp_get_untracked_forums($userid, $courseid) {
    global $CFG, $DB;

    $sql = "SELECT f.id
              FROM {forum} f
                   LEFT JOIN {forum_track_prefs} ft ON (ft.forumid = f.id AND ft.userid = ?)
             WHERE f.course = ?
                   AND (f.trackingtype = ".FORUM_TRACKING_OFF."
                        OR (f.trackingtype = ".FORUM_TRACKING_OPTIONAL." AND ft.id IS NOT NULL))";

    if ($forums = $DB->get_records_sql($sql, array($userid, $courseid))) {
        foreach ($forums as $forum) {
            $forums[$forum->id] = $forum;
        }
        return $forums;

    } else {
        return array();
    }
}

/**
 * Determine if a user can track forums and optionally a particular forum.
 * Checks the site settings, the user settings and the forum settings (if
 * requested).
 *
 * @global object
 * @global object
 * @global object
 * @param mixed $forum The forum object to test, or the int id (optional).
 * @param mixed $userid The user object to check for (optional).
 * @return boolean
 */
function forum_tp_can_track_forums($forum=false, $user=false) {
    global $USER, $CFG, $DB;

    // if possible, avoid expensive
    // queries
    if (empty($CFG->forum_trackreadposts)) {    
        return false;
    }

    if ($user === false) {
        $user = $USER;
    }

    if (isguestuser($user) or empty($user->id)) {
        return false;
    }

    if ($forum === false) {
        // general abitily to track forums
        return (bool)$user->trackforums;
    }


    // Work toward always passing an object...
    if (is_numeric($forum)) {
        debugging('Better use proper forum object.', DEBUG_DEVELOPER);
        $forum = $DB->get_record('forum', array('id' => $forum), '', 'id,trackingtype');
    }

    $forumallows = ($forum->trackingtype == FORUM_TRACKING_OPTIONAL);
    $forumforced = ($forum->trackingtype == FORUM_TRACKING_ON);

    return ($forumforced || $forumallows)  && !empty($user->trackforums);
}

/**
 * Tells whether a specific forum is tracked by the user. A user can optionally
 * be specified. If not specified, the current user is assumed.
 *
 * @global object
 * @global object
 * @global object
 * @param mixed $forum If int, the id of the forum being checked; if object, the forum object
 * @param int $userid The id of the user being checked (optional).
 * @return boolean
 */
function forum_tp_is_tracked($forum, $user=false) {
    global $USER, $CFG, $DB;

    if ($user === false) {
        $user = $USER;
    }

    if (isguestuser($user) or empty($user->id)) {
        return false;
    }

    // Work toward always passing an object...
    if (is_numeric($forum)) {
        debugging('Better use proper forum object.', DEBUG_DEVELOPER);
        $forum = $DB->get_record('forum', array('id' => $forum));
    }

    if (!forum_tp_can_track_forums($forum, $user)) {
        return false;
    }

    $forumallows = ($forum->trackingtype == FORUM_TRACKING_OPTIONAL);
    $forumforced = ($forum->trackingtype == FORUM_TRACKING_ON);

    return $forumforced ||
           ($forumallows && $DB->get_record('forum_track_prefs', array('userid' => $user->id, 'forumid' => $forum->id)) === false);
}

/**
 * @global object
 * @global object
 * @param int $forumid
 * @param int $userid
 */
function forum_tp_start_tracking($forumid, $userid=false) {
    global $USER, $DB;

    if ($userid === false) {
        $userid = $USER->id;
    }

    return $DB->delete_records('forum_track_prefs', array('userid' => $userid, 'forumid' => $forumid));
}

/**
 * @global object
 * @global object
 * @param int $forumid
 * @param int $userid
 */
function forum_tp_stop_tracking($forumid, $userid=false) {
    global $USER, $DB;

    if ($userid === false) {
        $userid = $USER->id;
    }

    if (!$DB->record_exists('forum_track_prefs', array('userid' => $userid, 'forumid' => $forumid))) {
        $track_prefs = new object();
        $track_prefs->userid = $userid;
        $track_prefs->forumid = $forumid;
        $DB->insert_record('forum_track_prefs', $track_prefs);
    }

    return forum_tp_delete_read_records($userid, -1, -1, $forumid);
}


/**
 * Clean old records from the forum_read table.
 * @global object
 * @global object
 * @return void
 */
function forum_tp_clean_read_records() {
    global $CFG, $DB;

    if (!isset($CFG->forum_oldpostdays)) {
        return;
    }
// Look for records older than the cutoffdate that are still in the forum_read table.
    $cutoffdate = time() - ($CFG->forum_oldpostdays*24*60*60);

    //first get the oldest tracking present - we need tis to speedup the next delete query
    $sql = "SELECT MIN(fp.modified) AS first
              FROM {forum_posts} fp
                   JOIN {forum_read} fr ON fr.postid=fp.id";
    if (!$first = $DB->get_field_sql($sql)) {
        // nothing to delete;
        return;
    }

    // now delete old tracking info
    $sql = "DELETE
              FROM {forum_read}
             WHERE postid IN (SELECT fp.id
                                FROM {forum_posts} fp
                               WHERE fp.modified >= ? AND fp.modified < ?)";
    $DB->execute($sql, array($first, $cutoffdate));
}

/**
 * Sets the last post for a given discussion
 *
 * @global object
 * @global object
 * @param into $discussionid
 * @return bool|int
 **/
function forum_discussion_update_last_post($discussionid) {
    global $CFG, $DB;

// Check the given discussion exists
    if (!$DB->record_exists('forum_discussions', array('id' => $discussionid))) {
        return false;
    }

// Use SQL to find the last post for this discussion
    $sql = "SELECT id, userid, modified
              FROM {forum_posts}
             WHERE discussion=?
             ORDER BY modified DESC";

// Lets go find the last post
    if (($lastposts = $DB->get_records_sql($sql, array($discussionid), 0, 1))) {
        $lastpost = reset($lastposts);
        $discussionobject = new Object;
        $discussionobject->id           = $discussionid;
        $discussionobject->usermodified = $lastpost->userid;
        $discussionobject->timemodified = $lastpost->modified;
        if ($DB->update_record('forum_discussions', $discussionobject)) {
            return $lastpost->id;
        }
    }

// To get here either we couldn't find a post for the discussion (weird)
// or we couldn't update the discussion record (weird x2)
    return false;
}


/**
 * @return array
 */
function forum_get_view_actions() {
    return array('view discussion','search','forum','forums','subscribers');
}

/**
 * @return array
 */
function forum_get_post_actions() {
    return array('add discussion','add post','delete discussion','delete post','move discussion','prune post','update post');
}

/**
 * this function returns all the separate forum ids, given a courseid
 *
 * @global object
 * @global object
 * @param int $courseid
 * @return array
 */
function forum_get_separate_modules($courseid) {

    global $CFG,$DB;
    $forummodule = $DB->get_record("modules", array("name" => "forum"));

    $sql = 'SELECT f.id, f.id FROM {forum} f, {course_modules} cm WHERE
           f.id = cm.instance AND cm.module =? AND cm.visible = 1 AND cm.course = ?
           AND cm.groupmode ='.SEPARATEGROUPS;

    return $DB->get_records_sql($sql, array($forummodule->id, $courseid));

}

/**
 * @global object
 * @global object
 * @global object
 * @param object $forum
 * @param object $cm
 * @return bool
 */
function forum_check_throttling($forum, $cm=null) {
    global $USER, $CFG, $DB;

    if (is_numeric($forum)) {
        $forum = $DB->get_record('forum',array('id'=>$forum));
    }
    if (!is_object($forum)) {
        return false;  // this is broken.
    }

    if (empty($forum->blockafter)) {
        return true;
    }

    if (empty($forum->blockperiod)) {
        return true;
    }

    if (!$cm) {
        if (!$cm = get_coursemodule_from_instance('forum', $forum->id, $forum->course)) {
            print_error('invalidcoursemodule');
        }
    }

    $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
    if(!has_capability('mod/forum:throttlingapplies', $modcontext)) {
        return true;
    }

    // get the number of posts in the last period we care about
    $timenow = time();
    $timeafter = $timenow - $forum->blockperiod;

    $numposts = $DB->count_records_sql('SELECT COUNT(p.id) FROM {forum_posts} p'
                                      .' JOIN {forum_discussions} d'
                                      .' ON p.discussion = d.id WHERE d.forum = ?'
                                      .' AND p.userid = ? AND p.created > ?', array($forum->id, $USER->id, $timeafter));

    $a = new object();
    $a->blockafter = $forum->blockafter;
    $a->numposts = $numposts;
    $a->blockperiod = get_string('secondstotime'.$forum->blockperiod);

    if ($forum->blockafter <= $numposts) {
        print_error('forumblockingtoomanyposts', 'error', $CFG->wwwroot.'/mod/forum/view.php?f='.$forum->id, $a);
    }
    if ($forum->warnafter <= $numposts) {
        notify(get_string('forumblockingalmosttoomanyposts','forum',$a));
    }


}


/**
 * Removes all grades from gradebook
 *
 * @global object
 * @global object
 * @param int $courseid
 * @param string $type optional
 */
function forum_reset_gradebook($courseid, $type='') {
    global $CFG, $DB;

    $wheresql = '';
    $params = array($courseid);
    if ($type) {
        $wheresql = "AND f.type=?";
        $params[] = $type;
    }

    $sql = "SELECT f.*, cm.idnumber as cmidnumber, f.course as courseid
              FROM {forum} f, {course_modules} cm, {modules} m
             WHERE m.name='forum' AND m.id=cm.module AND cm.instance=f.id AND f.course=? $wheresql";

    if ($forums = $DB->get_records_sql($sql, $params)) {
        foreach ($forums as $forum) {
            forum_grade_item_update($forum, 'reset');
        }
    }
}

/**
 * This function is used by the reset_course_userdata function in moodlelib.
 * This function will remove all posts from the specified forum
 * and clean up any related data.
 *
 * @global object
 * @global object
 * @param $data the data submitted from the reset course.
 * @return array status array
 */
function forum_reset_userdata($data) {
    global $CFG, $DB;

    $componentstr = get_string('modulenameplural', 'forum');
    $status = array();

    $params = array($data->courseid);

    $removeposts = false;
    if (!empty($data->reset_forum_all)) {
        $removeposts = true;
        $typesql     = "";
        $typesstr    = get_string('resetforumsall', 'forum');
        $types       = array();

    } else if (!empty($data->reset_forum_types)){
        $removeposts = true;
        $typesql     = "";
        $types       = array();
        $forum_types_all = forum_get_forum_types_all();
        foreach ($data->reset_forum_types as $type) {
            if (!array_key_exists($type, $forum_types_all)) {
                continue;
            }
            $typesql .= " AND f.type=?";
            $types[] = $forum_types_all[$type];
            $params[] = $type;
        }
        $typesstr = get_string('resetforums', 'forum').': '.implode(', ', $types);

    }
    $alldiscussionssql = "SELECT fd.id
                            FROM {forum_discussions} fd, {forum} f
                           WHERE f.course=? AND f.id=fd.forum";

    $allforumssql      = "SELECT f.id
                            FROM {forum} f
                           WHERE f.course=?";

    $allpostssql       = "SELECT fp.id
                            FROM {forum_posts} fp, {forum_discussions} fd, {forum} f
                           WHERE f.course=? AND f.id=fd.forum AND fd.id=fp.discussion";

    if ($removeposts) {
        $discussionssql = "$alldiscussionssql $typesql";
        $forumssql      = "$allforumssql $typesql";
        $postssql       = "$allpostssql $typesql";

        // now get rid of all attachments
        $fs = get_file_storage();
        if ($forums = $DB->get_records_sql($forumssql, $params)) {
            foreach ($forums as $forumid=>$unused) {
                if (!$cm = get_coursemodule_from_instance('forum', $forumid)) {
                    continue;
                }
                $context = get_context_instance(CONTEXT_MODULE, $cm->id);
                $fs->delete_area_files($context->id, 'forum_attachment');
            }
        }

        // first delete all read flags
        $DB->delete_records_select('forum_read', "forumid IN ($forumssql)", $params);

        // remove tracking prefs
        $DB->delete_records_select('forum_track_prefs', "forumid IN ($forumssql)", $params);

        // remove posts from queue
        $DB->delete_records_select('forum_queue', "discussionid IN ($discussionssql)", $params);

        // remove ratings
        $DB->delete_records_select('forum_ratings', "post IN ($postssql)", $params);

        // all posts - initial posts must be kept in single simple discussion forums
        $DB->delete_records_select('forum_posts', "discussion IN ($discussionssql) AND parent <> 0", $params); // first all children
        $DB->delete_records_select('forum_posts', "discussion IN ($discussionssql AND f.type <> 'single') AND parent = 0", $params); // now the initial posts for non single simple

        // finally all discussions except single simple forums
        $DB->delete_records_select('forum_discussions', "forum IN ($forumssql AND f.type <> 'single')", $params);

        // remove all grades from gradebook
        if (empty($data->reset_gradebook_grades)) {
            if (empty($types)) {
                forum_reset_gradebook($data->courseid);
            } else {
                foreach ($types as $type) {
                    forum_reset_gradebook($data->courseid, $type);
                }
            }
        }

        $status[] = array('component'=>$componentstr, 'item'=>$typesstr, 'error'=>false);
    }

    // remove all ratings
    if (!empty($data->reset_forum_ratings)) {
        $DB->delete_records_select('forum_ratings', "post IN ($allpostssql)", $params);
        // remove all grades from gradebook
        if (empty($data->reset_gradebook_grades)) {
            forum_reset_gradebook($data->courseid);
        }
    }

    // remove all subscriptions unconditionally - even for users still enrolled in course
    if (!empty($data->reset_forum_subscriptions)) {
        $DB->delete_records_select('forum_subscriptions', "forum IN ($allforumssql)", $params);
        $status[] = array('component'=>$componentstr, 'item'=>get_string('resetsubscriptions','forum'), 'error'=>false);
    }

    // remove all tracking prefs unconditionally - even for users still enrolled in course
    if (!empty($data->reset_forum_track_prefs)) {
        $DB->delete_records_select('forum_track_prefs', "forumid IN ($allforumssql)", $params);
        $status[] = array('component'=>$componentstr, 'item'=>get_string('resettrackprefs','forum'), 'error'=>false);
    }

    /// updating dates - shift may be negative too
    if ($data->timeshift) {
        shift_course_mod_dates('forum', array('assesstimestart', 'assesstimefinish'), $data->timeshift, $data->courseid);
        $status[] = array('component'=>$componentstr, 'item'=>get_string('datechanged'), 'error'=>false);
    }

    return $status;
}

/**
 * Called by course/reset.php
 *
 * @param $mform form passed by reference
 */
function forum_reset_course_form_definition(&$mform) {
    $mform->addElement('header', 'forumheader', get_string('modulenameplural', 'forum'));

    $mform->addElement('checkbox', 'reset_forum_all', get_string('resetforumsall','forum'));

    $mform->addElement('select', 'reset_forum_types', get_string('resetforums', 'forum'), forum_get_forum_types_all(), array('multiple' => 'multiple'));
    $mform->setAdvanced('reset_forum_types');
    $mform->disabledIf('reset_forum_types', 'reset_forum_all', 'checked');

    $mform->addElement('checkbox', 'reset_forum_subscriptions', get_string('resetsubscriptions','forum'));
    $mform->setAdvanced('reset_forum_subscriptions');

    $mform->addElement('checkbox', 'reset_forum_track_prefs', get_string('resettrackprefs','forum'));
    $mform->setAdvanced('reset_forum_track_prefs');
    $mform->disabledIf('reset_forum_track_prefs', 'reset_forum_all', 'checked');

    $mform->addElement('checkbox', 'reset_forum_ratings', get_string('deleteallratings'));
    $mform->disabledIf('reset_forum_ratings', 'reset_forum_all', 'checked');
}

/**
 * Course reset form defaults.
 * @return array
 */
function forum_reset_course_form_defaults($course) {
    return array('reset_forum_all'=>1, 'reset_forum_subscriptions'=>0, 'reset_forum_track_prefs'=>0, 'reset_forum_ratings'=>1);
}

/**
 * Converts a forum to use the Roles System
 *
 * @global object
 * @global object
 * @param object $forum        a forum object with the same attributes as a record
 *                        from the forum database table
 * @param int $forummodid   the id of the forum module, from the modules table
 * @param array $teacherroles array of roles that have moodle/legacy:teacher
 * @param array $studentroles array of roles that have moodle/legacy:student
 * @param array $guestroles   array of roles that have moodle/legacy:guest
 * @param int $cmid         the course_module id for this forum instance
 * @return boolean      forum was converted or not
 */
function forum_convert_to_roles($forum, $forummodid, $teacherroles=array(),
                                $studentroles=array(), $guestroles=array(), $cmid=NULL) {

    global $CFG, $DB;

    if (!isset($forum->open) && !isset($forum->assesspublic)) {
        // We assume that this forum has already been converted to use the
        // Roles System. Columns forum.open and forum.assesspublic get dropped
        // once the forum module has been upgraded to use Roles.
        return false;
    }

    if ($forum->type == 'teacher') {

        // Teacher forums should be converted to normal forums that
        // use the Roles System to implement the old behavior.
        // Note:
        //   Seems that teacher forums were never backed up in 1.6 since they
        //   didn't have an entry in the course_modules table.
        require_once($CFG->dirroot.'/course/lib.php');

        if ($DB->count_records('forum_discussions', array('forum' => $forum->id)) == 0) {
            // Delete empty teacher forums.
            $DB->delete_records('forum', array('id' => $forum->id));
        } else {
            // Create a course module for the forum and assign it to
            // section 0 in the course.
            $mod = new object;
            $mod->course = $forum->course;
            $mod->module = $forummodid;
            $mod->instance = $forum->id;
            $mod->section = 0;
            $mod->visible = 0;     // Hide the forum
            $mod->visibleold = 0;  // Hide the forum
            $mod->groupmode = 0;

            if (!$cmid = add_course_module($mod)) {
                print_error('cannotcreateinstanceforteacher', 'forum');
            } else {
                $mod->coursemodule = $cmid;
                if (!$sectionid = add_mod_to_section($mod)) {
                    print_error('cannotaddteacherforumto', 'forum');
                } else {
                    $DB->set_field('course_modules', 'section', $sectionid, array('id' => $cmid));
                }
            }

            // Change the forum type to general.
            $forum->type = 'general';
            $DB->update_record('forum', $forum);

            $context = get_context_instance(CONTEXT_MODULE, $cmid);

            // Create overrides for default student and guest roles (prevent).
            foreach ($studentroles as $studentrole) {
                assign_capability('mod/forum:viewdiscussion', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/forum:viewhiddentimedposts', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/forum:startdiscussion', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/forum:replypost', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/forum:viewrating', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/forum:viewanyrating', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/forum:rate', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/forum:createattachment', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/forum:deleteownpost', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/forum:deleteanypost', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/forum:splitdiscussions', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/forum:movediscussions', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/forum:editanypost', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/forum:viewqandawithoutposting', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/forum:viewsubscribers', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/forum:managesubscriptions', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/forum:throttlingapplies', CAP_PREVENT, $studentrole->id, $context->id);
            }
            foreach ($guestroles as $guestrole) {
                assign_capability('mod/forum:viewdiscussion', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/forum:viewhiddentimedposts', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/forum:startdiscussion', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/forum:replypost', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/forum:viewrating', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/forum:viewanyrating', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/forum:rate', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/forum:createattachment', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/forum:deleteownpost', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/forum:deleteanypost', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/forum:splitdiscussions', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/forum:movediscussions', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/forum:editanypost', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/forum:viewqandawithoutposting', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/forum:viewsubscribers', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/forum:managesubscriptions', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/forum:throttlingapplies', CAP_PREVENT, $guestrole->id, $context->id);
            }
        }
    } else {
        // Non-teacher forum.

        if (empty($cmid)) {
            // We were not given the course_module id. Try to find it.
            if (!$cm = get_coursemodule_from_instance('forum', $forum->id)) {
                notify('Could not get the course module for the forum');
                return false;
            } else {
                $cmid = $cm->id;
            }
        }
        $context = get_context_instance(CONTEXT_MODULE, $cmid);

        // $forum->open defines what students can do:
        //   0 = No discussions, no replies
        //   1 = No discussions, but replies are allowed
        //   2 = Discussions and replies are allowed
        switch ($forum->open) {
            case 0:
                foreach ($studentroles as $studentrole) {
                    assign_capability('mod/forum:startdiscussion', CAP_PREVENT, $studentrole->id, $context->id);
                    assign_capability('mod/forum:replypost', CAP_PREVENT, $studentrole->id, $context->id);
                }
                break;
            case 1:
                foreach ($studentroles as $studentrole) {
                    assign_capability('mod/forum:startdiscussion', CAP_PREVENT, $studentrole->id, $context->id);
                    assign_capability('mod/forum:replypost', CAP_ALLOW, $studentrole->id, $context->id);
                }
                break;
            case 2:
                foreach ($studentroles as $studentrole) {
                    assign_capability('mod/forum:startdiscussion', CAP_ALLOW, $studentrole->id, $context->id);
                    assign_capability('mod/forum:replypost', CAP_ALLOW, $studentrole->id, $context->id);
                }
                break;
        }

        // $forum->assessed defines whether forum rating is turned
        // on (1 or 2) and who can rate posts:
        //   1 = Everyone can rate posts
        //   2 = Only teachers can rate posts
        switch ($forum->assessed) {
            case 1:
                foreach ($studentroles as $studentrole) {
                    assign_capability('mod/forum:rate', CAP_ALLOW, $studentrole->id, $context->id);
                }
                foreach ($teacherroles as $teacherrole) {
                    assign_capability('mod/forum:rate', CAP_ALLOW, $teacherrole->id, $context->id);
                }
                break;
            case 2:
                foreach ($studentroles as $studentrole) {
                    assign_capability('mod/forum:rate', CAP_PREVENT, $studentrole->id, $context->id);
                }
                foreach ($teacherroles as $teacherrole) {
                    assign_capability('mod/forum:rate', CAP_ALLOW, $teacherrole->id, $context->id);
                }
                break;
        }

        // $forum->assesspublic defines whether students can see
        // everybody's ratings:
        //   0 = Students can only see their own ratings
        //   1 = Students can see everyone's ratings
        switch ($forum->assesspublic) {
            case 0:
                foreach ($studentroles as $studentrole) {
                    assign_capability('mod/forum:viewanyrating', CAP_PREVENT, $studentrole->id, $context->id);
                }
                foreach ($teacherroles as $teacherrole) {
                    assign_capability('mod/forum:viewanyrating', CAP_ALLOW, $teacherrole->id, $context->id);
                }
                break;
            case 1:
                foreach ($studentroles as $studentrole) {
                    assign_capability('mod/forum:viewanyrating', CAP_ALLOW, $studentrole->id, $context->id);
                }
                foreach ($teacherroles as $teacherrole) {
                    assign_capability('mod/forum:viewanyrating', CAP_ALLOW, $teacherrole->id, $context->id);
                }
                break;
        }

        if (empty($cm)) {
            $cm = $DB->get_record('course_modules', array('id' => $cmid));
        }

        // $cm->groupmode:
        // 0 - No groups
        // 1 - Separate groups
        // 2 - Visible groups
        switch ($cm->groupmode) {
            case 0:
                break;
            case 1:
                foreach ($studentroles as $studentrole) {
                    assign_capability('moodle/site:accessallgroups', CAP_PREVENT, $studentrole->id, $context->id);
                }
                foreach ($teacherroles as $teacherrole) {
                    assign_capability('moodle/site:accessallgroups', CAP_ALLOW, $teacherrole->id, $context->id);
                }
                break;
            case 2:
                foreach ($studentroles as $studentrole) {
                    assign_capability('moodle/site:accessallgroups', CAP_ALLOW, $studentrole->id, $context->id);
                }
                foreach ($teacherroles as $teacherrole) {
                    assign_capability('moodle/site:accessallgroups', CAP_ALLOW, $teacherrole->id, $context->id);
                }
                break;
        }
    }
    return true;
}

/**
 * Returns array of forum aggregate types
 *
 * @return array
 */
function forum_get_aggregate_types() {
    return array (FORUM_AGGREGATE_NONE  => get_string('aggregatenone', 'forum'),
                  FORUM_AGGREGATE_AVG   => get_string('aggregateavg', 'forum'),
                  FORUM_AGGREGATE_COUNT => get_string('aggregatecount', 'forum'),
                  FORUM_AGGREGATE_MAX   => get_string('aggregatemax', 'forum'),
                  FORUM_AGGREGATE_MIN   => get_string('aggregatemin', 'forum'),
                  FORUM_AGGREGATE_SUM   => get_string('aggregatesum', 'forum'));
}

/**
 * Returns array of forum layout modes
 *
 * @return array
 */
function forum_get_layout_modes() {
    return array (FORUM_MODE_FLATOLDEST => get_string('modeflatoldestfirst', 'forum'),
                  FORUM_MODE_FLATNEWEST => get_string('modeflatnewestfirst', 'forum'),
                  FORUM_MODE_THREADED   => get_string('modethreaded', 'forum'),
                  FORUM_MODE_NESTED     => get_string('modenested', 'forum'));
}

/**
 * Returns array of forum types
 *
 * @return array
 */
function forum_get_forum_types() {
    return array ('general'  => get_string('generalforum', 'forum'),
                  'eachuser' => get_string('eachuserforum', 'forum'),
                  'single'   => get_string('singleforum', 'forum'),
                  'qanda'    => get_string('qandaforum', 'forum'));
}

/**
 * Returns array of all forum layout modes
 *
 * @return array
 */
function forum_get_forum_types_all() {
    return array ('news'     => get_string('namenews','forum'),
                  'social'   => get_string('namesocial','forum'),
                  'general'  => get_string('generalforum', 'forum'),
                  'eachuser' => get_string('eachuserforum', 'forum'),
                  'single'   => get_string('singleforum', 'forum'),
                  'qanda'    => get_string('qandaforum', 'forum'));
}

/**
 * Returns array of forum open modes
 *
 * @return array
 */
function forum_get_open_modes() {
    return array ('2' => get_string('openmode2', 'forum'),
                  '1' => get_string('openmode1', 'forum'),
                  '0' => get_string('openmode0', 'forum') );
}

/**
 * Returns all other caps used in module
 *
 * @return array
 */
function forum_get_extra_capabilities() {
    return array('moodle/site:accessallgroups', 'moodle/site:viewfullnames', 'moodle/site:trustcontent');
}

/**
 * @package   mod-forum
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class forum_portfolio_caller extends portfolio_module_caller_base {

    protected $postid;
    protected $discussionid;
    protected $attachment;

    private $post;
    private $forum;
    private $discussion;
    private $posts;
    private $keyedfiles;
    /**
     * @return array
     */
    public static function expected_callbackargs() {
        return array(
            'postid'       => false,
            'discussionid' => false,
            'attachment'   => false,
        );
    }
    /**
     * @param array $callbackargs
     */
    function __construct($callbackargs) {
        parent::__construct($callbackargs);
        if (!$this->postid && !$this->discussionid) {
            throw new portfolio_caller_exception('mustprovidediscussionorpost', 'forum');
        }
    }
    /**
     * @global object
     */
    public function load_data() {
        global $DB;

        if ($this->postid) {
            if (!$this->post = $DB->get_record('forum_posts', array('id' => $this->postid))) {
                throw new portfolio_caller_exception('invalidpostid', 'forum');
            }
        }

        $dparams = array();
        if ($this->discussionid) {
            $dbparams = array('id' => $this->discussionid);
        } else if ($this->post) {
            $dbparams = array('id' => $this->post->discussion);
        } else {
            throw new portfolio_caller_exception('mustprovidediscussionorpost', 'forum');
        }

        if (!$this->discussion = $DB->get_record('forum_discussions', $dbparams)) {
            throw new portfolio_caller_exception('invaliddiscussionid', 'forum');
        }

        if (!$this->forum = $DB->get_record('forum', array('id' => $this->discussion->forum))) {
            throw new portfolio_caller_exception('invalidforumid', 'forum');
        }

        if (!$this->cm = get_coursemodule_from_instance('forum', $this->forum->id)) {
            throw new portfolio_caller_exception('invalidcoursemodule');
        }

        $modcontext = get_context_instance(CONTEXT_MODULE, $this->cm->id);
        if ($this->post) {
            $this->set_file_and_format_data($this->attachment, $modcontext->id, 'forum_attachment', $this->post->id);
            if (!empty($this->multifiles)) {
                $this->keyedfiles[$this->post->id] = $this->multifiles;
            } else if (!empty($this->singlefile)) {
                $this->keyedfiles[$this->post->id] = $this->singlefile;
            }
        } else { // whole thread
            $fs = get_file_storage();
            $this->posts = forum_get_all_discussion_posts($this->discussion->id, 'p.created ASC');
            $this->multifiles = array();
            foreach ($this->posts as $post) {
                if (!$this->keyedfiles[$post->id] = $fs->get_area_files($modcontext->id, 'forum_attachment', $post->id, "timemodified", false)) {
                    continue;
                }
                $this->multifiles = array_merge($this->multifiles, array_values($this->keyedfiles[$post->id]));
            }
        }
        if ($this->attachment) {
            // do nothing
        } else if (!empty($this->multifiles) || !empty($this->singlefile)) {
            $this->supportedformats = array(PORTFOLIO_FORMAT_FILE, PORTFOLIO_FORMAT_RICHHTML);
        } else {
            $this->supportedformats = array(PORTFOLIO_FORMAT_PLAINHTML);
        }
    }
    /**
     * @global object
     * @return string
     */
    function get_return_url() {
        global $CFG;
        return $CFG->wwwroot . '/mod/forum/discuss.php?d=' . $this->discussion->id;
    }
    /**
     * @global object
     * @return array
     */
    function get_navigation() {
        global $CFG;

        $navlinks = array();
        $navlinks[] = array(
            'name' => format_string($this->discussion->name),
            'link' => $CFG->wwwroot . '/mod/forum/discuss.php?d=' . $this->discussion->id,
            'type' => 'title'
        );
        return array($navlinks, $this->cm);
    }
    /**
     * @global object
     * @global object
     * @uses PORTFOLIO_FORMAT_RICH
     * @return mixed
     */
    function prepare_package() {
        global $CFG, $SESSION;
        // either a whole discussion
        // a single post, with or without attachment
        // or just an attachment with no post
        $manifest = ($this->exporter->get('format') instanceof PORTFOLIO_FORMAT_RICH);
        if (!$this->post) { // whole discussion
            $content = '';
            foreach ($this->posts as $post) {
                $content .= '<br /><br />' . $this->prepare_post($post);
            }
            $this->copy_files($this->multifiles);
            return $this->get('exporter')->write_new_file($content, 'discussion.html', $manifest);
        }
        if ($this->attachment) {
            return $this->copy_files(array($this->singlefile), $this->attachment); // all we need to do
        }
        $this->copy_files($this->multifiles, $this->attachment);
        $post = $this->prepare_post($this->post);
        $this->get('exporter')->write_new_file($post, 'post.html', $manifest);
    }
    /**
     * @param array $files
     * @param bool $justone
     * @return bool|void
     */
    private function copy_files($files, $justone=false) {
        if (empty($files)) {
            return;
        }
        foreach ($files as $f) {
            if ($justone && $f->get_id() != $justone) {
                continue; // support multipe files later
            }
            $this->get('exporter')->copy_existing_file($f);
            if ($justone && $f->get_id() == $justone) {
                return true; // all we need to do
            }
        }
    }
    /**
     * this is a very cut down version of what is in forum_make_mail_post
     *
     * @global object
     * @param int $post
     * @return string
     */
    private function prepare_post($post) {
        global $DB;
        static $users;
        if (empty($users)) {
            $users = array($this->user->id => $this->user);
        }
        if (!array_key_exists($post->userid, $users)) {
            $users[$post->userid] = $DB->get_record('user', array('id' => $post->userid));
        }
        $viewfullnames = true;
        // format the post body
        $options = new object();
        $options->para = true;
        $formattedtext = format_text($post->message, $post->messageformat, $options, $this->get('course')->id);

        $output = '<table border="0" cellpadding="3" cellspacing="0" class="forumpost">';

        $output .= '<tr class="header"><td>';// can't print picture.
        $output .= '</td>';

        if ($post->parent) {
            $output .= '<td class="topic">';
        } else {
            $output .= '<td class="topic starter">';
        }
        $output .= '<div class="subject">'.format_string($post->subject).'</div>';

        $fullname = fullname($users[$post->userid], $viewfullnames);
        $by = new object();
        $by->name = $fullname;
        $by->date = userdate($post->modified, '', $this->user->timezone);
        $output .= '<div class="author">'.get_string('bynameondate', 'forum', $by).'</div>';

        $output .= '</td></tr>';

        $output .= '<tr><td class="left side" valign="top">';

        $output .= '</td><td class="content">';

        $output .= $formattedtext;

        if (is_array($this->keyedfiles) && array_key_exists($post->id, $this->keyedfiles) && is_array($this->keyedfiles[$post->id])) {
            $output .= '<div class="attachments">';
            $output .= '<br /><b>' .  get_string('attachments', 'forum') . '</b>:<br /><br />';
            $format = $this->get('exporter')->get('format');
            foreach ($this->keyedfiles[$post->id] as $file) {
                $output .= $format->file_output($file) . '<br/ >';
            }
            $output .= "</div>";
        }

        $output .= '</td></tr></table>'."\n\n";

        return $output;
    }
    /**
     * @return string
     */
    function get_sha1() {
        $filesha = '';
        try {
            $filesha = $this->get_sha1_file();
        } catch (portfolio_caller_exception $e) { } // no files

        if ($this->post) {
            return sha1($filesha . ',' . $this->post->subject . ',' . $this->post->message);
        } else {
            $sha1s = array($filesha);
            foreach ($this->posts as $post) {
                $sha1s[] = sha1($post->subject . ',' . $post->message);
            }
            return sha1(implode(',', $sha1s));
        }
    }

    function expected_time() {
        $filetime = $this->expected_time_file();
        if ($this->posts) {
            $posttime = portfolio_expected_time_db(count($this->posts));
            if ($filetime < $posttime) {
                return $posttime;
            }
        }
        return $filetime;
    }
    /**
     * @uses CONTEXT_MODULE
     * @return bool
     */
    function check_permissions() {
        $context = get_context_instance(CONTEXT_MODULE, $this->cm->id);
        if ($this->post) {
            return (has_capability('mod/forum:exportpost', $context)
                || ($this->post->userid == $this->user->id
                    && has_capability('mod/forum:exportownpost', $context)));
        }
        return has_capability('mod/forum:exportdiscussion', $context);
    }
    /**
     * @return string
     */
    public static function display_name() {
        return get_string('modulename', 'forum');
    }
}
