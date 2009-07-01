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
 * @package   mod-scorm
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** SCORM_TYPE_LOCAL = local */
define('SCORM_TYPE_LOCAL', 'local');
/** SCORM_TYPE_LOCALSYNC = localsync */
define('SCORM_TYPE_LOCALSYNC', 'localsync');
/** SCORM_TYPE_EXTERNAL = external */
define('SCORM_TYPE_EXTERNAL', 'external');
/** SCORM_TYPE_IMSREPOSITORY = imsrepository */
define('SCORM_TYPE_IMSREPOSITORY', 'imsrepository');


/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will create a new instance and return the id number
 * of the new instance.
 *
 * @global stdClass
 * @global object
 * @uses CONTEXT_MODULE
 * @uses SCORM_TYPE_LOCAL
 * @uses SCORM_TYPE_LOCALSYNC
 * @uses SCORM_TYPE_EXTERNAL
 * @uses SCORM_TYPE_IMSREPOSITORY
 * @param object $scorm Form data
 * @param object $mform
 * @return int new instance id
 */
function scorm_add_instance($scorm, $mform=null) {
    global $CFG, $DB;

    require_once('locallib.php');

    if (empty($scorm->timerestrict)) {
        $scorm->timeopen = 0;
        $scorm->timeclose = 0;
    }

    $cmid       = $scorm->coursemodule;
    $cmidnumber = $scorm->cmidnumber;
    $courseid   = $scorm->course;

    $context = get_context_instance(CONTEXT_MODULE, $cmid);

    $scorm = scorm_option2text($scorm);
    $scorm->width  = (int)str_replace('%', '', $scorm->width);
    $scorm->height = (int)str_replace('%', '', $scorm->height);

    if (!isset($scorm->whatgrade)) {
        $scorm->whatgrade = 0;
    }
    $scorm->grademethod = ($scorm->whatgrade * 10) + $scorm->grademethod;

    $id = $DB->insert_record('scorm', $scorm);

/// update course module record - from now on this instance properly exists and all function may be used
    $DB->set_field('course_modules', 'instance', $id, array('id'=>$cmid));

/// reload scorm instance
    $scorm = $DB->get_record('scorm', array('id'=>$id));

/// store the package and verify
    if ($scorm->scormtype === SCORM_TYPE_LOCAL) {
        if ($mform) {
            $filename = $mform->get_new_filename('packagefile');
            if ($filename !== false) {
                $fs = get_file_storage();
                $fs->delete_area_files($context->id, 'scorm_package');
                $mform->save_stored_file('packagefile', $context->id, 'scorm_package', 0, '/', $filename);
                $scorm->reference = $filename;
            }
        }

    } else if ($scorm->scormtype === SCORM_TYPE_LOCALSYNC) {
        $scorm->reference = $scorm->packageurl;

    } else if ($scorm->scormtype === SCORM_TYPE_EXTERNAL) {
        $scorm->reference = $scorm->packageurl;

    } else if ($scorm->scormtype === SCORM_TYPE_IMSREPOSITORY) {
        $scorm->reference = $scorm->packageurl;

    } else {
        return false;
    }

    // save reference
    $DB->update_record('scorm', $scorm);


/// extra fields required in grade related functions
    $scorm->course     = $courseid;
    $scorm->cmidnumber = $cmidnumber;
    $scorm->cmid       = $cmid;

    scorm_parse($scorm, true);

    scorm_grade_item_update($scorm);

    return $scorm->id;
}

/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will update an existing instance with new data.
 *
 * @global stdClass
 * @global object
 * @uses CONTEXT_MODULE
 * @uses SCORM_TYPE_LOCAL
 * @uses SCORM_TYPE_LOCALSYNC
 * @uses SCORM_TYPE_EXTERNAL
 * @uses SCORM_TYPE_IMSREPOSITORY
 * @param object $scorm Form data
 * @param object $mform
 * @return bool
 */
