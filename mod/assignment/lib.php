<?PHP

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
 * assignment_base is the base class for assignment types
 *
 * This class provides all the functionality for an assignment
 *
 * @package   mod-assignment
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Include eventslib.php */
require_once($CFG->libdir.'/eventslib.php');
/** Include formslib.php */
require_once($CFG->libdir.'/formslib.php');
/** Include portfoliolib.php */
require_once($CFG->libdir.'/portfoliolib.php');

/** ASSIGNMENT_COUNT_WORDS = 1 */
DEFINE ('ASSIGNMENT_COUNT_WORDS', 1);
/** ASSIGNMENT_COUNT_LETTERS = 2 */
DEFINE ('ASSIGNMENT_COUNT_LETTERS', 2);

/**
 * Standard base class for all assignment submodules (assignment types).
 * 
 * @package   mod-assignment
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignment_base {

    /** @var object */
    var $cm;
    /** @var object */
    var $course;
    /** @var object */
    var $assignment;
    /** @var string */
    var $strassignment;
    /** @var string */
    var $strassignments;
    /** @var string */
    var $strsubmissions;
    /** @var string */
    var $strlastmodified;
    /** @var string */
    var $pagetitle;
    /** @var bool */
    var $usehtmleditor;
    /**
     * @todo document this var
     */
    var $defaultformat;
    /**
     * @todo document this var
     */
    var $context;
    /** @var string */
    var $type;

    /**
     * Constructor for the base assignment class
     *
     * Constructor for the base assignment class.
     * If cmid is set create the cm, course, assignment objects.
     * If the assignment is hidden and the user is not a teacher then
     * this prints a page header and notice.
     *
     * @global object
     * @global object
     * @param int $cmid the current course module id - not set for new assignments
     * @param object $assignment usually null, but if we have it we pass it to save db access
     * @param object $cm usually null, but if we have it we pass it to save db access
     * @param object $course usually null, but if we have it we pass it to save db access
     */
    function assignment_base($cmid='staticonly', $assignment=NULL, $cm=NULL, $course=NULL) {
        global $COURSE, $DB;

        if ($cmid == 'staticonly') {
            //use static functions only!
            return;
        }

        global $CFG;

        if ($cm) {
            $this->cm = $cm;
        } else if (! $this->cm = get_coursemodule_from_id('assignment', $cmid)) {
            print_error('invalidcoursemodule');
        }

        $this->context = get_context_instance(CONTEXT_MODULE, $this->cm->id);

        if ($course) {
            $this->course = $course;
        } else if ($this->cm->course == $COURSE->id) {
            $this->course = $COURSE;
        } else if (! $this->course = $DB->get_record('course', array('id'=>$this->cm->course))) {
            print_error('invalidid', 'assignment');
        }

        if ($assignment) {
            $this->assignment = $assignment;
        } else if (! $this->assignment = $DB->get_record('assignment', array('id'=>$this->cm->instance))) {
            print_error('invalidid', 'assignment');
        }

        $this->assignment->cmidnumber = $this->cm->id;     // compatibility with modedit assignment obj
        $this->assignment->courseid   = $this->course->id; // compatibility with modedit assignment obj

        $this->strassignment = get_string('modulename', 'assignment');
        $this->strassignments = get_string('modulenameplural', 'assignment');
        $this->strsubmissions = get_string('submissions', 'assignment');
        $this->strlastmodified = get_string('lastmodified');
        $this->pagetitle = strip_tags($this->course->shortname.': '.$this->strassignment.': '.format_string($this->assignment->name,true));

        // visibility handled by require_login() with $cm parameter
        // get current group only when really needed

    /// Set up things for a HTML editor if it's needed
        if ($this->usehtmleditor = can_use_html_editor()) {
            $this->defaultformat = FORMAT_HTML;
        } else {
            $this->defaultformat = FORMAT_MOODLE;
        }
    }

    /**
     * Display the assignment, used by view.php
     *
     * This in turn calls the methods producing individual parts of the page
     */
    function view() {

        $context = get_context_instance(CONTEXT_MODULE,$this->cm->id);
        require_capability('mod/assignment:view', $context);

        add_to_log($this->course->id, "assignment", "view", "view.php?id={$this->cm->id}",
                   $this->assignment->id, $this->cm->id);

        $this->view_header();

        $this->view_intro();

        $this->view_dates();

        $this->view_feedback();

        $this->view_footer();
    }

    /**
     * Display the header and top of a page
     *
     * (this doesn't change much for assignment types)
     * This is used by the view() method to print the header of view.php but
     * it can be used on other pages in which case the string to denote the
     * page in the navigation trail should be passed as an argument
     *
     * @global object
     * @param string $subpage Description of subpage to be used in navigation trail
     */
    function view_header($subpage='') {

        global $CFG;


        if ($subpage) {
            $navigation = build_navigation($subpage, $this->cm);
        } else {
            $navigation = build_navigation('', $this->cm);
        }

        print_header($this->pagetitle, $this->course->fullname, $navigation, '', '',
                     true, update_module_button($this->cm->id, $this->course->id, $this->strassignment),
                     navmenu($this->course, $this->cm));

        groups_print_activity_menu($this->cm, 'view.php?id=' . $this->cm->id);

        echo '<div class="reportlink">'.$this->submittedlink().'</div>';
        echo '<div class="clearer"></div>';
    }


    /**
     * Display the assignment intro
     *
     * This will most likely be extended by assignment type plug-ins
     * The default implementation prints the assignment description in a box
     */
    function view_intro() {
        print_simple_box_start('center', '', '', 0, 'generalbox', 'intro');
        echo format_module_intro('assignment', $this->assignment, $this->cm->id);
        print_simple_box_end();
    }

    /**
     * Display the assignment dates
     *
     * Prints the assignment start and end dates in a box.
     * This will be suitable for most assignment types
     */
    function view_dates() {
        if (!$this->assignment->timeavailable && !$this->assignment->timedue) {
            return;
        }

        print_simple_box_start('center', '', '', 0, 'generalbox', 'dates');
        echo '<table>';
        if ($this->assignment->timeavailable) {
            echo '<tr><td class="c0">'.get_string('availabledate','assignment').':</td>';
            echo '    <td class="c1">'.userdate($this->assignment->timeavailable).'</td></tr>';
        }
        if ($this->assignment->timedue) {
            echo '<tr><td class="c0">'.get_string('duedate','assignment').':</td>';
            echo '    <td class="c1">'.userdate($this->assignment->timedue).'</td></tr>';
        }
        echo '</table>';
        print_simple_box_end();
    }


    /**
     * Display the bottom and footer of a page
     *
     * This default method just prints the footer.
     * This will be suitable for most assignment types
     */
    function view_footer() {
        global $OUTPUT;
        echo $OUTPUT->footer();
    }

    /**
     * Display the feedback to the student
     *
     * This default method prints the teacher picture and name, date when marked,
     * grade and teacher submissioncomment.
     *
     * @global object
     * @global object
     * @global object
     * @param object $submission The submission object or NULL in which case it will be loaded
     */
    function view_feedback($submission=NULL) {
        global $USER, $CFG, $DB, $OUTPUT;
        require_once($CFG->libdir.'/gradelib.php');

        if (!has_capability('mod/assignment:submit', $this->context, $USER->id, false)) {
            // can not submit assignments -> no feedback
            return;
        }

        if (!$submission) { /// Get submission for this assignment
            $submission = $this->get_submission($USER->id);
        }

        $grading_info = grade_get_grades($this->course->id, 'mod', 'assignment', $this->assignment->id, $USER->id);
        $item = $grading_info->items[0];
        $grade = $item->grades[$USER->id];

        if ($grade->hidden or $grade->grade === false) { // hidden or error
            return;
        }

        if ($grade->grade === null and empty($grade->str_feedback)) {   /// Nothing to show yet
            return;
        }

        $graded_date = $grade->dategraded;
        $graded_by   = $grade->usermodified;

    /// We need the teacher info
        if (!$teacher = $DB->get_record('user', array('id'=>$graded_by))) {
            print_error('cannotfindteacher');
        }

    /// Print the feedback
        echo $OUTPUT->heading(get_string('feedbackfromteacher', 'assignment', fullname($teacher)));

        echo '<table cellspacing="0" class="feedback">';

        echo '<tr>';
        echo '<td class="left picture">';
        if ($teacher) {
            print_user_picture($teacher, $this->course->id, $teacher->picture);
        }
        echo '</td>';
        echo '<td class="topic">';
        echo '<div class="from">';
        if ($teacher) {
            echo '<div class="fullname">'.fullname($teacher).'</div>';
        }
        echo '<div class="time">'.userdate($graded_date).'</div>';
        echo '</div>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<td class="left side">&nbsp;</td>';
        echo '<td class="content">';
        echo '<div class="grade">';
        echo get_string("grade").': '.$grade->str_long_grade;
        echo '</div>';
        echo '<div class="clearer"></div>';

        echo '<div class="comment">';
        echo $grade->str_feedback;
        echo '</div>';
        echo '</tr>';

        echo '</table>';
    }

    /**
     * Returns a link with info about the state of the assignment submissions
     *
     * This is used by view_header to put this link at the top right of the page.
     * For teachers it gives the number of submitted assignments with a link
     * For students it gives the time of their submission.
     * This will be suitable for most assignment types.
     *
     * @global object
     * @global object
     * @param bool $allgroup print all groups info if user can access all groups, suitable for index.php
     * @return string
     */
    function submittedlink($allgroups=false) {
        global $USER;
        global $CFG;

        $submitted = '';
        $urlbase = "{$CFG->wwwroot}/mod/assignment/";

        $context = get_context_instance(CONTEXT_MODULE,$this->cm->id);
        if (has_capability('mod/assignment:grade', $context)) {
            if ($allgroups and has_capability('moodle/site:accessallgroups', $context)) {
                $group = 0;
            } else {
                $group = groups_get_activity_group($this->cm);
            }
            if ($count = $this->count_real_submissions($group)) {
                $submitted = '<a href="'.$urlbase.'submissions.php?id='.$this->cm->id.'">'.
                             get_string('viewsubmissions', 'assignment', $count).'</a>';
            } else {
                $submitted = '<a href="'.$urlbase.'submissions.php?id='.$this->cm->id.'">'.
                             get_string('noattempts', 'assignment').'</a>';
            }
        } else {
            if (!empty($USER->id)) {
                if ($submission = $this->get_submission($USER->id)) {
                    if ($submission->timemodified) {
                        if ($submission->timemodified <= $this->assignment->timedue || empty($this->assignment->timedue)) {
                            $submitted = '<span class="early">'.userdate($submission->timemodified).'</span>';
                        } else {
                            $submitted = '<span class="late">'.userdate($submission->timemodified).'</span>';
                        }
                    }
                }
            }
        }

        return $submitted;
    }


    /**
     * @todo Document this function
     */
    function setup_elements(&$mform) {

    }

    /**
     * Create a new assignment activity
     *
     * Given an object containing all the necessary data,
     * (defined by the form in mod_form.php) this function
     * will create a new instance and return the id number
     * of the new instance.
     * The due data is added to the calendar
     * This is common to all assignment types.
     *
     * @global object
     * @global object
     * @param object $assignment The data from the form on mod_form.php
     * @return int The id of the assignment
     */
    function add_instance($assignment) {
        global $COURSE, $DB;

        $assignment->timemodified = time();
        $assignment->courseid = $assignment->course;

        if ($returnid = $DB->insert_record("assignment", $assignment)) {
            $assignment->id = $returnid;

            if ($assignment->timedue) {
                $event = new object();
                $event->name        = $assignment->name;
                $event->description = format_module_intro('assignment', $assignment, $assignment->coursemodule);
                $event->courseid    = $assignment->course;
                $event->groupid     = 0;
                $event->userid      = 0;
                $event->modulename  = 'assignment';
                $event->instance    = $returnid;
                $event->eventtype   = 'due';
                $event->timestart   = $assignment->timedue;
                $event->timeduration = 0;

                add_event($event);
            }

            assignment_grade_item_update($assignment);

        }


        return $returnid;
    }

    /**
     * Deletes an assignment activity
     *
     * Deletes all database records, files and calendar events for this assignment.
     *
     * @global object
     * @global object
     * @param object $assignment The assignment to be deleted
     * @return boolean False indicates error
     */
    function delete_instance($assignment) {
        global $CFG, $DB;

        $assignment->courseid = $assignment->course;

        $result = true;

        // now get rid of all files
        $fs = get_file_storage();
        if ($cm = get_coursemodule_from_instance('assignment', $assignment->id)) {
            $context = get_context_instance(CONTEXT_MODULE, $cm->id);
            $fs->delete_area_files($context->id);
        }

        if (! $DB->delete_records('assignment_submissions', array('assignment'=>$assignment->id))) {
            $result = false;
        }

        if (! $DB->delete_records('event', array('modulename'=>'assignment', 'instance'=>$assignment->id))) {
            $result = false;
        }

        if (! $DB->delete_records('assignment', array('id'=>$assignment->id))) {
            $result = false;
        }

        assignment_grade_item_delete($assignment);

        return $result;
    }

    /**
     * Updates a new assignment activity
     *
     * Given an object containing all the necessary data,
     * (defined by the form in mod_form.php) this function
     * will update the assignment instance and return the id number
     * The due date is updated in the calendar
     * This is common to all assignment types.
     *
     * @global object
     * @global object
     * @param object $assignment The data from the form on mod_form.php
     * @return int The assignment id
     */
    function update_instance($assignment) {
        global $COURSE, $DB;

        $assignment->timemodified = time();

        $assignment->id = $assignment->instance;
        $assignment->courseid = $assignment->course;

        $DB->update_record('assignment', $assignment);

        if ($assignment->timedue) {
            $event = new object();

            if ($event->id = $DB->get_field('event', 'id', array('modulename'=>'assignment', 'instance'=>$assignment->id))) {

                $event->name        = $assignment->name;
                $event->description = format_module_intro('assignment', $assignment, $assignment->coursemodule);
                $event->timestart   = $assignment->timedue;

                update_event($event);
            } else {
                $event = new object();
                $event->name        = $assignment->name;
                $event->description = format_module_intro('assignment', $assignment, $assignment->coursemodule);
                $event->courseid    = $assignment->course;
                $event->groupid     = 0;
                $event->userid      = 0;
                $event->modulename  = 'assignment';
                $event->instance    = $assignment->id;
                $event->eventtype   = 'due';
                $event->timestart   = $assignment->timedue;
                $event->timeduration = 0;

                add_event($event);
            }
        } else {
            $DB->delete_records('event', array('modulename'=>'assignment', 'instance'=>$assignment->id));
        }

        // get existing grade item
        assignment_grade_item_update($assignment);

        return true;
    }

    /**
     * Update grade item for this submission.
     */
    function update_grade($submission) {
        assignment_update_grades($this->assignment, $submission->userid);
    }

    /**
     * Top-level function for handling of submissions called by submissions.php
     *
     * This is for handling the teacher interaction with the grading interface
     * This should be suitable for most assignment types.
     *
     * @global object
     * @param string $mode Specifies the kind of teacher interaction taking place
     */
    function submissions($mode) {
        ///The main switch is changed to facilitate
        ///1) Batch fast grading
        ///2) Skip to the next one on the popup
        ///3) Save and Skip to the next one on the popup

        //make user global so we can use the id
        global $USER, $OUTPUT;

        $mailinfo = optional_param('mailinfo', null, PARAM_BOOL);
        if (is_null($mailinfo)) {
            $mailinfo = get_user_preferences('assignment_mailinfo', 0);
        } else {
            set_user_preference('assignment_mailinfo', $mailinfo);
        }

        switch ($mode) {
            case 'grade':                         // We are in a popup window grading
                if ($submission = $this->process_feedback()) {
                    //IE needs proper header with encoding
                    print_header(get_string('feedback', 'assignment').':'.format_string($this->assignment->name));
                    echo $OUTPUT->heading(get_string('changessaved'));
                    print $this->update_main_listing($submission);
                }
                close_window();
                break;

            case 'single':                        // We are in a popup window displaying submission
                $this->display_submission();
                break;

            case 'all':                          // Main window, display everything
                $this->display_submissions();
                break;

            case 'fastgrade':
                ///do the fast grading stuff  - this process should work for all 3 subclasses

                $grading    = false;
                $commenting = false;
                $col        = false;
                if (isset($_POST['submissioncomment'])) {
                    $col = 'submissioncomment';
                    $commenting = true;
                }
                if (isset($_POST['menu'])) {
                    $col = 'menu';
                    $grading = true;
                }
                if (!$col) {
                    //both submissioncomment and grade columns collapsed..
                    $this->display_submissions();
                    break;
                }

                foreach ($_POST[$col] as $id => $unusedvalue){

                    $id = (int)$id; //clean parameter name

                    $this->process_outcomes($id);

                    if (!$submission = $this->get_submission($id)) {
                        $submission = $this->prepare_new_submission($id);
                        $newsubmission = true;
                    } else {
                        $newsubmission = false;
                    }
                    unset($submission->data1);  // Don't need to update this.
                    unset($submission->data2);  // Don't need to update this.

                    //for fast grade, we need to check if any changes take place
                    $updatedb = false;

                    if ($grading) {
                        $grade = $_POST['menu'][$id];
                        $updatedb = $updatedb || ($submission->grade != $grade);
                        $submission->grade = $grade;
                    } else {
                        if (!$newsubmission) {
                            unset($submission->grade);  // Don't need to update this.
                        }
                    }
                    if ($commenting) {
                        $commentvalue = trim($_POST['submissioncomment'][$id]);
                        $updatedb = $updatedb || ($submission->submissioncomment != $commentvalue);
                        $submission->submissioncomment = $commentvalue;
                    } else {
                        unset($submission->submissioncomment);  // Don't need to update this.
                    }

                    $submission->teacher    = $USER->id;
                    if ($updatedb) {
                        $submission->mailed = (int)(!$mailinfo);
                    }

                    $submission->timemarked = time();

                    //if it is not an update, we don't change the last modified time etc.
                    //this will also not write into database if no submissioncomment and grade is entered.

                    if ($updatedb){
                        if ($newsubmission) {
                            if (!isset($submission->submissioncomment)) {
                                $submission->submissioncomment = '';
                            }
                            $sid = $DB->insert_record('assignment_submissions', $submission);
                            $submission->id = $sid;
                        } else {
                            $DB->update_record('assignment_submissions', $submission);
                        }

                        // triger grade event
                        $this->update_grade($submission);

                        //add to log only if updating
                        add_to_log($this->course->id, 'assignment', 'update grades',
                                   'submissions.php?id='.$this->assignment->id.'&user='.$submission->userid,
                                   $submission->userid, $this->cm->id);
                    }

                }

                $message = notify(get_string('changessaved'), 'notifysuccess', 'center', true);

                $this->display_submissions($message);
                break;


            case 'next':
                /// We are currently in pop up, but we want to skip to next one without saving.
                ///    This turns out to be similar to a single case
                /// The URL used is for the next submission.

                $this->display_submission();
                break;

            case 'saveandnext':
                ///We are in pop up. save the current one and go to the next one.
                //first we save the current changes
                if ($submission = $this->process_feedback()) {
                    //print_heading(get_string('changessaved'));
                    $extra_javascript = $this->update_main_listing($submission);
                }

                //then we display the next submission
                $this->display_submission($extra_javascript);
                break;

            default:
                echo "something seriously is wrong!!";
                break;
        }
    }

    /**
     * Helper method updating the listing on the main script from popup using javascript
     *
     * @global object
     * @global object
     * @param $submission object The submission whose data is to be updated on the main page
     */
    function update_main_listing($submission) {
        global $SESSION, $CFG;

        $output = '';

        $perpage = get_user_preferences('assignment_perpage', 10);

        $quickgrade = get_user_preferences('assignment_quickgrade', 0);

        /// Run some Javascript to try and update the parent page
        $output .= '<script type="text/javascript">'."\n<!--\n";
        if (empty($SESSION->flextable['mod-assignment-submissions']->collapse['submissioncomment'])) {
            if ($quickgrade){
                $output.= 'opener.document.getElementById("submissioncomment'.$submission->userid.'").value="'
                .trim($submission->submissioncomment).'";'."\n";
             } else {
                $output.= 'opener.document.getElementById("com'.$submission->userid.
                '").innerHTML="'.shorten_text(trim(strip_tags($submission->submissioncomment)), 15)."\";\n";
            }
        }

        if (empty($SESSION->flextable['mod-assignment-submissions']->collapse['grade'])) {
            //echo optional_param('menuindex');
            if ($quickgrade){
                $output.= 'opener.document.getElementById("menumenu'.$submission->userid.
                '").selectedIndex="'.optional_param('menuindex', 0, PARAM_INT).'";'."\n";
            } else {
                $output.= 'opener.document.getElementById("g'.$submission->userid.'").innerHTML="'.
                $this->display_grade($submission->grade)."\";\n";
            }
        }
        //need to add student's assignments in there too.
        if (empty($SESSION->flextable['mod-assignment-submissions']->collapse['timemodified']) &&
            $submission->timemodified) {
            $output.= 'opener.document.getElementById("ts'.$submission->userid.
                 '").innerHTML="'.addslashes_js($this->print_student_answer($submission->userid)).userdate($submission->timemodified)."\";\n";
        }

        if (empty($SESSION->flextable['mod-assignment-submissions']->collapse['timemarked']) &&
            $submission->timemarked) {
            $output.= 'opener.document.getElementById("tt'.$submission->userid.
                 '").innerHTML="'.userdate($submission->timemarked)."\";\n";
        }

        if (empty($SESSION->flextable['mod-assignment-submissions']->collapse['status'])) {
            $output.= 'opener.document.getElementById("up'.$submission->userid.'").className="s1";';
            $buttontext = get_string('update');
            $button = link_to_popup_window ('/mod/assignment/submissions.php?id='.$this->cm->id.'&amp;userid='.$submission->userid.'&amp;mode=single'.'&amp;offset='.(optional_param('offset', '', PARAM_INT)-1),
                      'grade'.$submission->userid, $buttontext, 450, 700, $buttontext, 'none', true, 'button'.$submission->userid);
            $output.= 'opener.document.getElementById("up'.$submission->userid.'").innerHTML="'.addslashes_js($button).'";';
        }

        $grading_info = grade_get_grades($this->course->id, 'mod', 'assignment', $this->assignment->id, $submission->userid);

        if (empty($SESSION->flextable['mod-assignment-submissions']->collapse['finalgrade'])) {
            $output.= 'opener.document.getElementById("finalgrade_'.$submission->userid.
            '").innerHTML="'.$grading_info->items[0]->grades[$submission->userid]->str_grade.'";'."\n";
        }

        if (!empty($CFG->enableoutcomes) and empty($SESSION->flextable['mod-assignment-submissions']->collapse['outcome'])) {

            if (!empty($grading_info->outcomes)) {
                foreach($grading_info->outcomes as $n=>$outcome) {
                    if ($outcome->grades[$submission->userid]->locked) {
                        continue;
                    }

                    if ($quickgrade){
                        $output.= 'opener.document.getElementById("outcome_'.$n.'_'.$submission->userid.
                        '").selectedIndex="'.$outcome->grades[$submission->userid]->grade.'";'."\n";

                    } else {
                        $options = make_grades_menu(-$outcome->scaleid);
                        $options[0] = get_string('nooutcome', 'grades');
                        $output.= 'opener.document.getElementById("outcome_'.$n.'_'.$submission->userid.'").innerHTML="'.$options[$outcome->grades[$submission->userid]->grade]."\";\n";
                    }

                }
            }
        }

        $output .= "\n-->\n</script>";
        return $output;
    }

    /**
     *  Return a grade in user-friendly form, whether it's a scale or not
     *
     * @global object
     * @param mixed $grade
     * @return string User-friendly representation of grade
     */
    function display_grade($grade) {
        global $DB;

        static $scalegrades = array();   // Cache scales for each assignment - they might have different scales!!

        if ($this->assignment->grade >= 0) {    // Normal number
            if ($grade == -1) {
                return '-';
            } else {
                return $grade.' / '.$this->assignment->grade;
            }

        } else {                                // Scale
            if (empty($scalegrades[$this->assignment->id])) {
                if ($scale = $DB->get_record('scale', array('id'=>-($this->assignment->grade)))) {
                    $scalegrades[$this->assignment->id] = make_menu_from_list($scale->scale);
                } else {
                    return '-';
                }
            }
            if (isset($scalegrades[$this->assignment->id][$grade])) {
                return $scalegrades[$this->assignment->id][$grade];
            }
            return '-';
        }
    }

    /**
     *  Display a single submission, ready for grading on a popup window
     *
     * This default method prints the teacher info and submissioncomment box at the top and
     * the student info and submission at the bottom.
     * This method also fetches the necessary data in order to be able to
     * provide a "Next submission" button.
     * Calls preprocess_submission() to give assignment type plug-ins a chance
     * to process submissions before they are graded
     * This method gets its arguments from the page parameters userid and offset
     *
     * @global object
     * @global object
     * @param string $extra_javascript
     */
    function display_submission($extra_javascript = '') {
        global $CFG, $DB, $PAGE, $OUTPUT;
        require_once($CFG->libdir.'/gradelib.php');
        require_once($CFG->libdir.'/tablelib.php');

        $userid = required_param('userid', PARAM_INT);
        $offset = required_param('offset', PARAM_INT);//offset for where to start looking for student.

        if (!$user = $DB->get_record('user', array('id'=>$userid))) {
            print_error('nousers');
        }

        if (!$submission = $this->get_submission($user->id)) {
            $submission = $this->prepare_new_submission($userid);
        }
        if ($submission->timemodified > $submission->timemarked) {
            $subtype = 'assignmentnew';
        } else {
            $subtype = 'assignmentold';
        }

        $grading_info = grade_get_grades($this->course->id, 'mod', 'assignment', $this->assignment->id, array($user->id));
        $disabled = $grading_info->items[0]->grades[$userid]->locked || $grading_info->items[0]->grades[$userid]->overridden;

    /// construct SQL, using current offset to find the data of the next student
        $course     = $this->course;
        $assignment = $this->assignment;
        $cm         = $this->cm;
        $context    = get_context_instance(CONTEXT_MODULE, $cm->id);

        /// Get all ppl that can submit assignments

        $currentgroup = groups_get_activity_group($cm);
        if ($users = get_users_by_capability($context, 'mod/assignment:submit', 'u.id', '', '', '', $currentgroup, '', false)) {
            $users = array_keys($users);
        }

        // if groupmembersonly used, remove users who are not in any group
        if ($users and !empty($CFG->enablegroupings) and $cm->groupmembersonly) {
            if ($groupingusers = groups_get_grouping_members($cm->groupingid, 'u.id', 'u.id')) {
                $users = array_intersect($users, array_keys($groupingusers));
            }
        }

        $nextid = 0;

        if ($users) {
            $select = 'SELECT u.id, u.firstname, u.lastname, u.picture, u.imagealt,
                              s.id AS submissionid, s.grade, s.submissioncomment,
                              s.timemodified, s.timemarked,
                              COALESCE(SIGN(SIGN(s.timemarked) + SIGN(s.timemarked - s.timemodified)), 0) AS status ';
            $sql = 'FROM {user} u '.
                   'LEFT JOIN {assignment_submissions} s ON u.id = s.userid
                                                                      AND s.assignment = '.$this->assignment->id.' '.
                   'WHERE u.id IN ('.implode(',', $users).') ';

            if ($sort = flexible_table::get_sql_sort('mod-assignment-submissions')) {
                $sort = 'ORDER BY '.$sort.' ';
            }

            if (($auser = $DB->get_records_sql($select.$sql.$sort, null, $offset+1, 1)) !== false) {
                $nextuser = array_shift($auser);
            /// Calculate user status
                $nextuser->status = ($nextuser->timemarked > 0) && ($nextuser->timemarked >= $nextuser->timemodified);
                $nextid = $nextuser->id;
            }
        }

        print_header(get_string('feedback', 'assignment').':'.fullname($user, true).':'.format_string($this->assignment->name));

        /// Print any extra javascript needed for saveandnext
        echo $extra_javascript;

        echo $PAGE->requires->data_for_js('assignment', Array('nextid'=>$nextid, 'userid'=>$userid))->asap();
        echo $PAGE->requires->js('mod/assignment/assignment.js')->asap();

        echo '<table cellspacing="0" class="feedback '.$subtype.'" >';

        ///Start of teacher info row

        echo '<tr>';
        echo '<td class="picture teacher">';
        if ($submission->teacher) {
            $teacher = $DB->get_record('user', array('id'=>$submission->teacher));
        } else {
            global $USER;
            $teacher = $USER;
        }
        print_user_picture($teacher, $this->course->id, $teacher->picture);
        echo '</td>';
        echo '<td class="content">';
        echo '<form id="submitform" action="submissions.php" method="post">';
        echo '<div>'; // xhtml compatibility - invisiblefieldset was breaking layout here
        echo '<input type="hidden" name="offset" value="'.($offset+1).'" />';
        echo '<input type="hidden" name="userid" value="'.$userid.'" />';
        echo '<input type="hidden" name="id" value="'.$this->cm->id.'" />';
        echo '<input type="hidden" name="mode" value="grade" />';
        echo '<input type="hidden" name="menuindex" value="0" />';//selected menu index

        //new hidden field, initialized to -1.
        echo '<input type="hidden" name="saveuserid" value="-1" />';

        if ($submission->timemarked) {
            echo '<div class="from">';
            echo '<div class="fullname">'.fullname($teacher, true).'</div>';
            echo '<div class="time">'.userdate($submission->timemarked).'</div>';
            echo '</div>';
        }
        echo '<div class="grade"><label for="menugrade">'.get_string('grade').'</label> ';
        choose_from_menu(make_grades_menu($this->assignment->grade), 'grade', $submission->grade, get_string('nograde'), '', -1, false, $disabled);
        echo '</div>';

        echo '<div class="clearer"></div>';
        echo '<div class="finalgrade">'.get_string('finalgrade', 'grades').': '.$grading_info->items[0]->grades[$userid]->str_grade.'</div>';
        echo '<div class="clearer"></div>';

        if (!empty($CFG->enableoutcomes)) {
            foreach($grading_info->outcomes as $n=>$outcome) {
                echo '<div class="outcome"><label for="menuoutcome_'.$n.'">'.$outcome->name.'</label> ';
                $options = make_grades_menu(-$outcome->scaleid);
                if ($outcome->grades[$submission->userid]->locked) {
                    $options[0] = get_string('nooutcome', 'grades');
                    echo $options[$outcome->grades[$submission->userid]->grade];
                } else {
                    choose_from_menu($options, 'outcome_'.$n.'['.$userid.']', $outcome->grades[$submission->userid]->grade, get_string('nooutcome', 'grades'), '', 0, false, false, 0, 'menuoutcome_'.$n);
                }
                echo '</div>';
                echo '<div class="clearer"></div>';
            }
        }


        $this->preprocess_submission($submission);

        if ($disabled) {
            echo '<div class="disabledfeedback">'.$grading_info->items[0]->grades[$userid]->str_feedback.'</div>';

        } else {
            print_textarea($this->usehtmleditor, 14, 58, 0, 0, 'submissioncomment', $submission->submissioncomment, $this->course->id);
            if ($this->usehtmleditor) {
                echo '<input type="hidden" name="format" value="'.FORMAT_HTML.'" />';
            } else {
                echo '<div class="format">';
                choose_from_menu(format_text_menu(), "format", $submission->format, "");
                helpbutton("textformat", get_string("helpformatting"));
                echo '</div>';
            }
        }

        $lastmailinfo = get_user_preferences('assignment_mailinfo', 1) ? 'checked="checked"' : '';

        ///Print Buttons in Single View
        echo '<input type="hidden" name="mailinfo" value="0" />';
        echo '<input type="checkbox" id="mailinfo" name="mailinfo" value="1" '.$lastmailinfo.' /><label for="mailinfo">'.get_string('enableemailnotification','assignment').'</label>';
        echo '<div class="buttons">';
        echo '<input type="submit" name="submit" value="'.get_string('savechanges').'" onclick = "document.getElementById(\'submitform\').menuindex.value = document.getElementById(\'submitform\').grade.selectedIndex" />';
        echo '<input type="submit" name="cancel" value="'.get_string('cancel').'" />';
        //if there are more to be graded.
        if ($nextid) {
            echo '<input type="submit" name="saveandnext" value="'.get_string('saveandnext').'" onclick="saveNext()" />';
            echo '<input type="submit" name="next" value="'.get_string('next').'" onclick="setNext();" />';
        }
        echo '</div>';
        echo '</div></form>';

        $customfeedback = $this->custom_feedbackform($submission, true);
        if (!empty($customfeedback)) {
            echo $customfeedback;
        }

        echo '</td></tr>';

        ///End of teacher info row, Start of student info row
        echo '<tr>';
        echo '<td class="picture user">';
        print_user_picture($user, $this->course->id, $user->picture);
        echo '</td>';
        echo '<td class="topic">';
        echo '<div class="from">';
        echo '<div class="fullname">'.fullname($user, true).'</div>';
        if ($submission->timemodified) {
            echo '<div class="time">'.userdate($submission->timemodified).
                                     $this->display_lateness($submission->timemodified).'</div>';
        }
        echo '</div>';
        $this->print_user_files($user->id);
        echo '</td>';
        echo '</tr>';

        ///End of student info row

        echo '</table>';

        echo $OUTPUT->footer();
    }

    /**
     *  Preprocess submission before grading
     *
     * Called by display_submission()
     * The default type does nothing here.
     *
     * @param object $submission The submission object
     */
    function preprocess_submission(&$submission) {
    }

    /**
     *  Display all the submissions ready for grading
     *
     * @global object
     * @global object
     * @global object
     * @global object
     * @param string $message
     * @return bool|void
     */
    function display_submissions($message='') {
        global $CFG, $DB, $USER, $DB, $OUTPUT;
        require_once($CFG->libdir.'/gradelib.php');

        /* first we check to see if the form has just been submitted
         * to request user_preference updates
         */

        if (isset($_POST['updatepref'])){
            $perpage = optional_param('perpage', 10, PARAM_INT);
            $perpage = ($perpage <= 0) ? 10 : $perpage ;
            set_user_preference('assignment_perpage', $perpage);
            set_user_preference('assignment_quickgrade', optional_param('quickgrade', 0, PARAM_BOOL));
        }

        /* next we get perpage and quickgrade (allow quick grade) params
         * from database
         */
        $perpage    = get_user_preferences('assignment_perpage', 10);

        $quickgrade = get_user_preferences('assignment_quickgrade', 0);

        $grading_info = grade_get_grades($this->course->id, 'mod', 'assignment', $this->assignment->id);

        if (!empty($CFG->enableoutcomes) and !empty($grading_info->outcomes)) {
            $uses_outcomes = true;
        } else {
            $uses_outcomes = false;
        }

        $page    = optional_param('page', 0, PARAM_INT);
        $strsaveallfeedback = get_string('saveallfeedback', 'assignment');

    /// Some shortcuts to make the code read better

        $course     = $this->course;
        $assignment = $this->assignment;
        $cm         = $this->cm;

        $tabindex = 1; //tabindex for quick grading tabbing; Not working for dropdowns yet
        add_to_log($course->id, 'assignment', 'view submission', 'submissions.php?id='.$this->cm->id, $this->assignment->id, $this->cm->id);
        $navigation = build_navigation($this->strsubmissions, $this->cm);
        print_header_simple(format_string($this->assignment->name,true), "", $navigation,
                '', '', true, update_module_button($cm->id, $course->id, $this->strassignment), navmenu($course, $cm));

        $course_context = get_context_instance(CONTEXT_COURSE, $course->id);
        if (has_capability('gradereport/grader:view', $course_context) && has_capability('moodle/grade:viewall', $course_context)) {
            echo '<div class="allcoursegrades"><a href="' . $CFG->wwwroot . '/grade/report/grader/index.php?id=' . $course->id . '">'
                . get_string('seeallcoursegrades', 'grades') . '</a></div>';
        }

        if (!empty($message)) {
            echo $message;   // display messages here if any
        }

        $context = get_context_instance(CONTEXT_MODULE, $cm->id);

    /// Check to see if groups are being used in this assignment

        /// find out current groups mode
        $groupmode = groups_get_activity_groupmode($cm);
        $currentgroup = groups_get_activity_group($cm, true);
        groups_print_activity_menu($cm, 'submissions.php?id=' . $this->cm->id);

        /// Get all ppl that are allowed to submit assignments
        if ($users = get_users_by_capability($context, 'mod/assignment:submit', 'u.id', '', '', '', $currentgroup, '', false)) {
            $users = array_keys($users);
        }

        // if groupmembersonly used, remove users who are not in any group
        if ($users and !empty($CFG->enablegroupings) and $cm->groupmembersonly) {
            if ($groupingusers = groups_get_grouping_members($cm->groupingid, 'u.id', 'u.id')) {
                $users = array_intersect($users, array_keys($groupingusers));
            }
        }

        $tablecolumns = array('picture', 'fullname', 'grade', 'submissioncomment', 'timemodified', 'timemarked', 'status', 'finalgrade');
        if ($uses_outcomes) {
            $tablecolumns[] = 'outcome'; // no sorting based on outcomes column
        }

        $tableheaders = array('',
                              get_string('fullname'),
                              get_string('grade'),
                              get_string('comment', 'assignment'),
                              get_string('lastmodified').' ('.get_string('submission', 'assignment').')',
                              get_string('lastmodified').' ('.get_string('grade').')',
                              get_string('status'),
                              get_string('finalgrade', 'grades'));
        if ($uses_outcomes) {
            $tableheaders[] = get_string('outcome', 'grades');
        }

        require_once($CFG->libdir.'/tablelib.php');
        $table = new flexible_table('mod-assignment-submissions');

        $table->define_columns($tablecolumns);
        $table->define_headers($tableheaders);
        $table->define_baseurl($CFG->wwwroot.'/mod/assignment/submissions.php?id='.$this->cm->id.'&amp;currentgroup='.$currentgroup);

        $table->sortable(true, 'lastname');//sorted by lastname by default
        $table->collapsible(true);
        $table->initialbars(true);

        $table->column_suppress('picture');
        $table->column_suppress('fullname');

        $table->column_class('picture', 'picture');
        $table->column_class('fullname', 'fullname');
        $table->column_class('grade', 'grade');
        $table->column_class('submissioncomment', 'comment');
        $table->column_class('timemodified', 'timemodified');
        $table->column_class('timemarked', 'timemarked');
        $table->column_class('status', 'status');
        $table->column_class('finalgrade', 'finalgrade');
        if ($uses_outcomes) {
            $table->column_class('outcome', 'outcome');
        }

        $table->set_attribute('cellspacing', '0');
        $table->set_attribute('id', 'attempts');
        $table->set_attribute('class', 'submissions');
        $table->set_attribute('width', '100%');
        //$table->set_attribute('align', 'center');

        $table->no_sorting('finalgrade');
        $table->no_sorting('outcome');

        // Start working -- this is necessary as soon as the niceties are over
        $table->setup();

        if (empty($users)) {
            echo $OUTPUT->heading(get_string('nosubmitusers','assignment'));
            return true;
        }

    /// Construct the SQL

        if ($where = $table->get_sql_where()) {
            $where .= ' AND ';
        }

        if ($sort = $table->get_sql_sort()) {
            $sort = ' ORDER BY '.$sort;
        }

        $select = 'SELECT u.id, u.firstname, u.lastname, u.picture, u.imagealt,
                          s.id AS submissionid, s.grade, s.submissioncomment,
                          s.timemodified, s.timemarked,
                          COALESCE(SIGN(SIGN(s.timemarked) + SIGN(s.timemarked - s.timemodified)), 0) AS status ';
        $sql = 'FROM {user} u '.
               'LEFT JOIN {assignment_submissions} s ON u.id = s.userid
                                                                  AND s.assignment = '.$this->assignment->id.' '.
               'WHERE '.$where.'u.id IN ('.implode(',',$users).') ';

        $table->pagesize($perpage, count($users));

        ///offset used to calculate index of student in that particular query, needed for the pop up to know who's next
        $offset = $page * $perpage;

        $strupdate = get_string('update');
        $strgrade  = get_string('grade');
        $grademenu = make_grades_menu($this->assignment->grade);

        if (($ausers = $DB->get_records_sql($select.$sql.$sort, null, $table->get_page_start(), $table->get_page_size())) !== false) {
            $grading_info = grade_get_grades($this->course->id, 'mod', 'assignment', $this->assignment->id, array_keys($ausers));
            foreach ($ausers as $auser) {
                $final_grade = $grading_info->items[0]->grades[$auser->id];
                $grademax = $grading_info->items[0]->grademax;
                $final_grade->formatted_grade = round($final_grade->grade,2) .' / ' . round($grademax,2);
                $locked_overridden = 'locked';
                if ($final_grade->overridden) {
                    $locked_overridden = 'overridden';
                }

            /// Calculate user status
                $auser->status = ($auser->timemarked > 0) && ($auser->timemarked >= $auser->timemodified);
                $picture = print_user_picture($auser, $course->id, $auser->picture, false, true);

                if (empty($auser->submissionid)) {
                    $auser->grade = -1; //no submission yet
                }

                if (!empty($auser->submissionid)) {
                ///Prints student answer and student modified date
                ///attach file or print link to student answer, depending on the type of the assignment.
                ///Refer to print_student_answer in inherited classes.
                    if ($auser->timemodified > 0) {
                        $studentmodified = '<div id="ts'.$auser->id.'">'.$this->print_student_answer($auser->id)
                                         . userdate($auser->timemodified).'</div>';
                    } else {
                        $studentmodified = '<div id="ts'.$auser->id.'">&nbsp;</div>';
                    }
                ///Print grade, dropdown or text
                    if ($auser->timemarked > 0) {
                        $teachermodified = '<div id="tt'.$auser->id.'">'.userdate($auser->timemarked).'</div>';

                        if ($final_grade->locked or $final_grade->overridden) {
                            $grade = '<div id="g'.$auser->id.'" class="'. $locked_overridden .'">'.$final_grade->formatted_grade.'</div>';
                        } else if ($quickgrade) {
                            $menu = choose_from_menu(make_grades_menu($this->assignment->grade),
                                                     'menu['.$auser->id.']', $auser->grade,
                                                     get_string('nograde'),'',-1,true,false,$tabindex++);
                            $grade = '<div id="g'.$auser->id.'">'. $menu .'</div>';
                        } else {
                            $grade = '<div id="g'.$auser->id.'">'.$this->display_grade($auser->grade).'</div>';
                        }

                    } else {
                        $teachermodified = '<div id="tt'.$auser->id.'">&nbsp;</div>';
                        if ($final_grade->locked or $final_grade->overridden) {
                            $grade = '<div id="g'.$auser->id.'" class="'. $locked_overridden .'">'.$final_grade->formatted_grade.'</div>';
                        } else if ($quickgrade) {
                            $menu = choose_from_menu(make_grades_menu($this->assignment->grade),
                                                     'menu['.$auser->id.']', $auser->grade,
                                                     get_string('nograde'),'',-1,true,false,$tabindex++);
                            $grade = '<div id="g'.$auser->id.'">'.$menu.'</div>';
                        } else {
                            $grade = '<div id="g'.$auser->id.'">'.$this->display_grade($auser->grade).'</div>';
                        }
                    }
                ///Print Comment
                    if ($final_grade->locked or $final_grade->overridden) {
                        $comment = '<div id="com'.$auser->id.'">'.shorten_text(strip_tags($final_grade->str_feedback),15).'</div>';

                    } else if ($quickgrade) {
                        $comment = '<div id="com'.$auser->id.'">'
                                 . '<textarea tabindex="'.$tabindex++.'" name="submissioncomment['.$auser->id.']" id="submissioncomment'
                                 . $auser->id.'" rows="2" cols="20">'.($auser->submissioncomment).'</textarea></div>';
                    } else {
                        $comment = '<div id="com'.$auser->id.'">'.shorten_text(strip_tags($auser->submissioncomment),15).'</div>';
                    }
                } else {
                    $studentmodified = '<div id="ts'.$auser->id.'">&nbsp;</div>';
                    $teachermodified = '<div id="tt'.$auser->id.'">&nbsp;</div>';
                    $status          = '<div id="st'.$auser->id.'">&nbsp;</div>';

                    if ($final_grade->locked or $final_grade->overridden) {
                        $grade = '<div id="g'.$auser->id.'">'.$final_grade->formatted_grade . '</div>';
                    } else if ($quickgrade) {   // allow editing
                        $menu = choose_from_menu(make_grades_menu($this->assignment->grade),
                                                 'menu['.$auser->id.']', $auser->grade,
                                                 get_string('nograde'),'',-1,true,false,$tabindex++);
                        $grade = '<div id="g'.$auser->id.'">'.$menu.'</div>';
                    } else {
                        $grade = '<div id="g'.$auser->id.'">-</div>';
                    }

                    if ($final_grade->locked or $final_grade->overridden) {
                        $comment = '<div id="com'.$auser->id.'">'.$final_grade->str_feedback.'</div>';
                    } else if ($quickgrade) {
                        $comment = '<div id="com'.$auser->id.'">'
                                 . '<textarea tabindex="'.$tabindex++.'" name="submissioncomment['.$auser->id.']" id="submissioncomment'
                                 . $auser->id.'" rows="2" cols="20">'.($auser->submissioncomment).'</textarea></div>';
                    } else {
                        $comment = '<div id="com'.$auser->id.'">&nbsp;</div>';
                    }
                }

                if (empty($auser->status)) { /// Confirm we have exclusively 0 or 1
                    $auser->status = 0;
                } else {
                    $auser->status = 1;
                }

                $buttontext = ($auser->status == 1) ? $strupdate : $strgrade;

                ///No more buttons, we use popups ;-).
                $popup_url = '/mod/assignment/submissions.php?id='.$this->cm->id
                           . '&amp;userid='.$auser->id.'&amp;mode=single'.'&amp;offset='.$offset++;
                $button = link_to_popup_window ($popup_url, 'grade'.$auser->id, $buttontext, 600, 780,
                                                $buttontext, 'none', true, 'button'.$auser->id);

                $status  = '<div id="up'.$auser->id.'" class="s'.$auser->status.'">'.$button.'</div>';

                $finalgrade = '<span id="finalgrade_'.$auser->id.'">'.$final_grade->str_grade.'</span>';

                $outcomes = '';

                if ($uses_outcomes) {

                    foreach($grading_info->outcomes as $n=>$outcome) {
                        $outcomes .= '<div class="outcome"><label>'.$outcome->name.'</label>';
                        $options = make_grades_menu(-$outcome->scaleid);

                        if ($outcome->grades[$auser->id]->locked or !$quickgrade) {
                            $options[0] = get_string('nooutcome', 'grades');
                            $outcomes .= ': <span id="outcome_'.$n.'_'.$auser->id.'">'.$options[$outcome->grades[$auser->id]->grade].'</span>';
                        } else {
                            $outcomes .= ' ';
                            $outcomes .= choose_from_menu($options, 'outcome_'.$n.'['.$auser->id.']',
                                        $outcome->grades[$auser->id]->grade, get_string('nooutcome', 'grades'), '', 0, true, false, 0, 'outcome_'.$n.'_'.$auser->id);
                        }
                        $outcomes .= '</div>';
                    }
                }

				$userlink = '<a href="' . $CFG->wwwroot . '/user/view.php?id=' . $auser->id . '&amp;course=' . $course->id . '">' . fullname($auser) . '</a>';
                $row = array($picture, $userlink, $grade, $comment, $studentmodified, $teachermodified, $status, $finalgrade);
                if ($uses_outcomes) {
                    $row[] = $outcomes;
                }

                $table->add_data($row);
            }
        }

        /// Print quickgrade form around the table
        if ($quickgrade){
            echo '<form action="submissions.php" id="fastg" method="post">';
            echo '<div>';
            echo '<input type="hidden" name="id" value="'.$this->cm->id.'" />';
            echo '<input type="hidden" name="mode" value="fastgrade" />';
            echo '<input type="hidden" name="page" value="'.$page.'" />';
            echo '</div>';
        }

        $table->print_html();  /// Print the whole table

        if ($quickgrade){
            $lastmailinfo = get_user_preferences('assignment_mailinfo', 1) ? 'checked="checked"' : '';
            echo '<div class="fgcontrols">';
            echo '<div class="emailnotification">';
            echo '<label for="mailinfo">'.get_string('enableemailnotification','assignment').'</label>';
            echo '<input type="hidden" name="mailinfo" value="0" />';
            echo '<input type="checkbox" id="mailinfo" name="mailinfo" value="1" '.$lastmailinfo.' />';
            helpbutton('emailnotification', get_string('enableemailnotification', 'assignment'), 'assignment').'</p></div>';
            echo '</div>';
            echo '<div class="fastgbutton"><input type="submit" name="fastg" value="'.get_string('saveallfeedback', 'assignment').'" /></div>';
            echo '</div>';
            echo '</form>';
        }
        /// End of fast grading form

        /// Mini form for setting user preference
        echo '<div class="qgprefs">';
        echo '<form id="options" action="submissions.php?id='.$this->cm->id.'" method="post"><div>';
        echo '<input type="hidden" name="updatepref" value="1" />';
        echo '<table id="optiontable">';
        echo '<tr><td>';
        echo '<label for="perpage">'.get_string('pagesize','assignment').'</label>';
        echo '</td>';
        echo '<td>';
        echo '<input type="text" id="perpage" name="perpage" size="1" value="'.$perpage.'" />';
        helpbutton('pagesize', get_string('pagesize','assignment'), 'assignment');
        echo '</td></tr>';
        echo '<tr><td>';
        echo '<label for="quickgrade">'.get_string('quickgrade','assignment').'</label>';
        echo '</td>';
        echo '<td>';
        $checked = $quickgrade ? 'checked="checked"' : '';
        echo '<input type="checkbox" id="quickgrade" name="quickgrade" value="1" '.$checked.' />';
        helpbutton('quickgrade', get_string('quickgrade', 'assignment'), 'assignment').'</p></div>';
        echo '</td></tr>';
        echo '<tr><td colspan="2">';
        echo '<input type="submit" value="'.get_string('savepreferences').'" />';
        echo '</td></tr></table>';
        echo '</div></form></div>';
        ///End of mini form
        echo $OUTPUT->footer();
    }

    /**
     *  Process teacher feedback submission
     *
     * This is called by submissions() when a grading even has taken place.
     * It gets its data from the submitted form.
     *
     * @global object
     * @global object
     * @global object
     * @return object|bool The updated submission object or false
     */
    function process_feedback() {
        global $CFG, $USER, $DB;
        require_once($CFG->libdir.'/gradelib.php');

        if (!$feedback = data_submitted()) {      // No incoming data?
            return false;
        }

        ///For save and next, we need to know the userid to save, and the userid to go
        ///We use a new hidden field in the form, and set it to -1. If it's set, we use this
        ///as the userid to store
        if ((int)$feedback->saveuserid !== -1){
            $feedback->userid = $feedback->saveuserid;
        }

        if (!empty($feedback->cancel)) {          // User hit cancel button
            return false;
        }

        $grading_info = grade_get_grades($this->course->id, 'mod', 'assignment', $this->assignment->id, $feedback->userid);

        // store outcomes if needed
        $this->process_outcomes($feedback->userid);

        $submission = $this->get_submission($feedback->userid, true);  // Get or make one

        if (!$grading_info->items[0]->grades[$feedback->userid]->locked and
            !$grading_info->items[0]->grades[$feedback->userid]->overridden) {

            $submission->grade      = $feedback->grade;
            $submission->submissioncomment    = $feedback->submissioncomment;
            $submission->format     = $feedback->format;
            $submission->teacher    = $USER->id;
            $mailinfo = get_user_preferences('assignment_mailinfo', 0);
            if (!$mailinfo) {
                $submission->mailed = 1;       // treat as already mailed
            } else {
                $submission->mailed = 0;       // Make sure mail goes out (again, even)
            }
            $submission->timemarked = time();

            unset($submission->data1);  // Don't need to update this.
            unset($submission->data2);  // Don't need to update this.

            if (empty($submission->timemodified)) {   // eg for offline assignments
                // $submission->timemodified = time();
            }

            $DB->update_record('assignment_submissions', $submission);

            // triger grade event
            $this->update_grade($submission);

            add_to_log($this->course->id, 'assignment', 'update grades',
                       'submissions.php?id='.$this->assignment->id.'&user='.$feedback->userid, $feedback->userid, $this->cm->id);
        }

        return $submission;

    }

    function process_outcomes($userid) {
        global $CFG, $USER;

        if (empty($CFG->enableoutcomes)) {
            return;
        }

        require_once($CFG->libdir.'/gradelib.php');

        if (!$formdata = data_submitted()) {
            return;
        }

        $data = array();
        $grading_info = grade_get_grades($this->course->id, 'mod', 'assignment', $this->assignment->id, $userid);

        if (!empty($grading_info->outcomes)) {
            foreach($grading_info->outcomes as $n=>$old) {
                $name = 'outcome_'.$n;
                if (isset($formdata->{$name}[$userid]) and $old->grades[$userid]->grade != $formdata->{$name}[$userid]) {
                    $data[$n] = $formdata->{$name}[$userid];
                }
            }
        }
        if (count($data) > 0) {
            grade_update_outcomes('mod/assignment', $this->course->id, 'mod', 'assignment', $this->assignment->id, $userid, $data);
        }

    }

    /**
     * Load the submission object for a particular user
     *
     * @global object
     * @global object
     * @param $userid int The id of the user whose submission we want or 0 in which case USER->id is used
     * @param $createnew boolean optional Defaults to false. If set to true a new submission object will be created in the database
     * @param bool $teachermodified student submission set if false
     * @return object The submission
     */
    function get_submission($userid=0, $createnew=false, $teachermodified=false) {
        global $USER, $DB;

        if (empty($userid)) {
            $userid = $USER->id;
        }

        $submission = $DB->get_record('assignment_submissions', array('assignment'=>$this->assignment->id, 'userid'=>$userid));

        if ($submission || !$createnew) {
            return $submission;
        }
        $newsubmission = $this->prepare_new_submission($userid, $teachermodified);
        $DB->insert_record("assignment_submissions", $newsubmission);

        return $DB->get_record('assignment_submissions', array('assignment'=>$this->assignment->id, 'userid'=>$userid));
    }

    /**
     * Instantiates a new submission object for a given user
     *
     * Sets the assignment, userid and times, everything else is set to default values.
     *
     * @param int $userid The userid for which we want a submission object
     * @param bool $teachermodified student submission set if false
     * @return object The submission
     */
    function prepare_new_submission($userid, $teachermodified=false) {
        $submission = new Object;
        $submission->assignment   = $this->assignment->id;
        $submission->userid       = $userid;
        //$submission->timecreated  = time();
        $submission->timecreated = '';
        // teachers should not be modifying modified date, except offline assignments
        if ($teachermodified) {
            $submission->timemodified = 0;
        } else {
            $submission->timemodified = $submission->timecreated;
        }
        $submission->numfiles     = 0;
        $submission->data1        = '';
        $submission->data2        = '';
        $submission->grade        = -1;
        $submission->submissioncomment      = '';
        $submission->format       = 0;
        $submission->teacher      = 0;
        $submission->timemarked   = 0;
        $submission->mailed       = 0;
        return $submission;
    }

    /**
     * Return all assignment submissions by ENROLLED students (even empty)
     *
     * @param string $sort optional field names for the ORDER BY in the sql query
     * @param string $dir optional specifying the sort direction, defaults to DESC
     * @return array The submission objects indexed by id
     */
    function get_submissions($sort='', $dir='DESC') {
        return assignment_get_all_submissions($this->assignment, $sort, $dir);
    }

    /**
     * Counts all real assignment submissions by ENROLLED students (not empty ones)
     *
     * @param int $groupid optional If nonzero then count is restricted to this group
     * @return int The number of submissions
     */
    function count_real_submissions($groupid=0) {
        return assignment_count_real_submissions($this->cm, $groupid);
    }

    /**
     * Alerts teachers by email of new or changed assignments that need grading
     *
     * First checks whether the option to email teachers is set for this assignment.
     * Sends an email to ALL teachers in the course (or in the group if using separate groups).
     * Uses the methods email_teachers_text() and email_teachers_html() to construct the content.
     *
     * @global object
     * @global object
     * @param $submission object The submission that has changed
     * @return void
     */
    function email_teachers($submission) {
        global $CFG, $DB;

        if (empty($this->assignment->emailteachers)) {          // No need to do anything
            return;
        }

        $user = $DB->get_record('user', array('id'=>$submission->userid));

        if ($teachers = $this->get_graders($user)) {

            $strassignments = get_string('modulenameplural', 'assignment');
            $strassignment  = get_string('modulename', 'assignment');
            $strsubmitted  = get_string('submitted', 'assignment');

            foreach ($teachers as $teacher) {
                $info = new object();
                $info->username = fullname($user, true);
                $info->assignment = format_string($this->assignment->name,true);
                $info->url = $CFG->wwwroot.'/mod/assignment/submissions.php?id='.$this->cm->id;

                $postsubject = $strsubmitted.': '.$info->username.' -> '.$this->assignment->name;
                $posttext = $this->email_teachers_text($info);
                $posthtml = ($teacher->mailformat == 1) ? $this->email_teachers_html($info) : '';

                $eventdata = new object();
                $eventdata->modulename       = 'assignment';
                $eventdata->userfrom         = $user;
                $eventdata->userto           = $teacher;
                $eventdata->subject          = $postsubject;
                $eventdata->fullmessage      = $posttext;
                $eventdata->fullmessageformat = FORMAT_PLAIN;
                $eventdata->fullmessagehtml  = $posthtml;
                $eventdata->smallmessage     = '';
                if ( events_trigger('message_send', $eventdata) > 0 ){
                }
            }
        }
    }

    /**
     * @param string $filearea
     * @param array $args
     * @return bool
     */
    function send_file($filearea, $args) {
        debugging('plugin does not implement file sending', DEBUG_DEVELOPER);
        return false;
    }

    /**
     * Returns a list of teachers that should be grading given submission
     *
     * @param object $user
     * @return array
     */
    function get_graders($user) {
        //potential graders
        $potgraders = get_users_by_capability($this->context, 'mod/assignment:grade', '', '', '', '', '', '', false, false);

        $graders = array();
        if (groups_get_activity_groupmode($this->cm) == SEPARATEGROUPS) {   // Separate groups are being used
            if ($groups = groups_get_all_groups($this->course->id, $user->id)) {  // Try to find all groups
                foreach ($groups as $group) {
                    foreach ($potgraders as $t) {
                        if ($t->id == $user->id) {
                            continue; // do not send self
                        }
                        if (groups_is_member($group->id, $t->id)) {
                            $graders[$t->id] = $t;
                        }
                    }
                }
            } else {
                // user not in group, try to find graders without group
                foreach ($potgraders as $t) {
                    if ($t->id == $user->id) {
                        continue; // do not send self
                    }
                    if (!groups_get_all_groups($this->course->id, $t->id)) { //ugly hack
                        $graders[$t->id] = $t;
                    }
                }
            }
        } else {
            foreach ($potgraders as $t) {
                if ($t->id == $user->id) {
                    continue; // do not send self
                }
                $graders[$t->id] = $t;
            }
        }
        return $graders;
    }

    /**
     * Creates the text content for emails to teachers
     *
     * @param $info object The info used by the 'emailteachermail' language string
     * @return string
     */
    function email_teachers_text($info) {
        $posttext  = format_string($this->course->shortname).' -> '.$this->strassignments.' -> '.
                     format_string($this->assignment->name)."\n";
        $posttext .= '---------------------------------------------------------------------'."\n";
        $posttext .= get_string("emailteachermail", "assignment", $info)."\n";
        $posttext .= "\n---------------------------------------------------------------------\n";
        return $posttext;
    }

     /**
     * Creates the html content for emails to teachers
     *
     * @param $info object The info used by the 'emailteachermailhtml' language string
     * @return string
     */
    function email_teachers_html($info) {
        global $CFG;
        $posthtml  = '<p><font face="sans-serif">'.
                     '<a href="'.$CFG->wwwroot.'/course/view.php?id='.$this->course->id.'">'.format_string($this->course->shortname).'</a> ->'.
                     '<a href="'.$CFG->wwwroot.'/mod/assignment/index.php?id='.$this->course->id.'">'.$this->strassignments.'</a> ->'.
                     '<a href="'.$CFG->wwwroot.'/mod/assignment/view.php?id='.$this->cm->id.'">'.format_string($this->assignment->name).'</a></font></p>';
        $posthtml .= '<hr /><font face="sans-serif">';
        $posthtml .= '<p>'.get_string('emailteachermailhtml', 'assignment', $info).'</p>';
        $posthtml .= '</font><hr />';
        return $posthtml;
    }

    /**
     * Produces a list of links to the files uploaded by a user
     *
     * @param $userid int optional id of the user. If 0 then $USER->id is used.
     * @param $return boolean optional defaults to false. If true the list is returned rather than printed
     * @return string optional
     */
    function print_user_files($userid=0, $return=false) {
        global $CFG, $USER, $OUTPUT;

        if (!$userid) {
            if (!isloggedin()) {
                return '';
            }
            $userid = $USER->id;
        }

        $output = '';

        $fs = get_file_storage();
        $browser = get_file_browser();

        $found = false;

        if ($files = $fs->get_area_files($this->context->id, 'assignment_submission', $userid, "timemodified", false)) {
            $button = new portfolio_add_button();
            foreach ($files as $file) {
                $filename = $file->get_filename();
                $found = true;
                $mimetype = $file->get_mimetype();
                $path = file_encode_url($CFG->wwwroot.'/pluginfile.php', '/'.$this->context->id.'/assignment_submission/'.$userid.'/'.$filename);
                $output .= '<a href="'.$path.'" ><img src="'.$OUTPUT->old_icon_url(file_mimetype_icon($mimetype)).'" class="icon" alt="'.$mimetype.'" />'.s($filename).'</a>';
                if ($this->portfolio_exportable() && has_capability('mod/assignment:exportownsubmission', $this->context)) {
                    $button->set_callback_options('assignment_portfolio_caller', array('id' => $this->cm->id, 'fileid' => $file->get_id()));
                    $button->set_formats(portfolio_format_from_file($file));
                    $output .= $button->to_html(PORTFOLIO_ADD_ICON_LINK);
                }
                $output .= '<br />';
            }
            if (count($files) > 1  && $this->portfolio_exportable() && has_capability('mod/assignment:exportownsubmission', $this->context)) {
                $button->set_callback_options('assignment_portfolio_caller', array('id' => $this->cm->id));
                $button->set_formats(PORTFOLIO_PORMAT_FILE);
                $output .= '<br />'  . $button->to_html();
            }
        }

        $output = '<div class="files">'.$output.'</div>';

        if ($return) {
            return $output;
        }
        echo $output;
    }

    /**
     * Count the files uploaded by a given user
     *
     * @param $userid int The user id
     * @return int
     */
    function count_user_files($userid) {
        $fs = get_file_storage();
        $files = $fs->get_area_files($this->context->id, 'assignment_submission', $userid, "id", false);
        return count($files);
    }

    /**
     * Returns true if the student is allowed to submit
     *
     * Checks that the assignment has started and, if the option to prevent late
     * submissions is set, also checks that the assignment has not yet closed.
     * @return boolean
     */
    function isopen() {
        $time = time();
        if ($this->assignment->preventlate && $this->assignment->timedue) {
            return ($this->assignment->timeavailable <= $time && $time <= $this->assignment->timedue);
        } else {
            return ($this->assignment->timeavailable <= $time);
        }
    }


    /**
     * Return true if is set description is hidden till available date
     *
     * This is needed by calendar so that hidden descriptions do not
     * come up in upcoming events.
     *
     * Check that description is hidden till available date
     * By default return false
     * Assignments types should implement this method if needed
     * @return boolen
     */
    function description_is_hidden() {
        return false;
    }

    /**
     * Return an outline of the user's interaction with the assignment
     *
     * The default method prints the grade and timemodified
     * @param $user object
     * @return object with properties ->info and ->time
     */
    function user_outline($user) {
        if ($submission = $this->get_submission($user->id)) {

            $result = new object();
            $result->info = get_string('grade').': '.$this->display_grade($submission->grade);
            $result->time = $submission->timemodified;
            return $result;
        }
        return NULL;
    }

    /**
     * Print complete information about the user's interaction with the assignment
     *
     * @param $user object
     */
    function user_complete($user) {
        if ($submission = $this->get_submission($user->id)) {

            $fs = get_file_storage();
            $browser = get_file_browser();

            if ($files = $fs->get_area_files($this->context->id, 'assignment_submission', $user->id, "timemodified", false)) {
                $countfiles = count($files)." ".get_string("uploadedfiles", "assignment");
                foreach ($files as $file) {
                    $countfiles .= "; ".$file->get_filename();
                }
            }

            print_simple_box_start();
            echo get_string("lastmodified").": ";
            echo userdate($submission->timemodified);
            echo $this->display_lateness($submission->timemodified);

            $this->print_user_files($user->id);

            echo '<br />';

            if (empty($submission->timemarked)) {
                print_string("notgradedyet", "assignment");
            } else {
                $this->view_feedback($submission);
            }

            print_simple_box_end();

        } else {
            print_string("notsubmittedyet", "assignment");
        }
    }

    /**
     * Return a string indicating how late a submission is
     *
     * @param $timesubmitted int
     * @return string
     */
    function display_lateness($timesubmitted) {
        return assignment_display_lateness($timesubmitted, $this->assignment->timedue);
    }

    /**
     * Empty method stub for all delete actions.
     */
    function delete() {
        //nothing by default
        redirect('view.php?id='.$this->cm->id);
    }

    /**
     * Empty custom feedback grading form.
     */
    function custom_feedbackform($submission, $return=false) {
        //nothing by default
        return '';
    }

    /**
     * Add a get_coursemodule_info function in case any assignment type wants to add 'extra' information
     * for the course (see resource).
     *
     * Given a course_module object, this function returns any "extra" information that may be needed
     * when printing this activity in a course listing.  See get_array_of_activities() in course/lib.php.
     *
     * @param $coursemodule object The coursemodule object (record).
     * @return object An object on information that the coures will know about (most noticeably, an icon).
     *
     */
    function get_coursemodule_info($coursemodule) {
        return false;
    }

    /**
     * Plugin cron method - do not use $this here, create new assignment instances if needed.
     * @return void
     */
    function cron() {
        //no plugin cron by default - override if needed
    }

    /**
     * Reset all submissions
     */
    function reset_userdata($data) {
        global $CFG, $DB;

        if (!$DB->count_records('assignment', array('course'=>$data->courseid, 'assignmenttype'=>$this->type))) {
            return array(); // no assignments of this type present
        }

        $componentstr = get_string('modulenameplural', 'assignment');
        $status = array();

        $typestr = get_string('type'.$this->type, 'assignment');

        if (!empty($data->reset_assignment_submissions)) {
            $assignmentssql = "SELECT a.id
                                 FROM {assignment} a
                                WHERE a.course=? AND a.assignmenttype=?";
            $params = array($data->courseid, $this->type);

            // now get rid of all submissions and responses
            $fs = get_file_storage();
            if ($assignments = $DB->get_records_sql($assignmentssql, $params)) {
                foreach ($assignments as $assignmentid=>$unused) {
                    if (!$cm = get_coursemodule_from_instance('assignment', $assignmentid)) {
                        continue;
                    }
                    $context = get_context_instance(CONTEXT_MODULE, $cm->id);
                    $fs->delete_area_files($context->id, 'assignment_submission');
                    $fs->delete_area_files($context->id, 'assignment_response');
                }
            }

            $DB->delete_records_select('assignment_submissions', "assignment IN ($assignmentssql)", $params);

            $status[] = array('component'=>$componentstr, 'item'=>get_string('deleteallsubmissions','assignment').': '.$typestr, 'error'=>false);

            if (empty($data->reset_gradebook_grades)) {
                // remove all grades from gradebook
                assignment_reset_gradebook($data->courseid, $this->type);
            }
        }

        /// updating dates - shift may be negative too
        if ($data->timeshift) {
            shift_course_mod_dates('assignment', array('timedue', 'timeavailable'), $data->timeshift, $data->courseid);
            $status[] = array('component'=>$componentstr, 'item'=>get_string('datechanged').': '.$typestr, 'error'=>false);
        }

        return $status;
    }


    function portfolio_exportable() {
        return false;
    }
} ////// End of the assignment_base class

