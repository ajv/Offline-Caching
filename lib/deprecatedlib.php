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
 * deprecatedlib.php - Old functions retained only for backward compatibility
 *
 * Old functions retained only for backward compatibility.  New code should not
 * use any of these functions.
 *
 * @package moodlecore
 * @subpackage deprecated
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @deprecated
 */

/**
 * Determines if a user is a teacher (or better)
 *
 * @global object
 * @uses CONTEXT_COURSE
 * @uses CONTEXT_SYSTEM
 * @param int $courseid The id of the course that is being viewed, if any
 * @param int $userid The id of the user that is being tested against. Set this to 0 if you would just like to test against the currently logged in user.
 * @param bool $obsolete_includeadmin Not used any more
 * @return bool
 */
function isteacher($courseid=0, $userid=0, $obsolete_includeadmin=true) {
/// Is the user able to access this course as a teacher?
    global $CFG;

    if ($courseid) {
        $context = get_context_instance(CONTEXT_COURSE, $courseid);
    } else {
        $context = get_context_instance(CONTEXT_SYSTEM);
    }

    return (has_capability('moodle/legacy:teacher', $context, $userid, false)
         or has_capability('moodle/legacy:editingteacher', $context, $userid, false)
         or has_capability('moodle/legacy:admin', $context, $userid, false));
}

/**
 * Determines if a user is a teacher in any course, or an admin
 *
 * @global object
 * @global object
 * @global object
 * @uses CAP_ALLOW
 * @uses CONTEXT_SYSTEM
 * @param int $userid The id of the user that is being tested against. Set this to 0 if you would just like to test against the currently logged in user.
 * @param bool $includeadmin Include anyone wo is an admin as well
 * @return bool
 */
function isteacherinanycourse($userid=0, $includeadmin=true) {
    global $USER, $CFG, $DB;

    if (!$userid) {
        if (empty($USER->id)) {
            return false;
        }
        $userid = $USER->id;
    }

    if (!$DB->record_exists('role_assignments', array('userid'=>$userid))) {    // Has no roles anywhere
        return false;
    }

/// If this user is assigned as an editing teacher anywhere then return true
    if ($roles = get_roles_with_capability('moodle/legacy:editingteacher', CAP_ALLOW)) {
        foreach ($roles as $role) {
            if ($DB->record_exists('role_assignments', array('roleid'=>$role->id, 'userid'=>$userid))) {
                return true;
            }
        }
    }

/// If this user is assigned as a non-editing teacher anywhere then return true
    if ($roles = get_roles_with_capability('moodle/legacy:teacher', CAP_ALLOW)) {
        foreach ($roles as $role) {
            if ($DB->record_exists('role_assignments', array('roleid'=>$role->id, 'userid'=>$userid))) {
                return true;
            }
        }
    }

/// Include admins if required
    if ($includeadmin) {
        $context = get_context_instance(CONTEXT_SYSTEM);
        if (has_capability('moodle/legacy:admin', $context, $userid, false)) {
            return true;
        }
    }

    return false;
}


/**
 * Determines if the specified user is logged in as guest.
 *
 * @global object
 * @uses CONTEXT_SYSTEM
 * @param int $userid The user being tested. You can set this to 0 or leave it blank to test the currently logged in user.
 * @return bool
 */
function isguest($userid=0) {
    global $CFG;

    $context = get_context_instance(CONTEXT_SYSTEM);

    return has_capability('moodle/legacy:guest', $context, $userid, false);
}


/**
 * Get the guest user information from the database
 *
 * @todo Is object(user) a correct return type? Or is array the proper return type with a
 * note that the contents include all details for a user.
 *
 * @return object(user) An associative array with the details of the guest user account.
 */
function get_guest() {
    return get_complete_user_data('username', 'guest');
}

/**
 * Returns $user object of the main teacher for a course
 *
 * @global object
 * @uses CONTEXT_COURSE
 * @param int $courseid The course in question.
 * @return user|false  A {@link $USER} record of the main teacher for the specified course or false if error.
 */
function get_teacher($courseid) {

    global $CFG;

    $context = get_context_instance(CONTEXT_COURSE, $courseid);

    // Pass $view=true to filter hidden caps if the user cannot see them
    if ($users = get_users_by_capability($context, 'moodle/course:update', 'u.*', 'u.id ASC',
                                         '', '', '', '', false, true)) {
        $users = sort_by_roleassignment_authority($users, $context);
        return array_shift($users);
    }

    return false;
}

/**
 * Searches logs to find all enrolments since a certain date
 *
 * used to print recent activity
 *
 * @global object
 * @uses CONTEXT_COURSE
 * @param int $courseid The course in question.
 * @param int $timestart The date to check forward of
 * @return object|false  {@link $USER} records or false if error.
 */
function get_recent_enrolments($courseid, $timestart) {
    global $DB;

    $context = get_context_instance(CONTEXT_COURSE, $courseid);

    $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, l.time
              FROM {user} u, {role_assignments} ra, {log} l
             WHERE l.time > ?
                   AND l.course = ?
                   AND l.module = 'course'
                   AND l.action = 'enrol'
                   AND ".$DB->sql_cast_char2int('l.info')." = u.id
                   AND u.id = ra.userid
                   AND ra.contextid ".get_related_contexts_string($context)."
          ORDER BY l.time ASC";
    $params = array($timestart, $courseid);
    return $DB->get_records_sql($sql, $params);
}

########### FROM weblib.php ##########################################################################


/**
 * Print a message in a standard themed box.
 * This old function used to implement boxes using tables.  Now it uses a DIV, but the old
 * parameters remain.  If possible, $align, $width and $color should not be defined at all.
 * Preferably just use print_box() in weblib.php
 *
 * @deprecated
 * @param string $message The message to display
 * @param string $align alignment of the box, not the text (default center, left, right).
 * @param string $width width of the box, including units %, for example '100%'.
 * @param string $color background colour of the box, for example '#eee'.
 * @param int $padding padding in pixels, specified without units.
 * @param string $class space-separated class names.
 * @param string $id space-separated id names.
 * @param boolean $return return as string or just print it
 * @return string|void Depending on $return
 */
function print_simple_box($message, $align='', $width='', $color='', $padding=5, $class='generalbox', $id='', $return=false) {
    $output = '';
    $output .= print_simple_box_start($align, $width, $color, $padding, $class, $id, true);
    $output .= $message;
    $output .= print_simple_box_end(true);

    if ($return) {
        return $output;
    } else {
        echo $output;
    }
}



/**
 * This old function used to implement boxes using tables.  Now it uses a DIV, but the old
 * parameters remain.  If possible, $align, $width and $color should not be defined at all.
 * Even better, please use print_box_start() in weblib.php
 *
 * @param string $align alignment of the box, not the text (default center, left, right).   DEPRECATED
 * @param string $width width of the box, including % units, for example '100%'.            DEPRECATED
 * @param string $color background colour of the box, for example '#eee'.                   DEPRECATED
 * @param int $padding padding in pixels, specified without units.                          OBSOLETE
 * @param string $class space-separated class names.
 * @param string $id space-separated id names.
 * @param boolean $return return as string or just print it
 * @return string|void Depending on $return
 */
function print_simple_box_start($align='', $width='', $color='', $padding=5, $class='generalbox', $id='', $return=false) {
    debugging('print_simple_box(_start/_end) is deprecated. Please use $OUTPUT->box(_start/_end) instead', DEBUG_DEVELOPER);

    $output = '';

    $divclasses = 'box '.$class.' '.$class.'content';
    $divstyles  = '';

    if ($align) {
        $divclasses .= ' boxalign'.$align;    // Implement alignment using a class
    }
    if ($width) {    // Hopefully we can eliminate these in calls to this function (inline styles are bad)
        if (substr($width, -1, 1) == '%') {    // Width is a % value
            $width = (int) substr($width, 0, -1);    // Extract just the number
            if ($width < 40) {
                $divclasses .= ' boxwidthnarrow';    // Approx 30% depending on theme
            } else if ($width > 60) {
                $divclasses .= ' boxwidthwide';      // Approx 80% depending on theme
            } else {
                $divclasses .= ' boxwidthnormal';    // Approx 50% depending on theme
            }
        } else {
            $divstyles  .= ' width:'.$width.';';     // Last resort
        }
    }
    if ($color) {    // Hopefully we can eliminate these in calls to this function (inline styles are bad)
        $divstyles  .= ' background:'.$color.';';
    }
    if ($divstyles) {
        $divstyles = ' style="'.$divstyles.'"';
    }

    if ($id) {
        $id = ' id="'.$id.'"';
    }

    $output .= '<div'.$id.$divstyles.' class="'.$divclasses.'">';

    if ($return) {
        return $output;
    } else {
        echo $output;
    }
}


/**
 * Print the end portion of a standard themed box.
 * Preferably just use print_box_end() in weblib.php
 *
 * @param boolean $return return as string or just print it
 * @return string|void Depending on $return
 */
function print_simple_box_end($return=false) {
    $output = '</div>';
    if ($return) {
        return $output;
    } else {
        echo $output;
    }
}

/**
 * deprecated - use clean_param($string, PARAM_FILE); instead
 * Check for bad characters ?
 *
 * @todo Finish documenting this function - more detail needed in description as well as details on arguments
 *
 * @param string $string ?
 * @param int $allowdots ?
 * @return bool
 */
function detect_munged_arguments($string, $allowdots=1) {
    if (substr_count($string, '..') > $allowdots) {   // Sometimes we allow dots in references
        return true;
    }
    if (ereg('[\|\`]', $string)) {  // check for other bad characters
        return true;
    }
    if (empty($string) or $string == '/') {
        return true;
    }

    return false;
}


/**
 * Unzip one zip file to a destination dir
 * Both parameters must be FULL paths
 * If destination isn't specified, it will be the
 * SAME directory where the zip file resides.
 *
 * @global object
 * @param string $zipfile The zip file to unzip
 * @param string $destination The location to unzip to
 * @param bool $showstatus_ignored Unused
 */
function unzip_file($zipfile, $destination = '', $showstatus_ignored = true) {
    global $CFG;

    //Extract everything from zipfile
    $path_parts = pathinfo(cleardoubleslashes($zipfile));
    $zippath = $path_parts["dirname"];       //The path of the zip file
    $zipfilename = $path_parts["basename"];  //The name of the zip file
    $extension = $path_parts["extension"];    //The extension of the file

    //If no file, error
    if (empty($zipfilename)) {
        return false;
    }

    //If no extension, error
    if (empty($extension)) {
        return false;
    }

    //Clear $zipfile
    $zipfile = cleardoubleslashes($zipfile);

    //Check zipfile exists
    if (!file_exists($zipfile)) {
        return false;
    }

    //If no destination, passed let's go with the same directory
    if (empty($destination)) {
        $destination = $zippath;
    }

    //Clear $destination
    $destpath = rtrim(cleardoubleslashes($destination), "/");

    //Check destination path exists
    if (!is_dir($destpath)) {
        return false;
    }

    $packer = get_file_packer('application/zip');

    $result = $packer->extract_to_pathname($zipfile, $destpath);

    if ($result === false) {
        return false;
    }

    foreach ($result as $status) {
        if ($status !== true) {
            return false;
        }
    }

    return true;
}

/**
 * Zip an array of files/dirs to a destination zip file
 * Both parameters must be FULL paths to the files/dirs
 *
 * @global object
 * @param array $originalfiles Files to zip
 * @param string $destination The destination path
 * @return bool Outcome
 */
function zip_files ($originalfiles, $destination) {
    global $CFG;

    //Extract everything from destination
    $path_parts = pathinfo(cleardoubleslashes($destination));
    $destpath = $path_parts["dirname"];       //The path of the zip file
    $destfilename = $path_parts["basename"];  //The name of the zip file
    $extension = $path_parts["extension"];    //The extension of the file

    //If no file, error
    if (empty($destfilename)) {
        return false;
    }

    //If no extension, add it
    if (empty($extension)) {
        $extension = 'zip';
        $destfilename = $destfilename.'.'.$extension;
    }

    //Check destination path exists
    if (!is_dir($destpath)) {
        return false;
    }

    //Check destination path is writable. TODO!!

    //Clean destination filename
    $destfilename = clean_filename($destfilename);

    //Now check and prepare every file
    $files = array();
    $origpath = NULL;

    foreach ($originalfiles as $file) {  //Iterate over each file
        //Check for every file
        $tempfile = cleardoubleslashes($file); // no doubleslashes!
        //Calculate the base path for all files if it isn't set
        if ($origpath === NULL) {
            $origpath = rtrim(cleardoubleslashes(dirname($tempfile)), "/");
        }
        //See if the file is readable
        if (!is_readable($tempfile)) {  //Is readable
            continue;
        }
        //See if the file/dir is in the same directory than the rest
        if (rtrim(cleardoubleslashes(dirname($tempfile)), "/") != $origpath) {
            continue;
        }
        //Add the file to the array
        $files[] = $tempfile;
    }

    $zipfiles = array();
    $start = strlen($origpath)+1;
    foreach($files as $file) {
        $zipfiles[substr($file, $start)] = $file;
    }

    $packer = get_file_packer('application/zip');

    return $packer->archive_to_pathname($zipfiles, $destpath . '/' . $destfilename);
}

