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
 * Functions and classes used during installation, upgrades and for admin settings.
 *
 *  ADMIN SETTINGS TREE INTRODUCTION
 *
 *  This file performs the following tasks:
 *   -it defines the necessary objects and interfaces to build the Moodle
 *    admin hierarchy
 *   -it defines the admin_externalpage_setup(), admin_externalpage_print_header(),
 *    and admin_externalpage_print_footer() functions used on admin pages
 *
 *  ADMIN_SETTING OBJECTS
 *
 *  Moodle settings are represented by objects that inherit from the admin_setting
 *  class. These objects encapsulate how to read a setting, how to write a new value
 *  to a setting, and how to appropriately display the HTML to modify the setting.
 *
 *  ADMIN_SETTINGPAGE OBJECTS
 *
 *  The admin_setting objects are then grouped into admin_settingpages. The latter
 *  appear in the Moodle admin tree block. All interaction with admin_settingpage
 *  objects is handled by the admin/settings.php file.
 *
 *  ADMIN_EXTERNALPAGE OBJECTS
 *
 *  There are some settings in Moodle that are too complex to (efficiently) handle
 *  with admin_settingpages. (Consider, for example, user management and displaying
 *  lists of users.) In this case, we use the admin_externalpage object. This object
 *  places a link to an external PHP file in the admin tree block.
 *
 *  If you're using an admin_externalpage object for some settings, you can take
 *  advantage of the admin_externalpage_* functions. For example, suppose you wanted
 *  to add a foo.php file into admin. First off, you add the following line to
 *  admin/settings/first.php (at the end of the file) or to some other file in
 *  admin/settings:
 * <code>
 *     $ADMIN->add('userinterface', new admin_externalpage('foo', get_string('foo'),
 *         $CFG->wwwdir . '/' . '$CFG->admin . '/foo.php', 'some_role_permission'));
 * </code>
 *
 *  Next, in foo.php, your file structure would resemble the following:
 * <code>
 *         require_once('.../config.php');
 *         require_once($CFG->libdir.'/adminlib.php');
 *         admin_externalpage_setup('foo');
 *         // functionality like processing form submissions goes here
 *         admin_externalpage_print_header();
 *         // your HTML goes here
 *         print_footer();
 * </code>
 *
 *  The admin_externalpage_setup() function call ensures the user is logged in,
 *  and makes sure that they have the proper role permission to access the page.
 *
 *  The admin_externalpage_print_header() function prints the header (it figures
 *  out what category and subcategories the page is classified under) and ensures
 *  that you're using the admin pagelib (which provides the admin tree block and
 *  the admin bookmarks block).
 *
 *  The admin_externalpage_print_footer() function properly closes the tables
 *  opened up by the admin_externalpage_print_header() function and prints the
 *  standard Moodle footer.
 *
 *  ADMIN_CATEGORY OBJECTS
 *
 *  Above and beyond all this, we have admin_category objects. These objects
 *  appear as folders in the admin tree block. They contain admin_settingpage's,
 *  admin_externalpage's, and other admin_category's.
 *
 *  OTHER NOTES
 *
 *  admin_settingpage's, admin_externalpage's, and admin_category's all inherit
 *  from part_of_admin_tree (a pseudointerface). This interface insists that
 *  a class has a check_access method for access permissions, a locate method
 *  used to find a specific node in the admin tree and find parent path.
 *
 *  admin_category's inherit from parentable_part_of_admin_tree. This pseudo-
 *  interface ensures that the class implements a recursive add function which
 *  accepts a part_of_admin_tree object and searches for the proper place to
 *  put it. parentable_part_of_admin_tree implies part_of_admin_tree.
 *
 *  Please note that the $this->name field of any part_of_admin_tree must be
 *  UNIQUE throughout the ENTIRE admin tree.
 *
 *  The $this->name field of an admin_setting object (which is *not* part_of_
 *  admin_tree) must be unique on the respective admin_settingpage where it is
 *  used.
 *
 * Original author: Vincenzo K. Marcovecchio
 * Maintainer:      Petr Skoda
 *
 * @package   moodlecore
 * @copyright 1999 onwards Martin Dougiamas  http://dougiamas.com
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/// Add libraries
require_once($CFG->libdir.'/ddllib.php');
require_once($CFG->libdir.'/xmlize.php');
require_once($CFG->libdir.'/messagelib.php');      // Messagelib functions

define('INSECURE_DATAROOT_WARNING', 1);
define('INSECURE_DATAROOT_ERROR', 2);

/**
 * Delete all plugin tables
 *
 * @param string $name Name of plugin, used as table prefix
 * @param string $file Path to install.xml file
 * @param bool $feedback defaults to true
 * @return bool Always returns true
 */
function drop_plugin_tables($name, $file, $feedback=true) {
    global $CFG, $DB;

    // first try normal delete
    if (file_exists($file) and $DB->get_manager()->delete_tables_from_xmldb_file($file)) {
        return true;
    }

    // then try to find all tables that start with name and are not in any xml file
    $used_tables = get_used_table_names();

    $tables = $DB->get_tables();

    /// Iterate over, fixing id fields as necessary
    foreach ($tables as $table) {
        if (in_array($table, $used_tables)) {
            continue;
        }

        if (strpos($table, $name) !== 0) {
            continue;
        }

        // found orphan table --> delete it
        if ($DB->get_manager()->table_exists($table)) {
            $xmldb_table = new xmldb_table($table);
            $DB->get_manager()->drop_table($xmldb_table);
        }
    }

    return true;
}

/**
 * Returns names of all known tables == tables that moodle knowns about.
 *
 * @return array Array of lowercase table names
 */
function get_used_table_names() {
    $table_names = array();
    $dbdirs = get_db_directories();

    foreach ($dbdirs as $dbdir) {
        $file = $dbdir.'/install.xml';

        $xmldb_file = new xmldb_file($file);

        if (!$xmldb_file->fileExists()) {
            continue;
        }

        $loaded    = $xmldb_file->loadXMLStructure();
        $structure = $xmldb_file->getStructure();

        if ($loaded and $tables = $structure->getTables()) {
            foreach($tables as $table) {
                $table_names[] = strtolower($table->name);
            }
        }
    }

    return $table_names;
}

/**
 * Returns list of all directories where we expect install.xml files
 * @return array Array of paths
 */
function get_db_directories() {
    global $CFG;

    $dbdirs = array();

/// First, the main one (lib/db)
    $dbdirs[] = $CFG->libdir.'/db';

/// Then, all the ones defined by get_plugin_types()
    $plugintypes = get_plugin_types();
    foreach ($plugintypes as $plugintype => $pluginbasedir) {
        if ($plugins = get_plugin_list($plugintype)) {
            foreach ($plugins as $plugin => $plugindir) {
                $dbdirs[] = $plugindir.'/db';
            }
        }
    }

    return $dbdirs;
}

/**
 * Try to obtain or release the cron lock.
 * @param string  $name  name of lock
 * @param int  $until timestamp when this lock considered stale, null means remove lock unconditionaly
 * @param bool $ignorecurrent ignore current lock state, usually entend previous lock, defaults to false
 * @return bool true if lock obtained
 */
function set_cron_lock($name, $until, $ignorecurrent=false) {
    global $DB;
    if (empty($name)) {
        debugging("Tried to get a cron lock for a null fieldname");
        return false;
    }

    // remove lock by force == remove from config table
    if (is_null($until)) {
        set_config($name, null);
        return true;
    }

    if (!$ignorecurrent) {
        // read value from db - other processes might have changed it
        $value = $DB->get_field('config', 'value', array('name'=>$name));

        if ($value and $value > time()) {
            //lock active
            return false;
        }
    }

    set_config($name, $until);
    return true;
}

/**
 * Test if and critical warnings are present
 * @return bool
 */
function admin_critical_warnings_present() {
    global $SESSION;

    if (!has_capability('moodle/site:config', get_context_instance(CONTEXT_SYSTEM))) {
        return 0;
    }

    if (!isset($SESSION->admin_critical_warning)) {
        $SESSION->admin_critical_warning = 0;
        if (is_dataroot_insecure(true) === INSECURE_DATAROOT_ERROR) {
            $SESSION->admin_critical_warning = 1;
        }
    }

    return $SESSION->admin_critical_warning;
}

/**
 * Detects if float supports at least 10 decimal digits
 *
 * Detects if float supports at least 10 deciman digits
 * and also if float-->string conversion works as expected.
 *
 * @return bool true if problem found
 */
function is_float_problem() {
    $num1 = 2009010200.01;
    $num2 = 2009010200.02;

    return ((string)$num1 === (string)$num2 or $num1 === $num2 or $num2 <= (string)$num1);
}

/**
 * Try to verify that dataroot is not accessible from web.
 *
 * Try to verify that dataroot is not accessible from web.
 * It is not 100% correct but might help to reduce number of vulnerable sites.
 * Protection from httpd.conf and .htaccess is not detected properly.
 *
 * @uses INSECURE_DATAROOT_WARNING
 * @uses INSECURE_DATAROOT_ERROR
 * @param bool $fetchtest try to test public access by fetching file, default false
 * @return mixed empty means secure, INSECURE_DATAROOT_ERROR found a critical problem, INSECURE_DATAROOT_WARNING migth be problematic
 */
function is_dataroot_insecure($fetchtest=false) {
    global $CFG;

    $siteroot = str_replace('\\', '/', strrev($CFG->dirroot.'/')); // win32 backslash workaround

    $rp = preg_replace('|https?://[^/]+|i', '', $CFG->wwwroot, 1);
    $rp = strrev(trim($rp, '/'));
    $rp = explode('/', $rp);
    foreach($rp as $r) {
        if (strpos($siteroot, '/'.$r.'/') === 0) {
            $siteroot = substr($siteroot, strlen($r)+1); // moodle web in subdirectory
        } else {
            break; // probably alias root
        }
    }

    $siteroot = strrev($siteroot);
    $dataroot = str_replace('\\', '/', $CFG->dataroot.'/');

    if (strpos($dataroot, $siteroot) !== 0) {
        return false;
    }

    if (!$fetchtest) {
        return INSECURE_DATAROOT_WARNING;
    }

    // now try all methods to fetch a test file using http protocol

    $httpdocroot = str_replace('\\', '/', strrev($CFG->dirroot.'/'));
    preg_match('|(https?://[^/]+)|i', $CFG->wwwroot, $matches);
    $httpdocroot = $matches[1];
    $datarooturl = $httpdocroot.'/'. substr($dataroot, strlen($siteroot));
    if (make_upload_directory('diag', false) === false) {
        return INSECURE_DATAROOT_WARNING;
    }
    $testfile = $CFG->dataroot.'/diag/public.txt';
    if (!file_exists($testfile)) {
        file_put_contents($testfile, 'test file, do not delete');
    }
    $teststr = trim(file_get_contents($testfile));
    if (empty($teststr)) {
        // hmm, strange
        return INSECURE_DATAROOT_WARNING;
    }

    $testurl = $datarooturl.'/diag/public.txt';
    if (extension_loaded('curl') and
        !(stripos(ini_get('disable_functions'), 'curl_init') !== FALSE) and
        !(stripos(ini_get('disable_functions'), 'curl_setop') !== FALSE) and
        ($ch = @curl_init($testurl)) !== false) {
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        $data = curl_exec($ch);
        if (!curl_errno($ch)) {
            $data = trim($data);
            if ($data === $teststr) {
                curl_close($ch);
                return INSECURE_DATAROOT_ERROR;
            }
        }
        curl_close($ch);
    }

    if ($data = @file_get_contents($testurl)) {
        $data = trim($data);
        if ($data === $teststr) {
            return INSECURE_DATAROOT_ERROR;
        }
    }

    preg_match('|https?://([^/]+)|i', $testurl, $matches);
    $sitename = $matches[1];
    $error = 0;
    if ($fp = @fsockopen($sitename, 80, $error)) {
        preg_match('|https?://[^/]+(.*)|i', $testurl, $matches);
        $localurl = $matches[1];
        $out = "GET $localurl HTTP/1.1\r\n";
        $out .= "Host: $sitename\r\n";
        $out .= "Connection: Close\r\n\r\n";
        fwrite($fp, $out);
        $data = '';
        $incoming = false;
        while (!feof($fp)) {
            if ($incoming) {
                $data .= fgets($fp, 1024);
            } else if (@fgets($fp, 1024) === "\r\n") {
                $incoming = true;
            }
        }
        fclose($fp);
        $data = trim($data);
        if ($data === $teststr) {
            return INSECURE_DATAROOT_ERROR;
        }
    }

    return INSECURE_DATAROOT_WARNING;
}

/// CLASS DEFINITIONS /////////////////////////////////////////////////////////

/**
 * Pseudointerface for anything appearing in the admin tree
 *
 * The pseudointerface that is implemented by anything that appears in the admin tree
 * block. It forces inheriting classes to define a method for checking user permissions
 * and methods for finding something in the admin tree.
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface part_of_admin_tree {

    /**
     * Finds a named part_of_admin_tree.
     *
     * Used to find a part_of_admin_tree. If a class only inherits part_of_admin_tree
     * and not parentable_part_of_admin_tree, then this function should only check if
     * $this->name matches $name. If it does, it should return a reference to $this,
     * otherwise, it should return a reference to NULL.
     *
     * If a class inherits parentable_part_of_admin_tree, this method should be called
     * recursively on all child objects (assuming, of course, the parent object's name
     * doesn't match the search criterion).
     *
     * @param string $name The internal name of the part_of_admin_tree we're searching for.
     * @return mixed An object reference or a NULL reference.
     */
    public function locate($name);

    /**
     * Removes named part_of_admin_tree.
     *
     * @param string $name The internal name of the part_of_admin_tree we want to remove.
     * @return bool success.
     */
    public function prune($name);

    /**
     * Search using query
     * @param string $query
     * @return mixed array-object structure of found settings and pages
     */
    public function search($query);

    /**
     * Verifies current user's access to this part_of_admin_tree.
     *
     * Used to check if the current user has access to this part of the admin tree or
     * not. If a class only inherits part_of_admin_tree and not parentable_part_of_admin_tree,
     * then this method is usually just a call to has_capability() in the site context.
     *
     * If a class inherits parentable_part_of_admin_tree, this method should return the
     * logical OR of the return of check_access() on all child objects.
     *
     * @return bool True if the user has access, false if she doesn't.
     */
    public function check_access();

    /**
     * Mostly usefull for removing of some parts of the tree in admin tree block.
     *
     * @return True is hidden from normal list view
     */
    public function is_hidden();
}

/**
 * Pseudointerface implemented by any part_of_admin_tree that has children.
 *
 * The pseudointerface implemented by any part_of_admin_tree that can be a parent
 * to other part_of_admin_tree's. (For now, this only includes admin_category.) Apart
 * from ensuring part_of_admin_tree compliancy, it also ensures inheriting methods
 * include an add method for adding other part_of_admin_tree objects as children.
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface parentable_part_of_admin_tree extends part_of_admin_tree {

    /**
     * Adds a part_of_admin_tree object to the admin tree.
     *
     * Used to add a part_of_admin_tree object to this object or a child of this
     * object. $something should only be added if $destinationname matches
     * $this->name. If it doesn't, add should be called on child objects that are
     * also parentable_part_of_admin_tree's.
     *
     * @param string $destinationname The internal name of the new parent for $something.
     * @param part_of_admin_tree $something The object to be added.
     * @return bool True on success, false on failure.
     */
    public function add($destinationname, $something);

}