/**
 * @package   mod-assignment
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_assignment_upload_file_form extends moodleform {
    function definition() {
        $mform = $this->_form;
        $instance = $this->_customdata;

        //TODO: improve upload size checking
        $mform->setMaxFileSize($instance->assignment->maxbytes);

        // visible elements
        $mform->addElement('file', 'newfile', get_string('uploadafile'));

        // hidden params
        $mform->addElement('hidden', 'id', $instance->cm->id);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'action', 'uploadfile');
        $mform->setType('action', PARAM_ALPHA);

        // buttons
        $this->add_action_buttons(false, get_string('uploadthisfile'));
    }
}


/// OTHER STANDARD FUNCTIONS ////////////////////////////////////////////////////////

/**
 * Deletes an assignment instance
 *
 * This is done by calling the delete_instance() method of the assignment type class
 */
function assignment_delete_instance($id){
    global $CFG, $DB;

    if (! $assignment = $DB->get_record('assignment', array('id'=>$id))) {
        return false;
    }

    // fall back to base class if plugin missing
    $classfile = "$CFG->dirroot/mod/assignment/type/$assignment->assignmenttype/assignment.class.php";
    if (file_exists($classfile)) {
        require_once($classfile);
        $assignmentclass = "assignment_$assignment->assignmenttype";

    } else {
        debugging("Missing assignment plug-in: {$assignment->assignmenttype}. Using base class for deleting instead.");
        $assignmentclass = "assignment_base";
    }

    $ass = new $assignmentclass();
    return $ass->delete_instance($assignment);
}