/////////////////////////////////////////////////////////////
/// Old functions not used anymore - candidates for removal
/////////////////////////////////////////////////////////////


/** various deprecated groups function **/


/**
 * Get the IDs for the user's groups in the given course.
 *
 * @global object
 * @param int $courseid The course being examined - the 'course' table id field.
 * @return array|bool An _array_ of groupids, or false
 * (Was return $groupids[0] - consequences!)
 */
function mygroupid($courseid) {
    global $USER;
    if ($groups = groups_get_all_groups($courseid, $USER->id)) {
        return array_keys($groups);
    } else {
        return false;
    }
}


/**
 * Returns the current group mode for a given course or activity module
 *
 * Could be false, SEPARATEGROUPS or VISIBLEGROUPS    (<-- Martin)
 *
 * @param object $course Course Object
 * @param object $cm Course Manager Object
 * @return mixed $course->groupmode
 */
function groupmode($course, $cm=null) {

    if (isset($cm->groupmode) && empty($course->groupmodeforce)) {
        return $cm->groupmode;
    }
    return $course->groupmode;
}

/**
 * Sets the current group in the session variable
 * When $SESSION->currentgroup[$courseid] is set to 0 it means, show all groups.
 * Sets currentgroup[$courseid] in the session variable appropriately.
 * Does not do any permission checking.
 *
 * @global object
 * @param int $courseid The course being examined - relates to id field in
 * 'course' table.
 * @param int $groupid The group being examined.
 * @return int Current group id which was set by this function
 */
function set_current_group($courseid, $groupid) {
    global $SESSION;
    return $SESSION->currentgroup[$courseid] = $groupid;
}


/**
 * Gets the current group - either from the session variable or from the database.
 *
 * @global object
 * @param int $courseid The course being examined - relates to id field in
 * 'course' table.
 * @param bool $full If true, the return value is a full record object.
 * If false, just the id of the record.
 * @return int|bool
 */
function get_current_group($courseid, $full = false) {
    global $SESSION;

    if (isset($SESSION->currentgroup[$courseid])) {
        if ($full) {
            return groups_get_group($SESSION->currentgroup[$courseid]);
        } else {
            return $SESSION->currentgroup[$courseid];
        }
    }

    $mygroupid = mygroupid($courseid);
    if (is_array($mygroupid)) {
        $mygroupid = array_shift($mygroupid);
        set_current_group($courseid, $mygroupid);
        if ($full) {
            return groups_get_group($mygroupid);
        } else {
            return $mygroupid;
        }
    }

    if ($full) {
        return false;
    } else {
        return 0;
    }
}


/**
 * Print an error page displaying an error message.
 * Old method, don't call directly in new code - use print_error instead.
 *
 * @global object
 * @param string $message The message to display to the user about the error.
 * @param string $link The url where the user will be prompted to continue. If no url is provided the user will be directed to the site index page.
 * @return void Terminates script, does not return!
 */
function error($message, $link='') {
    global $UNITTEST, $OUTPUT;

    // If unittest running, throw exception instead
    if (!empty($UNITTEST->running)) {
        // Errors in unit test become exceptions, so you can unit test
        // code that might call error().
        throw new moodle_exception('notlocalisederrormessage', 'error', $link, $message);
    }

    list($message, $moreinfourl, $link) = prepare_error_message('notlocalisederrormessage', 'error', $link, $message);
    $OUTPUT->fatal_error($message, $moreinfourl, $link, debug_backtrace(), null, true); // show debug warning
    die;
}


/// Deprecated DDL functions, to be removed soon ///
/**
 * @deprecated
 * @global object
 * @param string $table
 * @return bool
 */
function table_exists($table) {
    global $DB;
    debugging('Deprecated ddllib function used!');
    return $DB->get_manager()->table_exists($table);
}

/**
 * @deprecated
 * @global object
 * @param string $table
 * @param string $field
 * @return bool
 */
function field_exists($table, $field) {
    global $DB;
    debugging('Deprecated ddllib function used!');
    return $DB->get_manager()->field_exists($table, $field);
}

/**
 * @deprecated
 * @global object
 * @param string $table
 * @param string $index
 * @return bool
 */
function find_index_name($table, $index) {
    global $DB;
    debugging('Deprecated ddllib function used!');
    return $DB->get_manager()->find_index_name($table, $index);
}

/**
 * @deprecated
 * @global object
 * @param string $table
 * @param string $index
 * @return bool
 */
function index_exists($table, $index) {
    global $DB;
    debugging('Deprecated ddllib function used!');
    return $DB->get_manager()->index_exists($table, $index);
}

/**
 * @deprecated
 * @global object
 * @param string $table
 * @param string $field
 * @return bool
 */
function find_check_constraint_name($table, $field) {
    global $DB;
    debugging('Deprecated ddllib function used!');
    return $DB->get_manager()->find_check_constraint_name($table, $field);
}

/**
 * @deprecated
 * @global object
 */
function check_constraint_exists($table, $field) {
    global $DB;
    debugging('Deprecated ddllib function used!');
    return $DB->get_manager()->check_constraint_exists($table, $field);
}

/**
 * @deprecated
 * @global object
 * @param string $table
 * @param string $xmldb_key
 * @return bool
 */
function find_key_name($table, $xmldb_key) {
    global $DB;
    debugging('Deprecated ddllib function used!');
    return $DB->get_manager()->find_key_name($table, $xmldb_key);
}

/**
 * @deprecated
 * @global object
 * @param string $table
 * @return bool
 */
function find_sequence_name($table) {
    global $DB;
    debugging('Deprecated ddllib function used!');
    return $DB->get_manager()->find_sequence_name($table);
}

/**
 * @deprecated
 * @global object
 * @param string $table
 * @return bool
 */
function drop_table($table) {
    global $DB;
    debugging('Deprecated ddllib function used!');
    $DB->get_manager()->drop_table($table);
    return true;
}

/**
 * @deprecated
 * @global object
 * @param string $file
 * @return bool
 */
function install_from_xmldb_file($file) {
    global $DB;
    debugging('Deprecated ddllib function used!');
    $DB->get_manager()->install_from_xmldb_file($file);
    return true;
}

/**
 * @deprecated
 * @global object
 * @param string $table
 * @return bool
 */
function create_table($table) {
    global $DB;
    debugging('Deprecated ddllib function used!');
    $DB->get_manager()->create_table($table);
    return true;
}

/**
 * @deprecated
 * @global object
 * @param string $table
 * @return bool
 */
function create_temp_table($table) {
    global $DB;
    debugging('Deprecated ddllib function used!');
    $DB->get_manager()->create_temp_table($table);
    return true;
}

/**
 * @deprecated
 * @global object
 * @param string $table
 * @param string $newname
 * @return bool
 */
function rename_table($table, $newname) {
    global $DB;
    debugging('Deprecated ddllib function used!');
    $DB->get_manager()->rename_table($table, $newname);
    return true;
}

/**
 * @deprecated
 * @global object
 * @param string $table
 * @param string $field
 * @return bool
 */
function add_field($table, $field) {
    global $DB;
    debugging('Deprecated ddllib function used!');
    $DB->get_manager()->add_field($table, $field);
    return true;
}

/**
 * @deprecated
 * @global object
 * @param string $table
 * @param string $field
 * @return bool
 */
function drop_field($table, $field) {
    global $DB;
    debugging('Deprecated ddllib function used!');
    $DB->get_manager()->drop_field($table, $field);
    return true;
}

/**
 * @deprecated
 * @global object
 * @param string $table
 * @param string $field
 * @return bool
 */
function change_field_type($table, $field) {
    global $DB;
    debugging('Deprecated ddllib function used!');
    $DB->get_manager()->change_field_type($table, $field);
    return true;
}

/**
 * @deprecated
 * @global object
 * @param string $table
 * @param string $field
 * @return bool
 */
function change_field_precision($table, $field) {
    global $DB;
    debugging('Deprecated ddllib function used!');
    $DB->get_manager()->change_field_precision($table, $field);
    return true;
}

/**
 * @deprecated
 * @global object
 * @param string $table
 * @param string $field
 * @return bool
 */
function change_field_unsigned($table, $field) {
    global $DB;
    debugging('Deprecated ddllib function used!');
    $DB->get_manager()->change_field_unsigned($table, $field);
    return true;
}

/**
 * @deprecated
 * @global object
 * @param string $table
 * @param string $field
 * @return bool
 */
function change_field_notnull($table, $field) {
    global $DB;
    debugging('Deprecated ddllib function used!');
    $DB->get_manager()->change_field_notnull($table, $field);
    return true;
}

/**
 * @deprecated
 * @global object
 * @param string $table
 * @param string $field
 * @return bool
 */
function change_field_enum($table, $field) {
    global $DB;
    debugging('Deprecated ddllib function used! Only dropping of enums is allowed.');
    $DB->get_manager()->drop_enum_from_field($table, $field);
    return true;
}

/**
 * @deprecated
 * @global object
 * @param string $table
 * @param string $field
 * @return bool
 */
function change_field_default($table, $field) {
    global $DB;
    debugging('Deprecated ddllib function used!');
    $DB->get_manager()->change_field_default($table, $field);
    return true;
}

/**
 * @deprecated
 * @global object
 * @param string $table
 * @param string $field
 * @param string $newname
 * @return bool
 */
function rename_field($table, $field, $newname) {
    global $DB;
    debugging('Deprecated ddllib function used!');
    $DB->get_manager()->rename_field($table, $field, $newname);
    return true;
}

/**
 * @deprecated
 * @global object
 * @param string $table
 * @param string $key
 * @return bool
 */
function add_key($table, $key) {
    global $DB;
    debugging('Deprecated ddllib function used!');
    $DB->get_manager()->add_key($table, $key);
    return true;
}

/**
 * @deprecated
 * @global object
 * @param string $table
 * @param string $key
 * @return bool
 */
function drop_key($table, $key) {
    global $DB;
    debugging('Deprecated ddllib function used!');
    $DB->get_manager()->drop_key($table, $key);
    return true;
}

/**
 * @deprecated
 * @global object
 * @param string $table
 * @param string $key
 * @param string $newname
 * @return bool
 */
function rename_key($table, $key, $newname) {
    global $DB;
    debugging('Deprecated ddllib function used!');
    $DB->get_manager()->rename_key($table, $key, $newname);
    return true;
}

/**
 * @deprecated
 * @global object
 * @param string $table
 * @param string $index
 * @return bool
 */
function add_index($table, $index) {
    global $DB;
    debugging('Deprecated ddllib function used!');
    $DB->get_manager()->add_index($table, $index);
    return true;
}

/**
 * @deprecated
 * @global object
 * @param string $table
 * @param string $index
 * @return bool
 */
function drop_index($table, $index) {
    global $DB;
    debugging('Deprecated ddllib function used!');
    $DB->get_manager()->drop_index($table, $index);
    return true;
}

/**
 * @deprecated
 * @global object
 * @param string $table
 * @param string $index
 * @param string $newname
 * @return bool
 */
function rename_index($table, $index, $newname) {
    global $DB;
    debugging('Deprecated ddllib function used!');
    $DB->get_manager()->rename_index($table, $index, $newname);
    return true;
}


//////////////////////////
/// removed functions ////
//////////////////////////

/**
 * @deprecated
 * @param mixed $mixed
 * @return void Throws an error and does nothing
 */
function stripslashes_safe($mixed) {
    error('stripslashes_safe() not available anymore');
}
/**
 * @deprecated
 * @param mixed $var
 * @return void Throws an error and does nothing
 */
function stripslashes_recursive($var) {
    error('stripslashes_recursive() not available anymore');
}
/**
 * @deprecated
 * @param mixed $dataobject
 * @return void Throws an error and does nothing
 */
