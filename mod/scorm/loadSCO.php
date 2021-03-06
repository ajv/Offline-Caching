<?php  // $Id$
    require_once('../../config.php');
    require_once('locallib.php');

    $id    = optional_param('id', '', PARAM_INT);    // Course Module ID, or
    $a     = optional_param('a', '', PARAM_INT);     // scorm ID
    $scoid = required_param('scoid', PARAM_INT);     // sco ID

    $delayseconds = 2;  // Delay time before sco launch, used to give time to browser to define API

    if (!empty($id)) {
        if (! $cm = get_coursemodule_from_id('scorm', $id)) {
            print_error('invalidcoursemodule');
        }
        if (! $course = $DB->get_record('course', array('id'=>$cm->course))) {
            print_error('coursemisconf');
        }
        if (! $scorm = $DB->get_record('scorm', array('id'=>$cm->instance))) {
            print_error('invalidcoursemodule');
        }
    } else if (!empty($a)) {
        if (! $scorm = $DB->get_record('scorm', array('id'=>$a))) {
            print_error('coursemisconf');
        }
        if (! $course = $DB->get_record('course', array('id'=>$scorm->course))) {
            print_error('coursemisconf');
        }
        if (! $cm = get_coursemodule_from_instance('scorm', $scorm->id, $course->id)) {
            print_error('invalidcoursemodule');
        }
    } else {
        print_error('missingparameter');
    }

    require_login($course->id, false, $cm);

    //check if scorm closed
    $timenow = time();
    if ($scorm->timeclose !=0) {
        if ($scorm->timeopen > $timenow) {
            print_error('notopenyet', 'scorm', null, userdate($scorm->timeopen));
        } else if ($timenow > $scorm->timeclose) {
            print_error('expired', 'scorm', null, userdate($scorm->timeclose));
        }
    }

    $context = get_context_instance(CONTEXT_MODULE, $cm->id);

    if (!empty($scoid)) {
    //
    // Direct SCO request
    //
        if ($sco = scorm_get_sco($scoid)) {
            if ($sco->launch == '') {
                // Search for the next launchable sco
                if ($scoes = $DB->get_records_select('scorm_scoes',"scorm = ? AND launch <> ? AND id > ?",array($scorm->id, $DB->sql_empty(), $sco->id), 'id ASC')) {
                    $sco = current($scoes);
                }
            }
        }
    }
    //
    // If no sco was found get the first of SCORM package
    //
    if (!isset($sco)) {
        $scoes = $DB->get_records_select('scorm_scoes',"scorm = ? AND launch <> ?", array($scorm->id, $DB->sql_empty()),'id ASC');
        $sco = current($scoes);
    }

    if ($sco->scormtype == 'asset') {
       $attempt = scorm_get_last_attempt($scorm->id,$USER->id);
       $element = ($scorm->version == 'scorm_13' || $scorm->version == 'SCORM_1.3') ? 'cmi.completion_status':'cmi.core.lesson_status';
       $value = 'completed';
       $result = scorm_insert_track($USER->id, $scorm->id, $sco->id, $attempt, $element, $value);
    }

    //
    // Forge SCO URL
    //
    $connector = '';
    $version = substr($scorm->version,0,4);
    if ((isset($sco->parameters) && (!empty($sco->parameters))) || ($version == 'AICC')) {
        if (stripos($sco->launch,'?') !== false) {
            $connector = '&';
        } else {
            $connector = '?';
        }
        if ((isset($sco->parameters) && (!empty($sco->parameters))) && ($sco->parameters[0] == '?')) {
            $sco->parameters = substr($sco->parameters,1);
        }
    }

    if ($version == 'AICC') {
        if (isset($sco->parameters) && (!empty($sco->parameters))) {
            $sco->parameters = '&'. $sco->parameters;
        }
        $launcher = $sco->launch.$connector.'aicc_sid='.sesskey().'&aicc_url='.$CFG->wwwroot.'/mod/scorm/aicc.php'.$sco->parameters;
    } else {
        if (isset($sco->parameters) && (!empty($sco->parameters))) {
            $launcher = $sco->launch.$connector.$sco->parameters;
        } else {
            $launcher = $sco->launch;
        }
    }

    if (scorm_external_link($sco->launch)) {
        //TODO: does this happen?
        $result = $launcher;

    } else if ($scorm->scormtype === SCORM_TYPE_EXTERNAL) {
        // Remote learning activity
        $result = dirname($scorm->reference).'/'.$launcher;

    } else if ($scorm->scormtype === SCORM_TYPE_IMSREPOSITORY) {
        // Repository
        $result = $CFG->repositorywebroot.substr($scorm->reference, 1).'/'.$sco->launch;

    } else if ($scorm->scormtype === SCORM_TYPE_LOCAL or $scorm->scormtype === SCORM_TYPE_LOCALSYNC) {
        //note: do not convert this to use get_file_url()!
        //      SCORM does not work without slasharguments anyway and there might be some extra ?xx=yy params
        //      see MDL-16060
        $result = "$CFG->wwwroot/pluginfile.php/$context->id/scorm_content/$scorm->revision/$launcher";
    }

    // which API are we looking for
    $LMS_api = ($scorm->version == 'scorm_12' || $scorm->version == 'SCORM_1.2' || empty($scorm->version)) ? 'API' : 'API_1484_11';