/**
 * Updates an assignment instance
 *
 * This is done by calling the update_instance() method of the assignment type class
 */
function assignment_update_instance($assignment){
    global $CFG;

    $assignment->assignmenttype = clean_param($assignment->assignmenttype, PARAM_SAFEDIR);

    require_once("$CFG->dirroot/mod/assignment/type/$assignment->assignmenttype/assignment.class.php");
    $assignmentclass = "assignment_$assignment->assignmenttype";
    $ass = new $assignmentclass();
    return $ass->update_instance($assignment);
}


/**
 * Adds an assignment instance
 *
 * This is done by calling the add_instance() method of the assignment type class
 */
function assignment_add_instance($assignment) {
    global $CFG;

    $assignment->assignmenttype = clean_param($assignment->assignmenttype, PARAM_SAFEDIR);

    require_once("$CFG->dirroot/mod/assignment/type/$assignment->assignmenttype/assignment.class.php");
    $assignmentclass = "assignment_$assignment->assignmenttype";
    $ass = new $assignmentclass();
    return $ass->add_instance($assignment);
}


/**
 * Returns an outline of a user interaction with an assignment
 *
 * This is done by calling the user_outline() method of the assignment type class
 */
function assignment_user_outline($course, $user, $mod, $assignment) {
    global $CFG;

    require_once("$CFG->dirroot/mod/assignment/type/$assignment->assignmenttype/assignment.class.php");
    $assignmentclass = "assignment_$assignment->assignmenttype";
    $ass = new $assignmentclass($mod->id, $assignment, $mod, $course);
    return $ass->user_outline($user);
}

