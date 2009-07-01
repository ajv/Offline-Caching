<?PHP  // $Id$

/// This page prints a particular instance of aicc/scorm package

    require_once('../../config.php');
    require_once('locallib.php');
    //
    // Checkin' script parameters
    //
    $id = optional_param('id', '', PARAM_INT);       // Course Module ID, or
    $a = optional_param('a', '', PARAM_INT);         // scorm ID
    $scoid = required_param('scoid', PARAM_INT);  // sco ID
    $mode = optional_param('mode', 'normal', PARAM_ALPHA); // navigation mode
    $currentorg = optional_param('currentorg', '', PARAM_RAW); // selected organization
    $newattempt = optional_param('newattempt', 'off', PARAM_ALPHA); // the user request to start a new attempt

    if (!empty($id)) {
        if (! $cm = get_coursemodule_from_id('scorm', $id)) {
            print_error('invalidcoursemodule');
        }
        if (! $course = $DB->get_record("course", array("id"=>$cm->course))) {
            print_error('coursemisconf');
        }
        if (! $scorm = $DB->get_record("scorm", array("id"=>$cm->instance))) {
            print_error('invalidcoursemodule');
        }
    } else if (!empty($a)) {
        if (! $scorm = $DB->get_record("scorm", array("id"=>$a))) {
            print_error('invalidcoursemodule');
        }
        if (! $course = $DB->get_record("course", array("id"=>$scorm->course))) {
            print_error('coursemisconf');
        }
        if (! $cm = get_coursemodule_from_instance("scorm", $scorm->id, $course->id)) {
            print_error('invalidcoursemodule');
        }
    } else {
        print_error('missingparameter');
    }

    require_login($course->id, false, $cm);

    $strscorms = get_string('modulenameplural', 'scorm');
    $strscorm  = get_string('modulename', 'scorm');
    $strpopup = get_string('popup','scorm');
    $strexit = get_string('exitactivity','scorm');

    $navlinks = array();

    if ($course->id != SITEID) {
        if ($scorms = get_all_instances_in_course('scorm', $course)) {
            // The module SCORM/AICC activity with the first id is the course
            $firstscorm = current($scorms);
            if (!(($course->format == 'scorm') && ($firstscorm->id == $scorm->id))) {
                $navlinks[] = array('name' => $strscorms, 'link' => "index.php?id=$course->id", 'type' => 'activity');
            }
        }
    }

    $pagetitle = strip_tags("$course->shortname: ".format_string($scorm->name));
    if (!$cm->visible and !has_capability('moodle/course:viewhiddenactivities', get_context_instance(CONTEXT_COURSE,$course->id))) {
        $navlinks[] = array('name' => format_string($scorm->name,true), 'link' => "view.php?id=$cm->id", 'type' => 'activityinstance');
        $navigation = build_navigation($navlinks);

        print_header($pagetitle, $course->fullname, $navigation,
                 '', '', true, update_module_button($cm->id, $course->id, $strscorm), '', false);
        notice(get_string("activityiscurrentlyhidden"));
        print_footer($course);
        die;
    }

    //check if scorm closed
    $timenow = time();
    if ($scorm->timeclose !=0) {
        if ($scorm->timeopen > $timenow) {
            $navlinks[] = array('name' => format_string($scorm->name,true), 'link' => "view.php?id=$cm->id", 'type' => 'activityinstance');
            $navigation = build_navigation($navlinks);
            print_header($pagetitle, $course->fullname, $navigation,
                     '', '', true, update_module_button($cm->id, $course->id, $strscorm), '', false);
            print_simple_box(get_string("notopenyet", "scorm", userdate($scorm->timeopen)), "center");
            print_footer($course);
            die;
        } elseif ($timenow > $scorm->timeclose) {
            $navlinks[] = array('name' => format_string($scorm->name,true), 'link' => "view.php?id=$cm->id", 'type' => 'activityinstance');
            $navigation = build_navigation($navlinks);
            print_header($pagetitle, $course->fullname, $navigation,
                     '', '', true, update_module_button($cm->id, $course->id, $strscorm), '', false);
            print_simple_box(get_string("expired", "scorm", userdate($scorm->timeclose)), "center");
            print_footer($course);
            die;
        }
    }

    //
    // TOC processing
    //
    $scorm->version = strtolower(clean_param($scorm->version, PARAM_SAFEDIR));   // Just to be safe
    if (!file_exists($CFG->dirroot.'/mod/scorm/datamodels/'.$scorm->version.'lib.php')) {
        $scorm->version = 'scorm_12';
    }
    require_once($CFG->dirroot.'/mod/scorm/datamodels/'.$scorm->version.'lib.php');
    $attempt = scorm_get_last_attempt($scorm->id, $USER->id);
    if (($newattempt=='on') && (($attempt < $scorm->maxattempt) || ($scorm->maxattempt == 0))) {
        $attempt++;
        $mode = 'normal';
    }
    $attemptstr = '&amp;attempt=' . $attempt;

    $result = scorm_get_toc($USER,$scorm,'structurelist',$currentorg,$scoid,$mode,$attempt,true);
    $sco = $result->sco;

    if (($mode == 'browse') && ($scorm->hidebrowse == 1)) {
       $mode = 'normal';
    }
    if ($mode != 'browse') {
        if ($trackdata = scorm_get_tracks($sco->id,$USER->id,$attempt)) {
            if (($trackdata->status == 'completed') || ($trackdata->status == 'passed') || ($trackdata->status == 'failed')) {
                $mode = 'review';
            } else {
                $mode = 'normal';
            }
        } else {
            $mode = 'normal';
        }
    }

    add_to_log($course->id, 'scorm', 'view', "player.php?id=$cm->id&scoid=$sco->id", "$scorm->id", $cm->id);


    $scoidstr = '&amp;scoid='.$sco->id;
    $scoidpop = '&scoid='.$sco->id;
    $modestr = '&amp;mode='.$mode;
    if ($mode == 'browse') {
        $modepop = '&mode='.$mode;
    } else {
        $modepop = '';
    }
    $orgstr = '&currentorg='.$currentorg;

    $SESSION->scorm_scoid = $sco->id;
    $SESSION->scorm_status = 'Not Initialized';
    $SESSION->scorm_mode = $mode;
    $SESSION->scorm_attempt = $attempt;

    //
    // Print the page header
    //
    $bodyscript = '';
    if ($scorm->popup == 1) {
        $bodyscript = 'onunload="main.close();"';
    }

    $navlinks[] = array('name' => format_string($scorm->name,true), 'link' => "view.php?id=$cm->id", 'type' => 'activityinstance');
    $navigation = build_navigation($navlinks);
    $exitlink = '<a href="'.$CFG->wwwroot.'/course/view.php?id='.$scorm->course.'" title="'.$strexit.'">'.$strexit.'</a> ';

    print_header($pagetitle, $course->fullname,
                 $navigation,
                 '', '', true, $exitlink.update_module_button($cm->id, $course->id, $strscorm), '', false, $bodyscript);

    echo $PAGE->requires->data_for_js('scormplayerdata', Array('cwidth'=>$scorm->width,'cheight'=>$scorm->height))->asap();
    echo $PAGE->requires->js('mod/scorm/request.js')->asap();
    echo $PAGE->requires->js('mod/scorm/loaddatamodel.php?id='.$cm->id.$scoidstr.$modestr.$attemptstr)->asap();
    echo $PAGE->requires->js('mod/scorm/rd.js')->asap();
    $PAGE->requires->js_function_call('attach_resize_event');

    if (($sco->previd != 0) && ((!isset($sco->previous)) || ($sco->previous == 0))) {
        $scostr = '&scoid='.$sco->previd;
        $PAGE->requires->js_function_call('scorm_set_prev', Array($CFG->wwwroot.'/mod/scorm/player.php?id='.$cm->id.$orgstr.$modepop.$scostr));
    } else {
        $PAGE->requires->js_function_call('scorm_set_prev', Array($CFG->wwwroot.'/mod/scorm/view.php?id='.$cm->id));
    }
    if (($sco->nextid != 0) && ((!isset($sco->next)) || ($sco->next == 0))) {
        $scostr = '&scoid='.$sco->nextid;
        $PAGE->requires->js_function_call('scorm_set_next', Array($CFG->wwwroot.'/mod/scorm/player.php?id='.$cm->id.$orgstr.$modepop.$scostr));
    } else {
        $PAGE->requires->js_function_call('scorm_set_next', Array($CFG->wwwroot.'/mod/scorm/view.php?id='.$cm->id));
    }
