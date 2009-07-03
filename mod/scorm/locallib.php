<?php  // $Id$

require_once("$CFG->dirroot/mod/scorm/lib.php");

/// Constants and settings for module scorm
define('UPDATE_NEVER', '0');
define('UPDATE_ONCHANGE', '1');
define('UPDATE_EVERYDAY', '2');
define('UPDATE_EVERYTIME', '3');

define('SCO_ALL', 0);
define('SCO_DATA', 1);
define('SCO_ONLY', 2);

define('GRADESCOES', '0');
define('GRADEHIGHEST', '1');
define('GRADEAVERAGE', '2');
define('GRADESUM', '3');

define('HIGHESTATTEMPT', '0');
define('AVERAGEATTEMPT', '1');
define('FIRSTATTEMPT', '2');
define('LASTATTEMPT', '3');

/// Local Library of functions for module scorm

/**
 * Returns an array of the popup options for SCORM and each options default value
 *
 * @return array an array of popup options as the key and their defaults as the value
 */
function scorm_get_popup_options_array(){
    global $CFG;
    $cfg_scorm = get_config('scorm');

    return array('resizable'=> isset($cfg_scorm->resizable) ? $cfg_scorm->resizable : 0,
                 'scrollbars'=> isset($cfg_scorm->scrollbars) ? $cfg_scorm->scrollbars : 0,
                 'directories'=> isset($cfg_scorm->directories) ? $cfg_scorm->directories : 0,
                 'location'=> isset($cfg_scorm->location) ? $cfg_scorm->location : 0,
                 'menubar'=> isset($cfg_scorm->menubar) ? $cfg_scorm->menubar : 0,
                 'toolbar'=> isset($cfg_scorm->toolbar) ? $cfg_scorm->toolbar : 0,
                 'status'=> isset($cfg_scorm->status) ? $cfg_scorm->status : 0);
}

/**
 * Returns an array of the array of what grade options
 *
 * @return array an array of what grade options
 */
function scorm_get_grade_method_array(){
    return array (GRADESCOES => get_string('gradescoes', 'scorm'),
                  GRADEHIGHEST => get_string('gradehighest', 'scorm'),
                  GRADEAVERAGE => get_string('gradeaverage', 'scorm'),
                  GRADESUM => get_string('gradesum', 'scorm'));
}

/**
 * Returns an array of the array of what grade options
 *
 * @return array an array of what grade options
 */
function scorm_get_what_grade_array(){
    return array (HIGHESTATTEMPT => get_string('highestattempt', 'scorm'),
                  AVERAGEATTEMPT => get_string('averageattempt', 'scorm'),
                  FIRSTATTEMPT => get_string('firstattempt', 'scorm'),
                  LASTATTEMPT => get_string('lastattempt', 'scorm'));
}

/**
 * Returns an array of the array of skip view options
 *
 * @return array an array of skip view options
 */
function scorm_get_skip_view_array(){
   return array(0 => get_string('never'),
                 1 => get_string('firstaccess','scorm'),
                 2 => get_string('always'));
}

/**
 * Returns an array of the array of hide table of contents options
 *
 * @return array an array of hide table of contents options
 */
function scorm_get_hidetoc_array(){
     return array(0 =>get_string('sided','scorm'),
                  1 => get_string('hidden','scorm'),
                  2 => get_string('popupmenu','scorm'));
}

/**
 * Returns an array of the array of update frequency options
 *
 * @return array an array of update frequency options
 */
function scorm_get_updatefreq_array(){
    return array(0 => get_string('never'),
                 1 => get_string('onchanges','scorm'),
                 2 => get_string('everyday','scorm'),
                 3 => get_string('everytime','scorm'));
}

/**
 * Returns an array of the array of popup display options
 *
 * @return array an array of popup display options
 */
function scorm_get_popup_display_array(){
    return array(0 => get_string('iframe', 'scorm'),
                 1 => get_string('popup', 'scorm'));
}

/**
 * Returns an array of the array of attempt options
 *
 * @return array an array of attempt options
 */
function scorm_get_attempts_array(){
    $attempts = array(0 => get_string('nolimit','scorm'),
                      1 => get_string('attempt1','scorm'));

    for ($i=2; $i<=6; $i++) {
        $attempts[$i] = get_string('attemptsx','scorm', $i);
    }

    return $attempts;
}
/**
 * Extracts scrom package, sets up all variables.
 * Called whenever scorm changes
 * @param object $scorm instance - fields are updated and changes saved into database
 * @param bool $full force full update if true
 * @return void
 */