function addslashes_object($dataobject) {
    error('addslashes_object() not available anymore');
}
/**
 * @deprecated
 * @param mixed $var
 * @return void Throws an error and does nothing
 */
function addslashes_recursive($var) {
    error('addslashes_recursive() not available anymore');
}
/**
 * @deprecated
 * @param mixed $command
 * @param bool $feedback
 * @return void Throws an error and does nothing
 */
function execute_sql($command, $feedback=true) {
    error('execute_sql() not available anymore');
}
/**
 * @deprecated
 * @param mixed $table
 * @param mixed $select
 * @return void Throws an error and does nothing
 */
function record_exists_select($table, $select='') {
    error('record_exists_select() not available anymore');
}
/**
 * @deprecated
 * @param mixed $sql
 * @return void Throws an error and does nothing
 */
function record_exists_sql($sql) {
    error('record_exists_sql() not available anymore');
}
/**
 * @deprecated
 * @param mixed $table
 * @param mixed $select
 * @param mixed $countitem
 * @return void Throws an error and does nothing
 */
function count_records_select($table, $select='', $countitem='COUNT(*)') {
    error('count_records_select() not available anymore');
}
/**
 * @deprecated
 * @param mixed $sql
 * @return void Throws an error and does nothing
 */
function count_records_sql($sql) {
    error('count_records_sql() not available anymore');
}
/**
 * @deprecated
 * @param mixed $sql
 * @param bool $expectmultiple
 * @param bool $nolimit
 * @return void Throws an error and does nothing
 */
function get_record_sql($sql, $expectmultiple=false, $nolimit=false) {
    error('get_record_sql() not available anymore');
}
/**
 * @deprecated
 * @param mixed $table
 * @param mixed $select
 * @param mixed $fields
 * @return void Throws an error and does nothing
 */
function get_record_select($table, $select='', $fields='*') {
    error('get_record_select() not available anymore');
}
/**
 * @deprecated
 * @param mixed $table
 * @param mixed $field
 * @param mixed $value
 * @param mixed $sort
 * @param mixed $fields
 * @param mixed $limitfrom
 * @param mixed $limitnum
 * @return void Throws an error and does nothing
 */
function get_recordset($table, $field='', $value='', $sort='', $fields='*', $limitfrom='', $limitnum='') {
    error('get_recordset() not available anymore');
}
/**
 * @deprecated
 * @param mixed $sql
 * @param mixed $limitfrom
 * @param mixed $limitnum
 * @return void Throws an error and does nothing
 */
function get_recordset_sql($sql, $limitfrom=null, $limitnum=null) {
    error('get_recordset_sql() not available anymore');
}
/**
 * @deprecated
 * @param mixed $rs
 * @return void Throws an error and does nothing
 */
function rs_fetch_record(&$rs) {
    error('rs_fetch_record() not available anymore');
}
/**
 * @deprecated
 * @param mixed $rs
 * @return void Throws an error and does nothing
 */
function rs_next_record(&$rs) {
    error('rs_next_record() not available anymore');
}
/**
 * @deprecated
 * @param mixed $rs
 * @return void Throws an error and does nothing
 */
function rs_fetch_next_record(&$rs) {
    error('rs_fetch_next_record() not available anymore');
}
/**
 * @deprecated
 * @param mixed $rs
 * @return void Throws an error and does nothing
 */
function rs_EOF($rs) {
    error('rs_EOF() not available anymore');
}
/**
 * @deprecated
 * @param mixed $rs
 * @return void Throws an error and does nothing
 */
function rs_close(&$rs) {
    error('rs_close() not available anymore');
}
/**
 * @deprecated
 * @param mixed $table
 * @param mixed $select
 * @param mixed $sort
 * @param mixed $fields
 * @param mixed $limitfrom
 * @param mixed $limitnum
 * @return void Throws an error and does nothing
 */
function get_records_select($table, $select='', $sort='', $fields='*', $limitfrom='', $limitnum='') {
    error('get_records_select() not available anymore');
}
/**
 * @deprecated
 * @param mixed $table
 * @param mixed $return
 * @param mixed $select
 * @return void Throws an error and does nothing
 */
function get_field_select($table, $return, $select) {
    error('get_field_select() not available anymore');
}
/**
 * @deprecated
 * @param mixed $sql
 * @return void Throws an error and does nothing
 */
function get_field_sql($sql) {
    error('get_field_sql() not available anymore');
}
/**
 * @deprecated
 * @param mixed $sql
 * @param mixed $select
 * @return void Throws an error and does nothing
 */
function delete_records_select($table, $select='') {
    error('get_field_sql() not available anymore');
}
/**
 * @deprecated
 * @return void Throws an error and does nothing
 */
function configure_dbconnection() {
    error('configure_dbconnection() removed');
}
/**
 * @deprecated
 * @param mixed $field
 * @return void Throws an error and does nothing
 */
function sql_max($field) {
    error('sql_max() removed - use normal sql MAX() instead');
}
/**
 * @deprecated
 * @return void Throws an error and does nothing
 */
function sql_as() {
    error('sql_as() removed - do not use AS for tables at all');
}
/**
 * @deprecated
 * @param mixed $page
 * @param mixed $recordsperpage
 * @return void Throws an error and does nothing
 */
function sql_paging_limit($page, $recordsperpage) {
    error('Function sql_paging_limit() is deprecated. Replace it with the correct use of limitfrom, limitnum parameters');
}
/**
 * @deprecated
 * @return void Throws an error and does nothing
 */
function db_uppercase() {
    error('upper() removed - use normal sql UPPER()');
}
/**
 * @deprecated
 * @return void Throws an error and does nothing
 */
function db_lowercase() {
    error('upper() removed - use normal sql LOWER()');
}
/**
 * @deprecated
 * @param mixed $sqlfile
 * @param mixed $sqlstring
 * @return void Throws an error and does nothing
 */
function modify_database($sqlfile='', $sqlstring='') {
    error('modify_database() removed - use new XMLDB functions');
}
/**
 * @deprecated
 * @param mixed $field1
 * @param mixed $value1
 * @param mixed $field2
 * @param mixed $value2
 * @param mixed $field3
 * @param mixed $value3
 * @return void Throws an error and does nothing
 */
function where_clause($field1='', $value1='', $field2='', $value2='', $field3='', $value3='') {
    error('where_clause() removed - use new functions with $conditions parameter');
}
/**
 * @deprecated
 * @param mixed $sqlarr
 * @param mixed $continue
 * @param mixed $feedback
 * @return void Throws an error and does nothing
 */
function execute_sql_arr($sqlarr, $continue=true, $feedback=true) {
    error('execute_sql_arr() removed');
}
/**
 * @deprecated
 * @param mixed $table
 * @param mixed $field
 * @param mixed $values
 * @param mixed $sort
 * @param mixed $fields
 * @param mixed $limitfrom
 * @param mixed $limitnum
 * @return void Throws an error and does nothing
 */
function get_records_list($table, $field='', $values='', $sort='', $fields='*', $limitfrom='', $limitnum='') {
    error('get_records_list() removed');
}
/**
 * @deprecated
 * @param mixed $table
 * @param mixed $field
 * @param mixed $values
 * @param mixed $sort
 * @param mixed $fields
 * @param mixed $limitfrom
 * @param mixed $limitnum
 * @return void Throws an error and does nothing
 */
function get_recordset_list($table, $field='', $values='', $sort='', $fields='*', $limitfrom='', $limitnum='') {
    error('get_recordset_list() removed');
}
/**
 * @deprecated
 * @param mixed $table
 * @param mixed $field
 * @param mixed $value
 * @param mixed $sort
 * @param mixed $fields
 * @param mixed $limitfrom
 * @param mixed $limitnum
 * @return void Throws an error and does nothing
 */
function get_records_menu($table, $field='', $value='', $sort='', $fields='*', $limitfrom='', $limitnum='') {
    error('get_records_menu() removed');
}
/**
 * @deprecated
 * @param mixed $table
 * @param mixed $select
 * @param mixed $sort
 * @param mixed $fields
 * @param mixed $limitfrom
 * @param mixed $limitnum
 * @return void Throws an error and does nothing
 */
function get_records_select_menu($table, $select='', $sort='', $fields='*', $limitfrom='', $limitnum='') {
    error('get_records_select_menu() removed');
}
/**
 * @deprecated
 * @param mixed $sql
 * @param mixed $limitfrom
 * @param mixed $limitnum
 * @return void Throws an error and does nothing
 */
function get_records_sql_menu($sql, $limitfrom='', $limitnum='') {
    error('get_records_sql_menu() removed');
}
/**
 * @deprecated
 * @param mixed $table
 * @param mixed $column
 * @return void Throws an error and does nothing
 */
function column_type($table, $column) {
    error('column_type() removed');
}
/**
 * @deprecated
 * @param mixed $rs
 * @return void Throws an error and does nothing
 */
function recordset_to_menu($rs) {
    error('recordset_to_menu() removed');
}
/**
 * @deprecated
 * @param mixed $records
 * @param mixed $field1
 * @param mixed $field2
 * @return void Throws an error and does nothing
 */
function records_to_menu($records, $field1, $field2) {
    error('records_to_menu() removed');
}
/**
 * @deprecated
 * @param mixed $table
 * @param mixed $newfield
 * @param mixed $newvalue
 * @param mixed $select
 * @param mixed $localcall
 * @return void Throws an error and does nothing
 */
function set_field_select($table, $newfield, $newvalue, $select, $localcall = false) {
    error('set_field_select() removed');
}
/**
 * @deprecated
 * @param mixed $table
 * @param mixed $return
 * @param mixed $select
 * @return void Throws an error and does nothing
 */
function get_fieldset_select($table, $return, $select) {
    error('get_fieldset_select() removed');
}
/**
 * @deprecated
 * @param mixed $sql
 * @return void Throws an error and does nothing
 */
function get_fieldset_sql($sql) {
    error('get_fieldset_sql() removed');
}
/**
 * @deprecated
 * @return void Throws an error and does nothing
 */
function sql_ilike() {
    error('sql_ilike() not available anymore');
}
/**
 * @deprecated
 * @param mixed $first
 * @param mixed $last
 * @return void Throws an error and does nothing
 */
function sql_fullname($first='firstname', $last='lastname') {
    error('sql_fullname() not available anymore');
}
/**
 * @deprecated
 * @return void Throws an error and does nothing
 */
function sql_concat() {
    error('sql_concat() not available anymore');
}
/**
 * @deprecated
 * @return void Throws an error and does nothing
 */
function sql_empty() {
    error('sql_empty() not available anymore');
}
/**
 * @deprecated
 * @return void Throws an error and does nothing
 */
function sql_substr() {
    error('sql_substr() not available anymore');
}
/**
 * @deprecated
 * @param mixed $int1
 * @param mixed $int2
 * @return void Throws an error and does nothing
 */
function sql_bitand($int1, $int2) {
    error('sql_bitand() not available anymore');
}
/**
 * @deprecated
 * @param mixed $int1
 * @return void Throws an error and does nothing
 */
function sql_bitnot($int1) {
    error('sql_bitnot() not available anymore');
}
/**
 * @deprecated
 * @param mixed $int1
 * @param mixed $int2
 * @return void Throws an error and does nothing
 */
function sql_bitor($int1, $int2) {
    error('sql_bitor() not available anymore');
}
/**
 * @deprecated
 * @param mixed $int1
 * @param mixed $int2
 * @return void Throws an error and does nothing
 */
function sql_bitxor($int1, $int2) {
    error('sql_bitxor() not available anymore');
}
/**
 * @deprecated
 * @param mixed $fieldname
 * @param mixed $text
 * @return void Throws an error and does nothing
 */
function sql_cast_char2int($fieldname, $text=false) {
    error('sql_cast_char2int() not available anymore');
}
/**
 * @deprecated
 * @param mixed $fieldname
 * @param mixed $numchars
 * @return void Throws an error and does nothing
 */
function sql_compare_text($fieldname, $numchars=32) {
    error('sql_compare_text() not available anymore');
}
/**
 * @deprecated
 * @param mixed $fieldname
 * @param mixed $numchars
 * @return void Throws an error and does nothing
 */
function sql_order_by_text($fieldname, $numchars=32) {
    error('sql_order_by_text() not available anymore');
}
/**
 * @deprecated
 * @param mixed $fieldname
 * @return void Throws an error and does nothing
 */
function sql_length($fieldname) {
    error('sql_length() not available anymore');
}
/**
 * @deprecated
 * @param mixed $separator
 * @param mixed $elements
 * @return void Throws an error and does nothing
 */
