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
 * @package   mod-resource
 * @copyright 2009 Petr Skoda (http://skodak.org)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * List of features supported in Resource module
 * @param string $feature FEATURE_xx constant for requested feature
 * @return mixed True if module supports feature, false if not, null if doesn't know
 */
function resource_supports($feature) {
    switch($feature) {
        case FEATURE_MOD_ARCHETYPE:           return MOD_ARCHETYPE_RESOURCE;
        case FEATURE_GROUPS:                  return false;
        case FEATURE_GROUPINGS:               return false;
        case FEATURE_GROUPMEMBERSONLY:        return true;
        case FEATURE_MOD_INTRO:               return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS: return true;
        case FEATURE_GRADE_HAS_GRADE:         return false;
        case FEATURE_GRADE_OUTCOMES:          return false;

        default: return null;
    }
}

/**
 * Returns all other caps used in module
 * @return array
 */
function resource_get_extra_capabilities() {
    return array('moodle/site:accessallgroups');
}

/**
 * This function is used by the reset_course_userdata function in moodlelib.
 * @param $data the data submitted from the reset course.
 * @return array status array
 */
function resource_reset_userdata($data) {
    return array();
}

/**
 * List of view style log actions
 * @return array
 */
function resource_get_view_actions() {
    return array('view','view all');
}

/**
 * List of update style log actions
 * @return array
 */
function resource_get_post_actions() {
    return array('update', 'add');
}

/**
 * Add resource instance.
 * @param object $data
 * @param object $mform
 * @return int new resoruce instance id
 */
function resource_add_instance($data, $mform) {
    global $CFG, $DB;
    require_once("$CFG->libdir/resourcelib.php");

    $cmid        = $data->coursemodule;
    $draftitemid = $data->files;

    $data->timemodified = time();
    $displayoptions = array();
    if ($data->display == RESOURCELIB_DISPLAY_POPUP) {
        $displayoptions['popupwidth']  = $data->popupwidth;
        $displayoptions['popupheight'] = $data->popupheight;
    }
    if (in_array($data->display, array(RESOURCELIB_DISPLAY_AUTO, RESOURCELIB_DISPLAY_EMBED, RESOURCELIB_DISPLAY_FRAME))) {
        $displayoptions['printheading'] = (int)!empty($data->printheading);
        $displayoptions['printintro']   = (int)!empty($data->printintro);
    }
    $data->displayoptions = serialize($displayoptions);

    $data->id = $DB->insert_record('resource', $data);

    // we need to use context now, so we need to make sure all needed info is already in db
    $DB->set_field('course_modules', 'instance', $data->id, array('id'=>$cmid));
    $context = get_context_instance(CONTEXT_MODULE, $cmid);

    if ($draftitemid) {
        file_save_draft_area_files($draftitemid, $context->id, 'resource_content', 0, array('subdirs'=>true));
    }

    $fs = get_file_storage();
    $files = $fs->get_area_files($context->id, 'resource_content', 0, '', false);
    if (count($files) == 1) {
        $file = reset($files);
        $path = $file->get_filepath().$file->get_filename();
        if ($path !== $data->mainfile) {
            $data->mainfile = $path;
            $DB->set_field('resource', 'mainfile', $path, array('id'=>$data->id));
        }
    }

    return $data->id;
}

/**
 * Update resource instance.
 * @param object $data
 * @param object $mform
 * @return bool true
 */
function resource_update_instance($data, $mform) {
    global $CFG, $DB;
    require_once("$CFG->libdir/resourcelib.php");

    $cmid        = $data->coursemodule;
    $draftitemid = $data->files;

    $data->timemodified = time();
    $data->id           = $data->instance;
    $data->revision++;

    $displayoptions = array();
    if ($data->display == RESOURCELIB_DISPLAY_POPUP) {
        $displayoptions['popupwidth']  = $data->popupwidth;
        $displayoptions['popupheight'] = $data->popupheight;
    }
    if (in_array($data->display, array(RESOURCELIB_DISPLAY_AUTO, RESOURCELIB_DISPLAY_EMBED, RESOURCELIB_DISPLAY_FRAME))) {
        $displayoptions['printheading'] = (int)!empty($data->printheading);
        $displayoptions['printintro']   = (int)!empty($data->printintro);
    }
    $data->displayoptions = serialize($displayoptions);

    $DB->update_record('resource', $data);

    $context = get_context_instance(CONTEXT_MODULE, $cmid);
    if ($draftitemid) {
        file_save_draft_area_files($draftitemid, $context->id, 'resource_content', 0, array('subdirs'=>true));
    }

    $fs = get_file_storage();
    $files = $fs->get_area_files($context->id, 'resource_content', 0, '', false);
    if (count($files) == 1) {
        $file = reset($files);
        $path = $file->get_filepath().$file->get_filename();
        if ($path !== $data->mainfile) {
            $data->mainfile = $path;
            $DB->set_field('resource', 'mainfile', $path, array('id'=>$data->id));
        }
    }

    return true;
}

/**
 * Delete resource instance.
 * @param int $id
 * @return bool true
 */
function resource_delete_instance($id) {
    global $DB;

    if (!$resource = $DB->get_record('resource', array('id'=>$id))) {
        return false;
    }

    // note: all context files are deleted automatically

    $DB->delete_records('resource', array('id'=>$resource->id));

    return true;
}

/**
 * Return use outline
 * @param object $course
 * @param object $user
 * @param object $mod
 * @param object $resource
 * @return object|null
 */
function resource_user_outline($course, $user, $mod, $resource) {
    global $DB;

    if ($logs = $DB->get_records('log', array('userid'=>$user->id, 'module'=>'resource',
                                              'action'=>'view', 'info'=>$resource->id), 'time ASC')) {

        $numviews = count($logs);
        $lastlog = array_pop($logs);

        $result = new object();
        $result->info = get_string('numviews', '', $numviews);
        $result->time = $lastlog->time;

        return $result;
    }
    return NULL;
}

/**
 * Return use complete
 * @param object $course
 * @param object $user
 * @param object $mod
 * @param object $resource
 */
function resource_user_complete($course, $user, $mod, $resource) {
    global $CFG, $DB;

    if ($logs = $DB->get_records('log', array('userid'=>$user->id, 'module'=>'resource',
                                              'action'=>'view', 'info'=>$resource->id), 'time ASC')) {
        $numviews = count($logs);
        $lastlog = array_pop($logs);

        $strmostrecently = get_string('mostrecently');
        $strnumviews = get_string('numviews', '', $numviews);

        echo "$strnumviews - $strmostrecently ".userdate($lastlog->time);

    } else {
        print_string('neverseen', 'resource');
    }
}

/**
 * Returns the users with data in one resource
 *
 * @param int $resourceid
 * @return bool false
 */
function resource_get_participants($resourceid) {
    return false;
}

/**
 * Given a course_module object, this function returns any
 * "extra" information that may be needed when printing
 * this activity in a course listing.
 *
 * See {@link get_array_of_activities()} in course/lib.php
 *
 * @param object $coursemodule
 * @return object info
 */
function resource_get_coursemodule_info($coursemodule) {
    global $CFG, $DB;
    require_once("$CFG->libdir/filelib.php");
    require_once("$CFG->dirroot/mod/resource/locallib.php");

    if (!$resource = $DB->get_record('resource', array('id'=>$coursemodule->instance), 'id, name, display, displayoptions, tobemigrated, mainfile, revision')) {
        return NULL;
    }

    $info = new object();
    $info->name = $resource->name;

    if ($resource->tobemigrated) {
        $info->icon ='i/cross_red_big';
        return $info;
    }

    $info->icon = str_replace(array('.gif', '.png'), '', file_extension_icon($resource->mainfile));

    $display = resource_get_final_display_type($resource);

    if ($display == RESOURCELIB_DISPLAY_POPUP) {
        $fullurl = "$CFG->wwwroot/mod/resource/view.php?id=$coursemodule->id&amp;redirect=1";
        $options = empty($resource->displayoptions) ? array() : unserialize($resource->displayoptions);
        $width  = empty($options['popupwidth'])  ? 620 : $options['popupwidth'];
        $height = empty($options['popupheight']) ? 450 : $options['popupheight'];
        $wh = "width=$width,height=$height,toolbar=no,location=no,menubar=no,copyhistory=no,status=no,directories=no,scrollbars=yes,resizable=yes";
        $info->extra = urlencode("onclick=\"window.open('$fullurl', '', '$wh'); return false;\"");

    } else if ($display == RESOURCELIB_DISPLAY_NEW) {
        $fullurl = "$CFG->wwwroot/mod/resource/view.php?id=$coursemodule->id&amp;redirect=1";
        $info->extra = urlencode("onclick=\"window.open('$fullurl'); return false;\"");

    } else if ($display == RESOURCELIB_DISPLAY_OPEN) {
        $fullurl = "$CFG->wwwroot/mod/resource/view.php?id=$coursemodule->id&amp;redirect=1";
        $info->extra = urlencode("onclick=\"window.location.href ='$fullurl';return false;\"");

    } else if ($display == RESOURCELIB_DISPLAY_DOWNLOAD) {
        // do not open any window because it would be left there after download
        $context = get_context_instance(CONTEXT_MODULE, $coursemodule->id);
        $path = '/'.$context->id.'/resource_content/'.$resource->revision.$resource->mainfile;
        $fullurl = addslashes_js(file_encode_url($CFG->wwwroot.'/pluginfile.php', $path, true));
        $info->extra = urlencode("onclick=\"window.open('$fullurl'); return false;\"");
    }

    return $info;
}


/**
 * Lists all browsable file areas
 * @param object $course
 * @param object $cm
 * @param object $context
 * @return array
 */
function resource_get_file_areas($course, $cm, $context) {
    $areas = array();
    if (has_capability('moodle/course:managefiles', $context)) {
        $areas['resource_content'] = get_string('resourcecontent', 'resource');
    }
    return $areas;
}

/**
 * File browsing support for resource module ontent area.
 * @param object $browser
 * @param object $areas
 * @param object $course
 * @param object $cm
 * @param object $context
 * @param string $filearea
 * @param int $itemid
 * @param string $filepath
 * @param string $filename
 * @return object file_info instance or null if not found
 */
function resource_get_file_info($browser, $areas, $course, $cm, $context, $filearea, $itemid, $filepath, $filename) {
    global $CFG;

    $canwrite = has_capability('moodle/course:managefiles', $context);

    $fs = get_file_storage();

    if ($filearea === 'resource_content') {
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
        require_once("$CFG->dirroot/mod/resource/locallib.php");
        return new resource_content_file_info($browser, $context, $storedfile, $urlbase, $areas[$filearea], true, true, $canwrite, false);
    }

    // note: resource_intro handled in file_browser automatically

    return null;
}

/**
 * Serves the resource files.
 * @param object $course
 * @param object $cminfo
 * @param object $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @return bool false if file not found, does not return if found - justsend the file
 */
function resource_pluginfile($course, $cminfo, $context, $filearea, $args, $forcedownload) {
    global $CFG, $DB;
    require_once("$CFG->libdir/resourcelib.php");

    if (!$cminfo->uservisible) {
        return false;
    }

    if ($filearea !== 'resource_content') {
        // intro is handled automatically in pluginfile.php
        return false;
    }

    if (!$cm = get_coursemodule_from_instance('resource', $cminfo->instance, $course->id)) {
        return false;
    }

    require_course_login($course, true, $cm);

    array_shift($args); // ignore revision - designed to prevent caching problems only

    $fs = get_file_storage();
    $relativepath = '/'.implode('/', $args);
    $fullpath = $context->id.$filearea.'0'.$relativepath;
    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
        $resource = $DB->get_record('resource', array('id'=>$cminfo->instance), 'id, legacyfiles', MUST_EXIST);
        if ($resource->legacyfiles != RESOURCELIB_LEGACYFILES_ACTIVE) {
            return false;
        }
        require_once("$CFG->dirroot/mod/resource/db/upgradelib.php");
        if (!$file = resource_try_file_migration($relativepath, $cminfo->id, $cminfo->course, 'resource_content', 0)) {
            return false;
        }
        // file migrate - update flag
        $resource->legacyfileslast = time();
        $DB->update_record('resource', $resource);
    }

    // should we apply filters?
    $mimetype = $file->get_mimetype();
    if ($mimetype = 'text/html' or $mimetype = 'text/plain') {
        $filter = $DB->get_field('resource', 'filterfiles', array('id'=>$cminfo->instance));
    } else {
        $filter = 0;
    }

    // finally send the file
    send_stored_file($file, 86400, $filter, $forcedownload);
}
