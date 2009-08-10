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
 * Library of functions for the quiz module.
 *
 * This contains functions that are called also from outside the quiz module
 * Functions that are only called by the quiz module itself are in {@link locallib.php}
 *
 * @package mod-quiz
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Require {@link eventslib.php} */
require_once($CFG->libdir . '/eventslib.php');

/// CONSTANTS ///////////////////////////////////////////////////////////////////

/**#@+
 * Options determining how the grades from individual attempts are combined to give
 * the overall grade for a user
 */
define('QUIZ_GRADEHIGHEST', 1);
define('QUIZ_GRADEAVERAGE', 2);
define('QUIZ_ATTEMPTFIRST', 3);
define('QUIZ_ATTEMPTLAST', 4);
/**#@-*/

define('QUIZ_MAX_ATTEMPT_OPTION', 10);
define('QUIZ_MAX_QPP_OPTION', 50);
define('QUIZ_MAX_DECIMAL_OPTION', 5);
define('QUIZ_MAX_Q_DECIMAL_OPTION', 7);

/**#@+
 * The different review options are stored in the bits of $quiz->review
 * These constants help to extract the options
 *
 * This is more of a mess than you might think necessary, because originally
 * it was though that 3x6 bits were enough, but then they ran out. PHP integers
 * are only reliably 32 bits signed, so the simplest solution was then to
 * add 4x3 more bits.
 */
/**
 * The first 6 + 4 bits refer to the time immediately after the attempt
 */
define('QUIZ_REVIEW_IMMEDIATELY', 0x3c003f);
/**
 * the next 6 + 4 bits refer to the time after the attempt but while the quiz is open
 */
define('QUIZ_REVIEW_OPEN',       0x3c00fc0);
/**
 * the final 6 + 4 bits refer to the time after the quiz closes
 */
define('QUIZ_REVIEW_CLOSED',    0x3c03f000);

// within each group of 6 bits we determine what should be shown
define('QUIZ_REVIEW_RESPONSES',       1*0x1041); // Show responses
define('QUIZ_REVIEW_SCORES',          2*0x1041); // Show scores
define('QUIZ_REVIEW_FEEDBACK',        4*0x1041); // Show question feedback
define('QUIZ_REVIEW_ANSWERS',         8*0x1041); // Show correct answers
// Some handling of worked solutions is already in the code but not yet fully supported
// and not switched on in the user interface.
define('QUIZ_REVIEW_SOLUTIONS',      16*0x1041); // Show solutions
define('QUIZ_REVIEW_GENERALFEEDBACK',32*0x1041); // Show question general feedback
define('QUIZ_REVIEW_OVERALLFEEDBACK', 1*0x4440000); // Show quiz overall feedback
// Multipliers 2*0x4440000, 4*0x4440000 and 8*0x4440000 are still available
/**#@-*/

/**
 * If start and end date for the quiz are more than this many seconds apart
 * they will be represented by two separate events in the calendar
 */
define("QUIZ_MAX_EVENT_LENGTH", 5*24*60*60);   // 5 days maximum

/// FUNCTIONS ///////////////////////////////////////////////////////////////////

/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will create a new instance and return the id number
 * of the new instance.
 *
 * @global object
 * @param object $quiz the data that came from the form.
 * @return mixed the id of the new instance on success,
 *          false or a string error message on failure.
 */
function quiz_add_instance($quiz) {
    global $DB;

    // Process the options from the form.
    $quiz->created = time();
    $quiz->questions = '';
    $result = quiz_process_options($quiz);
    if ($result && is_string($result)) {
        return $result;
    }

    // Try to store it in the database.
    $quiz->id = $DB->insert_record('quiz', $quiz);

    // Do the processing required after an add or an update.
    quiz_after_add_or_update($quiz);

    return $quiz->id;
}

/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will update an existing instance with new data.
 *
 * @global stdClass
 * @global object
 * @param object $quiz the data that came from the form.
 * @return mixed true on success, false or a string error message on failure.
 */
