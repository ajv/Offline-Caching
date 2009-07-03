<?php // $Id$
require_once($CFG->libdir . '/portfoliolib.php');
require_once($CFG->dirroot . '/mod/assignment/lib.php');

define('ASSIGNMENT_STATUS_SUBMITTED', 'submitted'); // student thinks it is finished
define('ASSIGNMENT_STATUS_CLOSED', 'closed');       // teacher prevents more submissions

/**
 * Extend the base assignment class for assignments where you upload a single file
 *
 */
class assignment_upload extends assignment_base {

    function assignment_upload($cmid='staticonly', $assignment=NULL, $cm=NULL, $course=NULL) {
        parent::assignment_base($cmid, $assignment, $cm, $course);
        $this->type = 'upload';
    }

    function view() {
        global $USER;

        require_capability('mod/assignment:view', $this->context);

        add_to_log($this->course->id, 'assignment', 'view', "view.php?id={$this->cm->id}", $this->assignment->id, $this->cm->id);

        $this->view_header();

        if ($this->assignment->timeavailable > time()
          and !has_capability('mod/assignment:grade', $this->context)      // grading user can see it anytime
          and $this->assignment->var3) {                                   // force hiding before available date
            print_simple_box_start('center', '', '', 0, 'generalbox', 'intro');
            print_string('notavailableyet', 'assignment');
            print_simple_box_end();
        } else {
            $this->view_intro();
        }

        $this->view_dates();

        if (has_capability('mod/assignment:submit', $this->context)) {
            $filecount = $this->count_user_files($USER->id);
            $submission = $this->get_submission($USER->id);

            $this->view_feedback();

            if (!$this->drafts_tracked() or !$this->isopen() or $this->is_finalized($submission)) {
                print_heading(get_string('submission', 'assignment'), '', 3);
            } else {
                print_heading(get_string('submissiondraft', 'assignment'), '', 3);
            }

            if ($filecount and $submission) {
                print_simple_box($this->print_user_files($USER->id, true), 'center');
            } else {
                if (!$this->isopen() or $this->is_finalized($submission)) {
                    print_simple_box(get_string('nofiles', 'assignment'), 'center');
                } else {
                    print_simple_box(get_string('nofilesyet', 'assignment'), 'center');
                }
            }

            $this->view_upload_form();

            if ($this->notes_allowed()) {
                print_heading(get_string('notes', 'assignment'), '', 3);
                $this->view_notes();
            }

            $this->view_final_submission();
        }
        $this->view_footer();
    }