function scorm_parse($scorm, $full) {
    global $CFG, $DB;
    $cfg_scorm = get_config('scorm');

    if (!isset($scorm->cmid)) {
        $cm = get_coursemodule_from_instance('scorm', $scorm->id);
        $scorm->cmid = $cm->id;
    }
    $context = get_context_instance(CONTEXT_MODULE, $scorm->cmid);
    $newhash = $scorm->sha1hash;

    if ($scorm->scormtype === SCORM_TYPE_LOCAL or $scorm->scormtype === SCORM_TYPE_LOCALSYNC) {

        $fs = get_file_storage();
        $packagefile = false;

        if ($scorm->scormtype === SCORM_TYPE_LOCAL) {
            if ($packagefile = $fs->get_file($context->id, 'scorm_package', 0, '/', $scorm->reference)) {
                $newhash = $packagefile->get_contenthash();
            } else {
                $newhash = null;
            }
        } else {
            if (!$cfg_scorm->allowtypelocalsync) {
                // sorry - localsync disabled
                return;
            }
            if ($scorm->reference !== '' and (!$full or $scorm->sha1hash !== sha1($scorm->reference))) {
                $fs->delete_area_files($context->id, 'scorm_package');
                $file_record = array('contextid'=>$context->id, 'filearea'=>'scorm_package', 'itemid'=>0, 'filepath'=>'/');
                if ($packagefile = $fs->create_file_from_url($file_record, $scorm->reference)) {
                    $newhash = sha1($scorm->reference);
                } else {
                    $newhash = null;
                }
            }
        }

        if ($packagefile) {
            if (!$full and $packagefile and $scorm->sha1hash === $newhash) {
                if (strpos($scorm->version, 'SCORM') !== false) {
                    if ($fs->get_file($context->id, 'scorm_content', 0, '/', 'imsmanifest.xml')) {
                        // no need to update
                        return;
                    }
                } else if (strpos($scorm->version, 'AICC') !== false) {
                    // TODO: add more sanity checks - something really exists in scorm_content area
                    return;
                }
            }

            // now extract files
            $fs->delete_area_files($context->id, 'scorm_content');

            $packer = get_file_packer('application/zip');
            $packagefile->extract_to_storage($packer, $context->id, 'scorm_content', 0, '/');

        } else if (!$full) {
            return;
        }


        if ($manifest = $fs->get_file($context->id, 'scorm_content', 0, '/', 'imsmanifest.xml')) {
            require_once("$CFG->dirroot/mod/scorm/datamodels/scormlib.php");
            // SCORM
            if (!scorm_parse_scorm($scorm, $manifest)) {
                $scorm->version = 'ERROR';
            }
        } else {
            require_once("$CFG->dirroot/mod/scorm/datamodels/aicclib.php");
            // AICC
            if (!scorm_parse_aicc($scorm)) {
                $scorm->version = 'ERROR';
            }
        }

    } else if ($scorm->scormtype === SCORM_TYPE_EXTERNAL and $cfg_scorm->allowtypeexternal) {
        if (!$full and $scorm->sha1hash === sha1($scorm->reference)) {
            return;
        }
        require_once("$CFG->dirroot/mod/scorm/datamodels/scormlib.php");
        // SCORM only, AICC can not be external
        if (!scorm_parse_scorm($scorm, $scorm->reference)) {
            $scorm->version = 'ERROR';
        }
        $newhash = sha1($scorm->reference);

    } else if ($scorm->scormtype === SCORM_TYPE_IMSREPOSITORY and !empty($CFG->repositoryactivate) and $cfg_scorm->allowtypeimsrepository) {
        if (!$full and $scorm->sha1hash === sha1($scorm->reference)) {
            return;
        }
        require_once("$CFG->dirroot/mod/scorm/datamodels/scormlib.php");
        if (!scorm_parse_scorm($scorm, $CFG->repository.substr($scorm->reference,1).'/imsmanifest.xml')) {
            $scorm->version = 'ERROR';
        }
        $newhash = sha1($scorm->reference);

    } else {
        // sorry, disabled type
        return;
    }

    $scorm->revision++;
    $scorm->sha1hash = $newhash;
    $DB->update_record('scorm', $scorm);
}


function scorm_array_search($item, $needle, $haystacks, $strict=false) {
    if (!empty($haystacks)) {
        foreach ($haystacks as $key => $element) {
            if ($strict) {
                if ($element->{$item} === $needle) {
                    return $key;
                }
            } else {
                if ($element->{$item} == $needle) {
                    return $key;
                }
            }
        }
    }
    return false;
}

function scorm_repeater($what, $times) {
    if ($times <= 0) {
        return null;
    }
    $return = '';
    for ($i=0; $i<$times;$i++) {
        $return .= $what;
    }
    return $return;
}

function scorm_external_link($link) {
// check if a link is external
    $result = false;
    $link = strtolower($link);
    if (substr($link,0,7) == 'http://') {
        $result = true;
    } else if (substr($link,0,8) == 'https://') {
        $result = true;
    } else if (substr($link,0,4) == 'www.') {
        $result = true;
    }
    return $result;
}

/**
* Returns an object containing all datas relative to the given sco ID
*
* @param integer $id The sco ID
* @return mixed (false if sco id does not exists)
*/

function scorm_get_sco($id,$what=SCO_ALL) {
    global $DB;

    if ($sco = $DB->get_record('scorm_scoes', array('id'=>$id))) {
        $sco = ($what == SCO_DATA) ? new stdClass() : $sco;
        if (($what != SCO_ONLY) && ($scodatas = $DB->get_records('scorm_scoes_data', array('scoid'=>$id)))) {
            foreach ($scodatas as $scodata) {
                $sco->{$scodata->name} = $scodata->value;
            }
        } else if (($what != SCO_ONLY) && (!($scodatas = $DB->get_records('scorm_scoes_data', array('scoid'=>$id))))) {
            $sco->parameters = '';
        }
        return $sco;
    } else {
        return false;
    }
}

/**
* Returns an object (array) containing all the scoes data related to the given sco ID
*
* @param integer $id The sco ID
* @param integer $organisation an organisation ID - defaults to false if not required
* @return mixed (false if there are no scoes or an array)
*/

