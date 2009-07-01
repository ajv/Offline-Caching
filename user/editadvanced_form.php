<?php //$Id$

require_once($CFG->dirroot.'/lib/formslib.php');

class user_editadvanced_form extends moodleform {

    // Define the form
    function definition() {
        global $USER, $CFG, $COURSE;

        $mform =& $this->_form;
        //Accessibility: "Required" is bad legend text.
        $strgeneral  = get_string('general');
        $strrequired = get_string('required');

        /// Add some extra hidden fields
        $mform->addElement('hidden', 'id');
        $mform->addElement('hidden', 'course', $COURSE->id);

        /// Print the required moodle fields first
        $mform->addElement('header', 'moodle', $strgeneral);

        $mform->addElement('text', 'username', get_string('username'), 'size="20"');
        $mform->addRule('username', $strrequired, 'required', null, 'client');
        $mform->setType('username', PARAM_RAW);

        $auths = get_plugin_list('auth');
        $auth_options = array();
        foreach ($auths as $auth => $unused) {
            $auth_options[$auth] = auth_get_plugin_title($auth);
        }
        $mform->addElement('select', 'auth', get_string('chooseauthmethod','auth'), $auth_options);
        $mform->setHelpButton('auth', array('authchange', get_string('chooseauthmethod','auth')));
        $mform->setAdvanced('auth');

        $mform->addElement('passwordunmask', 'newpassword', get_string('newpassword'), 'size="20"');
        $mform->setHelpButton('newpassword',array('newpassword', get_string('leavetokeep')));
        $mform->setType('newpassword', PARAM_RAW);

        $mform->addElement('advcheckbox', 'preference_auth_forcepasswordchange', get_string('forcepasswordchange'));
        $mform->setHelpButton('preference_auth_forcepasswordchange',array('forcepasswordchange', get_string('forcepasswordchange')));
        /// shared fields
        useredit_shared_definition($mform);

        /// Next the customisable profile fields
        profile_definition($mform);

        $this->add_action_buttons(false, get_string('updatemyprofile'));
    }

    function definition_after_data() {
        global $USER, $CFG, $DB;

        $mform =& $this->_form;
        if ($userid = $mform->getElementValue('id')) {
            $user = $DB->get_record('user', array('id'=>$userid));
        } else {
            $user = false;
        }

        // if language does not exist, use site default lang
        if ($langsel = $mform->getElementValue('lang')) {
            $lang = reset($langsel);
            // missing _utf8 in language, add it before further processing. MDL-11829 MDL-16845
            if (strpos($lang, '_utf8') === false) {
                $lang = $lang . '_utf8';
                $lang_el =& $mform->getElement('lang');
                $lang_el->setValue($lang);
            }
            // check lang exists
            if (!file_exists($CFG->dataroot.'/lang/'.$lang) and
              !file_exists($CFG->dirroot .'/lang/'.$lang)) {
                $lang_el =& $mform->getElement('lang');
                $lang_el->setValue($CFG->lang);
            }
        }

        // user can not change own auth method
        if ($userid == $USER->id) {
            $mform->hardFreeze('auth');
            $mform->hardFreeze('preference_auth_forcepasswordchange');
        }

        // admin must choose some password and supply correct email
        if (!empty($USER->newadminuser)) {
            $mform->addRule('newpassword', get_string('required'), 'required', null, 'client');
        }

        // require password for new users
        if ($userid == -1) {
            $mform->addRule('newpassword', get_string('required'), 'required', null, 'client');
        }

        // print picture
        if (!empty($CFG->gdversion)) {
            $image_el =& $mform->getElement('currentpicture');
            if ($user and $user->picture) {
                $image_el->setValue(print_user_picture($user, SITEID, $user->picture, 64, true, false, '', true));
            } else {
                $image_el->setValue(get_string('none'));
            }
        }

        /// Next the customisable profile fields
        profile_definition_after_data($mform, $userid);
    }

    function validation($usernew, $files) {
        global $CFG, $DB;

        $usernew = (object)$usernew;
        $usernew->username = trim($usernew->username);

        $user = $DB->get_record('user', array('id'=>$usernew->id));
        $err = array();

        if (!empty($usernew->newpassword)) {
            $errmsg = '';//prevent eclipse warning
            if (!check_password_policy($usernew->newpassword, $errmsg)) {
                $err['newpassword'] = $errmsg;
            }
        }

        if (empty($usernew->username)) {
            //might be only whitespace
            $err['username'] = get_string('required');
        } else if (!$user or $user->username !== $usernew->username) {
            //check new username does not exist
            if ($DB->record_exists('user', array('username'=>$usernew->username, 'mnethostid'=>$CFG->mnet_localhost_id))) {
                $err['username'] = get_string('usernameexists');
            }
            //check allowed characters
            if ($usernew->username !== moodle_strtolower($usernew->username)) {
                $err['username'] = get_string('usernamelowercase');
            } else {
                if (empty($CFG->extendedusernamechars)) {
                    $string = preg_replace("/[^(-\.[:alnum:])]/i", '', $usernew->username);
                    if ($usernew->username !== $string) {
                        $err['username'] = get_string('alphanumerical');
                    }
                }
            }
        }

        if (!$user or $user->email !== $usernew->email) {
            if (!validate_email($usernew->email)) {
                $err['email'] = get_string('invalidemail');
            } else if ($DB->record_exists('user', array('email'=>$usernew->email, 'mnethostid'=>$CFG->mnet_localhost_id))) {
                $err['email'] = get_string('emailexists');
            }
        }

        /// Next the customisable profile fields
        $err += profile_validation($usernew, $files);

        if (count($err) == 0){
            return true;
        } else {
            return $err;
        }
    }

    function get_um() {
        return $this->_upload_manager;
    }
}

?>