function sql_concat_join($separator="' '", $elements=array()) {
    error('sql_concat_join() not available anymore');
}
/**
 * @deprecated
 * @param mixed $tablename
 * @param mixed $fieldname
 * @param mixed $nullablefield
 * @param mixed $textfield
 * @return void Throws an error and does nothing
 */
function sql_isempty($tablename, $fieldname, $nullablefield, $textfield) {
    error('sql_isempty() not available anymore');
}
/**
 * @deprecated
 * @param mixed $tablename
 * @param mixed $fieldname
 * @param mixed $nullablefield
 * @param mixed $textfield
 * @return void Throws an error and does nothing
 */
function sql_isnotempty($tablename, $fieldname, $nullablefield, $textfield) {
    error('sql_isnotempty() not available anymore');
}
/**
 * @deprecated
 * @return void Throws an error and does nothing
 */
function begin_sql() {
    error('begin_sql() not available anymore');
}
/**
 * @deprecated
 * @return void Throws an error and does nothing
 */
function commit_sql() {
    error('commit_sql() not available anymore');
}
/**
 * @deprecated
 * @return void Throws an error and does nothing
 */
function rollback_sql() {
    error('rollback_sql() not available anymore');
}
/**
 * @deprecated
 * @param mixed $table
 * @param mixed $dataobject
 * @param mixed $returnid
 * @param mixed $primarykey
 * @return void Throws an error and does nothing
 */
function insert_record($table, $dataobject, $returnid=true, $primarykey='id') {
    error('insert_record() not available anymore');
}
/**
 * @deprecated
 * @param mixed $table
 * @param mixed $dataobject
 * @return void Throws an error and does nothing
 */
function update_record($table, $dataobject) {
    error('update_record() not available anymore');
}
/**
 * @deprecated
 * @param mixed $table
 * @param mixed $field
 * @param mixed $value
 * @param mixed $sort
 * @param mixed $fields
 * @param mixed $limitfrom
 * @param mixed $limitnum
 *
 * @return void Throws an error and does nothing
 */
function get_records($table, $field='', $value='', $sort='', $fields='*', $limitfrom='', $limitnum='') {
    error('get_records() not available anymore');
}
/**
 * @deprecated
 * @param mixed $table
 * @param mixed $field1
 * @param mixed $value1
 * @param mixed $field2
 * @param mixed $value2
 * @param mixed $field3
 * @param mixed $value3
 * @param mixed $fields
 * @return void Throws an error and does nothing
 */
function get_record($table, $field1, $value1, $field2='', $value2='', $field3='', $value3='', $fields='*') {
    error('get_record() not available anymore');
}
/**
 * @deprecated
 * @param mixed $table
 * @param mixed $newfield
 * @param mixed $newvalue
 * @param mixed $field1
 * @param mixed $value1
 * @param mixed $field2
 * @param mixed $value2
 * @param mixed $field3
 * @param mixed $value3
 * @return void Throws an error and does nothing
 */
function set_field($table, $newfield, $newvalue, $field1, $value1, $field2='', $value2='', $field3='', $value3='') {
    error('set_field() not available anymore');
}
/**
 * @deprecated
 * @param mixed $table
 * @param mixed $field1
 * @param mixed $value1
 * @param mixed $field2
 * @param mixed $value2
 * @param mixed $field3
 * @param mixed $value3
 * @return void Throws an error and does nothing
 */
function count_records($table, $field1='', $value1='', $field2='', $value2='', $field3='', $value3='') {
    error('count_records() not available anymore');
}
/**
 * @deprecated
 * @param mixed $table
 * @param mixed $field1
 * @param mixed $value1
 * @param mixed $field2
 * @param mixed $value2
 * @param mixed $field3
 * @param mixed $value3
 * @return void Throws an error and does nothing
 */
function record_exists($table, $field1='', $value1='', $field2='', $value2='', $field3='', $value3='') {
    error('record_exists() not available anymore');
}
/**
 * @deprecated
 * @param mixed $table
 * @param mixed $field1
 * @param mixed $value1
 * @param mixed $field2
 * @param mixed $value2
 * @param mixed $field3
 * @param mixed $value3
 * @return void Throws an error and does nothing
 */
function delete_records($table, $field1='', $value1='', $field2='', $value2='', $field3='', $value3='') {
    error('delete_records() not available anymore');
}
/**
 * @deprecated
 * @param mixed $table
 * @param mixed $return
 * @param mixed $field1
 * @param mixed $value1
 * @param mixed $field2
 * @param mixed $value2
 * @param mixed $field3
 * @param mixed $value3
 * @return void Throws an error and does nothing
 */
function get_field($table, $return, $field1, $value1, $field2='', $value2='', $field3='', $value3='') {
    error('get_field() not available anymore');
}
/**
 * @deprecated
 * @param mixed $table
 * @param mixed $oldfield
 * @param mixed $field
 * @param mixed $type
 * @param mixed $size
 * @param mixed $signed
 * @param mixed $default
 * @param mixed $null
 * @param mixed $after
 * @return void Throws an error and does nothing
 */
function table_column($table, $oldfield, $field, $type='integer', $size='10',
                      $signed='unsigned', $default='0', $null='not null', $after='') {
    error('table_column() was removed, please use new ddl functions');
}
/**
 * @deprecated
 * @param mixed $name
 * @param mixed $editorhidebuttons
 * @param mixed $id
 * @return void Throws an error and does nothing
 */
function use_html_editor($name='', $editorhidebuttons='', $id='') {
    error('use_html_editor() not available anymore');
}

/**
 * The old method that was used to include JavaScript libraries.
 * Please use $PAGE->requires->js() or $PAGE->requires->yui_lib() instead.
 *
 * @param mixed $lib The library or libraries to load (a string or array of strings)
 *      There are three way to specify the library:
 *      1. a shorname like 'yui_yahoo'. This translates into a call to $PAGE->requires->yui_lib('yahoo')->asap();
 *      2. the path to the library relative to wwwroot, for example 'lib/javascript-static.js'
 *      3. (legacy) a full URL like $CFG->wwwroot . '/lib/javascript-static.js'.
 *      2. and 3. lead to a call $PAGE->requires->js('/lib/javascript-static.js').
 */
function require_js($lib) {
    global $CFG, $PAGE;
    // Add the lib to the list of libs to be loaded, if it isn't already
    // in the list.
    if (is_array($lib)) {
        foreach($lib as $singlelib) {
            require_js($singlelib);
        }
        return;
    }

    // TODO uncomment this once we have eliminated the remaining calls to require_js from core.
    //debugging('Call to deprecated function require_js. Please use $PAGE->requires->js() ' .
    //        'or $PAGE->requires->yui_lib() instead.', DEBUG_DEVELOPER);

    if (strpos($lib, 'yui_') === 0) {
        echo $PAGE->requires->yui_lib(substr($lib, 4))->asap();
    } else if (preg_match('/^https?:/', $lib)) {
        echo $PAGE->requires->js(str_replace($CFG->wwwroot, '', $lib))->asap();
    } else {
        echo $PAGE->requires->js($lib)->asap();
    }
}

/**
 * Makes an upload directory for a particular module.
 *
 * This function has been deprecated by the file API changes in Moodle 2.0.
 *
 * @deprecated
 * @param int $courseid The id of the course in question - maps to id field of 'course' table.
 * @return string|false Returns full path to directory if successful, false if not
 */
function make_mod_upload_directory($courseid) {
    global $CFG;
    debugging('make_mod_upload_directory has been deprecated by the file API changes in Moodle 2.0.', DEBUG_DEVELOPER);
    return make_upload_directory($courseid .'/'. $CFG->moddata);
}


/**
 * This is a slight variatoin on the standard_renderer_factory that uses
 * custom_corners_core_renderer instead of moodle_core_renderer.
 *
 * This generates the slightly different HTML that the custom_corners theme expects.
 *
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @deprecated Required to make the old $THEME->customcorners setting work.
 */
class custom_corners_renderer_factory extends standard_renderer_factory {
    public function __construct($theme) {
        parent::__construct($theme);
        global $CFG;
        require_once($CFG->themedir . '/custom_corners/renderers.php');
    }
    /* Implement the subclass method. */
    public function get_renderer($module, $page, $subtype=null) {
        if ($module == 'core') {
            return new custom_corners_core_renderer($page);
        }
        return parent::get_renderer($module, $page, $subtype);
    }
}


/**
 * Used to be used for setting up the theme. No longer used by core code, and
 * should not have been used elsewhere.
 *
 * The theme is now automatically initialised before it is first used. If you really need
 * to force this to happen, just reference $PAGE->theme.
 *
 * To force a particular theme on a particular page, you can use $PAGE->force_theme(...).
 * However, I can't think of any valid reason to do that outside the theme selector UI.
 *
 * @deprecated
 * @param string $theme The theme to use defaults to current theme
 * @param array $params An array of parameters to use
 */
function theme_setup($theme = '', $params=NULL) {
    throw new coding_exception('The function theme_setup is no longer required, and should no longer be used. ' .
            'The current theme gets initialised automatically before it is first used.');
}

/**
 * @deprecated use $PAGE->theme->name instead.
 * @return string the name of the current theme.
 */
function current_theme() {
    global $PAGE;
    // TODO, uncomment this once we have eliminated all references to current_theme in core code.
    // debugging('current_theme is deprecated, use $PAGE->theme->name instead', DEBUG_DEVELOPER);
    return $PAGE->theme->name;
}

/**
 * This used to be the thing that theme styles.php files used to do all the work.
 * This is now handled differently. You should copy theme/standard/styes.php
 * into your theme.
 *
 * @deprecated
 * @param int $lastmodified Always gets set to now
 * @param int $lifetime The max-age header setting (seconds) defaults to 300
 * @param string $themename The name of the theme to use (optional) defaults to current theme
 * @param string $forceconfig Force a particular theme config (optional)
 * @param string $lang Load styles for the specified language (optional)
 */
function style_sheet_setup($lastmodified=0, $lifetime=300, $themename='', $forceconfig='', $lang='') {
    global $CFG, $PAGE, $THEME, $showdeprecatedstylesheetsetupwarning;
    $showdeprecatedstylesheetsetupwarning = true;
    include($CFG->dirroot . '/theme/styles.php');
    exit;
}

/**
 * @todo Remove this deprecated function when no longer used
 * @deprecated since Moodle 2.0 - use $PAGE->pagetype instead of the .
 *
 * @param string $getid used to return $PAGE->pagetype.
 * @param string $getclass used to return $PAGE->legacyclass.
 */
function page_id_and_class(&$getid, &$getclass) {
    global $PAGE;
    debugging('Call to deprecated function page_id_and_class. Please use $PAGE->pagetype instead.', DEBUG_DEVELOPER);
    $getid = $PAGE->pagetype;
    $getclass = $PAGE->legacyclass;
}

/**
 * Prints some red text using echo
 *
 * @deprecated
 * @param string $error The text to be displayed in red
 */
function formerr($error) {
    global $OUTPUT;
    echo $OUTPUT->error_text($error);
}

/**
 * Return the markup for the destination of the 'Skip to main content' links.
 * Accessibility improvement for keyboard-only users.
 *
 * Used in course formats, /index.php and /course/index.php
 *
 * @deprecated use $OUTPUT->skip_link_target() in instead.
 * @return string HTML element.
 */
function skip_main_destination() {
    global $OUTPUT;
    return $OUTPUT->skip_link_target();
}

/**
 * Prints a string in a specified size  (retained for backward compatibility)
 *
 * @deprecated
 * @param string $text The text to be displayed
 * @param int $size The size to set the font for text display.
 * @param bool $return If set to true output is returned rather than echoed Default false
 * @return string|void String if return is true
 */
function print_headline($text, $size=2, $return=false) {
    global $OUTPUT;
    $output = $OUTPUT->heading($text, $size);
    if ($return) {
        return $output;
    } else {
        echo $output;
    }
}

/**
 * Prints text in a format for use in headings.
 *
 * @deprecated
 * @param string $text The text to be displayed
 * @param string $deprecated No longer used. (Use to do alignment.)
 * @param int $size The size to set the font for text display.
 * @param string $class
 * @param bool $return If set to true output is returned rather than echoed, default false
 * @param string $id The id to use in the element
 * @return string|void String if return=true nothing otherwise
 */
