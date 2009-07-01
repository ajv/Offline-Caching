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
 * This script delegates file serving to individual plugins
 *
 * @package    moodlecore
 * @subpackage file
 * @copyright  2008 Petr Skoda (http://skodak.org)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('config.php');
require_once('lib/filelib.php');

// disable moodle specific debug messages
disable_debugging();

$relativepath = get_file_argument();
$forcedownload = optional_param('forcedownload', 0, PARAM_BOOL);

// relative path must start with '/'
if (!$relativepath) {
    print_error('invalidargorconf');
} else if ($relativepath{0} != '/') {
    print_error('pathdoesnotstartslash');
}

// extract relative path components
$args = explode('/', ltrim($relativepath, '/'));

if (count($args) == 0) { // always at least user id
    print_error('invalidarguments');
}

$contextid = (int)array_shift($args);
$filearea = array_shift($args);

if (!$context = get_context_instance_by_id($contextid)) {
    send_file_not_found();
}
$fs = get_file_storage();


if ($context->contextlevel == CONTEXT_SYSTEM) {
    if ($filearea === 'blog') {

        if (empty($CFG->bloglevel)) {
            print_error('siteblogdisable', 'blog');
        }
        if ($CFG->bloglevel < BLOG_GLOBAL_LEVEL) {
            require_login();
            if (isguestuser()) {
                print_error('noguest');
            }
            if ($CFG->bloglevel == BLOG_USER_LEVEL) {
                if ($USER->id != $entry->userid) {
                    send_file_not_found();
                }
            }
        }
        $entryid = (int)array_shift($args);
        if (!$entry = $DB->get_record('post', array('module'=>'blog', 'id'=>$entryid))) {
            send_file_not_found();
        }
        if ('publishstate' === 'public') {
            if ($CFG->forcelogin) {
                require_login();
            }

        } else if ('publishstate' === 'site') {
            require_login();
            //ok
        } else if ('publishstate' === 'draft') {
            require_login();
            if ($USER->id != $entry->userid) {
                send_file_not_found();
            }
        }

        //TODO: implement shared course and shared group access

        $relativepath = '/'.implode('/', $args);
        $fullpath = $context->id.'blog'.$entryid.$relativepath;

        if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
            send_file_not_found();
        }

        send_stored_file($file, 10*60, 0, true); // download MUST be forced - security!

    } else {
        send_file_not_found();
    }


} else if ($context->contextlevel == CONTEXT_USER) {
    send_file_not_found();


} else if ($context->contextlevel == CONTEXT_COURSECAT) {
    if ($filearea !== 'coursecat_intro') {
        send_file_not_found();
    }

    if ($CFG->forcelogin) {
        // no login necessary - unless login forced everywhere
        require_login();
    }

    $relativepath = '/'.implode('/', $args);
    $fullpath = $context->id.'coursecat_intro0'.$relativepath;

    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->get_filename() == '.') {
        send_file_not_found();
    }

    session_get_instance()->write_close(); // unlock session during fileserving
    send_stored_file($file, 60*60, 0, $forcedownload);


} else if ($context->contextlevel == CONTEXT_COURSE) {
    if (!$course = $DB->get_record('course', array('id'=>$context->instanceid))) {
        print_error('invalidcourseid');
    }

    if ($filearea === 'course_backup') {
        require_login($course);
        require_capability('moodle/site:backupdownload', $context);

        $relativepath = '/'.implode('/', $args);
        $fullpath = $context->id.'course_backup0'.$relativepath;

        if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
            send_file_not_found();
        }

        session_get_instance()->write_close(); // unlock session during fileserving
        send_stored_file($file, 0, 0, true);

    } else if ($filearea === 'course_intro') {
        if ($CFG->forcelogin) {
            require_login();
        }

        $relativepath = '/'.implode('/', $args);
        $fullpath = $context->id.'course_intro0'.$relativepath;

        if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
            send_file_not_found();
        }

        session_get_instance()->write_close(); // unlock session during fileserving
        send_stored_file($file, 60*60, 0, false); // TODO: change timeout?

    } else if ($filearea === 'course_section') {
        if ($CFG->forcelogin) {
            require_login($course);
        } else if ($course->id !== SITEID) {
            require_login($course);
        }

        $sectionid = (int)array_shift($args);

        if ($course->numsections < $sectionid) {
            if (!has_capability('moodle/course:update', $context)) {
                // disable access to invisible sections if can not edit course
                // this is going to break some ugly hacks, but is necessary
                send_file_not_found();
            }
        }

        $relativepath = '/'.implode('/', $args);
        $fullpath = $context->id.'course_section'.$sectionid.$relativepath;

        if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
            send_file_not_found();
        }

        session_get_instance()->write_close(); // unlock session during fileserving
        send_stored_file($file, 60*60, 0, false); // TODO: change timeout?

    } else if ($filearea === 'user_profile') {
        $userid = (int)array_shift($args);
        $usercontext = get_context_instance(CONTEXT_USER, $userid);

        if (!empty($CFG->forceloginforprofiles)) {
            require_login();
            if (isguestuser()) {
                print_error('noguest');
            }

            if (!isteacherinanycourse()
                and !isteacherinanycourse($userid)
                and !has_capability('moodle/user:viewdetails', $usercontext)) {
                print_error('usernotavailable');
            }
            if (!has_capability('moodle/user:viewdetails', $context) &&
                !has_capability('moodle/user:viewdetails', $usercontext)) {
                print_error('cannotviewprofile');
            }
            if (!has_capability('moodle/course:view', $context, $userid, false)) {
                print_error('notenrolledprofile');
            }
            if (groups_get_course_groupmode($course) == SEPARATEGROUPS and !has_capability('moodle/site:accessallgroups', $context)) {
                print_error('groupnotamember');
            }
        }

        $relativepath = '/'.implode('/', $args);
        $fullpath = $usercontext->id.'user_profile0'.$relativepath;

        if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
            send_file_not_found();
        }

        session_get_instance()->write_close(); // unlock session during fileserving
        send_stored_file($file, 0, 0, true); // must force download - security!

    } else {
        send_file_not_found();
    }

} else if ($context->contextlevel == CONTEXT_MODULE) {

    if (!$coursecontext = get_context_instance_by_id(get_parent_contextid($context))) {
        send_file_not_found();
    }

    if (!$course = $DB->get_record('course', array('id'=>$coursecontext->instanceid))) {
        send_file_not_found();
    }
    $modinfo = get_fast_modinfo($course);
    if (empty($modinfo->cms[$context->instanceid])) {
        send_file_not_found();
    }

    $cminfo = $modinfo->cms[$context->instanceid];
    $modname = $cminfo->modname;
    $libfile = "$CFG->dirroot/mod/$modname/lib.php";
    if (!file_exists($libfile)) {
        send_file_not_found();
    }

    require_once($libfile);
    if ($filearea === $modname.'_intro') {
        if (!plugin_supports('mod', $modname, FEATURE_MOD_INTRO, true)) {
            send_file_not_found();
        }
        if (!$cminfo->uservisible) {
            send_file_not_found();
        }
        // all users may access it
        $relativepath = '/'.implode('/', $args);
        $fullpath = $context->id.$filearea.'0'.$relativepath;

        $fs = get_file_storage();
        if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
            send_file_not_found();
        }

        $lifetime = isset($CFG->filelifetime) ? $CFG->filelifetime : 86400;

        // finally send the file
        send_stored_file($file, $lifetime, 0);
    }

    $filefunction = $modname.'_pluginfile';
    if (function_exists($filefunction)) {
        if ($filefunction($course, $cminfo, $context, $filearea, $args) !== false) {
            die;
        }
    }

} else if ($context->contextlevel == CONTEXT_BLOCK) {
    //not supported yet
    send_file_not_found();


} else {
    send_file_not_found();
}
