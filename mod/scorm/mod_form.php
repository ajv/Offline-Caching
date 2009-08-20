<?php
require_once ($CFG->dirroot.'/course/moodleform_mod.php');
require_once($CFG->dirroot.'/mod/scorm/locallib.php');

class mod_scorm_mod_form extends moodleform_mod {

    function definition() {
        global $CFG, $COURSE, $OUTPUT;
        $cfg_scorm = get_config('scorm');

        $mform = $this->_form;

        if (!$CFG->slasharguments) {
            $mform->addElement('static', '', '',$OUTPUT->notification(get_string('slashargs', 'scorm'), 'notifyproblem'));
        }
        $zlib = ini_get('zlib.output_compression'); //check for zlib compression - if used, throw error because of IE bug. - SEE MDL-16185
        if (isset($zlib) && $zlib) {
            $mform->addElement('static', '', '',$OUTPUT->notification(get_string('zlibwarning', 'scorm'), 'notifyproblem'));
        }
//-------------------------------------------------------------------------------
        $mform->addElement('header', 'general', get_string('general', 'form'));

// Name
        $mform->addElement('text', 'name', get_string('name'));
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEAN);
        }
        $mform->addRule('name', null, 'required', null, 'client');

// Summary
        $this->add_intro_editor(true);

// Scorm types
        $options = array(SCORM_TYPE_LOCAL => get_string('typelocal', 'scorm'));

        if ($cfg_scorm->allowtypeexternal) {
            $options[SCORM_TYPE_EXTERNAL] = get_string('typeexternal', 'scorm');
        }

        if ($cfg_scorm->allowtypelocalsync) {
            $options[SCORM_TYPE_LOCALSYNC] = get_string('typelocalsync', 'scorm');
        }

        if (!empty($CFG->repositoryactivate) and $cfg_scorm->allowtypeimsrepository) {
            $options[SCORM_TYPE_IMSREPOSITORY] = get_string('typeimsrepository', 'scorm');
        }

        $mform->addElement('select', 'scormtype', get_string('scormtype', 'scorm'), $options);

// Reference
        if (count($options) > 1) {
            $mform->addElement('text', 'packageurl', get_string('url', 'scorm'), array('size'=>60));
            $mform->setType('packageurl', PARAM_RAW);
            $mform->setHelpButton('packageurl', array('packagefile', get_string('package', 'scorm'), 'scorm'));
            $mform->disabledIf('packageurl', 'scormtype', 'eq', SCORM_TYPE_LOCAL);
        }

// New local package upload
        $maxbytes = get_max_upload_file_size($CFG->maxbytes, $COURSE->maxbytes);
        $mform->setMaxFileSize($maxbytes);
        $mform->addElement('file', 'packagefile', get_string('package','scorm'));
        $mform->disabledIf('packagefile', 'scormtype', 'noteq', SCORM_TYPE_LOCAL);

//-------------------------------------------------------------------------------
// Time restrictions
        $mform->addElement('header', 'timerestricthdr', get_string('timerestrict', 'scorm'));
        $mform->addElement('checkbox', 'timerestrict', get_string('timerestrict', 'scorm'));
        $mform->setHelpButton('timerestrict', array("timerestrict", get_string("timerestrict","scorm"), "scorm"));


        $mform->addElement('date_time_selector', 'timeopen', get_string("scormopen", "scorm"));
        $mform->disabledIf('timeopen', 'timerestrict');

        $mform->addElement('date_time_selector', 'timeclose', get_string("scormclose", "scorm"));
        $mform->disabledIf('timeclose', 'timerestrict');

//-------------------------------------------------------------------------------
// Other Settings
        $mform->addElement('header', 'advanced', get_string('othersettings', 'form'));

// Grade Method
        $mform->addElement('select', 'grademethod', get_string('grademethod', 'scorm'), scorm_get_grade_method_array());
        $mform->setHelpButton('grademethod', array('grademethod', get_string('grademethod', 'scorm'),'scorm'));
        $mform->setDefault('grademethod', $cfg_scorm->grademethod);

// Maximum Grade
        for ($i=0; $i<=100; $i++) {
          $grades[$i] = "$i";
        }
        $mform->addElement('select', 'maxgrade', get_string('maximumgrade'), $grades);
        $mform->setDefault('maxgrade', $cfg_scorm->maxgrade);
        $mform->disabledIf('maxgrade', 'grademethod','eq', GRADESCOES);