function print_heading($text, $deprecated = '', $size = 2, $class = 'main', $return = false, $id = '') {
    global $OUTPUT;
    if (!empty($deprecated)) {
        debugging('Use of deprecated align attribute of print_heading. ' .
                'Please do not specify styling in PHP code like that.', DEBUG_DEVELOPER);
    }
    $output = $OUTPUT->heading($text, $size, $class, $id);
    if ($return) {
        return $output;
    } else {
        echo $output;
    }
}

/**
 * Output a standard heading block
 *
 * @deprecated
 * @param string $heading The text to write into the heading
 * @param string $class An additional Class Attr to use for the heading
 * @param bool $return If set to true output is returned rather than echoed, default false
 * @return string|void HTML String if return=true nothing otherwise
 */
function print_heading_block($heading, $class='', $return=false) {
    global $OUTPUT;
    $output = $OUTPUT->heading($heading, 2, 'headingblock header ' . moodle_renderer_base::prepare_classes($class));
    if ($return) {
        return $output;
    } else {
        echo $output;
    }
}

/**
 * Print a message in a standard themed box.
 * Replaces print_simple_box (see deprecatedlib.php)
 *
 * @deprecated
 * @param string $message, the content of the box
 * @param string $classes, space-separated class names.
 * @param string $ids
 * @param boolean $return, return as string or just print it
 * @return string|void mixed string or void
 */
function print_box($message, $classes='generalbox', $ids='', $return=false) {
    global $OUTPUT;
    $output = $OUTPUT->box($message, $classes, $ids);
    if ($return) {
        return $output;
    } else {
        echo $output;
    }
}

/**
 * Starts a box using divs
 * Replaces print_simple_box_start (see deprecatedlib.php)
 *
 * @deprecated
 * @param string $classes, space-separated class names.
 * @param string $ids
 * @param boolean $return, return as string or just print it
 * @return string|void  string or void
 */
function print_box_start($classes='generalbox', $ids='', $return=false) {
    global $OUTPUT;
    $output = $OUTPUT->box_start($classes, $ids);
    if ($return) {
        return $output;
    } else {
        echo $output;
    }
}

/**
 * Simple function to end a box (see above)
 * Replaces print_simple_box_end (see deprecatedlib.php)
 *
 * @deprecated
 * @param boolean $return, return as string or just print it
 * @return string|void Depending on value of return
 */
function print_box_end($return=false) {
    global $OUTPUT;
    $output = $OUTPUT->box_end();
    if ($return) {
        return $output;
    } else {
        echo $output;
    }
}

/**
 * Print a message in a standard themed container.
 *
 * @deprecated
 * @param string $message, the content of the container
 * @param boolean $clearfix clear both sides
 * @param string $classes, space-separated class names.
 * @param string $idbase
 * @param boolean $return, return as string or just print it
 * @return string|void Depending on value of $return
 */
function print_container($message, $clearfix=false, $classes='', $idbase='', $return=false) {
    global $OUTPUT;
    if ($clearfix) {
        $classes .= ' clearfix';
    }
    $output = $OUTPUT->container($message, $classes, $idbase);
    if ($return) {
        return $output;
    } else {
        echo $output;
    }
}

/**
 * Starts a container using divs
 *
 * @deprecated
 * @param boolean $clearfix clear both sides
 * @param string $classes, space-separated class names.
 * @param string $idbase
 * @param boolean $return, return as string or just print it
 * @return string|void Based on value of $return
 */
function print_container_start($clearfix=false, $classes='', $idbase='', $return=false) {
    global $OUTPUT;
    if ($clearfix) {
        $classes .= ' clearfix';
    }
    $output = $OUTPUT->container_start($classes, $idbase);
    if ($return) {
        return $output;
    } else {
        echo $output;
    }
}

/**
 * Simple function to end a container (see above)
 *
 * @deprecated
 * @param boolean $return, return as string or just print it
 * @return string|void Based on $return
 */
function print_container_end($return=false) {
    global $OUTPUT;
    $output = $OUTPUT->container_end();
    if ($return) {
        return $output;
    } else {
        echo $output;
    }
}

/**
 * Print a bold message in an optional color.
 *
 * @deprecated use $OUTPUT->notification instead.
 * @param string $message The message to print out
 * @param string $style Optional style to display message text in
 * @param string $align Alignment option
 * @param bool $return whether to return an output string or echo now
 * @return string|bool Depending on $result
 */
function notify($message, $classes = 'notifyproblem', $align = 'center', $return = false) {
    global $OUTPUT;

    if ($classes == 'green') {
        debugging('Use of deprecated class name "green" in notify. Please change to "notifysuccess".', DEBUG_DEVELOPER);
        $classes = 'notifysuccess'; // Backward compatible with old color system
    }

    $output = $OUTPUT->notification($message, $classes);
    if ($return) {
        return $output;
    } else {
        echo $output;
    }
}

/**
 * Print a continue button that goes to a particular URL.
 *
 * @deprecated since Moodle 2.0
 *
 * @param string $link The url to create a link to.
 * @param bool $return If set to true output is returned rather than echoed, default false
 * @return string|void HTML String if return=true nothing otherwise
 */
function print_continue($link, $return = false) {
    global $CFG, $OUTPUT;

    if ($link == '') {
        if (!empty($_SERVER['HTTP_REFERER'])) {
            $link = $_SERVER['HTTP_REFERER'];
            $link = str_replace('&', '&amp;', $link); // make it valid XHTML
        } else {
            $link = $CFG->wwwroot .'/';
        }
    }

    $output = $OUTPUT->continue_button($link);
    if ($return) {
        return $output;
    } else {
        echo $output;
    }
}

/**
 * Returns a string containing a link to the user documentation for the current
 * page. Also contains an icon by default. Shown to teachers and admin only.
 *
 * @deprecated since Moodle 2.0
 *
 * @global object
 * @global object
 * @param string $text The text to be displayed for the link
 * @param string $iconpath The path to the icon to be displayed
 * @return string The link to user documentation for this current page
 */
function page_doc_link($text='', $iconpath='') {
    global $CFG, $PAGE;

    if (empty($CFG->docroot) || during_initial_install()) {
        return '';
    }
    if (!has_capability('moodle/site:doclinks', $PAGE->context)) {
        return '';
    }

    $path = $PAGE->docspath;
    if (!$path) {
        return '';
    }
    return doc_link($path, $text, $iconpath);
}

/**
 * Print a standard header
 *
 * @param string  $title Appears at the top of the window
 * @param string  $heading Appears at the top of the page
 * @param string  $navigation Array of $navlinks arrays (keys: name, link, type) for use as breadcrumbs links
 * @param string  $focus Indicates form element to get cursor focus on load eg  inputform.password
 * @param string  $meta Meta tags to be added to the header
 * @param boolean $cache Should this page be cacheable?
 * @param string  $button HTML code for a button (usually for module editing)
 * @param string  $menu HTML code for a popup menu
 * @param boolean $usexml use XML for this page
 * @param string  $bodytags This text will be included verbatim in the <body> tag (useful for onload() etc)
 * @param bool    $return If true, return the visible elements of the header instead of echoing them.
 * @return string|void If return=true then string else void
 */
function print_header($title='', $heading='', $navigation='', $focus='',
                      $meta='', $cache=true, $button='&nbsp;', $menu='',
                      $usexml=false, $bodytags='', $return=false) {
    global $PAGE, $OUTPUT;

    $PAGE->set_title($title);
    $PAGE->set_heading($heading);
    $PAGE->set_cacheable($cache);
    $PAGE->set_focuscontrol($focus);
    if ($button == '') {
        $button = '&nbsp;';
    }
    $PAGE->set_button($button);

    if ($navigation == 'home') {
        $navigation = '';
    }
    if (gettype($navigation) == 'string' && strlen($navigation) != 0 && $navigation != 'home') {
        debugging("print_header() was sent a string as 3rd ($navigation) parameter. "
                . "This is deprecated in favour of an array built by build_navigation(). Please upgrade your code.", DEBUG_DEVELOPER);
    }

    // TODO $navigation
    // TODO $menu
    
    if ($meta) {
        throw new coding_exception('The $meta parameter to print_header is no longer supported. '.
                'You should be able to do everything you want with $PAGE->requires and other such mechanisms.');
    }
    if ($usexml) {
        throw new coding_exception('The $usexml parameter to print_header is no longer supported.');
    }
    if ($bodytags) {
        throw new coding_exception('The $bodytags parameter to print_header is no longer supported.');
    }

    $output = $OUTPUT->header($navigation, $menu);

    if ($return) {
        return $output;
    } else {
        echo $output;
    }
}

function print_footer($course = NULL, $usercourse = NULL, $return = false) {
    global $PAGE, $OUTPUT;
    // TODO check arguments.
    if (is_string($course)) {
        debugging("Magic values like 'home', 'empty' passed to print_footer no longer have any effect. " .
                'To achieve a similar effect, call $PAGE->set_generaltype before you call print_header.', DEBUG_DEVELOPER);
    } else if (!empty($course->id) && $course->id != $PAGE->course->id) {
        throw new coding_exception('The $course object you passed to print_footer does not match $PAGE->course.');
    }
    if (!is_null($usercourse)) {
        debugging('The second parameter ($usercourse) to print_footer is no longer supported. ' .
                '(I did not think it was being used anywhere.)', DEBUG_DEVELOPER);
    }
    $output = $OUTPUT->footer();
    if ($return) {
        return $output;
    } else {
        echo $output;
    }
}

/**
 * Prints a nice side block with an optional header.  The content can either
 * be a block of HTML or a list of text with optional icons.
 *
 * @todo Finish documenting this function. Show example of various attributes, etc.
 *
 * @static int $block_id Increments for each call to the function
 * @param string $heading HTML for the heading. Can include full HTML or just
 *   plain text - plain text will automatically be enclosed in the appropriate
 *   heading tags.
 * @param string $content HTML for the content
 * @param array $list an alternative to $content, it you want a list of things with optional icons.
 * @param array $icons optional icons for the things in $list.
 * @param string $footer Extra HTML content that gets output at the end, inside a &lt;div class="footer">
 * @param array $attributes an array of attribute => value pairs that are put on the
 * outer div of this block. If there is a class attribute ' sideblock' gets appended to it. If there isn't
 * already a class, class='sideblock' is used.
 * @param string $title Plain text title, as embedded in the $heading.
 * @deprecated
 */
function print_side_block($heading='', $content='', $list=NULL, $icons=NULL, $footer='', $attributes = array(), $title='') {
    global $OUTPUT;

    // We don't use $heading, becuse it often contains HTML that we don't want.
    // However, sometimes $title is not set, but $heading is.
    if (empty($title)) {
        $title = strip_tags($heading);
    }

    // Render list contents to HTML if required.
    if (empty($content) && $list) {
        $content = $OUTPUT->list_block_contents($icons, $list);
    }

    $bc = new block_contents();
    $bc->content = $content;
    $bc->footer = $footer;
    $bc->title = $title;

    if (isset($attributes['id'])) {
        $bc->id = $attributes['id'];
        unset($attributes['id']);
    }
    if (isset($attributes['class'])) {
        $bc->set_classes($attributes['class']);
        unset($attributes['class']);
    }
    $bc->attributes = $attributes;

    echo $OUTPUT->block($bc, BLOCK_POS_LEFT); // POS LEFT may be wrong, but no way to get a better guess here.
}

/**
 * Starts a nice side block with an optional header.
 *
 * @todo Finish documenting this function
 *
 * @global object
 * @global object
 * @param string $heading HTML for the heading. Can include full HTML or just
 *   plain text - plain text will automatically be enclosed in the appropriate
 *   heading tags.
 * @param array $attributes HTML attributes to apply if possible
 * @deprecated
 */
function print_side_block_start($heading='', $attributes = array()) {
    throw new coding_exception('print_side_block_start has been deprecated. Please change your code to use $OUTPUT->block().');
}

/**
 * Print table ending tags for a side block box.
 *
 * @global object
 * @global object
 * @param array $attributes HTML attributes to apply if possible [id]
 * @param string $title
 * @deprecated
 */
function print_side_block_end($attributes = array(), $title='') {
    throw new coding_exception('print_side_block_end has been deprecated. Please change your code to use $OUTPUT->block().');
}

/**
 * This was used by old code to see whether a block region had anything in it,
 * and hence wether that region should be printed.
 *
 * We don't ever want old code to print blocks, so we now always return false.
 * The function only exists to avoid fatal errors in old code.
 *
 * @deprecated since Moodle 2.0. always returns false.
 *
 * @param object $blockmanager
 * @param string $region
 * @return bool
 */
function blocks_have_content(&$blockmanager, $region) {
    debugging('The function blocks_have_content should no longer be used. Blocks are now printed by the theme.');
    return false;
}

