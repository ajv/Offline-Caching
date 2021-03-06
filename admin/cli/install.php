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
 * This script creates config.php file and prepares database.
 *
 * This script is not intended for beginners!
 * Potential problems:
 * - environment check is not present yet
 * - su to apache account or sudo before execution
 * - not compatible with Windows platform
 *
 * @package    moodlecore
 * @subpackage cli
 * @copyright  2009 Petr Skoda (http://skodak.org)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

if (isset($_SERVER['REMOTE_ADDR'])) {
    error_log("admin/cli/install.php can not be called from web server!");
    exit;
}

$help =
"Command line Moodle installer, creates config.php and initializes database.
Please note you must execute this script with the same uid as apache
or use chmod/chown after installation.

Site defaults may be changed via local/defaults.php.

Options:
--lang=CODE           Installation and default site language.
--wwwroot=URL         Web address for the Moodle site,
                      required in non-interactive mode.
--dataroot=DIR        Location of the moodle data folder,
                      must not be web accessible. Default is moodleroot
                      in parent directory.
--dbtype=TYPE         Database type. Default is mysqli
--dbhost=HOST         Database host. Default is localhost
--dbname=NAME         Database name. Default is moodle
--dbuser=USERNAME     Database user. Default is root
--dbpass=PASSWORD     Database password. Default is blank
--dbsocket            Use database sockets. Available for some databases only.
--prefix=STRING       Table prefix for above database tables. Default is mdl_
--admin-password=PASS Password for the moodle admin account,
                      required in non-interactive mode.
--non-interactive     No interactive questions, installation fails if any
                      problem encountered.
--agree-license       Indicates agreement with software license,
                      required in non-interactive mode.
-h, --help            Print out this help

Example: \$sudo -u wwwrun /usr/bin/php admin/cli/install.php --lang=cs
"; //TODO: localize, mark as needed in install - to be translated later when everything is finished


// Nothing to do if config.php exists
$configfile = dirname(dirname(dirname(__FILE__))).'/config.php';
if (file_exists($configfile)) {
    require($configfile);
    require_once($CFG->libdir.'/clilib.php');
    list($options, $unrecognized) = cli_get_params(array('help'=>false), array('h'=>'help'));

    if ($options['help']) {
        echo $help;
        echo "\n\n";
    }

    cli_error(get_string('clialreadyinstalled', 'install'));
}

$olddir = getcwd();

// change directory so that includes bellow work properly
chdir(dirname($_SERVER['argv'][0]));

// make sure PHP errors are displayed - helps with diagnosing of problems
@error_reporting(E_ALL);
@ini_set('display_errors', '1');
// we need a lot of memory
@ini_set('memory_limit', '128M');

// Check that PHP is of a sufficient version
if (version_compare(phpversion(), "5.2.8") < 0) {
    $phpversion = phpversion();
    // do NOT localise - lang strings would not work here and we CAN NOT move it after installib
    echo "Sorry, Moodle 2.0 requires PHP 5.2.8 or later (currently using version $phpversion).\n";
    echo "Please upgrade your server software or install latest Moodle 1.9.x instead.";
    die;
}

// set up configuration
$CFG = new stdClass();
$CFG->lang                 = 'en_utf8';
$CFG->dirroot              = str_replace('\\', '/', dirname(dirname(dirname(__FILE__)))); // Fix for win32
$CFG->libdir               = "$CFG->dirroot/lib";
$CFG->wwwroot              = "http://localhost";
$CFG->httpswwwroot         = $CFG->wwwroot;
$CFG->httpsthemewww        = $CFG->wwwroot;
$CFG->dataroot             = str_replace('\\', '/', dirname(dirname(dirname(__FILE__))).'/moodledata');
$CFG->docroot              = 'http://docs.moodle.org';
$CFG->directorypermissions = 00777;
$CFG->running_installer    = true;
$parts = explode('/', str_replace('\\', '/', dirname(dirname(__FILE__))));
$CFG->admin                = array_pop($parts);

require($CFG->dirroot.'/version.php');
$CFG->target_release = $release;

//point pear include path to moodles lib/pear so that includes and requires will search there for files before anywhere else
//the problem is that we need specific version of quickforms and hacked excel files :-(
ini_set('include_path', $CFG->libdir.'/pear' . PATH_SEPARATOR . ini_get('include_path'));

require_once($CFG->libdir.'/installlib.php');
require_once($CFG->libdir.'/clilib.php');
require_once($CFG->libdir.'/setuplib.php');
require_once($CFG->libdir.'/textlib.class.php');
require_once($CFG->libdir.'/weblib.php');
require_once($CFG->libdir.'/dmllib.php');
require_once($CFG->libdir.'/moodlelib.php');
require_once($CFG->libdir.'/deprecatedlib.php');
require_once($CFG->libdir.'/adminlib.php');
require_once($CFG->libdir.'/environmentlib.php');
require_once($CFG->libdir.'/xmlize.php');
require_once($CFG->libdir.'/componentlib.class.php');
require_once($CFG->libdir.'/upgradelib.php');
require_once($CFG->libdir.'/environmentlib.php');

//Database types
$databases = array('mysqli' => moodle_database::get_driver_instance('mysqli', 'native'),
                   'pgsql'  => moodle_database::get_driver_instance('pgsql',  'native'),
                   'oci'    => moodle_database::get_driver_instance('oci',    'native'),
                   //'sqlsrv' => moodle_database::get_driver_instance('sqlsrv', 'native'), // new MS sql driver - win32 only
                   //'mssql'  => moodle_database::get_driver_instance('mssql',  'native'), // FreeTDS driver
                  );
foreach ($databases as $type=>$database) {
    if ($database->driver_installed() !== true) {
        unset($databases[$type]);
    }
}
if (empty($databases)) {
    $defaultdb = '';
} else {
    reset($databases);
    $defaultdb = key($databases);
}

// now get cli options
list($options, $unrecognized) = cli_get_params(array('lang'=>$CFG->lang, 'wwwroot'=>'', 'dataroot'=>$CFG->dataroot, 'dbtype'=>$defaultdb, 'dbhost'=>'localhost',
                                                     'dbname'=>'moodle', 'dbuser'=>'root', 'dbpass'=>'', 'dbsocket'=>false, 'prefix'=>'mdl_', 'admin-password'=>'',
                                                     'non-interactive'=>false, 'agree-license'=>false, 'help'=>false),
                                               array('h'=>'help'));

$interactive = empty($options['non-interactive']);

// set up language
$lang = clean_param($options['lang'], PARAM_SAFEDIR);
if (file_exists($CFG->dirroot.'/install/lang/'.$lang)) {
    $CFG->lang = $lang;
} else {
    if (file_exists($CFG->dirroot.'/install/lang/'.$lang.'_utf8')) {
        $CFG->lang = $lang.'_utf8';
    }
}

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    echo $help;
    die;
}