// Attempts
        $mform->addElement('static', '', '' ,'<hr />');

// Max Attempts
        $mform->addElement('select', 'maxattempt', get_string('maximumattempts', 'scorm'), scorm_get_attempts_array());
        $mform->setHelpButton('maxattempt', array('maxattempt',get_string('maximumattempts', 'scorm'), 'scorm'));
        $mform->setDefault('maxattempt', $cfg_scorm->maxattempts);

// Display attempt status
        $mform->addElement('selectyesno', 'displayattemptstatus', get_string('displayattemptstatus', 'scorm'));
        $mform->setHelpButton('displayattemptstatus', array('displayattemptstatus',get_string('displayattemptstatus', 'scorm'), 'scorm'));
        $mform->setDefault('displayattemptstatus', $cfg_scorm->displayattemptstatus);

// Display course structure
        $mform->addElement('selectyesno', 'displaycoursestructure', get_string('displaycoursestructure', 'scorm'));
        $mform->setHelpButton('displaycoursestructure', array('displaycoursestructure',get_string('displaycoursestructure', 'scorm'), 'scorm'));
        $mform->setDefault('displaycoursestructure', $cfg_scorm->displaycoursestructure);

// Force completed
        $mform->addElement('selectyesno', 'forcecompleted', get_string('forcecompleted', 'scorm'));
        $mform->setHelpButton('forcecompleted', array('forcecompleted',get_string('forcecompleted', 'scorm'), 'scorm'));
        $mform->setDefault('forcecompleted', $cfg_scorm->forcecompleted);
        $mform->setAdvanced('forcecompleted');

// Force new attempt
        $mform->addElement('selectyesno', 'forcenewattempt', get_string('forcenewattempt', 'scorm'));
        $mform->setHelpButton('forcenewattempt', array('forcenewattempt',get_string('forcenewattempt', 'scorm'), 'scorm'));
        $mform->setDefault('forcenewattempt', $cfg_scorm->forcenewattempt);
        $mform->setAdvanced('forcenewattempt');

// Last attempt lock - lock the enter button after the last available attempt has been made
        $mform->addElement('selectyesno', 'lastattemptlock', get_string('lastattemptlock', 'scorm'));
        $mform->setHelpButton('lastattemptlock', array('lastattemptlock',get_string('lastattemptlock', 'scorm'), 'scorm'));
        $mform->setDefault('lastattemptlock', $cfg_scorm->lastattemptlock);
        $mform->setAdvanced('lastattemptlock');

// What Grade
        $mform->addElement('select', 'whatgrade', get_string('whatgrade', 'scorm'),  scorm_get_what_grade_array());
        $mform->disabledIf('whatgrade', 'maxattempt','eq',1);
        $mform->setHelpButton('whatgrade', array('whatgrade',get_string('whatgrade', 'scorm'), 'scorm'));
        $mform->setDefault('whatgrade', $cfg_scorm->whatgrade);
        $mform->setAdvanced('whatgrade');

// Activation period
/*        $mform->addElement('static', '', '' ,'<hr />');
        $mform->addElement('static', 'activation', get_string('activation','scorm'));
        $datestartgrp = array();
        $datestartgrp[] = &$mform->createElement('date_time_selector', 'startdate');
        $datestartgrp[] = &$mform->createElement('checkbox', 'startdisabled', null, get_string('disable'));
        $mform->addGroup($datestartgrp, 'startdategrp', get_string('from'), ' ', false);
        $mform->setDefault('startdate', 0);
        $mform->setDefault('startdisabled', 1);
        $mform->disabledIf('startdategrp', 'startdisabled', 'checked');

        $dateendgrp = array();
        $dateendgrp[] = &$mform->createElement('date_time_selector', 'enddate');
        $dateendgrp[] = &$mform->createElement('checkbox', 'enddisabled', null, get_string('disable'));
        $mform->addGroup($dateendgrp, 'dateendgrp', get_string('to'), ' ', false);
        $mform->setDefault('enddate', 0);
        $mform->setDefault('enddisabled', 1);
        $mform->disabledIf('dateendgrp', 'enddisabled', 'checked');
*/