/**
 * This was used by old code to print the blocks in a region.
 *
 * We don't ever want old code to print blocks, so this is now a no-op.
 * The function only exists to avoid fatal errors in old code.
 *
 * @deprecated since Moodle 2.0. does nothing.
 *
 * @param object $page
 * @param object $blockmanager
 * @param string $region
 */
function blocks_print_group($page, $blockmanager, $region) {
    debugging('The function blocks_print_group should no longer be used. Blocks are now printed by the theme.');
}

/**
 * This used to be the old entry point for anyone that wants to use blocks.
 * Since we don't want people people dealing with blocks this way any more,
 * just return a suitable empty array.
 *
 * @deprecated since Moodle 2.0.
 *
 * @param object $page
 * @return array
 */
function blocks_setup(&$page, $pinned = BLOCKS_PINNED_FALSE) {
    debugging('The function blocks_print_group should no longer be used. Blocks are now printed by the theme.');
    return array(BLOCK_POS_LEFT => array(), BLOCK_POS_RIGHT => array());
}

/**
 * This iterates over an array of blocks and calculates the preferred width
 * Parameter passed by reference for speed; it's not modified.
 *
 * @deprecated since Moodle 2.0. Layout is now controlled by the theme.
 *
 * @param mixed $instances
 */
function blocks_preferred_width($instances) {
    debugging('The function blocks_print_group should no longer be used. Blocks are now printed by the theme.');
    $width = 210;
}

/**
 * @deprecated since Moodle 2.0. See the replacements in blocklib.php.
 *
 * @param object $page The page object
 * @param object $blockmanager The block manager object
 * @param string $blockaction One of [config, add, delete]
 * @param int|object $instanceorid The instance id or a block_instance object
 * @param bool $pinned
 * @param bool $redirect To redirect or not to that is the question but you should stick with true
 */
function blocks_execute_action($page, &$blockmanager, $blockaction, $instanceorid, $pinned=false, $redirect=true) {
    throw new coding_exception('blocks_execute_action is no longer used. The way blocks work has been changed. See the new code in blocklib.php.');
}

/**
 * You can use this to get the blocks to respond to URL actions without much hassle
 *
 * @deprecated since Moodle 2.0. Blocks have been changed. {@link block_manager::process_url_actions} is the closest replacement.
 *
 * @param object $PAGE
 * @param object $blockmanager
 * @param bool $pinned
 */
function blocks_execute_url_action(&$PAGE, &$blockmanager,$pinned=false) {
    throw new coding_exception('blocks_execute_url_action is no longer used. It has been replaced by methods of block_manager.');
}

/**
 * This shouldn't be used externally at all, it's here for use by blocks_execute_action()
 * in order to reduce code repetition.
 *
 * @deprecated since Moodle 2.0. See the replacements in blocklib.php.
 *
 * @param $instance
 * @param $newpos
 * @param string|int $newweight
 * @param bool $pinned
 */
function blocks_execute_repositioning(&$instance, $newpos, $newweight, $pinned=false) {
    throw new coding_exception('blocks_execute_repositioning is no longer used. The way blocks work has been changed. See the new code in blocklib.php.');
}


/**
 * Moves a block to the new position (column) and weight (sort order).
 *
 * @deprecated since Moodle 2.0. See the replacements in blocklib.php.
 *
 * @param object $instance The block instance to be moved.
 * @param string $destpos BLOCK_POS_LEFT or BLOCK_POS_RIGHT. The destination column.
 * @param string $destweight The destination sort order. If NULL, we add to the end
 *                    of the destination column.
 * @param bool $pinned Are we moving pinned blocks? We can only move pinned blocks
 *                to a new position withing the pinned list. Likewise, we
 *                can only moved non-pinned blocks to a new position within
 *                the non-pinned list.
 * @return boolean success or failure
 */
function blocks_move_block($page, &$instance, $destpos, $destweight=NULL, $pinned=false) {
    throw new coding_exception('blocks_move_block is no longer used. The way blocks work has been changed. See the new code in blocklib.php.');
}

/**
 * Print a nicely formatted table.
 *
 * @deprecated since Moodle 2.0
 *
 * @param array $table is an object with several properties.
 */
function print_table($table, $return=false) {
    global $OUTPUT;
    // TODO MDL-19755 turn debugging on once we migrate the current core code to use the new API
    // debugging('print_table() has been deprecated. Please change your code to use $OUTPUT->table().');
    $newtable = new html_table();
    foreach ($table as $property => $value) {
        if (property_exists($newtable, $property)) {
            $newtable->{$property} = $value;
        }
    }
    if (isset($table->class)) {
        $newtable->set_classes($table->class);
    }
    if (isset($table->rowclass) && is_array($table->rowclass)) {
        debugging('rowclass[] has been deprecated for html_table and should be replaced by rowclasses[]. please fix the code.');
        $newtable->rowclasses = $table->rowclass;
    }
    $output = $OUTPUT->table($newtable);
    if ($return) {
        return $output;
    } else {
        echo $output;
        return true;
    }
}

/**
 * Creates and displays (or returns) a link to a popup window
 *
 * @deprecated since Moodle 2.0
 *
 * @param string $url Web link. Either relative to $CFG->wwwroot, or a full URL.
 * @param string $name Name to be assigned to the popup window (this is used by
 *   client-side scripts to "talk" to the popup window)
 * @param string $linkname Text to be displayed as web link
 * @param int $height Height to assign to popup window
 * @param int $width Height to assign to popup window
 * @param string $title Text to be displayed as popup page title
 * @param string $options List of additional options for popup window
 * @param bool $return If true, return as a string, otherwise print
 * @param string $id id added to the element
 * @param string $class class added to the element
 * @return string html code to display a link to a popup window.
 */
function link_to_popup_window ($url, $name=null, $linkname=null,
                               $height=400, $width=500, $title=null,
                               $options=null, $return=false) {
    global $OUTPUT;

    // debugging('link_to_popup_window() has been deprecated. Please change your code to use $OUTPUT->link_to_popup().');

    if ($options == 'none') {
        $options = null;
    }

    if (empty($linkname)) {
        throw new coding_exception('A link must have a descriptive text value! See $OUTPUT->link_to_popup() for usage.');
    }

    // Create a html_link object
    $link = new html_link();
    $link->text = $linkname;
    $link->url = $url;
    $link->title = $title;

    // Parse the $options string
    $popupparams = array();
    if (!empty($options)) {
        $optionsarray = explode(',', $options);
        foreach ($optionsarray as $option) {
            if (strstr($option, '=')) {
                $parts = explode('=', $option);
                if ($parts[1] == '0') {
                    $popupparams[$parts[0]] = false;
                } else {
                    $popupparams[$parts[0]] = $parts[1];
                }
            } else {
                $popupparams[$option] = true;
            }
        }
    }

    $popupaction = new popup_action('click', $url, $name, $popupparams);
    $link->add_action($popupaction);

    // Call the output method
    $output = $OUTPUT->link_to_popup($link);

    if ($return) {
        return $output;
    } else {
        echo $output;
    }
}

/**
 * Creates and displays (or returns) a buttons to a popup window.
 *
 * @deprecated since Moodle 2.0
 *
 * @param string $url Web link. Either relative to $CFG->wwwroot, or a full URL.
 * @param string $name Name to be assigned to the popup window (this is used by
 *   client-side scripts to "talk" to the popup window)
 * @param string $linkname Text to be displayed as web link
 * @param int $height Height to assign to popup window
 * @param int $width Height to assign to popup window
 * @param string $title Text to be displayed as popup page title
 * @param string $options List of additional options for popup window
 * @param bool $return If true, return as a string, otherwise print
 * @param string $id id added to the element
 * @param string $class class added to the element
 * @return string html code to display a link to a popup window.
 */
function button_to_popup_window ($url, $name=null, $linkname=null,
                                 $height=400, $width=500, $title=null, $options=null, $return=false,
                                 $id=null, $class=null) {
    global $OUTPUT;

    // debugging('link_to_popup_window() has been deprecated. Please change your code to use $OUTPUT->link_to_popup().');

    if ($options == 'none') {
        $options = null;
    }

    if (empty($linkname)) {
        throw new coding_exception('A link must have a descriptive text value! See $OUTPUT->link_to_popup() for usage.');
    }

    // Create a html_button object
    $button = new html_button();
    $button->value = $linkname;
    $button->url = $url;
    $button->id = $id;
    $button->add_class($class);
    $button->method = 'post';
    $button->title = $title;

    // Parse the $options string
    $popupparams = array();
    if (!empty($options)) {
        $optionsarray = explode(',', $options);
        foreach ($optionsarray as $option) {
            if (strstr($option, '=')) {
                $parts = explode('=', $option);
                if ($parts[1] == '0') {
                    $popupparams[$parts[0]] = false;
                } else {
                    $popupparams[$parts[0]] = $parts[1];
                }
            } else {
                $popupparams[$option] = true;
            }
        }
    }

    if (!empty($height)) {
        $popupparams['height'] = $height;
    }
    if (!empty($width)) {
        $popupparams['width'] = $width;
    }

    $popupaction = new popup_action('click', $url, $name, $popupparams);
    $button->add_action($popupaction);
    $output = $OUTPUT->button($button);

    if ($return) {
        return $output;
    } else {
        echo $output;
    }
}

/**
 * Print a self contained form with a single submit button.
 *
 * @deprecated since Moodle 2.0
 *
 * @param string $link used as the action attribute on the form, so the URL that will be hit if the button is clicked.
 * @param array $options these become hidden form fields, so these options get passed to the script at $link.
 * @param string $label the caption that appears on the button.
 * @param string $method HTTP method used on the request of the button is clicked. 'get' or 'post'.
 * @param string $notusedanymore no longer used.
 * @param boolean $return if false, output the form directly, otherwise return the HTML as a string.
 * @param string $tooltip a tooltip to add to the button as a title attribute.
 * @param boolean $disabled if true, the button will be disabled.
 * @param string $jsconfirmmessage if not empty then display a confirm dialogue with this string as the question.
 * @param string $formid The id attribute to use for the form
 * @return string|void Depending on the $return paramter.
 */
function print_single_button($link, $options, $label='OK', $method='get', $notusedanymore='',
        $return=false, $tooltip='', $disabled = false, $jsconfirmmessage='', $formid = '') {
    global $OUTPUT;

    // debugging('print_single_button() has been deprecated. Please change your code to use $OUTPUT->button().');

    // Cast $options to array
    $options = (array) $options;
    $form = new html_form();
    $form->url = new moodle_url($link, $options);
    $form->button = new html_button();
    $form->button->text = $label;
    $form->button->disabled = $disabled;
    $form->button->title = $tooltip;
    $form->method = $method;
    $form->id = $formid;

    if ($jsconfirmmessage) {
        $confirmaction = new component_action('click', 'confirm_dialog', array('message' => $jsconfirmmessage));
        $form->button->add_action($confirmaction);
    }

    $output = $OUTPUT->button($form);
    
    $icon = new action_icon();

    if ($return) {
        return $output;
    } else {
        echo $output;
    }
}

/**
 * Print a spacer image with the option of including a line break.
 *
 * @deprecated since Moodle 2.0
 *
 * @global object
 * @param int $height The height in pixels to make the spacer
 * @param int $width The width in pixels to make the spacer
 * @param boolean $br If set to true a BR is written after the spacer
 */
function print_spacer($height=1, $width=1, $br=true, $return=false) {
    global $CFG, $OUTPUT;

    // debugging('print_spacer() has been deprecated. Please change your code to use $OUTPUT->spacer().');

    $spacer = new html_image();
    $spacer->height = $height;
    $spacer->width = $width;

    $output = $OUTPUT->spacer($spacer);

    if ($br) {
        $output .= '<br />';
    }

    if ($return) {
        return $output;
    } else {
        echo $output;
    }
}

/**
 * Given the path to a picture file in a course, or a URL,
 * this function includes the picture in the page.
 *
 * @deprecated since Moodle 2.0
 */
function print_file_picture($path, $courseid=0, $height='', $width='', $link='', $return=false) {
    throw new coding_exception('print_file_picture() has been deprecated since Moodle 2.0. Please use $OUTPUT->action_icon() instead.');
}