function scorm_update_instance($scorm, $mform=null) {
    global $CFG, $DB;

    require_once('locallib.php');

    if (empty($scorm->timerestrict)) {
        $scorm->timeopen = 0;
        $scorm->timeclose = 0;
    }

    $cmid       = $scorm->coursemodule;
    $cmidnumber = $scorm->cmidnumber;
    $courseid   = $scorm->course;

    $scorm->id = $scorm->instance;

    $context = get_context_instance(CONTEXT_MODULE, $cmid);

    if ($scorm->scormtype === SCORM_TYPE_LOCAL) {
        if ($mform) {
            $filename = $mform->get_new_filename('packagefile');
            if ($filename !== false) {
                $scorm->reference = $filename;
                $fs = get_file_storage();
                $fs->delete_area_files($context->id, 'scorm_package');
                $mform->save_stored_file('packagefile', $context->id, 'scorm_package', 0, '/', $filename);
            }
        }

    } else if ($scorm->scormtype === SCORM_TYPE_LOCALSYNC) {
        $scorm->reference = $scorm->packageurl;

    } else if ($scorm->scormtype === SCORM_TYPE_EXTERNAL) {
        $scorm->reference = $scorm->packageurl;

    } else if ($scorm->scormtype === SCORM_TYPE_IMSREPOSITORY) {
        $scorm->reference = $scorm->packageurl;

    } else {
        return false;
    }

    $scorm = scorm_option2text($scorm);
    $scorm->width        = (int)str_replace('%','',$scorm->width);
    $scorm->height       = (int)str_replace('%','',$scorm->height);
    $scorm->timemodified = time();

    if (!isset($scorm->whatgrade)) {
        $scorm->whatgrade = 0;
    }
    $scorm->grademethod  = ($scorm->whatgrade * 10) + $scorm->grademethod;

    $DB->update_record('scorm', $scorm);

    $scorm = $DB->get_record('scorm', array('id'=>$scorm->id));

/// extra fields required in grade related functions
    $scorm->course   = $courseid;
    $scorm->idnumber = $cmidnumber;
    $scorm->cmid     = $cmid;

    scorm_parse($scorm, (bool)$scorm->updatefreq);

    scorm_grade_item_update($scorm);

    return true;
}

/**
 * Given an ID of an instance of this module,
 * this function will permanently delete the instance
 * and any data that depends on it.
 *
 * @global stdClass
 * @global object
 * @param int $id Scorm instance id
 * @return boolean
 */
function scorm_delete_instance($id) {
    global $CFG, $DB;

    if (! $scorm = $DB->get_record('scorm', array('id'=>$id))) {
        return false;
    }

    $result = true;

    // Delete any dependent records
    if (! $DB->delete_records('scorm_scoes_track', array('scormid'=>$scorm->id))) {
        $result = false;
    }
    if ($scoes = $DB->get_records('scorm_scoes', array('scorm'=>$scorm->id))) {
        foreach ($scoes as $sco) {
            if (! $DB->delete_records('scorm_scoes_data', array('scoid'=>$sco->id))) {
                $result = false;
            }
        }
        $DB->delete_records('scorm_scoes', array('scorm'=>$scorm->id));
    } else {
        $result = false;
    }
    if (! $DB->delete_records('scorm', array('id'=>$scorm->id))) {
        $result = false;
    }

    /*if (! $DB->delete_records('scorm_sequencing_controlmode', array('scormid'=>$scorm->id))) {
        $result = false;
    }
    if (! $DB->delete_records('scorm_sequencing_rolluprules', array('scormid'=>$scorm->id))) {
        $result = false;
    }
    if (! $DB->delete_records('scorm_sequencing_rolluprule', array('scormid'=>$scorm->id))) {
        $result = false;
    }
    if (! $DB->delete_records('scorm_sequencing_rollupruleconditions', array('scormid'=>$scorm->id))) {
        $result = false;
    }
    if (! $DB->delete_records('scorm_sequencing_rolluprulecondition', array('scormid'=>$scorm->id))) {
        $result = false;
    }
    if (! $DB->delete_records('scorm_sequencing_rulecondition', array('scormid'=>$scorm->id))) {
        $result = false;
    }
    if (! $DB->delete_records('scorm_sequencing_ruleconditions', array('scormid'=>$scorm->id))) {
        $result = false;
    }*/

    scorm_grade_item_delete($scorm);

    return $result;
}