    function view_feedback($submission=NULL) {
        global $USER, $CFG, $DB;
        require_once($CFG->libdir.'/gradelib.php');

        if (!$submission) { /// Get submission for this assignment
            $submission = $this->get_submission($USER->id);
        }

        if (empty($submission->timemarked)) {   /// Nothing to show, so print nothing
            if ($this->count_responsefiles($USER->id)) {
                print_heading(get_string('responsefiles', 'assignment'), '', 3);
                $responsefiles = $this->print_responsefiles($USER->id, true);
                print_simple_box($responsefiles, 'center');
            }
            return;
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
        print_heading(get_string('submissionfeedback', 'assignment'), '', 3);

        echo '<table cellspacing="0" class="feedback">';

        echo '<tr>';
        echo '<td class="left picture">';
        print_user_picture($teacher, $this->course->id, $teacher->picture);
        echo '</td>';
        echo '<td class="topic">';
        echo '<div class="from">';
        echo '<div class="fullname">'.fullname($teacher).'</div>';
        echo '<div class="time">'.userdate($graded_date).'</div>';
        echo '</div>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<td class="left side">&nbsp;</td>';
        echo '<td class="content">';
        if ($this->assignment->grade) {
            echo '<div class="grade">';
            echo get_string("grade").': '.$grade->str_long_grade;
            echo '</div>';
            echo '<div class="clearer"></div>';
        }

        echo '<div class="comment">';
        echo $grade->str_feedback;
        echo '</div>';
        echo '</tr>';

        echo '<tr>';
        echo '<td class="left side">&nbsp;</td>';
        echo '<td class="content">';
        echo $this->print_responsefiles($USER->id, true);
        echo '</tr>';

        echo '</table>';
    }


    function view_upload_form() {
        global $CFG, $USER;

        $submission = $this->get_submission($USER->id);

        if ($this->is_finalized($submission)) {
            // no uploading
            return;
        }

        if ($this->can_upload_file($submission)) {
            $mform = new mod_assignment_upload_file_form('upload.php', $this);
            $mform->display();
        }

    }

    function view_notes() {
        global $USER;

        if ($submission = $this->get_submission($USER->id)
          and !empty($submission->data1)) {
            print_simple_box(format_text($submission->data1, FORMAT_HTML), 'center', '630px');
        } else {
            print_simple_box(get_string('notesempty', 'assignment'), 'center');
        }
        if ($this->can_update_notes($submission)) {
            $options = array ('id'=>$this->cm->id, 'action'=>'editnotes');
            echo '<div style="text-align:center">';
            print_single_button('upload.php', $options, get_string('edit'), 'post', '_self', false);
            echo '</div>';
        }
    }

    function view_final_submission() {
        global $CFG, $USER;

        $submission = $this->get_submission($USER->id);

        if ($this->isopen() and $this->can_finalize($submission)) {
            //print final submit button
            print_heading(get_string('submitformarking','assignment'), '', 3);
            echo '<div style="text-align:center">';
            echo '<form method="post" action="upload.php">';
            echo '<fieldset class="invisiblefieldset">';
            echo '<input type="hidden" name="id" value="'.$this->cm->id.'" />';
            echo '<input type="hidden" name="action" value="finalize" />';
            echo '<input type="submit" name="formarking" value="'.get_string('sendformarking', 'assignment').'" />';
            echo '</fieldset>';
            echo '</form>';
            echo '</div>';
        } else if (!$this->isopen()) {
            print_heading(get_string('nomoresubmissions','assignment'), '', 3);

        } else if ($this->drafts_tracked() and $state = $this->is_finalized($submission)) {
            if ($state == ASSIGNMENT_STATUS_SUBMITTED) {
                print_heading(get_string('submitedformarking','assignment'), '', 3);
            } else {
                print_heading(get_string('nomoresubmissions','assignment'), '', 3);
            }
        } else {
            //no submission yet
        }
    }


    /**
     * Return true if var3 == hide description till available day
     *
     *@return boolean
     */
    function description_is_hidden() {
        return ($this->assignment->var3 && (time() <= $this->assignment->timeavailable));
    }

    function custom_feedbackform($submission, $return=false) {
        global $CFG;

        $mode         = optional_param('mode', '', PARAM_ALPHA);
        $offset       = optional_param('offset', 0, PARAM_INT);
        $forcerefresh = optional_param('forcerefresh', 0, PARAM_BOOL);

        $mform = new mod_assignment_upload_response_form("$CFG->wwwroot/mod/assignment/upload.php", $this);

        $mform->set_data(array('id'=>$this->cm->id, 'offset'=>$offset, 'forcerefresh'=>$forcerefresh, 'userid'=>$submission->userid, 'mode'=>$mode));

        $output = get_string('responsefiles', 'assignment').': ';

        ob_start();
        $mform->display();
        $output = ob_get_clean();

        if ($forcerefresh) {
            $output .= $this->update_main_listing($submission);
        }

        $responsefiles = $this->print_responsefiles($submission->userid, true);
        if (!empty($responsefiles)) {
            $output .= $responsefiles;
        }

        if ($return) {
            return $output;
        }
        echo $output;
        return;
    }


    function print_student_answer($userid, $return=false){
        global $CFG, $OUTPUT;

        $submission = $this->get_submission($userid);

        $output = '';

        if ($this->drafts_tracked() and $this->isopen() and !$this->is_finalized($submission)) {
            $output .= '<strong>'.get_string('draft', 'assignment').':</strong> ';
        }

        if ($this->notes_allowed() and !empty($submission->data1)) {
            $output .= link_to_popup_window ('/mod/assignment/type/upload/notes.php?id='.$this->cm->id.'&amp;userid='.$userid,
                                            'notes'.$userid, get_string('notes', 'assignment'), 500, 780, get_string('notes', 'assignment'), 'none', true, 'notesbutton'.$userid);
            $output .= '&nbsp;';
        }

        $fs = get_file_storage();
        $browser = get_file_browser();

        if ($files = $fs->get_area_files($this->context->id, 'assignment_submission', $userid, "timemodified", false)) {

            foreach ($files as $file) {
                $filename = $file->get_filename();
                $found = true;
                $mimetype = $file->get_mimetype();
                $icon = str_replace(array('.gif', '.png'), '', mimeinfo_from_type('icon', $mimetype));
                $path = file_encode_url($CFG->wwwroot.'/pluginfile.php', '/'.$this->context->id.'/assignment_submission/'.$userid.'/'.$filename);
                $output .= '<a href="'.$path.'" ><img class="icon" src="'.$OUTPUT->old_icon_url('f/'.$icon).'" alt="'.$icon.'" />'.s($filename).'</a>&nbsp;';

            }

        }
        $output = '<div class="files">'.$output.'</div>';
        $output .= '<br />';

        return $output;
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

        $mode    = optional_param('mode', '', PARAM_ALPHA);
        $offset  = optional_param('offset', 0, PARAM_INT);

        if (!$userid) {
            if (!isloggedin()) {
                return '';
            }
            $userid = $USER->id;
        }

        $output = '';

        $submission = $this->get_submission($userid);

        $candelete = $this->can_delete_files($submission);
        $strdelete   = get_string('delete');

        if ($this->drafts_tracked() and $this->isopen() and !$this->is_finalized($submission) and !empty($mode)) {                 // only during grading
            $output .= '<strong>'.get_string('draft', 'assignment').':</strong><br />';
        }

        if ($this->notes_allowed() and !empty($submission->data1) and !empty($mode)) { // only during grading

            $npurl = $CFG->wwwroot."/mod/assignment/type/upload/notes.php?id={$this->cm->id}&amp;userid=$userid&amp;offset=$offset&amp;mode=single";
            $output .= '<a href="'.$npurl.'">'.get_string('notes', 'assignment').'</a><br />';

        }

        $fs = get_file_storage();
        $browser = get_file_browser();

        if ($files = $fs->get_area_files($this->context->id, 'assignment_submission', $userid, "timemodified", false)) {
            $button = new portfolio_add_button();
            foreach ($files as $file) {
                $filename = $file->get_filename();
                $mimetype = $file->get_mimetype();
                $icon = str_replace(array('.gif', '.png'), '', mimeinfo_from_type('icon', $mimetype));
                $path = file_encode_url($CFG->wwwroot.'/pluginfile.php', '/'.$this->context->id.'/assignment_submission/'.$userid.'/'.$filename);
                $output .= '<a href="'.$path.'" ><img src="'.$OUTPUT->old_icon_url('f/'.$icon).'" class="icon" alt="'.$icon.'" />'.s($filename).'</a>';

                if ($candelete) {
                    $delurl  = "$CFG->wwwroot/mod/assignment/delete.php?id={$this->cm->id}&amp;file=".rawurlencode($filename)."&amp;userid={$submission->userid}&amp;mode=$mode&amp;offset=$offset";

                    $output .= '<a href="'.$delurl.'">&nbsp;'
                              .'<img title="'.$strdelete.'" src="'.$OUTPUT->old_icon_url('t/delete') . '" class="iconsmall" alt="" /></a> ';
                }

                if (has_capability('mod/assignment:exportownsubmission', $this->context)) {
                    $button->set_callback_options('assignment_portfolio_caller', array('id' => $this->cm->id, 'fileid' => $file->get_id()), '/mod/assignment/lib.php');
                    $button->set_formats(portfolio_format_from_file($file));
                    $output .= $button->to_html(PORTFOLIO_ADD_ICON_LINK);
                }
                $output .= '<br />';
            }
            if (count($files) > 1 && has_capability('mod/assignment:exportownsubmission', $this->context)) {
                $button->set_callback_options('assignment_portfolio_caller', array('id' => $this->cm->id), '/mod/assignment/lib.php');
                $button->set_formats(PORTFOLIO_FORMAT_FILE);
                $output .= $button->to_html();
            }
        }

        if ($this->drafts_tracked() and $this->isopen() and has_capability('mod/assignment:grade', $this->context) and $mode != '') { // we do not want it on view.php page
            if ($this->can_unfinalize($submission)) {
                $options = array ('id'=>$this->cm->id, 'userid'=>$userid, 'action'=>'unfinalize', 'mode'=>$mode, 'offset'=>$offset);
                $output .= print_single_button('upload.php', $options, get_string('unfinalize', 'assignment'), 'post', '_self', true);
            } else if ($this->can_finalize($submission)) {
                $options = array ('id'=>$this->cm->id, 'userid'=>$userid, 'action'=>'finalizeclose', 'mode'=>$mode, 'offset'=>$offset);
                $output .= print_single_button('upload.php', $options, get_string('finalize', 'assignment'), 'post', '_self', true);
            }
        }

        $output = '<div class="files">'.$output.'</div>';

        if ($return) {
            return $output;
        }
        echo $output;
    }

    function print_responsefiles($userid, $return=false) {
        global $CFG, $USER, $OUTPUT;

        $mode    = optional_param('mode', '', PARAM_ALPHA);
        $offset  = optional_param('offset', 0, PARAM_INT);

        $output = '';

        $candelete = $this->can_manage_responsefiles();
        $strdelete   = get_string('delete');

        $fs = get_file_storage();
        $browser = get_file_browser();

        if ($files = $fs->get_area_files($this->context->id, 'assignment_response', $userid, "timemodified", false)) {
            foreach ($files as $file) {
                $filename = $file->get_filename();
                $found = true;
                $mimetype = $file->get_mimetype();
                $icon = str_replace(array('.gif', '.png'), '', mimeinfo_from_type('icon', $mimetype));
                $path = file_encode_url($CFG->wwwroot.'/pluginfile.php', '/'.$this->context->id.'/assignment_response/'.$userid.'/'.$filename);

                $output .= '<a href="'.$path.'" ><img src="'.$OUTPUT->old_icon_url('f/'.$icon).'" alt="'.$icon.'" />'.$filename.'</a>';

                if ($candelete) {
                    $delurl  = "$CFG->wwwroot/mod/assignment/delete.php?id={$this->cm->id}&amp;file=".rawurlencode($filename)."&amp;userid=$userid&amp;mode=$mode&amp;offset=$offset&amp;action=response";

                    $output .= '<a href="'.$delurl.'">&nbsp;'
                              .'<img title="'.$strdelete.'" src="'.$OUTPUT->old_icon_url('t/delete') . '" class="iconsmall" alt=""/></a> ';
                }

                $output .= '&nbsp;';
            }

            $output = '<div class="responsefiles">'.$output.'</div>';
        }

        if ($return) {
            return $output;
        }
        echo $output;
    }


    function upload() {
        $action = required_param('action', PARAM_ALPHA);

        switch ($action) {
            case 'finalize':
                $this->finalize();
                break;
            case 'finalizeclose':
                $this->finalizeclose();
                break;
            case 'unfinalize':
                $this->unfinalize();
                break;
            case 'uploadresponse':
                $this->upload_responsefile();
                break;
            case 'uploadfile':
                $this->upload_file();
            case 'savenotes':
            case 'editnotes':
                $this->upload_notes();
            default:
                print_error('unknowuploadaction', '', '', $action);
        }
    }

    function upload_notes() {
        global $CFG, $USER;

        $action = required_param('action', PARAM_ALPHA);

        $returnurl = 'view.php?id='.$this->cm->id;

        $mform = new mod_assignment_upload_notes_form();

        $defaults = new object();
        $defaults->id = $this->cm->id;

        if ($submission = $this->get_submission($USER->id)) {
            $defaults->text = $submission->data1;
        } else {
            $defaults->text = '';
        }

        $mform->set_data($defaults);

        if ($mform->is_cancelled()) {
            redirect('view.php?id='.$this->cm->id);
        }

        if (!$this->can_update_notes($submission)) {
            $this->view_header(get_string('upload'));
            notify(get_string('uploaderror', 'assignment'));
            print_continue($returnurl);
            $this->view_footer();
            die;
        }

        if ($data = $mform->get_data() and $action == 'savenotes') {
            $submission = $this->get_submission($USER->id, true); // get or create submission
            $updated = new object();
            $updated->id           = $submission->id;
            $updated->timemodified = time();
            $updated->data1        = $data->text;

            if ($DB->update_record('assignment_submissions', $updated)) {
                add_to_log($this->course->id, 'assignment', 'upload', 'view.php?a='.$this->assignment->id, $this->assignment->id, $this->cm->id);
                redirect($returnurl);
                $submission = $this->get_submission($USER->id);
                $this->update_grade($submission);

            } else {
                $this->view_header(get_string('notes', 'assignment'));
                notify(get_string('notesupdateerror', 'assignment'));
                print_continue($returnurl);
                $this->view_footer();
                die;
            }
        }

        /// show notes edit form
        $this->view_header(get_string('notes', 'assignment'));

        print_heading(get_string('notes', 'assignment'), '');

        $mform->display();

        $this->view_footer();
        die;
    }

    function upload_responsefile() {
        global $CFG, $USER;

        $userid = required_param('userid', PARAM_INT);
        $mode   = required_param('mode', PARAM_ALPHA);
        $offset = required_param('offset', PARAM_INT);

        $returnurl = "submissions.php?id={$this->cm->id}&amp;userid=$userid&amp;mode=$mode&amp;offset=$offset";

        $mform = new mod_assignment_upload_response_form(null, $this);
        if ($mform->get_data() and $this->can_manage_responsefiles()) {
            $fs = get_file_storage();
            $filename = $mform->get_new_filename('newfile');
            if ($filename !== false) {
                if (!$fs->file_exists($this->context->id, 'assignment_response', $userid, '/', $filename)) {
                    if ($file = $mform->save_stored_file('newfile', $this->context->id, 'assignment_response', $userid, '/', $filename, false, $USER->id)) {
                        redirect($returnurl);
                    }
                }
            }
        }
        print_header(get_string('upload'));
        notify(get_string('uploaderror', 'assignment'));
        print_continue($returnurl);
        print_footer('none');
        die;
    }

    function upload_file() {
        global $CFG, $USER, $DB;

        $returnurl = 'view.php?id='.$this->cm->id;

        $filecount = $this->count_user_files($USER->id);
        $submission = $this->get_submission($USER->id);

        if (!$this->can_upload_file($submission)) {
            $this->view_header(get_string('upload'));
            notify(get_string('uploaderror', 'assignment'));
            print_continue($returnurl);
            $this->view_footer();
            die;
        }

        $mform = new mod_assignment_upload_file_form('upload.php', $this);
        if ($mform->get_data()) {
            $fs = get_file_storage();
            $filename = $mform->get_new_filename('newfile');
            if ($filename !== false) {
                if (!$fs->file_exists($this->context->id, 'assignment_submission', $USER->id, '/', $filename)) {
                    if ($file = $mform->save_stored_file('newfile', $this->context->id, 'assignment_submission', $USER->id, '/', $filename, false, $USER->id)) {
                        $submission = $this->get_submission($USER->id, true); //create new submission if needed
                        $submission->timemodified = time();
                        if ($DB->update_record('assignment_submissions', $submission)) {
                            add_to_log($this->course->id, 'assignment', 'upload',
                                    'view.php?a='.$this->assignment->id, $this->assignment->id, $this->cm->id);
                            $this->update_grade($submission);
                            if (!$this->drafts_tracked()) {
                                $this->email_teachers($submission);
                            }
                            redirect('view.php?id='.$this->cm->id);
                        } else {
                            $file->delete();
                        }
                    }
                }
            }
        }

        $this->view_header(get_string('upload'));
        notify(get_string('uploaderror', 'assignment'));
        print_continue($returnurl);
        $this->view_footer();
        die;
    }

    function send_file($filearea, $args) {
        global $CFG, $DB, $USER;
        require_once($CFG->libdir.'/filelib.php');

        require_login($this->course, false, $this->cm);

        $userid = (int)array_shift($args);
        $relativepath = '/'.implode('/', $args);
        $fullpath = $this->context->id.$filearea.$userid.$relativepath;

        $fs = get_file_storage();

        if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
            return false;
        }

        if ($filearea === 'assignment_submission') {
            if ($USER->id != $userid and !has_capability('mod/assignment:grade', $this->context)) {
                return false;
            }

        } else if ($filearea === 'assignment_response') {
            if ($USER->id != $userid and !has_capability('mod/assignment:grade', $this->context)) {
                return false;
            }

        } else {
            return false;
        }

        send_stored_file($file, 0, 0, true); // download MUST be forced - security!
    }

