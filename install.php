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
 * This script creates config.php file during installation.
 *
 * @package    moodlecore
 * @subpackage install
 * @copyright  2009 Petr Skoda (http://skodak.org)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

if (isset($_REQUEST['lang'])) {
    $lang = preg_replace('/[^A-Za-z0-9_-]/i', '', $_REQUEST['lang']);
} else {
    $lang = 'en_utf8';
}

if (isset($_REQUEST['admin'])) {
    $admin = preg_replace('/[^A-Za-z0-9_-]/i', '', $_REQUEST['admin']);
} else {
    $admin = 'admin';
}

// If config.php exists we just created config.php and need to redirect to continue installation
$configfile = './config.php';
if (file_exists($configfile)) {
    header("Location: $admin/index.php?lang=$lang");
    die;
}

// make sure PHP errors are displayed - helps with diagnosing of problems
@error_reporting(E_ALL);
@ini_set('display_errors', '1');
// we need a lot of memory
@ini_set('memory_limit', '40M');

// Check that PHP is of a sufficient version
if (version_compare(phpversion(), "5.2.0") < 0) {
    $phpversion = phpversion();
    // do NOT localise - lang strings would not work here and we CAN not move it after installib
    echo "Sorry, Moodle 2.0 requires PHP 5.2.8 or later (currently using version $phpversion). ";
    echo "Please upgrade your server software or install latest Moodle 1.9.x instead.";
    die;
}

require dirname(__FILE__).'/lib/installlib.php';

// TODO: add lang detection here if empty $_REQUEST['lang']

// distro specific customisation
$distro = null;
if (file_exists('install/distrolib.php')) {
    require_once('install/distrolib.php');
    if (function_exists('distro_get_config')) {
        $distro = distro_get_config();
    }
}

$config = new stdClass();
$config->lang = $lang;

if (!empty($_POST)) {
    if (install_ini_get_bool('magic_quotes_gpc')) {
        $_POST = array_map('stripslashes', $_POST);
    }

    $config->stage = (int)$_POST['stage'];

    if (isset($_POST['previous'])) {
        $config->stage--;
        if (INSTALL_DATABASETYPE and !empty($distro->dbtype)) {
            $config->stage--;
        }
        if ($config->stage == INSTALL_ENVIRONMENT or $config->stage == INSTALL_DOWNLOADLANG) {
            $config->stage--;
        }
    } else if (isset($_POST['next'])) {
        $config->stage++;
    }

    $config->dbtype   = trim($_POST['dbtype']);
    $config->dbhost   = trim($_POST['dbhost']);
    $config->dbuser   = trim($_POST['dbuser']);
    $config->dbpass   = trim($_POST['dbpass']);
    $config->dbname   = trim($_POST['dbname']);
    $config->prefix   = trim($_POST['prefix']);
    $config->dbsocket = (int)(!empty($_POST['dbsocket']));

    $config->dirroot  = trim($_POST['dirroot']);
    $config->admin    = empty($_POST['admin']) ? 'admin' : trim($_POST['admin']);

    $config->dataroot = trim($_POST['dataroot']);

} else {
    $config->stage    = INSTALL_WELCOME;

    $config->dbtype   = empty($distro->dbtype) ? '' : $distro->dbtype; // let distro skip dbtype selection
    $config->dbhost   = empty($distro->dbhost) ? 'localhost' : $distro->dbhost; // let distros set dbhost
    $config->dbuser   = empty($distro->dbuser) ? '' : $distro->dbuser; // let distros set dbuser
    $config->dbpass   = '';
    $config->dbname   = 'moodle';
    $config->prefix   = 'mdl_';
    $config->dbsocket = 0;

    $config->dirroot  = str_replace('\\', '/', dirname(__FILE__)); // Fix for win32
    $config->admin    = 'admin';

    $config->dataroot = empty($distro->dataroot) ? null  : $distro->dataroot; // initialised later after including libs or by distro
}

// Fake some settings so that we can use selected functions from moodlelib.php and weblib.php
$CFG = new stdClass();
$CFG->lang                 = $config->lang;
$CFG->dirroot              = str_replace('\\', '/', dirname(__FILE__)); // Fix for win32
$CFG->libdir               = "$CFG->dirroot/lib";
$CFG->wwwroot              = install_guess_wwwroot(); // can not be changed - ppl must use the real address when installing
$CFG->httpswwwroot         = $CFG->wwwroot;
$CFG->httpsthemewww        = $CFG->wwwroot;
$CFG->dataroot             = $config->dataroot;
$CFG->admin                = $config->admin;
$CFG->docroot              = 'http://docs.moodle.org';
$CFG->directorypermissions = 00777;
$CFG->running_installer    = true;

// Require all needed libs
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

require('version.php');
$CFG->target_release = $release;

$SESSION = new object();
$SESSION->lang = $CFG->lang;

$USER = new object();
$USER->id = 0;

$COURSE = new object();
$COURSE->id = 0;

$SITE = $COURSE;
define('SITEID', 0);

$hint_dataroot = '';
$hint_dirroot  = '';
$hint_admindir = '';
$hint_database = '';

// Are we in help mode?
if (isset($_GET['help'])) {
    install_print_help_page($_GET['help']);
}

// send css?
if (isset($_GET['css'])) {
    install_css_styles();
}

//first time here? find out suitable dataroot
if (is_null($CFG->dataroot)) {
    $CFG->dataroot = str_replace('\\', '/', dirname(dirname(__FILE__)).'/moodledata');

    $i = 0; //safety check - dirname might return some unexpected results
    while(is_dataroot_insecure()) {
        $parrent = dirname($CFG->dataroot);
        $i++;
        if ($parrent == '/' or $parrent == '.' or preg_match('/^[a-z]:\\\?$/i', $parrent) or ($i > 100)) {
            $CFG->dataroot = ''; //can not find secure location for dataroot
            break;
        }
        $CFG->dataroot = dirname($parrent).'/moodledata';
    }
    $config->dataroot = $CFG->dataroot;
    $config->stage    = INSTALL_WELCOME;
}

// now let's do the stage work
if ($config->stage < INSTALL_WELCOME) {
    $config->stage = INSTALL_WELCOME;
}
if ($config->stage > INSTALL_SAVE) {
    $config->stage = INSTALL_SAVE;
}



if ($config->stage == INSTALL_SAVE) {
    $database = moodle_database::get_driver_instance($config->dbtype, 'native');
    if (!$database->driver_installed()) {
        $config->stage = INSTALL_DATABASETYPE;
    } else {
        if (function_exists('distro_pre_create_db')) { // Hook for distros needing to do something before DB creation
            $distro = distro_pre_create_db($database, $config->dbhost, $config->dbuser, $config->dbpass, $config->dbname, $config->prefix, array('dbpersit'=>0, 'dbsocket'=>$config->dbsocket), $distro);
        }
        $hint_database = install_db_validate($database, $config->dbhost, $config->dbuser, $config->dbpass, $config->dbname, $config->prefix, array('dbpersit'=>0, 'dbsocket'=>$config->dbsocket));

        if ($hint_database === '') {
            // extra hackery needed for symbolic link support
            if  ($CFG->dirroot !== $config->dirroot) {
                $CFG->dirroot = $config->dirroot;
                $userealpath = true;
            } else {
                $userealpath = false;
            }
            $configphp = install_generate_configphp($database, $CFG, $userealpath);

            umask(0137);
            if (($fh = @fopen($configfile, 'w')) !== false) {
                fwrite($fh, $configphp);
                fclose($fh);
            }

            if (file_exists($configfile)) {
                // config created, let's continue!
                redirect("$CFG->wwwroot/$config->admin/index.php?lang=$config->lang");
            }

            install_print_header($config, 'config.php',
                                          get_string('configurationcompletehead', 'install'),
                                          get_string('configurationcompletesub', 'install').get_string('configfilenotwritten', 'install'));
            echo '<div class="configphp"><pre>';
            echo p($configphp);
            echo '</pre></div>';

            install_print_footer($config);
            die;

        } else {
            $config->stage = INSTALL_DATABASE;
        }
    }
}



if ($config->stage == INSTALL_DOWNLOADLANG) {
    if (empty($CFG->dataroot)) {
        $config->stage = INSTALL_PATHS;

    } else if (is_dataroot_insecure()) {
        $hint_dataroot = get_string('pathsunsecuredataroot', 'install');
        $config->stage = INSTALL_PATHS;

    } else if (!file_exists($CFG->dataroot)) {
        $a = new stdClass();
        $a->parent = dirname($CFG->dataroot);
        $a->dataroot = $CFG->dataroot;
        if (!is_writable(dirname($CFG->dataroot))) {
            $hint_dataroot = get_string('pathsroparentdataroot', 'install', $a);
            $config->stage = INSTALL_PATHS;
        } else {
            if (!make_upload_directory('lang', false)) {
                $hint_dataroot = get_string('pathserrcreatedataroot', 'install', $a);
                $config->stage = INSTALL_PATHS;
            }
        }
    }

    if (empty($hint_dataroot) and !is_writable($CFG->dataroot)) {
        $hint_dataroot = get_string('pathsrodataroot', 'install');
        $config->stage = INSTALL_PATHS;
    }

    if ($config->dirroot === '' or !file_exists($config->dirroot)) {
        $hint_dirroot = get_string('pathswrongdirroot', 'install');
        $config->stage = INSTALL_PATHS;
    }

    if ($config->admin === '' or !file_exists($config->dirroot.'/'.$config->admin.'/environment.xml')) {
        $hint_admindir = get_string('pathswrongadmindir', 'install');
        $config->stage = INSTALL_PATHS;
    }
}



if ($config->stage == INSTALL_DOWNLOADLANG) {
    // no need to download anything if en lang selected
    if ($CFG->lang == 'en_utf8') {
        $config->stage = INSTALL_DATABASETYPE;
    }
}



if ($config->stage == INSTALL_DATABASETYPE) {
    // skip db selection if distro package supports only one db
    if (!empty($distro->dbtype)) {
        $config->stage = INSTALL_DATABASE;
    }
}


if ($config->stage == INSTALL_DOWNLOADLANG) {
    $downloaderror = '';

// Create necessary lang dir
    if (!make_upload_directory('lang', false)) {
        $downloaderror = get_string('cannotcreatelangdir', 'error');

// Download and install lang component
    } else if ($cd = new component_installer('http://download.moodle.org', 'lang16', $CFG->lang.'.zip', 'languages.md5', 'lang')) {
        if ($cd->install() == COMPONENT_ERROR) {
            if ($cd->get_error() == 'remotedownloaderror') {
                $a = new stdClass();
                $a->url  = 'http://download.moodle.org/lang16/'.$INSTALL['language'].'.zip';
                $a->dest = $CFG->dataroot.'/lang';
                $downloaderror = get_string($cd->get_error(), 'error', $a);
            } else {
                $downloaderror = get_string($cd->get_error(), 'error');
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

    if ($downloaderror !== '') {
        install_print_header($config, get_string('language'), get_string('langdownloaderror', 'install', $CFG->lang), $downloaderror);
        install_print_footer($config);
        die;
    } else {
        if (empty($distro->dbtype)) {
            $config->stage = INSTALL_DATABASETYPE;
        } else {
            $config->stage = INSTALL_DATABASE;
        }
    }
}


if ($config->stage == INSTALL_DATABASE) {
    $database = moodle_database::get_driver_instance($config->dbtype, 'native');

    $sub = '<h3>'.$database->get_name().'</h3>'.$database->get_configuration_help();

    install_print_header($config, get_string('database', 'install'), get_string('databasehead', 'install'), $sub);

    $strdbhost   = get_string('databasehost', 'install');
    $strdbname   = get_string('databasename', 'install');
    $strdbuser   = get_string('databaseuser', 'install');
    $strdbpass   = get_string('databasepass', 'install');
    $strprefix   = get_string('dbprefix', 'install');
    $strdbsocket = get_string('databasesocket', 'install');

    echo '<div class="userinput">';

    $disabled = empty($distro->dbhost) ? '' : 'disabled="disabled';
    echo '<div class="formrow"><label for="id_dbhost" class="formlabel">'.$strdbhost.'</label>';
    echo '<input id="id_dbhost" name="dbhost" '.$disabled.' type="text" value="'.s($config->dbhost).'" size="30" class="forminput" />';
    echo '</div>';

    echo '<div class="formrow"><label for="id_dbname" class="formlabel">'.$strdbname.'</label>';
    echo '<input id="id_dbname" name="dbname" type="text" value="'.s($config->dbname).'" size="30" class="forminput" />';
    echo '</div>';

    $disabled = empty($distro->dbuser) ? '' : 'disabled="disabled';
    echo '<div class="formrow"><label for="id_dbuser" class="formlabel">'.$strdbuser.'</label>';
    echo '<input id="id_dbuser" name="dbuser" '.$disabled.' type="text" value="'.s($config->dbuser).'" size="30" class="forminput" />';
    echo '</div>';

    echo '<div class="formrow"><label for="id_dbpass" class="formlabel">'.$strdbpass.'</label>';
    // no password field here, the password may be visible in config.php if we can not write it to disk
    echo '<input id="id_dbpass" name="dbpass" type="text" value="'.s($config->dbpass).'" size="30" class="forminput" />';
    echo '</div>';

    echo '<div class="formrow"><label for="id_prefix" class="formlabel">'.$strprefix.'</label>';
    echo '<input id="id_prefix" name="prefix" type="text" value="'.s($config->prefix).'" size="10" class="forminput" />';
    echo '</div>';

    if (!(stristr(PHP_OS, 'win') && !stristr(PHP_OS, 'darwin'))) {
        $checked = $config->dbsocket ? 'checked="checked' : '';
        echo '<div class="formrow"><label for="id_dbsocket" class="formlabel">'.$strdbsocket.'</label>';
        echo '<input type="hidden" value="0" name="dbsocket" />';
        echo '<input type="checkbox" id="id_dbsocket" value="1" name="dbsocket" '.$checked.' class="forminput" />';
        echo '</div>';
    }

    echo '<div class="hint">'.$hint_database.'</div>';
    echo '</div>';
    install_print_footer($config);
    die;
}


if ($config->stage == INSTALL_DATABASETYPE) {
    // Finally ask for DB type
    install_print_header($config, get_string('database', 'install'),
                                  get_string('databasetypehead', 'install'),
                                  get_string('databasetypesub', 'install'));

    // TODO: move this PHP5 code to lib/installib.php so that this file parses in PHP4
    $databases = array('mysqli' => moodle_database::get_driver_instance('mysqli', 'native'),
                       'pgsql'  => moodle_database::get_driver_instance('pgsql',  'native'),
                       'oci'    => moodle_database::get_driver_instance('oci',    'native'),
                       //'sqlsrv' => moodle_database::get_driver_instance('sqlsrv', 'native'), // new MS sql driver - win32 only
                       //'mssql'  => moodle_database::get_driver_instance('mssql',  'native'), // FreeTDS driver
                      );

    echo '<div class="userinput">';
    echo '<div class="formrow"><label class="formlabel" for="dbtype">'.get_string('dbtype', 'install').'</label>';
    echo '<select id="dbtype" name="dbtype" class="forminput">';
    $disabled = array();
    $options = array();
    foreach ($databases as $type=>$database) {
        if ($database->driver_installed() !== true) {
            $disabled[$type] = $database;
            continue;
        }
        echo '<option value="'.s($type).'">'.$database->get_name().'</option>';
    }
    if ($disabled) {
        echo '<optgroup label="'.s(get_string('notavailable')).'">';
        foreach ($disabled as $type=>$database) {
            echo '<option value="'.s($type).'" class="notavailable">'.$database->get_name().'</option>';
        }
        echo '</optgroup>';
    }
    echo '</select></div>';
    echo '</div>';

    install_print_footer($config);
    die;
}



if ($config->stage == INSTALL_ENVIRONMENT or $config->stage == INSTALL_PATHS) {
    $version_fail = (version_compare(phpversion(), "5.2.8") < 0);
    $curl_fail    = ($lang !== 'en_utf8' and !extension_loaded('curl')); // needed for lang pack download
    $zip_fail     = ($lang !== 'en_utf8' and !extension_loaded('zip'));  // needed for lang pack download

    if ($version_fail or $curl_fail or $zip_fail) {
        $config->stage = INSTALL_ENVIRONMENT;

        install_print_header($config, get_string('environment', 'install'),
                                      get_string('errorsinenvironment', 'install'),
                                      get_string('environmentsub2', 'install'));

        echo '<div id="envresult"><dl>';
        if ($version_fail) {
            $a = (object)array('needed'=>'5.2.8', 'current'=>phpversion());
            echo '<dt>'.get_string('phpversion', 'install').'</dt><dd>'.get_string('environmentrequireversion', 'admin', $a).'</dd>';
        }
        if ($curl_fail) {
            echo '<dt>'.get_string('phpextension', 'install', 'cURL').'</dt><dd>'.get_string('environmentrequireinstall', 'admin').'</dd>';
        }
        if ($zip_fail) {
            echo '<dt>'.get_string('phpextension', 'install', 'Zip').'</dt><dd>'.get_string('environmentrequireinstall', 'admin').'</dd>';
        }
        echo '</dl></div>';

        install_print_footer($config, true);
        die;

    } else {
        $config->stage = INSTALL_PATHS;
    }
}



if ($config->stage == INSTALL_PATHS) {
    $paths = array('wwwroot'  => get_string('wwwroot', 'install'),
                   'dirroot'  => get_string('dirroot', 'install'),
                   'dataroot' => get_string('dataroot', 'install'),
                   'admindir' => get_string('admindirname', 'install'));

    $sub = '<dl>';
    foreach ($paths as $path=>$name) {
        $sub .= '<dt>'.$name.'</dt><dd>'.get_string('pathssub'.$path, 'install').'</dd>';
    }
    $sub .= '</dl>';

    install_print_header($config, get_string('paths', 'install'), get_string('pathshead', 'install'), $sub);

    $strwwwroot      = get_string('wwwroot', 'install');
    $strdirroot      = get_string('dirroot', 'install');
    $strdataroot     = get_string('dataroot', 'install');
    $stradmindirname = get_string('admindirname', 'install');

    echo '<div class="userinput">';
    echo '<div class="formrow"><label for="id_wwwroot" class="formlabel">'.$paths['wwwroot'].'</label>';
    echo '<input id="id_wwwroot" name="wwwroot" type="text" value="'.s($CFG->wwwroot).'" disabled="disabled" size="45" class="forminput" />';
    echo '</div>';

    echo '<div class="formrow"><label for="id_dirroot" class="formlabel">'.$paths['dirroot'].'</label>';
    echo '<input id="id_dirroot" name="dirroot" type="text" value="'.s($config->dirroot).'" size="45"class="forminput" />';
    if ($hint_dirroot !== '') {
        echo '<div class="hint">'.$hint_dirroot.'</div>';
    }
    echo '</div>';

    echo '<div class="formrow"><label for="id_dataroot" class="formlabel">'.$paths['dataroot'].'</label>';
    echo '<input id="id_dataroot" name="dataroot" type="text" value="'.s($config->dataroot).'" size="45" class="forminput" />';
    if ($hint_dataroot !== '') {
        echo '<div class="hint">'.$hint_dataroot.'</div>';
    }
    echo '</div>';


    if (file_exists("$CFG->dirroot/admin/environment.xml")) {
        $disabled = 'disabled="disabled"';
    } else {
        $disabled = '';
    }
    echo '<div class="formrow"><label for="id_admin" class="formlabel">'.$paths['admindir'].'</label>';
    echo '<input id="id_admin" name="admin" type="text" value="'.s($config->admin).'" '.$disabled.' size="10" class="forminput" />';
    if ($hint_admindir !== '') {
        echo '<div class="hint">'.$hint_admindir.'</div>';
    }
    echo '</div>';

    echo '</div>';

    install_print_footer($config);
    die;
}



$config->stage = INSTALL_WELCOME;

if ($distro) {
    ob_start();
    include('install/distribution.html');
    $sub = ob_get_clean();

    install_print_header($config, get_string('language'),
                                  get_string('chooselanguagehead', 'install'),
                                  $sub);

} else {
    install_print_header($config, get_string('language'),
                                  get_string('chooselanguagehead', 'install'),
                                  get_string('chooselanguagesub', 'install'));
}

$languages = install_get_list_of_languages();
echo '<div class="userinput">';
echo '<div class="formrow"><label class="formlabel" for="langselect">'.get_string('language').'</label>';
echo '<select id="langselect" name="lang" class="forminput" onchange="this.form.submit()">';
foreach ($languages as $name=>$value) {
    $selected = ($name == $CFG->lang) ? 'selected="selected"' : '';
    echo '<option value="'.s($name).'" '.$selected.'>'.$value.'</option>';
}
echo '</select></div>';
echo '</div>';

install_print_footer($config);
die;