function scorm_get_scoes($id,$organisation=false) {
    global $DB;

    $organizationsql = '';
    $queryarray = array('scorm'=>$id);
    if (!empty($organisation)) {
        $queryarray['organization'] = $organisation;
    }
    if ($scoes = $DB->get_records('scorm_scoes', $queryarray, 'id ASC')) {
        // drop keys so that it is a simple array as expected
        $scoes = array_values($scoes);
        foreach ($scoes as $sco) {
            if ($scodatas = $DB->get_records('scorm_scoes_data',array('scoid'=>$sco->id))) {
                foreach ($scodatas as $scodata) {
                    $sco->{$scodata->name} = $scodata->value;
                }
            }
        }
        return $scoes;
    } else {
        return false;
    }
}

function scorm_insert_track($userid,$scormid,$scoid,$attempt,$element,$value,$forcecompleted=false) {
    global $DB;

    $id = null;

    if ($forcecompleted) {
        //TODO - this could be broadened to encompass SCORM 2004 in future
        if (($element == 'cmi.core.lesson_status') && ($value == 'incomplete')) {
            if ($track = $DB->get_record_select('scorm_scoes_track','userid=? AND scormid=? AND scoid=? AND attempt=? AND element=\'cmi.core.score.raw\'', array($userid, $scormid, $scoid, $attempt))) {
                $value = 'completed';
            }
        }
        if ($element == 'cmi.core.score.raw') {
            if ($tracktest = $DB->get_record_select('scorm_scoes_track','userid=? AND scormid=? AND scoid=? AND attempt=? AND element=\'cmi.core.lesson_status\'', array($userid, $scormid, $scoid, $attempt))) {
                if ($tracktest->value == "incomplete") {
                    $tracktest->value = "completed";
                    $idtest = $DB->update_record('scorm_scoes_track',$tracktest);
                }
            }
        }
    }

    if ($track = $DB->get_record('scorm_scoes_track',array('userid'=>$userid, 'scormid'=>$scormid, 'scoid'=>$scoid, 'attempt'=>$attempt, 'element'=>$element))) {
        $track->value = addslashes_js($value);
        $track->timemodified = time();
        $id = $DB->update_record('scorm_scoes_track',$track);
    } else {
        $track->userid = $userid;
        $track->scormid = $scormid;
        $track->scoid = $scoid;
        $track->attempt = $attempt;
        $track->element = $element;
        $track->value = addslashes_js($value);
        $track->timemodified = time();
        $id = $DB->insert_record('scorm_scoes_track',$track);
    }

    if (strstr($element, '.score.raw') ||
        (($element == 'cmi.core.lesson_status' || $element == 'cmi.completion_status') && ($track->value == 'completed' || $track->value == 'passed'))) {
        $scorm = $DB->get_record('scorm', array('id' => $scormid));
        $grademethod = $scorm->grademethod % 10;
        include_once('lib.php');
        scorm_update_grades($scorm, $userid);
    }

    return $id;
}

function scorm_get_tracks($scoid,$userid,$attempt='') {
/// Gets all tracks of specified sco and user
    global $CFG, $DB;

    if (empty($attempt)) {
        if ($scormid = $DB->get_field('scorm_scoes','scorm', array('id'=>$scoid))) {
            $attempt = scorm_get_last_attempt($scormid,$userid);
        } else {
            $attempt = 1;
        }
    }
    if ($tracks = $DB->get_records('scorm_scoes_track', array('userid'=>$userid, 'scoid'=>$scoid, 'attempt'=>$attempt),'element ASC')) {
        $usertrack->userid = $userid;
        $usertrack->scoid = $scoid;
        // Defined in order to unify scorm1.2 and scorm2004
        $usertrack->score_raw = '';
        $usertrack->status = '';
        $usertrack->total_time = '00:00:00';
        $usertrack->session_time = '00:00:00';
        $usertrack->timemodified = 0;
        foreach ($tracks as $track) {
            $element = $track->element;
            $track->value = stripslashes($track->value);
            $usertrack->{$element} = $track->value;
            switch ($element) {
                case 'x.start.time':
                    $usertrack->x_start_time = $track->value;
                    break;
                case 'cmi.core.lesson_status':
                case 'cmi.completion_status':
                    if ($track->value == 'not attempted') {
                        $track->value = 'notattempted';
                    }
                    $usertrack->status = $track->value;
                break;
                case 'cmi.core.score.raw':
                case 'cmi.score.raw':
                    $usertrack->score_raw = sprintf('%0d', $track->value);
                break;
                case 'cmi.core.session_time':
                case 'cmi.session_time':
                    $usertrack->session_time = $track->value;
                break;
                case 'cmi.core.total_time':
                case 'cmi.total_time':
                    $usertrack->total_time = $track->value;
                break;
            }
            if (isset($track->timemodified) && ($track->timemodified > $usertrack->timemodified)) {
                $usertrack->timemodified = $track->timemodified;
            }
        }
        if (is_array($usertrack)) {
            ksort($usertrack);
        }
        return $usertrack;
    } else {
        return false;
    }
}


/* Find the start and finsh time for a a given SCO attempt
 *
 * @param int $scormid SCORM Id
 * @param int $scoid SCO Id
 * @param int $userid User Id
 * @param int $attemt Attempt Id
 *
 * @return object start and finsh time EPOC secods
 *
 */