function quiz_update_instance($quiz, $mform) {
    global $CFG, $DB;

    // Process the options from the form.
    $result = quiz_process_options($quiz);
    if ($result && is_string($result)) {
        return $result;
    }

    // Repaginate, if asked to.
    if (!$quiz->shufflequestions && !empty($quiz->repaginatenow)) {
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $quiz->questions = $DB->get_field('quiz', 'questions', array('id' => $quiz->instance));
        $quiz->questions = quiz_repaginate($quiz->questions, $quiz->questionsperpage);
    }
    unset($quiz->repaginatenow);

    // Update the database.
    $quiz->id = $quiz->instance;
    $DB->update_record('quiz', $quiz);

    // Do the processing required after an add or an update.
    quiz_after_add_or_update($quiz);

    // Delete any previous preview attempts
    quiz_delete_previews($quiz);

    return true;
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
function quiz_delete_instance($id) {
    global $DB;

    if (!$quiz = $DB->get_record('quiz', array('id' => $id))) {
        return false;
    }

    quiz_delete_all_attempts($quiz);

    $DB->delete_records('quiz_question_instances', array('quiz' => $quiz->id));
    $DB->delete_records('quiz_feedback', array('quizid' => $quiz->id));

    $events = $DB->get_records('event', array('modulename' => 'quiz', 'instance' => $quiz->id));
    foreach($events as $event) {
        delete_event($event->id);
    }

    quiz_grade_item_delete($quiz);
    $DB->delete_records('quiz', array('id' => $quiz->id));

    return true;
}

/**
 * Delete all the attempts belonging to a quiz.
 *
 * @global stdClass
 * @global object
 * @param object $quiz The quiz object.
 */
function quiz_delete_all_attempts($quiz) {
    global $CFG, $DB;
    require_once($CFG->libdir . '/questionlib.php');
    $attempts = $DB->get_records('quiz_attempts', array('quiz' => $quiz->id));
    foreach ($attempts as $attempt) {
        delete_attempt($attempt->uniqueid);
    }
    $DB->delete_records('quiz_attempts', array('quiz' => $quiz->id));
    $DB->delete_records('quiz_grades', array('quiz' => $quiz->id));
}

/**
 * Return a small object with summary information about what a
 * user has done with a given particular instance of this module
 * Used for user activity reports.
 * $return->time = the time they did it
 * $return->info = a short text description
 *
 * @global object
 * @param object $course
 * @param object $user
 * @param object $mod
 * @param object $quiz
 * @return object|null
 */
function quiz_user_outline($course, $user, $mod, $quiz) {
    global $DB;
    $grade = quiz_get_best_grade($quiz, $user->id);
    if (is_null($grade)) {
        return NULL;
    }

    $result = new stdClass;
    $result->info = get_string('grade') . ': ' . $grade . '/' . $quiz->grade;
    $result->time = $DB->get_field('quiz_attempts', 'MAX(timefinish)', array('userid' => $user->id, 'quiz' => $quiz->id));
    return $result;
    }

/**
 * Is this a graded quiz? If this method returns true, you can assume that
 * $quiz->grade and $quiz->sumgrades are non-zero (for example, if you want to
 * divide by them).
 *
 * @param object $quiz a row from the quiz table.
 * @return boolean whether this is a graded quiz.
 */
function quiz_has_grades($quiz) {
    return $quiz->grade != 0 && $quiz->sumgrades != 0;
}

/**
 * Get the best current grade for a particular user in a quiz.
 *
 * @global object
 * @param object $quiz the quiz object.
 * @param integer $userid the id of the user.
 * @return float the user's current grade for this quiz, or NULL if this user does
 * not have a grade on this quiz.
 */
function quiz_get_best_grade($quiz, $userid) {
    global $DB;
    $grade = $DB->get_field('quiz_grades', 'grade', array('quiz' => $quiz->id, 'userid' => $userid));

    // Need to detect errors/no result, without catching 0 scores.
    if (is_numeric($grade)) {
        return quiz_format_grade($quiz, $grade);
    } else {
        return NULL;
    }
}

/**
 * Print a detailed representation of what a  user has done with
 * a given particular instance of this module, for user activity reports.
 *
 * @global object
 * @param object $course
 * @param object $user
 * @param object $mod
 * @param object $quiz
 * @return bool
 */
function quiz_user_complete($course, $user, $mod, $quiz) {
    global $DB;

    if ($attempts = $DB->get_records('quiz_attempts', array('userid' => $user->id, 'quiz' => $quiz->id), 'attempt')) {
        if (quiz_has_grades($quiz) && $grade = quiz_get_best_grade($quiz, $user->id)) {
            echo get_string('grade') . ': ' . $grade . '/' . quiz_format_grade($quiz, $quiz->grade) . '<br />';
        }
        foreach ($attempts as $attempt) {
            echo get_string('attempt', 'quiz').' '.$attempt->attempt.': ';
            if ($attempt->timefinish == 0) {
                print_string('unfinished');
            } else {
                echo quiz_format_grade($quiz, $attempt->sumgrades) . '/' . quiz_format_grade($quiz, $quiz->sumgrades);
            }
            echo ' - '.userdate($attempt->timemodified).'<br />';
        }
    } else {
       print_string('noattempts', 'quiz');
    }

    return true;
}

/**
 * Function to be run periodically according to the moodle cron
 * This function searches for things that need to be done, such
 * as sending out mail, toggling flags etc ...
 *
 * @global stdClass
 * @return bool true
 */
function quiz_cron() {
    global $CFG;

    return true;
}

/**
 * @global object
 * @param integer $quizid the quiz id.
 * @param integer $userid the userid.
 * @param string $status 'all', 'finished' or 'unfinished' to control
 * @param bool $includepreviews
 * @return an array of all the user's attempts at this quiz. Returns an empty array if there are none.
 */
function quiz_get_user_attempts($quizid, $userid=0, $status = 'finished', $includepreviews = false) {
    global $DB;
    $status_condition = array(
        'all' => '',
        'finished' => ' AND timefinish > 0',
        'unfinished' => ' AND timefinish = 0'
    );
    $previewclause = '';
    if (!$includepreviews) {
        $previewclause = ' AND preview = 0';
    }
    $params=array($quizid);
    if ($userid){
        $userclause = ' AND userid = ?';
        $params[]=$userid;
    } else {
        $userclause = '';
    }
    if ($attempts = $DB->get_records_select('quiz_attempts',
            "quiz = ?" .$userclause. $previewclause . $status_condition[$status], $params,
            'attempt ASC')) {
        return $attempts;
    } else {
        return array();
    }
}

/**
 * Return grade for given user or all users.
 *
 * @global stdClass
 * @global object
 * @param int $quizid id of quiz
 * @param int $userid optional user id, 0 means all users
 * @return array array of grades, false if none. These are raw grades. They should
 * be processed with quiz_format_grade for display.
 */
function quiz_get_user_grades($quiz, $userid=0) {
    global $CFG, $DB;

    $params = array($quiz->id);
    $wheresql = '';
    if ($userid) {
        $params[] = $userid;
        $wheresql = "AND u.id = ?";
    }
    $sql = "SELECT u.id, u.id AS userid, g.grade AS rawgrade, g.timemodified AS dategraded, MAX(a.timefinish) AS datesubmitted
            FROM {user} u, {quiz_grades} g, {quiz_attempts} a
            WHERE u.id = g.userid AND g.quiz = ? AND a.quiz = g.quiz AND u.id = a.userid $wheresql
            GROUP BY u.id, g.grade, g.timemodified";

    return $DB->get_records_sql($sql, $params);
}

/**
 * Round a grade to to the correct number of decimal places, and format it for display.
 *
 * @param object $quiz The quiz table row, only $quiz->decimalpoints is used.
 * @param float $grade The grade to round.
 * @return float
 */
function quiz_format_grade($quiz, $grade) {
    return format_float($grade, $quiz->decimalpoints);
}

/**
 * Round a grade to to the correct number of decimal places, and format it for display.
 *
 * @param object $quiz The quiz table row, only $quiz->decimalpoints is used.
 * @param float $grade The grade to round.
 * @return float
 */
function quiz_format_question_grade($quiz, $grade) {
    if ($quiz->questiondecimalpoints == -1) {
        return format_float($grade, $quiz->decimalpoints);
    } else {
        return format_float($grade, $quiz->questiondecimalpoints);
    }
}

/**
 * Update grades in central gradebook
 *
 * @global stdClass
 * @global object
 * @param object $quiz
 * @param int $userid specific user only, 0 means all
 */
function quiz_update_grades($quiz, $userid=0, $nullifnone=true) {
    global $CFG, $DB;
    require_once($CFG->libdir.'/gradelib.php');

    if ($quiz->grade == 0) {
        quiz_grade_item_update($quiz);

    } else if ($grades = quiz_get_user_grades($quiz, $userid)) {
        quiz_grade_item_update($quiz, $grades);

    } else if ($userid and $nullifnone) {
        $grade = new object();
        $grade->userid   = $userid;
        $grade->rawgrade = NULL;
        quiz_grade_item_update($quiz, $grade);

    } else {
        quiz_grade_item_update($quiz);
    }
}

/**
 * Update all grades in gradebook.
 *
 * @global object
 */
function quiz_upgrade_grades() {
    global $DB;

    $sql = "SELECT COUNT('x')
              FROM {quiz} a, {course_modules} cm, {modules} m
             WHERE m.name='quiz' AND m.id=cm.module AND cm.instance=a.id";
    $count = $DB->count_records_sql($sql);

    $sql = "SELECT a.*, cm.idnumber AS cmidnumber, a.course AS courseid
              FROM {quiz} a, {course_modules} cm, {modules} m
             WHERE m.name='quiz' AND m.id=cm.module AND cm.instance=a.id";
    if ($rs = $DB->get_recordset_sql($sql)) {
        $pbar = new progress_bar('quizupgradegrades', 500, true);
        $i=0;
        foreach ($rs as $quiz) {
            $i++;
            upgrade_set_timeout(60*5); // set up timeout, may also abort execution
            quiz_update_grades($quiz, 0, false);
            $pbar->update($i, $count, "Updating Quiz grades ($i/$count).");
        }
        $rs->close();
    }
}

/**
 * Create grade item for given quiz
 *
 * @global stdClass
 * @uses GRADE_TYPE_VALUE
 * @uses GRADE_TYPE_NONE
 * @uses QUIZ_REVIEW_SCORES
 * @uses QUIZ_REVIEW_CLOSED
 * @uses QUIZ_REVIEW_OPEN
 * @uses PARAM_INT
 * @uses GRADE_UPDATE_ITEM_LOCKED
 * @param object $quiz object with extra cmidnumber
 * @param mixed $grades optional array/object of grade(s); 'reset' means reset grades in gradebook
 * @return int 0 if ok, error code otherwise
 */
function quiz_grade_item_update($quiz, $grades=NULL) {
    global $CFG, $OUTPUT;
    if (!function_exists('grade_update')) { //workaround for buggy PHP versions
        require_once($CFG->libdir.'/gradelib.php');
    }

    if (array_key_exists('cmidnumber', $quiz)) { //it may not be always present
        $params = array('itemname'=>$quiz->name, 'idnumber'=>$quiz->cmidnumber);
    } else {
        $params = array('itemname'=>$quiz->name);
    }

    if ($quiz->grade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax']  = $quiz->grade;
        $params['grademin']  = 0;

    } else {
        $params['gradetype'] = GRADE_TYPE_NONE;
    }

/* description by TJ:
1/ If the quiz is set to not show scores while the quiz is still open, and is set to show scores after
   the quiz is closed, then create the grade_item with a show-after date that is the quiz close date.
2/ If the quiz is set to not show scores at either of those times, create the grade_item as hidden.
3/ If the quiz is set to show scores, create the grade_item visible.
*/
    if (!($quiz->review & QUIZ_REVIEW_SCORES & QUIZ_REVIEW_CLOSED)
    and !($quiz->review & QUIZ_REVIEW_SCORES & QUIZ_REVIEW_OPEN)) {
        $params['hidden'] = 1;

    } else if ( ($quiz->review & QUIZ_REVIEW_SCORES & QUIZ_REVIEW_CLOSED)
           and !($quiz->review & QUIZ_REVIEW_SCORES & QUIZ_REVIEW_OPEN)) {
        if ($quiz->timeclose) {
            $params['hidden'] = $quiz->timeclose;
        } else {
            $params['hidden'] = 1;
        }

    } else {
        // a) both open and closed enabled
        // b) open enabled, closed disabled - we can not "hide after", grades are kept visible even after closing
        $params['hidden'] = 0;
    }

    if ($grades  === 'reset') {
        $params['reset'] = true;
        $grades = NULL;
    }

    $gradebook_grades = grade_get_grades($quiz->course, 'mod', 'quiz', $quiz->id);
    if (!empty($gradebook_grades->items)) {
        $grade_item = $gradebook_grades->items[0];
        if ($grade_item->locked) {
            $confirm_regrade = optional_param('confirm_regrade', 0, PARAM_INT);
            if (!$confirm_regrade) {
                $message = get_string('gradeitemislocked', 'grades');
                $back_link = $CFG->wwwroot . '/mod/quiz/report.php?q=' . $quiz->id . '&amp;mode=overview';
                $regrade_link = qualified_me() . '&amp;confirm_regrade=1';
                echo $OUTPUT->box_start('generalbox', 'notice');
                echo '<p>'. $message .'</p>';
                echo '<div class="buttons">';
                print_single_button($regrade_link, null, get_string('regradeanyway', 'grades'), 'post', $CFG->framename);
                print_single_button($back_link,  null,  get_string('cancel'),  'post',  $CFG->framename);
                echo '</div>';
                echo $OUTPUT->box_end();

                return GRADE_UPDATE_ITEM_LOCKED;
            }
        }
    }

    return grade_update('mod/quiz', $quiz->course, 'mod', 'quiz', $quiz->id, 0, $grades, $params);
}

/**
 * Delete grade item for given quiz
 *
 * @global stdClass
 * @param object $quiz object
 * @return object quiz
 */
function quiz_grade_item_delete($quiz) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    return grade_update('mod/quiz', $quiz->course, 'mod', 'quiz', $quiz->id, 0, NULL, array('deleted' => 1));
}