?>
    <div id="scormpage">
<?php
    if ($scorm->hidetoc == 0) {
?>
        <div id="tocbox">
<?php
        if ($scorm->hidenav ==0){
?>
            <!-- Bottons nav at left-->
            <div id="tochead">
                <form name="tochead" method="post" action="player.php?id=<?php echo $cm->id ?>" target="_top">
<?php
            $orgstr = '&amp;currentorg='.$currentorg;
            if (($scorm->hidenav == 0) && ($sco->previd != 0) && (!isset($sco->previous) || $sco->previous == 0)) {
                // Print the prev LO button
                $scostr = '&amp;scoid='.$sco->previd;
                $url = $CFG->wwwroot.'/mod/scorm/player.php?id='.$cm->id.$orgstr.$modestr.$scostr;
?>
                    <input name="prev" type="button" value="<?php print_string('prev','scorm') ?>" onClick="document.location.href=' <?php echo $url; ?> '"/>
<?php
            }
            if (($scorm->hidenav == 0) && ($sco->nextid != 0) && (!isset($sco->next) || $sco->next == 0)) {
                // Print the next LO button
                $scostr = '&amp;scoid='.$sco->nextid;
                $url = $CFG->wwwroot.'/mod/scorm/player.php?id='.$cm->id.$orgstr.$modestr.$scostr;
?>
                    <input name="next" type="button" value="<?php print_string('next','scorm') ?>" onClick="document.location.href=' <?php echo $url; ?> '"/>
<?php
            }
?>
                </form>
            </div> <!-- tochead -->
<?php
        }
?>
            <div id="toctree" class="generalbox">
            <?php echo $result->toc; ?>
            </div> <!-- toctree -->
        </div> <!--  tocbox -->
<?php
        $class = ' class="toc"';
    } else {
        $class = ' class="no-toc"';
    }