//Print header
echo get_string('cliinstallheader', 'install', $CFG->target_release)."\n";

//Fist select language
if ($interactive) {
    cli_separator();
    $languages = install_get_list_of_languages();
    // format the langs nicely - 3 per line
    $c = 0;
    $langlist = '';
    foreach ($languages as $key=>$lang) {
        $c++;
        $length = iconv_strlen($lang, 'UTF-8');
        $padded = $lang.str_repeat(' ', 28-$length);
        $langlist .= $padded;
        if ($c % 3 == 0) {
            $langlist .= "\n";
        }
    }
    $default = str_replace('_utf8', '', $CFG->lang);
    cli_heading(get_string('availablelangs', 'install'));
    echo $langlist."\n";
    $prompt = get_string('clitypevaluedefault', 'admin', $default);
    $error = '';
    do {
        echo $error;
        $input = cli_input($prompt, $default);
        $input = clean_param($input, PARAM_SAFEDIR);

        if (!file_exists($CFG->dirroot.'/install/lang/'.$input.'_utf8')) {
            $error = get_string('cliincorrectvalueretry', 'admin')."\n";
        } else {
            $error = '';
        }
    } while ($error !== '');
    $CFG->lang = $input.'_utf8';
} else {
    // already selected and verified
}


//We need wwwroot before we test dataroot
$wwwroot = clean_param($options['wwwroot'], PARAM_URL);
$wwwroot = trim($wwwroot, '/');
if ($interactive) {
    cli_separator();
    cli_heading(get_string('wwwroot', 'install'));
    if (strpos($wwwroot, 'http') === 0) {
        $prompt = get_string('clitypevaluedefault', 'admin', $wwwroot);
    } else {
        $wwwroot = null;
        $prompt = get_string('clitypevalue', 'admin');
    }
    $error = '';
    do {
        echo $error;
        $input = cli_input($prompt, $wwwroot);
        $input = clean_param($input, PARAM_URL);
        $input = trim($input, '/');
        if (strpos($input, 'http') !== 0) {
            $error = get_string('cliincorrectvalueretry', 'admin')."\n";
        } else {
            $error = '';
        }
    } while ($error !== '');
    $wwwroot = $input;

} else {
    if (strpos($wwwroot, 'http') !== 0) {
        $a = (object)array('option'=>'wwwroot', 'value'=>$wwwroot);
        cli_error(get_string('cliincorrectvalueerror', 'admin', $a));
    }
}
$CFG->wwwroot       = $wwwroot;
$CFG->httpswwwroot  = $CFG->wwwroot;
$CFG->httpsthemewww = $CFG->wwwroot;


