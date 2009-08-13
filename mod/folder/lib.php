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
 * Mandatory public API of folder module
 *
 * @package   mod-folder
 * @copyright 2009 Petr Skoda (http://skodak.org)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * List of features supported in Folder module
 * @param string $feature FEATURE_xx constant for requested feature
 * @return mixed True if module supports feature, false if not, null if doesn't know
 */
function folder_supports($feature) {
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
function folder_get_extra_capabilities() {
    return array('moodle/site:accessallgroups');
}

/**
 * This function is used by the reset_course_userdata function in moodlelib.
 * @param $data the data submitted from the reset course.
 * @return array status array
 */
function folder_reset_userdata($data) {
    return array();
}

/**
 * List of view style log actions
 * @return array
 */
function folder_get_view_actions() {
    return array('view', 'view all');
}

/**
 * List of update style log actions
 * @return array
 */
function folder_get_post_actions() {
    return array('update', 'add');
}

/**
 * Add folder instance.
 * @param object $data
 * @param object $mform
 * @return int new folder instance id
 */
function folder_add_instance($data, $mform) {
    global $DB;

    $cmid        = $data->coursemodule;
    $draftitemid = $data->files;

    $data->timemodified = time();
    $data->id = $DB->insert_record('folder', $data);

    // we need to use context now, so we need to make sure all needed info is already in db
    $DB->set_field('course_modules', 'instance', $data->id, array('id'=>$cmid));
    $context = get_context_instance(CONTEXT_MODULE, $cmid);

    if ($draftitemid) {
        file_save_draft_area_files($draftitemid, $context->id, 'folder_content', 0, array('subdirs'=>true));
    }

    return $data->id;
}

/**
 * Update folder instance.
 * @param object $data
 * @param object $mform
 * @return bool true
 */
function folder_update_instance($data, $mform) {
    global $CFG, $DB;

    $cmid        = $data->coursemodule;
    $draftitemid = $data->files;

    $data->timemodified = time();
    $data->id           = $data->instance;
    $data->revision++;

    $DB->update_record('folder', $data);

    $context = get_context_instance(CONTEXT_MODULE, $cmid);
    if ($draftitemid = file_get_submitted_draft_itemid('files')) {
        file_save_draft_area_files($draftitemid, $context->id, 'folder_content', 0, array('subdirs'=>true));
    }

    return true;
}

/**
 * Delete folder instance.
 * @param int $id
 * @return bool true
 */
function folder_delete_instance($id) {
    global $DB;

    if (!$folder = $DB->get_record('folder', array('id'=>$id))) {
        return false;
    }

    // note: all context files are deleted automatically

    $DB->delete_records('folder', array('id'=>$folder->id));

    return true;
}

/**
 * Return use outline
 * @param object $course
 * @param object $user
 * @param object $mod
 * @param object $folder
 * @return object|null
 */
function folder_user_outline($course, $user, $mod, $folder) {
    global $DB;

    if ($logs = $DB->get_records('log', array('userid'=>$user->id, 'module'=>'folder',
                                              'action'=>'view', 'info'=>$folder->id), 'time ASC')) {

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
 * @param object $folder
 */
function folder_user_complete($course, $user, $mod, $folder) {
    global $CFG, $DB;

    if ($logs = $DB->get_records('log', array('userid'=>$user->id, 'module'=>'folder',
                                              'action'=>'view', 'info'=>$folder->id), 'time ASC')) {
        $numviews = count($logs);
        $lastlog = array_pop($logs);

        $strmostrecently = get_string('mostrecently');
        $strnumviews = get_string('numviews', '', $numviews);

        echo "$strnumviews - $strmostrecently ".userdate($lastlog->time);

    } else {
        print_string('neverseen', 'folder');
    }
}

/**
 * Returns the users with data in one folder
 *
 * @param int $folderid
 * @return bool false
 */
function folder_get_participants($folderid) {
    return false;
}

/**
 * Lists all browsable file areas
 * @param object $course
 * @param object $cm
 * @param object $context
 * @return array
 */
function folder_get_file_areas($course, $cm, $context) {
    $areas = array();
    if (has_capability('moodle/course:managefiles', $context)) {
        $areas['folder_content'] = get_string('foldercontent', 'folder');
    }
    return $areas;
}

/**
 * File browsing support for folder module ontent area.
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
function folder_get_file_info($browser, $areas, $course, $cm, $context, $filearea, $itemid, $filepath, $filename) {
    global $CFG;

    $canwrite = has_capability('moodle/course:managefiles', $context);

    $fs = get_file_storage();

    if ($filearea === 'folder_content') {
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
        require_once("$CFG->dirroot/mod/folder/locallib.php");
        return new folder_content_file_info($browser, $context, $storedfile, $urlbase, $areas[$filearea], true, true, $canwrite, false);
    }

    // note: folder_intro handled in file_browser automatically

    return null;
}

/**
 * Serves the folder files.
 *
 * @param object $course
 * @param object $cminfo
 * @param object $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @return bool false if file not found, does not return if found - justsend the file
 */
function folder_pluginfile($course, $cminfo, $context, $filearea, $args, $forcedownload) {
    global $CFG, $DB;

    if (!$cminfo->uservisible) {
        return false;
    }

    if ($filearea !== 'folder_content') {
        // intro is handled automatically in pluginfile.php
        return false;
    }

    if (!$cm = get_coursemodule_from_instance('folder', $cminfo->instance, $course->id)) {
        return false;
    }

    require_course_login($course, true, $cm);

    array_shift($args); // ignore revision - designed to prevent caching problems only

    $fs = get_file_storage();
    $relativepath = '/'.implode('/', $args);
    $fullpath = $context->id.$filearea.'0'.$relativepath;
    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
        return false;
    }

    // finally send the file
    send_stored_file($file, 86400, 0, $forcedownload);
}