?>
        <div id="scormbox"<?php echo $class; if(($scorm->hidetoc == 2) || ($scorm->hidetoc == 1)){echo 'style="width:100%"';}?>>
<?php
    // This very big test check if is necessary the "scormtop" div
    if (
           ($mode != 'normal') ||  // We are not in normal mode so review or browse text will displayed
           (
               ($scorm->hidenav == 0) &&  // Teacher want to display navigation links
               ($scorm->hidetoc != 0) &&  // The buttons has not been displayed
               (
                   (
                       ($sco->previd != 0) &&  // This is not the first learning object of the package
                       ((!isset($sco->previous)) || ($sco->previous == 0))   // Moodle must manage the previous link
                   ) ||
                   (
                       ($sco->nextid != 0) &&  // This is not the last learning object of the package
                       ((!isset($sco->next)) || ($sco->next == 0))       // Moodle must manage the next link
                   )
               )
           ) || ($scorm->hidetoc == 2)      // Teacher want to display toc in a small dropdown menu
       ) {
?>
            <div id="scormtop">
        <?php echo $mode == 'browse' ? '<div id="scormmode" class="scorm-left">'.get_string('browsemode','scorm')."</div>\n" : ''; ?>
        <?php echo $mode == 'review' ? '<div id="scormmode" class="scorm-left">'.get_string('reviewmode','scorm')."</div>\n" : ''; ?>
<?php
        if (($scorm->hidenav == 0) || ($scorm->hidetoc == 2) || ($scorm->hidetoc == 1)) {
?>
                <div id="scormnav" class="scorm-right">
        <?php
            $orgstr = '&amp;currentorg='.$currentorg;
            if (($scorm->hidenav == 0) && ($sco->previd != 0) && (!isset($sco->previous) || $sco->previous == 0) && (($scorm->hidetoc == 2) || ($scorm->hidetoc == 1)) ) {
                // Print the prev LO button
                $scostr = '&amp;scoid='.$sco->previd;
                $url = $CFG->wwwroot.'/mod/scorm/player.php?id='.$cm->id.$orgstr.$modestr.$scostr;
?>
                    <form name="scormnavprev" method="post" action="player.php?id=<?php echo $cm->id ?>" target="_top" style= "display:inline">
                        <input name="prev" type="button" value="<?php print_string('prev','scorm') ?>" onClick="document.location.href=' <?php echo $url; ?> '"/>
                    </form>
<?php
            }
            if ($scorm->hidetoc == 2) {
                echo $result->tocmenu;
            }
            if (($scorm->hidenav == 0) && ($sco->nextid != 0) && (!isset($sco->next) || $sco->next == 0) && (($scorm->hidetoc == 2) || ($scorm->hidetoc == 1))) {
                // Print the next LO button
                $scostr = '&amp;scoid='.$sco->nextid;
                $url = $CFG->wwwroot.'/mod/scorm/player.php?id='.$cm->id.$orgstr.$modestr.$scostr;
?>
                    <form name="scormnavnext" method="post" action="player.php?id=<?php echo $cm->id ?>" target="_top" style= "display:inline">
                        <input name="next" type="button" value="<?php print_string('next','scorm') ?>" onClick="document.location.href=' <?php echo $url; ?> '"/>
                    </form>
<?php
            }
        ?>
                </div>
<?php
        }
?>
            </div> <!-- Scormtop -->
<?php
    } // The end of the very big test
