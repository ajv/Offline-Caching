<?php  // $Id$
require_once "$CFG->libdir/formslib.php";
class mod_quiz_report_overview_settings extends moodleform {

    function definition() {
        global $COURSE;
        $mform    =& $this->_form;
//-------------------------------------------------------------------------------
        $mform->addElement('header', 'preferencespage', get_string('preferencespage', 'quiz_overview'));

        if (!$this->_customdata['currentgroup']){
            $studentsstring = get_string('participants');
        } else {
            $a = new object();
            $a->coursestudent = get_string('participants');
            $a->groupname = groups_get_group_name($this->_customdata['currentgroup']);
            if (20 < strlen($a->groupname)){
              $studentsstring = get_string('studentingrouplong', 'quiz_overview', $a);
            } else {
              $studentsstring = get_string('studentingroup', 'quiz_overview', $a);
            }
        }
        $options = array();
        if (!$this->_customdata['currentgroup']){
            $options[QUIZ_REPORT_ATTEMPTS_ALL] = get_string('optallattempts','quiz_overview');
        }
        if ($this->_customdata['currentgroup'] || $COURSE->id != SITEID) {
            $options[QUIZ_REPORT_ATTEMPTS_ALL_STUDENTS] = get_string('optallstudents','quiz_overview', $studentsstring);
            $options[QUIZ_REPORT_ATTEMPTS_STUDENTS_WITH] =
                     get_string('optattemptsonly','quiz_overview', $studentsstring);
            $options[QUIZ_REPORT_ATTEMPTS_STUDENTS_WITH_NO] = get_string('optnoattemptsonly', 'quiz_overview', $studentsstring);
        }
        $mform->addElement('select', 'attemptsmode', get_string('show', 'quiz_overview'), $options);

        $showattemptsgrp = array();
        if ($this->_customdata['qmsubselect']){
            $gm = '<span class="highlight">'.quiz_get_grading_option_name($this->_customdata['quiz']->grademethod).'</span>';
            $showattemptsgrp[] =& $mform->createElement('advcheckbox', 'qmfilter', get_string('showattempts', 'quiz_overview'), get_string('optonlygradedattempts', 'quiz_overview', $gm), null, array(0,1));
        }
        if (has_capability('mod/quiz:regrade', $this->_customdata['context'])){
            $showattemptsgrp[] =& $mform->createElement('advcheckbox', 'regradefilter', get_string('showattempts', 'quiz_overview'), get_string('optonlyregradedattempts', 'quiz_overview'), null, array(0,1));
        }
        if ($showattemptsgrp){
            $mform->addGroup($showattemptsgrp, null, get_string('showattempts', 'quiz_overview'), '<br />', false);
        } 
//-------------------------------------------------------------------------------
        $mform->addElement('header', 'preferencesuser', get_string('preferencesuser', 'quiz_overview'));

        $mform->addElement('text', 'pagesize', get_string('pagesize', 'quiz_overview'));
        $mform->setType('pagesize', PARAM_INT);

        $mform->addElement('selectyesno', 'detailedmarks', get_string('showdetailedmarks', 'quiz_overview'));

        $this->add_action_buttons(false, get_string('preferencessave', 'quiz_overview'));
    }
}
?>