/**
 * The object used to represent folders (a.k.a. categories) in the admin tree block.
 *
 * Each admin_category object contains a number of part_of_admin_tree objects.
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_category implements parentable_part_of_admin_tree {

    /** @var mixed An array of part_of_admin_tree objects that are this object's children */
    public $children;
    /** @var string An internal name for this category. Must be unique amongst ALL part_of_admin_tree objects */
    public $name;
    /** @var string The displayed name for this category. Usually obtained through get_string() */
    public $visiblename;
    /** @var bool Should this category be hidden in admin tree block? */
    public $hidden;
    /** @var mixed Either a string or an array or strings */
    public $path;
    /** @var mixed Either a string or an array or strings */
    public $visiblepath;

    /**
     * Constructor for an empty admin category
     *
     * @param string $name The internal name for this category. Must be unique amongst ALL part_of_admin_tree objects
     * @param string $visiblename The displayed named for this category. Usually obtained through get_string()
     * @param bool $hidden hide category in admin tree block, defaults to false
     */
    public function __construct($name, $visiblename, $hidden=false) {
        $this->children    = array();
        $this->name        = $name;
        $this->visiblename = $visiblename;
        $this->hidden      = $hidden;
    }

    /**
     * Returns a reference to the part_of_admin_tree object with internal name $name.
     *
     * @param string $name The internal name of the object we want.
     * @param bool $findpath initialize path and visiblepath arrays
     * @return mixed A reference to the object with internal name $name if found, otherwise a reference to NULL.
     *                  defaults to false
     */
    public function locate($name, $findpath=false) {
        if ($this->name == $name) {
            if ($findpath) {
                $this->visiblepath[] = $this->visiblename;
                $this->path[]        = $this->name;
            }
            return $this;
        }

        $return = NULL;
        foreach($this->children as $childid=>$unused) {
            if ($return = $this->children[$childid]->locate($name, $findpath)) {
                break;
            }
        }

        if (!is_null($return) and $findpath) {
            $return->visiblepath[] = $this->visiblename;
            $return->path[]        = $this->name;
        }

        return $return;
    }

    /**
     * Search using query
     *
     * @param string query
     * @return mixed array-object structure of found settings and pages
     */
    public function search($query) {
        $result = array();
        foreach ($this->children as $child) {
            $subsearch = $child->search($query);
            if (!is_array($subsearch)) {
                debugging('Incorrect search result from '.$child->name);
                continue;
            }
            $result = array_merge($result, $subsearch);
        }
        return $result;
    }

    /**
     * Removes part_of_admin_tree object with internal name $name.
     *
     * @param string $name The internal name of the object we want to remove.
     * @return bool success
     */
    public function prune($name) {

        if ($this->name == $name) {
            return false;  //can not remove itself
        }

        foreach($this->children as $precedence => $child) {
            if ($child->name == $name) {
                // found it!
                unset($this->children[$precedence]);
                return true;
            }
            if ($this->children[$precedence]->prune($name)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Adds a part_of_admin_tree to a child or grandchild (or great-grandchild, and so forth) of this object.
     *
     * @param string $destinationame The internal name of the immediate parent that we want for $something.
     * @param mixed $something A part_of_admin_tree or setting instanceto be added.
     * @return bool True if successfully added, false if $something can not be added.
     */
    public function add($parentname, $something) {
        $parent = $this->locate($parentname);
        if (is_null($parent)) {
            debugging('parent does not exist!');
            return false;
        }

        if ($something instanceof part_of_admin_tree) {
            if (!($parent instanceof parentable_part_of_admin_tree)) {
                debugging('error - parts of tree can be inserted only into parentable parts');
                return false;
            }
            $parent->children[] = $something;
            return true;

        } else {
            debugging('error - can not add this element');
            return false;
        }

    }

    /**
     * Checks if the user has access to anything in this category.
     *
     * @return bool True if the user has access to atleast one child in this category, false otherwise.
     */
    public function check_access() {
        foreach ($this->children as $child) {
            if ($child->check_access()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Is this category hidden in admin tree block?
     *
     * @return bool True if hidden
     */
    public function is_hidden() {
        return $this->hidden;
    }
}

/**
 * Root of admin settings tree, does not have any parent.
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_root extends admin_category {
    /** @var array List of errors */
    public $errors;
    /** @var string search query */
    public $search;
    /** @var bool full tree flag - true means all settings required, false onlypages required */
    public $fulltree;
    /** @var bool flag indicating loaded tree */
    public $loaded;
    /** @var mixed site custom defaults overriding defaults in setings files*/
    public $custom_defaults;

    /**
     * @param bool $fulltree true means all settings required,
     *                            false only pages required
     */
    public function __construct($fulltree) {
        global $CFG;

        parent::__construct('root', get_string('administration'), false);
        $this->errors   = array();
        $this->search   = '';
        $this->fulltree = $fulltree;
        $this->loaded   = false;

        // load custom defaults if found
        $this->custom_defaults = null;
        $defaultsfile = "$CFG->dirroot/local/defaults.php";
        if (is_readable($defaultsfile)) {
            $defaults = array();
            include($defaultsfile);
            if (is_array($defaults) and count($defaults)) {
                $this->custom_defaults = $defaults;
            }
        }
    }

    /**
     * Empties children array, and sets loaded to false
     *
     * @param bool $requirefulltree
     */
    public function purge_children($requirefulltree) {
        $this->children = array();
        $this->fulltree = ($requirefulltree || $this->fulltree);
        $this->loaded   = false;
    }
}

/**
 * Links external PHP pages into the admin tree.
 *
 * See detailed usage example at the top of this document (adminlib.php)
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_externalpage implements part_of_admin_tree {

    /** @var string An internal name for this external page. Must be unique amongst ALL part_of_admin_tree objects */
    public $name;

    /** @var string The displayed name for this external page. Usually obtained through get_string(). */
    public $visiblename;

    /** @var string The external URL that we should link to when someone requests this external page. */
    public $url;

    /** @var string The role capability/permission a user must have to access this external page. */
    public $req_capability;

    /** @var object The context in which capability/permission should be checked, default is site context. */
    public $context;

    /** @var bool hidden in admin tree block. */
    public $hidden;

    /** @var mixed either string or array of string */
    public $path;
    public $visiblepath;

    /**
     * Constructor for adding an external page into the admin tree.
     *
     * @param string $name The internal name for this external page. Must be unique amongst ALL part_of_admin_tree objects.
     * @param string $visiblename The displayed name for this external page. Usually obtained through get_string().
     * @param string $url The external URL that we should link to when someone requests this external page.
     * @param mixed $req_capability The role capability/permission a user must have to access this external page. Defaults to 'moodle/site:config'.
     * @param boolean $hidden Is this external page hidden in admin tree block? Default false.
     * @param context $context The context the page relates to. Not sure what happens
     *      if you specify something other than system or front page. Defaults to system.
     */
    public function __construct($name, $visiblename, $url, $req_capability='moodle/site:config', $hidden=false, $context=NULL) {
        $this->name        = $name;
        $this->visiblename = $visiblename;
        $this->url         = $url;
        if (is_array($req_capability)) {
            $this->req_capability = $req_capability;
        } else {
            $this->req_capability = array($req_capability);
        }
        $this->hidden = $hidden;
        $this->context = $context;
    }

    /**
     * Returns a reference to the part_of_admin_tree object with internal name $name.
     *
     * @param string $name The internal name of the object we want.
     * @param bool $findpath defaults to false
     * @return mixed A reference to the object with internal name $name if found, otherwise a reference to NULL.
     */
    public function locate($name, $findpath=false) {
        if ($this->name == $name) {
            if ($findpath) {
                $this->visiblepath = array($this->visiblename);
                $this->path        = array($this->name);
            }
            return $this;
        } else {
            $return = NULL;
            return $return;
        }
    }

    /**
     * This function always returns false, required function by interface
     *
     * @param string $name
     * @return false
     */
    public function prune($name) {
        return false;
    }

    /**
     * Search using query
     *
     * @param string $query
     * @return mixed array-object structure of found settings and pages
     */
    public function search($query) {
        $textlib = textlib_get_instance();

        $found = false;
        if (strpos(strtolower($this->name), $query) !== false) {
            $found = true;
        } else if (strpos($textlib->strtolower($this->visiblename), $query) !== false) {
            $found = true;
        }
        if ($found) {
            $result = new object();
            $result->page     = $this;
            $result->settings = array();
            return array($this->name => $result);
        } else {
            return array();
        }
    }

    /**
     * Determines if the current user has access to this external page based on $this->req_capability.
     *
     * @return bool True if user has access, false otherwise.
     */
    public function check_access() {
        global $CFG;
        $context = empty($this->context) ? get_context_instance(CONTEXT_SYSTEM) : $this->context;
        foreach($this->req_capability as $cap) {
            if (is_valid_capability($cap) and has_capability($cap, $context)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Is this external page hidden in admin tree block?
     *
     * @return bool True if hidden
     */
    public function is_hidden() {
        return $this->hidden;
    }

}

/**
 * Used to group a number of admin_setting objects into a page and add them to the admin tree.
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_settingpage implements part_of_admin_tree {

    /** @var string An internal name for this external page. Must be unique amongst ALL part_of_admin_tree objects */
    public $name;

    /** @var string The displayed name for this external page. Usually obtained through get_string(). */
    public $visiblename;

    /** @var mixed An array of admin_setting objects that are part of this setting page. */
    public $settings;

    /** @var string The role capability/permission a user must have to access this external page. */
    public $req_capability;

    /** @var object The context in which capability/permission should be checked, default is site context. */
    public $context;

    /** @var bool hidden in admin tree block. */
    public $hidden;

    /** @var mixed string of paths or array of strings of paths */
    public $path;
    public $visiblepath;

    /**
     * see admin_settingpage for details of this function
     *
     * @param string $name The internal name for this external page. Must be unique amongst ALL part_of_admin_tree objects.
     * @param string $visiblename The displayed name for this external page. Usually obtained through get_string().
     * @param mixed $req_capability The role capability/permission a user must have to access this external page. Defaults to 'moodle/site:config'.
     * @param boolean $hidden Is this external page hidden in admin tree block? Default false.
     * @param context $context The context the page relates to. Not sure what happens
     *      if you specify something other than system or front page. Defaults to system.
     */
    public function __construct($name, $visiblename, $req_capability='moodle/site:config', $hidden=false, $context=NULL) {
        $this->settings    = new object();
        $this->name        = $name;
        $this->visiblename = $visiblename;
        if (is_array($req_capability)) {
            $this->req_capability = $req_capability;
        } else {
            $this->req_capability = array($req_capability);
        }
        $this->hidden      = $hidden;
        $this->context     = $context;
    }

    /**
     * see admin_category
     *
     * @param string $name
     * @param bool $findpath
     * @return mixed Object (this) if name ==  this->name, else returns null
     */
    public function locate($name, $findpath=false) {
        if ($this->name == $name) {
            if ($findpath) {
                $this->visiblepath = array($this->visiblename);
                $this->path        = array($this->name);
            }
            return $this;
        } else {
            $return = NULL;
            return $return;
        }
    }

    /**
     * Search string in settings page.
     *
     * @param string $query
     * @return array
     */
    public function search($query) {
        $found = array();

        foreach ($this->settings as $setting) {
            if ($setting->is_related($query)) {
                $found[] = $setting;
            }
        }

        if ($found) {
            $result = new object();
            $result->page     = $this;
            $result->settings = $found;
            return array($this->name => $result);
        }

        $textlib = textlib_get_instance();

        $found = false;
        if (strpos(strtolower($this->name), $query) !== false) {
            $found = true;
        } else if (strpos($textlib->strtolower($this->visiblename), $query) !== false) {
            $found = true;
        }
        if ($found) {
            $result = new object();
            $result->page     = $this;
            $result->settings = array();
            return array($this->name => $result);
        } else {
            return array();
        }
    }

    /**
     * This function always returns false, required by interface
     *
     * @param string $name
     * @return bool Always false
     */
    public function prune($name) {
        return false;
    }

    /**
     * adds an admin_setting to this admin_settingpage
     *
     * not the same as add for admin_category. adds an admin_setting to this admin_settingpage. settings appear (on the settingpage) in the order in which they're added
     * n.b. each admin_setting in an admin_settingpage must have a unique internal name
     *
     * @param object $setting is the admin_setting object you want to add
     * @return bool true if successful, false if not
     */
    public function add($setting) {
        if (!($setting instanceof admin_setting)) {
            debugging('error - not a setting instance');
            return false;
        }

        $this->settings->{$setting->name} = $setting;
        return true;
    }

    /**
     * see admin_externalpage
     *
     * @return bool Returns true for yes false for no
     */
    public function check_access() {
        global $CFG;
        $context = empty($this->context) ? get_context_instance(CONTEXT_SYSTEM) : $this->context;
        foreach($this->req_capability as $cap) {
            if (is_valid_capability($cap) and has_capability($cap, $context)) {
                return true;
            }
        }
        return false;
    }

    /**
     * outputs this page as html in a table (suitable for inclusion in an admin pagetype)
     * @return string Returns an XHTML string
     */
    public function output_html() {
        $adminroot = admin_get_root();
        $return = '<fieldset>'."\n".'<div class="clearer"><!-- --></div>'."\n";
        foreach($this->settings as $setting) {
            $fullname = $setting->get_full_name();
            if (array_key_exists($fullname, $adminroot->errors)) {
                $data = $adminroot->errors[$fullname]->data;
            } else {
                $data = $setting->get_setting();
                // do not use defaults if settings not available - upgrdesettings handles the defaults!
            }
            $return .= $setting->output_html($data);
        }
        $return .= '</fieldset>';
        return $return;
    }

    /**
     * Is this settigns page hidden in admin tree block?
     *
     * @return bool True if hidden
     */
    public function is_hidden() {
        return $this->hidden;
    }

}


/**
 * Admin settings class. Only exists on setting pages.
 * Read & write happens at this level; no authentication.
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class admin_setting {
    /** @var string unique ascii name, either 'mysetting' for settings that in config, or 'myplugin/mysetting' for ones in config_plugins. */
    public $name;
    /** @var string localised name */
    public $visiblename;
    /** @var string localised long description */
    public $description;
    /** @var mixed Can be string or array of string */
    public $defaultsetting;
    /** @var string */
    public $updatedcallback;
    /** @var mixed can be String or Null.  Null means main config table */
    public $plugin; // null means main config table

    /**
     * Constructor
     * @param string $name unique ascii name, either 'mysetting' for settings that in config,
     *                     or 'myplugin/mysetting' for ones in config_plugins.
     * @param string $visiblename localised name
     * @param string $description localised long description
     * @param mixed $defaultsetting string or array depending on implementation
     */
    public function __construct($name, $visiblename, $description, $defaultsetting) {
        $this->parse_setting_name($name);
        $this->visiblename    = $visiblename;
        $this->description    = $description;
        $this->defaultsetting = $defaultsetting;
    }

    /**
     * Set up $this->name and potentially $this->plugin
     *
     * Set up $this->name and possibly $this->plugin based on whether $name looks
     * like 'settingname' or 'plugin/settingname'. Also, do some sanity checking
     * on the names, that is, output a developer debug warning if the name
     * contains anything other than [a-zA-Z0-9_]+.
     *
     * @param string $name the setting name passed in to the constructor.
     */
    private function parse_setting_name($name) {
        $bits = explode('/', $name);
        if (count($bits) > 2) {
            throw new moodle_exception('invalidadminsettingname', '', '', $name);
        }
        $this->name = array_pop($bits);
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $this->name)) {
            throw new moodle_exception('invalidadminsettingname', '', '', $name);
        }
        if (!empty($bits)) {
            $this->plugin = array_pop($bits);
            if ($this->plugin === 'moodle') {
                $this->plugin = null;
            } else if (!preg_match('/^[a-zA-Z0-9_]+$/', $this->plugin)) {
                throw new moodle_exception('invalidadminsettingname', '', '', $name);
            }
        }
    }

    /**
     * Returns the fullname prefixed by the plugin
     * @return string
     */
    public function get_full_name() {
        return 's_'.$this->plugin.'_'.$this->name;
    }

    /**
     * Returns the ID string based on plugin and name
     * @return string
     */
    public function get_id() {
        return 'id_s_'.$this->plugin.'_'.$this->name;
    }

    /**
     * Returns the config if possible
     *
     * @return mixed returns config if successfull else null
     */
    public function config_read($name) {
        global $CFG;
        if (!empty($this->plugin)) {
            $value = get_config($this->plugin, $name);
            return $value === false ? NULL : $value;

        } else {
            if (isset($CFG->$name)) {
                return $CFG->$name;
            } else {
                return NULL;
            }
        }
    }

    /**
     * Used to set a config pair and log change
     *
     * @param string $name
     * @param mixed $value Gets converted to string if not null
     * @return bool Write setting to confix table
     */
    public function config_write($name, $value) {
        global $DB, $USER, $CFG;

        // make sure it is a real change
        $oldvalue = get_config($this->plugin, $name);
        $oldvalue = ($oldvalue === false) ? null : $oldvalue; // normalise
        $value = is_null($value) ? null : (string)$value;

        if ($oldvalue === $value) {
            return true;
        }

        // store change
        set_config($name, $value, $this->plugin);

        // log change
        $log = new object();
        $log->userid       = during_initial_install() ? 0 :$USER->id; // 0 as user id during install
        $log->timemodified = time();
        $log->plugin       = $this->plugin;
        $log->name         = $name;
        $log->value        = $value;
        $log->oldvalue     = $oldvalue;
        $DB->insert_record('config_log', $log);

        return true; // BC only
    }

    /**
     * Returns current value of this setting
     * @return mixed array or string depending on instance, NULL means not set yet
     */
    public abstract function get_setting();

    /**
     * Returns default setting if exists
     * @return mixed array or string depending on instance; NULL means no default, user must supply
     */
    public function get_defaultsetting() {
        $adminroot =  admin_get_root(false, false);
        if (!empty($adminroot->custom_defaults)) {
            $plugin = is_null($this->plugin) ? 'moodle' : $this->plugin;
            if (isset($adminroot->custom_defaults[$plugin])) {
                if (array_key_exists($this->name, $adminroot->custom_defaults[$plugin])) { // null is valid valie here ;-)
                    return $adminroot->custom_defaults[$plugin][$this->name];
                }
            }
        }
        return $this->defaultsetting;
    }

    /**
     * Store new setting
     *
     * @param mixed $data string or array, must not be NULL
     * @return string empty string if ok, string error message otherwise
     */
    public abstract function write_setting($data);

    /**
     * Return part of form with setting
     * This function should always be overwritten
     *
     * @param mixed $data array or string depending on setting
     * @param string $query
     * @return string
     */
    public function output_html($data, $query='') {
        // should be overridden
        return;
    }

    /**
     * Function called if setting updated - cleanup, cache reset, etc.
     * @param string $functionname Sets the function name
     */
    public function set_updatedcallback($functionname) {
        $this->updatedcallback = $functionname;
    }

    /**
     * Is setting related to query text - used when searching
     * @param string $query
     * @return bool
     */
    public function is_related($query) {
        if (strpos(strtolower($this->name), $query) !== false) {
            return true;
        }
        $textlib = textlib_get_instance();
        if (strpos($textlib->strtolower($this->visiblename), $query) !== false) {
            return true;
        }
        if (strpos($textlib->strtolower($this->description), $query) !== false) {
            return true;
        }
        $current = $this->get_setting();
        if (!is_null($current)) {
            if (is_string($current)) {
                if (strpos($textlib->strtolower($current), $query) !== false) {
                    return true;
                }
            }
        }
        $default = $this->get_defaultsetting();
        if (!is_null($default)) {
            if (is_string($default)) {
                if (strpos($textlib->strtolower($default), $query) !== false) {
                    return true;
                }
            }
        }
        return false;
    }
}

/**
 * No setting - just heading and text.
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_heading extends admin_setting {
    /**
     * not a setting, just text
     * @param string $name unique ascii name, either 'mysetting' for settings that in config, or 'myplugin/mysetting' for ones in config_plugins.
     * @param string $heading heading
     * @param string $information text in box
     */
    public function __construct($name, $heading, $information) {
        parent::__construct($name, $heading, $information, '');
    }

    /**
     * Always returns true
     * @return bool Always returns true
     */
    public function get_setting() {
        return true;
    }

    /**
     * Always returns true
     * @return bool Always returns true
     */
    public function get_defaultsetting() {
        return true;
    }

    /**
     * Never write settings
     * @return string Always returns an empty string
     */
    public function write_setting($data) {
        // do not write any setting
        return '';
    }

    /**
     * Returns an HTML string
     * @return string Returns an HTML string
     */
    public function output_html($data, $query='') {
        global $OUTPUT;
        $return = '';
        if ($this->visiblename != '') {
            $return .= $OUTPUT->heading('<a name="'.$this->name.'">'.highlightfast($query, $this->visiblename).'</a>', 3, 'main', true);
        }
        if ($this->description != '') {
            $return .= $OUTPUT->box(highlight($query, $this->description), 'generalbox formsettingheading');
        }
        return $return;
    }
}

/**
 * The most flexibly setting, user is typing text
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_configtext extends admin_setting {

    /** @var mixed int means PARAM_XXX type, string is a allowed format in regex */
    public $paramtype;
    /** @var int default field size */
    public $size;

    /**
     * Config text contructor
     *
     * @param string $name unique ascii name, either 'mysetting' for settings that in config, or 'myplugin/mysetting' for ones in config_plugins.
     * @param string $visiblename localised
     * @param string $description long localised info
     * @param string $defaultsetting
     * @param mixed $paramtype int means PARAM_XXX type, string is a allowed format in regex
     * @param int $size default field size
     */
    public function __construct($name, $visiblename, $description, $defaultsetting, $paramtype=PARAM_RAW, $size=null) {
        $this->paramtype = $paramtype;
        if (!is_null($size)) {
            $this->size  = $size;
        } else {
            $this->size  = ($paramtype == PARAM_INT) ? 5 : 30;
        }
        parent::__construct($name, $visiblename, $description, $defaultsetting);
    }

    /**
     * Return the setting
     *
     * @return mixed returns config if successfull else null
     */
    public function get_setting() {
        return $this->config_read($this->name);
    }

    public function write_setting($data) {
        if ($this->paramtype === PARAM_INT and $data === '') {
            // do not complain if '' used instead of 0
            $data = 0;
        }
        // $data is a string
        $validated = $this->validate($data);
        if ($validated !== true) {
            return $validated;
        }
        return ($this->config_write($this->name, $data) ? '' : get_string('errorsetting', 'admin'));
    }

    /**
     * Validate data before storage
     * @param string data
     * @return mixed true if ok string if error found
     */
    public function validate($data) {
        if (is_string($this->paramtype)) {
            if (preg_match($this->paramtype, $data)) {
                return true;
            } else {
                return get_string('validateerror', 'admin');
            }

        } else if ($this->paramtype === PARAM_RAW) {
            return true;

        } else {
            $cleaned = clean_param($data, $this->paramtype);
            if ("$data" == "$cleaned") { // implicit conversion to string is needed to do exact comparison
                return true;
            } else {
                return get_string('validateerror', 'admin');
            }
        }
    }

    /**
     * Return an XHTML string for the setting
     * @return string Returns an XHTML string
     */
    public function output_html($data, $query='') {
        $default = $this->get_defaultsetting();

        return format_admin_setting($this, $this->visiblename,
                '<div class="form-text defaultsnext"><input type="text" size="'.$this->size.'" id="'.$this->get_id().'" name="'.$this->get_full_name().'" value="'.s($data).'" /></div>',
                $this->description, true, '', $default, $query);
    }
}

/**
 * General text area without html editor.
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_configtextarea extends admin_setting_configtext {
    private $rows;
    private $cols;

    /**
     * @param string $name
     * @param string $visiblename
     * @param string $description
     * @param mixed $defaultsetting string or array
     * @param mixed $paramtype
     * @param string $cols The number of columns to make the editor
     * @param string $rows The number of rows to make the editor
     */
    public function __construct($name, $visiblename, $description, $defaultsetting, $paramtype=PARAM_RAW, $cols='60', $rows='8') {
        $this->rows = $rows;
        $this->cols = $cols;
        parent::__construct($name, $visiblename, $description, $defaultsetting, $paramtype);
    }
    /**
     * Returns an XHTML string for the editor
     *
     * @param string $data
     * @param string $query
     * @return string XHTML string for the editor
     */
    public function output_html($data, $query='') {
        $default = $this->get_defaultsetting();

        $defaultinfo = $default;
        if (!is_null($default) and $default !== '') {
            $defaultinfo = "\n".$default;
        }

        return format_admin_setting($this, $this->visiblename,
                '<div class="form-textarea" ><textarea rows="'. $this->rows .'" cols="'. $this->cols .'" id="'. $this->get_id() .'" name="'. $this->get_full_name() .'">'. s($data) .'</textarea></div>',
                $this->description, true, '', $defaultinfo, $query);
    }
}

/**
 * General text area with html editor.
 */
class admin_setting_confightmleditor extends admin_setting_configtext {
    private $rows;
    private $cols;

    /**
     * @param string $name
     * @param string $visiblename
     * @param string $description
     * @param mixed $defaultsetting string or array
     * @param mixed $paramtype
     */
    public function __construct($name, $visiblename, $description, $defaultsetting, $paramtype=PARAM_RAW, $cols='60', $rows='8') {
        $this->rows = $rows;
        $this->cols = $cols;
        parent::__construct($name, $visiblename, $description, $defaultsetting, $paramtype);
        editors_head_setup();
    }
    /**
     * Returns an XHTML string for the editor
     *
     * @param string $data
     * @param string $query
     * @return string XHTML string for the editor
     */
    public function output_html($data, $query='') {
        $default = $this->get_defaultsetting();

        $defaultinfo = $default;
        if (!is_null($default) and $default !== '') {
            $defaultinfo = "\n".$default;
        }

        $editor = get_preferred_texteditor(FORMAT_HTML);
        $editor->use_editor($this->get_id(), array('noclean'=>true));

        return format_admin_setting($this, $this->visiblename,
                '<div class="form-textarea"><textarea rows="'. $this->rows .'" cols="'. $this->cols .'" id="'. $this->get_id() .'" name="'. $this->get_full_name() .'">'. s($data) .'</textarea></div>',
                $this->description, true, '', $defaultinfo, $query);
    }
}

/**
 * Password field, allows unmasking of password
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_configpasswordunmask extends admin_setting_configtext {
    /**
     * Constructor
     * @param string $name unique ascii name, either 'mysetting' for settings that in config, or 'myplugin/mysetting' for ones in config_plugins.
     * @param string $visiblename localised
     * @param string $description long localised info
     * @param string $defaultsetting default password
     */
    public function __construct($name, $visiblename, $description, $defaultsetting) {
        parent::__construct($name, $visiblename, $description, $defaultsetting, PARAM_RAW, 30);
    }

    /**
     * Returns XHTML for the field
     * Writes Javascript into the HTML below right before the last div
     *
     * @todo Make javascript available through newer methods if possible
     * @param string $data Value for the field
     * @param string $query Passed as final argument for format_admin_setting
     * @return string XHTML field
     */
    public function output_html($data, $query='') {
        $id = $this->get_id();
        $unmask = get_string('unmaskpassword', 'form');
        $unmaskjs = '<script type="text/javascript">
//<![CDATA[
var is_ie = (navigator.userAgent.toLowerCase().indexOf("msie") != -1);

document.getElementById("'.$id.'").setAttribute("autocomplete", "off");

var unmaskdiv = document.getElementById("'.$id.'unmaskdiv");

var unmaskchb = document.createElement("input");
unmaskchb.setAttribute("type", "checkbox");
unmaskchb.setAttribute("id", "'.$id.'unmask");
unmaskchb.onchange = function() {unmaskPassword("'.$id.'");};
unmaskdiv.appendChild(unmaskchb);

var unmasklbl = document.createElement("label");
unmasklbl.innerHTML = "'.addslashes_js($unmask).'";
if (is_ie) {
  unmasklbl.setAttribute("htmlFor", "'.$id.'unmask");
} else {
  unmasklbl.setAttribute("for", "'.$id.'unmask");
}
unmaskdiv.appendChild(unmasklbl);

if (is_ie) {
  // ugly hack to work around the famous onchange IE bug
  unmaskchb.onclick = function() {this.blur();};
  unmaskdiv.onclick = function() {this.blur();};
}
//]]>
</script>';
        return format_admin_setting($this, $this->visiblename,
                '<div class="form-password"><input type="password" size="'.$this->size.'" id="'.$id.'" name="'.$this->get_full_name().'" value="'.s($data).'" /><div class="unmask" id="'.$id.'unmaskdiv"></div>'.$unmaskjs.'</div>',
                $this->description, true, '', NULL, $query);
    }
}