//We need dataroot before lang download
if ($interactive) {
    cli_separator();
    $i=0;
    while(is_dataroot_insecure()) {
        $parrent = dirname($CFG->dataroot);
        $i++;
        if ($parrent == '/' or $parrent == '.' or preg_match('/^[a-z]:\\\?$/i', $parrent) or ($i > 100)) {
            $CFG->dataroot = ''; //can not find secure location for dataroot
            break;
        }
        $CFG->dataroot = dirname($parrent).'/moodledata';
    }
    cli_heading(get_string('dataroot', 'install'));
    $error = '';
    do {
        if ($CFG->dataroot !== '') {
            $prompt = get_string('clitypevaluedefault', 'admin', $CFG->dataroot);
        } else {
            $prompt = get_string('clitypevalue', 'admin');
        }
        echo $error;
        $CFG->dataroot = cli_input($prompt, $CFG->dataroot);
        if ($CFG->dataroot === '') {
            $error = get_string('cliincorrectvalueretry', 'admin')."\n";
        } else if (is_dataroot_insecure()) {
            $CFG->dataroot = '';
            //TODO: use unsecure warning instead
            $error = get_string('cliincorrectvalueretry', 'admin')."\n";
        } else {
            if (make_upload_directory('lang', false)) {
                $error = '';
            } else {
                $error = get_string('pathserrcreatedataroot', 'install', $CFG->dataroot)."\n";
            }
        }

    } while ($error !== '');

} else {
    if (is_dataroot_insecure()) {
        $a = (object)array('option'=>'dataroot', 'value'=>$CFG->dataroot);
        //TODO: use unsecure warning instead
        cli_error(get_string('cliincorrectvalueerror', 'admin', $a));
    }
    if (!make_upload_directory('lang', false)) {
        cli_error(get_string('pathserrcreatedataroot', 'install', $CFG->dataroot));
    }
}

//download lang pack with optional notification
if ($CFG->lang != 'en_utf8') {
    if ($cd = new component_installer('http://download.moodle.org', 'lang16', $CFG->lang.'.zip', 'languages.md5', 'lang')) {
        if ($cd->install() == COMPONENT_ERROR) {
            if ($cd->get_error() == 'remotedownloaderror') {
                $a = new stdClass();
                $a->url  = 'http://download.moodle.org/lang16/'.$CFG->lang.'.zip';
                $a->dest = $CFG->dataroot.'/lang';
                cli_problem(get_string($cd->get_error(), 'error', $a));
            } else {
                cli_problem(get_string($cd->get_error(), 'error'));
            }
        } else {
            // install parent lang if defined
            if ($parentlang = get_parent_language()) {
                if ($cd = new component_installer('http://download.moodle.org', 'lang16', $parentlang.'.zip', 'languages.md5', 'lang')) {
                    $cd->install();
                }
            }
        }
    }
}