/**
 * Prints the complete info about a user's interaction with an assignment
 *
 * This is done by calling the user_complete() method of the assignment type class
 */
function assignment_user_complete($course, $user, $mod, $assignment) {
    global $CFG;

    require_once("$CFG->dirroot/mod/assignment/type/$assignment->assignmenttype/assignment.class.php");
    $assignmentclass = "assignment_$assignment->assignmenttype";
    $ass = new $assignmentclass($mod->id, $assignment, $mod, $course);
    return $ass->user_complete($user);
}

/**
 * Function to be run periodically according to the moodle cron
 *
 * Finds all assignment notifications that have yet to be mailed out, and mails them
 */
function assignment_cron () {
    global $CFG, $USER, $DB;

    /// first execute all crons in plugins
    if ($plugins = get_plugin_list('assignment')) {
        foreach ($plugins as $plugin=>$dir) {
            require_once("$dir/assignment.class.php");
            $assignmentclass = "assignment_$plugin";
            $ass = new $assignmentclass();
            $ass->cron();
        }
    }

    /// Notices older than 1 day will not be mailed.  This is to avoid the problem where
    /// cron has not been running for a long time, and then suddenly people are flooded
    /// with mail from the past few weeks or months

    $timenow   = time();
    $endtime   = $timenow - $CFG->maxeditingtime;
    $starttime = $endtime - 24 * 3600;   /// One day earlier

    if ($submissions = assignment_get_unmailed_submissions($starttime, $endtime)) {

        $realuser = clone($USER);

        foreach ($submissions as $key => $submission) {
            if (! $DB->set_field("assignment_submissions", "mailed", "1", array("id"=>$submission->id))) {
                echo "Could not update the mailed field for id $submission->id.  Not mailed.\n";
                unset($submissions[$key]);
            }
        }

        $timenow = time();

        foreach ($submissions as $submission) {

            echo "Processing assignment submission $submission->id\n";

            if (! $user = $DB->get_record("user", array("id"=>$submission->userid))) {
                echo "Could not find user $post->userid\n";
                continue;
            }

            if (! $course = $DB->get_record("course", array("id"=>$submission->course))) {
                echo "Could not find course $submission->course\n";
                continue;
            }

            /// Override the language and timezone of the "current" user, so that
            /// mail is customised for the receiver.
            cron_setup_user($user, $course);

            if (!has_capability('moodle/course:view', get_context_instance(CONTEXT_COURSE, $submission->course), $user->id)) {
                echo fullname($user)." not an active participant in " . format_string($course->shortname) . "\n";
                continue;
            }

            if (! $teacher = $DB->get_record("user", array("id"=>$submission->teacher))) {
                echo "Could not find teacher $submission->teacher\n";
                continue;
            }

            if (! $mod = get_coursemodule_from_instance("assignment", $submission->assignment, $course->id)) {
                echo "Could not find course module for assignment id $submission->assignment\n";
                continue;
            }

            if (! $mod->visible) {    /// Hold mail notification for hidden assignments until later
                continue;
            }

            $strassignments = get_string("modulenameplural", "assignment");
            $strassignment  = get_string("modulename", "assignment");

            $assignmentinfo = new object();
            $assignmentinfo->teacher = fullname($teacher);
            $assignmentinfo->assignment = format_string($submission->name,true);
            $assignmentinfo->url = "$CFG->wwwroot/mod/assignment/view.php?id=$mod->id";

            $postsubject = "$course->shortname: $strassignments: ".format_string($submission->name,true);
            $posttext  = "$course->shortname -> $strassignments -> ".format_string($submission->name,true)."\n";
            $posttext .= "---------------------------------------------------------------------\n";
            $posttext .= get_string("assignmentmail", "assignment", $assignmentinfo)."\n";
            $posttext .= "---------------------------------------------------------------------\n";

            if ($user->mailformat == 1) {  // HTML
                $posthtml = "<p><font face=\"sans-serif\">".
                "<a href=\"$CFG->wwwroot/course/view.php?id=$course->id\">$course->shortname</a> ->".
                "<a href=\"$CFG->wwwroot/mod/assignment/index.php?id=$course->id\">$strassignments</a> ->".
                "<a href=\"$CFG->wwwroot/mod/assignment/view.php?id=$mod->id\">".format_string($submission->name,true)."</a></font></p>";
                $posthtml .= "<hr /><font face=\"sans-serif\">";
                $posthtml .= "<p>".get_string("assignmentmailhtml", "assignment", $assignmentinfo)."</p>";
                $posthtml .= "</font><hr />";
            } else {
                $posthtml = "";
            }

            $eventdata = new object();
            $eventdata->modulename       = 'assignment';
            $eventdata->userfrom         = $teacher;
            $eventdata->userto           = $user;
            $eventdata->subject          = $postsubject;
            $eventdata->fullmessage      = $posttext;
            $eventdata->fullmessageformat = FORMAT_PLAIN;
            $eventdata->fullmessagehtml  = $posthtml;
            $eventdata->smallmessage     = '';
            if ( events_trigger('message_send', $eventdata) > 0 ){
                echo "Error: assignment cron: Could not send out mail for id $submission->id to user $user->id ($user->email)\n";
            }
        }

        cron_setup_user();
    }

    return true;
}