// Stage Size
        $mform->addElement('static', '', '' ,'<hr />');
        $mform->addElement('static', 'stagesize', get_string('stagesize','scorm'));
        $mform->setHelpButton('stagesize', array('stagesize',get_string('stagesize', 'scorm'), 'scorm'));
// Width
        $mform->addElement('text', 'width', get_string('width','scorm'), 'maxlength="5" size="5"');
        $mform->setDefault('width', $cfg_scorm->framewidth);
        $mform->setType('width', PARAM_INT);

// Height
        $mform->addElement('text', 'height', get_string('height','scorm'), 'maxlength="5" size="5"');
        $mform->setDefault('height', $cfg_scorm->frameheight);
        $mform->setType('height', PARAM_INT);

// Framed / Popup Window
        $mform->addElement('select', 'popup', get_string('display', 'scorm'), scorm_get_popup_display_array());
        $mform->setDefault('popup', $cfg_scorm->popup);
        $mform->setAdvanced('popup');

// Window Options
        $winoptgrp = array();
        foreach(scorm_get_popup_options_array() as $key => $value){
            $winoptgrp[] = &$mform->createElement('checkbox', $key, '', get_string($key, 'scorm'));
            $mform->setDefault($key, $value);
        }
        $mform->addGroup($winoptgrp, 'winoptgrp', get_string('options','scorm'), '<br />', false);
        $mform->setAdvanced('winoptgrp');
        $mform->disabledIf('winoptgrp', 'popup', 'eq', 0);

// Skip view page
        $mform->addElement('select', 'skipview', get_string('skipview', 'scorm'),scorm_get_skip_view_array());
        $mform->setHelpButton('skipview', array('skipview',get_string('skipview', 'scorm'), 'scorm'));
        $mform->setDefault('skipview', $cfg_scorm->skipview);
        $mform->setAdvanced('skipview');

// Hide Browse
        $mform->addElement('selectyesno', 'hidebrowse', get_string('hidebrowse', 'scorm'));
        $mform->setHelpButton('hidebrowse', array('hidebrowse',get_string('hidebrowse', 'scorm'), 'scorm'));
        $mform->setDefault('hidebrowse', $cfg_scorm->hidebrowse);
        $mform->setAdvanced('hidebrowse');

// Toc display
        $mform->addElement('select', 'hidetoc', get_string('hidetoc', 'scorm'), scorm_get_hidetoc_array());
        $mform->setDefault('hidetoc', $cfg_scorm->hidetoc);
        $mform->setAdvanced('hidetoc');

// Hide Navigation panel
        $mform->addElement('selectyesno', 'hidenav', get_string('hidenav', 'scorm'));
        $mform->setDefault('hidenav', $cfg_scorm->hidenav);
        $mform->setAdvanced('hidenav');

// Autocontinue
        $mform->addElement('selectyesno', 'auto', get_string('autocontinue', 'scorm'));
        $mform->setHelpButton('auto', array('autocontinue',get_string('autocontinue', 'scorm'), 'scorm'));
        $mform->setDefault('auto', $cfg_scorm->auto);
        $mform->setAdvanced('auto');

// Update packages timing
        $mform->addElement('select', 'updatefreq', get_string('updatefreq', 'scorm'), scorm_get_updatefreq_array());
        $mform->setDefault('updatefreq', $cfg_scorm->updatefreq);
        $mform->setAdvanced('updatefreq');

//-------------------------------------------------------------------------------
// Hidden Settings
        $mform->addElement('hidden', 'datadir', null);
        $mform->addElement('hidden', 'pkgtype', null);
        $mform->addElement('hidden', 'launch', null);
        $mform->addElement('hidden', 'redirect', null);
        $mform->addElement('hidden', 'redirecturl', null);


//-------------------------------------------------------------------------------
        $this->standard_coursemodule_elements();