/**
 * @return the options for calculating the quiz grade from the individual attempt grades.
 */
function quiz_get_grading_options() {
    return array (
            QUIZ_GRADEHIGHEST => get_string('gradehighest', 'quiz'),
            QUIZ_GRADEAVERAGE => get_string('gradeaverage', 'quiz'),
            QUIZ_ATTEMPTFIRST => get_string('attemptfirst', 'quiz'),
            QUIZ_ATTEMPTLAST  => get_string('attemptlast', 'quiz'));
}

/**
 * Returns an array of users who have data in a given quiz
 *
 * @global stdClass
 * @global object
 * @param int $quizid
 * @return array
 */
function quiz_get_participants($quizid) {
    global $CFG, $DB;

    //Get users from attempts
    $us_attempts = $DB->get_records_sql("SELECT DISTINCT u.id, u.id
                                    FROM {user} u,
                                         {quiz_attempts} a
                                    WHERE a.quiz = ? and
                                          u.id = a.userid", array($quizid));

    //Return us_attempts array (it contains an array of unique users)
    return $us_attempts;

}

/**
 * This standard function will check all instances of this module
 * and make sure there are up-to-date events created for each of them.
 * If courseid = 0, then every quiz event in the site is checked, else
 * only quiz events belonging to the course specified are checked.
 * This function is used, in its new format, by restore_refresh_events()
 *
 * @global object
 * @uses QUIZ_MAX_EVENT_LENGTH
 * @param int $courseid
 * @return bool
 */
function quiz_refresh_events($courseid = 0) {
    global $DB;

    if ($courseid == 0) {
        if (! $quizzes = $DB->get_records('quiz')) {
            return true;
        }
    } else {
        if (! $quizzes = $DB->get_records('quiz', array('course' => $courseid))) {
            return true;
        }
    }
    $moduleid = $DB->get_field('modules', 'id', array('name' => 'quiz'));

    foreach ($quizzes as $quiz) {
        $cm = get_coursemodule_from_id('quiz', $quiz->id);
        $event = NULL;
        $event2 = NULL;
        $event2old = NULL;

        if ($events = $DB->get_records('event', array('modulename' => 'quiz', 'instance' => $quiz->id), 'timestart')) {
            $event = array_shift($events);
            if (!empty($events)) {
                $event2old = array_shift($events);
                if (!empty($events)) {
                    foreach ($events as $badevent) {
                        delete_event($badevent->id);
                    }
                }
            }
        }

        $event->name        = $quiz->name;
        $event->description = format_module_intro('quiz', $quiz, $cm->id);
        $event->courseid    = $quiz->course;
        $event->groupid     = 0;
        $event->userid      = 0;
        $event->modulename  = 'quiz';
        $event->instance    = $quiz->id;
        $event->visible     = instance_is_visible('quiz', $quiz);
        $event->timestart   = $quiz->timeopen;
        $event->eventtype   = 'open';
        $event->timeduration = ($quiz->timeclose - $quiz->timeopen);

        if ($event->timeduration > QUIZ_MAX_EVENT_LENGTH) {  /// Set up two events

            $event2 = $event;

            $event->name         = $quiz->name.' ('.get_string('quizopens', 'quiz').')';
            $event->timeduration = 0;

            $event2->name        = $quiz->name.' ('.get_string('quizcloses', 'quiz').')';
            $event2->timestart   = $quiz->timeclose;
            $event2->eventtype   = 'close';
            $event2->timeduration = 0;

            if (empty($event2old->id)) {
                unset($event2->id);
                add_event($event2);
            } else {
                $event2->id = $event2old->id;
                update_event($event2);
            }
        } else if (!empty($event2old->id)) {
            delete_event($event2old->id);
        }

        if (empty($event->id)) {
            if (!empty($event->timestart)) {
                add_event($event);
            }
        } else {
            update_event($event);
        }

    }
    return true;
}

/**
 * Returns all quiz graded users since a given time for specified quiz
 *
 * @global stdClass
 * @global object
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @param array $activities By reference
 * @param int $index By reference
 * @param int $timestart
 * @param int $courseid
 * @param int $cmid
 * @param int $userid
 * @param int $groupid
 * @return void
 */
function quiz_get_recent_mod_activity(&$activities, &$index, $timestart, $courseid, $cmid, $userid=0, $groupid=0)  {
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

    if (!$attempts = $DB->get_records_sql("SELECT qa.*, q.sumgrades AS maxgrade,
                                             u.firstname, u.lastname, u.email, u.picture
                                        FROM {quiz_attempts} qa
                                             JOIN {quiz} q ON q.id = qa.quiz
                                             JOIN {user} u ON u.id = qa.userid
                                             $groupjoin
                                       WHERE qa.timefinish > $timestart AND q.id = $cm->instance
                                             $userselect $groupselect
                                    ORDER BY qa.timefinish ASC", $params)) {
         return;
    }

    $cm_context      = get_context_instance(CONTEXT_MODULE, $cm->id);
    $grader          = has_capability('moodle/grade:viewall', $cm_context);
    $accessallgroups = has_capability('moodle/site:accessallgroups', $cm_context);
    $viewfullnames   = has_capability('moodle/site:viewfullnames', $cm_context);
    $grader          = has_capability('mod/quiz:grade', $cm_context);
    $groupmode       = groups_get_activity_groupmode($cm, $course);

    if (is_null($modinfo->groups)) {
        $modinfo->groups = groups_get_user_groups($course->id); // load all my groups and cache it in modinfo
    }

    $aname = format_string($cm->name,true);
    foreach ($attempts as $attempt) {
        if ($attempt->userid != $USER->id) {
            if (!$grader) {
                // grade permission required
                continue;
            }

            if ($groupmode == SEPARATEGROUPS and !$accessallgroups) {
                $usersgroups = groups_get_all_groups($course->id, $attempt->userid, $cm->groupingid);
                if (!is_array($usersgroups)) {
                    continue;
                }
                $usersgroups = array_keys($usersgroups);
                $interset = array_intersect($usersgroups, $modinfo->groups[$cm->id]);
                if (empty($intersect)) {
                    continue;
                }
            }
       }

        $tmpactivity = new object();

        $tmpactivity->type      = 'quiz';
        $tmpactivity->cmid      = $cm->id;
        $tmpactivity->name      = $aname;
        $tmpactivity->sectionnum= $cm->sectionnum;
        $tmpactivity->timestamp = $attempt->timefinish;

        $tmpactivity->content->attemptid = $attempt->id;
        $tmpactivity->content->sumgrades = $attempt->sumgrades;
        $tmpactivity->content->maxgrade  = $attempt->maxgrade;
        $tmpactivity->content->attempt   = $attempt->attempt;

        $tmpactivity->user->userid   = $attempt->userid;
        $tmpactivity->user->fullname = fullname($attempt, $viewfullnames);
        $tmpactivity->user->picture  = $attempt->picture;

        $activities[$index++] = $tmpactivity;
    }

  return;
}

/**
 * @global stdClass
 * @param object $activity
 * @param int $courseid
 * @param bool $detail
 * @param array $modnames
 * @return void output is echo'd
 */
function quiz_print_recent_mod_activity($activity, $courseid, $detail, $modnames) {
    global $CFG, $OUTPUT;

    echo '<table border="0" cellpadding="3" cellspacing="0" class="forum-recent">';

    echo "<tr><td class=\"userpicture\" valign=\"top\">";
    print_user_picture($activity->user->userid, $courseid, $activity->user->picture);
    echo "</td><td>";

    if ($detail) {
        $modname = $modnames[$activity->type];
        echo '<div class="title">';
        echo "<img src=\"" . $OUTPUT->mod_icon_url('icon', $activity->type) . "\" ".
             "class=\"icon\" alt=\"$modname\" />";
        echo "<a href=\"$CFG->wwwroot/mod/quiz/view.php?id={$activity->cmid}\">{$activity->name}</a>";
        echo '</div>';
    }

    echo '<div class="grade">';
    echo  get_string("attempt", "quiz")." {$activity->content->attempt}: ";
    $grades = "({$activity->content->sumgrades} / {$activity->content->maxgrade})";
    echo "<a href=\"$CFG->wwwroot/mod/quiz/review.php?attempt={$activity->content->attemptid}\">$grades</a>";
    echo '</div>';

    echo '<div class="user">';
    echo "<a href=\"$CFG->wwwroot/user/view.php?id={$activity->user->userid}&amp;course=$courseid\">"
         ."{$activity->user->fullname}</a> - ".userdate($activity->timestamp);
    echo '</div>';

    echo "</td></tr></table>";

    return;
}

/**
 * Pre-process the quiz options form data, making any necessary adjustments.
 * Called by add/update instance in this file.
 *
 * @uses QUIZ_REVIEW_OVERALLFEEDBACK
 * @uses QUIZ_REVIEW_CLOSED
 * @uses QUIZ_REVIEW_OPEN
 * @uses QUIZ_REVIEW_IMMEDIATELY
 * @uses QUIZ_REVIEW_GENERALFEEDBACK
 * @uses QUIZ_REVIEW_SOLUTIONS
 * @uses QUIZ_REVIEW_ANSWERS
 * @uses QUIZ_REVIEW_FEEDBACK
 * @uses QUIZ_REVIEW_SCORES
 * @uses QUIZ_REVIEW_RESPONSES
 * @uses QUESTION_ADAPTIVE
 * @param object $quiz The variables set on the form.
 * @return string
 */
function quiz_process_options(&$quiz) {
    $quiz->timemodified = time();

    // Quiz name.
    if (!empty($quiz->name)) {
        $quiz->name = trim($quiz->name);
    }

    // Password field - different in form to stop browsers that remember passwords
    // getting confused.
    $quiz->password = $quiz->quizpassword;
    unset($quiz->quizpassword);

    // Quiz feedback
    if (isset($quiz->feedbacktext)) {
        // Clean up the boundary text.
        for ($i = 0; $i < count($quiz->feedbacktext); $i += 1) {
            if (empty($quiz->feedbacktext[$i])) {
                $quiz->feedbacktext[$i] = '';
            } else {
                $quiz->feedbacktext[$i] = trim($quiz->feedbacktext[$i]);
            }
        }

        // Check the boundary value is a number or a percentage, and in range.
        $i = 0;
        while (!empty($quiz->feedbackboundaries[$i])) {
            $boundary = trim($quiz->feedbackboundaries[$i]);
            if (!is_numeric($boundary)) {
                if (strlen($boundary) > 0 && $boundary[strlen($boundary) - 1] == '%') {
                    $boundary = trim(substr($boundary, 0, -1));
                    if (is_numeric($boundary)) {
                        $boundary = $boundary * $quiz->grade / 100.0;
                    } else {
                        return get_string('feedbackerrorboundaryformat', 'quiz', $i + 1);
                    }
                }
            }
            if ($boundary <= 0 || $boundary >= $quiz->grade) {
                return get_string('feedbackerrorboundaryoutofrange', 'quiz', $i + 1);
            }
            if ($i > 0 && $boundary >= $quiz->feedbackboundaries[$i - 1]) {
                return get_string('feedbackerrororder', 'quiz', $i + 1);
            }
            $quiz->feedbackboundaries[$i] = $boundary;
            $i += 1;
        }
        $numboundaries = $i;

        // Check there is nothing in the remaining unused fields.
        if (!empty($quiz->feedbackboundaries)) {
            for ($i = $numboundaries; $i < count($quiz->feedbackboundaries); $i += 1) {
                if (!empty($quiz->feedbackboundaries[$i]) && trim($quiz->feedbackboundaries[$i]) != '') {
                    return get_string('feedbackerrorjunkinboundary', 'quiz', $i + 1);
                }
            }
        }
        for ($i = $numboundaries + 1; $i < count($quiz->feedbacktext); $i += 1) {
            if (!empty($quiz->feedbacktext[$i]) && trim($quiz->feedbacktext[$i]) != '') {
                return get_string('feedbackerrorjunkinfeedback', 'quiz', $i + 1);
            }
        }
        $quiz->feedbackboundaries[-1] = $quiz->grade + 1; // Needs to be bigger than $quiz->grade because of '<' test in quiz_feedback_for_grade().
        $quiz->feedbackboundaries[$numboundaries] = 0;
        $quiz->feedbackboundarycount = $numboundaries;
    }

    // Settings that get combined to go into the optionflags column.
    $quiz->optionflags = 0;
    if (!empty($quiz->adaptive)) {
        $quiz->optionflags |= QUESTION_ADAPTIVE;
    }

    // Settings that get combined to go into the review column.
    $review = 0;
    if (isset($quiz->responsesimmediately)) {
        $review += (QUIZ_REVIEW_RESPONSES & QUIZ_REVIEW_IMMEDIATELY);
        unset($quiz->responsesimmediately);
    }
    if (isset($quiz->responsesopen)) {
        $review += (QUIZ_REVIEW_RESPONSES & QUIZ_REVIEW_OPEN);
        unset($quiz->responsesopen);
    }
    if (isset($quiz->responsesclosed)) {
        $review += (QUIZ_REVIEW_RESPONSES & QUIZ_REVIEW_CLOSED);
        unset($quiz->responsesclosed);
    }

    if (isset($quiz->scoreimmediately)) {
        $review += (QUIZ_REVIEW_SCORES & QUIZ_REVIEW_IMMEDIATELY);
        unset($quiz->scoreimmediately);
    }
    if (isset($quiz->scoreopen)) {
        $review += (QUIZ_REVIEW_SCORES & QUIZ_REVIEW_OPEN);
        unset($quiz->scoreopen);
    }
    if (isset($quiz->scoreclosed)) {
        $review += (QUIZ_REVIEW_SCORES & QUIZ_REVIEW_CLOSED);
        unset($quiz->scoreclosed);
    }

    if (isset($quiz->feedbackimmediately)) {
        $review += (QUIZ_REVIEW_FEEDBACK & QUIZ_REVIEW_IMMEDIATELY);
        unset($quiz->feedbackimmediately);
    }
    if (isset($quiz->feedbackopen)) {
        $review += (QUIZ_REVIEW_FEEDBACK & QUIZ_REVIEW_OPEN);
        unset($quiz->feedbackopen);
    }
    if (isset($quiz->feedbackclosed)) {
        $review += (QUIZ_REVIEW_FEEDBACK & QUIZ_REVIEW_CLOSED);
        unset($quiz->feedbackclosed);
    }

    if (isset($quiz->answersimmediately)) {
        $review += (QUIZ_REVIEW_ANSWERS & QUIZ_REVIEW_IMMEDIATELY);
        unset($quiz->answersimmediately);
    }
    if (isset($quiz->answersopen)) {
        $review += (QUIZ_REVIEW_ANSWERS & QUIZ_REVIEW_OPEN);
        unset($quiz->answersopen);
    }
    if (isset($quiz->answersclosed)) {
        $review += (QUIZ_REVIEW_ANSWERS & QUIZ_REVIEW_CLOSED);
        unset($quiz->answersclosed);
    }

    if (isset($quiz->solutionsimmediately)) {
        $review += (QUIZ_REVIEW_SOLUTIONS & QUIZ_REVIEW_IMMEDIATELY);
        unset($quiz->solutionsimmediately);
    }
    if (isset($quiz->solutionsopen)) {
        $review += (QUIZ_REVIEW_SOLUTIONS & QUIZ_REVIEW_OPEN);
        unset($quiz->solutionsopen);
    }
    if (isset($quiz->solutionsclosed)) {
        $review += (QUIZ_REVIEW_SOLUTIONS & QUIZ_REVIEW_CLOSED);
        unset($quiz->solutionsclosed);
    }

    if (isset($quiz->generalfeedbackimmediately)) {
        $review += (QUIZ_REVIEW_GENERALFEEDBACK & QUIZ_REVIEW_IMMEDIATELY);
        unset($quiz->generalfeedbackimmediately);
    }
    if (isset($quiz->generalfeedbackopen)) {
        $review += (QUIZ_REVIEW_GENERALFEEDBACK & QUIZ_REVIEW_OPEN);
        unset($quiz->generalfeedbackopen);
    }
    if (isset($quiz->generalfeedbackclosed)) {
        $review += (QUIZ_REVIEW_GENERALFEEDBACK & QUIZ_REVIEW_CLOSED);
        unset($quiz->generalfeedbackclosed);
    }

    if (isset($quiz->overallfeedbackimmediately)) {
        $review += (QUIZ_REVIEW_OVERALLFEEDBACK & QUIZ_REVIEW_IMMEDIATELY);
        unset($quiz->overallfeedbackimmediately);
    }
    if (isset($quiz->overallfeedbackopen)) {
        $review += (QUIZ_REVIEW_OVERALLFEEDBACK & QUIZ_REVIEW_OPEN);
        unset($quiz->overallfeedbackopen);
    }
    if (isset($quiz->overallfeedbackclosed)) {
        $review += (QUIZ_REVIEW_OVERALLFEEDBACK & QUIZ_REVIEW_CLOSED);
        unset($quiz->overallfeedbackclosed);
    }

    $quiz->review = $review;
}

/**
 * This function is called at the end of quiz_add_instance
 * and quiz_update_instance, to do the common processing.
 *
 * @global object
 * @uses QUIZ_MAX_EVENT_LENGTH
 * @param object $quiz the quiz object.
 * @return void|string Void or error message
 */
function quiz_after_add_or_update($quiz) {
    global $DB;

    // Save the feedback
    $DB->delete_records('quiz_feedback', array('quizid' => $quiz->id));

    for ($i = 0; $i <= $quiz->feedbackboundarycount; $i += 1) {
        $feedback = new stdClass;
        $feedback->quizid = $quiz->id;
        $feedback->feedbacktext = $quiz->feedbacktext[$i];
        $feedback->mingrade = $quiz->feedbackboundaries[$i];
        $feedback->maxgrade = $quiz->feedbackboundaries[$i - 1];
        $DB->insert_record('quiz_feedback', $feedback, false);
    }

    // Update the events relating to this quiz.
    // This is slightly inefficient, deleting the old events and creating new ones. However,
    // there are at most two events, and this keeps the code simpler.
    if ($events = $DB->get_records('event', array('modulename'=>'quiz', 'instance'=>$quiz->id))) {
        foreach($events as $event) {
            delete_event($event->id);
        }
    }

    $event = new stdClass;
    $event->description = $quiz->intro;
    $event->courseid    = $quiz->course;
    $event->groupid     = 0;
    $event->userid      = 0;
    $event->modulename  = 'quiz';
    $event->instance    = $quiz->id;
    $event->timestart   = $quiz->timeopen;
    $event->timeduration = $quiz->timeclose - $quiz->timeopen;
    $event->visible     = instance_is_visible('quiz', $quiz);
    $event->eventtype   = 'open';

    if ($quiz->timeclose and $quiz->timeopen and $event->timeduration <= QUIZ_MAX_EVENT_LENGTH) {
        // Single event for the whole quiz.
        $event->name = $quiz->name;
        add_event($event);
    } else {
        // Separate start and end events.
        $event->timeduration  = 0;
        if ($quiz->timeopen) {
            $event->name = $quiz->name.' ('.get_string('quizopens', 'quiz').')';
            add_event($event);
            unset($event->id); // So we can use the same object for the close event.
        }
        if ($quiz->timeclose) {
            $event->name      = $quiz->name.' ('.get_string('quizcloses', 'quiz').')';
            $event->timestart = $quiz->timeclose;
            $event->eventtype = 'close';
            add_event($event);
        }
    }

    //update related grade item
    quiz_grade_item_update($quiz);
}

/**
 * @return array
 */
function quiz_get_view_actions() {
    return array('view', 'view all', 'report', 'review');
}

/**
 * @return array
 */
function quiz_get_post_actions() {
    return array('attempt', 'close attempt', 'preview', 'editquestions', 'delete attempt', 'manualgrade');
}

/**
 * Returns an array of names of quizzes that use this question
 *
 * @param integer $questionid
 * @return array of strings
 */
function quiz_question_list_instances($questionid) {
    global $CFG, $DB;

    // TODO MDL-5780: we should also consider other questions that are used by
    // random questions in this quiz, but that is very hard.

    $sql = "SELECT q.id, q.name
            FROM {quiz} q
            JOIN {quiz_question_instances} qqi ON q.id = qqi.quiz
            WHERE qqi.question = ?";

    if ($instances = $DB->get_records_sql_menu($sql, array($questionid))) {
        return $instances;
    }
    return array();
}

/**
 * Implementation of the function for printing the form elements that control
 * whether the course reset functionality affects the quiz.
 *
 * @param $mform form passed by reference
 */
function quiz_reset_course_form_definition(&$mform) {
    $mform->addElement('header', 'quizheader', get_string('modulenameplural', 'quiz'));
    $mform->addElement('advcheckbox', 'reset_quiz_attempts', get_string('removeallquizattempts','quiz'));
}

/**
 * Course reset form defaults.
 * @return array
 */
function quiz_reset_course_form_defaults($course) {
    return array('reset_quiz_attempts'=>1);
}

/**
 * Removes all grades from gradebook
 *
 * @global stdClass
 * @global object
 * @param int $courseid
 * @param string optional type
 */
function quiz_reset_gradebook($courseid, $type='') {
    global $CFG, $DB;

    $sql = "SELECT q.*, cm.idnumber as cmidnumber, q.course as courseid
              FROM {quiz} q, {course_modules} cm, {modules} m
             WHERE m.name='quiz' AND m.id=cm.module AND cm.instance=q.id AND q.course=?";

    if ($quizs = $DB->get_records_sql($sql, array($courseid))) {
        foreach ($quizs as $quiz) {
            quiz_grade_item_update($quiz, 'reset');
        }
    }
}

/**
 * Actual implementation of the rest coures functionality, delete all the
 * quiz attempts for course $data->courseid, if $data->reset_quiz_attempts is
 * set and true.
 *
 * Also, move the quiz open and close dates, if the course start date is changing.
 *
 * @global stdClass
 * @global object
 * @param object $data the data submitted from the reset course.
 * @return array status array
 */
function quiz_reset_userdata($data) {
    global $CFG, $DB;
    require_once($CFG->libdir.'/questionlib.php');

    $componentstr = get_string('modulenameplural', 'quiz');
    $status = array();

    /// Delete attempts.
    if (!empty($data->reset_quiz_attempts)) {
        $quizzes = $DB->get_records('quiz', array('course' => $data->courseid));
        foreach ($quizzes as $quiz) {
            quiz_delete_all_attempts($quiz);
        }

        // remove all grades from gradebook
        if (empty($data->reset_gradebook_grades)) {
            quiz_reset_gradebook($data->courseid);
        }
        $status[] = array('component' => $componentstr, 'item' => get_string('attemptsdeleted', 'quiz'), 'error' => false);
    }

    /// updating dates - shift may be negative too
    if ($data->timeshift) {
        shift_course_mod_dates('quiz', array('timeopen', 'timeclose'), $data->timeshift, $data->courseid);
        $status[] = array('component' => $componentstr, 'item' => get_string('openclosedatesupdated', 'quiz'), 'error' => false);
    }

    return $status;
}

/**
 * Checks whether the current user is allowed to view a file uploaded in a quiz.
 * Teachers can view any from their courses, students can only view their own.
 *
 * @global object
 * @global object
 * @uses CONTEXT_COURSE
 * @param int $attemptuniqueid int attempt id
 * @param int $questionid int question id
 * @return boolean to indicate access granted or denied
 */
function quiz_check_file_access($attemptuniqueid, $questionid) {
    global $USER, $DB;

    $attempt = $DB->get_record('quiz_attempts', array('uniqueid' => $attemptuniqueid));
    $quiz = $DB->get_record('quiz', array('id' => $attempt->quiz));
    $context = get_context_instance(CONTEXT_COURSE, $quiz->course);

    // access granted if the current user submitted this file
    if ($attempt->userid == $USER->id) {
        return true;
    // access granted if the current user has permission to grade quizzes in this course
    } else if (has_capability('mod/quiz:viewreports', $context) || has_capability('mod/quiz:grade', $context)) {
        return true;
    }

    // otherwise, this user does not have permission
    return false;
}

/**
 * Prints quiz summaries on MyMoodle Page
 *
 * @global object
 * @global object
 * @param arry $courses
 * @param array $htmlarray
 */
function quiz_print_overview($courses, &$htmlarray) {
    global $USER, $CFG;
/// These next 6 Lines are constant in all modules (just change module name)
    if (empty($courses) || !is_array($courses) || count($courses) == 0) {
        return array();
    }

    if (!$quizzes = get_all_instances_in_courses('quiz', $courses)) {
        return;
    }

/// Fetch some language strings outside the main loop.
    $strquiz = get_string('modulename', 'quiz');
    $strnoattempts = get_string('noattempts', 'quiz');

/// We want to list quizzes that are currently available, and which have a close date.
/// This is the same as what the lesson does, and the dabate is in MDL-10568.
    $now = time();
    foreach ($quizzes as $quiz) {
        if ($quiz->timeclose >= $now && $quiz->timeopen < $now) {
        /// Give a link to the quiz, and the deadline.
            $str = '<div class="quiz overview">' .
                    '<div class="name">' . $strquiz . ': <a ' . ($quiz->visible ? '' : ' class="dimmed"') .
                    ' href="' . $CFG->wwwroot . '/mod/quiz/view.php?id=' . $quiz->coursemodule . '">' .
                    $quiz->name . '</a></div>';
            $str .= '<div class="info">' . get_string('quizcloseson', 'quiz', userdate($quiz->timeclose)) . '</div>';

        /// Now provide more information depending on the uers's role.
            $context = get_context_instance(CONTEXT_MODULE, $quiz->coursemodule);
            if (has_capability('mod/quiz:viewreports', $context)) {
            /// For teacher-like people, show a summary of the number of student attempts.
                // The $quiz objects returned by get_all_instances_in_course have the necessary $cm
                // fields set to make the following call work.
                $str .= '<div class="info">' . quiz_num_attempt_summary($quiz, $quiz, true) . '</div>';
            } else if (has_any_capability(array('mod/quiz:reviewmyattempts', 'mod/quiz:attempt'), $context)) { // Student
            /// For student-like people, tell them how many attempts they have made.
                if (isset($USER->id) && ($attempts = quiz_get_user_attempts($quiz->id, $USER->id))) {
                    $numattempts = count($attempts);
                    $str .= '<div class="info">' . get_string('numattemptsmade', 'quiz', $numattempts) . '</div>';
                } else {
                    $str .= '<div class="info">' . $strnoattempts . '</div>';
                }
            } else {
            /// For ayone else, there is no point listing this quiz, so stop processing.
                continue;
            }

        /// Add the output for this quiz to the rest.
            $str .= '</div>';
            if (empty($htmlarray[$quiz->course]['quiz'])) {
                $htmlarray[$quiz->course]['quiz'] = $str;
            } else {
                $htmlarray[$quiz->course]['quiz'] .= $str;
            }
        }
    }
}

/**
 * Return a textual summary of the number of attemtps that have been made at a particular quiz,
 * returns '' if no attemtps have been made yet, unless $returnzero is passed as true.
 *
 * @global stdClass
 * @global object
 * @global object
 * @param object $quiz the quiz object. Only $quiz->id is used at the moment.
 * @param object $cm the cm object. Only $cm->course, $cm->groupmode and $cm->groupingid fields are used at the moment.
 * @param boolean $returnzero if false (default), when no attempts have been made '' is returned instead of 'Attempts: 0'.
 * @param int $currentgroup if there is a concept of current group where this method is being called
 *         (e.g. a report) pass it in here. Default 0 which means no current group.
 * @return string a string like "Attempts: 123", "Attemtps 123 (45 from your groups)" or
 *          "Attemtps 123 (45 from this group)".
 */
function quiz_num_attempt_summary($quiz, $cm, $returnzero = false, $currentgroup = 0) {
    global $CFG, $USER, $DB;
    $numattempts = $DB->count_records('quiz_attempts', array('quiz'=> $quiz->id, 'preview'=>0));
    if ($numattempts || $returnzero) {
        if (groups_get_activity_groupmode($cm)) {
            $a->total = $numattempts;
            if ($currentgroup) {
                $a->group = $DB->count_records_sql('SELECT count(1) FROM ' .
                        '{quiz_attempts} qa JOIN ' .
                        '{groups_members} gm ON qa.userid = gm.userid ' .
                        'WHERE quiz = ? AND preview = 0 AND groupid = ?', array($quiz->id, $currentgroup));
                return get_string('attemptsnumthisgroup', 'quiz', $a);
            } else if ($groups = groups_get_all_groups($cm->course, $USER->id, $cm->groupingid)) {
                list($usql, $params) = $DB->get_in_or_equal(array_keys($groups));
                $a->group = $DB->count_records_sql('SELECT count(1) FROM ' .
                        '{quiz_attempts} qa JOIN ' .
                        '{groups_members} gm ON qa.userid = gm.userid ' .
                        'WHERE quiz = ? AND preview = 0 AND ' .
                        "groupid $usql", array_merge(array($quiz->id), $params));
                return get_string('attemptsnumyourgroups', 'quiz', $a);
            }
        }
        return get_string('attemptsnum', 'quiz', $numattempts);
    }
    return '';
}

/**
 * @uses FEATURE_GROUPS
 * @uses FEATURE_GROUPINGS
 * @uses FEATURE_GROUPMEMBERSONLY
 * @uses FEATURE_MOD_INTRO
 * @uses FEATURE_COMPLETION_TRACKS_VIEWS
 * @uses FEATURE_GRADE_HAS_GRADE
 * @uses FEATURE_GRADE_OUTCOMES
 * @param string $feature FEATURE_xx constant for requested feature
 * @return bool True if quiz supports feature
 */
function quiz_supports($feature) {
    switch($feature) {
        case FEATURE_GROUPS:                  return true;
        case FEATURE_GROUPINGS:               return true;
        case FEATURE_GROUPMEMBERSONLY:        return true;
        case FEATURE_MOD_INTRO:               return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS: return true;
        case FEATURE_GRADE_HAS_GRADE:         return true;
        case FEATURE_GRADE_OUTCOMES:          return true;
        case FEATURE_MOD_SUBPLUGINS:          return array('quiz'=>'mod/quiz/report');

        default: return null;
    }
}

/**
 * @global object
 * @global stdClass
 * @return array all other caps used in module
 */
function quiz_get_extra_capabilities() {
    global $DB, $CFG;
    require_once($CFG->libdir.'/questionlib.php');
    $caps = question_get_all_capabilities();
    $reportcaps = $DB->get_records_select_menu('capabilities', 'name LIKE ?', array('quizreport/%'), 'id,name');
    $caps = array_merge($caps, $reportcaps);
    $caps[] = 'moodle/site:accessallgroups';
    return $caps;
}