/**
 * Return a small object with summary information about what a
 * user has done with a given particular instance of this module
 * Used for user activity reports.
 *
 * @global stdClass
 * @param int $course Course id
 * @param int $user User id
 * @param int $mod
 * @param int $scorm The scorm id
 * @return mixed
 */
function scorm_user_outline($course, $user, $mod, $scorm) {
    global $CFG;
    require_once('locallib.php');

    $return = scorm_grade_user($scorm, $user->id, true);

    return $return;
}

/**
 * Print a detailed representation of what a user has done with
 * a given particular instance of this module, for user activity reports.
 *
 * @global stdClass
 * @global object
 * @param object $course
 * @param object $user
 * @param object $mod
 * @param object $scorm
 * @return boolean
 */
function scorm_user_complete($course, $user, $mod, $scorm) {
    global $CFG, $DB;

    $liststyle = 'structlist';
    $scormpixdir = $CFG->modpixpath.'/scorm/pix';
    $now = time();
    $firstmodify = $now;
    $lastmodify = 0;
    $sometoreport = false;
    $report = '';

    if ($orgs = $DB->get_records('scorm_scoes', array('scorm'=>$scorm->id, 'organization'=>'', 'launch'=>''),'id','id,identifier,title')) {
        if (count($orgs) <= 1) {
            unset($orgs);
            $orgs[]->identifier = '';
        }
        $report .= '<div class="mod-scorm">'."\n";
        foreach ($orgs as $org) {
            $conditions = array();
            $currentorg = '';
            if (!empty($org->identifier)) {
                $report .= '<div class="orgtitle">'.$org->title.'</div>';
                $currentorg = $org->identifier;
                $conditions['organization'] = $currentorg;
            }
            $report .= "<ul id='0' class='$liststyle'>";
                $conditions['scorm'] = $scorm->id;
            if ($scoes = $DB->get_records('scorm_scoes', $conditions, "id ASC")){
                // drop keys so that we can access array sequentially
                $scoes = array_values($scoes);
                $level=0;
                $sublist=1;
                $parents[$level]='/';
                foreach ($scoes as $pos=>$sco) {
                    if ($parents[$level]!=$sco->parent) {
                        if ($level>0 && $parents[$level-1]==$sco->parent) {
                            $report .= "\t\t</ul></li>\n";
                            $level--;
                        } else {
                            $i = $level;
                            $closelist = '';
                            while (($i > 0) && ($parents[$level] != $sco->parent)) {
                                $closelist .= "\t\t</ul></li>\n";
                                $i--;
                            }
                            if (($i == 0) && ($sco->parent != $currentorg)) {
                                $report .= "\t\t<li><ul id='$sublist' class='$liststyle'>\n";
                                $level++;
                            } else {
                                $report .= $closelist;
                                $level = $i;
                            }
                            $parents[$level]=$sco->parent;
                        }
                    }
                    $report .= "\t\t<li>";
                    if (isset($scoes[$pos+1])) {
                        $nextsco = $scoes[$pos+1];
                    } else {
                        $nextsco = false;
                    }
                    if (($nextsco !== false) && ($sco->parent != $nextsco->parent) && (($level==0) || (($level>0) && ($nextsco->parent == $sco->identifier)))) {
                        $sublist++;
                    } else {
                        $report .= '<img src="'.$scormpixdir.'/spacer.gif" alt="" />';
                    }

                    if ($sco->launch) {
                        require_once('locallib.php');
                        $score = '';
                        $totaltime = '';
                        if ($usertrack=scorm_get_tracks($sco->id,$user->id)) {
                            if ($usertrack->status == '') {
                                $usertrack->status = 'notattempted';
                            }
                            $strstatus = get_string($usertrack->status,'scorm');
                            $report .= "<img src='".$scormpixdir.'/'.$usertrack->status.".gif' alt='$strstatus' title='$strstatus' />";
                            if ($usertrack->timemodified != 0) {
                                if ($usertrack->timemodified > $lastmodify) {
                                    $lastmodify = $usertrack->timemodified;
                                }
                                if ($usertrack->timemodified < $firstmodify) {
                                    $firstmodify = $usertrack->timemodified;
                                }
                            }
                        } else {
                            if ($sco->scormtype == 'sco') {
                                $report .= '<img src="'.$scormpixdir.'/'.'notattempted.gif" alt="'.get_string('notattempted','scorm').'" title="'.get_string('notattempted','scorm').'" />';
                            } else {
                                $report .= '<img src="'.$scormpixdir.'/'.'asset.gif" alt="'.get_string('asset','scorm').'" title="'.get_string('asset','scorm').'" />';
                            }
                        }
                        $report .= "&nbsp;$sco->title $score$totaltime</li>\n";
                        if ($usertrack !== false) {
                            $sometoreport = true;
                            $report .= "\t\t\t<li><ul class='$liststyle'>\n";
                            foreach($usertrack as $element => $value) {
                                if (substr($element,0,3) == 'cmi') {
                                    $report .= '<li>'.$element.' => '.$value.'</li>';
                                }
                            }
                            $report .= "\t\t\t</ul></li>\n";
                        }
                    } else {
                        $report .= "&nbsp;$sco->title</li>\n";
                    }
                }
                for ($i=0;$i<$level;$i++) {
                    $report .= "\t\t</ul></li>\n";
                }
            }
            $report .= "\t</ul><br />\n";
        }
        $report .= "</div>\n";
    }
    if ($sometoreport) {
        if ($firstmodify < $now) {
            $timeago = format_time($now - $firstmodify);
            echo get_string('firstaccess','scorm').': '.userdate($firstmodify).' ('.$timeago.")<br />\n";
        }
        if ($lastmodify > 0) {
            $timeago = format_time($now - $lastmodify);
            echo get_string('lastaccess','scorm').': '.userdate($lastmodify).' ('.$timeago.")<br />\n";
        }
        echo get_string('report','scorm').":<br />\n";
        echo $report;
    } else {
        print_string('noactivity','scorm');
    }

    return true;
}