/**
 * Return grade for given user or all users.
 *
 * @param int $assignmentid id of assignment
 * @param int $userid optional user id, 0 means all users
 * @return array array of grades, false if none
 */
function assignment_get_user_grades($assignment, $userid=0) {
    global $CFG, $DB;

    if ($userid) {
        $user = "AND u.id = :userid";
        $params = array('userid'=>$userid);
    } else {
        $user = "";
    }
    $params['aid'] = $assignment->id;

    $sql = "SELECT u.id, u.id AS userid, s.grade AS rawgrade, s.submissioncomment AS feedback, s.format AS feedbackformat,
                   s.teacher AS usermodified, s.timemarked AS dategraded, s.timemodified AS datesubmitted
              FROM {user} u, {assignment_submissions} s
             WHERE u.id = s.userid AND s.assignment = :aid
                   $user";

    return $DB->get_records_sql($sql, $params);
}

/**
 * Update activity grades
 *
 * @param object $assignment
 * @param int $userid specific user only, 0 means all
 */
function assignment_update_grades($assignment, $userid=0, $nullifnone=true) {
    global $CFG, $DB;
    require_once($CFG->libdir.'/gradelib.php');

    if ($assignment->grade == 0) {
        assignment_grade_item_update($assignment);

    } else if ($grades = assignment_get_user_grades($assignment, $userid)) {
        foreach($grades as $k=>$v) {
            if ($v->rawgrade == -1) {
                $grades[$k]->rawgrade = null;
            }
        }
        assignment_grade_item_update($assignment, $grades);

    } else {
        assignment_grade_item_update($assignment);
    }
}