function scorm_get_sco_runtime($scormid, $scoid, $userid, $attempt=1) {
	global $DB;

    $timedata = new object();
    $sql = !empty($scoid) ? "userid=$userid AND scormid=$scormid AND scoid=$scoid AND attempt=$attempt" : "userid=$userid AND scormid=$scormid AND attempt=$attempt";
    $tracks = $DB->get_records_select('scorm_scoes_track',"$sql ORDER BY timemodified ASC");
    if ($tracks) {
        $tracks = array_values($tracks);
    }

    if ($start_track = $DB->get_records_select('scorm_scoes_track',"$sql AND element='x.start.time' ORDER BY scoid ASC")) {
        $start_track = array_values($start_track);
        $timedata->start = $start_track[0]->value;
    }
    else if ($tracks) {
        $timedata->start = $tracks[0]->timemodified;
    }
    else {
        $timedata->start = false;
    }
    if ($tracks && $track = array_pop($tracks)) {
        $timedata->finish = $track->timemodified;
    }
    else {
        $timedata->finish = $timedata->start;
    }
    return $timedata;
}


function scorm_get_user_data($userid) {
    global $DB;
/// Gets user info required to display the table of scorm results
/// for report.php

    return $DB->get_record('user', array('id'=>$userid),'firstname, lastname, picture');
}

function scorm_grade_user_attempt($scorm, $userid, $attempt=1, $time=false) {
    global $DB;
    $attemptscore = NULL;
    $attemptscore->scoes = 0;
    $attemptscore->values = 0;
    $attemptscore->max = 0;
    $attemptscore->sum = 0;
    $attemptscore->lastmodify = 0;

    if (!$scoes = $DB->get_records('scorm_scoes', array('scorm'=>$scorm->id))) {
        return NULL;
    }

    // this treatment is necessary as the whatgrade field was not in the DB
    // and so whatgrade and grademethod are combined in grademethod 10s are whatgrade
    // and 1s are grademethod
    $grademethod = $scorm->grademethod % 10;

    foreach ($scoes as $sco) {
        if ($userdata=scorm_get_tracks($sco->id, $userid,$attempt)) {
            if (($userdata->status == 'completed') || ($userdata->status == 'passed')) {
                $attemptscore->scoes++;
            }
            if (!empty($userdata->score_raw)) {
                $attemptscore->values++;
                $attemptscore->sum += $userdata->score_raw;
                $attemptscore->max = ($userdata->score_raw > $attemptscore->max)?$userdata->score_raw:$attemptscore->max;
                if (isset($userdata->timemodified) && ($userdata->timemodified > $attemptscore->lastmodify)) {
                    $attemptscore->lastmodify = $userdata->timemodified;
                } else {
                    $attemptscore->lastmodify = 0;
                }
            }
        }
    }
    switch ($grademethod) {
        case GRADEHIGHEST:
            $score = $attemptscore->max;
        break;
        case GRADEAVERAGE:
            if ($attemptscore->values > 0) {
                $score = $attemptscore->sum/$attemptscore->values;
            } else {
                $score = 0;
            }
        break;
        case GRADESUM:
            $score = $attemptscore->sum;
        break;
        case GRADESCOES:
            $score = $attemptscore->scoes;
        break;
        default:
            $score = $attemptscore->max;   // Remote Learner GRADEHIGHEST is default
    }

    if ($time) {
        $result = new stdClass();
        $result->score = $score;
        $result->time = $attemptscore->lastmodify;
    } else {
        $result = $score;
    }

    return $result;
}

function scorm_grade_user($scorm, $userid, $time=false) {
    // this treatment is necessary as the whatgrade field was not in the DB
    // and so whatgrade and grademethod are combined in grademethod 10s are whatgrade
    // and 1s are grademethod
    $whatgrade = intval($scorm->grademethod / 10);

    // insure we dont grade user beyond $scorm->maxattempt settings
    $lastattempt = scorm_get_last_attempt($scorm->id, $userid);
    if($scorm->maxattempt != 0 && $lastattempt >= $scorm->maxattempt){
        $lastattempt = $scorm->maxattempt;
    }

    switch ($whatgrade) {
        case FIRSTATTEMPT:
            return scorm_grade_user_attempt($scorm, $userid, 1, $time);
        break;
        case LASTATTEMPT:
            return scorm_grade_user_attempt($scorm, $userid, scorm_get_last_attempt($scorm->id, $userid), $time);
        break;
        case HIGHESTATTEMPT:
            $maxscore = 0;
            $attempttime = 0;
            for ($attempt = 1; $attempt <= $lastattempt; $attempt++) {
                $attemptscore = scorm_grade_user_attempt($scorm, $userid, $attempt, $time);
                if ($time) {
                    if ($attemptscore->score > $maxscore) {
                        $maxscore = $attemptscore->score;
                        $attempttime = $attemptscore->time;
                    }
                } else {
                    $maxscore = $attemptscore > $maxscore ? $attemptscore: $maxscore;
                }
            }
            if ($time) {
                $result = new stdClass();
                $result->score = $maxscore;
                $result->time = $attempttime;
                return $result;
            } else {
               return $maxscore;
            }
        break;
        case AVERAGEATTEMPT:
            $lastattempt = scorm_get_last_attempt($scorm->id, $userid);
            $sumscore = 0;
            for ($attempt = 1; $attempt <= $lastattempt; $attempt++) {
                $attemptscore = scorm_grade_user_attempt($scorm, $userid, $attempt, $time);
                if ($time) {
                    $sumscore += $attemptscore->score;
                } else {
                    $sumscore += $attemptscore;
                }
            }

            if ($lastattempt > 0) {
                $score = $sumscore / $lastattempt;
            } else {
                $score = 0;
            }

            if ($time) {
                $result = new stdClass();
                $result->score = $score;
                $result->time = $attemptscore->time;
                return $result;
            } else {
               return $score;
            }
        break;
    }
}