/**
 * Print the specified user's avatar.
 *
 * @deprecated since Moodle 2.0
 *
 * @global object
 * @global object
 * @param mixed $user Should be a $user object with at least fields id, picture, imagealt, firstname, lastname
 *      If any of these are missing, or if a userid is passed, the the database is queried. Avoid this
 *      if at all possible, particularly for reports. It is very bad for performance.
 * @param int $courseid The course id. Used when constructing the link to the user's profile.
 * @param boolean $picture The picture to print. By default (or if NULL is passed) $user->picture is used.
 * @param int $size Size in pixels. Special values are (true/1 = 100px) and (false/0 = 35px) for backward compatibility
 * @param boolean $return If false print picture to current page, otherwise return the output as string
 * @param boolean $link enclose printed image in a link the user's profile (default true).
 * @param string $target link target attribute. Makes the profile open in a popup window.
 * @param boolean $alttext add non-blank alt-text to the image. (Default true, set to false for purely
 *      decorative images, or where the username will be printed anyway.)
 * @return string|void String or nothing, depending on $return.
 */
function print_user_picture($user, $courseid, $picture=NULL, $size=0, $return=false, $link=true, $target='', $alttext=true) {
    global $CFG, $DB, $OUTPUT;

    // debugging('print_user_picture() has been deprecated. Please change your code to use $OUTPUT->user_picture($user, $link, $popup).');

    $userpic = new user_picture();
    $userpic->user = $user;
    $userpic->courseid = $courseid;
    $userpic->size = $size;
    $userpic->link = $link;
    $userpic->alttext = $alttext;

    if (!empty($picture)) {
        $userpic->image = new html_image();
        $userpic->image->src = $picture;
    }

    if (!empty($target)) {
        $popupaction = new popup_action('click', new moodle_url($target));
        $userpic->add_action($popupaction);
    }

    $output = $OUTPUT->user_picture($userpic);

    if ($return) {
        return $output;
    } else {
        echo $output;
    }
}

/**
 * Print a png image.
 *
 * @deprecated since Moodle 2.0: no replacement
 *
 */
function print_png() {
    throw new coding_exception('print_png() has been deprecated since Moodle 2.0. Please use $OUTPUT->image() instead.');
}


/**
 * Prints a basic textarea field.
 *
 * @deprecated since Moodle 2.0
 *
 * When using this function, you should
 *
 * @global object
 * @param bool $usehtmleditor Enables the use of the htmleditor for this field.
 * @param int $rows Number of rows to display  (minimum of 10 when $height is non-null)
 * @param int $cols Number of columns to display (minimum of 65 when $width is non-null)
 * @param null $width (Deprecated) Width of the element; if a value is passed, the minimum value for $cols will be 65. Value is otherwise ignored.
 * @param null $height (Deprecated) Height of the element; if a value is passe, the minimum value for $rows will be 10. Value is otherwise ignored.
 * @param string $name Name to use for the textarea element.
 * @param string $value Initial content to display in the textarea.
 * @param int $obsolete deprecated
 * @param bool $return If false, will output string. If true, will return string value.
 * @param string $id CSS ID to add to the textarea element.
 * @return string|void depending on the value of $return
 */
function print_textarea($usehtmleditor, $rows, $cols, $width, $height, $name, $value='', $obsolete=0, $return=false, $id='') {
    /// $width and height are legacy fields and no longer used as pixels like they used to be.
    /// However, you can set them to zero to override the mincols and minrows values below.

    // debugging('print_textarea() has been deprecated. Please change your code to use $OUTPUT->textarea().');

    global $CFG;

    $mincols = 65;
    $minrows = 10;
    $str = '';

    if ($id === '') {
        $id = 'edit-'.$name;
    }

    if ($usehtmleditor) {
        if ($height && ($rows < $minrows)) {
            $rows = $minrows;
        }
        if ($width && ($cols < $mincols)) {
            $cols = $mincols;
        }
    }

    if ($usehtmleditor) {
        editors_head_setup();
        $editor = get_preferred_texteditor(FORMAT_HTML);
        $editor->use_editor($id, array('legacy'=>true));
    } else {
        $editorclass = '';
    }

    $str .= "\n".'<textarea class="form-textarea" id="'. $id .'" name="'. $name .'" rows="'. $rows .'" cols="'. $cols .'">'."\n";
    if ($usehtmleditor) {
        $str .= htmlspecialchars($value); // needed for editing of cleaned text!
    } else {
        $str .= s($value);
    }
    $str .= '</textarea>'."\n";

    if ($return) {
        return $str;
    }
    echo $str;
}


/**
 * Print a help button.
 *
 * @deprecated since Moodle 2.0
 *
 * @param string $page  The keyword that defines a help page
 * @param string $title The title of links, rollover tips, alt tags etc
 *           'Help with' (or the language equivalent) will be prefixed and '...' will be stripped.
 * @param string $module Which module is the page defined in
 * @param mixed $image Use a help image for the link?  (true/false/"both")
 * @param boolean $linktext If true, display the title next to the help icon.
 * @param string $text If defined then this text is used in the page, and
 *           the $page variable is ignored. DEPRECATED!
 * @param boolean $return If true then the output is returned as a string, if false it is printed to the current page.
 * @param string $imagetext The full text for the helpbutton icon. If empty use default help.gif
 * @return string|void Depending on value of $return
 */
function helpbutton($page, $title, $module='moodle', $image=true, $linktext=false, $text='', $return=false, $imagetext='') {
    // debugging('helpbutton() has been deprecated. Please change your code to use $OUTPUT->help_icon().');

    global $OUTPUT;

    if (!empty($text)) {
        throw new coding_exception('The $text parameter has been deprecated. Please update your code and use $OUTPUT->help_icon() instead. <br />' .
            "You will also need to copy the following text into a proper html help file if not already done so: <p>$text</p>");
    }

    if (!empty($imagetext)) {
        throw new coding_exception('The $imagetext parameter has been deprecated. Please update your code and use $OUTPUT->help_icon() instead.');
    }

    $helpicon = new help_icon();
    $helpicon->page = $page;
    $helpicon->text = $title;
    $helpicon->module = $module;
    $helpicon->linktext = $linktext;

    // If image is true, the defaults are handled by the helpicon's prepare method
    if (!$image) {
        $helpicon->image = false;
    }

    $output = $OUTPUT->help_icon($helpicon);

    if ($return) {
        return $output;
    } else {
        echo $output;
    }

}

/**
 * Returns an image of an up or down arrow, used for column sorting. To avoid unnecessary DB accesses, please
 * provide this function with the language strings for sortasc and sortdesc.
 *
 * @deprecated since Moodle 2.0
 *
 * TODO migrate to outputlib
 * If no sort string is associated with the direction, an arrow with no alt text will be printed/returned.
 *
 * @global object
 * @param string $direction 'up' or 'down'
 * @param string $strsort The language string used for the alt attribute of this image
 * @param bool $return Whether to print directly or return the html string
 * @return string|void depending on $return
 *
 */
function print_arrow($direction='up', $strsort=null, $return=false) {
    // debugging('print_arrow() has been deprecated. Please change your code to use $OUTPUT->arrow().');

    global $OUTPUT;

    if (!in_array($direction, array('up', 'down', 'right', 'left', 'move'))) {
        return null;
    }

    $return = null;

    switch ($direction) {
        case 'up':
            $sortdir = 'asc';
            break;
        case 'down':
            $sortdir = 'desc';
            break;
        case 'move':
            $sortdir = 'asc';
            break;
        default:
            $sortdir = null;
            break;
    }

    // Prepare language string
    $strsort = '';
    if (empty($strsort) && !empty($sortdir)) {
        $strsort  = get_string('sort' . $sortdir, 'grades');
    }

    $return = ' <img src="'.$OUTPUT->old_icon_url('t/' . $direction) . '" alt="'.$strsort.'" /> ';

    if ($return) {
        return $return;
    } else {
        echo $return;
    }
}

/**
 * Returns a string containing a link to the user documentation.
 * Also contains an icon by default. Shown to teachers and admin only.
 *
 * @deprecated since Moodle 2.0
 *
 * @global object
 * @param string $path The page link after doc root and language, no leading slash.
 * @param string $text The text to be displayed for the link
 * @param string $iconpath The path to the icon to be displayed
 * @return string Either the link or an empty string
 */
function doc_link($path='', $text='', $iconpath='') {
    global $CFG, $OUTPUT;

    // debugging('doc_link() has been deprecated. Please change your code to use $OUTPUT->action_icon().');

    if (empty($CFG->docroot)) {
        return '';
    }

    $icon = new action_icon();
    $icon->linktext = $text;

    if (!empty($iconpath)) {
        $icon->image->src = $iconpath;
        $icon->image->alt = $text;
        $icon->image->add_class('iconhelp');
    } else {
        $icon->image->src = $CFG->httpswwwroot . '/pix/docs.gif';
    }

    $icon->link->url = new moodle_url(get_docs_url($path));

    if (!empty($CFG->doctonewwindow)) {
        $icon->actions[] = new popup_action('click', $icon->link->url);
    }

    return $OUTPUT->action_icon($icon);
}

/**
 * Prints a single paging bar to provide access to other pages  (usually in a search)
 *
 * @deprecated since Moodle 2.0
 *
 * @param int $totalcount Thetotal number of entries available to be paged through
 * @param int $page The page you are currently viewing
 * @param int $perpage The number of entries that should be shown per page
 * @param mixed $baseurl If this  is a string then it is the url which will be appended with $pagevar, an equals sign and the page number.
 *                          If this is a moodle_url object then the pagevar param will be replaced by the page no, for each page.
 * @param string $pagevar This is the variable name that you use for the page number in your code (ie. 'tablepage', 'blogpage', etc)
 * @param bool $nocurr do not display the current page as a link
 * @param bool $return whether to return an output string or echo now
 * @return bool|string depending on $result
 */
function print_paging_bar($totalcount, $page, $perpage, $baseurl, $pagevar='page',$nocurr=false, $return=false) {
    global $OUTPUT;

    // debugging('print_paging_bar() has been deprecated. Please change your code to use $OUTPUT->paging_bar($pagingbar).');

    $pagingbar = new moodle_paging_bar();
    $pagingbar->totalcount = $totalcount;
    $pagingbar->page = $page;
    $pagingbar->perpage = $perpage;
    $pagingbar->baseurl = $baseurl;
    $pagingbar->pagevar = $pagevar;
    $pagingbar->nocurr = $nocurr;
    $output = $OUTPUT->paging_bar($pagingbar);

    if ($return) {
        return $output;
    }

    echo $output;
    return true;
}

/**
 * Print a message along with "Yes" and "No" links for the user to continue.
 *
 * @deprecated since Moodle 2.0
 *
 * @global object
 * @param string $message The text to display
 * @param string $linkyes The link to take the user to if they choose "Yes"
 * @param string $linkno The link to take the user to if they choose "No"
 * @param string $optionyes The yes option to show on the notice
 * @param string $optionsno The no option to show
 * @param string $methodyes Form action method to use if yes [post, get]
 * @param string $methodno Form action method to use if no [post, get]
 * @return void Output is echo'd
 */
function notice_yesno($message, $linkyes, $linkno, $optionsyes=NULL, $optionsno=NULL, $methodyes='post', $methodno='post') {

    // debugging('notice_yesno() has been deprecated. Please change your code to use $OUTPUT->confirm($message, $buttoncontinue, $buttoncancel).');

    global $OUTPUT;

    $formcontinue = new html_form();
    $formcontinue->url = new moodle_url($linkyes, $optionsyes);
    $formcontinue->button->text = get_string('yes');
    $formcontinue->method = $methodyes;

    $formcancel = new html_form();
    $formcancel->url = new moodle_url($linkno, $optionsno);
    $formcancel->button->text = get_string('no');
    $formcancel->method = $methodno;

    echo $OUTPUT->confirm($message, $formcontinue, $formcancel);
}

/**
 * Prints a scale menu (as part of an existing form) including help button
 * @deprecated since Moodle 2.0
 */
function print_scale_menu() {
    throw new coding_exception('print_scale_menu() has been deprecated since the Jurassic period. Get with the times!.');
}

