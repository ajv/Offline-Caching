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
 * Main administration script.
 *
 * @package    moodlecore
 * @copyright  1999 onwards Martin Dougiamas (http://dougiamas.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Check that config.php exists, if not then call the install script
if (!file_exists('../config.php')) {
    header('Location: ../install.php');
    die;
}

// Check that PHP is of a sufficient version as soon as possible
if (version_compare(phpversion(), '5.2.0') < 0) {
    $phpversion = phpversion();
    // do NOT localise - lang strings would not work here and we CAN NOT move it to later place
    echo "Sorry, Moodle 2.0 requires PHP 5.2.8 or later (currently using version $phpversion). ";
    echo "Please upgrade your server software or use latest Moodle 1.9.x instead.";
    die;
}

// try to flush everything all the time
@ob_implicit_flush(true);
while(@ob_end_clean()); // ob_end_flush prevents sending of headers

require('../config.php');
require_once($CFG->libdir.'/adminlib.php');    // various admin-only functions
require_once($CFG->libdir.'/upgradelib.php');  // general upgrade/install related functions

$id             = optional_param('id', '', PARAM_TEXT);
$confirmupgrade = optional_param('confirmupgrade', 0, PARAM_BOOL);
$confirmrelease = optional_param('confirmrelease', 0, PARAM_BOOL);
$confirmplugins = optional_param('confirmplugincheck', 0, PARAM_BOOL);
$agreelicense   = optional_param('agreelicense', 0, PARAM_BOOL);

// Check some PHP server settings

$PAGE->set_url($CFG->admin . '/index.php');

$documentationlink = '<a href="http://docs.moodle.org/en/Installation">Installation docs</a>';

if (ini_get_bool('session.auto_start')) {
    print_error('phpvaroff', 'debug', '', (object)array('name'=>'session.auto_start', 'link'=>$documentationlink));
}

if (ini_get_bool('magic_quotes_runtime')) {
    print_error('phpvaroff', 'debug', '', (object)array('name'=>'magic_quotes_runtime', 'link'=>$documentationlink));
}

if (!ini_get_bool('file_uploads')) {
    print_error('phpvaron', 'debug', '', (object)array('name'=>'file_uploads', 'link'=>$documentationlink));
}

if (is_float_problem()) {
    print_error('phpfloatproblem', 'admin', '', $documentationlink);
}

// Check settings in config.php

$dirroot = dirname(realpath('../index.php'));
// Check correct dirroot, ignoring slashes (though should be always forward slashes). MDL-18195
if (!empty($dirroot) and str_replace('\\', '/', $dirroot) != str_replace('\\', '/', $CFG->dirroot)) {
    print_error('fixsetting', 'debug', '', (object)array('current'=>$CFG->dirroot, 'found'=>str_replace('\\', '/', $dirroot)));
}

// Set some necessary variables during set-up to avoid PHP warnings later on this page
if (!isset($CFG->framename)) {
    $CFG->framename = '_top';
}
if (!isset($CFG->release)) {
    $CFG->release = '';
}
if (!isset($CFG->version)) {
    $CFG->version = '';
}

$version = null;
$release = null;
require("$CFG->dirroot/version.php");       // defines $version and $release
$CFG->target_release = $release;            // used during installation and upgrades

if (!$version or !$release) {
    print_error('withoutversion', 'debug'); // without version, stop
}

// Turn off xmlstrictheaders during upgrade.
$origxmlstrictheaders = !empty($CFG->xmlstrictheaders);
$CFG->xmlstrictheaders = false;

if (!core_tables_exist()) {
    $PAGE->set_generaltype('maintenance');

    // fake some settings
    $CFG->docroot = 'http://docs.moodle.org';

    $strinstallation = get_string('installation', 'install');

    // remove current session content completely
    session_get_instance()->terminate_current();

    if (empty($agreelicense)) {
        $strlicense = get_string('license');
        $navigation = build_navigation(array(array('name'=>$strlicense, 'link'=>null, 'type'=>'misc')));
        print_header($strinstallation.' - Moodle '.$CFG->target_release, $strinstallation, $navigation, '', '', false, '&nbsp;', '&nbsp;');
        echo $OUTPUT->heading('<a href="http://moodle.org">Moodle</a> - Modular Object-Oriented Dynamic Learning Environment');
        echo $OUTPUT->heading(get_string('copyrightnotice'));
        $copyrightnotice = text_to_html(get_string('gpl'));
        $copyrightnotice = str_replace('target="_blank"', 'onclick="this.target=\'_blank\'"', $copyrightnotice); // extremely ugly validation hack
        echo $OUTPUT->box($copyrightnotice, 'copyrightnotice');
        echo '<br />';
        notice_yesno(get_string('doyouagree'), "index.php?agreelicense=1&lang=$CFG->lang",
                                               "http://docs.moodle.org/en/License");
        echo $OUTPUT->footer();
        die;
    }
    if (empty($confirmrelease)) {
        $strcurrentrelease = get_string('currentrelease');
        $navigation = build_navigation(array(array('name'=>$strcurrentrelease, 'link'=>null, 'type'=>'misc')));
        print_header($strinstallation.' - Moodle '.$CFG->target_release, $strinstallation, $navigation, '', '', false, '&nbsp;', '&nbsp;');
        echo $OUTPUT->heading("Moodle $release");
        $releasenoteslink = get_string('releasenoteslink', 'admin', 'http://docs.moodle.org/en/Release_Notes');
        $releasenoteslink = str_replace('target="_blank"', 'onclick="this.target=\'_blank\'"', $releasenoteslink); // extremely ugly validation hack
        echo $OUTPUT->box($releasenoteslink, 'generalbox boxaligncenter boxwidthwide');

        require_once($CFG->libdir.'/environmentlib.php');
        if (!check_moodle_environment($release, $environment_results, true, ENV_SELECT_RELEASE)) {
            print_upgrade_reload("index.php?agreelicense=1&amp;lang=$CFG->lang");
        } else {
            notify(get_string('environmentok', 'admin'), 'notifysuccess');
            print_continue("index.php?agreelicense=1&amp;confirmrelease=1&amp;lang=$CFG->lang");
        }

        echo $OUTPUT->footer();
        die;
    }

    $strdatabasesetup = get_string('databasesetup');
    $navigation = build_navigation(array(array('name'=>$strdatabasesetup, 'link'=>null, 'type'=>'misc')));
    upgrade_get_javascript();
    print_header($strinstallation.' - Moodle '.$CFG->target_release, $strinstallation, $navigation, '', '', false, '&nbsp;', '&nbsp;');

    if (!$DB->setup_is_unicodedb()) {
        if (!$DB->change_db_encoding()) {
            // If could not convert successfully, throw error, and prevent installation
            print_error('unicoderequired', 'admin');
        }
    }

    install_core($version, true);
}


// Check version of Moodle code on disk compared with database
// and upgrade if possible.

$stradministration = get_string('administration');
$PAGE->set_context(get_context_instance(CONTEXT_SYSTEM));

if (empty($CFG->version)) {
    print_error('missingconfigversion', 'debug');
}

if ($version > $CFG->version) {  // upgrade
    $PAGE->set_generaltype('maintenance');

    $a->oldversion = "$CFG->release ($CFG->version)";
    $a->newversion = "$release ($version)";
    $strdatabasechecking = get_string('databasechecking', '', $a);

    if (empty($confirmupgrade)) {
        $navigation = build_navigation(array(array('name'=>$strdatabasechecking, 'link'=>null, 'type'=>'misc')));
        print_header($strdatabasechecking, $stradministration, $navigation, '', '', false, '&nbsp;', '&nbsp;');
        $continueform = new html_form();
        $continueform->method = 'get';
        $continueform->url = new moodle_url('index.php', array('confirmupgrade' => 1));
        echo $OUTPUT->confirm(get_string('upgradesure', 'admin', $a->newversion), $continueform, 'index.php');
        echo $OUTPUT->footer();
        exit;

    } else if (empty($confirmrelease)){
        $strcurrentrelease = get_string('currentrelease');
        $navigation = build_navigation(array(array('name'=>$strcurrentrelease, 'link'=>null, 'type'=>'misc')));
        print_header($strcurrentrelease, $strcurrentrelease, $navigation, '', '', false, '&nbsp;', '&nbsp;');
        echo $OUTPUT->heading("Moodle $release");
        $releasenoteslink = get_string('releasenoteslink', 'admin', 'http://docs.moodle.org/en/Release_Notes');
        $releasenoteslink = str_replace('target="_blank"', 'onclick="this.target=\'_blank\'"', $releasenoteslink); // extremely ugly validation hack
        echo $OUTPUT->box($releasenoteslink);

        require_once($CFG->libdir.'/environmentlib.php');
        if (!check_moodle_environment($release, $environment_results, true, ENV_SELECT_RELEASE)) {
            print_upgrade_reload('index.php?confirmupgrade=1');
        } else {
            notify(get_string('environmentok', 'admin'), 'notifysuccess');
            if (empty($CFG->skiplangupgrade)) {
                echo $OUTPUT->box_start('generalbox', 'notice');
                print_string('langpackwillbeupdated', 'admin');
                echo $OUTPUT->box_end();
            }
            print_continue('index.php?confirmupgrade=1&amp;confirmrelease=1');
        }

        echo $OUTPUT->footer();
        die;

    } elseif (empty($confirmplugins)) {
        $strplugincheck = get_string('plugincheck');
        $navigation = build_navigation(array(array('name'=>$strplugincheck, 'link'=>null, 'type'=>'misc')));
        print_header($strplugincheck, $strplugincheck, $navigation, '', '', false, '&nbsp;', '&nbsp;');
        echo $OUTPUT->heading($strplugincheck);
        echo $OUTPUT->box_start('generalbox', 'notice');
        print_string('pluginchecknotice');
        echo $OUTPUT->box_end();
        print_plugin_tables();
        print_upgrade_reload('index.php?confirmupgrade=1&amp;confirmrelease=1');
        print_continue('index.php?confirmupgrade=1&amp;confirmrelease=1&amp;confirmplugincheck=1');
        echo $OUTPUT->footer();
        die();

    } else {
        // Launch main upgrade
        upgrade_core($version, true);
    }
} else if ($version < $CFG->version) {
    notify('WARNING!!!  The code you are using is OLDER than the version that made these databases!');
}

// Updated human-readable release version if necessary
if ($release <> $CFG->release) {  // Update the release version
    set_config('release', $release);
}

// upgrade all plugins and other parts
upgrade_noncore(true);

// If this is the first install, indicate that this site is fully configured
// except the admin password
if (during_initial_install()) {
    set_config('rolesactive', 1); // after this, during_initial_install will return false.
    set_config('adminsetuppending', 1);
    // we neeed this redirect to setup proper session
    upgrade_finished("index.php?sessionstarted=1&amp;lang=$CFG->lang");
}

// make sure admin user is created - this is the last step because we need
// session to be working properly in order to edit admin account
 if (!empty($CFG->adminsetuppending)) {
    $sessionstarted = optional_param('sessionstarted', 0, PARAM_BOOL);
    if (!$sessionstarted) {
        redirect("index.php?sessionstarted=1&lang=$CFG->lang");
    } else {
        $sessionverify = optional_param('sessionverify', 0, PARAM_BOOL);
        if (!$sessionverify) {
            $SESSION->sessionverify = 1;
            redirect("index.php?sessionstarted=1&sessionverify=1&lang=$CFG->lang");
        } else {
            if (empty($SESSION->sessionverify)) {
                print_error('installsessionerror', 'admin', "index.php?sessionstarted=1&lang=$CFG->lang");
            }
            unset($SESSION->sessionverify);
        }
    }

    $adminuser = get_complete_user_data('username', 'admin');

    if ($adminuser->password === 'adminsetuppending') {
        // prevent installation hijacking
        if ($adminuser->lastip !== getremoteaddr()) {
            print_error('installhijacked', 'admin');
        }
        // login user and let him set password and admin details
        $adminuser->newadminuser = 1;
        message_set_default_message_preferences($adminuser);
        complete_user_login($adminuser, false);
        redirect("$CFG->wwwroot/user/editadvanced.php?id=$adminuser->id"); // Edit thyself

    } else {
        unset_config('adminsetuppending');
    }

} else {
    // just make sure upgrade logging is properly terminated
    upgrade_finished('upgradesettings.php');
}

// Turn xmlstrictheaders back on now.
$CFG->xmlstrictheaders = $origxmlstrictheaders;
unset($origxmlstrictheaders);

// Check for valid admin user - no guest autologin
require_login(0, false);
$context = get_context_instance(CONTEXT_SYSTEM);
require_capability('moodle/site:config', $context);

// check that site is properly customized
$site = get_site();
if (empty($site->shortname)) {
    // probably new installation - lets return to frontpage after this step
    // remove settings that we want uninitialised
    unset_config('registerauth');
    redirect('upgradesettings.php?return=site');
}

// Check if we are returning from moodle.org registration and if so, we mark that fact to remove reminders
if (!empty($id) and $id == $CFG->siteidentifier) {
    set_config('registered', time());
}

// setup critical warnings before printing admin tree block
$insecuredataroot = is_dataroot_insecure(true);
$SESSION->admin_critical_warning = ($insecuredataroot==INSECURE_DATAROOT_ERROR);

$adminroot = admin_get_root();

// Check if there are any new admin settings which have still yet to be set
if (any_new_admin_settings($adminroot)){
    redirect('upgradesettings.php');
}

// Everything should now be set up, and the user is an admin

// Print default admin page with notifications.
admin_externalpage_setup('adminnotifications');
admin_externalpage_print_header();

if ($insecuredataroot == INSECURE_DATAROOT_WARNING) {
    echo $OUTPUT->box(get_string('datarootsecuritywarning', 'admin', $CFG->dataroot), 'generalbox adminwarning');
} else if ($insecuredataroot == INSECURE_DATAROOT_ERROR) {
    echo $OUTPUT->box(get_string('datarootsecurityerror', 'admin', $CFG->dataroot), 'generalbox adminerror');

}

if (defined('WARN_DISPLAY_ERRORS_ENABLED')) {
    echo $OUTPUT->box(get_string('displayerrorswarning', 'admin'), 'generalbox adminwarning');
}

// If no recently cron run
$lastcron = $DB->get_field_sql('SELECT MAX(lastcron) FROM {modules}');
if (time() - $lastcron > 3600 * 24) {
    $strinstallation = get_string('installation', 'install');
    $helpbutton = helpbutton('install', $strinstallation, 'moodle', true, false, '', true);
    echo $OUTPUT->box(get_string('cronwarning', 'admin').'&nbsp;'.$helpbutton, 'generalbox adminwarning');
}

// Print multilang upgrade notice if needed
if (empty($CFG->filter_multilang_converted)) {
    echo $OUTPUT->box(get_string('multilangupgradenotice', 'admin'), 'generalbox adminwarning');
}

// Alert if we are currently in maintenance mode
if (!empty($CFG->maintenance_enabled)) {
    echo $OUTPUT->box(get_string('sitemaintenancewarning2', 'admin', "$CFG->wwwroot/$CFG->admin/settings.php?section=maintenancemode"), 'generalbox adminwarning');
}


//////////////////////////////////////////////////////////////////////////////////////////////////
////  IT IS ILLEGAL AND A VIOLATION OF THE GPL TO HIDE, REMOVE OR MODIFY THIS COPYRIGHT NOTICE ///
$copyrighttext = '<a href="http://moodle.org/">Moodle</a> '.
                 '<a href="http://docs.moodle.org/en/Release" title="'.$CFG->version.'">'.$CFG->release.'</a><br />'.
                 'Copyright &copy; 1999 onwards, Martin Dougiamas<br />'.
                 'and <a href="http://docs.moodle.org/en/Credits">many other contributors</a>.<br />'.
                 '<a href="http://docs.moodle.org/en/License">GNU Public License</a>';
echo $OUTPUT->box($copyrighttext, 'copyright');
//////////////////////////////////////////////////////////////////////////////////////////////////

echo $OUTPUT->footer();