function scorm_count_launchable($scormid,$organization='') {
    global $DB;

    $sqlorganization = '';
    $params = array($scormid);
    if (!empty($organization)) {
        $sqlorganization = " AND organization=?";
        $params[] = $organization;
    }
    $params []= ''; // empty launch
    return $DB->count_records_select('scorm_scoes',"scorm = ? $sqlorganization AND launch <> ?", $params);
}

function scorm_get_last_attempt($scormid, $userid) {
    global $DB;

/// Find the last attempt number for the given user id and scorm id
    if ($lastattempt = $DB->get_record('scorm_scoes_track', array('userid'=>$userid, 'scormid'=>$scormid), 'max(attempt) as a')) {
        if (empty($lastattempt->a)) {
            return '1';
        } else {
            return $lastattempt->a;
        }
    } else {
        return false;
    }
}

function scorm_course_format_display($user,$course) {
    global $CFG, $DB, $PAGE, $OUTPUT;

    $strupdate = get_string('update');
    $strmodule = get_string('modulename','scorm');
    $context = get_context_instance(CONTEXT_COURSE,$course->id);

    echo '<div class="mod-scorm">';
    if ($scorms = get_all_instances_in_course('scorm', $course)) {
        // The module SCORM activity with the least id is the course
        $scorm = current($scorms);
        if (! $cm = get_coursemodule_from_instance('scorm', $scorm->id, $course->id)) {
            print_error('invalidcoursemodule');
        }
        $colspan = '';
        $headertext = '<table width="100%"><tr><td class="title">'.get_string('name').': <b>'.format_string($scorm->name).'</b>';
        if (has_capability('moodle/course:manageactivities', $context)) {
            if ($PAGE->user_is_editing()) {
                // Display update icon
                $path = $CFG->wwwroot.'/course';
                $headertext .= '<span class="commands">'.
                        '<a title="'.$strupdate.'" href="'.$path.'/mod.php?update='.$cm->id.'&amp;sesskey='.sesskey().'">'.
                        '<img src="'.$OUTPUT->old_icon_url('t/edit') . '" class="iconsmall" alt="'.$strupdate.'" /></a></span>';
            }
            $headertext .= '</td>';
            // Display report link
            $trackedusers = $DB->get_record('scorm_scoes_track', array('scormid'=>$scorm->id), 'count(distinct(userid)) as c');
            if ($trackedusers->c > 0) {
                $headertext .= '<td class="reportlink">'.
                              '<a '.$CFG->frametarget.'" href="'.$CFG->wwwroot.'/mod/scorm/report.php?id='.$cm->id.'">'.
                               get_string('viewallreports','scorm',$trackedusers->c).'</a>';
            } else {
                $headertext .= '<td class="reportlink">'.get_string('noreports','scorm');
            }
            $colspan = ' colspan="2"';
        }
        $options = (object)array('noclean'=>true);
        $headertext .= '</td></tr><tr><td'.$colspan.'>'.get_string('summary').':<br />'.format_module_intro('scorm', $scorm, $scorm->coursemodule).'</td></tr></table>';
        print_simple_box($headertext,'','100%');
        scorm_view_display($user, $scorm, 'view.php?id='.$course->id, $cm, '100%');
    } else {
        if (has_capability('moodle/course:update', $context)) {
            // Create a new activity
            redirect($CFG->wwwroot.'/course/mod.php?id='.$course->id.'&amp;section=0&sesskey='.sesskey().'&amp;add=scorm');
        } else {
            notify('Could not find a scorm course here');
        }
    }
    echo '</div>';
}