    function finalize() {
        global $USER, $DB;

        $confirm    = optional_param('confirm', 0, PARAM_BOOL);
        $returnurl  = 'view.php?id='.$this->cm->id;
        $submission = $this->get_submission($USER->id);

        if (!$this->can_finalize($submission)) {
            redirect($returnurl); // probably already graded, redirect to assignment page, the reason should be obvious
        }

        if (!data_submitted() or !$confirm) {
            $optionsno = array('id'=>$this->cm->id);
            $optionsyes = array ('id'=>$this->cm->id, 'confirm'=>1, 'action'=>'finalize');
            $this->view_header(get_string('submitformarking', 'assignment'));
            print_heading(get_string('submitformarking', 'assignment'));
            notice_yesno(get_string('onceassignmentsent', 'assignment'), 'upload.php', 'view.php', $optionsyes, $optionsno, 'post', 'get');
            $this->view_footer();
            die;

        }
        $updated = new object();
        $updated->id           = $submission->id;
        $updated->data2        = ASSIGNMENT_STATUS_SUBMITTED;
        $updated->timemodified = time();

        if ($DB->update_record('assignment_submissions', $updated)) {
            add_to_log($this->course->id, 'assignment', 'upload', //TODO: add finalize action to log
                    'view.php?a='.$this->assignment->id, $this->assignment->id, $this->cm->id);
            $submission = $this->get_submission($USER->id);
            $this->update_grade($submission);
            $this->email_teachers($submission);
        } else {
            $this->view_header(get_string('submitformarking', 'assignment'));
            notify(get_string('finalizeerror', 'assignment'));
            print_continue($returnurl);
            $this->view_footer();
            die;
        }
        redirect($returnurl);
    }