/**
 * Update all grades in gradebook.
 */
function assignment_upgrade_grades() {
    global $DB;

    $sql = "SELECT COUNT('x')
              FROM {assignment} a, {course_modules} cm, {modules} m
             WHERE m.name='assignment' AND m.id=cm.module AND cm.instance=a.id";
    $count = $DB->count_records_sql($sql);

    $sql = "SELECT a.*, cm.idnumber AS cmidnumber, a.course AS courseid
              FROM {assignment} a, {course_modules} cm, {modules} m
             WHERE m.name='assignment' AND m.id=cm.module AND cm.instance=a.id";
    if ($rs = $DB->get_recordset_sql($sql)) {
        // too much debug output
        $pbar = new progress_bar('assignmentupgradegrades', 500, true);
        $i=0;
        foreach ($rs as $assignment) {
            $i++;
            upgrade_set_timeout(60*5); // set up timeout, may also abort execution
            assignment_update_grades($assignment);
            $pbar->update($i, $count, "Updating Assignment grades ($i/$count).");
        }
        $rs->close();
        upgrade_set_timeout(); // reset to default timeout
    }
}

/**
 * Create grade item for given assignment
 *
 * @param object $assignment object with extra cmidnumber
 * @param mixed optional array/object of grade(s); 'reset' means reset grades in gradebook
 * @return int 0 if ok, error code otherwise
 */
function assignment_grade_item_update($assignment, $grades=NULL) {
    global $CFG;
    require_once($CFG->libdir.'/gradelib.php');

    if (!isset($assignment->courseid)) {
        $assignment->courseid = $assignment->course;
    }

    $params = array('itemname'=>$assignment->name, 'idnumber'=>$assignment->cmidnumber);

    if ($assignment->grade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax']  = $assignment->grade;
        $params['grademin']  = 0;

    } else if ($assignment->grade < 0) {
        $params['gradetype'] = GRADE_TYPE_SCALE;
        $params['scaleid']   = -$assignment->grade;

    } else {
        $params['gradetype'] = GRADE_TYPE_TEXT; // allow text comments only
    }

    if ($grades  === 'reset') {
        $params['reset'] = true;
        $grades = NULL;
    }

    return grade_update('mod/assignment', $assignment->courseid, 'mod', 'assignment', $assignment->id, 0, $grades, $params);
}

/**
 * Delete grade item for given assignment
 *
 * @param object $assignment object
 * @return object assignment
 */
function assignment_grade_item_delete($assignment) {
    global $CFG;
    require_once($CFG->libdir.'/gradelib.php');

    if (!isset($assignment->courseid)) {
        $assignment->courseid = $assignment->course;
    }

    return grade_update('mod/assignment', $assignment->courseid, 'mod', 'assignment', $assignment->id, 0, NULL, array('deleted'=>1));
}

/**
 * Returns the users with data in one assignment (students and teachers)
 *
 * @param $assignmentid int
 * @return array of user objects
 */
