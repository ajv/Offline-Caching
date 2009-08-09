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
 * Various upgrade/install related functions and classes.
 *
 * @package    moodlecore
 * @subpackage upgrade
 * @copyright  1999 onwards Martin Dougiamas (http://dougiamas.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** UPGRADE_LOG_NORMAL = 0 */
define('UPGRADE_LOG_NORMAL', 0);
/** UPGRADE_LOG_NOTICE = 1 */
define('UPGRADE_LOG_NOTICE', 1);
/** UPGRADE_LOG_ERROR = 2 */
define('UPGRADE_LOG_ERROR',  2);

/**
 * Exception indicating unknown error during upgrade.
 *
 * @package    moodlecore
 * @subpackage upgrade
 * @copyright  1999 onwards Martin Dougiamas (http://dougiamas.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upgrade_exception extends moodle_exception {
    function __construct($plugin, $version) {
        global $CFG;
        $a = (object)array('plugin'=>$plugin, 'version'=>$version);
        parent::__construct('upgradeerror', 'admin', "$CFG->wwwroot/$CFG->admin/index.php", $a);
    }
}

/**
 * Exception indicating downgrade error during upgrade.
 *
 * @package    moodlecore
 * @subpackage upgrade
 * @copyright  1999 onwards Martin Dougiamas (http://dougiamas.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class downgrade_exception extends moodle_exception {
    function __construct($plugin, $oldversion, $newversion) {
        global $CFG;
        $plugin = is_null($plugin) ? 'moodle' : $plugin;
        $a = (object)array('plugin'=>$plugin, 'oldversion'=>$oldversion, 'newversion'=>$newversion);
        parent::__construct('cannotdowngrade', 'debug', "$CFG->wwwroot/$CFG->admin/index.php", $a);
    }
}

/**
 * @package    moodlecore
 * @subpackage upgrade
 * @copyright  1999 onwards Martin Dougiamas (http://dougiamas.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upgrade_requires_exception extends moodle_exception {
    function __construct($plugin, $pluginversion, $currentmoodle, $requiremoodle) {
        global $CFG;
        $a = new object();
        $a->pluginname     = $plugin;
        $a->pluginversion  = $pluginversion;
        $a->currentmoodle  = $currentmoodle;
        $a->requiremoodle  = $requiremoodle;
        parent::__construct('pluginrequirementsnotmet', 'error', "$CFG->wwwroot/$CFG->admin/index.php", $a);
    }
}

/**
 * @package    moodlecore
 * @subpackage upgrade
 * @copyright  1999 onwards Martin Dougiamas (http://dougiamas.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class plugin_defective_exception extends moodle_exception {
    function __construct($plugin, $details) {
        global $CFG;
        parent::__construct('detectedbrokenplugin', 'error', "$CFG->wwwroot/$CFG->admin/index.php", $plugin, $details);
    }
}

/**
 * Insert or update log display entry. Entry may already exist.
 * $module, $action must be unique
 *
 * @global object
 * @param string $module
 * @param string $action
 * @param string $mtable
 * @param string $field
 * @return void
 *
 */
function update_log_display_entry($module, $action, $mtable, $field) {
    global $DB;

    if ($type = $DB->get_record('log_display', array('module'=>$module, 'action'=>$action))) {
        $type->mtable = $mtable;
        $type->field  = $field;
        $DB->update_record('log_display', $type);

    } else {
        $type = new object();
        $type->module = $module;
        $type->action = $action;
        $type->mtable = $mtable;
        $type->field  = $field;

        $DB->insert_record('log_display', $type, false);
    }
}

/**
 * Upgrade savepoint, marks end of each upgrade block.
 * It stores new main version, resets upgrade timeout
 * and abort upgrade if user cancels page loading.
 *
 * Please do not make large upgrade blocks with lots of operations,
 * for example when adding tables keep only one table operation per block.
 *
 * @global object
 * @param bool $result false if upgrade step failed, true if completed
 * @param string or float $version main version
 * @param bool $allowabort allow user to abort script execution here
 * @return void
 */
function upgrade_main_savepoint($result, $version, $allowabort=true) {
    global $CFG;

    if (!$result) {
        throw new upgrade_exception(null, $version);
    }

    if ($CFG->version >= $version) {
        // something really wrong is going on in main upgrade script
        throw new downgrade_exception(null, $CFG->version, $version);
    }

    set_config('version', $version);
    upgrade_log(UPGRADE_LOG_NORMAL, null, 'Upgrade savepoint reached');

    // reset upgrade timeout to default
    upgrade_set_timeout();

    // this is a safe place to stop upgrades if user aborts page loading
    if ($allowabort and connection_aborted()) {
        die;
    }
}

/**
 * Module upgrade savepoint, marks end of module upgrade blocks
 * It stores module version, resets upgrade timeout
 * and abort upgrade if user cancels page loading.
 *
 * @global object
 * @param bool $result false if upgrade step failed, true if completed
 * @param string or float $version main version
 * @param string $modname name of module
 * @param bool $allowabort allow user to abort script execution here
 * @return void
 */
function upgrade_mod_savepoint($result, $version, $modname, $allowabort=true) {
    global $DB;

    if (!$result) {
        throw new upgrade_exception("mod_$modname", $version);
    }

    if (!$module = $DB->get_record('modules', array('name'=>$modname))) {
        print_error('modulenotexist', 'debug', '', $modname);
    }

    if ($module->version >= $version) {
        // something really wrong is going on in upgrade script
        throw new downgrade_exception("mod_$modname", $module->version, $version);
    }
    $module->version = $version;
    $DB->update_record('modules', $module);
    upgrade_log(UPGRADE_LOG_NORMAL, "mod_$modname", 'Upgrade savepoint reached');

    // reset upgrade timeout to default
    upgrade_set_timeout();

    // this is a safe place to stop upgrades if user aborts page loading
    if ($allowabort and connection_aborted()) {
        die;
    }
}

/**
 * Blocks upgrade savepoint, marks end of blocks upgrade blocks
 * It stores block version, resets upgrade timeout
 * and abort upgrade if user cancels page loading.
 *
 * @global object
 * @param bool $result false if upgrade step failed, true if completed
 * @param string or float $version main version
 * @param string $blockname name of block
 * @param bool $allowabort allow user to abort script execution here
 * @return void
 */
function upgrade_block_savepoint($result, $version, $blockname, $allowabort=true) {
    global $DB;

    if (!$result) {
        throw new upgrade_exception("block_$blockname", $version);
    }

    if (!$block = $DB->get_record('block', array('name'=>$blockname))) {
        print_error('blocknotexist', 'debug', '', $blockname);
    }

    if ($block->version >= $version) {
        // something really wrong is going on in upgrade script
        throw new downgrade_exception("block_$blockname", $block->version, $version);
    }
    $block->version = $version;
    $DB->update_record('block', $block);
    upgrade_log(UPGRADE_LOG_NORMAL, "block_$blockname", 'Upgrade savepoint reached');

    // reset upgrade timeout to default
    upgrade_set_timeout();

    // this is a safe place to stop upgrades if user aborts page loading
    if ($allowabort and connection_aborted()) {
        die;
    }
}

/**
 * Plugins upgrade savepoint, marks end of blocks upgrade blocks
 * It stores plugin version, resets upgrade timeout
 * and abort upgrade if user cancels page loading.
 *
 * @param bool $result false if upgrade step failed, true if completed
 * @param string or float $version main version
 * @param string $type name of plugin
 * @param string $dir location of plugin
 * @param bool $allowabort allow user to abort script execution here
 * @return void
 */
function upgrade_plugin_savepoint($result, $version, $type, $plugin, $allowabort=true) {
    $component = $type.'_'.$plugin;

    if (!$result) {
        throw new upgrade_exception($component, $version);
    }

    $installedversion = get_config($component, 'version');
    if ($installedversion >= $version) {
        // Something really wrong is going on in the upgrade script
        throw new downgrade_exception($component, $installedversion, $version);
    }
    set_config('version', $version, $component);
    upgrade_log(UPGRADE_LOG_NORMAL, $component, 'Upgrade savepoint reached');

    // Reset upgrade timeout to default
    upgrade_set_timeout();

    // This is a safe place to stop upgrades if user aborts page loading
    if ($allowabort and connection_aborted()) {
        die;
    }
}


/**
 * Upgrade plugins
 * @param string $type The type of plugins that should be updated (e.g. 'enrol', 'qtype')
 * return void
 */
function upgrade_plugins($type, $startcallback, $endcallback, $verbose) {
    global $CFG, $DB;

/// special cases
    if ($type === 'mod') {
        return upgrade_plugins_modules($startcallback, $endcallback, $verbose);
    } else if ($type === 'block') {
        return upgrade_plugins_blocks($startcallback, $endcallback, $verbose);
    }

    $plugs = get_plugin_list($type);

    foreach ($plugs as $plug=>$fullplug) {
        $component = $type.'_'.$plug; // standardised plugin name

        if (!is_readable($fullplug.'/version.php')) {
            continue;
        }

        $plugin = new object();
        require($fullplug.'/version.php');  // defines $plugin with version etc

        if (empty($plugin->version)) {
            throw new plugin_defective_exception($component, 'Missing version value in version.php');
        }

        $plugin->name     = $plug;
        $plugin->fullname = $component;


        if (!empty($plugin->requires)) {
            if ($plugin->requires > $CFG->version) {
                throw new upgrade_requires_exception($component, $plugin->version, $CFG->version, $plugin->requires);
            }
        }

        // try to recover from interrupted install.php if needed
        if (file_exists($fullplug.'/db/install.php')) {
            if (get_config($plugin->fullname, 'installrunning')) {
                require_once($fullplug.'/db/install.php');
                $recover_install_function = 'xmldb_'.$plugin->fullname.'_install_recovery';
                if (function_exists($recover_install_function)) {
                    $startcallback($component, true, $verbose);
                    $recover_install_function();
                    unset_config('installrunning', 'block_'.$plugin->fullname);
                    update_capabilities($component);
                    events_update_definition($component);
                    message_update_providers($component);
                    $endcallback($component, true, $verbose);
                }
            }
        }

        $installedversion = get_config($plugin->fullname, 'version');
        if (empty($installedversion)) { // new installation
            $startcallback($component, true, $verbose);

        /// Install tables if defined
            if (file_exists($fullplug.'/db/install.xml')) {
                $DB->get_manager()->install_from_xmldb_file($fullplug.'/db/install.xml');
            }

        /// store version
            upgrade_plugin_savepoint(true, $plugin->version, $type, $plug, false);

        /// execute post install file
            if (file_exists($fullplug.'/db/install.php')) {
                require_once($fullplug.'/db/install.php');
                set_config('installrunning', 1, 'block_'.$plugin->fullname);
                $post_install_function = 'xmldb_'.$plugin->fullname.'_install';;
                $post_install_function();
                unset_config('installrunning', 'block_'.$plugin->fullname);
            }

        /// Install various components
            update_capabilities($component);
            events_update_definition($component);
            message_update_providers($component);

            $endcallback($component, true, $verbose);

        } else if ($installedversion < $plugin->version) { // upgrade
        /// Run the upgrade function for the plugin.
            $startcallback($component, false, $verbose);

            if (is_readable($fullplug.'/db/upgrade.php')) {
                require_once($fullplug.'/db/upgrade.php');  // defines upgrading function

                $newupgrade_function = 'xmldb_'.$plugin->fullname.'_upgrade';
                $result = $newupgrade_function($installedversion);
            } else {
                $result = true;
            }

            $installedversion = get_config($plugin->fullname, 'version');
            if ($installedversion < $plugin->version) {
                // store version if not already there
                upgrade_plugin_savepoint($result, $plugin->version, $type, $plug, false);
            }

        /// Upgrade various components
            update_capabilities($component);
            events_update_definition($component);
            message_update_providers($component);

            $endcallback($component, false, $verbose);

        } else if ($installedversion > $plugin->version) {
            throw new downgrade_exception($component, $installedversion, $plugin->version);
        }
    }
}

/**
 * Find and check all modules and load them up or upgrade them if necessary
 *
 * @global object
 * @global object
 */
function upgrade_plugins_modules($startcallback, $endcallback, $verbose) {
    global $CFG, $DB;

    $mods = get_plugin_list('mod');

    foreach ($mods as $mod=>$fullmod) {

        if ($mod == 'NEWMODULE') {   // Someone has unzipped the template, ignore it
            continue;
        }

        $component = 'mod_'.$mod;

        if (!is_readable($fullmod.'/version.php')) {
            throw new plugin_defective_exception($component, 'Missing version.php');
        }

        $module = new object();
        require($fullmod .'/version.php');  // defines $module with version etc

        if (empty($module->version)) {
            if (isset($module->version)) {
                // Version is empty but is set - it means its value is 0 or ''. Let us skip such module.
                // This is inteded for developers so they can work on the early stages of the module.
                continue;
            }
            throw new plugin_defective_exception($component, 'Missing version value in version.php');
        }

        if (!empty($module->requires)) {
            if ($module->requires > $CFG->version) {
                throw new upgrade_requires_exception($component, $module->version, $CFG->version, $module->requires);
            }
        }

        $module->name = $mod;   // The name MUST match the directory

        $currmodule = $DB->get_record('modules', array('name'=>$module->name));

        if (file_exists($fullmod.'/db/install.php')) {
            if (get_config($module->name, 'installrunning')) {
                require_once($fullmod.'/db/install.php');
                $recover_install_function = 'xmldb_'.$module->name.'_install_recovery';
                if (function_exists($recover_install_function)) {
                    $startcallback($component, true, $verbose);
                    $recover_install_function();
                    unset_config('installrunning', $module->name);
                    // Install various components too
                    update_capabilities($component);
                    events_update_definition($component);
                    message_update_providers($component);
                    $endcallback($component, true, $verbose);
                }
            }
        }

        if (empty($currmodule->version)) {
            $startcallback($component, true, $verbose);

        /// Execute install.xml (XMLDB) - must be present in all modules
            $DB->get_manager()->install_from_xmldb_file($fullmod.'/db/install.xml');

        /// Add record into modules table - may be needed in install.php already
            $module->id = $DB->insert_record('modules', $module);

        /// Post installation hook - optional
            if (file_exists("$fullmod/db/install.php")) {
                require_once("$fullmod/db/install.php");
                // Set installation running flag, we need to recover after exception or error
                set_config('installrunning', 1, $module->name);
                $post_install_function = 'xmldb_'.$module->name.'_install';;
                $post_install_function();
                unset_config('installrunning', $module->name);
            }

        /// Install various components
            update_capabilities($component);
            events_update_definition($component);
            message_update_providers($component);

            $endcallback($component, true, $verbose);

        } else if ($currmodule->version < $module->version) {
        /// If versions say that we need to upgrade but no upgrade files are available, notify and continue
            $startcallback($component, false, $verbose);

            if (is_readable($fullmod.'/db/upgrade.php')) {
                require_once($fullmod.'/db/upgrade.php');  // defines new upgrading function
                $newupgrade_function = 'xmldb_'.$module->name.'_upgrade';
                $result = $newupgrade_function($currmodule->version, $module);
            } else {
                $result = true;
            }

            $currmodule = $DB->get_record('modules', array('name'=>$module->name));
            if ($currmodule->version < $module->version) {
                // store version if not already there
                upgrade_mod_savepoint($result, $module->version, $mod, false);
            }

        /// Upgrade various components
            update_capabilities($component);
            events_update_definition($component);
            message_update_providers($component);

            remove_dir($CFG->dataroot.'/cache', true); // flush cache

            $endcallback($component, false, $verbose);

        } else if ($currmodule->version > $module->version) {
            throw new downgrade_exception($component, $currmodule->version, $module->version);
        }
    }
}


/**
 * This function finds all available blocks and install them
 * into blocks table or do all the upgrade process if newer.
 *
 * @global object
 * @global object
 */
function upgrade_plugins_blocks($startcallback, $endcallback, $verbose) {
    global $CFG, $DB;

    require_once($CFG->dirroot.'/blocks/moodleblock.class.php');

    $blocktitles   = array(); // we do not want duplicate titles

    //Is this a first install
    $first_install = null;

    $blocks = get_plugin_list('block');

    foreach ($blocks as $blockname=>$fullblock) {

        if (is_null($first_install)) {
            $first_install = ($DB->count_records('block') == 0);
        }

        if ($blockname == 'NEWBLOCK') {   // Someone has unzipped the template, ignore it
            continue;
        }

        $component = 'block_'.$blockname;

        if (!is_readable($fullblock.'/block_'.$blockname.'.php')) {
            throw new plugin_defective_exception('block/'.$blockname, 'Missing main block class file.');
        }
        require_once($fullblock.'/block_'.$blockname.'.php');

        $classname = 'block_'.$blockname;

        if (!class_exists($classname)) {
            throw new plugin_defective_exception($component, 'Can not load main class.');
        }

        $blockobj    = new $classname;   // This is what we 'll be testing
        $blocktitle  = $blockobj->get_title();

        // OK, it's as we all hoped. For further tests, the object will do them itself.
        if (!$blockobj->_self_test()) {
            throw new plugin_defective_exception($component, 'Self test failed.');
        }

        $block           = new object();     // This may be used to update the db below
        $block->name     = $blockname;   // The name MUST match the directory
        $block->version  = $blockobj->get_version();
        $block->cron     = !empty($blockobj->cron) ? $blockobj->cron : 0;
        $block->multiple = $blockobj->instance_allow_multiple() ? 1 : 0;

        if (empty($block->version)) {
            throw new plugin_defective_exception($component, 'Missing block version.');
        }

        $currblock = $DB->get_record('block', array('name'=>$block->name));

        if (file_exists($fullblock.'/db/install.php')) {
            if (get_config('block_'.$blockname, 'installrunning')) {
                require_once($fullblock.'/db/install.php');
                $recover_install_function = 'xmldb_block_'.$blockname.'_install_recovery';
                if (function_exists($recover_install_function)) {
                    $startcallback($component, true, $verbose);
                    $recover_install_function();
                    unset_config('installrunning', 'block_'.$blockname);
                    // Install various components
                    update_capabilities($component);
                    events_update_definition($component);
                    message_update_providers($component);
                    $endcallback($component, true, $verbose);
                }
            }
        }

        if (empty($currblock->version)) { // block not installed yet, so install it
            // If it allows multiples, start with it enabled

            $conflictblock = array_search($blocktitle, $blocktitles);
            if ($conflictblock !== false) {
                // Duplicate block titles are not allowed, they confuse people
                // AND PHP's associative arrays ;)
                throw new plugin_defective_exception($component, get_string('blocknameconflict', '', (object)array('name'=>$block->name, 'conflict'=>$conflictblock)));
            }
            $startcallback($component, true, $verbose);

            if (file_exists($fullblock.'/db/install.xml')) {
                $DB->get_manager()->install_from_xmldb_file($fullblock.'/db/install.xml');
            }
            $block->id = $DB->insert_record('block', $block);

            if (file_exists($fullblock.'/db/install.php')) {
                require_once($fullblock.'/db/install.php');
                // Set installation running flag, we need to recover after exception or error
                set_config('installrunning', 1, 'block_'.$blockname);
                $post_install_function = 'xmldb_block_'.$blockname.'_install';;
                $post_install_function();
                unset_config('installrunning', 'block_'.$blockname);
            }

            $blocktitles[$block->name] = $blocktitle;

            // Install various components
            update_capabilities($component);
            events_update_definition($component);
            message_update_providers($component);

            $endcallback($component, true, $verbose);

        } else if ($currblock->version < $block->version) {
            $startcallback($component, false, $verbose);

            if (is_readable($fullblock.'/db/upgrade.php')) {
                require_once($fullblock.'/db/upgrade.php');  // defines new upgrading function
                $newupgrade_function = 'xmldb_block_'.$blockname.'_upgrade';
                $result = $newupgrade_function($currblock->version, $block);
            } else {
                $result = true;
            }

            $currblock = $DB->get_record('block', array('name'=>$block->name));
            if ($currblock->version < $block->version) {
                // store version if not already there
                upgrade_block_savepoint($result, $block->version, $block->name, false);
            }

            if ($currblock->cron != $block->cron) {
                // update cron flag if needed
                $currblock->cron = $block->cron;
                $DB->update_record('block', $currblock);
            }

            // Upgrade various componebts
            update_capabilities($component);
            events_update_definition($component);
            message_update_providers($component);

            $endcallback($component, false, $verbose);

        } else if ($currblock->version > $block->version) {
            throw new downgrade_exception($component, $currblock->version, $block->version);
        }
    }


    // Finally, if we are in the first_install of BLOCKS setup frontpage and admin page blocks
    if ($first_install) {
        //Iterate over each course - there should be only site course here now
        if ($courses = $DB->get_records('course')) {
            foreach ($courses as $course) {
                blocks_add_default_course_blocks($course);
            }
        }

        blocks_add_default_system_blocks();
    }
}

/**
 * upgrade logging functions
 */
function upgrade_handle_exception($ex, $plugin = null) {
    default_exception_handler($ex, true, $plugin);
}

/**
 * Adds log entry into upgrade_log table
 *
 * @global object
 * @global object
 * @global object
 * @param int $type UPGRADE_LOG_NORMAL, UPGRADE_LOG_NOTICE or UPGRADE_LOG_ERROR
 * @param string $plugin plugin or null if main
 * @param string $info short description text of log entry
 * @param string $details long problem description
 * @param string $backtrace string used for errors only
 * @return void
 */
function upgrade_log($type, $plugin, $info, $details=null, $backtrace=null) {
    global $DB, $USER, $CFG;

    $plugin = ($plugin==='moodle') ? null : $plugin;

    $backtrace = format_backtrace($backtrace, true);

    $version = null;

    //first try to find out current version number
    if (empty($plugin) or $plugin === 'moodle') {
        //main
        $version = $CFG->version;

    } else if ($plugin === 'local') {
        //customisation
        $version = $CFG->local_version;

    } else if (strpos($plugin, 'mod/') === 0) {
        try {
            $modname = substr($plugin, strlen('mod/'));
            $version = $DB->get_field('modules', 'version', array('name'=>$modname));
            $version = ($version === false) ? null : $version;
        } catch (Exception $ignored) {
        }

    } else if (strpos($plugin, 'block/') === 0) {
        try {
            $blockname = substr($plugin, strlen('block/'));
            if ($block = $DB->get_record('block', array('name'=>$blockname))) {
                $version = $block->version;
            }
        } catch (Exception $ignored) {
        }

    } else {
        $pluginversion = get_config(str_replace('/', '_', $plugin), 'version');
        if (!empty($pluginversion)) {
            $version = $pluginversion;
        }
    }

    $log = new object();
    $log->type         = $type;
    $log->plugin       = $plugin;
    $log->version      = $version;
    $log->info         = $info;
    $log->details      = $details;
    $log->backtrace    = $backtrace;
    $log->userid       = $USER->id;
    $log->timemodified = time();
    try {
        $DB->insert_record('upgrade_log', $log);
    } catch (Exception $ignored) {
        // possible during install or 2.0 upgrade
    }
}

/**
 * Marks start of upgrade, blocks any other access to site.
 * The upgrade is finished at the end of script or after timeout.
 *
 * @global object
 * @global object
 * @global object
 */
function upgrade_started($preinstall=false) {
    global $CFG, $DB, $PAGE;

    static $started = false;

    if ($preinstall) {
        ignore_user_abort(true);
        upgrade_setup_debug(true);

    } else if ($started) {
        upgrade_set_timeout(120);

    } else {
        if (!CLI_SCRIPT and !$PAGE->headerprinted) {
            $strupgrade  = get_string('upgradingversion', 'admin');
            $PAGE->set_generaltype('maintenance');
            upgrade_get_javascript();
            print_header($strupgrade.' - Moodle '.$CFG->target_release, $strupgrade,
                build_navigation(array(array('name' => $strupgrade, 'link' => null, 'type' => 'misc'))), '',
                '', false, '&nbsp;', '&nbsp;');
        }

        ignore_user_abort(true);
        register_shutdown_function('upgrade_finished_handler');
        upgrade_setup_debug(true);
        set_config('upgraderunning', time()+300);
        $started = true;
    }
}

/**
 * Internal function - executed if upgrade interruped.
 */
function upgrade_finished_handler() {
    upgrade_finished();
}

/**
 * Indicates upgrade is finished.
 *
 * This function may be called repeatedly.
 *
 * @global object
 * @global object
 */
function upgrade_finished($continueurl=null) {
    global $CFG, $DB, $OUTPUT;

    if (!empty($CFG->upgraderunning)) {
        unset_config('upgraderunning');
        upgrade_setup_debug(false);
        ignore_user_abort(false);
        if ($continueurl) {
            print_continue($continueurl);
            echo $OUTPUT->footer();
            die;
        }
    }
}

/**
 * @global object
 * @global object
 */
function upgrade_setup_debug($starting) {
    global $CFG, $DB;

    static $originaldebug = null;

    if ($starting) {
        if ($originaldebug === null) {
            $originaldebug = $DB->get_debug();
        }
        if (!empty($CFG->upgradeshowsql)) {
            $DB->set_debug(true);
        }
    } else {
        $DB->set_debug($originaldebug);
    }
}

/**
 * @global object
 */
function print_upgrade_reload($url) {
    global $OUTPUT;

    echo "<br />";
    echo '<div class="continuebutton">';
    echo '<a href="'.$url.'" title="'.get_string('reload').'" ><img src="'.$OUTPUT->old_icon_url('i/reload') . '" alt="" /> '.get_string('reload').'</a>';
    echo '</div><br />';
}

function print_upgrade_separator() {
    if (!CLI_SCRIPT) {
        echo '<hr />';
    }
}

/**
 * Default start upgrade callback
 * @param string $plugin
 * @param bool $installation true if installation, false menas upgrade
 */
function print_upgrade_part_start($plugin, $installation, $verbose) {
    global $OUTPUT;
    if (empty($plugin) or $plugin == 'moodle') {
        upgrade_started($installation); // does not store upgrade running flag yet
        if ($verbose) {
            echo $OUTPUT->heading(get_string('coresystem'));
        }
    } else {
        upgrade_started();
        if ($verbose) {
            echo $OUTPUT->heading($plugin);
        }
    }
    if ($installation) {
        if (empty($plugin) or $plugin == 'moodle') {
            // no need to log - log table not yet there ;-)
        } else {
            upgrade_log(UPGRADE_LOG_NORMAL, $plugin, 'Starting plugin installation');
        }
    } else {
        if (empty($plugin) or $plugin == 'moodle') {
            upgrade_log(UPGRADE_LOG_NORMAL, $plugin, 'Starting core upgrade');
        } else {
            upgrade_log(UPGRADE_LOG_NORMAL, $plugin, 'Starting plugin upgrade');
        }
    }
}

/**
 * Default end upgrade callback
 * @param string $plugin
 * @param bool $installation true if installation, false menas upgrade
 */
function print_upgrade_part_end($plugin, $installation, $verbose) {
    upgrade_started();
    if ($installation) {
        if (empty($plugin) or $plugin == 'moodle') {
            upgrade_log(UPGRADE_LOG_NORMAL, $plugin, 'Core installed');
        } else {
            upgrade_log(UPGRADE_LOG_NORMAL, $plugin, 'Plugin installed');
        }
    } else {
        if (empty($plugin) or $plugin == 'moodle') {
            upgrade_log(UPGRADE_LOG_NORMAL, $plugin, 'Core upgraded');
        } else {
            upgrade_log(UPGRADE_LOG_NORMAL, $plugin, 'Plugin upgraded');
        }
    }
    if ($verbose) {
        notify(get_string('success'), 'notifysuccess');
        print_upgrade_separator();
    }
}

/**
 * @global object
 */
function upgrade_get_javascript() {
    global $PAGE;
    $PAGE->requires->js('lib/javascript-static.js')->at_top_of_body();
    $PAGE->requires->js_function_call('repeatedly_scroll_to_end')->at_top_of_body();
    $PAGE->requires->js_function_call('cancel_scroll_to_end')->after_delay(1);
}


/**
 * Try to upgrade the given language pack (or current language)
 * @global object
 */
function upgrade_language_pack($lang='') {
    global $CFG, $OUTPUT;

    if (empty($lang)) {
        $lang = current_language();
    }

    if ($lang == 'en_utf8') {
        return true;  // Nothing to do
    }

    upgrade_started(false);
    echo $OUTPUT->heading(get_string('langimport', 'admin').': '.$lang);

    @mkdir ($CFG->dataroot.'/temp/');    //make it in case it's a fresh install, it might not be there
    @mkdir ($CFG->dataroot.'/lang/');

    require_once($CFG->libdir.'/componentlib.class.php');

    if ($cd = new component_installer('http://download.moodle.org', 'lang16', $lang.'.zip', 'languages.md5', 'lang')) {
        $status = $cd->install(); //returns COMPONENT_(ERROR | UPTODATE | INSTALLED)

        if ($status == COMPONENT_INSTALLED) {
            @unlink($CFG->dataroot.'/cache/languages');
            if ($parentlang = get_parent_language($lang)) {
                if ($cd = new component_installer('http://download.moodle.org', 'lang16', $parentlang.'.zip', 'languages.md5', 'lang')) {
                    $cd->install();
                }
            }
            notify(get_string('success'), 'notifysuccess');
        }
    }

    print_upgrade_separator();
}

/**
 * Install core moodle tables and initialize
 * @param float $version target version
 * @param bool $verbose
 * @return void, may throw exception
 */
function install_core($version, $verbose) {
    global $CFG, $DB;

    try {
        set_time_limit(600);
        print_upgrade_part_start('moodle', true, $verbose); // does not store upgrade running flag

        $DB->get_manager()->install_from_xmldb_file("$CFG->libdir/db/install.xml");
        upgrade_started();     // we want the flag to be stored in config table ;-)

        // set all core default records and default settings
        require_once("$CFG->libdir/db/install.php");
        xmldb_main_install();

        // store version
        upgrade_main_savepoint(true, $version, false);

        // Continue with the instalation
        events_update_definition('moodle');
        message_update_providers('moodle');

        // Write default settings unconditionlly
        admin_apply_default_settings(NULL, true);

        print_upgrade_part_end(null, true, $verbose);
    } catch (exception $ex) {
        upgrade_handle_exception($ex);
    }
}

/**
 * Upgrade moodle core
 * @param float $version target version
 * @param bool $verbose
 * @return void, may throw exception
 */
function upgrade_core($version, $verbose) {
    global $CFG;

    require_once($CFG->libdir.'/db/upgrade.php');    // Defines upgrades

    try {
        // Upgrade current language pack if we can
        if (empty($CFG->skiplangupgrade)) {
            upgrade_language_pack(false);
        }

        print_upgrade_part_start('moodle', false, $verbose);

        // one time special local migration pre 2.0 upgrade script
        if ($version < 2007101600) {
            $pre20upgradefile = "$CFG->dirrot/local/upgrade_pre20.php";
            if (file_exists($pre20upgradefile)) {
                set_time_limit(0);
                require($pre20upgradefile);
                // reset upgrade timeout to default
                upgrade_set_timeout();
            }
        }

        $result = xmldb_main_upgrade($CFG->version);
        if ($version > $CFG->version) {
            // store version if not already there
            upgrade_main_savepoint($result, $version, false);
        }

        // perform all other component upgrade routines
        update_capabilities('moodle');
        events_update_definition('moodle');
        message_update_providers('moodle');

        remove_dir($CFG->dataroot . '/cache', true); // flush cache

        print_upgrade_part_end('moodle', false, $verbose);
    } catch (Exception $ex) {
        upgrade_handle_exception($ex);
    }
}

/**
 * Upgrade/install other parts of moodle
 * @param bool $verbose
 * @return void, may throw exception
 */
function upgrade_noncore($verbose) {
    global $CFG;

    // upgrade all plugins types
    try {
        $plugintypes = get_plugin_types();
        foreach ($plugintypes as $type=>$location) {
            upgrade_plugins($type, 'print_upgrade_part_start', 'print_upgrade_part_end', $verbose);
        }
    } catch (Exception $ex) {
        upgrade_handle_exception($ex);
    }

    // Check for changes to RPC functions
    if ($CFG->mnet_dispatcher_mode != 'off') {
        try {
            // this needs a full rewrite, sorry to mention that :-(
            // we have to make it part of standard WS framework
            require_once("$CFG->dirroot/$CFG->admin/mnet/adminlib.php");
            upgrade_RPC_functions();  // Return here afterwards
        } catch (Exception $ex) {
            upgrade_handle_exception($ex);
        }
    }
}

/**
 * Checks if the main tables have been installed yet or not.
 * @return bool
 */
function core_tables_exist() {
    global $DB;

    if (!$tables = $DB->get_tables() ) {    // No tables yet at all.
        return false;

    } else {                                 // Check for missing main tables
        $mtables = array('config', 'course', 'groupings'); // some tables used in 1.9 and 2.0, preferable something from the start and end of install.xml
        foreach ($mtables as $mtable) {
            if (!in_array($mtable, $tables)) {
                return false;
            }
        }
        return true;
    }
}
