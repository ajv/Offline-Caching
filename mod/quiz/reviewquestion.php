<?php  // $Id$
/**
 * This page prints a review of a particular question attempt.
 * This page is expected to only be used in a popup window.
 *
 * @author Martin Dougiamas, Tim Hunt and many others.
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package quiz
 */

    require_once(dirname(__FILE__) . '/../../config.php');
    require_once('locallib.php');

    $attemptid = required_param('attempt', PARAM_INT); // attempt id
    $questionid = required_param('question', PARAM_INT); // question id
    $stateid = optional_param('state', 0, PARAM_INT); // state id

    $attemptobj = new quiz_attempt($attemptid);

/// Check login.
    require_login($attemptobj->get_courseid(), false, $attemptobj->get_cm());
    $attemptobj->check_review_capability();

/// Permissions checks for normal users who do not have quiz:viewreports capability.
    if (!$attemptobj->has_capability('mod/quiz:viewreports')) {
    /// Can't review during the attempt - send them back to the attempt page.
        if (!$attemptobj->is_finished()) {
            notify(get_string('cannotreviewopen', 'quiz'));
            echo $OUTPUT->close_window_button();
        }
    /// Can't review other users' attempts.
        if (!$attemptobj->is_own_attempt()) {
            notify(get_string('notyourattempt', 'quiz'));
            echo $OUTPUT->close_window_button();
        }
    /// Can't review unless Students may review -> Responses option is turned on.
        if (!$options->responses) {
            $accessmanager = $attemptobj->get_access_manager(time());
            notify($accessmanager->cannot_review_message($attemptobj->get_review_options()));
            echo $OUTPUT->close_window_button();
        }
    }

/// Load the questions and states.
    $questionids = array($questionid);
    $attemptobj->load_questions($questionids);
    $attemptobj->load_question_states($questionids);

/// If it was asked for, load another state, instead of the latest.
    if ($stateid) {
        $attemptobj->load_specific_question_state($questionid, $stateid);
    }

/// Work out the base URL of this page.
    $baseurl = $CFG->wwwroot . '/mod/quiz/reviewquestion.php?attempt=' .
            $attemptobj->get_attemptid() . '&amp;question=' . $questionid;

/// Log this review.
    add_to_log($attemptobj->get_courseid(), 'quiz', 'review', 'reviewquestion.php?attempt=' .
            $attemptobj->get_attemptid() . '&question=' . $questionid .
            ($stateid ? '&state=' . $stateid : ''),
            $attemptobj->get_quizid(), $attemptobj->get_cmid());

/// Print the page header
    $headtags = $attemptobj->get_question_html_head_contributions($questionid);
    print_header('', '', '', '', $headtags);

    echo '<div id="overDiv" style="position:absolute; visibility:hidden; z-index:1000;"></div>'; // for overlib

/// Print infobox
    $rows = array();

/// User picture and name.
    if ($attemptobj->get_userid() <> $USER->id) {
        // Print user picture and name
        $student = $DB->get_record('user', array('id' => $attemptobj->get_userid()));
        $picture = print_user_picture($student, $attemptobj->get_courseid(), $student->picture, false, true);
        $rows[] = '<tr><th scope="row" class="cell">' . $picture . '</th><td class="cell"><a href="' .
                $CFG->wwwroot . '/user/view.php?id=' . $student->id . '&amp;course=' . $attemptobj->get_courseid() . '">' .
                fullname($student, true) . '</a></td></tr>';
    }

/// Quiz name.
    $rows[] = '<tr><th scope="row" class="cell">' . get_string('modulename', 'quiz') .
            '</th><td class="cell">' . format_string($attemptobj->get_quiz_name()) . '</td></tr>';

/// Question name.
    $rows[] = '<tr><th scope="row" class="cell">' . get_string('question', 'quiz') .
            '</th><td class="cell">' . format_string(
            $attemptobj->get_question($questionid)->name) . '</td></tr>';

/// Other attempts at the quiz.
    if ($attemptobj->has_capability('mod/quiz:viewreports')) {
        $attemptlist = $attemptobj->links_to_other_attempts($baseurl);
        if ($attemptlist) {
            $rows[] = '<tr><th scope="row" class="cell">' . get_string('attempts', 'quiz') .
                    '</th><td class="cell">' . $attemptlist . '</td></tr>';
        }
    }

/// Timestamp of this action.
    $timestamp = $attemptobj->get_question_state($questionid)->timestamp;
    if ($timestamp) {
        $rows[] = '<tr><th scope="row" class="cell">' . get_string('completedon', 'quiz') .
                '</th><td class="cell">' . userdate($timestamp) . '</td></tr>';
    }

/// Now output the summary table, if there are any rows to be shown.
    if (!empty($rows)) {
        echo '<table class="generaltable generalbox quizreviewsummary"><tbody>', "\n";
        echo implode("\n", $rows);
        echo "\n</tbody></table>\n";
    }

/// Print the question in the requested state.
    if ($stateid) {
        $baseurl .= '&amp;state=' . $stateid;
    }
    $attemptobj->print_question($questionid, true, $baseurl);

/// Finish the page
    echo $OUTPUT->footer();
?>