function scorm_view_display ($user, $scorm, $action, $cm, $boxwidth='') {
    global $CFG, $DB;

    if ($scorm->updatefreq == UPDATE_EVERYTIME) {
        scorm_parse($scorm, false);
    }

    $organization = optional_param('organization', '', PARAM_INT);

    if($scorm->displaycoursestructure == 1) {
        print_simple_box_start('center',$boxwidth);
?>
        <div class="structurehead"><?php print_string('contents','scorm') ?></div>
<?php
    }
    if (empty($organization)) {
        $organization = $scorm->launch;
    }
    if ($orgs = $DB->get_records_menu('scorm_scoes', array('scorm'=>$scorm->id, 'organization'=>'', 'launch'=>''), 'id', 'id,title')) {
        if (count($orgs) > 1) {
 ?>
            <div class='scorm-center'>
                <?php print_string('organizations','scorm') ?>
                <form id='changeorg' method='post' action='<?php echo $action ?>'>
                    <?php choose_from_menu($orgs, 'organization', "$organization", '','submit()') ?>
                </form>
            </div>
<?php
        }
    }
    $orgidentifier = '';
    if ($sco = scorm_get_sco($organization, SCO_ONLY)) {
        if (($sco->organization == '') && ($sco->launch == '')) {
            $orgidentifier = $sco->identifier;
        } else {
            $orgidentifier = $sco->organization;
        }
    }

/*
 $orgidentifier = '';
    if ($org = $DB->get_record('scorm_scoes', array('id'=>$organization))) {
        if (($org->organization == '') && ($org->launch == '')) {
            $orgidentifier = $org->identifier;
        } else {
            $orgidentifier = $org->organization;
        }
    }*/

    $scorm->version = strtolower(clean_param($scorm->version, PARAM_SAFEDIR));   // Just to be safe
    if (!file_exists($CFG->dirroot.'/mod/scorm/datamodels/'.$scorm->version.'lib.php')) {
        $scorm->version = 'scorm_12';
    }
    require_once($CFG->dirroot.'/mod/scorm/datamodels/'.$scorm->version.'lib.php');

    $result = scorm_get_toc($user,$scorm,'structlist',$orgidentifier);
    $incomplete = $result->incomplete;

    // do we want the TOC to be displayed?
    if($scorm->displaycoursestructure == 1) {
        echo $result->toc;
        print_simple_box_end();
    }

    // is this the first attempt ?
    $attemptcount = scorm_get_attempt_count($user, $scorm);

    // do not give the player launch FORM if the SCORM object is locked after the final attempt
    if ($scorm->lastattemptlock == 0 || $result->attemptleft > 0) {
?>
            <div class="scorm-center">
               <form id="theform" method="post" action="<?php echo $CFG->wwwroot ?>/mod/scorm/player.php">
              <?php
                  if ($scorm->hidebrowse == 0) {
                      print_string('mode','scorm');
                      echo ': <input type="radio" id="b" name="mode" value="browse" /><label for="b">'.get_string('browse','scorm').'</label>'."\n";
                      echo '<input type="radio" id="n" name="mode" value="normal" checked="checked" /><label for="n">'.get_string('normal','scorm')."</label>\n";
                  } else {
                      echo '<input type="hidden" name="mode" value="normal" />'."\n";
                  }
                  if ($scorm->forcenewattempt == 1) {
                      if ($incomplete === false) {
                          echo '<input type="hidden" name="newattempt" value="on" />'."\n";
                      }
                  } elseif ($attemptcount != 0 && ($incomplete === false) && (($result->attemptleft > 0)||($scorm->maxattempt == 0))) {
?>
                      <br />
                      <input type="checkbox" id="a" name="newattempt" />
                      <label for="a"><?php print_string('newattempt','scorm') ?></label>
<?php
                  }
              ?>
              <br />
              <input type="hidden" name="scoid"/>
              <input type="hidden" name="id" value="<?php echo $cm->id ?>"/>
              <input type="hidden" name="currentorg" value="<?php echo $orgidentifier ?>" />
              <input type="submit" value="<?php print_string('enter','scorm') ?>" />
              </form>
          </div>
<?php
    }
}

function scorm_simple_play($scorm,$user) {
    global $DB;

    $result = false;

    if ($scorm->updatefreq == UPDATE_EVERYTIME) {
        scorm_parse($scorm, false);
    }

    $scoes = $DB->get_records_select('scorm_scoes', 'scorm = ? AND launch <> ?', array($scorm->id, $DB->sql_empty()));

    if ($scoes) {
        if ($scorm->skipview >= 1) {
            $sco = current($scoes);
            if (scorm_get_tracks($sco->id,$user->id) === false) {
                header('Location: player.php?a='.$scorm->id.'&scoid='.$sco->id);
                $result = true;
            } else if ($scorm->skipview == 2) {
                header('Location: player.php?a='.$scorm->id.'&scoid='.$sco->id);
                $result = true;
            }
        }
    }
    return $result;
}
/*
function scorm_simple_play($scorm,$user) {
    global $DB;
    $result = false;
    if ($scoes = $DB->get_records_select('scorm_scoes','scorm=? AND launch<>""', array($scorm->id))) {
        if (count($scoes) == 1) {
            if ($scorm->skipview >= 1) {
                $sco = current($scoes);
                if (scorm_get_tracks($sco->id,$user->id) === false) {
                    header('Location: player.php?a='.$scorm->id.'&scoid='.$sco->id);
                    $result = true;
                } else if ($scorm->skipview == 2) {
                    header('Location: player.php?a='.$scorm->id.'&scoid='.$sco->id);
                    $result = true;
                }
            }
        }
    }
    return $result;
}
*/

function scorm_get_count_users($scormid, $groupingid=null) {
    global $CFG, $DB;

    if (!empty($CFG->enablegroupings) && !empty($groupingid)) {
        $sql = "SELECT COUNT(DISTINCT st.userid)
                FROM {scorm_scoes_track} st
                    INNER JOIN {groups_members} gm ON st.userid = gm.userid
                    INNER JOIN {groupings_groups} gg ON gm.groupid = gg.groupid
                WHERE st.scormid = ? AND gg.groupingid = ?
                ";
        $params = array($scormid, $groupingid);
    } else {
        $sql = "SELECT COUNT(DISTINCT st.userid)
                FROM {scorm_scoes_track} st
                WHERE st.scormid = ?
                ";
        $params = array($scormid);
    }

    return ($DB->count_records_sql($sql, $params));
}