/**
 * Function to be run periodically according to the moodle cron
 * This function searches for things that need to be done, such
 * as sending out mail, toggling flags etc ...
 *
 * @global stdClass
 * @global object
 * @return boolean
 */
function scorm_cron () {
    global $CFG, $DB;

    require_once('locallib.php');

    $sitetimezone = $CFG->timezone;
    /// Now see if there are any scorm updates to be done

    if (!isset($CFG->scorm_updatetimelast)) {    // To catch the first time
        set_config('scorm_updatetimelast', 0);
    }

    $timenow = time();
    $updatetime = usergetmidnight($timenow, $sitetimezone) + ($CFG->scorm_updatetimelast * 3600);

    if ($CFG->scorm_updatetimelast < $updatetime and $timenow > $updatetime) {

        set_config('scorm_updatetimelast', $timenow);

        mtrace('Updating scorm packages which require daily update');//We are updating

        $scormsupdate = $DB->get_records('scorm', array('updatefreq'=>UPDATE_EVERYDAY));
        foreach($scormsupdate as $scormupdate) {
            scorm_parse($scormupdate, true);
        }
    }

    return true;
}

/**
 * Return grade for given user or all users.
 *
 * @global stdClass
 * @global object
 * @param int $scormid id of scorm
 * @param int $userid optional user id, 0 means all users
 * @return array array of grades, false if none
 */
function scorm_get_user_grades($scorm, $userid=0) {
    global $CFG, $DB;
    require_once('locallib.php');

    $grades = array();
    if (empty($userid)) {
        if ($scousers = $DB->get_records_select('scorm_scoes_track', "scormid=? GROUP BY userid", array($scorm->id), "", "userid,null")) {
            foreach ($scousers as $scouser) {
                $grades[$scouser->userid] = new object();
                $grades[$scouser->userid]->id         = $scouser->userid;
                $grades[$scouser->userid]->userid     = $scouser->userid;
                $grades[$scouser->userid]->rawgrade = scorm_grade_user($scorm, $scouser->userid);
            }
        } else {
            return false;
        }

    } else {
        if (!$DB->get_records_select('scorm_scoes_track', "scormid=? AND userid=? GROUP BY userid", array($scorm->id, $userid), "", "userid,null")) {
            return false; //no attempt yet
        }
        $grades[$userid] = new object();
        $grades[$userid]->id         = $userid;
        $grades[$userid]->userid     = $userid;
        $grades[$userid]->rawgrade = scorm_grade_user($scorm, $userid);
    }

    return $grades;
}