?>
<html>
    <head>
        <title>LoadSCO</title>
        <script type="text/javascript">
        //<![CDATA[
        var apiHandle = null;
        var findAPITries = 0;

        function getAPIHandle() {
           if (apiHandle == null) {
              apiHandle = getAPI();
           }
           return apiHandle;
        }

        function findAPI(win) {
           while ((win.<?php echo $LMS_api; ?> == null) && (win.parent != null) && (win.parent != win)) {
              findAPITries++;
              // Note: 7 is an arbitrary number, but should be more than sufficient
              if (findAPITries > 7) {
                 return null;
              }
              win = win.parent;
           }
           return win.<?php echo $LMS_api; ?>;
        }

        // hun for the API - needs to be loaded before we can launch the package
        function getAPI() {
           var theAPI = findAPI(window);
           if ((theAPI == null) && (window.opener != null) && (typeof(window.opener) != "undefined")) {
              theAPI = findAPI(window.opener);
           }
           if (theAPI == null) {
              return null;
           }
           return theAPI;
        }

       function doredirect() {
            if (getAPI() != null) {
                location = "<?php echo $result ?>";
            }
            else {
                document.body.innerHTML = "<p><?php echo get_string('activityloading', 'scorm');?> <span id='countdown'><?php echo $delayseconds ?></span> <?php echo get_string('numseconds');?>. &nbsp; <img src='<?php echo $OUTPUT->mod_icon_url('pix/wait', 'scorm') ?>'><p>";
                var e = document.getElementById("countdown");
                var cSeconds = parseInt(e.innerHTML);
                var timer = setInterval(function() {
                                                if( cSeconds && getAPI() == null ) {
                                                    e.innerHTML = --cSeconds;
                                                } else {
                                                    clearInterval(timer);
                                                    document.body.innerHTML = "<p><?php echo get_string('activitypleasewait', 'scorm');?></p>";
                                                    location = "<?php echo $result ?>";
                                                }
                                            }, 1000);
            }
        }
        //]]>
        </script>
        <noscript>
            <meta http-equiv="refresh" content="0;url=<?php echo $result ?>" />
        </noscript>
    </head>
    <body onload="doredirect();">
        <p><?php echo get_string('activitypleasewait', 'scorm');?></p>
        <?php if (debugging('',DEBUG_DEVELOPER)) {
                  add_to_log($course->id, 'scorm', 'launch', 'view.php?id='.$cm->id, $result, $cm->id);
              }
        ?>
    </body>
</html>