    function finalizeclose() {
        global $DB;

        $userid    = optional_param('userid', 0, PARAM_INT);
        $mode      = required_param('mode', PARAM_ALPHA);
        $offset    = required_param('offset', PARAM_INT);
        $returnurl = "submissions.php?id={$this->cm->id}&amp;userid=$userid&amp;mode=$mode&amp;offset=$offset&amp;forcerefresh=1";

        // create but do not add student submission date
        $submission = $this->get_submission($userid, true, true);

        if (!data_submitted() or !$this->can_finalize($submission)) {
            redirect($returnurl); // probably closed already
        }

        $updated = new object();
        $updated->id    = $submission->id;
        $updated->data2 = ASSIGNMENT_STATUS_CLOSED;

        if ($DB->update_record('assignment_submissions', $updated)) {
            add_to_log($this->course->id, 'assignment', 'upload', //TODO: add finalize action to log
                    'view.php?a='.$this->assignment->id, $this->assignment->id, $this->cm->id);
            $submission = $this->get_submission($userid, false, true);
            $this->update_grade($submission);
        }
        redirect($returnurl);
    }

    function unfinalize() {
        global $DB;

        $userid = required_param('userid', PARAM_INT);
        $mode   = required_param('mode', PARAM_ALPHA);
        $offset = required_param('offset', PARAM_INT);

        $returnurl = "submissions.php?id={$this->cm->id}&amp;userid=$userid&amp;mode=$mode&amp;offset=$offset&amp;forcerefresh=1";

        if (data_submitted()
          and $submission = $this->get_submission($userid)
          and $this->can_unfinalize($submission)) {

            $updated = new object();
            $updated->id = $submission->id;
            $updated->data2 = '';
            if ($DB->update_record('assignment_submissions', $updated)) {
                //TODO: add unfinalize action to log
                add_to_log($this->course->id, 'assignment', 'view submission', 'submissions.php?id='.$this->assignment->id, $this->assignment->id, $this->cm->id);
                $submission = $this->get_submission($userid);
                $this->update_grade($submission);
            } else {
                $this->view_header(get_string('submitformarking', 'assignment'));
                notify(get_string('unfinalizeerror', 'assignment'));
                print_continue($returnurl);
                $this->view_footer();
                die;
            }
        }
        redirect($returnurl);
    }