/**
* Build up the JavaScript representation of an array element
*
* @param string $sversion SCORM API version
* @param array $userdata User track data
* @param string $element_name Name of array element to get values for
* @param array $children list of sub elements of this array element that also need instantiating
* @return None
*/
function scorm_reconstitute_array_element($sversion, $userdata, $element_name, $children) {
    // reconstitute comments_from_learner and comments_from_lms
    $current = '';
    $current_subelement = '';
    $current_sub = '';
    $count = 0;
    $count_sub = 0;

    // filter out the ones we want
    $element_list = array();
    foreach($userdata as $element => $value){
        if (substr($element,0,strlen($element_name)) == $element_name) {
            $element_list[$element] = $value;
        }
    }

    // sort elements in .n array order
    uksort($element_list, "scorm_element_cmp");

    // generate JavaScript
    foreach($element_list as $element => $value){
        if ($sversion == 'scorm_13') {
            $element = preg_replace('/\.(\d+)\./', ".N\$1.", $element);
            preg_match('/\.(N\d+)\./', $element, $matches);
        } else {
            $element = preg_replace('/\.(\d+)\./', "_\$1.", $element);
            preg_match('/\_(\d+)\./', $element, $matches);
        }
        if (count($matches) > 0 && $current != $matches[1]) {
            if ($count_sub > 0) {
                echo '    '.$element_name.'_'.$current.'.'.$current_subelement.'._count = '.$count_sub.";\n";
            }
            $current = $matches[1];
            $count++;
            $current_subelement = '';
            $current_sub = '';
            $count_sub = 0;
            $end = strpos($element,$matches[1])+strlen($matches[1]);
            $subelement = substr($element,0,$end);
            echo '    '.$subelement." = new Object();\n";
            // now add the children
            foreach ($children as $child) {
                echo '    '.$subelement.".".$child." = new Object();\n";
                echo '    '.$subelement.".".$child."._children = ".$child."_children;\n";
            }
        }

        // now - flesh out the second level elements if there are any
        if ($sversion == 'scorm_13') {
            $element = preg_replace('/(.*?\.N\d+\..*?)\.(\d+)\./', "\$1.N\$2.", $element);
            preg_match('/.*?\.N\d+\.(.*?)\.(N\d+)\./', $element, $matches);
        } else {
            $element = preg_replace('/(.*?\_\d+\..*?)\.(\d+)\./', "\$1_\$2.", $element);
            preg_match('/.*?\_\d+\.(.*?)\_(\d+)\./', $element, $matches);
        }

        // check the sub element type
        if (count($matches) > 0 && $current_subelement != $matches[1]) {
            if ($count_sub > 0) {
                echo '    '.$element_name.'_'.$current.'.'.$current_subelement.'._count = '.$count_sub.";\n";
            }
            $current_subelement = $matches[1];
            $current_sub = '';
            $count_sub = 0;
            $end = strpos($element,$matches[1])+strlen($matches[1]);
            $subelement = substr($element,0,$end);
            echo '    '.$subelement." = new Object();\n";
        }

        // now check the subelement subscript
        if (count($matches) > 0 && $current_sub != $matches[2]) {
            $current_sub = $matches[2];
            $count_sub++;
            $end = strrpos($element,$matches[2])+strlen($matches[2]);
            $subelement = substr($element,0,$end);
            echo '    '.$subelement." = new Object();\n";
        }

        echo '    '.$element.' = \''.$value."';\n";
    }
    if ($count_sub > 0) {
        echo '    '.$element_name.'_'.$current.'.'.$current_subelement.'._count = '.$count_sub.";\n";
    }
    if ($count > 0) {
        echo '    '.$element_name.'._count = '.$count.";\n";
    }
}

/**
* Build up the JavaScript representation of an array element
*
* @param string $a left array element
* @param string $b right array element
* @return comparator - 0,1,-1
*/
function scorm_element_cmp($a, $b) {
    preg_match('/.*?(\d+)\./', $a, $matches);
    $left = intval($matches[1]);
    preg_match('/.?(\d+)\./', $b, $matches);
    $right = intval($matches[1]);
    if ($left < $right) {
        return -1; // smaller
    } elseif ($left > $right) {
        return 1;  // bigger
    } else {
        // look for a second level qualifier eg cmi.interactions_0.correct_responses_0.pattern
        if (preg_match('/.*?(\d+)\.(.*?)\.(\d+)\./', $a, $matches)) {
            $leftterm = intval($matches[2]);
            $left = intval($matches[3]);
            if (preg_match('/.*?(\d+)\.(.*?)\.(\d+)\./', $b, $matches)) {
                $rightterm = intval($matches[2]);
                $right = intval($matches[3]);
                if ($leftterm < $rightterm) {
                    return -1; // smaller
                } elseif ($leftterm > $rightterm) {
                    return 1;  // bigger
                } else {
                    if ($left < $right) {
                        return -1; // smaller
                    } elseif ($left > $right) {
                        return 1;  // bigger
                    }
                }
            }
        }
        // fall back for no second level matches or second level matches are equal
        return 0;  // equal to
    }
}