/**
 * Path to directory
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_configfile extends admin_setting_configtext {
    /**
     * Constructor
     * @param string $name unique ascii name, either 'mysetting' for settings that in config, or 'myplugin/mysetting' for ones in config_plugins.
     * @param string $visiblename localised
     * @param string $description long localised info
     * @param string $defaultdirectory default directory location
     */
    public function __construct($name, $visiblename, $description, $defaultdirectory) {
        parent::__construct($name, $visiblename, $description, $defaultdirectory, PARAM_RAW, 50);
    }

    /**
     * Returns XHTML for the field
     *
     * Returns XHTML for the field and also checks whether the file
     * specified in $data exists using file_exists()
     *
     * @param string $data File name and path to use in value attr
     * @param string $query
     * @return string XHTML field
     */
    public function output_html($data, $query='') {
        $default = $this->get_defaultsetting();

        if ($data) {
            if (file_exists($data)) {
                $executable = '<span class="pathok">&#x2714;</span>';
            } else {
                $executable = '<span class="patherror">&#x2718;</span>';
            }
        } else {
            $executable = '';
        }

        return format_admin_setting($this, $this->visiblename,
                '<div class="form-file defaultsnext"><input type="text" size="'.$this->size.'" id="'.$this->get_id().'" name="'.$this->get_full_name().'" value="'.s($data).'" />'.$executable.'</div>',
                $this->description, true, '', $default, $query);
    }
}

/**
 * Path to executable file
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_configexecutable extends admin_setting_configfile {

    /**
     * Returns an XHTML field
     *
     * @param string $data This is the value for the field
     * @param string $query
     * @return string XHTML field
     */
    public function output_html($data, $query='') {
        $default = $this->get_defaultsetting();

        if ($data) {
            if (file_exists($data) and is_executable($data)) {
                $executable = '<span class="pathok">&#x2714;</span>';
            } else {
                $executable = '<span class="patherror">&#x2718;</span>';
            }
        } else {
            $executable = '';
        }

        return format_admin_setting($this, $this->visiblename,
                '<div class="form-file defaultsnext"><input type="text" size="'.$this->size.'" id="'.$this->get_id().'" name="'.$this->get_full_name().'" value="'.s($data).'" />'.$executable.'</div>',
                $this->description, true, '', $default, $query);
    }
}

/**
 * Path to directory
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_configdirectory extends admin_setting_configfile {

    /**
     * Returns an XHTML field
     *
     * @param string $data This is the value for the field
     * @param string $query
     * @return string XHTML
     */
    public function output_html($data, $query='') {
        $default = $this->get_defaultsetting();

        if ($data) {
            if (file_exists($data) and is_dir($data)) {
                $executable = '<span class="pathok">&#x2714;</span>';
            } else {
                $executable = '<span class="patherror">&#x2718;</span>';
            }
        } else {
            $executable = '';
        }

        return format_admin_setting($this, $this->visiblename,
                '<div class="form-file defaultsnext"><input type="text" size="'.$this->size.'" id="'.$this->get_id().'" name="'.$this->get_full_name().'" value="'.s($data).'" />'.$executable.'</div>',
                $this->description, true, '', $default, $query);
    }
}

/**
 * Checkbox
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_configcheckbox extends admin_setting {
    /** @var string Value used when checked */
    public $yes;
    /** @var string Value used when not checked */
    public $no;

    /**
     * Constructor
     * @param string $name unique ascii name, either 'mysetting' for settings that in config, or 'myplugin/mysetting' for ones in config_plugins.
     * @param string $visiblename localised
     * @param string $description long localised info
     * @param string $defaultsetting
     * @param string $yes value used when checked
     * @param string $no value used when not checked
     */
    public function __construct($name, $visiblename, $description, $defaultsetting, $yes='1', $no='0') {
        parent::__construct($name, $visiblename, $description, $defaultsetting);
        $this->yes = (string)$yes;
        $this->no  = (string)$no;
    }

    /**
     * Retrieves the current setting using the objects name
     *
     * @return string
     */
    public function get_setting() {
        return $this->config_read($this->name);
    }

    /**
     * Sets the value for the setting
     *
     * Sets the value for the setting to either the yes or no values
     * of the object by comparing $data to yes
     *
     * @param mixed $data Gets converted to str for comparison against yes value
     * @return string empty string or error
     */
    public function write_setting($data) {
        if ((string)$data === $this->yes) { // convert to strings before comparison
            $data = $this->yes;
        } else {
            $data = $this->no;
        }
        return ($this->config_write($this->name, $data) ? '' : get_string('errorsetting', 'admin'));
    }

    /**
     * Returns an XHTML checkbox field
     *
     * @param string $data If $data matches yes then checkbox is checked
     * @param string $query
     * @return string XHTML field
     */
    public function output_html($data, $query='') {
        $default = $this->get_defaultsetting();

        if (!is_null($default)) {
            if ((string)$default === $this->yes) {
                $defaultinfo = get_string('checkboxyes', 'admin');
            } else {
                $defaultinfo = get_string('checkboxno', 'admin');
            }
        } else {
            $defaultinfo = NULL;
        }

        if ((string)$data === $this->yes) { // convert to strings before comparison
            $checked = 'checked="checked"';
        } else {
            $checked = '';
        }

        return format_admin_setting($this, $this->visiblename,
                '<div class="form-checkbox defaultsnext" ><input type="hidden" name="'.$this->get_full_name().'" value="'.s($this->no).'" /> '
                .'<input type="checkbox" id="'.$this->get_id().'" name="'.$this->get_full_name().'" value="'.s($this->yes).'" '.$checked.' /></div>',
                $this->description, true, '', $defaultinfo, $query);
    }
}

/**
 * Multiple checkboxes, each represents different value, stored in csv format
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_configmulticheckbox extends admin_setting {
    /** @var array Array of choices value=>label */
    public $choices;

    /**
     * Constructor: uses parent::__construct
     *
     * @param string $name unique ascii name, either 'mysetting' for settings that in config, or 'myplugin/mysetting' for ones in config_plugins.
     * @param string $visiblename localised
     * @param string $description long localised info
     * @param array $defaultsetting array of selected
     * @param array $choices array of $value=>$label for each checkbox
     */
    public function __construct($name, $visiblename, $description, $defaultsetting, $choices) {
        $this->choices = $choices;
        parent::__construct($name, $visiblename, $description, $defaultsetting);
    }

    /**
     * This public function may be used in ancestors for lazy loading of choices
     *
     * @todo Check if this function is still required content commented out only returns true
     * @return bool true if loaded, false if error
     */
    public function load_choices() {
        /*
        if (is_array($this->choices)) {
            return true;
        }
        .... load choices here
        */
        return true;
    }

    /**
     * Is setting related to query text - used when searching
     *
     * @param string $query
     * @return bool true on related, false on not or failure
     */
    public function is_related($query) {
        if (!$this->load_choices() or empty($this->choices)) {
            return false;
        }
        if (parent::is_related($query)) {
            return true;
        }

        $textlib = textlib_get_instance();
        foreach ($this->choices as $desc) {
            if (strpos($textlib->strtolower($desc), $query) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns the current setting if it is set
     *
     * @return mixed null if null, else an array
     */
    public function get_setting() {
        $result = $this->config_read($this->name);

        if (is_null($result)) {
            return NULL;
        }
        if ($result === '') {
            return array();
        }
        $enabled = explode(',', $result);
        $setting = array();
        foreach ($enabled as $option) {
            $setting[$option] = 1;
        }
        return $setting;
    }

    /**
     * Saves the setting(s) provided in $data
     *
     * @param array $data An array of data, if not array returns empty str
     * @return mixed empty string on useless data or bool true=success, false=failed
     */
    public function write_setting($data) {
        if (!is_array($data)) {
            return ''; // ignore it
        }
        if (!$this->load_choices() or empty($this->choices)) {
            return '';
        }
        unset($data['xxxxx']);
        $result = array();
        foreach ($data as $key => $value) {
            if ($value and array_key_exists($key, $this->choices)) {
                $result[] = $key;
            }
        }
        return $this->config_write($this->name, implode(',', $result)) ? '' : get_string('errorsetting', 'admin');
    }

    /**
     * Returns XHTML field(s) as required by choices
     *
     * Relies on data being an array should data ever be another valid vartype with
     * acceptable value this may cause a warning/error
     * if (!is_array($data)) would fix the problem
     *
     * @todo Add vartype handling to ensure $data is an array
     *
     * @param array $data An array of checked values
     * @param string $query
     * @return string XHTML field
     */
    public function output_html($data, $query='') {
        if (!$this->load_choices() or empty($this->choices)) {
            return '';
        }
        $default = $this->get_defaultsetting();
        if (is_null($default)) {
            $default = array();
        }
        if (is_null($data)) {
            $data = array();
        }
        $options = array();
        $defaults = array();
        foreach ($this->choices as $key=>$description) {
            if (!empty($data[$key])) {
                $checked = 'checked="checked"';
            } else {
                $checked = '';
            }
            if (!empty($default[$key])) {
                $defaults[] = $description;
            }

            $options[] = '<input type="checkbox" id="'.$this->get_id().'_'.$key.'" name="'.$this->get_full_name().'['.$key.']" value="1" '.$checked.' />'
                         .'<label for="'.$this->get_id().'_'.$key.'">'.highlightfast($query, $description).'</label>';
        }

        if (is_null($default)) {
            $defaultinfo = NULL;
        } else if (!empty($defaults)) {
            $defaultinfo = implode(', ', $defaults);
        } else {
            $defaultinfo = get_string('none');
        }

        $return = '<div class="form-multicheckbox">';
        $return .= '<input type="hidden" name="'.$this->get_full_name().'[xxxxx]" value="1" />'; // something must be submitted even if nothing selected
        if ($options) {
            $return .= '<ul>';
            foreach ($options as $option) {
                $return .= '<li>'.$option.'</li>';
            }
            $return .= '</ul>';
        }
        $return .= '</div>';

        return format_admin_setting($this, $this->visiblename, $return, $this->description, false, '', $defaultinfo, $query);

    }
}

/**
 * Multiple checkboxes 2, value stored as string 00101011
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_configmulticheckbox2 extends admin_setting_configmulticheckbox {

    /**
     * Returns the setting if set
     *
     * @return mixed null if not set, else an array of set settings
     */
    public function get_setting() {
        $result = $this->config_read($this->name);
        if (is_null($result)) {
            return NULL;
        }
        if (!$this->load_choices()) {
            return NULL;
        }
        $result = str_pad($result, count($this->choices), '0');
        $result = preg_split('//', $result, -1, PREG_SPLIT_NO_EMPTY);
        $setting = array();
        foreach ($this->choices as $key=>$unused) {
            $value = array_shift($result);
            if ($value) {
                $setting[$key] = 1;
            }
        }
        return $setting;
    }

    /**
     * Save setting(s) provided in $data param
     *
     * @param array $data An array of settings to save
     * @return mixed empty string for bad data or bool true=>success, false=>error
     */
    public function write_setting($data) {
        if (!is_array($data)) {
            return ''; // ignore it
        }
        if (!$this->load_choices() or empty($this->choices)) {
            return '';
        }
        $result = '';
        foreach ($this->choices as $key=>$unused) {
            if (!empty($data[$key])) {
                $result .= '1';
            } else {
                $result .= '0';
            }
        }
        return $this->config_write($this->name, $result) ? '' : get_string('errorsetting', 'admin');
    }
}

/**
 * Select one value from list
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_configselect extends admin_setting {
    /** @var array Array of choices value=>label */
    public $choices;

    /**
     * Constructor
     * @param string $name unique ascii name, either 'mysetting' for settings that in config, or 'myplugin/mysetting' for ones in config_plugins.
     * @param string $visiblename localised
     * @param string $description long localised info
     * @param string $defaultsetting
     * @param array $choices array of $value=>$label for each selection
     */
    public function __construct($name, $visiblename, $description, $defaultsetting, $choices) {
        $this->choices = $choices;
        parent::__construct($name, $visiblename, $description, $defaultsetting);
    }

    /**
     * This function may be used in ancestors for lazy loading of choices
     *
     * Override this method if loading of choices is expensive, such
     * as when it requires multiple db requests.
     *
     * @return bool true if loaded, false if error
     */
    public function load_choices() {
        /*
        if (is_array($this->choices)) {
            return true;
        }
        .... load choices here
        */
        return true;
    }

    /**
     * Check if this is $query is related to a choice
     *
     * @param string $query
     * @return bool true if related, false if not
     */
    public function is_related($query) {
        if (parent::is_related($query)) {
            return true;
        }
        if (!$this->load_choices()) {
            return false;
        }
        $textlib = textlib_get_instance();
        foreach ($this->choices as $key=>$value) {
            if (strpos($textlib->strtolower($key), $query) !== false) {
                return true;
            }
            if (strpos($textlib->strtolower($value), $query) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return the setting
     *
     * @return mixed returns config if successfull else null
     */
    public function get_setting() {
        return $this->config_read($this->name);
    }

    /**
     * Save a setting
     *
     * @param string $data
     * @return string empty of error string
     */
    public function write_setting($data) {
        if (!$this->load_choices() or empty($this->choices)) {
            return '';
        }
        if (!array_key_exists($data, $this->choices)) {
            return ''; // ignore it
        }

        return ($this->config_write($this->name, $data) ? '' : get_string('errorsetting', 'admin'));
    }

    /**
     * Returns XHTML select field
     *
     * Ensure the options are loaded, and generate the XHTML for the select
     * element and any warning message. Separating this out from output_html
     * makes it easier to subclass this class.
     *
     * @param string $data the option to show as selected.
     * @param string $current the currently selected option in the database, null if none.
     * @param string $default the default selected option.
     * @return array the HTML for the select element, and a warning message.
     */
    public function output_select_html($data, $current, $default, $extraname = '') {
        if (!$this->load_choices() or empty($this->choices)) {
            return array('', '');
        }

        $warning = '';
        if (is_null($current)) {
            // first run
        } else if (empty($current) and (array_key_exists('', $this->choices) or array_key_exists(0, $this->choices))) {
            // no warning
        } else if (!array_key_exists($current, $this->choices)) {
            $warning = get_string('warningcurrentsetting', 'admin', s($current));
            if (!is_null($default) and $data == $current) {
                $data = $default; // use default instead of first value when showing the form
            }
        }

        $selecthtml = '<select id="'.$this->get_id().'" name="'.$this->get_full_name().$extraname.'">';
        foreach ($this->choices as $key => $value) {
            // the string cast is needed because key may be integer - 0 is equal to most strings!
            $selecthtml .= '<option value="'.$key.'"'.((string)$key==$data ? ' selected="selected"' : '').'>'.$value.'</option>';
        }
        $selecthtml .= '</select>';
        return array($selecthtml, $warning);
    }

    /**
     * Returns XHTML select field and wrapping div(s)
     *
     * @see output_select_html()
     *
     * @param string $data the option to show as selected
     * @param string $query
     * @return string XHTML field and wrapping div
     */
    public function output_html($data, $query='') {
        $default = $this->get_defaultsetting();
        $current = $this->get_setting();

        list($selecthtml, $warning) = $this->output_select_html($data, $current, $default);
        if (!$selecthtml) {
            return '';
        }

        if (!is_null($default) and array_key_exists($default, $this->choices)) {
            $defaultinfo = $this->choices[$default];
        } else {
            $defaultinfo = NULL;
        }

        $return = '<div class="form-select defaultsnext">' . $selecthtml . '</div>';

        return format_admin_setting($this, $this->visiblename, $return, $this->description, true, $warning, $defaultinfo, $query);
    }
}

/**
 * Select multiple items from list
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_configmultiselect extends admin_setting_configselect {
    /**
     * Constructor
     * @param string $name unique ascii name, either 'mysetting' for settings that in config, or 'myplugin/mysetting' for ones in config_plugins.
     * @param string $visiblename localised
     * @param string $description long localised info
     * @param array $defaultsetting array of selected items
     * @param array $choices array of $value=>$label for each list item
     */
    public function __construct($name, $visiblename, $description, $defaultsetting, $choices) {
        parent::__construct($name, $visiblename, $description, $defaultsetting, $choices);
    }

    /**
     * Returns the select setting(s)
     *
     * @return mixed null or array. Null if no settings else array of setting(s)
     */
    public function get_setting() {
        $result = $this->config_read($this->name);
        if (is_null($result)) {
            return NULL;
        }
        if ($result === '') {
            return array();
        }
        return explode(',', $result);
    }

    /**
     * Saves setting(s) provided through $data
     *
     * Potential bug in the works should anyone call with this function
     * using a vartype that is not an array
     *
     * @todo Add vartype handling to ensure $data is an array
     * @param array $data
     */
    public function write_setting($data) {
        if (!is_array($data)) {
            return ''; //ignore it
        }
        if (!$this->load_choices() or empty($this->choices)) {
            return '';
        }

        unset($data['xxxxx']);

        $save = array();
        foreach ($data as $value) {
            if (!array_key_exists($value, $this->choices)) {
                continue; // ignore it
            }
            $save[] = $value;
        }

        return ($this->config_write($this->name, implode(',', $save)) ? '' : get_string('errorsetting', 'admin'));
    }

    /**
     * Is setting related to query text - used when searching
     *
     * @param string $query
     * @return bool true if related, false if not
     */
    public function is_related($query) {
        if (!$this->load_choices() or empty($this->choices)) {
            return false;
        }
        if (parent::is_related($query)) {
            return true;
        }

        $textlib = textlib_get_instance();
        foreach ($this->choices as $desc) {
            if (strpos($textlib->strtolower($desc), $query) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns XHTML multi-select field
     *
     * @todo Add vartype handling to ensure $data is an array
     * @param array $data Array of values to select by default
     * @param string $query
     * @return string XHTML multi-select field
     */
    public function output_html($data, $query='') {
        if (!$this->load_choices() or empty($this->choices)) {
            return '';
        }
        $choices = $this->choices;
        $default = $this->get_defaultsetting();
        if (is_null($default)) {
            $default = array();
        }
        if (is_null($data)) {
            $data = array();
        }

        $defaults = array();
        $size = min(10, count($this->choices));
        $return = '<div class="form-select"><input type="hidden" name="'.$this->get_full_name().'[xxxxx]" value="1" />'; // something must be submitted even if nothing selected
        $return .= '<select id="'.$this->get_id().'" name="'.$this->get_full_name().'[]" size="'.$size.'" multiple="multiple">';
        foreach ($this->choices as $key => $description) {
            if (in_array($key, $data)) {
                $selected = 'selected="selected"';
            } else {
                $selected = '';
            }
            if (in_array($key, $default)) {
                $defaults[] = $description;
            }

            $return .= '<option value="'.s($key).'" '.$selected.'>'.$description.'</option>';
        }

        if (is_null($default)) {
            $defaultinfo = NULL;
        } if (!empty($defaults)) {
            $defaultinfo = implode(', ', $defaults);
        } else {
            $defaultinfo = get_string('none');
        }

        $return .= '</select></div>';
        return format_admin_setting($this, $this->visiblename, $return, $this->description, true, '', $defaultinfo, $query);
    }
}

/**
 * Time selector
 *
 * This is a liiitle bit messy. we're using two selects, but we're returning
 * them as an array named after $name (so we only use $name2 internally for the setting)
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_configtime extends admin_setting {
    /** @var string Used for setting second select (minutes) */
    public $name2;

    /**
     * Constructor
     * @param string $hoursname setting for hours
     * @param string $minutesname setting for hours
     * @param string $visiblename localised
     * @param string $description long localised info
     * @param array $defaultsetting array representing default time 'h'=>hours, 'm'=>minutes
     */
    public function __construct($hoursname, $minutesname, $visiblename, $description, $defaultsetting) {
        $this->name2 = $minutesname;
        parent::__construct($hoursname, $visiblename, $description, $defaultsetting);
    }

    /**
     * Get the selected time
     *
     * @return mixed An array containing 'h'=>xx, 'm'=>xx, or null if not set
     */
    public function get_setting() {
        $result1 = $this->config_read($this->name);
        $result2 = $this->config_read($this->name2);
        if (is_null($result1) or is_null($result2)) {
            return NULL;
        }

        return array('h' => $result1, 'm' => $result2);
    }

    /**
     * Store the time (hours and minutes)
     *
     * @param array $data Must be form 'h'=>xx, 'm'=>xx
     * @return bool true if success, false if not
     */
    public function write_setting($data) {
        if (!is_array($data)) {
            return '';
        }

        $result = $this->config_write($this->name, (int)$data['h']) && $this->config_write($this->name2, (int)$data['m']);
        return ($result ? '' : get_string('errorsetting', 'admin'));
    }

    /**
     * Returns XHTML time select fields
     *
     * @param array $data Must be form 'h'=>xx, 'm'=>xx
     * @param string $query
     * @return string XHTML time select fields and wrapping div(s)
     */
    public function output_html($data, $query='') {
        $default = $this->get_defaultsetting();

        if (is_array($default)) {
            $defaultinfo = $default['h'].':'.$default['m'];
        } else {
            $defaultinfo = NULL;
        }

        $return = '<div class="form-time defaultsnext">'.
                  '<select id="'.$this->get_id().'h" name="'.$this->get_full_name().'[h]">';
        for ($i = 0; $i < 24; $i++) {
            $return .= '<option value="'.$i.'"'.($i == $data['h'] ? ' selected="selected"' : '').'>'.$i.'</option>';
        }
        $return .= '</select>:<select id="'.$this->get_id().'m" name="'.$this->get_full_name().'[m]">';
        for ($i = 0; $i < 60; $i += 5) {
            $return .= '<option value="'.$i.'"'.($i == $data['m'] ? ' selected="selected"' : '').'>'.$i.'</option>';
        }
        $return .= '</select></div>';
        return format_admin_setting($this, $this->visiblename, $return, $this->description, false, '', $defaultinfo, $query);
    }

}

/**
 * Used to validate a textarea used for ip addresses
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_configiplist extends admin_setting_configtextarea {

    /**
     * Validate the contents of the textarea as IP addresses
     *
     * Used to validate a new line seperated list of IP addresses collected from
     * a textarea control
     *
     * @param string $data A list of IP Addresses seperated by new lines
     * @return mixed bool true for success or string:error on failure
     */
    public function validate($data) {
        if(!empty($data)) {
            $ips = explode("\n", $data);
        } else {
            return true;
        }
        $result = true;
        foreach($ips as $ip) {
            $ip = trim($ip);
            if(preg_match('#^(\d{1,3})(\.\d{1,3}){0,3}$#', $ip, $match) ||
                   preg_match('#^(\d{1,3})(\.\d{1,3}){0,3}(\/\d{1,2})$#', $ip, $match) ||
                   preg_match('#^(\d{1,3})(\.\d{1,3}){3}(-\d{1,3})$#', $ip, $match)) {
                $result = true;
            } else {
                $result = false;
                break;
            }
        }
        if($result){
            return true;
        } else {
            return get_string('validateerror', 'admin');
        }
    }
}

/**
 * An admin setting for selecting one or more users who have a capability
 * in the system context
 *
 * An admin setting for selecting one or more users, who have a particular capability
 * in the system context. Warning, make sure the list will never be too long. There is
 * no paging or searching of this list.
 *
 * To correctly get a list of users from this config setting, you need to call the
 * get_users_from_config($CFG->mysetting, $capability); function in moodlelib.php.
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_users_with_capability extends admin_setting_configmultiselect {
    /** @var string The capabilities name */
    protected $capability;

    /**
     * Constructor.
     *
     * @param string $name unique ascii name, either 'mysetting' for settings that in config, or 'myplugin/mysetting' for ones in config_plugins.
     * @param string $visiblename localised name
     * @param string $description localised long description
     * @param array $defaultsetting array of usernames
     * @param string $capability string capability name.
     */
    function __construct($name, $visiblename, $description, $defaultsetting, $capability) {
        $this->capability = $capability;
        parent::__construct($name, $visiblename, $description, $defaultsetting, NULL);
    }

    /**
     * Load all of the uses who have the capability into choice array
     *
     * @return bool Always returns true
     */
    function load_choices() {
        if (is_array($this->choices)) {
            return true;
        }
        $users = get_users_by_capability(get_context_instance(CONTEXT_SYSTEM),
                $this->capability, 'u.id,u.username,u.firstname,u.lastname', 'u.lastname,u.firstname');
        $this->choices = array(
            '$@NONE@$' => get_string('nobody'),
            '$@ALL@$' => get_string('everyonewhocan', 'admin', get_capability_string($this->capability)),
        );
        foreach ($users as $user) {
            $this->choices[$user->username] = fullname($user);
        }
        return true;
    }

    /**
     * Returns the default setting for class
     *
     * @return mixed Array, or string. Empty string if no default
     */
    public function get_defaultsetting() {
        $this->load_choices();
        $defaultsetting = parent::get_defaultsetting();
        if (empty($defaultsetting)) {
            return array('$@NONE@$');
        } else if (array_key_exists($defaultsetting, $this->choices)) {
            return $defaultsetting;
        } else {
            return '';
        }
    }

    /**
     * Returns the current setting
     *
     * @return mixed array or string
     */
    public function get_setting() {
        $result = parent::get_setting();
        if (empty($result)) {
            $result = array('$@NONE@$');
        }
        return $result;
    }

    /**
     * Save the chosen setting provided as $data
     *
     * @param array $data
     * @return mixed string or array
     */
    public function write_setting($data) {
        // If all is selected, remove any explicit options.
        if (in_array('$@ALL@$', $data)) {
            $data = array('$@ALL@$');
        }
        // None never needs to be writted to the DB.
        if (in_array('$@NONE@$', $data)) {
            unset($data[array_search('$@NONE@$', $data)]);
        }
        return parent::write_setting($data);
    }
}

/**
 * Special checkbox for calendar - resets SESSION vars.
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_special_adminseesall extends admin_setting_configcheckbox {
    /**
     * Calls the parent::__construct with default values
     *
     * name =>  calendar_adminseesall
     * visiblename => get_string('adminseesall', 'admin')
     * description => get_string('helpadminseesall', 'admin')
     * defaultsetting => 0
     */
    public function __construct() {
        parent::__construct('calendar_adminseesall', get_string('adminseesall', 'admin'),
                            get_string('helpadminseesall', 'admin'), '0');
    }

    /**
     * Stores the setting passed in $data
     *
     * @param mixed gets converted to string for comparison
     * @return string empty string or error message
     */
    public function write_setting($data) {
        global $SESSION;
        unset($SESSION->cal_courses_shown);
        return parent::write_setting($data);
    }
}

/**
 * Special select for settings that are altered in setup.php and can not be altered on the fly
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_special_selectsetup extends admin_setting_configselect {
    /**
     * Reads the setting directly from the database
     *
     * @return mixed
     */
    public function get_setting() {
        // read directly from db!
        return get_config(NULL, $this->name);
    }

    /**
     * Save the setting passed in $data
     *
     * @param string $data The setting to save
     * @return string empty or error message
     */
    public function write_setting($data) {
        global $CFG;
        // do not change active CFG setting!
        $current = $CFG->{$this->name};
        $result = parent::write_setting($data);
        $CFG->{$this->name} = $current;
        return $result;
    }
}