/**
 * Update grades in central gradebook
 *
 * @global stdClass
 * @global object
 * @param object $scorm
 * @param int $userid specific user only, 0 mean all
 * @param bool $nullifnone
 */
function scorm_update_grades($scorm, $userid=0, $nullifnone=true) {
    global $CFG, $DB;
    require_once($CFG->libdir.'/gradelib.php');

    if ($grades = scorm_get_user_grades($scorm, $userid)) {
        scorm_grade_item_update($scorm, $grades);

    } else if ($userid and $nullifnone) {
        $grade = new object();
        $grade->userid   = $userid;
        $grade->rawgrade = NULL;
        scorm_grade_item_update($scorm, $grade);

    } else {
        scorm_grade_item_update($scorm);
    }
}

/**
 * Update all grades in gradebook.
 *
 * @global object
 */
function scorm_upgrade_grades() {
    global $DB;

    $sql = "SELECT COUNT('x')
              FROM {scorm} s, {course_modules} cm, {modules} m
             WHERE m.name='scorm' AND m.id=cm.module AND cm.instance=s.id";
    $count = $DB->count_records_sql($sql);

    $sql = "SELECT s.*, cm.idnumber AS cmidnumber, s.course AS courseid
              FROM {scorm} s, {course_modules} cm, {modules} m
             WHERE m.name='scorm' AND m.id=cm.module AND cm.instance=s.id";
    if ($rs = $DB->get_recordset_sql($sql)) {
        $pbar = new progress_bar('scormupgradegrades', 500, true);
        $i=0;
        foreach ($rs as $scorm) {
            $i++;
            upgrade_set_timeout(60*5); // set up timeout, may also abort execution
            scorm_update_grades($scorm, 0, false);
            $pbar->update($i, $count, "Updating Scorm grades ($i/$count).");
        }
        $rs->close();
    }
}

/**
 * Update/create grade item for given scorm
 *
 * @global stdClass
 * @global object
 * @uses GRADE_TYPE_VALUE
 * @uses GRADE_TYPE_NONE
 * @param object $scorm object with extra cmidnumber
 * @param mixed $grades optional array/object of grade(s); 'reset' means reset grades in gradebook
 * @return object grade_item
 */
function scorm_grade_item_update($scorm, $grades=NULL) {
    global $CFG, $DB;
    if (!function_exists('grade_update')) { //workaround for buggy PHP versions
        require_once($CFG->libdir.'/gradelib.php');
    }

    $params = array('itemname'=>$scorm->name);
    if (isset($scorm->cmidnumber)) {
        $params['idnumber'] = $scorm->cmidnumber;
    }

    if (($scorm->grademethod % 10) == 0) { // GRADESCOES
        if ($maxgrade = $DB->count_records_select('scorm_scoes', 'scorm = ? AND launch <> ?', array($scorm->id, $DB->sql_empty()))) {
            $params['gradetype'] = GRADE_TYPE_VALUE;
            $params['grademax']  = $maxgrade;
            $params['grademin']  = 0;
        } else {
            $params['gradetype'] = GRADE_TYPE_NONE;
        }
    } else {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax']  = $scorm->maxgrade;
        $params['grademin']  = 0;
    }

    if ($grades  === 'reset') {
        $params['reset'] = true;
        $grades = NULL;
    }

    return grade_update('mod/scorm', $scorm->course, 'mod', 'scorm', $scorm->id, 0, $grades, $params);
}

/**
 * Delete grade item for given scorm
 *
 * @global stdClass
 * @param object $scorm object
 * @return object grade_item
 */