    function delete() {
        $action   = optional_param('action', '', PARAM_ALPHA);

        switch ($action) {
            case 'response':
                $this->delete_responsefile();
                break;
            default:
                $this->delete_file();
        }
        die;
    }


    function delete_responsefile() {
        global $CFG;

        $file     = required_param('file', PARAM_FILE);
        $userid   = required_param('userid', PARAM_INT);
        $mode     = required_param('mode', PARAM_ALPHA);
        $offset   = required_param('offset', PARAM_INT);
        $confirm  = optional_param('confirm', 0, PARAM_BOOL);

        $returnurl = "submissions.php?id={$this->cm->id}&amp;userid=$userid&amp;mode=$mode&amp;offset=$offset";

        if (!$this->can_manage_responsefiles()) {
           redirect($returnurl);
        }

        $urlreturn = 'submissions.php';
        $optionsreturn = array('id'=>$this->cm->id, 'offset'=>$offset, 'mode'=>$mode, 'userid'=>$userid);

        if (!data_submitted() or !$confirm) {
            $optionsyes = array ('id'=>$this->cm->id, 'file'=>$file, 'userid'=>$userid, 'confirm'=>1, 'action'=>'response', 'mode'=>$mode, 'offset'=>$offset);
            print_header(get_string('delete'));
            print_heading(get_string('delete'));
            notice_yesno(get_string('confirmdeletefile', 'assignment', $file), 'delete.php', $urlreturn, $optionsyes, $optionsreturn, 'post', 'get');
            print_footer('none');
            die;
        }

        $fs = get_file_storage();
        if ($file = $fs->get_file($this->context->id, 'assignment_submission', $userid, '/', $file)) {
            if ($file->delete()) {
                redirect($returnurl);
            }
        }

        // print delete error
        print_header(get_string('delete'));
        notify(get_string('deletefilefailed', 'assignment'));
        print_continue($returnurl);
        print_footer('none');
        die;

    }