function assignment_get_participants($assignmentid) {
    global $CFG, $DB;

    //Get students
    $students = $DB->get_records_sql("SELECT DISTINCT u.id, u.id
                                        FROM {user} u,
                                             {assignment_submissions} a
                                       WHERE a.assignment = ? and
                                             u.id = a.userid", array($assignmentid));
    //Get teachers
    $teachers = $DB->get_records_sql("SELECT DISTINCT u.id, u.id
                                        FROM {user} u,
                                             {assignment_submissions} a
                                       WHERE a.assignment = ? and
                                             u.id = a.teacher", array($assignmentid));

    //Add teachers to students
    if ($teachers) {
        foreach ($teachers as $teacher) {
            $students[$teacher->id] = $teacher;
        }
    }
    //Return students array (it contains an array of unique users)
    return ($students);
}

/**
 * Serves assingment submissions and otehr files.
 *
 * @param object $course
 * @param object $cminfo
 * @param object $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @return bool false if file not found, does not return if found - justsend the file
 */
function assignment_pluginfile($course, $cminfo, $context, $filearea, $args, $forcedownload) {
    global $CFG, $DB;
    
    if (!$assignment = $DB->get_record('assignment', array('id'=>$cminfo->instance))) {
        return false;
    }
    if (!$cm = get_coursemodule_from_instance('assignment', $assignment->id, $course->id)) {
        return false;
    }

    require_login($course, false, $cm);

    require_once($CFG->dirroot.'/mod/assignment/type/'.$assignment->assignmenttype.'/assignment.class.php');
    $assignmentclass = 'assignment_'.$assignment->assignmenttype;
    $assignmentinstance = new $assignmentclass($cm->id, $assignment, $cm, $course);

    return $assignmentinstance->send_file($filearea, $args);
}
/**
 * Checks if a scale is being used by an assignment
 *
 * This is used by the backup code to decide whether to back up a scale
 * @param $assignmentid int
 * @param $scaleid int
 * @return boolean True if the scale is used by the assignment
 */
function assignment_scale_used($assignmentid, $scaleid) {
    global $DB;

    $return = false;

    $rec = $DB->get_record('assignment', array('id'=>$assignmentid,'grade'=>-$scaleid));

    if (!empty($rec) && !empty($scaleid)) {
        $return = true;
    }

    return $return;
}

/**
 * Checks if scale is being used by any instance of assignment
 *
 * This is used to find out if scale used anywhere
 * @param $scaleid int
 * @return boolean True if the scale is used by any assignment
 */
function assignment_scale_used_anywhere($scaleid) {
    global $DB;

    if ($scaleid and $DB->record_exists('assignment', array('grade'=>-$scaleid))) {
        return true;
    } else {
        return false;
    }
}

/**
 * Make sure up-to-date events are created for all assignment instances
 *
 * This standard function will check all instances of this module
 * and make sure there are up-to-date events created for each of them.
 * If courseid = 0, then every assignment event in the site is checked, else
 * only assignment events belonging to the course specified are checked.
 * This function is used, in its new format, by restore_refresh_events()
 *
 * @param $courseid int optional If zero then all assignments for all courses are covered
 * @return boolean Always returns true
 */
function assignment_refresh_events($courseid = 0) {
    global $DB;

    if ($courseid == 0) {
        if (! $assignments = $DB->get_records("assignment")) {
            return true;
        }
    } else {
        if (! $assignments = $DB->get_records("assignment", array("course"=>$courseid))) {
            return true;
        }
    }
    $moduleid = $DB->get_field('modules', 'id', array('name'=>'assignment'));

    foreach ($assignments as $assignment) {
        $cm = get_coursemodule_from_id('assignment', $assignment->id);
        $event = new object();
        $event->name        = $assignment->name;
        $event->description = format_module_intro('assignment', $assignment, $cm->id);
        $event->timestart   = $assignment->timedue;

        if ($event->id = $DB->get_field('event', 'id', array('modulename'=>'assignment', 'instance'=>$assignment->id))) {
            update_event($event);

        } else {
            $event->courseid    = $assignment->course;
            $event->groupid     = 0;
            $event->userid      = 0;
            $event->modulename  = 'assignment';
            $event->instance    = $assignment->id;
            $event->eventtype   = 'due';
            $event->timeduration = 0;
            $event->visible     = $DB->get_field('course_modules', 'visible', array('module'=>$moduleid, 'instance'=>$assignment->id));
            add_event($event);
        }

    }
    return true;
}

/**
 * Print recent activity from all assignments in a given course
 *
 * This is used by the recent activity block
 */
function assignment_print_recent_activity($course, $viewfullnames, $timestart) {
    global $CFG, $USER, $DB, $OUTPUT;

    // do not use log table if possible, it may be huge

    if (!$submissions = $DB->get_records_sql("SELECT asb.id, asb.timemodified, cm.id AS cmid, asb.userid,
                                                     u.firstname, u.lastname, u.email, u.picture
                                                FROM {assignment_submissions} asb
                                                     JOIN {assignment} a      ON a.id = asb.assignment
                                                     JOIN {course_modules} cm ON cm.instance = a.id
                                                     JOIN {modules} md        ON md.id = cm.module
                                                     JOIN {user} u            ON u.id = asb.userid
                                               WHERE asb.timemodified > ? AND
                                                     a.course = ? AND
                                                     md.name = 'assignment'
                                            ORDER BY asb.timemodified ASC", array($timestart, $course->id))) {
         return false;
    }

    $modinfo =& get_fast_modinfo($course); // reference needed because we might load the groups
    $show    = array();
    $grader  = array();

    foreach($submissions as $submission) {
        if (!array_key_exists($submission->cmid, $modinfo->cms)) {
            continue;
        }
        $cm = $modinfo->cms[$submission->cmid];
        if (!$cm->uservisible) {
            continue;
        }
        if ($submission->userid == $USER->id) {
            $show[] = $submission;
            continue;
        }

        // the act of sumbitting of assignment may be considered private - only graders will see it if specified
        if (empty($CFG->assignment_showrecentsubmissions)) {
            if (!array_key_exists($cm->id, $grader)) {
                $grader[$cm->id] = has_capability('moodle/grade:viewall', get_context_instance(CONTEXT_MODULE, $cm->id));
            }
            if (!$grader[$cm->id]) {
                continue;
            }
        }

        $groupmode = groups_get_activity_groupmode($cm, $course);

        if ($groupmode == SEPARATEGROUPS and !has_capability('moodle/site:accessallgroups', get_context_instance(CONTEXT_MODULE, $cm->id))) {
            if (isguestuser()) {
                // shortcut - guest user does not belong into any group
                continue;
            }

            if (is_null($modinfo->groups)) {
                $modinfo->groups = groups_get_user_groups($course->id); // load all my groups and cache it in modinfo
            }

            // this will be slow - show only users that share group with me in this cm
            if (empty($modinfo->groups[$cm->id])) {
                continue;
            }
            $usersgroups =  groups_get_all_groups($course->id, $cm->userid, $cm->groupingid);
            if (is_array($usersgroups)) {
                $usersgroups = array_keys($usersgroups);
                $interset = array_intersect($usersgroups, $modinfo->groups[$cm->id]);
                if (empty($intersect)) {
                    continue;
                }
            }
        }
        $show[] = $submission;
    }

    if (empty($show)) {
        return false;
    }

    echo $OUTPUT->heading(get_string('newsubmissions', 'assignment').':');

    foreach ($show as $submission) {
        $cm = $modinfo->cms[$submission->cmid];
        $link = $CFG->wwwroot.'/mod/assignment/view.php?id='.$cm->id;
        print_recent_activity_note($submission->timemodified, $submission, $cm->name, $link, false, $viewfullnames);
    }

    return true;
}


/**
 * Returns all assignments since a given time in specified forum.
 */
function assignment_get_recent_mod_activity(&$activities, &$index, $timestart, $courseid, $cmid, $userid=0, $groupid=0)  {
    global $CFG, $COURSE, $USER, $DB;

    if ($COURSE->id == $courseid) {
        $course = $COURSE;
    } else {
        $course = $DB->get_record('course', array('id'=>$courseid));
    }

    $modinfo =& get_fast_modinfo($course);

    $cm = $modinfo->cms[$cmid];

    $params = array();
    if ($userid) {
        $userselect = "AND u.id = :userid";
        $params['userid'] = $userid;
    } else {
        $userselect = "";
    }

    if ($groupid) {
        $groupselect = "AND gm.groupid = :groupid";
        $groupjoin   = "JOIN {groups_members} gm ON  gm.userid=u.id";
        $params['groupid'] = $groupid;
    } else {
        $groupselect = "";
        $groupjoin   = "";
    }

    $params['cminstance'] = $cm->instance;
    $params['timestart'] = $timestart;

    if (!$submissions = $DB->get_records_sql("SELECT asb.id, asb.timemodified, asb.userid,
                                                     u.firstname, u.lastname, u.email, u.picture
                                                FROM {assignment_submissions} asb
                                                JOIN {assignment} a      ON a.id = asb.assignment
                                                JOIN {user} u            ON u.id = asb.userid
                                          $groupjoin
                                               WHERE asb.timemodified > :timestart AND a.id = :cminstance
                                                     $userselect $groupselect
                                            ORDER BY asb.timemodified ASC", $params)) {
         return;
    }

    $groupmode       = groups_get_activity_groupmode($cm, $course);
    $cm_context      = get_context_instance(CONTEXT_MODULE, $cm->id);
    $grader          = has_capability('moodle/grade:viewall', $cm_context);
    $accessallgroups = has_capability('moodle/site:accessallgroups', $cm_context);
    $viewfullnames   = has_capability('moodle/site:viewfullnames', $cm_context);

    if (is_null($modinfo->groups)) {
        $modinfo->groups = groups_get_user_groups($course->id); // load all my groups and cache it in modinfo
    }

    $show = array();

    foreach($submissions as $submission) {
        if ($submission->userid == $USER->id) {
            $show[] = $submission;
            continue;
        }
        // the act of submitting of assignment may be considered private - only graders will see it if specified
        if (empty($CFG->assignment_showrecentsubmissions)) {
            if (!$grader) {
                continue;
            }
        }

        if ($groupmode == SEPARATEGROUPS and !$accessallgroups) {
            if (isguestuser()) {
                // shortcut - guest user does not belong into any group
                continue;
            }

            // this will be slow - show only users that share group with me in this cm
            if (empty($modinfo->groups[$cm->id])) {
                continue;
            }
            $usersgroups = groups_get_all_groups($course->id, $cm->userid, $cm->groupingid);
            if (is_array($usersgroups)) {
                $usersgroups = array_keys($usersgroups);
                $interset = array_intersect($usersgroups, $modinfo->groups[$cm->id]);
                if (empty($intersect)) {
                    continue;
                }
            }
        }
        $show[] = $submission;
    }

    if (empty($show)) {
        return;
    }

    if ($grader) {
        require_once($CFG->libdir.'/gradelib.php');
        $userids = array();
        foreach ($show as $id=>$submission) {
            $userids[] = $submission->userid;

        }
        $grades = grade_get_grades($courseid, 'mod', 'assignment', $cm->instance, $userids);
    }

    $aname = format_string($cm->name,true);
    foreach ($show as $submission) {
        $tmpactivity = new object();

        $tmpactivity->type         = 'assignment';
        $tmpactivity->cmid         = $cm->id;
        $tmpactivity->name         = $aname;
        $tmpactivity->sectionnum   = $cm->sectionnum;
        $tmpactivity->timestamp    = $submission->timemodified;

        if ($grader) {
            $tmpactivity->grade = $grades->items[0]->grades[$submission->userid]->str_long_grade;
        }

        $tmpactivity->user->userid   = $submission->userid;
        $tmpactivity->user->fullname = fullname($submission, $viewfullnames);
        $tmpactivity->user->picture  = $submission->picture;

        $activities[$index++] = $tmpactivity;
    }

    return;
}

/**
 * Print recent activity from all assignments in a given course
 *
 * This is used by course/recent.php
 */
function assignment_print_recent_mod_activity($activity, $courseid, $detail, $modnames)  {
    global $CFG, $OUTPUT;

    echo '<table border="0" cellpadding="3" cellspacing="0" class="assignment-recent">';

    echo "<tr><td class=\"userpicture\" valign=\"top\">";
    print_user_picture($activity->user->userid, $courseid, $activity->user->picture);
    echo "</td><td>";

    if ($detail) {
        $modname = $modnames[$activity->type];
        echo '<div class="title">';
        echo "<img src=\"" . $OUTPUT->mod_icon_url('icon', 'assignment') . "\" ".
             "class=\"icon\" alt=\"$modname\">";
        echo "<a href=\"$CFG->wwwroot/mod/assignment/view.php?id={$activity->cmid}\">{$activity->name}</a>";
        echo '</div>';
    }

    if (isset($activity->grade)) {
        echo '<div class="grade">';
        echo get_string('grade').': ';
        echo $activity->grade;
        echo '</div>';
    }

    echo '<div class="user">';
    echo "<a href=\"$CFG->wwwroot/user/view.php?id={$activity->user->userid}&amp;course=$courseid\">"
         ."{$activity->user->fullname}</a>  - ".userdate($activity->timestamp);
    echo '</div>';

    echo "</td></tr></table>";
}

/// GENERIC SQL FUNCTIONS

/**
 * Fetch info from logs
 *
 * @param $log object with properties ->info (the assignment id) and ->userid
 * @return array with assignment name and user firstname and lastname
 */
function assignment_log_info($log) {
    global $CFG, $DB;

    return $DB->get_record_sql("SELECT a.name, u.firstname, u.lastname
                                  FROM {assignment} a, {user} u
                                 WHERE a.id = ? AND u.id = ?", array($log->info, $log->userid));
}

/**
 * Return list of marked submissions that have not been mailed out for currently enrolled students
 *
 * @return array
 */
function assignment_get_unmailed_submissions($starttime, $endtime) {
    global $CFG, $DB;

    return $DB->get_records_sql("SELECT s.*, a.course, a.name
                                   FROM {assignment_submissions} s,
                                        {assignment} a
                                  WHERE s.mailed = 0
                                        AND s.timemarked <= ?
                                        AND s.timemarked >= ?
                                        AND s.assignment = a.id", array($endtime, $starttime));
}

/**
 * Counts all real assignment submissions by ENROLLED students (not empty ones)
 *
 * There are also assignment type methods count_real_submissions() wich in the default
 * implementation simply call this function.
 * @param $groupid int optional If nonzero then count is restricted to this group
 * @return int The number of submissions
 */
function assignment_count_real_submissions($cm, $groupid=0) {
    global $CFG, $DB;

    $context = get_context_instance(CONTEXT_MODULE, $cm->id);

    // this is all the users with this capability set, in this context or higher
    if ($users = get_users_by_capability($context, 'mod/assignment:submit', 'u.id', '', '', '', $groupid, '', false)) {
        $users = array_keys($users);
    }

    // if groupmembersonly used, remove users who are not in any group
    if ($users and !empty($CFG->enablegroupings) and $cm->groupmembersonly) {
        if ($groupingusers = groups_get_grouping_members($cm->groupingid, 'u.id', 'u.id')) {
            $users = array_intersect($users, array_keys($groupingusers));
        }
    }

    if (empty($users)) {
        return 0;
    }

    $userlists = implode(',', $users);

    return $DB->count_records_sql("SELECT COUNT('x')
                                     FROM {assignment_submissions}
                                    WHERE assignment = ? AND
                                          timemodified > 0 AND
                                          userid IN ($userlists)", array($cm->instance));
}


/**
 * Return all assignment submissions by ENROLLED students (even empty)
 *
 * There are also assignment type methods get_submissions() wich in the default
 * implementation simply call this function.
 * @param $sort string optional field names for the ORDER BY in the sql query
 * @param $dir string optional specifying the sort direction, defaults to DESC
 * @return array The submission objects indexed by id
 */
function assignment_get_all_submissions($assignment, $sort="", $dir="DESC") {
/// Return all assignment submissions by ENROLLED students (even empty)
    global $CFG, $DB;

    if ($sort == "lastname" or $sort == "firstname") {
        $sort = "u.$sort $dir";
    } else if (empty($sort)) {
        $sort = "a.timemodified DESC";
    } else {
        $sort = "a.$sort $dir";
    }

    /* not sure this is needed at all since assignmenet already has a course define, so this join?
    $select = "s.course = '$assignment->course' AND";
    if ($assignment->course == SITEID) {
        $select = '';
    }*/

    return $DB->get_records_sql("SELECT a.*
                                   FROM {assignment_submissions} a, {user} u
                                  WHERE u.id = a.userid
                                        AND a.assignment = ?
                               ORDER BY $sort", array($assignment->id));

}

/**
 * Add a get_coursemodule_info function in case any assignment type wants to add 'extra' information
 * for the course (see resource).
 *
 * Given a course_module object, this function returns any "extra" information that may be needed
 * when printing this activity in a course listing.  See get_array_of_activities() in course/lib.php.
 *
 * @param $coursemodule object The coursemodule object (record).
 * @return object An object on information that the coures will know about (most noticeably, an icon).
 *
 */
function assignment_get_coursemodule_info($coursemodule) {
    global $CFG, $DB;

    if (! $assignment = $DB->get_record('assignment', array('id'=>$coursemodule->instance), 'id, assignmenttype, name')) {
        return false;
    }

    $libfile = "$CFG->dirroot/mod/assignment/type/$assignment->assignmenttype/assignment.class.php";

    if (file_exists($libfile)) {
        require_once($libfile);
        $assignmentclass = "assignment_$assignment->assignmenttype";
        $ass = new $assignmentclass('staticonly');
        if ($result = $ass->get_coursemodule_info($coursemodule)) {
            return $result;
        } else {
            $info = new object();
            $info->name = $assignment->name;
            return $info;
        }

    } else {
        debugging('Incorrect assignment type: '.$assignment->assignmenttype);
        return false;
    }
}



/// OTHER GENERAL FUNCTIONS FOR ASSIGNMENTS  ///////////////////////////////////////

/**
 * Returns an array of installed assignment types indexed and sorted by name
 *
 * @return array The index is the name of the assignment type, the value its full name from the language strings
 */
function assignment_types() {
    $types = array();
    $names = get_plugin_list('assignment');
    foreach ($names as $name=>$dir) {
        $types[$name] = get_string('type'.$name, 'assignment');
    }
    asort($types);
    return $types;
}

function assignment_print_overview($courses, &$htmlarray) {
    global $USER, $CFG, $DB;

    if (empty($courses) || !is_array($courses) || count($courses) == 0) {
        return array();
    }

    if (!$assignments = get_all_instances_in_courses('assignment',$courses)) {
        return;
    }

    $assignmentids = array();

    // Do assignment_base::isopen() here without loading the whole thing for speed
    foreach ($assignments as $key => $assignment) {
        $time = time();
        if ($assignment->timedue) {
            if ($assignment->preventlate) {
                $isopen = ($assignment->timeavailable <= $time && $time <= $assignment->timedue);
            } else {
                $isopen = ($assignment->timeavailable <= $time);
            }
        }
        if (empty($isopen) || empty($assignment->timedue)) {
            unset($assignments[$key]);
        }else{
            $assignmentids[] = $assignment->id;
        }
    }

    if(empty($assignmentids)){
        // no assigments to look at - we're done
        return true;
    }

    $strduedate = get_string('duedate', 'assignment');
    $strduedateno = get_string('duedateno', 'assignment');
    $strgraded = get_string('graded', 'assignment');
    $strnotgradedyet = get_string('notgradedyet', 'assignment');
    $strnotsubmittedyet = get_string('notsubmittedyet', 'assignment');
    $strsubmitted = get_string('submitted', 'assignment');
    $strassignment = get_string('modulename', 'assignment');
    $strreviewed = get_string('reviewed','assignment');


    // NOTE: we do all possible database work here *outside* of the loop to ensure this scales 
    //
    list($sqlassignmentids, $assignmentidparams) = $DB->get_in_or_equal($assignmentids);
    
    // build up and array of unmarked submissions indexed by assigment id/ userid
    // for use where the user has grading rights on assigment
    $rs = $DB->get_recordset_sql("SELECT id, assignment, userid 
                            FROM {assignment_submissions}
                            WHERE teacher = 0 AND timemarked = 0
                            AND assignment $sqlassignmentids", $assignmentidparams);

    $unmarkedsubmissions = array();
    foreach ($rs as $rd) {
        $unmarkedsubmissions[$rd->assignment][$rd->userid] = $rd->id;
    }
    $rs->close();


    // get all user submissions, indexed by assigment id
    $mysubmissions = $DB->get_records_sql("SELECT assignment, timemarked, teacher, grade
                                      FROM {assignment_submissions}
                                      WHERE userid = ? AND 
                                      assignment $sqlassignmentids", array_merge(array($USER->id), $assignmentidparams));

    foreach ($assignments as $assignment) {
        $str = '<div class="assignment overview"><div class="name">'.$strassignment. ': '.
               '<a '.($assignment->visible ? '':' class="dimmed"').
               'title="'.$strassignment.'" href="'.$CFG->wwwroot.
               '/mod/assignment/view.php?id='.$assignment->coursemodule.'">'.
               $assignment->name.'</a></div>';
        if ($assignment->timedue) {
            $str .= '<div class="info">'.$strduedate.': '.userdate($assignment->timedue).'</div>';
        } else {
            $str .= '<div class="info">'.$strduedateno.'</div>';
        }
        $context = get_context_instance(CONTEXT_MODULE, $assignment->coursemodule);
        if (has_capability('mod/assignment:grade', $context)) {

            // count how many people can submit
            $submissions = 0; // init
            if ($students = get_users_by_capability($context, 'mod/assignment:submit', 'u.id', '', '', '', 0, '', false)) {
                foreach($students as $student){
                    if(isset($unmarkedsubmissions[$assignment->id][$student->id])){
                        $submissions++;
                    }
                }
            }

            if ($submissions) {
                $str .= get_string('submissionsnotgraded', 'assignment', $submissions);
            }
        } else {
            if(isset($mysubmissions[$assignment->id])){

                $submission = $mysubmissions[$assignment->id];

                if ($submission->teacher == 0 && $submission->timemarked == 0) {
                    $str .= $strsubmitted . ', ' . $strnotgradedyet;
                } else if ($submission->grade <= 0) {
                    $str .= $strsubmitted . ', ' . $strreviewed;
                } else {
                    $str .= $strsubmitted . ', ' . $strgraded;
                }
            } else {
                $str .= $strnotsubmittedyet . ' ' . assignment_display_lateness(time(), $assignment->timedue);
            }
        }
        $str .= '</div>';
        if (empty($htmlarray[$assignment->course]['assignment'])) {
            $htmlarray[$assignment->course]['assignment'] = $str;
        } else {
            $htmlarray[$assignment->course]['assignment'] .= $str;
        }
    }
}

function assignment_display_lateness($timesubmitted, $timedue) {
    if (!$timedue) {
        return '';
    }
    $time = $timedue - $timesubmitted;
    if ($time < 0) {
        $timetext = get_string('late', 'assignment', format_time($time));
        return ' (<span class="late">'.$timetext.'</span>)';
    } else {
        $timetext = get_string('early', 'assignment', format_time($time));
        return ' (<span class="early">'.$timetext.'</span>)';
    }
}

function assignment_get_view_actions() {
    return array('view');
}

function assignment_get_post_actions() {
    return array('upload');
}

function assignment_get_types() {
    global $CFG;
    $types = array();

    $type = new object();
    $type->modclass = MOD_CLASS_ACTIVITY;
    $type->type = "assignment_group_start";
    $type->typestr = '--'.get_string('modulenameplural', 'assignment');
    $types[] = $type;

    $standardassignments = array('upload','online','uploadsingle','offline');
    foreach ($standardassignments as $assignmenttype) {
        $type = new object();
        $type->modclass = MOD_CLASS_ACTIVITY;
        $type->type = "assignment&amp;type=$assignmenttype";
        $type->typestr = get_string("type$assignmenttype", 'assignment');
        $types[] = $type;
    }

    /// Drop-in extra assignment types
    $assignmenttypes = get_list_of_plugins('mod/assignment/type');
    foreach ($assignmenttypes as $assignmenttype) {
        if (!empty($CFG->{'assignment_hide_'.$assignmenttype})) {  // Not wanted
            continue;
        }
        if (!in_array($assignmenttype, $standardassignments)) {
            $type = new object();
            $type->modclass = MOD_CLASS_ACTIVITY;
            $type->type = "assignment&amp;type=$assignmenttype";
            $type->typestr = get_string("type$assignmenttype", 'assignment');
            $types[] = $type;
        }
    }

    $type = new object();
    $type->modclass = MOD_CLASS_ACTIVITY;
    $type->type = "assignment_group_end";
    $type->typestr = '--';
    $types[] = $type;

    return $types;
}

/**
 * Removes all grades from gradebook
 * @param int $courseid
 * @param string optional type
 */
function assignment_reset_gradebook($courseid, $type='') {
    global $CFG, $DB;

    $params = array('courseid'=>$courseid);
    if ($type) {
        $type = "AND a.assignmenttype= :type";
        $params['type'] = $type;
    }

    $sql = "SELECT a.*, cm.idnumber as cmidnumber, a.course as courseid
              FROM {assignment} a, {course_modules} cm, {modules} m
             WHERE m.name='assignment' AND m.id=cm.module AND cm.instance=a.id AND a.course=:courseid $type";

    if ($assignments = $DB->get_records_sql($sql, $params)) {
        foreach ($assignments as $assignment) {
            assignment_grade_item_update($assignment, 'reset');
        }
    }
}

/**
 * This function is used by the reset_course_userdata function in moodlelib.
 * This function will remove all posts from the specified assignment
 * and clean up any related data.
 * @param $data the data submitted from the reset course.
 * @return array status array
 */
function assignment_reset_userdata($data) {
    global $CFG;

    $status = array();

    foreach (get_plugin_list('mod/assignment/type') as $type=>$dir) {
        require_once("$dir/assignment.class.php");
        $assignmentclass = "assignment_$type";
        $ass = new $assignmentclass();
        $status = array_merge($status, $ass->reset_userdata($data));
    }

    return $status;
}

/**
 * Implementation of the function for printing the form elements that control
 * whether the course reset functionality affects the assignment.
 * @param $mform form passed by reference
 */
function assignment_reset_course_form_definition(&$mform) {
    $mform->addElement('header', 'assignmentheader', get_string('modulenameplural', 'assignment'));
    $mform->addElement('advcheckbox', 'reset_assignment_submissions', get_string('deleteallsubmissions','assignment'));
}

/**
 * Course reset form defaults.
 */
function assignment_reset_course_form_defaults($course) {
    return array('reset_assignment_submissions'=>1);
}

/**
 * Returns all other caps used in module
 */
function assignment_get_extra_capabilities() {
    return array('moodle/site:accessallgroups', 'moodle/site:viewfullnames');
}

/**
 * @package   mod-assignment
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignment_portfolio_caller extends portfolio_module_caller_base {

    /**
    * the assignment subclass
    */
    private $assignment;

    /**
    * the file to include when waking up to load the assignment subclass def
    */
    private $assignmentfile;

    /**
    * callback arg for a single file export
    */
    protected $fileid;

    public static function expected_callbackargs() {
        return array(
            'id'     => true,
            'fileid' => false,
        );
    }

    public function load_data() {
        global $DB, $CFG;

        if (! $this->cm = get_coursemodule_from_id('assignment', $this->id)) {
            throw new portfolio_caller_exception('invalidcoursemodule');
        }

        if (! $assignment = $DB->get_record("assignment", array("id"=>$this->cm->instance))) {
            throw new portfolio_caller_exception('invalidid', 'assignment');
        }

        $this->assignmentfile = $CFG->dirroot . '/mod/assignment/type/' . $assignment->assignmenttype . '/assignment.class.php';
        require_once($this->assignmentfile);
        $assignmentclass = "assignment_$assignment->assignmenttype";

        $this->assignment = new $assignmentclass($this->cm->id, $assignment, $this->cm);

        if (!$this->assignment->portfolio_exportable()) {
            throw new portfolio_caller_exception('notexportable', 'portfolio', $this->get_return_url());
        }

        $this->set_file_and_format_data($this->fileid, $this->assignment->context->id, 'assignment_submission', $this->user->id);
        if (empty($this->supportedformats) && is_callable(array($this->assignment, 'portfolio_supported_formats'))) {
            $this->supportedformats = $this->assignment->portfolio_supported_formats();
        }
    }

    public function prepare_package() {
        global $CFG;
        if (is_callable(array($this->assignment, 'portfolio_prepare_package'))) {
            return $this->assignment->portfolio_prepare_package($this->exporter, $this->user->id);
        }
        return $this->prepare_package_file();
    }

    public function get_sha1() {
        global $CFG;
        if (is_callable(array($this->assignment, 'portfolio_get_sha1'))) {
            return $this->assignment->portfolio_get_sha1($this->user->id);
        }
        return $this->get_sha1_file();
    }

    public function expected_time() {
        if (is_callable(array($this->assignment, 'portfolio_get_expected_time'))) {
            return $this->assignment->portfolio_get_expected_time();
        }
        return $this->expected_time_file();
    }

    public function check_permissions() {
        $context = get_context_instance(CONTEXT_MODULE, $this->assignment->cm->id);
        return has_capability('mod/assignment:exportownsubmission', $context);
    }

    public function __wakeup() {
        global $CFG;
        if (empty($CFG)) {
            return true; // too early yet
        }
        require_once($this->assignmentfile);
        $this->assignment = unserialize(serialize($this->assignment));
    }

    public static function display_name() {
        return get_string('modulename', 'assignment');
    }
}

/**
 * @param string $feature FEATURE_xx constant for requested feature
 * @return mixed True if module supports feature, null if doesn't know
 */
function assignment_supports($feature) {
    switch($feature) {
        case FEATURE_GROUPS:                  return true;
        case FEATURE_GROUPINGS:               return true;
        case FEATURE_GROUPMEMBERSONLY:        return true;
        case FEATURE_MOD_INTRO:               return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS: return true;
        case FEATURE_GRADE_HAS_GRADE:         return true;
        case FEATURE_GRADE_OUTCOMES:          return true;
        case FEATURE_MOD_SUBPLUGINS:          return array('assignment'=>'mod/assignment/type'); // to be hopefully removed in 2.0

        default: return null;
    }
}