function scorm_grade_item_delete($scorm) {
    global $CFG;
    require_once($CFG->libdir.'/gradelib.php');

    return grade_update('mod/scorm', $scorm->course, 'mod', 'scorm', $scorm->id, 0, NULL, array('deleted'=>1));
}

/**
 * @return array
 */
function scorm_get_view_actions() {
    return array('pre-view','view','view all','report');
}

/**
 * @return array
 */
function scorm_get_post_actions() {
    return array();
}

/**
 * @param object $scorm
 * @return object $scorm
 */
function scorm_option2text($scorm) {
    $scorm_popoup_options = scorm_get_popup_options_array();
    
    if (isset($scorm->popup)) {
        if ($scorm->popup == 1) {
            $optionlist = array();
            foreach ($scorm_popoup_options as $name => $option) {
                if (isset($scorm->$name)) {
                    $optionlist[] = $name.'='.$scorm->$name;
                } else {
                    $optionlist[] = $name.'=0';
                }
            }
            $scorm->options = implode(',', $optionlist);
        } else {
            $scorm->options = '';
        }
    } else {
        $scorm->popup = 0;
        $scorm->options = '';
    }
    return $scorm;
}

/**
 * Implementation of the function for printing the form elements that control
 * whether the course reset functionality affects the scorm.
 * 
 * @param object $mform form passed by reference
 */
function scorm_reset_course_form_definition(&$mform) {
    $mform->addElement('header', 'scormheader', get_string('modulenameplural', 'scorm'));
    $mform->addElement('advcheckbox', 'reset_scorm', get_string('deleteallattempts','scorm'));
}

/**
 * Course reset form defaults.
 *
 * @return array
 */
function scorm_reset_course_form_defaults($course) {
    return array('reset_scorm'=>1);
}

/**
 * Removes all grades from gradebook
 *
 * @global stdClass
 * @global object
 * @param int $courseid
 * @param string optional type
 */
function scorm_reset_gradebook($courseid, $type='') {
    global $CFG, $DB;

    $sql = "SELECT s.*, cm.idnumber as cmidnumber, s.course as courseid
              FROM {scorm} s, {course_modules} cm, {modules} m
             WHERE m.name='scorm' AND m.id=cm.module AND cm.instance=s.id AND s.course=?";

    if ($scorms = $DB->get_records_sql($sql, array($courseid))) {
        foreach ($scorms as $scorm) {
            scorm_grade_item_update($scorm, 'reset');
        }
    }
}

/**
 * Actual implementation of the rest coures functionality, delete all the
 * scorm attempts for course $data->courseid.
 *
 * @global stdClass
 * @global object
 * @param object $data the data submitted from the reset course.
 * @return array status array
 */
function scorm_reset_userdata($data) {
    global $CFG, $DB;

    $componentstr = get_string('modulenameplural', 'scorm');
    $status = array();

    if (!empty($data->reset_scorm)) {
        $scormssql = "SELECT s.id
                         FROM {scorm} s
                        WHERE s.course=?";

        $DB->delete_records_select('scorm_scoes_track', "scormid IN ($scormssql)", array($data->courseid));

        // remove all grades from gradebook
        if (empty($data->reset_gradebook_grades)) {
            scorm_reset_gradebook($data->courseid);
        }

        $status[] = array('component'=>$componentstr, 'item'=>get_string('deleteallattempts', 'scorm'), 'error'=>false);
    }

    // no dates to shift here

    return $status;
}

/**
 * Returns all other caps used in module
 *
 * @return array
 */
function scorm_get_extra_capabilities() {
    return array('moodle/site:accessallgroups');
}

/**
 * Lists all file areas current user may browse
 *
 * @param object $course
 * @param object $cm
 * @param object $context
 * @return array
 */
function scorm_get_file_areas($course, $cm, $context) {
    $areas = array();
    if (has_capability('moodle/course:managefiles', $context)) {
        $areas['scorm_content'] = get_string('areacontent', 'scorm');
        $areas['scorm_package'] = get_string('areapackage', 'scorm');
    }
    return $areas;
}