    function delete_file() {
        global $CFG, $DB;

        $file     = required_param('file', PARAM_FILE);
        $userid   = required_param('userid', PARAM_INT);
        $confirm  = optional_param('confirm', 0, PARAM_BOOL);
        $mode     = optional_param('mode', '', PARAM_ALPHA);
        $offset   = optional_param('offset', 0, PARAM_INT);

        require_login($this->course->id, false, $this->cm);

        if (empty($mode)) {
            $urlreturn = 'view.php';
            $optionsreturn = array('id'=>$this->cm->id);
            $returnurl = 'view.php?id='.$this->cm->id;
        } else {
            $urlreturn = 'submissions.php';
            $optionsreturn = array('id'=>$this->cm->id, 'offset'=>$offset, 'mode'=>$mode, 'userid'=>$userid);
            $returnurl = "submissions.php?id={$this->cm->id}&amp;offset=$offset&amp;mode=$mode&amp;userid=$userid";
        }

        if (!$submission = $this->get_submission($userid) // incorrect submission
          or !$this->can_delete_files($submission)) {     // can not delete
            $this->view_header(get_string('delete'));
            notify(get_string('cannotdeletefiles', 'assignment'));
            print_continue($returnurl);
            $this->view_footer();
            die;
        }

        if (!data_submitted() or !$confirm) {
            $optionsyes = array ('id'=>$this->cm->id, 'file'=>$file, 'userid'=>$userid, 'confirm'=>1, 'sesskey'=>sesskey(), 'mode'=>$mode, 'offset'=>$offset);
            if (empty($mode)) {
                $this->view_header(get_string('delete'));
            } else {
                print_header(get_string('delete'));
            }
            print_heading(get_string('delete'));
            notice_yesno(get_string('confirmdeletefile', 'assignment', $file), 'delete.php', $urlreturn, $optionsyes, $optionsreturn, 'post', 'get');
            if (empty($mode)) {
                $this->view_footer();
            } else {
                print_footer('none');
            }
            die;
        }

        $fs = get_file_storage();
        if ($file = $fs->get_file($this->context->id, 'assignment_submission', $userid, '/', $file)) {
            if ($file->delete()) {
                $submission->timemodified = time();
                if ($DB->update_record('assignment_submissions', $submission)) {
                    add_to_log($this->course->id, 'assignment', 'upload', //TODO: add delete action to log
                            'view.php?a='.$this->assignment->id, $this->assignment->id, $this->cm->id);
                    $this->update_grade($submission);
                }
                redirect($returnurl);
            }
        }

        // print delete error
        if (empty($mode)) {
            $this->view_header(get_string('delete'));
        } else {
            print_header(get_string('delete'));
        }
        notify(get_string('deletefilefailed', 'assignment'));
        print_continue($returnurl);
        if (empty($mode)) {
            $this->view_footer();
        } else {
            print_footer('none');
        }
        die;
    }