/**
 * Special select for frontpage - stores data in course table
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_sitesetselect extends admin_setting_configselect {
    /**
     * Returns the site name for the selected site
     *
     * @see get_site()
     * @return string The site name of the selected site
     */
    public function get_setting() {
        $site = get_site();
        return $site->{$this->name};
    }
    /**
     * Updates the database and save the setting
     *
     * @param string data
     * @return string empty or error message
     */
    public function write_setting($data) {
        global $DB, $SITE;
        if (!in_array($data, array_keys($this->choices))) {
            return get_string('errorsetting', 'admin');
        }
        $record = new stdClass();
        $record->id           = SITEID;
        $temp                 = $this->name;
        $record->$temp        = $data;
        $record->timemodified = time();
        // update $SITE
        $SITE->{$this->name} = $data;
        return ($DB->update_record('course', $record) ? '' : get_string('errorsetting', 'admin'));
    }
}

/**
 * Special select - lists on the frontpage - hacky
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_courselist_frontpage extends admin_setting {
    /** @var array Array of choices value=>label */
    public $choices;

    /**
     * Construct override, requires one param
     *
     * @param bool $loggedin Is the user logged in
     */
    public function __construct($loggedin) {
        global $CFG;
        require_once($CFG->dirroot.'/course/lib.php');
        $name        = 'frontpage'.($loggedin ? 'loggedin' : '');
        $visiblename = get_string('frontpage'.($loggedin ? 'loggedin' : ''),'admin');
        $description = get_string('configfrontpage'.($loggedin ? 'loggedin' : ''),'admin');
        $defaults    = array(FRONTPAGECOURSELIST);
        parent::__construct($name, $visiblename, $description, $defaults);
    }

    /**
     * Loads the choices available
     *
     * @return bool always returns true
     */
    public function load_choices() {
        global $DB;
        if (is_array($this->choices)) {
            return true;
        }
        $this->choices = array(FRONTPAGENEWS          => get_string('frontpagenews'),
                               FRONTPAGECOURSELIST    => get_string('frontpagecourselist'),
                               FRONTPAGECATEGORYNAMES => get_string('frontpagecategorynames'),
                               FRONTPAGECATEGORYCOMBO => get_string('frontpagecategorycombo'),
                               'none'                 => get_string('none'));
        if ($this->name == 'frontpage' and $DB->count_records('course') > FRONTPAGECOURSELIMIT) {
            unset($this->choices[FRONTPAGECOURSELIST]);
        }
        return true;
    }
    /**
     * Returns the selected settings
     *
     * @param mixed array or setting or null
     */
    public function get_setting() {
        $result = $this->config_read($this->name);
        if (is_null($result)) {
            return NULL;
        }
        if ($result === '') {
            return array();
        }
        return explode(',', $result);
    }

    /**
     * Save the selected options
     *
     * @param array $data
     * @return mixed empty string (data is not an array) or bool true=success false=failure
     */
    public function write_setting($data) {
        if (!is_array($data)) {
            return '';
        }
        $this->load_choices();
        $save = array();
        foreach($data as $datum) {
            if ($datum == 'none' or !array_key_exists($datum, $this->choices)) {
                continue;
            }
            $save[$datum] = $datum; // no duplicates
        }
        return ($this->config_write($this->name, implode(',', $save)) ? '' : get_string('errorsetting', 'admin'));
    }

    /**
     * Return XHTML select field and wrapping div
     *
     * @todo Add vartype handling to make sure $data is an array
     * @param array $data Array of elements to select by default
     * @return string XHTML select field and wrapping div
     */
    public function output_html($data, $query='') {
        $this->load_choices();
        $currentsetting = array();
        foreach ($data as $key) {
            if ($key != 'none' and array_key_exists($key, $this->choices)) {
                $currentsetting[] = $key; // already selected first
            }
        }

        $return = '<div class="form-group">';
        for ($i = 0; $i < count($this->choices) - 1; $i++) {
            if (!array_key_exists($i, $currentsetting)) {
                $currentsetting[$i] = 'none'; //none
            }
            $return .='<select class="form-select" id="'.$this->get_id().$i.'" name="'.$this->get_full_name().'[]">';
            foreach ($this->choices as $key => $value) {
                $return .= '<option value="'.$key.'"'.("$key" == $currentsetting[$i] ? ' selected="selected"' : '').'>'.$value.'</option>';
            }
            $return .= '</select>';
            if ($i !== count($this->choices) - 2) {
                $return .= '<br />';
            }
        }
        $return .= '</div>';

        return format_admin_setting($this, $this->visiblename, $return, $this->description, false, '', NULL, $query);
    }
}

/**
 * Special checkbox for frontpage - stores data in course table
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_sitesetcheckbox extends admin_setting_configcheckbox {
    /**
     * Returns the current sites name
     *
     * @return string
     */
    public function get_setting() {
        $site = get_site();
        return $site->{$this->name};
    }

    /**
     * Save the selected setting
     *
     * @param string $data The selected site
     * @return string empty string or error message
     */
    public function write_setting($data) {
        global $DB, $SITE;
        $record = new object();
        $record->id            = SITEID;
        $record->{$this->name} = ($data == '1' ? 1 : 0);
        $record->timemodified  = time();
        // update $SITE
        $SITE->{$this->name} = $data;
        return ($DB->update_record('course', $record) ? '' : get_string('errorsetting', 'admin'));
    }
}

/**
 * Special text for frontpage - stores data in course table.
 * Empty string means not set here. Manual setting is required.
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_sitesettext extends admin_setting_configtext {
    /**
     * Return the current setting
     *
     * @return mixed string or null
     */
    public function get_setting() {
        $site = get_site();
        return $site->{$this->name} != '' ? $site->{$this->name} : NULL;
    }

    /**
     * Validate the selected data
     *
     * @param string $data The selected value to validate
     * @return mixed true or message string
     */
    public function validate($data) {
        $cleaned = clean_param($data, PARAM_MULTILANG);
        if ($cleaned === '') {
            return get_string('required');
        }
        if ("$data" == "$cleaned") { // implicit conversion to string is needed to do exact comparison
            return true;
        } else {
            return get_string('validateerror', 'admin');
        }
    }

    /**
     * Save the selected setting
     *
     * @param string $data The selected value
     * @return string emtpy or error message
     */
    public function write_setting($data) {
        global $DB, $SITE;
        $data = trim($data);
        $validated = $this->validate($data);
        if ($validated !== true) {
            return $validated;
        }

        $record = new object();
        $record->id            = SITEID;
        $record->{$this->name} = $data;
        $record->timemodified  = time();
        // update $SITE
        $SITE->{$this->name} = $data;
        return ($DB->update_record('course', $record) ? '' : get_string('dbupdatefailed', 'error'));
    }
}

/**
 * Special text editor for site description.
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_special_frontpagedesc extends admin_setting {
    /**
     * Calls parent::__construct with specific arguments
     */
    public function __construct() {
        parent::__construct('summary', get_string('frontpagedescription'), get_string('frontpagedescriptionhelp'), NULL);
        editors_head_setup();
    }

    /**
     * Return the current setting
     * @return string The current setting
     */
    public function get_setting() {
        $site = get_site();
        return $site->{$this->name};
    }

    /**
     * Save the new setting
     *
     * @param string $data The new value to save
     * @return string empty or error message
     */
    public function write_setting($data) {
        global $DB, $SITE;
        $record = new object();
        $record->id            = SITEID;
        $record->{$this->name} = $data;
        $record->timemodified  = time();
        $SITE->{$this->name} = $data;
        return ($DB->update_record('course', $record) ? '' : get_string('errorsetting', 'admin'));
    }

    /**
     * Returns XHTML for the field plus wrapping div
     *
     * @param string $data The current value
     * @param string $query
     * @return string The XHTML output
     */
    public function output_html($data, $query='') {
        global $CFG;

        $CFG->adminusehtmleditor = can_use_html_editor();
        $return = '<div class="form-htmlarea">'.print_textarea($CFG->adminusehtmleditor, 15, 60, 0, 0, $this->get_full_name(), $data, 0, true, 'summary') .'</div>';

        return format_admin_setting($this, $this->visiblename, $return, $this->description, false, '', NULL, $query);
    }
}

/**
 * Special font selector for use in admin section
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_special_editorfontlist extends admin_setting {

    /**
     * Construct method, calls parent::__construct with specific args
     */
    public function __construct() {
        global $CFG;
        $name = 'editorfontlist';
        $visiblename = get_string('editorfontlist', 'admin');
        $description = get_string('configeditorfontlist', 'admin');
        $defaults = array('k0' => 'Trebuchet',
                          'v0' => 'Trebuchet MS,Verdana,Arial,Helvetica,sans-serif',
                          'k1' => 'Arial',
                          'v1' => 'arial,helvetica,sans-serif',
                          'k2' => 'Courier New',
                          'v2' => 'courier new,courier,monospace',
                          'k3' => 'Georgia',
                          'v3' => 'georgia,times new roman,times,serif',
                          'k4' => 'Tahoma',
                          'v4' => 'tahoma,arial,helvetica,sans-serif',
                          'k5' => 'Times New Roman',
                          'v5' => 'times new roman,times,serif',
                          'k6' => 'Verdana',
                          'v6' => 'verdana,arial,helvetica,sans-serif',
                          'k7' => 'Impact',
                          'v7' => 'impact',
                          'k8' => 'Wingdings',
                          'v8' => 'wingdings');
        parent::__construct($name, $visiblename, $description, $defaults);
    }

    /**
     * Return the current setting
     *
     * @return array Array of the current setting(s)
     */
    public function get_setting() {
        global $CFG;
        $result = $this->config_read($this->name);
        if (is_null($result)) {
            return NULL;
        }
        $i = 0;
        $currentsetting = array();
        $items = explode(';', $result);
        foreach ($items as $item) {
          $item = explode(':', $item);
          $currentsetting['k'.$i] = $item[0];
          $currentsetting['v'.$i] = $item[1];
          $i++;
        }
        return $currentsetting;
    }

    /**
     * Save the new setting(s)
     *
     * @todo Add vartype handling to ensure $data is an array
     * @param array $data Array containing the new settings
     * @return bool
     */
    public function write_setting($data) {

        // there miiight be an easier way to do this :)
        // if this is changed, make sure the $defaults array above is modified so that this
        // function processes it correctly

        $keys = array();
        $values = array();

        foreach ($data as $key => $value) {
            if (substr($key,0,1) == 'k') {
                $keys[substr($key,1)] = $value;
            } elseif (substr($key,0,1) == 'v') {
                $values[substr($key,1)] = $value;
            }
        }

        $result = array();
        for ($i = 0; $i < count($keys); $i++) {
            if (($keys[$i] !== '') && ($values[$i] !== '')) {
                $result[] = clean_param($keys[$i],PARAM_NOTAGS).':'.clean_param($values[$i], PARAM_NOTAGS);
            }
        }

        return ($this->config_write($this->name, implode(';', $result)) ? '' : get_string('errorsetting', 'admin'));
    }

    /**
     * Returns XHTML for the options
     *
     * @todo Add vartype handling to ensure that $data is an array
     * @param array $data An array of values to set
     * @param string $query
     * @return string XHTML
     */
    public function output_html($data, $query='') {
        $fullname = $this->get_full_name();
        $return = '<div class="form-group">';
        for ($i = 0; $i < count($data) / 2; $i++) {
            $return .= '<input type="text" class="form-text" name="'.$fullname.'[k'.$i.']" value="'.$data['k'.$i].'" />';
            $return .= '&nbsp;&nbsp;';
            $return .= '<input type="text" class="form-text" name="'.$fullname.'[v'.$i.']" value="'.$data['v'.$i].'" /><br />';
        }
        $return .= '<input type="text" class="form-text" name="'.$fullname.'[k'.$i.']" value="" />';
        $return .= '&nbsp;&nbsp;';
        $return .= '<input type="text" class="form-text" name="'.$fullname.'[v'.$i.']" value="" /><br />';
        $return .= '<input type="text" class="form-text" name="'.$fullname.'[k'.($i + 1).']" value="" />';
        $return .= '&nbsp;&nbsp;';
        $return .= '<input type="text" class="form-text" name="'.$fullname.'[v'.($i + 1).']" value="" />';
        $return .= '</div>';

        return format_admin_setting($this, $this->visiblename, $return, $this->description, false, '', NULL, $query);
    }

}
/**
 * Special settings for emoticons
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_emoticons extends admin_setting {

    /**
     * Calls parent::__construct with specific args
     */
    public function __construct() {
        global $CFG;
        $name = 'emoticons';
        $visiblename = get_string('emoticons', 'admin');
        $description = get_string('configemoticons', 'admin');
        $defaults = array('k0' => ':-)',
                          'v0' => 'smiley',
                          'k1' => ':)',
                          'v1' => 'smiley',
                          'k2' => ':-D',
                          'v2' => 'biggrin',
                          'k3' => ';-)',
                          'v3' => 'wink',
                          'k4' => ':-/',
                          'v4' => 'mixed',
                          'k5' => 'V-.',
                          'v5' => 'thoughtful',
                          'k6' => ':-P',
                          'v6' => 'tongueout',
                          'k7' => 'B-)',
                          'v7' => 'cool',
                          'k8' => '^-)',
                          'v8' => 'approve',
                          'k9' => '8-)',
                          'v9' => 'wideeyes',
                          'k10' => ':o)',
                          'v10' => 'clown',
                          'k11' => ':-(',
                          'v11' => 'sad',
                          'k12' => ':(',
                          'v12' => 'sad',
                          'k13' => '8-.',
                          'v13' => 'shy',
                          'k14' => ':-I',
                          'v14' => 'blush',
                          'k15' => ':-X',
                          'v15' => 'kiss',
                          'k16' => '8-o',
                          'v16' => 'surprise',
                          'k17' => 'P-|',
                          'v17' => 'blackeye',
                          'k18' => '8-[',
                          'v18' => 'angry',
                          'k19' => 'xx-P',
                          'v19' => 'dead',
                          'k20' => '|-.',
                          'v20' => 'sleepy',
                          'k21' => '}-]',
                          'v21' => 'evil',
                          'k22' => '(h)',
                          'v22' => 'heart',
                          'k23' => '(heart)',
                          'v23' => 'heart',
                          'k24' => '(y)',
                          'v24' => 'yes',
                          'k25' => '(n)',
                          'v25' => 'no',
                          'k26' => '(martin)',
                          'v26' => 'martin',
                          'k27' => '( )',
                          'v27' => 'egg');
        parent::__construct($name, $visiblename, $description, $defaults);
    }

    /**
     * Return the current setting(s)
     *
     * @return array Current settings array
     */
    public function get_setting() {
        global $CFG;
        $result = $this->config_read($this->name);
        if (is_null($result)) {
            return NULL;
        }
        $i = 0;
        $currentsetting = array();
        $items = explode('{;}', $result);
        foreach ($items as $item) {
          $item = explode('{:}', $item);
          $currentsetting['k'.$i] = $item[0];
          $currentsetting['v'.$i] = $item[1];
          $i++;
        }
        return $currentsetting;
    }

    /**
     * Save selected settings
     *
     * @param array $data Array of settings to save
     * @return bool
     */
    public function write_setting($data) {

        // there miiight be an easier way to do this :)
        // if this is changed, make sure the $defaults array above is modified so that this
        // function processes it correctly

        $keys = array();
        $values = array();

        foreach ($data as $key => $value) {
            if (substr($key,0,1) == 'k') {
                $keys[substr($key,1)] = $value;
            } elseif (substr($key,0,1) == 'v') {
                $values[substr($key,1)] = $value;
            }
        }

        $result = array();
        for ($i = 0; $i < count($keys); $i++) {
            if (($keys[$i] !== '') && ($values[$i] !== '')) {
                $result[] = clean_param($keys[$i],PARAM_NOTAGS).'{:}'.clean_param($values[$i], PARAM_NOTAGS);
            }
        }

        return ($this->config_write($this->name, implode('{;}', $result)) ? '' : get_string('errorsetting', 'admin').$this->visiblename.'<br />');
    }

    /**
     * Return XHTML field(s) for options
     *
     * @param array $data Array of options to set in HTML
     * @return string XHTML string for the fields and wrapping div(s)
     */
    public function output_html($data, $query='') {
        $fullname = $this->get_full_name();
        $return = '<div class="form-group">';
        for ($i = 0; $i < count($data) / 2; $i++) {
            $return .= '<input type="text" class="form-text" name="'.$fullname.'[k'.$i.']" value="'.$data['k'.$i].'" />';
            $return .= '&nbsp;&nbsp;';
            $return .= '<input type="text" class="form-text" name="'.$fullname.'[v'.$i.']" value="'.$data['v'.$i].'" /><br />';
        }
        $return .= '<input type="text" class="form-text" name="'.$fullname.'[k'.$i.']" value="" />';
        $return .= '&nbsp;&nbsp;';
        $return .= '<input type="text" class="form-text" name="'.$fullname.'[v'.$i.']" value="" /><br />';
        $return .= '<input type="text" class="form-text" name="'.$fullname.'[k'.($i + 1).']" value="" />';
        $return .= '&nbsp;&nbsp;';
        $return .= '<input type="text" class="form-text" name="'.$fullname.'[v'.($i + 1).']" value="" />';
        $return .= '</div>';

        return format_admin_setting($this, $this->visiblename, $return, $this->description, false, '', NULL, $query);
    }

}
/**
 * Used to set editor options/settings
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

class admin_setting_special_editorhidebuttons extends admin_setting {
    /** @var array Array of possible options */
    public $items;

    /**
     * Calls parent::__construct with specific options
     */
    public function __construct() {
        parent::__construct('editorhidebuttons', get_string('editorhidebuttons', 'admin'),
                            get_string('confeditorhidebuttons', 'admin'), array());
        // weird array... buttonname => buttonimage (assume proper path appended). if you leave buttomimage blank, text will be printed instead
        $this->items = array('fontname' => '',
                         'fontsize' => '',
                         'formatblock' => '',
                         'bold' => 'ed_format_bold.gif',
                         'italic' => 'ed_format_italic.gif',
                         'underline' => 'ed_format_underline.gif',
                         'strikethrough' => 'ed_format_strike.gif',
                         'subscript' => 'ed_format_sub.gif',
                         'superscript' => 'ed_format_sup.gif',
                         'copy' => 'ed_copy.gif',
                         'cut' => 'ed_cut.gif',
                         'paste' => 'ed_paste.gif',
                         'clean' => 'ed_wordclean.gif',
                         'undo' => 'ed_undo.gif',
                         'redo' => 'ed_redo.gif',
                         'justifyleft' => 'ed_align_left.gif',
                         'justifycenter' => 'ed_align_center.gif',
                         'justifyright' => 'ed_align_right.gif',
                         'justifyfull' => 'ed_align_justify.gif',
                         'lefttoright' => 'ed_left_to_right.gif',
                         'righttoleft' => 'ed_right_to_left.gif',
                         'insertorderedlist' => 'ed_list_num.gif',
                         'insertunorderedlist' => 'ed_list_bullet.gif',
                         'outdent' => 'ed_indent_less.gif',
                         'indent' => 'ed_indent_more.gif',
                         'forecolor' => 'ed_color_fg.gif',
                         'hilitecolor' => 'ed_color_bg.gif',
                         'inserthorizontalrule' => 'ed_hr.gif',
                         'createanchor' => 'ed_anchor.gif',
                         'createlink' => 'ed_link.gif',
                         'unlink' => 'ed_unlink.gif',
                         'insertimage' => 'ed_image.gif',
                         'inserttable' => 'insert_table.gif',
                         'insertsmile' => 'em.icon.smile.gif',
                         'insertchar' => 'icon_ins_char.gif',
                         'spellcheck' => 'spell-check.gif',
                         'htmlmode' => 'ed_html.gif',
                         'popupeditor' => 'fullscreen_maximize.gif',
                         'search_replace' => 'ed_replace.gif');
    }

    /**
     * Get an array of current settings
     *
     * @return array Array of current settings
     */
    public function get_setting() {
        $result = $this->config_read($this->name);
        if (is_null($result)) {
            return NULL;
        }
        if ($result === '') {
            return array();
        }
        return explode(' ', $result);
    }

    /**
     * Save the selected settings
     *
     * @param array $data Array of settings to save
     * @return mixed empty string, error string, or bool true=>success, false=>error
     */
    public function write_setting($data) {
        if (!is_array($data)) {
            return ''; // ignore it
        }
        unset($data['xxxxx']);
        $result = array();

        foreach ($data as $key => $value) {
            if (!isset($this->items[$key])) {
                return get_string('errorsetting', 'admin');
            }
            if ($value == '1') {
                $result[] = $key;
            }
        }
        return ($this->config_write($this->name, implode(' ', $result)) ? '' : get_string('errorsetting', 'admin'));
    }

    /**
     * Return XHTML for the field and wrapping div(s)
     *
     * @param array $data
     * @param string $query
     * @return string XHTML for output
     */
    public function output_html($data, $query='') {

        global $CFG;

        // checkboxes with input name="$this->name[$key]" value="1"
        // we do 15 fields per column

        $return = '<div class="form-group">';
        $return .= '<table><tr><td valign="top" align="right">';
        $return .= '<input type="hidden" name="'.$this->get_full_name().'[xxxxx]" value="1" />'; // something must be submitted even if nothing selected

        $count = 0;

        foreach($this->items as $key => $value) {
            if ($count % 15 == 0 and $count != 0) {
                $return .= '</td><td valign="top" align="right">';
            }

            $return .= '<label for="'.$this->get_id().$key.'">';
            $return .= ($value == '' ? get_string($key,'editor') : '<img width="18" height="18" src="'.$CFG->wwwroot.'/lib/editor/htmlarea/images/'.$value.'" alt="'.get_string($key,'editor').'" title="'.get_string($key,'editor').'" />').'&nbsp;';
            $return .= '<input type="checkbox" class="form-checkbox" value="1" id="'.$this->get_id().$key.'" name="'.$this->get_full_name().'['.$key.']"'.(in_array($key,$data) ? ' checked="checked"' : '').' />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';
            $return .= '</label>';
            $count++;
            if ($count % 15 != 0) {
                $return .= '<br /><br />';
            }
        }

        $return .= '</td></tr>';
        $return .= '</table>';
        $return .= '</div>';

        return format_admin_setting($this, $this->visiblename, $return, $this->description, false, '', NULL, $query);
    }
}