/**
 * Given an array of values, output the HTML for a select element with those options.
 *
 * @deprecated since Moodle 2.0
 *
 * Normally, you only need to use the first few parameters.
 *
 * @param array $options The options to offer. An array of the form
 *      $options[{value}] = {text displayed for that option};
 * @param string $name the name of this form control, as in &lt;select name="..." ...
 * @param string $selected the option to select initially, default none.
 * @param string $nothing The label for the 'nothing is selected' option. Defaults to get_string('choose').
 *      Set this to '' if you don't want a 'nothing is selected' option.
 * @param string $script if not '', then this is added to the &lt;select> element as an onchange handler.
 * @param string $nothingvalue The value corresponding to the $nothing option. Defaults to 0.
 * @param boolean $return if false (the default) the the output is printed directly, If true, the
 *      generated HTML is returned as a string.
 * @param boolean $disabled if true, the select is generated in a disabled state. Default, false.
 * @param int $tabindex if give, sets the tabindex attribute on the &lt;select> element. Default none.
 * @param string $id value to use for the id attribute of the &lt;select> element. If none is given,
 *      then a suitable one is constructed.
 * @param mixed $listbox if false, display as a dropdown menu. If true, display as a list box.
 *      By default, the list box will have a number of rows equal to min(10, count($options)), but if
 *      $listbox is an integer, that number is used for size instead.
 * @param boolean $multiple if true, enable multiple selections, else only 1 item can be selected. Used
 *      when $listbox display is enabled
 * @param string $class value to use for the class attribute of the &lt;select> element. If none is given,
 *      then a suitable one is constructed.
 * @return string|void If $return=true returns string, else echo's and returns void
 */
function choose_from_menu ($options, $name, $selected='', $nothing='choose', $script='',
                           $nothingvalue='0', $return=false, $disabled=false, $tabindex=0,
                           $id='', $listbox=false, $multiple=false, $class='') {

    global $OUTPUT;
    // debugging('choose_from_menu() has been deprecated. Please change your code to use $OUTPUT->select_menu($selectmenu).');

    if ($script) {
        debugging('The $script parameter has been deprecated. You must use component_actions instead', DEBUG_DEVELOPER);
    }
    $selectmenu = new moodle_select_menu();
    $selectmenu->options = $options;
    $selectmenu->name = $name;
    $selectmenu->selectedvalue = $selected;
    $selectmenu->nothinglabel = $nothing;
    $selectmenu->nothingvalue = $nothingvalue;
    $selectmenu->disabled = $disabled;
    $selectmenu->tabindex = $tabindex;
    $selectmenu->id = $id;
    $selectmenu->listbox = $listbox;
    $selectmenu->multiple = $multiple;
    $selectmenu->add_classes($class);

    if ($nothing == 'choose') {
        $selectmenu->nothinglabel = '';
    }

    $output = $OUTPUT->select_menu($selectmenu);

    if ($return) {
        return $output;
    } else {
        echo $output;
    }
}

/**
 * Choose value 0 or 1 from a menu with options 'No' and 'Yes'.
 * Other options like choose_from_menu.
 *
 * @deprecated since Moodle 2.0
 *
 * Calls {@link choose_from_menu()} with preset arguments
 * @see choose_from_menu()
 *
 * @param string $name the name of this form control, as in &lt;select name="..." ...
 * @param string $selected the option to select initially, default none.
 * @param string $script if not '', then this is added to the &lt;select> element as an onchange handler.
 * @param boolean $return Whether this function should return a string or output it (defaults to false)
 * @param boolean $disabled (defaults to false)
 * @param int $tabindex
 * @return string|void If $return=true returns string, else echo's and returns void
 */
function choose_from_menu_yesno($name, $selected, $script = '',
        $return = false, $disabled = false, $tabindex = 0) {
    // debugging('choose_from_menu_yesno() has been deprecated. Please change your code to use $OUTPUT->select_menu($selectmenu).');
    global $OUTPUT;

    if ($script) {
        debugging('The $script parameter has been deprecated. You must use component_actions instead', DEBUG_DEVELOPER);
    }

    $selectmenu = moodle_select_menu::make_yes_no($name, $selected);
    $selectmenu->disabled = $disabled;
    $selectmenu->tabindex = $tabindex;
    $output = $OUTPUT->select_menu($select_menu);

    if ($return) {
        return $output;
    } else {
        echo $output;
    }
}

/**
 * Just like choose_from_menu, but takes a nested array (2 levels) and makes a dropdown menu
 * including option headings with the first level.
 *
 * @deprecated since Moodle 2.0
 *
 * This function is very similar to {@link choose_from_menu_yesno()}
 * and {@link choose_from_menu()}
 *
 * @todo Add datatype handling to make sure $options is an array
 *
 * @param array $options An array of objects to choose from
 * @param string $name The XHTML field name
 * @param string $selected The value to select by default
 * @param string $nothing The label for the 'nothing is selected' option.
 *                        Defaults to get_string('choose').
 * @param string $script If not '', then this is added to the &lt;select> element
 *                       as an onchange handler.
 * @param string $nothingvalue The value for the first `nothing` option if $nothing is set
 * @param bool $return Whether this function should return a string or output
 *                     it (defaults to false)
 * @param bool $disabled Is the field disabled by default
 * @param int|string $tabindex Override the tabindex attribute [numeric]
 * @return string|void If $return=true returns string, else echo's and returns void
 */
function choose_from_menu_nested($options,$name,$selected='',$nothing='choose',$script = '',
                                 $nothingvalue=0,$return=false,$disabled=false,$tabindex=0) {

    // debugging('choose_from_menu_nested() has been deprecated. Please change your code to use $OUTPUT->select_menu($selectmenu).');
    global $OUTPUT;

    if ($script) {
        debugging('The $script parameter has been deprecated. You must use component_actions instead', DEBUG_DEVELOPER);
    }
    $selectmenu = moodle_select_menu::make($options, $name, $selected);
    $selectmenu->tabindex = $tabindex;
    $selectmenu->disabled = $disabled;
    $selectmenu->nothingvalue = $nothingvalue;
    $selectmenu->nested = true;

    if ($nothing == 'choose') {
        $selectmenu->nothinglabel = '';
    }

    $output = $OUTPUT->select_menu($selectmenu);

    if ($return) {
        return $output;
    } else {
        echo $output;
    }
}

/**
 * Prints a help button about a scale
 *
 * @deprecated since Moodle 2.0
 *
 * @global object
 * @param id $courseid
 * @param object $scale
 * @param boolean $return If set to true returns rather than echo's
 * @return string|bool Depending on value of $return
 */
function print_scale_menu_helpbutton($courseid, $scale, $return=false) {
    // debugging('print_scale_menu_helpbutton() has been deprecated. Please change your code to use $OUTPUT->help_button($scaleselectmenu).');
    global $OUTPUT;

    $helpbutton = help_button::make_scale_menu($courseid, $scale);

    $output = $OUTPUT->help_button($helpbutton);

    if ($return) {
        return $output;
    } else {
        echo $output;
    }
}


/**
 * Prints time limit value selector
 *
 * @deprecated since Moodle 2.0
 *
 * Uses {@link choose_from_menu()} to generate HTML
 * @see choose_from_menu()
 *
 * @global object
 * @param int $timelimit default
 * @param string $unit
 * @param string $name
 * @param boolean $return If set to true returns rather than echo's
 * @return string|bool Depending on value of $return
 */
function print_timer_selector($timelimit = 0, $unit = '', $name = 'timelimit', $return=false) {
    throw new coding_exception('print_timer_selector is completely deprecated. Please use $OUTPUT->select_menu($selectmenu) instead');
}

/**
 * Prints form items with the names $hour and $minute
 *
 * @deprecated since Moodle 2.0
 *
 * @param string $hour  fieldname
 * @param string $minute  fieldname
 * @param int $currenttime A default timestamp in GMT
 * @param int $step minute spacing
 * @param boolean $return If set to true returns rather than echo's
 * @return string|bool Depending on value of $return
 */
function print_time_selector($hour, $minute, $currenttime=0, $step=5, $return=false) {
    // debugging('print_time_selector() has been deprecated. Please change your code to use $OUTPUT->select_menu($timeselector).');
    global $OUTPUT;
    $hourselector = moodle_select_menu::make_time_selector('hours', $hour, $currenttime);
    $minuteselector = moodle_select_menu::make_time_selector('minutes', $minute, $currenttime, $step);

    $output = $OUTPUT->select_menu($hourselector) . $OUTPUT->select_menu($minuteselector);

    if ($return) {
        return $output;
    } else {
        echo $output;
    }
}

/**
 * Prints form items with the names $day, $month and $year
 *
 * @deprecated since Moodle 2.0
 *
 * @param string $day   fieldname
 * @param string $month  fieldname
 * @param string $year  fieldname
 * @param int $currenttime A default timestamp in GMT
 * @param boolean $return If set to true returns rather than echo's
 * @return string|bool Depending on value of $return
 */
function print_date_selector($day, $month, $year, $currenttime=0, $return=false) {

    // debugging('print_date_selector() has been deprecated. Please change your code to use $OUTPUT->select_menu($dateselector).');
    global $OUTPUT;

    $dayselector = moodle_select_menu::make_time_selector('days', $day, $currenttime);
    $monthselector = moodle_select_menu::make_time_selector('months', $month, $currenttime);
    $yearselector = moodle_select_menu::make_time_selector('years', $year, $currenttime);

    $output = $OUTPUT->select_menu($dayselector) . $OUTPUT->select_menu($monthselector) . $OUTPUT->select_menu($yearselector);

    if ($return) {
        return $output;
    } else {
        echo $output;
    }
}

/**
 * Implements a complete little form with a dropdown menu.
 *
 * @deprecated since Moodle 2.0
 *
 * When JavaScript is on selecting an option from the dropdown automatically
 * submits the form (while avoiding the usual acessibility problems with this appoach).
 * With JavaScript off, a 'Go' button is printed.
 *
 * @global object
 * @global object
 * @param string $baseurl The target URL up to the point of the variable that changes
 * @param array $options A list of value-label pairs for the popup list
 * @param string $formid id for the control. Must be unique on the page. Used in the HTML.
 * @param string $selected The option that is initially selected
 * @param string $nothing The label for the "no choice" option
 * @param string $help The name of a help page if help is required
 * @param string $helptext The name of the label for the help button
 * @param boolean $return Indicates whether the function should return the HTML
 *         as a string or echo it directly to the page being rendered
 * @param string $targetwindow The name of the target page to open the linked page in.
 * @param string $selectlabel Text to place in a [label] element - preferred for accessibility.
 * @param array $optionsextra an array with the same keys as $options. The values are added within the corresponding <option ...> tag.
 * @param string $submitvalue Optional label for the 'Go' button. Defaults to get_string('go').
 * @param boolean $disabled If true, the menu will be displayed disabled.
 * @param boolean $showbutton If true, the button will always be shown even if JavaScript is available
 * @return string|void If $return=true returns string, else echo's and returns void
 */
function popup_form($baseurl, $options, $formid, $selected='', $nothing='choose', $help='', $helptext='', $return=false,
    $targetwindow='self', $selectlabel='', $optionsextra=NULL, $submitvalue='', $disabled=false, $showbutton=false) {
    global $OUTPUT;

    // debugging('popup_form() has been deprecated. Please change your code to use $OUTPUT->select_menu($dateselector).');

    if (!empty($optionsextra)) {
        debugging('the optionsextra param has been deprecated in popup_form, it will be ignored.', DEBUG_DEVELOPER);
    }

    if (empty($options)) {
        return '';
    }
    $selectmenu = moodle_select_menu::make_popup_form($baseurl, $options, $formid, $submitvalue, $selected);
    $selectmenu->disabled = $disabled;
    
    // Extract the last param of the baseurl for the name of the select
    if (preg_match('/([a-z_]*)=$/', $baseurl, $matches)) {
        $selectmenu->name = $matches[1];
        $selectmenu->form->url->remove_params(array($matches[1]));
    }

    if ($nothing == 'choose') {
        $selectmenu->nothinglabel = '';
    } else {
        $selectmenu->nothinglabel = $nothing;
    }

    $selectmenu->set_label($selectlabel, $selectmenu->id);
    $selectmenu->set_help_icon($help, $helptext);

    $output = $OUTPUT->select_menu($selectmenu);

    if ($return) {
        return $output;
    } else {
        echo $output;
    }
}

/**
 * Prints a simple button to close a window
 *
 * @deprecated since Moodle 2.0
 *
 * @global object
 * @param string $name Name of the window to close
 * @param boolean $return whether this function should return a string or output it.
 * @param boolean $reloadopener if true, clicking the button will also reload
 *      the page that opend this popup window.
 * @return string|void if $return is true, void otherwise
 */
function close_window_button($name='closewindow', $return=false, $reloadopener = false) {
    global $OUTPUT;
    
    // debugging('close_window_button() has been deprecated. Please change your code to use $OUTPUT->close_window_button().');
    $output = $OUTPUT->close_window_button(get_string($name));
    
    if ($return) {
        return $output;
    } else {
        echo $output;
    }
}