/**
 * File browsing support
 * 
 * @todo Document this function
 */
function scorm_get_file_info($browser, $areas, $course, $cm, $context, $filearea, $itemid, $filepath, $filename) {
    global $CFG;

    if (!has_capability('moodle/course:managefiles', $context)) {
        return null;
    }

    // no writing for now!

    $fs = get_file_storage();

    if ($filearea === 'scorm_content') {

        $filepath = is_null($filepath) ? '/' : $filepath;
        $filename = is_null($filename) ? '.' : $filename;
    
        $urlbase = $CFG->wwwroot.'/pluginfile.php';
        if (!$storedfile = $fs->get_file($context->id, $filearea, 0, $filepath, $filename)) {
            if ($filepath === '/' and $filename === '.') {
                $storedfile = new virtual_root_file($context->id, $filearea, 0);
            } else {
                // not found
                return null;
            }
        }
        /**
         * @package   mod-scorm
         * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
         * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
         */
        class scorm_package_file_info extends file_info_stored {
            public function get_parent() {
                if ($this->lf->get_filepath() === '/' and $this->lf->get_filename() === '.') {
                    return $this->browser->get_file_info($this->context);
                }
                return parent::get_parent();
            }
            public function get_visible_name() {
                if ($this->lf->get_filepath() === '/' and $this->lf->get_filename() === '.') {
                    return $this->topvisiblename;
                }
                return parent::get_visible_name();
            }
        }
        return new scorm_package_file_info($browser, $context, $storedfile, $urlbase, $areas[$filearea], true, true, false, false);

    } else if ($filearea === 'scorm_package') {
        $filepath = is_null($filepath) ? '/' : $filepath;
        $filename = is_null($filename) ? '.' : $filename;
    
        $urlbase = $CFG->wwwroot.'/pluginfile.php';
        if (!$storedfile = $fs->get_file($context->id, $filearea, 0, $filepath, $filename)) {
            if ($filepath === '/' and $filename === '.') {
                $storedfile = new virtual_root_file($context->id, $filearea, 0);
            } else {
                // not found
                return null;
            }
        }
        return new file_info_stored($browser, $context, $storedfile, $urlbase, $areas[$filearea], false, true, false);
    }

    // scorm_intro handled in file_browser

    return false;
}

/**
 * Serves scorm content, introduction images and packages. Implements needed access control ;-)
 *
 * @global stdClass
 * @param object $course
 * @param object $cminfo
 * @param object $context
 * @param string $filearea
 * @param array $args
 * @return bool
 */
function scorm_pluginfile($course, $cminfo, $context, $filearea, $args) {
    global $CFG;

    if (!$cminfo->uservisible) {
        return false; // probably hidden
    }

    $lifetime = isset($CFG->filelifetime) ? $CFG->filelifetime : 86400;

    if ($filearea === 'scorm_content') {
        $revision = (int)array_shift($args); // prevents caching problems - ignored here
        $relativepath = '/'.implode('/', $args);
        $fullpath = $context->id.'scorm_content0'.$relativepath;
        // TODO: add any other access restrictions here if needed!

    } else if ($filearea === 'scorm_package') {
        if (!has_capability('moodle/course:manageactivities', $context)) {
            return false;
        }
        $relativepath = '/'.implode('/', $args);
        $fullpath = $context->id.'scorm_package0'.$relativepath;
        $lifetime = 0; // no caching here

    } else {
        return false;
    }

    $fs = get_file_storage();
    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
        return false;
    }

    // finally send the file
    send_stored_file($file, $lifetime, 0, false);
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
 * @return mixed True if module supports feature, false if not, null if doesn't know
 */
function scorm_supports($feature) {
    switch($feature) {
        case FEATURE_GROUPS:                  return false;
        case FEATURE_GROUPINGS:               return false;
        case FEATURE_GROUPMEMBERSONLY:        return true;
        case FEATURE_MOD_INTRO:               return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS: return true;
        case FEATURE_GRADE_HAS_GRADE:         return true;
        case FEATURE_GRADE_OUTCOMES:          return true;

        default: return null;
    }
}