/**
 * Special setting for limiting of the list of available languages.
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_langlist extends admin_setting_configtext {
    /**
     * Calls parent::__construct with specific arguments
     */
    public function __construct() {
        parent::__construct('langlist', get_string('langlist', 'admin'), get_string('configlanglist', 'admin'), '', PARAM_NOTAGS);
    }

    /**
     * Save the new setting
     *
     * @param string $data The new setting
     * @return bool
     */
    public function write_setting($data) {
        $return = parent::write_setting($data);
        get_list_of_languages(true);//refresh the list
        return $return;
    }
}

/**
 * Course category selection
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_settings_coursecat_select extends admin_setting_configselect {
    /**
     * Calls parent::__construct with specific arguments
     */
    public function __construct($name, $visiblename, $description, $defaultsetting) {
        parent::__construct($name, $visiblename, $description, $defaultsetting, NULL);
    }

    /**
     * Load the available choices for the select box
     *
     * @return bool
     */
    public function load_choices() {
        global $CFG;
        require_once($CFG->dirroot.'/course/lib.php');
        if (is_array($this->choices)) {
            return true;
        }
        $this->choices = make_categories_options();
        return true;
    }
}

/**
 * Special control for selecting days to backup
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_special_backupdays extends admin_setting_configmulticheckbox2 {
    /**
     * Calls parent::__construct with specific arguments
     */
    public function __construct() {
        parent::__construct('backup_sche_weekdays', get_string('schedule'), get_string('backupschedulehelp'), array(), NULL);
        $this->plugin = 'backup';
    }
    /**
     * Load the available choices for the select box
     *
     * @return bool Always returns true
     */
    public function load_choices() {
        if (is_array($this->choices)) {
            return true;
        }
        $this->choices = array();
        $days = array('sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday');
        foreach ($days as $day) {
            $this->choices[$day] = get_string($day, 'calendar');
        }
        return true;
    }
}

/**
 * Special debug setting
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_special_debug extends admin_setting_configselect {
    /**
     * Calls parent::__construct with specific arguments
     */
    public function __construct() {
        parent::__construct('debug', get_string('debug', 'admin'), get_string('configdebug', 'admin'), DEBUG_NONE, NULL);
    }

    /**
     * Load the available choices for the select box
     *
     * @return bool
     */
    public function load_choices() {
        if (is_array($this->choices)) {
            return true;
        }
        $this->choices = array(DEBUG_NONE      => get_string('debugnone', 'admin'),
                               DEBUG_MINIMAL   => get_string('debugminimal', 'admin'),
                               DEBUG_NORMAL    => get_string('debugnormal', 'admin'),
                               DEBUG_ALL       => get_string('debugall', 'admin'),
                               DEBUG_DEVELOPER => get_string('debugdeveloper', 'admin'));
        return true;
    }
}

/**
 * Special admin control
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_special_calendar_weekend extends admin_setting {
    /**
     * Calls parent::__construct with specific arguments
     */
    public function __construct() {
        $name = 'calendar_weekend';
        $visiblename = get_string('calendar_weekend', 'admin');
        $description = get_string('helpweekenddays', 'admin');
        $default = array ('0', '6'); // Saturdays and Sundays
        parent::__construct($name, $visiblename, $description, $default);
    }
    /**
     * Gets the current settins as an array
     *
     * @return mixed Null if none, else array of settings
     */
    public function get_setting() {
        $result = $this->config_read($this->name);
        if (is_null($result)) {
            return NULL;
        }
        if ($result === '') {
            return array();
        }
        $settings = array();
        for ($i=0; $i<7; $i++) {
            if ($result & (1 << $i)) {
                $settings[] = $i;
            }
        }
        return $settings;
    }

    /**
     * Save the new settings
     *
     * @param array $data Array of new settings
     * @return bool
     */
    public function write_setting($data) {
        if (!is_array($data)) {
            return '';
        }
        unset($data['xxxxx']);
        $result = 0;
        foreach($data as $index) {
            $result |= 1 << $index;
        }
        return ($this->config_write($this->name, $result) ? '' : get_string('errorsetting', 'admin'));
    }

    /**
     * Return XHTML to display the control
     *
     * @param array $data array of selected days
     * @param string $query
     * @return string XHTML for display (field + wrapping div(s)
     */
    public function output_html($data, $query='') {
        // The order matters very much because of the implied numeric keys
        $days = array('sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday');
        $return = '<table><thead><tr>';
        $return .= '<input type="hidden" name="'.$this->get_full_name().'[xxxxx]" value="1" />'; // something must be submitted even if nothing selected
        foreach($days as $index => $day) {
            $return .= '<td><label for="'.$this->get_id().$index.'">'.get_string($day, 'calendar').'</label></td>';
        }
        $return .= '</tr></thead><tbody><tr>';
        foreach($days as $index => $day) {
            $return .= '<td><input type="checkbox" class="form-checkbox" id="'.$this->get_id().$index.'" name="'.$this->get_full_name().'[]" value="'.$index.'" '.(in_array("$index", $data) ? 'checked="checked"' : '').' /></td>';
        }
        $return .= '</tr></tbody></table>';

        return format_admin_setting($this, $this->visiblename, $return, $this->description, false, '', NULL, $query);

    }
}


/**
 * Admin setting that allows a user to pick appropriate roles for something.
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_pickroles extends admin_setting_configmulticheckbox {
    /** @var array Array of capabilities which identify roles */
    private $types;

    /**
     * @param string $name Name of config variable
     * @param string $visiblename Display name
     * @param string $description Description
     * @param array $types Array of capabilities (usually moodle/legacy:something)
     *   which identify roles that will be enabled by default. Default is the
     *   student role
     */
    public function __construct($name, $visiblename, $description, $types) {
        parent::__construct($name, $visiblename, $description, NULL, NULL);
        $this->types = $types;
    }

    /**
     * Load roles as choices
     *
     * @return bool true=>success, false=>error
     */
    public function load_choices() {
        global $CFG, $DB;
        if (during_initial_install()) {
            return false;
        }
        if (is_array($this->choices)) {
            return true;
        }
        if ($roles = get_all_roles()) {
            $this->choices = array();
            foreach($roles as $role) {
                $this->choices[$role->id] = format_string($role->name);
            }
            return true;
        } else {
            return false;
        }
    }
    /**
     * Return the default setting for this control
     *
     * @return array Array of default settings
     */
    public function get_defaultsetting() {
        global $CFG;

        if (during_initial_install()) {
            return null;
        }
        $result = array();
        foreach($this->types as $capability) {
            if ($caproles = get_roles_with_capability($capability, CAP_ALLOW)) {
                foreach ($caproles as $caprole) {
                    $result[$caprole->id] = 1;
                }
            }
        }
        return $result;
    }
}

/**
 * Text field with an advanced checkbox, that controls a additional $name.'_adv' setting.
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_configtext_with_advanced extends admin_setting_configtext {
    /**
     * Constructor
     * @param string $name unique ascii name, either 'mysetting' for settings that in config, or 'myplugin/mysetting' for ones in config_plugins.
     * @param string $visiblename localised
     * @param string $description long localised info
     * @param array $defaultsetting ('value'=>string, '__construct'=>bool)
     * @param mixed $paramtype int means PARAM_XXX type, string is a allowed format in regex
     * @param int $size default field size
     */
    public function __construct($name, $visiblename, $description, $defaultsetting, $paramtype=PARAM_RAW, $size=null) {
        parent::__construct($name, $visiblename, $description, $defaultsetting, $paramtype, $size);
    }

    /**
     * Loads the current setting and returns array
     *
     * @return array Returns array value=>xx, __construct=>xx
     */
    public function get_setting() {
        $value = parent::get_setting();
        $adv = $this->config_read($this->name.'_adv');
        if (is_null($value) or is_null($adv)) {
            return NULL;
        }
        return array('value' => $value, 'adv' => $adv);
    }

    /**
     * Saves the new settings passed in $data
     *
     * @todo Add vartype handling to ensure $data is an array
     * @param array $data
     * @return mixed string or Array
     */
    public function write_setting($data) {
        $error = parent::write_setting($data['value']);
        if (!$error) {
            $value = empty($data['adv']) ? 0 : 1;
            $this->config_write($this->name.'_adv', $value);
        }
        return $error;
    }

    /**
     * Return XHTML for the control
     *
     * @param array $data Default data array
     * @param string $query
     * @return string XHTML to display control
     */
    public function output_html($data, $query='') {
        $default = $this->get_defaultsetting();
        $defaultinfo = array();
        if (isset($default['value'])) {
            if ($default['value'] === '') {
                $defaultinfo[] = "''";
            } else {
                $defaultinfo[] = $default['value'];
            }
        }
        if (!empty($default['adv'])) {
            $defaultinfo[] = get_string('advanced');
        }
        $defaultinfo = implode(', ', $defaultinfo);

        $adv = !empty($data['adv']);
        $return = '<div class="form-text defaultsnext">' .
                '<input type="text" size="' . $this->size . '" id="' . $this->get_id() .
                '" name="' . $this->get_full_name() . '[value]" value="' . s($data['value']) . '" />' .
                ' <input type="checkbox" class="form-checkbox" id="' .
                $this->get_id() . '_adv" name="' . $this->get_full_name() .
                '[adv]" value="1" ' . ($adv ? 'checked="checked"' : '') . ' />' .
                ' <label for="' . $this->get_id() . '_adv">' .
                get_string('advanced') . '</label></div>';

        return format_admin_setting($this, $this->visiblename, $return,
                $this->description, true, '', $defaultinfo, $query);
    }
}

/**
 * Checkbox with an advanced checkbox that controls an additional $name.'_adv' config setting.
 *
 * @copyright 2009 Petr Skoda (http://skodak.org)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_configcheckbox_with_advanced extends admin_setting_configcheckbox {

    /**
     * Constructor
     * @param string $name unique ascii name, either 'mysetting' for settings that in config, or 'myplugin/mysetting' for ones in config_plugins.
     * @param string $visiblename localised
     * @param string $description long localised info
     * @param array $defaultsetting ('value'=>string, 'adv'=>bool)
     * @param string $yes value used when checked
     * @param string $no value used when not checked
     */
    public function __construct($name, $visiblename, $description, $defaultsetting, $yes='1', $no='0') {
        parent::__construct($name, $visiblename, $description, $defaultsetting, $yes, $no);
    }

    /**
     * Loads the current setting and returns array
     *
     * @return array Returns array value=>xx, adv=>xx
     */
    public function get_setting() {
        $value = parent::get_setting();
        $adv = $this->config_read($this->name.'_adv');
        if (is_null($value) or is_null($adv)) {
            return NULL;
        }
        return array('value' => $value, 'adv' => $adv);
    }

    /**
     * Sets the value for the setting
     *
     * Sets the value for the setting to either the yes or no values
     * of the object by comparing $data to yes
     *
     * @param mixed $data Gets converted to str for comparison against yes value
     * @return string empty string or error
     */
    public function write_setting($data) {
        $error = parent::write_setting($data['value']);
        if (!$error) {
            $value = empty($data['adv']) ? 0 : 1;
            $this->config_write($this->name.'_adv', $value);
        }
        return $error;
    }

    /**
     * Returns an XHTML checkbox field and with extra advanced cehckbox
     *
     * @param string $data If $data matches yes then checkbox is checked
     * @param string $query
     * @return string XHTML field
     */
    public function output_html($data, $query='') {
        $defaults = $this->get_defaultsetting();
        $defaultinfo = array();
        if (!is_null($defaults)) {
            if ((string)$defaults['value'] === $this->yes) {
                $defaultinfo[] = get_string('checkboxyes', 'admin');
            } else {
                $defaultinfo[] = get_string('checkboxno', 'admin');
            }
            if (!empty($defaults['adv'])) {
                $defaultinfo[] = get_string('advanced');
            }
        }
        $defaultinfo = implode(', ', $defaultinfo);

        if ((string)$data['value'] === $this->yes) { // convert to strings before comparison
            $checked = 'checked="checked"';
        } else {
            $checked = '';
        }
        if (!empty($data['adv'])) {
            $advanced = 'checked="checked"';
        } else {
            $advanced = '';
        }

        $fullname    = $this->get_full_name();
        $novalue     = s($this->no);
        $yesvalue    = s($this->yes);
        $id          = $this->get_id();
        $stradvanced = get_string('advanced');
        $return = <<<EOT
<div class="form-checkbox defaultsnext" >
<input type="hidden" name="{$fullname}[value]" value="$novalue" />
<input type="checkbox" id="$id" name="{$fullname}[value]" value="$yesvalue" $checked />
<input type="checkbox" class="form-checkbox" id="{$id}_adv" name="{$fullname}[adv]" value="1" $advanced />
<label for="{$id}_adv">$stradvanced</label>
</div>
EOT;
        return format_admin_setting($this, $this->visiblename, $return, $this->description,
                                    true, '', $defaultinfo, $query);
    }
}

/**
 * Dropdown menu with an advanced checkbox, that controls a additional $name.'_adv' setting.
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_configselect_with_advanced extends admin_setting_configselect {
    /**
     * Calls parent::__construct with specific arguments
     */
    public function __construct($name, $visiblename, $description, $defaultsetting, $choices) {
        parent::__construct($name, $visiblename, $description, $defaultsetting, $choices);
    }

    /**
     * Loads the current setting and returns array
     *
     * @return array Returns array value=>xx, adv=>xx
     */
    public function get_setting() {
        $value = parent::get_setting();
        $adv = $this->config_read($this->name.'_adv');
        if (is_null($value) or is_null($adv)) {
            return NULL;
        }
        return array('value' => $value, 'adv' => $adv);
    }

    /**
     * Saves the new settings passed in $data
     *
     * @todo Add vartype handling to ensure $data is an array
     * @param array $data
     * @return mixed string or Array
     */
    public function write_setting($data) {
        $error = parent::write_setting($data['value']);
        if (!$error) {
            $value = empty($data['adv']) ? 0 : 1;
            $this->config_write($this->name.'_adv', $value);
        }
        return $error;
    }

    /**
     * Return XHTML for the control
     *
     * @param array $data Default data array
     * @param string $query
     * @return string XHTML to display control
     */
    public function output_html($data, $query='') {
        $default = $this->get_defaultsetting();
        $current = $this->get_setting();

        list($selecthtml, $warning) = $this->output_select_html($data['value'],
                $current['value'], $default['value'], '[value]');
        if (!$selecthtml) {
            return '';
        }

        if (!is_null($default) and array_key_exists($default['value'], $this->choices)) {
            $defaultinfo = array();
            if (isset($this->choices[$default['value']])) {
                $defaultinfo[] = $this->choices[$default['value']];
            }
            if (!empty($default['adv'])) {
                $defaultinfo[] = get_string('advanced');
            }
            $defaultinfo = implode(', ', $defaultinfo);
        } else {
            $defaultinfo = '';
        }

        $adv = !empty($data['adv']);
        $return = '<div class="form-select defaultsnext">' . $selecthtml .
                ' <input type="checkbox" class="form-checkbox" id="' .
                $this->get_id() . '_adv" name="' . $this->get_full_name() .
                '[adv]" value="1" ' . ($adv ? 'checked="checked"' : '') . ' />' .
                ' <label for="' . $this->get_id() . '_adv">' .
                get_string('advanced') . '</label></div>';

        return format_admin_setting($this, $this->visiblename, $return, $this->description, true, $warning, $defaultinfo, $query);
    }
}