// ask for db type - show only drivers available
if ($interactive) {
    $options['dbtype'] = strtolower($options['dbtype']);
    cli_separator();
    cli_heading(get_string('databasetypehead', 'install'));
    foreach ($databases as $type=>$database) {
        echo " $type \n";
    }
    if (!empty($databases[$options['dbtype']])) {
        $prompt = get_string('clitypevaluedefault', 'admin', $options['dbtype']);
    } else {
        $prompt = get_string('clitypevalue', 'admin');
    }
    $CFG->dbtype = cli_input($prompt, $options['dbtype'], array_keys($databases));

} else {
    if (empty($databases[$options['dbtype']])) {
        $a = (object)array('option'=>'dbtype', 'value'=>$options['dbtype']);
        cli_error(get_string('cliincorrectvalueerror', 'admin', $a));
    }
    $CFG->dbtype = $options['dbtype'];
}
$database = $databases[$CFG->dbtype];


// ask for db host
if ($interactive) {
    cli_separator();
    cli_heading(get_string('databasehost', 'install'));
    if ($options['dbhost'] !== '') {
        $prompt = get_string('clitypevaluedefault', 'admin', $options['dbhost']);
    } else {
        $prompt = get_string('clitypevalue', 'admin');
    }
    $CFG->dbhost = cli_input($prompt, $options['dbhost']);

} else {
    $CFG->dbhost = $options['dbhost'];
}

// ask for db name
if ($interactive) {
    cli_separator();
    cli_heading(get_string('databasename', 'install'));
    if ($options['dbname'] !== '') {
        $prompt = get_string('clitypevaluedefault', 'admin', $options['dbname']);
    } else {
        $prompt = get_string('clitypevalue', 'admin');
    }
    $CFG->dbname = cli_input($prompt, $options['dbname']);

} else {
    $CFG->dbname = $options['dbname'];
}

// ask for db prefix
if ($interactive) {
    cli_separator();
    cli_heading(get_string('dbprefix', 'install'));
    //TODO: solve somehow the prefix trouble for oci
    if ($options['prefix'] !== '') {
        $prompt = get_string('clitypevaluedefault', 'admin', $options['prefix']);
    } else {
        $prompt = get_string('clitypevalue', 'admin');
    }
    $CFG->prefix = cli_input($prompt, $options['prefix']);

} else {
    $CFG->prefix = $options['prefix'];
}

// ask for db user
if ($interactive) {
    cli_separator();
    cli_heading(get_string('databaseuser', 'install'));
    if ($options['dbuser'] !== '') {
        $prompt = get_string('clitypevaluedefault', 'admin', $options['dbuser']);
    } else {
        $prompt = get_string('clitypevalue', 'admin');
    }
    $CFG->dbuser = cli_input($prompt, $options['dbuser']);

} else {
    $CFG->dbuser = $options['dbuser'];
}

// ask for db password
if ($interactive) {
    cli_separator();
    cli_heading(get_string('databasepass', 'install'));
    do {
        if ($options['dbpass'] !== '') {
            $prompt = get_string('clitypevaluedefault', 'admin', $options['dbpass']);
        } else {
            $prompt = get_string('clitypevalue', 'admin');
        }

        $CFG->dbpass = cli_input($prompt, $options['dbpass']);
        $hint_database = install_db_validate($database, $CFG->dbhost, $CFG->dbuser, $CFG->dbpass, $CFG->dbname, $CFG->prefix, array('dbpersit'=>0, 'dbsocket'=>$options['dbsocket']));
    } while ($hint_database !== '');

} else {
    $CFG->dbpass = $options['dbpass'];
    $hint_database = install_db_validate($database, $CFG->dbhost, $CFG->dbuser, $CFG->dbpass, $CFG->dbname, $CFG->prefix, array('dbpersit'=>0, 'dbsocket'=>$options['dbsocket']));
    if ($hint_database !== '') {
        cli_error(get_string('dbconnectionerror', 'install'));
    }
}