    function can_upload_file($submission) {
        global $USER;

        if (has_capability('mod/assignment:submit', $this->context)           // can submit
          and $this->isopen()                                                 // assignment not closed yet
          and (empty($submission) or $submission->userid == $USER->id)        // his/her own submission
          and $this->count_user_files($USER->id) < $this->assignment->var1    // file limit not reached
          and !$this->is_finalized($submission)) {                            // no uploading after final submission
            return true;
        } else {
            return false;
        }
    }

    function can_manage_responsefiles() {
        if (has_capability('mod/assignment:grade', $this->context)) {
            return true;
        } else {
            return false;
        }
    }

    function can_delete_files($submission) {
        global $USER;

        if (has_capability('mod/assignment:grade', $this->context)) {
            return true;
        }

        if (has_capability('mod/assignment:submit', $this->context)
          and $this->isopen()                                      // assignment not closed yet
          and $this->assignment->resubmit                          // deleting allowed
          and $USER->id == $submission->userid                     // his/her own submission
          and !$this->is_finalized($submission)) {                 // no deleting after final submission
            return true;
        } else {
            return false;
        }
    }

    function drafts_tracked() {
        return !empty($this->assignment->var4);
    }

    /**
     * Returns submission status
     * @param object $submission - may be empty
     * @return string submission state - empty, ASSIGNMENT_STATUS_SUBMITTED or ASSIGNMENT_STATUS_CLOSED
     */
    function is_finalized($submission) {
        if (!$this->drafts_tracked()) {
            return '';

        } else if (empty($submission)) {
            return '';

        } else if ($submission->data2 == ASSIGNMENT_STATUS_SUBMITTED or $submission->data2 == ASSIGNMENT_STATUS_CLOSED) {
            return $submission->data2;

        } else {
            return '';
        }
    }

    function can_unfinalize($submission) {
        if (!$this->drafts_tracked()) {
            return false;
        }
        if (has_capability('mod/assignment:grade', $this->context)
          and $this->isopen()
          and $this->is_finalized($submission)) {
            return true;
        } else {
            return false;
        }
    }

    function can_finalize($submission) {
        global $USER;
        if (!$this->drafts_tracked()) {
            return false;
        }

		if ($this->is_finalized($submission)) {
		    return false;
		}

        if (has_capability('mod/assignment:grade', $this->context)) {
            return true;

        } else if (has_capability('mod/assignment:submit', $this->context)    // can submit
          and $this->isopen()                                                 // assignment not closed yet
          and !empty($submission)                                             // submission must exist
          and $submission->userid == $USER->id                                // his/her own submission
          and ($this->count_user_files($USER->id)
            or ($this->notes_allowed() and !empty($submission->data1)))) {    // something must be submitted

            return true;
        } else {
            return false;
        }
    }