//-------------------------------------------------------------------------------
        // buttons
        $this->add_action_buttons();

    }

    function data_preprocessing(&$default_values) {
        global $COURSE;

        if (isset($default_values['popup']) && ($default_values['popup'] == 1) && isset($default_values['options'])) {
            if (!empty($default_values['options'])) {
                $options = explode(',',$default_values['options']);
                foreach ($options as $option) {
                    list($element,$value) = explode('=',$option);
                    $element = trim($element);
                    $default_values[$element] = trim($value);
                }
            }
        }
        if (isset($default_values['grademethod'])) {
            $default_values['whatgrade'] = intval($default_values['grademethod'] / 10);
            $default_values['grademethod'] = $default_values['grademethod'] % 10;
        }
        if (isset($default_value['width']) && (strpos($default_value['width'],'%') === false) && ($default_value['width'] <= 100)) {
            $default_value['width'] .= '%';
        }
        if (isset($default_value['width']) && (strpos($default_value['height'],'%') === false) && ($default_value['height'] <= 100)) {
            $default_value['height'] .= '%';
        }
        $scorms = get_all_instances_in_course('scorm', $COURSE);
        $coursescorm = current($scorms);
        if (($COURSE->format == 'scorm') && ((count($scorms) == 0) || ($default_values['instance'] == $coursescorm->id))) {
            $default_values['redirect'] = 'yes';
            $default_values['redirecturl'] = '../course/view.php?id='.$default_values['course'];
        } else {
            $default_values['redirect'] = 'no';
            $default_values['redirecturl'] = '../mod/scorm/view.php?id='.$default_values['coursemodule'];
        }
        if (isset($default_values['version'])) {
            $default_values['pkgtype'] = (substr($default_values['version'],0,5) == 'SCORM') ? 'scorm':'aicc';
        }
        if (isset($default_values['instance'])) {
            $default_values['datadir'] = $default_values['instance'];
        }
        if (empty($default_values['timeopen'])) {
            $default_values['timerestrict'] = 0;
        } else {
            $default_values['timerestrict'] = 1;
        }
    }

    function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $type = $data['scormtype'];

        if ($type === SCORM_TYPE_LOCAL) {
            if (!empty($data['update'])) {
                //ok, not required

            } else if (empty($files['packagefile'])) {
                $errors['packagefile'] = get_string('required');

            } else {
                $packer = get_file_packer('application/zip');

                $filelist = $packer->list_files($files['packagefile']);
                if (!is_array($filelist)) {
                    $errors['packagefile'] = 'Incorrect file package - not an archive'; //TODO: localise
                } else {
                    $manifestpresent = false;
                    $aiccfound       = false;
                    foreach ($filelist as $info) {
                        if ($info->pathname == 'imsmanifest.xml') {
                            $manifestpresent = true;
                            break;
                        }
                        if (preg_match('/\.cst$/', $info->pathname)) {
                            $aiccfound = true;
                            break;
                        }
                    }
                    if (!$manifestpresent and !$aiccfound) {
                        $errors['packagefile'] = 'Incorrect file package - missing imsmanifest.xml or AICC structure'; //TODO: localise
                    }
                }
            }

        } else if ($type === SCORM_TYPE_EXTERNAL) {
            $reference = $data['packageurl'];
            if (!preg_match('/(http:\/\/|https:\/\/|www).*\/imsmanifest.xml$/i', $reference)) {
                $errors['packageurl'] = get_string('required'); // TODO: improve help
            }

        } else if ($type === 'packageurl') {
            $reference = $data['reference'];
            if (!preg_match('/(http:\/\/|https:\/\/|www).*(\.zip|\.pif)$/i', $reference)) {
                $errors['packageurl'] = get_string('required'); // TODO: improve help
            }

        } else if ($type === SCORM_TYPE_IMSREPOSITORY) {
            $reference = $data['packageurl'];
            if (stripos($reference, '#') !== 0) {
                $errors['packageurl'] = get_string('required');
            }
        }

        return $errors;
    }

    //need to translate the "options" and "reference" field.
    function set_data($default_values) {
        $default_values = (array)$default_values;

        if (isset($default_values['scormtype']) and isset($default_values['reference'])) {
            switch ($default_values['scormtype']) {
                case SCORM_TYPE_LOCALSYNC :
                case SCORM_TYPE_EXTERNAL:
                case SCORM_TYPE_IMSREPOSITORY:
                    $default_values['packageurl'] = $default_values['reference'];
            }
        }
        unset($default_values['reference']);

        if (!empty($default_values['options'])) {
            $options = explode(',', $default_values['options']);
            foreach ($options as $option) {
                $opt = explode('=', $option);
                if (isset($opt[1])) {
                    $default_values[$opt[0]] = $opt[1];
                }
            }
        }

        $this->data_preprocessing($default_values);
        parent::set_data($default_values);
    }
}
?>