// ask for admin user password
if ($interactive) {
    cli_separator();
    cli_heading(get_string('cliadminpassword', 'install'));
    $prompt = get_string('clitypevalue', 'admin');
    do {
        $options['admin-password'] = cli_input($prompt);
    } while (empty($options['admin-password']) or $options['admin-password'] === 'admin');
} else {
    if (empty($options['admin-password']) or $options['admin-password'] === 'admin') {
        $a = (object)array('option'=>'admin-password', 'value'=>$options['admin-password']);
        cli_error(get_string('cliincorrectvalueerror', 'admin', $a));
    }
}

if ($interactive) {
    if (!$options['agree-license']) {
        cli_separator();
        cli_heading(get_string('copyrightnotice'));
        echo "Moodle  - Modular Object-Oriented Dynamic Learning Environment\n";
        echo get_string('gpl')."\n\n";
        echo get_string('doyouagree')."\n";
        $prompt = get_string('cliyesnoprompt', 'admin');
        $input = cli_input($prompt, '', array(get_string('clianswerno', 'admin'), get_string('cliansweryes', 'admin')));
        if ($input == get_string('clianswerno', 'admin')) {
            exit(1);
        }
    }
} else {
    if (!$options['agree-license']) {
        cli_error(get_string('climustagreelicense', 'install'));
    }
}

// Finally we have all info needed for config.php
$configphp = install_generate_configphp($database, $CFG);
umask(0137);
if (($fh = fopen($configfile, 'w')) !== false) {
    fwrite($fh, $configphp);
    fclose($fh);
}

if (!file_exists($configfile)) {
    cli_error('Can not create config file.');
}

// return back to original dir before executing setup.php chich changes the dir again
chdir($olddir);
// We have config.php, it is a real php script from now on :-)
require($configfile);

// show as much debug as possible
@error_reporting(1023);
@ini_set('display_errors', '1');
$CFG->debug = 38911;
$CFG->debugdisplay = true;

$CFG->version = "";
$CFG->release = "";

// read $version adn $release
require($CFG->dirroot.'/version.php');

if ($DB->get_tables() ) {
    cli_error(get_string('clitablesexist', 'install'));
}

// test environment first
if (!check_moodle_environment($version, $environment_results, false, ENV_SELECT_RELEASE)) {
    $errors = environment_get_errors($environment_results);
    cli_heading(get_string('environment', 'admin'));
    foreach ($errors as $error) {
        list($info, $report) = $error;
        echo "!! $info !!\n$report\n\n";
    }
    //remove config.php, we do not want half finished upgrades!
    unlink($configfile);
    exit(1);
}

if (!$DB->setup_is_unicodedb()) {
    if (!$DB->change_db_encoding()) {
        // If could not convert successfully, throw error, and prevent installation
        cli_error(get_string('unicoderequired', 'admin'));
    }
}

if ($interactive) {
    cli_separator();
    cli_heading(get_string('databasesetup'));
}

// install core
install_core($version, true);
set_config('release', $release);

// install all plugins types, local, etc.
upgrade_noncore(true);

// set up admin user password
$DB->set_field('user', 'password', hash_internal_user_password($options['admin-password'], array('username'=>'admin')));

// indicate that this site is fully configured
set_config('rolesactive', 1);
upgrade_finished();

// log in as admin - we need do anything when applying defaults
$admins = get_admins();
$admin = reset($admins);
session_set_user($admin);
message_set_default_message_preferences($admin);

// apply all default settings, do it twice to fill all defaults - some settings depend on other setting
admin_apply_default_settings(NULL, true);
admin_apply_default_settings(NULL, true);
set_config('registerauth', '');

echo get_string('cliinstallfinished', 'install')."\n";
exit(0); // 0 means success