/**
 * Graded roles in gradebook
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_special_gradebookroles extends admin_setting_pickroles {
    /**
     * Calls parent::__construct with specific arguments
     */
    public function __construct() {
        parent::__construct('gradebookroles', get_string('gradebookroles', 'admin'),
                                              get_string('configgradebookroles', 'admin'),
                                              array('moodle/legacy:student'));
    }
}

/**
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_regradingcheckbox extends admin_setting_configcheckbox {
    /**
     * Saves the new settings passed in $data
     *
     * @param string $data
     * @return mixed string or Array
     */
    public function write_setting($data) {
        global $CFG, $DB;

        $oldvalue  = $this->config_read($this->name);
        $return    = parent::write_setting($data);
        $newvalue  = $this->config_read($this->name);

        if ($oldvalue !== $newvalue) {
            // force full regrading
            $DB->set_field('grade_items', 'needsupdate', 1, array('needsupdate'=>0));
        }

        return $return;
    }
}

/**
 * Which roles to show on course decription page
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_special_coursemanager extends admin_setting_pickroles {
    /**
     * Calls parent::__construct with specific arguments
     */
    public function __construct() {
        parent::__construct('coursemanager', get_string('coursemanager', 'admin'),
                                             get_string('configcoursemanager', 'admin'),
                                             array('moodle/legacy:editingteacher'));
    }
}

/**
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_special_gradelimiting extends admin_setting_configcheckbox {
    /**
     * Calls parent::__construct with specific arguments
     */
    function admin_setting_special_gradelimiting() {
        parent::__construct('unlimitedgrades', get_string('unlimitedgrades', 'grades'),
                                                  get_string('configunlimitedgrades', 'grades'), '0', '1', '0');
    }

    /**
     * Force site regrading
     */
    function regrade_all() {
        global $CFG;
        require_once("$CFG->libdir/gradelib.php");
        grade_force_site_regrading();
    }

    /**
     * Saves the new settings
     *
     * @param mixed $data
     * @return string empty string or error message
     */
    function write_setting($data) {
        $previous = $this->get_setting();

        if ($previous === null) {
            if ($data) {
                $this->regrade_all();
            }
        } else {
            if ($data != $previous) {
                $this->regrade_all();
            }
        }
        return ($this->config_write($this->name, $data) ? '' : get_string('errorsetting', 'admin'));
    }

}

/**
 * Primary grade export plugin - has state tracking.
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_special_gradeexport extends admin_setting_configmulticheckbox {
    /**
     * Calls parent::__construct with specific arguments
     */
    public function __construct() {
        parent::__construct('gradeexport', get_string('gradeexport', 'admin'),
                            get_string('configgradeexport', 'admin'), array(), NULL);
    }

    /**
     * Load the available choices for the multicheckbox
     *
     * @return bool always returns true
     */
    public function load_choices() {
        if (is_array($this->choices)) {
            return true;
        }
        $this->choices = array();

        if ($plugins = get_plugin_list('gradeexport')) {
            foreach($plugins as $plugin => $unused) {
                $this->choices[$plugin] = get_string('modulename', 'gradeexport_'.$plugin);
            }
        }
        return true;
    }
}

/**
 * Grade category settings
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_gradecat_combo extends admin_setting {
    /** @var array Array of choices */
    public $choices;

    /**
     * Sets choices and calls parent::__construct with passed arguments
     * @param string $name
     * @param string $visiblename
     * @param string $description
     * @param mixed $defaultsetting string or array depending on implementation
     * @param array $choices An array of choices for the control
     */
    public function __construct($name, $visiblename, $description, $defaultsetting, $choices) {
        $this->choices = $choices;
        parent::__construct($name, $visiblename, $description, $defaultsetting);
    }

    /**
     * Return the current setting(s) array
     *
     * @return array Array of value=>xx, forced=>xx, adv=>xx
     */
    public function get_setting() {
        global $CFG;

        $value = $this->config_read($this->name);
        $flag  = $this->config_read($this->name.'_flag');

        if (is_null($value) or is_null($flag)) {
            return NULL;
        }

        $flag   = (int)$flag;
        $forced = (boolean)(1 & $flag); // first bit
        $adv    = (boolean)(2 & $flag); // second bit

        return array('value' => $value, 'forced' => $forced, 'adv' => $adv);
    }

    /**
     * Save the new settings passed in $data
     *
     * @todo Add vartype handling to ensure $data is array
     * @param array $data Associative array of value=>xx, forced=>xx, adv=>xx
     * @return string empty or error message
     */
    public function write_setting($data) {
        global $CFG;

        $value  = $data['value'];
        $forced = empty($data['forced']) ? 0 : 1;
        $adv    = empty($data['adv'])    ? 0 : 2;
        $flag   = ($forced | $adv); //bitwise or

        if (!in_array($value, array_keys($this->choices))) {
            return 'Error setting ';
        }

        $oldvalue  = $this->config_read($this->name);
        $oldflag   = (int)$this->config_read($this->name.'_flag');
        $oldforced = (1 & $oldflag); // first bit

        $result1 = $this->config_write($this->name, $value);
        $result2 = $this->config_write($this->name.'_flag', $flag);

        // force regrade if needed
        if ($oldforced != $forced or ($forced and $value != $oldvalue)) {
           require_once($CFG->libdir.'/gradelib.php');
           grade_category::updated_forced_settings();
        }

        if ($result1 and $result2) {
            return '';
        } else {
            return get_string('errorsetting', 'admin');
        }
    }

    /**
     * Return XHTML to display the field and wrapping div
     *
     * @todo Add vartype handling to ensure $data is array
     * @param array $data Associative array of value=>xx, forced=>xx, adv=>xx
     * @param string $query
     * @return string XHTML to display control
     */
    public function output_html($data, $query='') {
        $value  = $data['value'];
        $forced = !empty($data['forced']);
        $adv    = !empty($data['adv']);

        $default = $this->get_defaultsetting();
        if (!is_null($default)) {
            $defaultinfo = array();
            if (isset($this->choices[$default['value']])) {
                $defaultinfo[] = $this->choices[$default['value']];
            }
            if (!empty($default['forced'])) {
                $defaultinfo[] = get_string('force');
            }
            if (!empty($default['adv'])) {
                $defaultinfo[] = get_string('advanced');
            }
            $defaultinfo = implode(', ', $defaultinfo);

        } else {
            $defaultinfo = NULL;
        }


        $return = '<div class="form-group">';
        $return .= '<select class="form-select" id="'.$this->get_id().'" name="'.$this->get_full_name().'[value]">';
        foreach ($this->choices as $key => $val) {
            // the string cast is needed because key may be integer - 0 is equal to most strings!
            $return .= '<option value="'.$key.'"'.((string)$key==$value ? ' selected="selected"' : '').'>'.$val.'</option>';
        }
        $return .= '</select>';
        $return .= '<input type="checkbox" class="form-checkbox" id="'.$this->get_id().'force" name="'.$this->get_full_name().'[forced]" value="1" '.($forced ? 'checked="checked"' : '').' />'
                  .'<label for="'.$this->get_id().'force">'.get_string('force').'</label>';
        $return .= '<input type="checkbox" class="form-checkbox" id="'.$this->get_id().'adv" name="'.$this->get_full_name().'[adv]" value="1" '.($adv ? 'checked="checked"' : '').' />'
                  .'<label for="'.$this->get_id().'adv">'.get_string('advanced').'</label>';
        $return .= '</div>';

        return format_admin_setting($this, $this->visiblename, $return, $this->description, true, '', $defaultinfo, $query);
    }
}


/**
 * Selection of grade report in user profiles
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_grade_profilereport extends admin_setting_configselect {
    /**
     * Calls parent::__construct with specific arguments
     */
    public function __construct() {
        parent::__construct('grade_profilereport', get_string('profilereport', 'grades'), get_string('configprofilereport', 'grades'), 'user', null);
    }

    /**
     * Loads an array of choices for the configselect control
     *
     * @return bool always return true
     */
    public function load_choices() {
        if (is_array($this->choices)) {
            return true;
        }
        $this->choices = array();

        global $CFG;
        require_once($CFG->libdir.'/gradelib.php');

        foreach (get_plugin_list('gradereport') as $plugin => $plugindir) {
            if (file_exists($plugindir.'/lib.php')) {
                require_once($plugindir.'/lib.php');
                $functionname = 'grade_report_'.$plugin.'_profilereport';
                if (function_exists($functionname)) {
                    $this->choices[$plugin] = get_string('modulename', 'gradereport_'.$plugin);
                }
            }
        }
        return true;
    }
}

/**
 * Special class for register auth selection
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_special_registerauth extends admin_setting_configselect {
    /**
     * Calls parent::__construct with specific arguments
     */
    public function __construct() {
        parent::__construct('registerauth', get_string('selfregistration', 'auth'), get_string('selfregistration_help', 'auth'), '', null);
    }

    /**
     * Returns the default option
     *
     * @return string emtpy or default option
     */
    public function get_defaultsetting() {
        $this->load_choices();
        $defaultsetting = parent::get_defaultsetting();
        if (array_key_exists($defaultsetting, $this->choices)) {
            return $defaultsetting;
        } else {
            return '';
        }
    }

    /**
     * Loads the possible choices for the array
     *
     * @return bool always returns true
     */
    public function load_choices() {
        global $CFG;

        if (is_array($this->choices)) {
            return true;
        }
        $this->choices = array();
        $this->choices[''] = get_string('disable');

        $authsenabled = get_enabled_auth_plugins(true);

        foreach ($authsenabled as $auth) {
            $authplugin = get_auth_plugin($auth);
            if (!$authplugin->can_signup()) {
                continue;
            }
            // Get the auth title (from core or own auth lang files)
            $authtitle = $authplugin->get_title();
            $this->choices[$auth] = $authtitle;
        }
        return true;
    }
}

/**
 * Module manage page
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_page_managemods extends admin_externalpage {
    /**
     * Calls parent::__construct with specific arguments
     */
    public function __construct() {
        global $CFG;
        parent::__construct('managemodules', get_string('modsettings', 'admin'), "$CFG->wwwroot/$CFG->admin/modules.php");
    }

    /**
     * Try to find the specified module
     *
     * @param string $query The module to search for
     * @return array
     */
    public function search($query) {
        global $DB;
        if ($result = parent::search($query)) {
            return $result;
        }

        $found = false;
        if ($modules = $DB->get_records('modules')) {
            $textlib = textlib_get_instance();
            foreach ($modules as $module) {
                if (strpos($module->name, $query) !== false) {
                    $found = true;
                    break;
                }
                $strmodulename = get_string('modulename', $module->name);
                if (strpos($textlib->strtolower($strmodulename), $query) !== false) {
                    $found = true;
                    break;
                }
            }
        }
        if ($found) {
            $result = new object();
            $result->page     = $this;
            $result->settings = array();
            return array($this->name => $result);
        } else {
            return array();
        }
    }
}

/**
 * Enrolment manage page
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_enrolment_page extends admin_externalpage {
    /**
     * Calls parent::__construct with specific arguments
     */
    public function __construct() {
        global $CFG;
        parent::__construct('enrolment', get_string('enrolments'), $CFG->wwwroot . '/'.$CFG->admin.'/enrol.php');
    }

    /**
     * @param string The string to search for
     * @return array
     */
    public function search($query) {
        if ($result = parent::search($query)) {
            return $result;
        }

        $found = false;

        if ($modules = get_plugin_list('enrol')) {
            $textlib = textlib_get_instance();
            foreach ($modules as $plugin => $dir) {
                if (strpos($plugin, $query) !== false) {
                    $found = true;
                    break;
                }
                $strmodulename = get_string('enrolname', "enrol_$plugin");
                if (strpos($textlib->strtolower($strmodulename), $query) !== false) {
                    $found = true;
                    break;
                }
            }
        }
        //ugly harcoded hacks
        if (strpos('sendcoursewelcomemessage', $query) !== false) {
             $found = true;
        } else if (strpos($textlib->strtolower(get_string('sendcoursewelcomemessage', 'admin')), $query) !== false) {
             $found = true;
        } else if (strpos($textlib->strtolower(get_string('configsendcoursewelcomemessage', 'admin')), $query) !== false) {
             $found = true;
        } else if (strpos($textlib->strtolower(get_string('configenrolmentplugins', 'admin')), $query) !== false) {
             $found = true;
        }
        if ($found) {
            $result = new object();
            $result->page     = $this;
            $result->settings = array();
            return array($this->name => $result);
        } else {
            return array();
        }
    }
}

/**
 * Blocks manage page
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_page_manageblocks extends admin_externalpage {
    /**
     * Calls parent::__construct with specific arguments
     */
    public function __construct() {
        global $CFG;
        parent::__construct('manageblocks', get_string('blocksettings', 'admin'), "$CFG->wwwroot/$CFG->admin/blocks.php");
    }

    /**
     * Search for a specific block
     *
     * @param string $query The string to search for
     * @return array
     */
    public function search($query) {
        global $CFG, $DB;
        if ($result = parent::search($query)) {
            return $result;
        }

        $found = false;
        if ($blocks = $DB->get_records('block')) {
            $textlib = textlib_get_instance();
            foreach ($blocks as $block) {
                if (strpos($block->name, $query) !== false) {
                    $found = true;
                    break;
                }
                $strblockname = get_string('blockname', 'block_'.$block->name);
                if (strpos($textlib->strtolower($strblockname), $query) !== false) {
                    $found = true;
                    break;
                }
            }
        }
        if ($found) {
            $result = new object();
            $result->page     = $this;
            $result->settings = array();
            return array($this->name => $result);
        } else {
            return array();
        }
    }
}

/**
 * Question type manage page
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_page_manageqtypes extends admin_externalpage {
    /**
     * Calls parent::__construct with specific arguments
     */
    public function __construct() {
        global $CFG;
        parent::__construct('manageqtypes', get_string('manageqtypes', 'admin'), "$CFG->wwwroot/$CFG->admin/qtypes.php");
    }

    /**
     * Search QTYPES for the specified string
     *
     * @param string $query The string to search for in QTYPES
     * @return array
     */
    public function search($query) {
        global $CFG;
        if ($result = parent::search($query)) {
            return $result;
        }

        $found = false;
        $textlib = textlib_get_instance();
        require_once($CFG->libdir . '/questionlib.php');
        global $QTYPES;
        foreach ($QTYPES as $qtype) {
            if (strpos($textlib->strtolower($qtype->local_name()), $query) !== false) {
                $found = true;
                break;
            }
        }
        if ($found) {
            $result = new object();
            $result->page     = $this;
            $result->settings = array();
            return array($this->name => $result);
        } else {
            return array();
        }
    }
}