    function can_update_notes($submission) {
        global $USER;

        if (has_capability('mod/assignment:submit', $this->context)
          and $this->notes_allowed()                                          // notesd must be allowed
          and $this->isopen()                                                 // assignment not closed yet
          and (empty($submission) or $USER->id == $submission->userid)        // his/her own submission
          and !$this->is_finalized($submission)) {                            // no updateingafter final submission
            return true;
        } else {
            return false;
        }
    }

    function notes_allowed() {
        return (boolean)$this->assignment->var2;
    }

    function count_responsefiles($userid) {
        $fs = get_file_storage();
        $files = $fs->get_area_files($this->context->id, 'assignment_response', $userid, "id", false);
        return count($files);
    }

    function setup_elements(&$mform) {
        global $CFG, $COURSE;

        $ynoptions = array( 0 => get_string('no'), 1 => get_string('yes'));

        $choices = get_max_upload_sizes($CFG->maxbytes, $COURSE->maxbytes);
        $choices[0] = get_string('courseuploadlimit') . ' ('.display_size($COURSE->maxbytes).')';
        $mform->addElement('select', 'maxbytes', get_string('maximumsize', 'assignment'), $choices);
        $mform->setDefault('maxbytes', $CFG->assignment_maxbytes);

        $mform->addElement('select', 'resubmit', get_string("allowdeleting", "assignment"), $ynoptions);
        $mform->setHelpButton('resubmit', array('allowdeleting', get_string('allowdeleting', 'assignment'), 'assignment'));
        $mform->setDefault('resubmit', 1);

        $options = array();
        for($i = 1; $i <= 20; $i++) {
            $options[$i] = $i;
        }
        $mform->addElement('select', 'var1', get_string("allowmaxfiles", "assignment"), $options);
        $mform->setHelpButton('var1', array('allowmaxfiles', get_string('allowmaxfiles', 'assignment'), 'assignment'));
        $mform->setDefault('var1', 3);

        $mform->addElement('select', 'var2', get_string("allownotes", "assignment"), $ynoptions);
        $mform->setHelpButton('var2', array('allownotes', get_string('allownotes', 'assignment'), 'assignment'));
        $mform->setDefault('var2', 0);

        $mform->addElement('select', 'var3', get_string("hideintro", "assignment"), $ynoptions);
        $mform->setHelpButton('var3', array('hideintro', get_string('hideintro', 'assignment'), 'assignment'));
        $mform->setDefault('var3', 0);

        $mform->addElement('select', 'emailteachers', get_string("emailteachers", "assignment"), $ynoptions);
        $mform->setHelpButton('emailteachers', array('emailteachers', get_string('emailteachers', 'assignment'), 'assignment'));
        $mform->setDefault('emailteachers', 0);

        $mform->addElement('select', 'var4', get_string("trackdrafts", "assignment"), $ynoptions);
        $mform->setHelpButton('var4', array('trackdrafts', get_string('trackdrafts', 'assignment'), 'assignment'));
        $mform->setDefault('var4', 1);

    }

    function portfolio_exportable() {
        return true;
    }

}

class mod_assignment_upload_notes_form extends moodleform {
    function definition() {
        $mform = $this->_form;

        // visible elements
        $mform->addElement('htmleditor', 'text', get_string('notes', 'assignment'), array('cols'=>85, 'rows'=>30));
        $mform->setType('text', PARAM_RAW); // to be cleaned before display
        $mform->setHelpButton('text', array('reading', 'writing'), false, 'editorhelpbutton');

        // hidden params
        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'action', 'savenotes');
        $mform->setType('action', PARAM_ALPHA);

        // buttons
        $this->add_action_buttons();
    }
}

class mod_assignment_upload_response_form extends moodleform {
    function definition() {
        $mform = $this->_form;
        $instance = $this->_customdata;

        // visible elements
        $mform->addElement('file', 'newfile', get_string('uploadafile'));

        // hidden params
        $mform->addElement('hidden', 'id', $instance->cm->id);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'action', 'uploadresponse');
        $mform->setType('action', PARAM_ALPHA);
        $mform->addElement('hidden', 'mode');
        $mform->setType('mode', PARAM_ALPHA);
        $mform->addElement('hidden', 'offset');
        $mform->setType('offset', PARAM_INT);
        $mform->addElement('hidden', 'forcerefresh');
        $mform->setType('forcerefresh', PARAM_INT);
        $mform->addElement('hidden', 'userid');
        $mform->setType('userid', PARAM_INT);

        // buttons
        $this->add_action_buttons(false, get_string('uploadthisfile'));
    }
}



?>