?>
            <div id="scormobject" class="scorm-right">
                <noscript>
                    <div id="noscript">
                        <?php print_string('noscriptnoscorm','scorm'); // No Martin(i), No Party ;-) ?>

                    </div>
                </noscript>
<?php
    if ($result->prerequisites) {
        if ($scorm->popup == 0) {
            $fullurl="loadSCO.php?id=".$cm->id.$scoidstr.$modestr;
            echo "                <iframe id=\"scoframe1\" class=\"scoframe\" name=\"scoframe1\" src=\"{$fullurl}\"></iframe>\n";
            $PAGE->requires->js_function_call('scorm_resize');
        } else {
            // Clean the name for the window as IE is fussy
            $name = preg_replace("/[^A-Za-z0-9]/", "", $scorm->name);
            if (!$name) {
                $name = 'DefaultPlayerWindow';
            }
            $name = 'scorm_'.$name;

            echo $PAGE->requires->js_function_call('scorm_resize')->asap();
            echo $PAGE->requires->js('mod/scorm/player.js')->asap();
            echo $PAGE->requires->js_function_call('scorm_openpopup', Array("loadSCO.php?id=".$cm->id.$scoidpop, p($name), p($scorm->options), p($scorm->width), p($scorm->height)))->asap();
            ?>
                    <noscript>
                    <iframe id="main" class="scoframe" src="loadSCO.php?id=<?php echo $cm->id.$scoidstr.$modestr ?>">
                    </iframe>
                    </noscript>
<?php
            //Added incase javascript popups are blocked
            $link = '<a href="'.$CFG->wwwroot.'/mod/scorm/loadSCO.php?id='.$cm->id.$scoidstr.$modestr.'" target="new">'.get_string('popupblockedlinkname','scorm').'</a>';
            print_simple_box(get_string('popupblocked','scorm',$link),'center');
        }
    } else {
        print_simple_box(get_string('noprerequisites','scorm'),'center');
    }
?>
            </div> <!-- SCORM object -->
        </div> <!-- SCORM box  -->
    </div> <!-- SCORM page -->
<?php print_footer('none'); ?>