/**
 * Special class for authentication administration.
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_manageauths extends admin_setting {
    /**
     * Calls parent::__construct with specific arguments
     */
    public function __construct() {
        parent::__construct('authsui', get_string('authsettings', 'admin'), '', '');
    }

    /**
     * Always returns true
     *
     * @return true
     */
    public function get_setting() {
        return true;
    }

    /**
     * Always returns true
     *
     * @return true
     */
    public function get_defaultsetting() {
        return true;
    }

    /**
     * Always returns '' and doesn't write anything
     *
     * @return string Always returns ''
     */
    public function write_setting($data) {
        // do not write any setting
        return '';
    }

    /**
     * Search to find if Query is related to auth plugin
     *
     * @param string $query The string to search for
     * @return bool true for related false for not
     */
    public function is_related($query) {
        if (parent::is_related($query)) {
            return true;
        }

        $textlib = textlib_get_instance();
        $authsavailable = get_plugin_list('auth');
        foreach ($authsavailable as $auth => $dir) {
            if (strpos($auth, $query) !== false) {
                return true;
            }
            $authplugin = get_auth_plugin($auth);
            $authtitle = $authplugin->get_title();
            if (strpos($textlib->strtolower($authtitle), $query) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return XHTML to display control
     *
     * @param mixed $data Unused
     * @param string $query
     * @return string highlight
     */
    public function output_html($data, $query='') {
        global $CFG, $OUTPUT;


        // display strings
        $txt = get_strings(array('authenticationplugins', 'users', 'administration',
                                 'settings', 'edit', 'name', 'enable', 'disable',
                                 'up', 'down', 'none'));
        $txt->updown = "$txt->up/$txt->down";

        $authsavailable = get_plugin_list('auth');
        get_enabled_auth_plugins(true); // fix the list of enabled auths
        if (empty($CFG->auth)) {
            $authsenabled = array();
        } else {
            $authsenabled = explode(',', $CFG->auth);
        }

        // construct the display array, with enabled auth plugins at the top, in order
        $displayauths = array();
        $registrationauths = array();
        $registrationauths[''] = $txt->disable;
        foreach ($authsenabled as $auth) {
            $authplugin = get_auth_plugin($auth);
        /// Get the auth title (from core or own auth lang files)
            $authtitle = $authplugin->get_title();
        /// Apply titles
            $displayauths[$auth] = $authtitle;
            if ($authplugin->can_signup()) {
                $registrationauths[$auth] = $authtitle;
            }
        }

        foreach ($authsavailable as $auth => $dir) {
            if (array_key_exists($auth, $displayauths)) {
                continue; //already in the list
            }
            $authplugin = get_auth_plugin($auth);
        /// Get the auth title (from core or own auth lang files)
            $authtitle = $authplugin->get_title();
        /// Apply titles
            $displayauths[$auth] = $authtitle;
            if ($authplugin->can_signup()) {
                $registrationauths[$auth] = $authtitle;
            }
        }

        $return = $OUTPUT->heading(get_string('actauthhdr', 'auth'), 3, 'main');
        $return .= $OUTPUT->box_start('generalbox authsui');

        $table = new html_table();
        $table->head  = array($txt->name, $txt->enable, $txt->updown, $txt->settings);
        $table->align = array('left', 'center', 'center', 'center');
        $table->width = '90%';
        $table->data  = array();

        //add always enabled plugins first
        $displayname = "<span>".$displayauths['manual']."</span>";
        $settings = "<a href=\"auth_config.php?auth=manual\">{$txt->settings}</a>";
        //$settings = "<a href=\"settings.php?section=authsettingmanual\">{$txt->settings}</a>";
        $table->data[] = array($displayname, '', '', $settings);
        $displayname = "<span>".$displayauths['nologin']."</span>";
        $settings = "<a href=\"auth_config.php?auth=nologin\">{$txt->settings}</a>";
        $table->data[] = array($displayname, '', '', $settings);


        // iterate through auth plugins and add to the display table
        $updowncount = 1;
        $authcount = count($authsenabled);
        $url = "auth.php?sesskey=" . sesskey();
        foreach ($displayauths as $auth => $name) {
            if ($auth == 'manual' or $auth == 'nologin') {
                continue;
            }
            // hide/show link
            if (in_array($auth, $authsenabled)) {
                $hideshow = "<a href=\"$url&amp;action=disable&amp;auth=$auth\">";
                $hideshow .= "<img src=\"" . $OUTPUT->old_icon_url('i/hide') . "\" class=\"icon\" alt=\"disable\" /></a>";
                // $hideshow = "<a href=\"$url&amp;action=disable&amp;auth=$auth\"><input type=\"checkbox\" checked /></a>";
                $enabled = true;
                $displayname = "<span>$name</span>";
            }
            else {
                $hideshow = "<a href=\"$url&amp;action=enable&amp;auth=$auth\">";
                $hideshow .= "<img src=\"" . $OUTPUT->old_icon_url('i/show') . "\" class=\"icon\" alt=\"enable\" /></a>";
                // $hideshow = "<a href=\"$url&amp;action=enable&amp;auth=$auth\"><input type=\"checkbox\" /></a>";
                $enabled = false;
                $displayname = "<span class=\"dimmed_text\">$name</span>";
            }

            // up/down link (only if auth is enabled)
            $updown = '';
            if ($enabled) {
                if ($updowncount > 1) {
                    $updown .= "<a href=\"$url&amp;action=up&amp;auth=$auth\">";
                    $updown .= "<img src=\"" . $OUTPUT->old_icon_url('t/up') . "\" alt=\"up\" /></a>&nbsp;";
                }
                else {
                    $updown .= "<img src=\"" . $OUTPUT->old_icon_url('spacer.gif') . "\" class=\"icon\" alt=\"\" />&nbsp;";
                }
                if ($updowncount < $authcount) {
                    $updown .= "<a href=\"$url&amp;action=down&amp;auth=$auth\">";
                    $updown .= "<img src=\"" . $OUTPUT->old_icon_url('t/down') . "\" alt=\"down\" /></a>";
                }
                else {
                    $updown .= "<img src=\"" . $OUTPUT->old_icon_url('spacer.gif') . "\" class=\"icon\" alt=\"\" />";
                }
                ++ $updowncount;
            }

            // settings link
            if (file_exists($CFG->dirroot.'/auth/'.$auth.'/settings.php')) {
                $settings = "<a href=\"settings.php?section=authsetting$auth\">{$txt->settings}</a>";
            } else {
                $settings = "<a href=\"auth_config.php?auth=$auth\">{$txt->settings}</a>";
            }

            // add a row to the table
            $table->data[] =array($displayname, $hideshow, $updown, $settings);
        }
        $return .= $OUTPUT->table($table);
        $return .= get_string('configauthenticationplugins', 'admin').'<br />'.get_string('tablenosave', 'filters');
        $return .= $OUTPUT->box_end();
        return highlight($query, $return);
    }
}

/**
 * Special class for authentication administration.
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_manageeditors extends admin_setting {
    /**
     * Calls parent::__construct with specific arguments
     */
    public function __construct() {
        parent::__construct('editorsui', get_string('editorsettings', 'editor'), '', '');
    }

    /**
     * Always returns true, does nothing
     *
     * @return true
     */
    public function get_setting() {
        return true;
    }

    /**
     * Always returns true, does nothing
     *
     * @return true
     */
    public function get_defaultsetting() {
        return true;
    }

    /**
     * Always returns '', does not write anything
     *
     * @return string Always returns ''
     */
    public function write_setting($data) {
        // do not write any setting
        return '';
    }

    /**
     * Checks if $query is one of the available editors
     *
     * @param string $query The string to search for
     * @return bool Returns true if found, false if not
     */
    public function is_related($query) {
        if (parent::is_related($query)) {
            return true;
        }

        $textlib = textlib_get_instance();
        $editors_available = get_available_editors();
        foreach ($editors_available as $editor=>$editorstr) {
            if (strpos($editor, $query) !== false) {
                return true;
            }
            if (strpos($textlib->strtolower($editorstr), $query) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Builds the XHTML to display the control
     *
     * @param string $data Unused
     * @param string $query
     * @return string
     */
    public function output_html($data, $query='') {
        global $CFG, $OUTPUT;

        // display strings
        $txt = get_strings(array('administration', 'settings', 'edit', 'name', 'enable', 'disable',
                                 'up', 'down', 'none'));
        $txt->updown = "$txt->up/$txt->down";

        $editors_available = get_available_editors();
        $active_editors = explode(',', $CFG->texteditors);

        $active_editors = array_reverse($active_editors);
        foreach ($active_editors as $key=>$editor) {
            if (empty($editors_available[$editor])) {
                unset($active_editors[$key]);
            } else {
                $name = $editors_available[$editor];
                unset($editors_available[$editor]);
                $editors_available[$editor] = $name;
            }
        }
        if (empty($active_editors)) {
            //$active_editors = array('textarea');
        }
        $editors_available = array_reverse($editors_available, true);
        $return = $OUTPUT->heading(get_string('acteditorshhdr', 'editor'), 3, 'main', true);
        $return .= $OUTPUT->box_start('generalbox editorsui');

        $table = new html_table();
        $table->head  = array($txt->name, $txt->enable, $txt->updown, $txt->settings);
        $table->align = array('left', 'center', 'center', 'center');
        $table->width = '90%';
        $table->data  = array();

        // iterate through auth plugins and add to the display table
        $updowncount = 1;
        $editorcount = count($active_editors);
        $url = "editors.php?sesskey=" . sesskey();
        foreach ($editors_available as $editor => $name) {
            // hide/show link
            if (in_array($editor, $active_editors)) {
                $hideshow = "<a href=\"$url&amp;action=disable&amp;editor=$editor\">";
                $hideshow .= "<img src=\"" . $OUTPUT->old_icon_url('i/hide') . "\" class=\"icon\" alt=\"disable\" /></a>";
                // $hideshow = "<a href=\"$url&amp;action=disable&amp;editor=$editor\"><input type=\"checkbox\" checked /></a>";
                $enabled = true;
                $displayname = "<span>$name</span>";
            }
            else {
                $hideshow = "<a href=\"$url&amp;action=enable&amp;editor=$editor\">";
                $hideshow .= "<img src=\"" . $OUTPUT->old_icon_url('i/show') . "\" class=\"icon\" alt=\"enable\" /></a>";
                // $hideshow = "<a href=\"$url&amp;action=enable&amp;editor=$editor\"><input type=\"checkbox\" /></a>";
                $enabled = false;
                $displayname = "<span class=\"dimmed_text\">$name</span>";
            }

            // up/down link (only if auth is enabled)
            $updown = '';
            if ($enabled) {
                if ($updowncount > 1) {
                    $updown .= "<a href=\"$url&amp;action=up&amp;editor=$editor\">";
                    $updown .= "<img src=\"" . $OUTPUT->old_icon_url('t/up') . "\" alt=\"up\" /></a>&nbsp;";
                }
                else {
                    $updown .= "<img src=\"" . $OUTPUT->old_icon_url('spacer.gif') . "\" class=\"icon\" alt=\"\" />&nbsp;";
                }
                if ($updowncount < $editorcount) {
                    $updown .= "<a href=\"$url&amp;action=down&amp;editor=$editor\">";
                    $updown .= "<img src=\"" . $OUTPUT->old_icon_url('t/down') . "\" alt=\"down\" /></a>";
                }
                else {
                    $updown .= "<img src=\"" . $OUTPUT->old_icon_url('spacer.gif') . "\" class=\"icon\" alt=\"\" />";
                }
                ++ $updowncount;
            }

            // settings link
            if (file_exists($CFG->dirroot.'/editor/'.$editor.'/settings.php')) {
                $settings = "<a href=\"settings.php?section=editorsetting$editor\">{$txt->settings}</a>";
            } else {
                $settings = '';
            }

            // add a row to the table
            $table->data[] =array($displayname, $hideshow, $updown, $settings);
        }
        $return .= $OUTPUT->table($table);
        $return .= get_string('configeditorplugins', 'editor').'<br />'.get_string('tablenosave', 'filters');
        $return .= $OUTPUT->box_end();
        return highlight($query, $return);
    }
}

/**
 * Special class for filter administration.
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_page_managefilters extends admin_externalpage {
    /**
     * Calls parent::__construct with specific arguments
     */
    public function __construct() {
        global $CFG;
        parent::__construct('managefilters', get_string('filtersettings', 'admin'), "$CFG->wwwroot/$CFG->admin/filters.php");
    }

    /**
     * Searches all installed filters for specified filter
     *
     * @param string $query The filter(string) to search for
     * @param string $query
     */
    public function search($query) {
        global $CFG;
        if ($result = parent::search($query)) {
            return $result;
        }

        $found = false;
        $filternames = filter_get_all_installed();
        $textlib = textlib_get_instance();
        foreach ($filternames as $path => $strfiltername) {
            if (strpos($textlib->strtolower($strfiltername), $query) !== false) {
                $found = true;
                break;
            }
            list($type, $filter) = explode('/', $path);
            if (strpos($filter, $query) !== false) {
                $found = true;
                break;
            }
        }

        if ($found) {
            $result = new stdClass;
            $result->page = $this;
            $result->settings = array();
            return array($this->name => $result);
        } else {
            return array();
        }
    }
}

/**
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_manageportfolio extends admin_setting {
    private $baseurl;

    public function __construct() {
        global $CFG;
        parent::__construct('manageportfolio', get_string('manageportfolio', 'portfolio'), '', '');
                            $this->baseurl = $CFG->wwwroot . '/' . $CFG->admin . '/portfolio.php?sesskey=' . sesskey();
    }

    /**
     * Always returns true, does nothing
     *
     * @return true
     */
    public function get_setting() {
        return true;
    }

    /**
     * Always returns true, does nothing
     *
     * @return true
     */
    public function get_defaultsetting() {
        return true;
    }

    /**
     * Always returns '', does not write anything
     *
     * @return string Always returns ''
     */
    public function write_setting($data) {
        return '';
    }

    /**
     * Searches the portfolio types for the specified type(string)
     *
     * @param string $query The string to search for
     * @return bool true for found or related, false for not
     */
    public function is_related($query) {
        if (parent::is_related($query)) {
            return true;
        }

        $textlib = textlib_get_instance();
        $portfolios = get_plugin_list('portfolio');
        foreach ($portfolios as $p => $dir) {
            if (strpos($p, $query) !== false) {
                return true;
            }
        }
        foreach (portfolio_instances(false, false) as $instance) {
            $title = $instance->get('name');
            if (strpos($textlib->strtolower($title), $query) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Builds XHTML to display the control
     *
     * @param string $data Unused
     * @param string $query
     * @return string XHTML to display the control
     */
    public function output_html($data, $query='') {
        global $CFG, $OUTPUT;

        $output = $OUTPUT->box_start('generalbox');

        $namestr = get_string('name');
        $pluginstr = get_string('plugin', 'portfolio');

        $plugins = get_plugin_list('portfolio');
        $plugins = array_keys($plugins);
        $instances = portfolio_instances(false, false);
        $alreadyplugins = array();

        // to avoid notifications being sent out while admin is editing the page
        define('ADMIN_EDITING_PORTFOLIO', true);

        $insane = portfolio_plugin_sanity_check($plugins);
        $insaneinstances = portfolio_instance_sanity_check($instances);

        $output .= portfolio_report_insane($insane, null, true);
        $output .= portfolio_report_insane($insaneinstances, $instances, true);

        $table = new html_table();
        $table->head = array($namestr, $pluginstr, '');
        $table->data = array();

        foreach ($instances as $i) {
            $row = '';
            $row .= '<a href="' . $this->baseurl . '&edit=' . $i->get('id') . '"><img src="' . $OUTPUT->old_icon_url('t/edit') . '" alt="' . get_string('edit') . '" /></a>' . "\n";
            $row .= '<a href="' . $this->baseurl . '&delete=' .  $i->get('id') . '"><img src="' . $OUTPUT->old_icon_url('t/delete') . '" alt="' . get_string('delete') . '" /></a>' . "\n";
            if (array_key_exists($i->get('plugin'), $insane) || array_key_exists($i->get('id'), $insaneinstances)) {
                $row .=  '<img src="' . $OUTPUT->old_icon_url('t/show') . '" alt="' . get_string('hidden', 'portfolio') . '" />' . "\n";
            } else {
                $row .= ' <a href="' . $this->baseurl . '&hide=' . $i->get('id') . '"><img src="' .
                    $OUTPUT->old_icon_url('t/' . ($i->get('visible') ? 'hide' : 'show')) . '" alt="' . get_string($i->get('visible') ? 'hide' : 'show') . '" /></a>' . "\n";
            }
            $table->data[] = array($i->get('name'), $i->get_name() . ' (' . $i->get('plugin') . ')', $row);
            if (!in_array($i->get('plugin'), $alreadyplugins)) {
                $alreadyplugins[] = $i->get('plugin');
            }
        }

        $output .= $OUTPUT->table($table);

        $instancehtml = '<br /><br />' . get_string('addnewportfolio', 'portfolio') . ': <br /><br />';
        $addable = 0;
        foreach ($plugins as $p) {
            if (!portfolio_static_function($p, 'allows_multiple') && in_array($p, $alreadyplugins)) {
                continue;
            }
            if (array_key_exists($p, $insane)) {
                continue;
            }

            $instancehtml .= '<a href="' . $this->baseurl . '&amp;new=' . $p . '">' . portfolio_static_function($p, 'get_name') . ' (' . s($p) . ')' . '</a><br />' . "\n";
            $addable++;
        }

        if ($addable) {
            $output .= $instancehtml;
        }
        $output .= $OUTPUT->box_end();

        return highlight($query, $output);
    }

}

/**
 * Initialise admin page - this function does require login and permission
 * checks specified in page definition.
 *
 * This function must be called on each admin page before other code.
 *
 * @param string $section name of page
 * @param string $extrabutton extra HTML that is added after the blocks editing on/off button.
 * @param array $extraurlparams an array paramname => paramvalue, or parameters that need to be
 *      added to the turn blocks editing on/off form, so this page reloads correctly.
 * @param string $actualurl if the actual page being viewed is not the normal one for this
 *      page (e.g. admin/roles/allowassin.php, instead of admin/roles/manage.php, you can pass the alternate URL here.
 */
function admin_externalpage_setup($section, $extrabutton = '',
        $extraurlparams = array(), $actualurl = '') {
    global $CFG, $PAGE, $USER;

    if ($site = get_site()) {
        require_login();
    } else {
        redirect($CFG->wwwroot.'/'.$CFG->admin.'/index.php');
        die;
    }

    $adminroot = admin_get_root(false, false); // settings not required for external pages
    $extpage = $adminroot->locate($section);

    $PAGE->set_context(get_context_instance(CONTEXT_SYSTEM));
    // $PAGE->set_extra_button($extrabutton); TODO

    if (!$actualurl) {
        $actualurl = $extpage->url;
    }
    $PAGE->set_url(str_replace($CFG->wwwroot . '/', '', $actualurl),
            array_merge($extraurlparams, array('section' => $section)));
    if (strpos($PAGE->pagetype, 'admin-') !== 0) {
        $PAGE->set_pagetype('admin-' . $PAGE->pagetype);
    }

    if (empty($extpage) or !($extpage instanceof admin_externalpage)) {
        print_error('sectionerror', 'admin', "$CFG->wwwroot/$CFG->admin/");
        die;
    }

    // this eliminates our need to authenticate on the actual pages
    if (!($extpage->check_access())) {
        print_error('accessdenied', 'admin');
        die;
    }

    $adminediting = optional_param('adminedit', -1, PARAM_BOOL);
    if ($PAGE->user_allowed_editing() && $adminediting != -1) {
        $USER->editing = $adminediting;
    }
}

/**
 * Print header for admin page
 *
 * @param string $focus focus element
 */
function admin_externalpage_print_header($focus='') {
    global $CFG, $PAGE, $SITE, $THEME, $OUTPUT;

    if (!is_string($focus)) {
        $focus = ''; // BC compatibility, there used to be adminroot parameter
    }

    if (empty($SITE->fullname) || empty($SITE->shortname)) {
        // During initial install.
        $strinstallation = get_string('installation', 'install');
        $strsettings = get_string('settings');
        $navigation = build_navigation(array(array('name'=>$strsettings, 'link'=>null, 'type'=>'misc')));
        print_header($strinstallation, $strinstallation, $navigation, "", "", false, "&nbsp;", "&nbsp;");
        return;
    }

    // Normal case.
    $adminroot = admin_get_root(false, false); //settings not required - only pages

    // fetch the path parameter
    $section = $PAGE->url->param('section');
    $current = $adminroot->locate($section, true);
    $visiblepathtosection = array_reverse($current->visiblepath);

    if ($PAGE->user_allowed_editing()) {
        $options = $PAGE->url->params();
        if ($PAGE->user_is_editing()) {
            $caption = get_string('blockseditoff');
            $options['adminedit'] = 'off';
        } else {
            $caption = get_string('blocksediton');
            $options['adminedit'] = 'on';
        }
        $buttons = $OUTPUT->button(html_form::make_button($PAGE->url->out(false), $options, $caption, 'get'));
    }

    $navlinks = array();
    foreach ($visiblepathtosection as $element) {
        $navlinks[] = array('name' => $element, 'link' => null, 'type' => 'misc');
    }
    $navigation = build_navigation($navlinks);

    print_header("$SITE->shortname: " . implode(": ",$visiblepathtosection), $SITE->fullname, $navigation, $focus, '', true, $buttons, '');
}

/**
 * @deprecated since Moodle 1.9. Please use normal print_footer() instead
 */
function admin_externalpage_print_footer() {
// TODO Still 103 referernces in core code. Don't do debugging output yet.
    debugging('admin_externalpage_print_footer is deprecated. Please $OUTPUT->footer() instead.', DEBUG_DEVELOPER);
    global $OUTPUT;
    echo $OUTPUT->footer();
}

/**
 * Returns the reference to admin tree root
 *
 * @return object admin_roow object
 */
function admin_get_root($reload=false, $requirefulltree=true) {
    global $CFG, $DB, $OUTPUT;

    static $ADMIN = NULL;

    if (is_null($ADMIN)) {
        // create the admin tree!
        $ADMIN = new admin_root($requirefulltree);
    }

    if ($reload or ($requirefulltree and !$ADMIN->fulltree)) {
        $ADMIN->purge_children($requirefulltree);
    }

    if (!$ADMIN->loaded) {
        // we process this file first to create categories first and in correct order
        require($CFG->dirroot.'/'.$CFG->admin.'/settings/top.php');

        // now we process all other files in admin/settings to build the admin tree
        foreach (glob($CFG->dirroot.'/'.$CFG->admin.'/settings/*.php') as $file) {
            if ($file == $CFG->dirroot.'/'.$CFG->admin.'/settings/top.php') {
                continue;
            }
            if ($file == $CFG->dirroot.'/'.$CFG->admin.'/settings/plugins.php') {
                // plugins are loaded last - they may insert pages anywhere
                continue;
            }
            require($file);
        }
        require($CFG->dirroot.'/'.$CFG->admin.'/settings/plugins.php');

        $ADMIN->loaded = true;
    }

    return $ADMIN;
}

/// settings utility functions

/**
 * This function applies default settings.
 *
 * @param object $node, NULL means complete tree, null by default
 * @param bool $uncoditional if true overrides all values with defaults, null buy default
 */
function admin_apply_default_settings($node=NULL, $unconditional=true) {
    global $CFG;

    if (is_null($node)) {
        $node = admin_get_root(true, true);
    }

    if ($node instanceof admin_category) {
        $entries = array_keys($node->children);
        foreach ($entries as $entry) {
            admin_apply_default_settings($node->children[$entry], $unconditional);
        }

    } else if ($node instanceof admin_settingpage) {
        foreach ($node->settings as $setting) {
            if (!$unconditional and !is_null($setting->get_setting())) {
                //do not override existing defaults
                continue;
            }
            $defaultsetting = $setting->get_defaultsetting();
            if (is_null($defaultsetting)) {
                // no value yet - default maybe applied after admin user creation or in upgradesettings
                continue;
            }
            $setting->write_setting($defaultsetting);
        }
    }
}

/**
 * Store changed settings, this function updates the errors variable in $ADMIN
 *
 * @param object $formdata from form
 * @return int number of changed settings
 */
function admin_write_settings($formdata) {
    global $CFG, $SITE, $DB;

    $olddbsessions = !empty($CFG->dbsessions);
    $formdata = (array)$formdata;

    $data = array();
    foreach ($formdata as $fullname=>$value) {
        if (strpos($fullname, 's_') !== 0) {
            continue; // not a config value
        }
        $data[$fullname] = $value;
    }

    $adminroot = admin_get_root();
    $settings = admin_find_write_settings($adminroot, $data);

    $count = 0;
    foreach ($settings as $fullname=>$setting) {
        $original = serialize($setting->get_setting()); // comparison must work for arrays too
        $error = $setting->write_setting($data[$fullname]);
        if ($error !== '') {
            $adminroot->errors[$fullname] = new object();
            $adminroot->errors[$fullname]->data  = $data[$fullname];
            $adminroot->errors[$fullname]->id    = $setting->get_id();
            $adminroot->errors[$fullname]->error = $error;
        }
        if ($original !== serialize($setting->get_setting())) {
            $count++;
            $callbackfunction = $setting->updatedcallback;
            if (function_exists($callbackfunction)) {
                $callbackfunction($fullname);
            }
        }
    }

    if ($olddbsessions != !empty($CFG->dbsessions)) {
        require_logout();
    }

    // Now update $SITE - just update the fields, in case other people have a
    // a reference to it (e.g. $PAGE, $COURSE).
    $newsite = $DB->get_record('course', array('id'=>$SITE->id));
    foreach (get_object_vars($newsite) as $field => $value) {
        $SITE->$field = $value;
    }

    // now reload all settings - some of them might depend on the changed
    admin_get_root(true);
    return $count;
}

/**
 * Internal recursive function - finds all settings from submitted form
 *
 * @param object $node Instance of admin_category, or admin_settingpage
 * @param array $data
 * @return array
 */
function admin_find_write_settings($node, $data) {
    $return = array();

    if (empty($data)) {
        return $return;
    }

    if ($node instanceof admin_category) {
        $entries = array_keys($node->children);
        foreach ($entries as $entry) {
            $return = array_merge($return, admin_find_write_settings($node->children[$entry], $data));
        }

    } else if ($node instanceof admin_settingpage) {
        foreach ($node->settings as $setting) {
            $fullname = $setting->get_full_name();
            if (array_key_exists($fullname, $data)) {
                $return[$fullname] = $setting;
            }
        }

    }

    return $return;
}

/**
 * Internal function - prints the search results
 *
 * @param string $query String to search for
 * @return string empty or XHTML
 */
function admin_search_settings_html($query) {
    global $CFG, $OUTPUT;

    $textlib = textlib_get_instance();
    if ($textlib->strlen($query) < 2) {
        return '';
    }
    $query = $textlib->strtolower($query);

    $adminroot = admin_get_root();
    $findings = $adminroot->search($query);
    $return = '';
    $savebutton = false;

    foreach ($findings as $found) {
        $page     = $found->page;
        $settings = $found->settings;
        if ($page->is_hidden()) {
            // hidden pages are not displayed in search results
            continue;
        }
        if ($page instanceof admin_externalpage) {
            $return .= $OUTPUT->heading(get_string('searchresults','admin').' - <a href="'.$page->url.'">'.highlight($query, $page->visiblename).'</a>', 2, 'main');
        } else if ($page instanceof admin_settingpage) {
            $return .= $OUTPUT->heading(get_string('searchresults','admin').' - <a href="'.$CFG->wwwroot.'/'.$CFG->admin.'/settings.php?section='.$page->name.'">'.highlight($query, $page->visiblename).'</a>', 2, 'main');
        } else {
            continue;
        }
        if (!empty($settings)) {
            $savebutton = true;
            $return .= '<fieldset class="adminsettings">'."\n";
            foreach ($settings as $setting) {
                $return .= '<div class="clearer"><!-- --></div>'."\n";
                $fullname = $setting->get_full_name();
                if (array_key_exists($fullname, $adminroot->errors)) {
                    $data = $adminroot->errors[$fullname]->data;
                } else {
                    $data = $setting->get_setting();
                    // do not use defaults if settings not available - upgrdesettings handles the defaults!
                }
                $return .= $setting->output_html($data, $query);
            }
            $return .= '</fieldset>';
        }
    }

    if ($savebutton) {
         $return .= '<div class="form-buttons"><input class="form-submit" type="submit" value="'.get_string('savechanges','admin').'" /></div>';
    }

    return $return;
}

/**
 * Internal function - returns arrays of html pages with uninitialised settings
 *
 * @param object $node Instance of admin_category or admin_settingpage
 * @return array
 */
function admin_output_new_settings_by_page($node) {
    global $OUTPUT;
    $return = array();

    if ($node instanceof admin_category) {
        $entries = array_keys($node->children);
        foreach ($entries as $entry) {
            $return += admin_output_new_settings_by_page($node->children[$entry]);
        }

    } else if ($node instanceof admin_settingpage) {
        $newsettings = array();
        foreach ($node->settings as $setting) {
            if (is_null($setting->get_setting())) {
                $newsettings[] = $setting;
            }
        }
        if (count($newsettings) > 0) {
            $adminroot = admin_get_root();
            $page = $OUTPUT->heading(get_string('upgradesettings','admin').' - '.$node->visiblename, 2, 'main');
            $page .= '<fieldset class="adminsettings">'."\n";
            foreach ($newsettings as $setting) {
                $fullname = $setting->get_full_name();
                if (array_key_exists($fullname, $adminroot->errors)) {
                    $data = $adminroot->errors[$fullname]->data;
                } else {
                    $data = $setting->get_setting();
                    if (is_null($data)) {
                        $data = $setting->get_defaultsetting();
                    }
                }
                $page .= '<div class="clearer"><!-- --></div>'."\n";
                $page .= $setting->output_html($data);
            }
            $page .= '</fieldset>';
            $return[$node->name] = $page;
        }
    }

    return $return;
}

/**
 * Format admin settings
 *
 * @param object $setting
 * @param string $title label element
 * @param string $form form fragment, html code - not highlighed automaticaly
 * @param string $description
 * @param bool $label link label to id, true by default
 * @param string $warning warning text
 * @param sting $defaultinfo defaults info, null means nothing, '' is converted to "Empty" string, defaults to null
 * @param string $query search query to be highlighted
 * @return string XHTML
 */
function format_admin_setting($setting, $title='', $form='', $description='', $label=true, $warning='', $defaultinfo=NULL, $query='') {
    global $CFG;

    $name     = empty($setting->plugin) ? $setting->name : "$setting->plugin | $setting->name";
    $fullname = $setting->get_full_name();

    // sometimes the id is not id_s_name, but id_s_name_m or something, and this does not validate
    if ($label) {
        $labelfor = 'for = "'.$setting->get_id().'"';
    } else {
        $labelfor = '';
    }

    if (empty($setting->plugin) and array_key_exists($setting->name, $CFG->config_php_settings)) {
        $override = '<div class="form-overridden">'.get_string('configoverride', 'admin').'</div>';
    } else {
        $override = '';
    }

    if ($warning !== '') {
        $warning = '<div class="form-warning">'.$warning.'</div>';
    }

    if (is_null($defaultinfo)) {
        $defaultinfo = '';
    } else {
        if ($defaultinfo === '') {
            $defaultinfo = get_string('emptysettingvalue', 'admin');
        }
        $defaultinfo = highlight($query, nl2br(s($defaultinfo)));
        $defaultinfo = '<div class="form-defaultinfo">'.get_string('defaultsettinginfo', 'admin', $defaultinfo).'</div>';
    }


    $str = '
<div class="form-item clearfix" id="admin-'.$setting->name.'">
  <div class="form-label">
    <label '.$labelfor.'>'.highlightfast($query, $title).'<span class="form-shortname">'.highlightfast($query, $name).'</span>
      '.$override.$warning.'
    </label>
  </div>
  <div class="form-setting">'.$form.$defaultinfo.'</div>
  <div class="form-description">'.highlight($query, $description).'</div>
</div>';

    $adminroot = admin_get_root();
    if (array_key_exists($fullname, $adminroot->errors)) {
        $str = '<fieldset class="error"><legend>'.$adminroot->errors[$fullname]->error.'</legend>'.$str.'</fieldset>';
    }

    return $str;
}

/**
 * Based on find_new_settings{@link ()}  in upgradesettings.php
 * Looks to find any admin settings that have not been initialized. Returns 1 if it finds any.
 *
 * @param object $node Instance of admin_category, or admin_settingpage
 * @return boolen true if any settings haven't been initialised, false if they all have
 */
function any_new_admin_settings($node) {

    if ($node instanceof admin_category) {
        $entries = array_keys($node->children);
        foreach ($entries as $entry) {
            if (any_new_admin_settings($node->children[$entry])){
                return true;
            }
        }

    } else if ($node instanceof admin_settingpage) {
        foreach ($node->settings as $setting) {
            if ($setting->get_setting() === NULL) {
                return true;
            }
        }
    }

    return false;
}


/**
 * Moved from admin/replace.php so that we can use this in cron
 *
 * @param string $search string to look for
 * @param string $replace string to replace
 * @return bool success or fail
 */
function db_replace($search, $replace) {

    global $DB, $CFG;

    /// Turn off time limits, sometimes upgrades can be slow.
    @set_time_limit(0);
    @ob_implicit_flush(true);
    while(@ob_end_flush());

    if (!$tables = $DB->get_tables() ) {    // No tables yet at all.
        return false;
    }
    foreach ($tables as $table) {

        if (in_array($table, array('config'))) {      // Don't process these
            continue;
        }

        if ($columns = $DB->get_columns($table)) {
            $DB->set_debug(true);
            foreach ($columns as $column => $data) {
                if (in_array($data->meta_type, array('C', 'X'))) {  // Text stuff only
                    $DB->execute("UPDATE {".$table."} SET $column = REPLACE($column, ?, ?)", array($search, $replace));
                }
            }
            $DB->set_debug(false);
        }
    }

    return true;
}

/**
 * Prints tables of detected plugins, one table per plugin type,
 * and prints whether they are part of the standard Moodle
 * distribution or not.
 */
function print_plugin_tables() {
    global $DB;
    $plugins_standard = array();
    $plugins_standard['mod'] = array('assignment',
                                     'chat',
                                     'choice',
                                     'data',
                                     'feedback',
                                     'folder',
                                     'forum',
                                     'glossary',
                                     'hotpot',
                                     'imscp',
                                     'label',
                                     'lesson',
                                     'page',
                                     'quiz',
                                     'resource',
                                     'scorm',
                                     'survey',
                                     'url',
                                     'wiki');

    $plugins_standard['blocks'] = array('activity_modules',
                                        'admin',
                                        'admin_bookmarks',
                                        'admin_tree',
                                        'blog_menu',
                                        'blog_tags',
                                        'calendar_month',
                                        'calendar_upcoming',
                                        'comments',
                                        'course_list',
                                        'course_summary',
                                        'glossary_random',
                                        'html',
                                        'loancalc',
                                        'login',
                                        'mentees',
                                        'messages',
                                        'mnet_hosts',
                                        'news_items',
                                        'online_users',
                                        'participants',
                                        'quiz_results',
                                        'recent_activity',
                                        'rss_client',
                                        'search',
                                        'search_forums',
                                        'section_links',
                                        'site_main_menu',
                                        'social_activities',
                                        'tag_flickr',
                                        'tag_youtube',
                                        'tags');

    $plugins_standard['filter'] = array('activitynames',
                                        'algebra',
                                        'censor',
                                        'emailprotect',
                                        'filter',
                                        'mediaplugin',
                                        'multilang',
                                        'tex',
                                        'tidy');

    $plugins_installed = array();
    $installed_mods = $DB->get_records('modules', null, 'name');
    $installed_blocks = $DB->get_records('block', null, 'name');

    foreach($installed_mods as $mod) {
        $plugins_installed['mod'][] = $mod->name;
    }

    foreach($installed_blocks as $block) {
        $plugins_installed['blocks'][] = $block->name;
    }
    $plugins_installed['filter'] = array();

    $plugins_ondisk = array();
    $plugins_ondisk['mod']    = array_keys(get_plugin_list('mod'));
    $plugins_ondisk['blocks'] = array_keys(get_plugin_list('block'));
    $plugins_ondisk['filter'] = array_keys(get_plugin_list('filter'));

    $strstandard    = get_string('standard');
    $strnonstandard = get_string('nonstandard');
    $strmissingfromdisk = '(' . get_string('missingfromdisk') . ')';
    $strabouttobeinstalled = '(' . get_string('abouttobeinstalled') . ')';

    $html = '';

    $html .= '<table class="generaltable plugincheckwrapper" cellspacing="4" cellpadding="1"><tr valign="top">';

    foreach ($plugins_ondisk as $cat => $list_ondisk) {
        $strcaption = get_string($cat);
        if ($cat == 'mod') {
            $strcaption = get_string('activitymodule');
        } elseif ($cat == 'filter') {
            $strcaption = get_string('managefilters');
        }

        $html .= '<td><table class="plugincompattable generaltable boxaligncenter" cellspacing="1" cellpadding="5" '
              . 'id="' . $cat . 'compattable" summary="compatibility table"><caption>' . $strcaption . '</caption>' . "\n";
        $html .= '<tr class="r0"><th class="header c0">' . get_string('directory') . "</th>\n"
               . '<th class="header c1">' . get_string('name') . "</th>\n"
               . '<th class="header c2">' . get_string('status') . "</th>\n</tr>\n";

        $row = 1;

        foreach ($list_ondisk as $k => $plugin) {
            $status = 'ok';
            $standard = 'standard';
            $note = '';

            if (!in_array($plugin, $plugins_standard[$cat])) {
                $standard = 'nonstandard';
                $status = 'warning';
            }

            // Get real name and full path of plugin
            $plugin_name = "[[$plugin]]";

            $plugin_path = "$cat/$plugin";

            $plugin_name = get_plugin_name($plugin, $cat);

            // Determine if the plugin is about to be installed
            if ($cat != 'filter' && !in_array($plugin, $plugins_installed[$cat])) {
                $note = $strabouttobeinstalled;
                $plugin_name = $plugin;
            }

            $html .= "<tr class=\"r$row\">\n"
                  .  "<td class=\"cell c0\">$plugin_path</td>\n"
                  .  "<td class=\"cell c1\">$plugin_name</td>\n"
                  .  "<td class=\"$standard $status cell c2\">" . ${'str' . $standard} . " $note</td>\n</tr>\n";
            $row++;

            // If the plugin was both on disk and in the db, unset the value from the installed plugins list
            if ($key = array_search($plugin, $plugins_installed[$cat])) {
                unset($plugins_installed[$cat][$key]);
            }
        }

        // If there are plugins left in the plugins_installed list, it means they are missing from disk
        foreach ($plugins_installed[$cat] as $k => $missing_plugin) {
            // Make sure the plugin really is missing from disk
            if (!in_array($missing_plugin, $plugins_ondisk[$cat])) {
                $standard = 'standard';
                $status = 'warning';

                if (!in_array($missing_plugin, $plugins_standard[$cat])) {
                    $standard = 'nonstandard';
                }

                $plugin_name = $missing_plugin;
                $html .= "<tr class=\"r$row\">\n"
                      .  "<td class=\"cell c0\">?</td>\n"
                      .  "<td class=\"cell c1\">$plugin_name</td>\n"
                      .  "<td class=\"$standard $status cell c2\">" . ${'str' . $standard} . " $strmissingfromdisk</td>\n</tr>\n";
                $row++;
            }
        }

        $html .= '</table></td>';
    }

    $html .= '</tr></table><br />';

    echo $html;
}

/**
 * Manage repository settings
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_managerepository extends admin_setting {
    /** @var string */
    private $baseurl;

    /**
     * calls parent::__construct with specific arguments
     */
    public function __construct() {
        global $CFG;
        parent::__construct('managerepository', get_string('managerepository', 'repository'), '', '');
        $this->baseurl = $CFG->wwwroot . '/' . $CFG->admin . '/repository.php?sesskey=' . sesskey();
    }

    /**
     * Always returns true, does nothing
     *
     * @return true
     */
    public function get_setting() {
        return true;
    }

    /**
     * Always returns true does nothing
     *
     * @return true
     */
    public function get_defaultsetting() {
        return true;
    }

    /**
     * Always returns s_managerepository
     *
     * @return string Always return 's_managerepository'
     */
    public function get_full_name() {
        return 's_managerepository';
    }

    /**
     * Always returns '' doesn't do anything
     */
    public function write_setting($data) {
        $url = $this->baseurl . '&amp;new=' . $data;
        return '';
        // TODO
        // Should not use redirect and exit here
        // Find a better way to do this.
        // redirect($url);
        // exit;
    }

    /**
     * Searches repository plugins for one that matches $query
     *
     * @param string $query The string to search for
     * @return bool true if found, false if not
     */
    public function is_related($query) {
        if (parent::is_related($query)) {
            return true;
        }

        $textlib = textlib_get_instance();
        $repositories= get_plugin_list('repository');
        foreach ($repositories as $p => $dir) {
            if (strpos($p, $query) !== false) {
                return true;
            }
        }
        foreach (repository::get_types() as $instance) {
            $title = $instance->get_typename();
            if (strpos($textlib->strtolower($title), $query) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Builds XHTML to display the control
     *
     * @param string $data Unused
     * @param string $query
     * @return string XHTML
     */
    public function output_html($data, $query='') {
        global $CFG, $USER, $OUTPUT;
        $output = $OUTPUT->box_start('generalbox');
        $namestr = get_string('name');
        $settingsstr = get_string('settings');
        $updownstr = get_string('updown', 'repository');
        $hiddenstr = get_string('hiddenshow', 'repository');
        $deletestr = get_string('delete');
        $plugins = get_plugin_list('repository');
        $instances = repository::get_types();
        $instancesnumber = count($instances);
        $alreadyplugins = array();
        $table = new html_table();
        $table->head = array($namestr, $updownstr, $hiddenstr, $deletestr, $settingsstr);
        $table->align = array('left', 'center', 'center','center','center');
        $table->data = array();
        $updowncount=1;
        foreach ($instances as $i) {
            $settings = '';
            //display edit link only if you can config the type or if it has multiple instances (e.g. has instance config)
            $typeoptionnames = repository::static_function($i->get_typename(), 'get_type_option_names');
            $instanceoptionnames = repository::static_function($i->get_typename(), 'get_instance_option_names');

            if ( !empty($typeoptionnames) || !empty($instanceoptionnames)) {

                //calculate number of instances in order to display them for the Moodle administrator
                if (!empty($instanceoptionnames)) {
                    $admininstancenumber = count(repository::static_function($i->get_typename(), 'get_instances', array(get_context_instance(CONTEXT_SYSTEM)),null,false,$i->get_typename()));
                    $admininstancenumbertext =   " <br/> ". $admininstancenumber .
                                        " " . get_string('instancesforadmin', 'repository');
                    $instancenumber =  count(repository::static_function($i->get_typename(), 'get_instances', array(),null,false,$i->get_typename())) - $admininstancenumber;
                    $instancenumbertext =  "<br/>" . $instancenumber .
                                        " " . get_string('instancesforothers', 'repository');
                } else {
                    $admininstancenumbertext = "";
                    $instancenumbertext = "";
                }

                $settings .= '<a href="' . $this->baseurl . '&amp;edit=' . $i->get_typename() . '">'
                              . $settingsstr .'</a>' . $admininstancenumbertext . $instancenumbertext . "\n";
            }
            $delete = '<a href="' . $this->baseurl . '&amp;delete=' .  $i->get_typename() . '">'
                        . $deletestr . '</a>' . "\n";

            $hidetitle = $i->get_visible() ? get_string('clicktohide', 'repository') : get_string('clicktoshow', 'repository');
            $hiddenshow = ' <a href="' . $this->baseurl . '&amp;hide=' . $i->get_typename() . '">'
                          .'<img src="' . $OUTPUT->old_icon_url('i/' . ($i->get_visible() ? 'hide' : 'show')) . '"'
                              .' alt="' . $hidetitle . '" '
                              .' title="' . $hidetitle . '" />'
                          .'</a>' . "\n";

             // display up/down link
            $updown = '';

                if ($updowncount > 1) {
                    $updown .= "<a href=\"$this->baseurl&amp;move=up&amp;type=".$i->get_typename()."\">";
                    $updown .= "<img src=\"" . $OUTPUT->old_icon_url('t/up') . "\" alt=\"up\" /></a>&nbsp;";
                }
                else {
                    $updown .= "<img src=\"" . $OUTPUT->old_icon_url('spacer.gif') . "\" class=\"icon\" alt=\"\" />&nbsp;";
                }
                if ($updowncount < count($instances)) {
                    $updown .= "<a href=\"$this->baseurl&amp;move=down&amp;type=".$i->get_typename()."\">";
                    $updown .= "<img src=\"" . $OUTPUT->old_icon_url('t/down') . "\" alt=\"down\" /></a>";
                }
                else {
                    $updown .= "<img src=\"" . $OUTPUT->old_icon_url('spacer.gif') . "\" class=\"icon\" alt=\"\" />";
                }

                $updowncount++;

            $table->data[] = array($i->get_readablename(), $updown, $hiddenshow, $delete, $settings);

            //display a grey row if the type is defined as not visible
            if (!$i->get_visible()){
                $table->rowclasses[] = 'dimmed_text';
            } else{
                $table->rowclasses[] = '';
            }

            if (!in_array($i->get_typename(), $alreadyplugins)) {
                $alreadyplugins[] = $i->get_typename();
            }
        }
        $output .= $OUTPUT->table($table);
        $instancehtml = '<div><h3>';
        $instancehtml .= get_string('addplugin', 'repository');
        $instancehtml .= '</h3><ul>';
        $addable = 0;
        foreach ($plugins as $p=>$dir) {
            if (!in_array($p, $alreadyplugins)) {
                $instancehtml .= '<li><a href="'.$CFG->wwwroot.'/'.$CFG->admin.'/repository.php?sesskey='
                    .sesskey().'&amp;new='.$p.'">'.get_string('add', 'repository')
                    .' "'.get_string('repositoryname', 'repository_'.$p).'" '
                    .'</a></li>';
                $addable++;
            }
        }
        $instancehtml .= '</ul>';
        $instancehtml .= '</div>';
        if ($addable) {
            $output .= $instancehtml;
        }

        $output .= $OUTPUT->box_end();
        return highlight($query, $output);
    }
}

/**
 *
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_managewsprotocols extends admin_setting {
    /** @var string */
    private $baseurl;

    /**
     * Calls parent::__construct with specific arguments
     */
    public function __construct() {
        global $CFG;
        parent::__construct('managewsprotocols', get_string('managewsprotocols', 'admin'), '', '');
        $this->baseurl = $CFG->wwwroot . '/' . $CFG->admin . '/wsprotocols.php?sesskey=' . sesskey();
    }

    /**
     * Always returns true, does nothing
     * @return true
     */
    public function get_setting() {
        return true;
    }

    /**
     * Doesnt nothing, always returns ''
     *
     * @return string Always returns ''
     */
    public function write_setting($data) {
        $url = $this->baseurl . '&amp;new=' . $data;
        return '';
    }

    /**
     * Builds XHTML to display the control
     *
     * @param string $data
     * @param string $query
     * @return string XHTML
     */
    public function output_html($data, $query='') {
        global $CFG, $OUTPUT;

        $namestr = get_string('name');
        $settingsstr = get_string('settings');
        $hiddenstr = get_string('hiddenshow', 'repository');
        require_once("../webservice/lib.php");
        $protocols = webservice_lib::get_list_protocols();
        $table = new html_table();
        $table->head = array($namestr, $hiddenstr, $settingsstr);
        $table->align = array('left', 'center', 'center');
        $table->data = array();

        foreach ($protocols as $i) {
            $hidetitle = $i->get_protocolid() ? get_string('clicktohide', 'repository') : get_string('clicktoshow', 'repository');
            $hiddenshow = ' <a href="' . $this->baseurl . '&amp;hide=' . $i->get_protocolid() . '">'
                          .'<img src="' . $OUTPUT->old_icon_url('i/' . ($i->get_enable() ? 'hide' : 'show')) . '"'
                              .' alt="' . $hidetitle . '" '
                              .' title="' . $hidetitle . '" />'
                          .'</a>' . "\n";

            $settingnames = $i->get_setting_names();
            if (!empty($settingnames)) {
                $settingsshow = ' <a href="' . $this->baseurl . '&amp;settings=' . $i->get_protocolid() . '">'
                          .$settingsstr
                          .'</a>' . "\n";
            } else {
                $settingsshow = "";
            }
            $table->data[] = array($i->get_protocolname(), $hiddenshow, $settingsshow);

            //display a grey row if the type is defined as not visible
            if (!$i->get_enable()){
                $table->rowclasses[] = 'dimmed_text';
            } else{
                $table->rowclasses[] = '';
            }
        }
        $output = $OUTPUT->table($table);

        return highlight($query, $output);
    }
}

/**
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_managewsusersettings extends admin_setting {
    /** @var string */
    private $baseurl;

    /**
     * Calls parent::__construct with specific arguments
     */
    public function __construct() {
        global $CFG;
        parent::__construct('managewsusersettings', get_string('managewsusersettings', 'admin'), '', '');
        $this->baseurl = $CFG->wwwroot . '/' . $CFG->admin . '/wsprotocols.php?sesskey=' . sesskey();
    }

    /**
     * Always returns true does nothing
     *
     * @return true
     */
    public function get_setting() {
        return true;
    }

    /**
     * Does nothing always returns ''
     *
     * @return string Always returns ''
     */
    public function write_setting($data) {
        $url = $this->baseurl . '&amp;new=' . $data;
        return '';
    }

    /**
     * Build XHTML to display the control
     *
     * @param string $data Unused
     * @param string $query
     * @return string XHTML
     */
    public function output_html($data, $query='') {
        global $CFG, $OUTPUT;
        $output = "";

        //search all web service users
        $users = get_users(true, '', false, null, 'firstname ASC','', '', '', 1000);

        $table = new html_table();
        $table->head = array('username', 'whitelist');
        $table->align = array('left', 'center');
        $table->data = array();

        foreach ($users as $user) {
            if (has_capability("moodle/site:usewebservices",get_system_context(), $user->id)) { //test if the users has has_capability('use_webservice')
                $wsusersetting = ' <a href="' . $this->baseurl . '&amp;username=' . $user->username . '">'
                . get_string("settings")
                          .'</a>' . "\n";
                $field = html_field::make_text('whitelist_'.$user->username);
                $field->style = "width: {$size}px;";
                $textfield = $OUTPUT->textfield($field);
                $table->data[] = array($user->username, $wsusersetting);
            }
        }

        $output .= $OUTPUT->table($table);
        return highlight($query, $output);
    }
}