/**
* Generate the user attempt status string
*
* @param object $user Current context user
* @param object $scorm a moodle scrom object - mdl_scorm
* @return string - Attempt status string
*/
function scorm_get_attempt_status($user, $scorm) {
    global $DB;

    $attempts = $DB->get_records_select('scorm_scoes_track',"element='cmi.core.score.raw' AND userid=? AND scormid=?", array($user->id, $scorm->id),'attempt','attempt AS attemptnumber, value AS grade');
    if(empty($attempts)) {
        $attemptcount = 0;
    } else {
        $attemptcount = count($attempts);
    }

    $result = '<p>'.get_string('noattemptsallowed', 'scorm').': ';
    if ($scorm->maxattempt > 0) {
        $result .= $scorm->maxattempt . '<BR>';
    } else {
        $result .= get_string('unlimited').'<BR>';
    }
    $result .= get_string('noattemptsmade', 'scorm').': ' . $attemptcount . '<BR>';

    $gradereported = 0;
    $gradesum = 0;
    switch ($scorm->grademethod) {
        case GRADEHIGHEST:
           $grademethod = get_string('gradehighest', 'scorm');
        break;
        case GRADEAVERAGE:
           $grademethod = get_string('gradeaverage', 'scorm');
        break;
        case GRADESUM:
           $grademethod = get_string('gradesum', 'scorm');
        break;
        case GRADESCOES:
           $grademethod = get_string('gradescoes', 'scorm');
        break;
    }

    if(!empty($attempts)) {
        foreach($attempts as $attempt) {
            $gradereported = scorm_grade_user_attempt($scorm, $user->id, $attempt->attemptnumber);
            $result .= get_string('gradeforattempt', 'scorm').' ' . $attempt->attemptnumber . ': ' . $attempt->grade .'%<BR>';
        }
    }

    $result .= get_string('grademethod', 'scorm'). ': ' . $grademethod;
    if(empty($attempts)) {
        $result .= '<BR>' . get_string('gradereported','scorm') . ': ' . get_string('none') . '<BR>';
    } else {
        $result .= '<BR>' . get_string('gradereported','scorm') . ': ' . $gradereported . ($scorm->grademethod == GRADESCOES ? '' : '%') .'<BR>';
    }
    $result .= '</p>';
    if ($attemptcount >= $scorm->maxattempt and $scorm->maxattempt > 0) {
        $result .= '<p><font color="#cc0000">'.get_string('exceededmaxattempts','scorm').'</font></p>';
    }
    return $result;
}

/**
* Get SCORM attempt count
*
* @param object $user Current context user
* @param object $scorm a moodle scrom object - mdl_scorm
* @return int - no. of attempts so far
*/
function scorm_get_attempt_count($user, $scorm) {
    global $DB;
    $attemptcount = 0;
    $element = 'cmi.core.score.raw';
    if ($scorm->version == 'scorm1_3') {
        $element = 'cmi.score.raw';
    }
    $attempts = $DB->get_records_select('scorm_scoes_track',"element=? AND userid=? AND scormid=?", array($element, $user->id, $scorm->id),'attempt','attempt AS attemptnumber, value AS grade');
    if(!empty($attempts)) {
        $attemptcount = count($attempts);
    }
    return $attemptcount;
}

/**
* Figure out with this is a debug situation
*
* @param object $scorm a moodle scrom object - mdl_scorm
* @return boolean - debugging true/false
*/
function scorm_debugging($scorm) {
    global $CFG, $USER;
    $cfg_scorm = get_config('scorm');

    if (!$cfg_scorm->allowapidebug) {
        return false;
    }
    $identifier = $USER->username.':'.$scorm->name;
    $test = $cfg_scorm->apidebugmask;
    // check the regex is only a short list of safe characters
    if (!preg_match('/^[\w\s\*\.\?\+\:\_\\\]+$/', $test)) {
        return false;
    }
    $res = false;
    eval('$res = preg_match(\'/^'.$test.'/\', $identifier) ? true : false;');
    return $res;
}

/**
* Delete Scorm tracks for selected users
*
* @param array $attemptids list of attempts that need to be deleted
* @param int $scormid ID of Scorm
*
* return bool true deleted all responses, false failed deleting an attempt - stopped here
*/
function scorm_delete_responses($attemptids, $scormid) {
    if(!is_array($attemptids) || empty($attemptids)) {
        return false;
    }

    foreach($attemptids as $num => $attemptid) {
        if(empty($attemptid)) {
            unset($attemptids[$num]);
        }
    }

    foreach($attemptids as $attempt) {
        $keys = explode(':', $attempt);
        if (count($keys) == 2) {
            $userid = clean_param($keys[0], PARAM_INT);
            $attemptid = clean_param($keys[1], PARAM_INT);
            if (!$userid || !$attemptid || !scorm_delete_attempt($userid, $scormid, $attemptid)) {
                    return false;
            }
        } else {
            return false;
        }
    }
    return true;
}

/**
* Delete Scorm tracks for selected users
*
* @param int $userid ID of User
* @param int $scormid ID of Scorm
* @param int $attemptid user attempt that need to be deleted
*
* return bool true suceeded
*/
function scorm_delete_attempt($userid, $scormid, $attemptid) {
    global $DB;

    $DB->delete_records('scorm_scoes_track', array('userid' => $userid, 'scormid' => $scormid, 'attempt' => $attemptid));
    return true;
}
?>