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
 * These functions are required very early in the Moodle
 * setup process, before any of the main libraries are
 * loaded.
 * 
 * @package   moodlecore
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/// Debug levels ///
/** no warnings at all */
define ('DEBUG_NONE', 0);
/** E_ERROR | E_PARSE */
define ('DEBUG_MINIMAL', 5);
/** E_ERROR | E_PARSE | E_WARNING | E_NOTICE */
define ('DEBUG_NORMAL', 15);
/** E_ALL without E_STRICT for now, do show recoverable fatal errors */
define ('DEBUG_ALL', 6143);
/** DEBUG_ALL with extra Moodle debug messages - (DEBUG_ALL | 32768) */
define ('DEBUG_DEVELOPER', 38911);

/**
 * Simple class
 *
 * @package   moodlecore
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class object {};

/**
 * Base Moodle Exception class
 *
 * Although this class is defined here, you cannot throw a moodle_exception until
 * after moodlelib.php has been included (which will happen very soon).
 *
 * @package   moodlecore
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_exception extends Exception {
    public $errorcode;
    public $module;
    public $a;
    public $link;
    public $debuginfo;

    /**
     * Constructor
     * @param string $errorcode The name of the string from error.php to print
     * @param string $module name of module
     * @param string $link The url where the user will be prompted to continue. If no url is provided the user will be directed to the site index page.
     * @param object $a Extra words and phrases that might be required in the error string
     * @param string $debuginfo optional debugging information
     */
    function __construct($errorcode, $module='', $link='', $a=NULL, $debuginfo=null) {
        if (empty($module) || $module == 'moodle' || $module == 'core') {
            $module = 'error';
        }

        $this->errorcode = $errorcode;
        $this->module    = $module;
        $this->link      = $link;
        $this->a         = $a;
        $this->debuginfo = $debuginfo;

        $message = get_string($errorcode, $module, $a);

        parent::__construct($message, 0);
    }
}

/**
 * Exception indicating programming error, must be fixed by a programer. For example
 * a core API might throw this type of exception if a plugin calls it incorrectly.
 *
 * @package   moodlecore
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class coding_exception extends moodle_exception {
    /**
     * Constructor
     * @param string $hint short description of problem
     * @param string $debuginfo detailed information how to fix problem
     */
    function __construct($hint, $debuginfo=null) {
        parent::__construct('codingerror', 'debug', '', $hint, $debuginfo);
    }
}

/**
 * An exception that indicates something really weird happended. For example,
 * if you do switch ($context->contextlevel), and have one case for each
 * CONTEXT_... constant. You might throw an invalid_state_exception in the
 * default case, to just in case something really weird is going on, and
 * $context->contextlevel is invalid - rather than ignoring this possibility.
 *
 * @package   moodlecore
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class invalid_state_exception extends moodle_exception {
    /**
     * Constructor
     * @param string $hint short description of problem
     * @param string $debuginfo optional more detailed information
     */
    function __construct($hint, $debuginfo=null) {
        parent::__construct('invalidstatedetected', 'debug', '', $hint, $debuginfo);
    }
}

/**
 * Default exception handler, uncought exceptions are equivalent to using print_error()
 *
 * @param Exception $ex 
 * @param boolean $isupgrade 
 * @param string $plugin 
 * Does not return. Terminates execution.
 */
function default_exception_handler($ex, $isupgrade = false, $plugin = null) {
    global $CFG, $DB, $OUTPUT, $SCRIPT;

    // detect active db transactions, rollback and log as error
    if ($DB && $DB->is_transaction_started()) {
        error_log('Database transaction aborted by exception in ' . $CFG->dirroot . $SCRIPT);
        try {
            // note: transaction blocks should never change current $_SESSION
            $DB->rollback_sql();
        } catch (Exception $ignored) {
        }
    }

    $backtrace = $ex->getTrace();
    $place = array('file'=>$ex->getFile(), 'line'=>$ex->getLine(), 'exception'=>get_class($ex));
    array_unshift($backtrace, $place);

    if ($ex instanceof moodle_exception) {
        $errorcode = $ex->errorcode;
        $module = $ex->module;
        $a = $ex->a;
        $link = $ex->link;
        $debuginfo = $ex->debuginfo;
    } else {
        $errorcode = 'generalexceptionmessage';
        $module = 'error';
        $a = $ex->getMessage();
        $link = '';
        $debuginfo = null;
    }

    list($message, $moreinfourl, $link) = prepare_error_message($errorcode, $module, $link, $a);

    if ($isupgrade) {
        // First log upgrade error
        upgrade_log(UPGRADE_LOG_ERROR, $plugin, 'Exception: ' . get_class($ex), $message, $backtrace);

        // Always turn on debugging - admins need to know what is going on
        $CFG->debug = DEBUG_DEVELOPER;
    }

    if (is_stacktrace_during_output_init($backtrace)) {
        echo bootstrap_renderer::early_error($message, $moreinfourl, $link, $backtrace);
    } else {
        echo $OUTPUT->fatal_error($message, $moreinfourl, $link, $backtrace, $debuginfo);
    }

    exit(1); // General error code
}

/**
 * This function encapsulates the tests for whether an exception was thrown in the middle
 * of initialising the $OUTPUT variable and starting output.
 *
 * If another exception is thrown then, and if we do not take special measures,
 * we would just get a very cryptic message "Exception thrown without a stack
 * frame in Unknown on line 0". That makes debugging very hard, so we do take
 * special measures in default_exception_handler, with the help of this function.
 *
 * @param array $backtrace the stack trace to analyse.
 * @return boolean whether the stack trace is somewhere in output initialisation.
 */
function is_stacktrace_during_output_init($backtrace) {
    $dangerouscode = array(
        array('function' => 'header', 'type' => '->'),
        array('class' => 'bootstrap_renderer'),
    );
    foreach ($backtrace as $stackframe) {
        foreach ($dangerouscode as $pattern) {
            $matches = true;
            foreach ($pattern as $property => $value) {
                if (!isset($stackframe[$property]) || $stackframe[$property] != $value) {
                    $matches = false;
                }
            }
            if ($matches) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Abort execution, displaying an error message.
 *
 * @param string $errorcode The name of the language string containing the error message.
 *      Normally this should be in the error.php lang file.
 * @param string $module The language file to get the error message from.
 * @param string $link The url where the user will be prompted to continue.
 *      If no url is provided the user will be directed to the site index page.
 * @param object $a Extra words and phrases that might be required in the error string
 * @return void terminates script, does not return!
 */
function print_error($errorcode, $module = 'error', $link = '', $a = null) {
    global $OUTPUT, $UNITTEST;

    // Errors in unit test become exceptions, so you can unit test code that might call print_error().
    if (!empty($UNITTEST->running)) {
        throw new moodle_exception($errorcode, $module, $link, $a);
    }

    list($message, $moreinfourl, $link) = prepare_error_message($errorcode, $module, $link, $a);
    echo $OUTPUT->fatal_error($message, $moreinfourl, $link, debug_backtrace());

    exit(1); // General error code
}

/**
 * Private method used by print_error and default_exception_handler.
 * @param $errorcode
 * @param $module
 * @param $link
 * @param $a
 * @return array
 */
function prepare_error_message($errorcode, $module, $link, $a) {
    global $CFG, $DB, $SESSION;

    if ($DB) {
        // If you enable db debugging and exception is thrown, the print footer prints a lot of rubbish
        $DB->set_debug(0);
    }

    // Be careful, no guarantee moodlelib.php is loaded.
    if (empty($module) || $module == 'moodle' || $module == 'core') {
        $module = 'error';
    }
    if (function_exists('get_string')) {
        $message = get_string($errorcode, $module, $a);
        if ($module === 'error' and strpos($message, '[[') === 0) {
            // Search in moodle file if error specified - needed for backwards compatibility
            $message = get_string($errorcode, 'moodle', $a);
        }
    } else {
        $message = $module . '/' . $errorcode;
    }

    // Be careful, no guarantee weblib.php is loaded.
    if (function_exists('clean_text')) {
        $message = clean_text($message);
    } else {
        $message = htmlspecialchars($message);
    }

    if (!empty($CFG->errordocroot)) {
        $errordocroot = $CFG->errordocroot;
    } else if (!empty($CFG->docroot)) {
        $errordocroot = $CFG->docroot;
    } else {
        $errordocroot = 'http://docs.moodle.org';
    }
    if ($module === 'error') {
        $modulelink = 'moodle';
    } else {
        $modulelink = $module;
    }
    $moreinfourl = $errordocroot . '/en/error/' . $modulelink . '/' . $errorcode;

    if (empty($link)) {
        if (!empty($SESSION->fromurl)) {
            $link = $SESSION->fromurl;
            unset($SESSION->fromurl);
        } else {
            $link = $CFG->wwwroot .'/';
        }
    }

    return array($message, $moreinfourl, $link);
}

/**
 * Formats a backtrace ready for output.
 *
 * @param array $callers backtrace array, as returned by debug_backtrace().
 * @param boolean $plaintext if false, generates HTML, if true generates plain text.
 * @return string formatted backtrace, ready for output.
 */
function format_backtrace($callers, $plaintext = false) {
    // do not use $CFG->dirroot because it might not be available in desctructors
    $dirroot = dirname(dirname(__FILE__));
 
    if (empty($callers)) {
        return '';
    }

    $from = $plaintext ? '' : '<ul style="text-align: left">';
    foreach ($callers as $caller) {
        if (!isset($caller['line'])) {
            $caller['line'] = '?'; // probably call_user_func()
        }
        if (!isset($caller['file'])) {
            $caller['file'] = 'unknownfile'; // probably call_user_func()
        }
        $from .= $plaintext ? '* ' : '<li>';
        $from .= 'line ' . $caller['line'] . ' of ' . str_replace($dirroot, '', $caller['file']);
        if (isset($caller['function'])) {
            $from .= ': call to ';
            if (isset($caller['class'])) {
                $from .= $caller['class'] . $caller['type'];
            }
            $from .= $caller['function'] . '()';
        } else if (isset($caller['exception'])) {
            $from .= ': '.$caller['exception'].' thrown';
        }
        $from .= $plaintext ? "\n" : '</li>';
    }
    $from .= $plaintext ? '' : '</ul>';

    return $from;
}

/**
 * This function verifies the sanity of PHP configuration
 * and stops execution if anything critical found.
 */
function setup_validate_php_configuration() {
   // this must be very fast - no slow checks here!!!

   if (ini_get_bool('register_globals')) {
       print_error('globalswarning', 'admin');
   }
   if (ini_get_bool('session.auto_start')) {
       print_error('sessionautostartwarning', 'admin');
   }
   if (ini_get_bool('magic_quotes_runtime')) {
       print_error('fatalmagicquotesruntime', 'admin');
   }
}

/**
 * Initialises $FULLME and friends. Private function. Should only be called from
 * setup.php.
 */
function initialise_fullme() {
    global $CFG, $FULLME, $ME, $SCRIPT, $FULLSCRIPT;

    // Detect common config error.
    if (substr($CFG->wwwroot, -1) == '/') {
        print_error('wwwrootslash', 'error');
    }

    if (CLI_SCRIPT) {
        initialise_fullme_cli();
        return;
    }

    $wwwroot = parse_url($CFG->wwwroot);
    if (!isset($wwwroot['path'])) {
        $wwwroot['path'] = '';
    }
    $wwwroot['path'] .= '/';

    $rurl = setup_get_remote_url();

    // Check that URL is under $CFG->wwwroot.
    if (strpos($rurl['path'], $wwwroot['path']) === 0) {
        $SCRIPT = substr($rurl['path'], strlen($wwwroot['path'])-1);
    } else {
        // Probably some weird external script
        $SCRIPT = $FULLSCRIPT = $FULLME = $ME = null;
        return;
    }

    // $CFG->sslproxy specifies if external SSL appliance is used
    // (That is, the Moodle server uses http, with an external box translating everything to https).
    if (empty($CFG->sslproxy)) {
        if ($rurl['scheme'] == 'http' and $wwwroot['scheme'] == 'https') {
            print_error('sslonlyaccess', 'error');
        }
    }

    // $CFG->reverseproxy specifies if reverse proxy server used.
    // Used in load balancing scenarios.
    // Do not abuse this to try to solve lan/wan access problems!!!!!
    if (empty($CFG->reverseproxy)) {
        if (($rurl['host'] != $wwwroot['host']) or
                (!empty($wwwroot['port']) and $rurl['port'] != $wwwroot['port'])) {
            print_error('wwwrootmismatch', 'error', '', $CFG->wwwroot);
        }
    }

    // hopefully this will stop all those "clever" admins trying to set up moodle
    // with two different addresses in intranet and Internet
    if (!empty($CFG->reverseproxy) && $rurl['host'] == $wwwroot['host']) {
        print_error('reverseproxyabused', 'error');
    }

    $hostandport = $rurl['scheme'] . '://' . $wwwroot['host'];
    if (!empty($wwwroot['port'])) {
        $hostandport .= ':'.$wwwroot['port'];
    }

    $FULLSCRIPT = $hostandport . $rurl['path'];
    $FULLME = $hostandport . $rurl['fullpath'];
    $ME = $rurl['fullpath'];
    $rurl['path'] = $rurl['fullpath'];
}

/**
 * Initialises $FULLME and friends for command line scripts.
 * This is a private method for use by initialise_fullme.
 */
function initialise_fullme_cli() {
    global $CFG, $FULLME, $ME, $SCRIPT, $FULLSCRIPT;

    // Urls do not make much sense in CLI scripts
    $backtrace = debug_backtrace();
    $topfile = array_pop($backtrace);
    $topfile = realpath($topfile['file']);
    $dirroot = realpath($CFG->dirroot);

    if (strpos($topfile, $dirroot) !== 0) {
        // Probably some weird external script
        $SCRIPT = $FULLSCRIPT = $FULLME = $ME = null;
    } else {
        $relativefile = substr($topfile, strlen($dirroot));
        $relativefile = str_replace('\\', '/', $relativefile); // Win fix
        $SCRIPT = $FULLSCRIPT = $relativefile;
        $FULLME = $ME = null;
    }
}

/**
 * Get the URL that PHP/the web server thinks it is serving. Private function
 * used by initialise_fullme. In your code, use $PAGE->url, $SCRIPT, etc.
 * @return array in the same format that parse_url returns, with the addition of
 *      a 'fullpath' element, which includes any slasharguments path.
 */
function setup_get_remote_url() {
    $rurl = array();
    list($rurl['host']) = explode(':', $_SERVER['HTTP_HOST']);
    $rurl['port'] = $_SERVER['SERVER_PORT'];
    $rurl['path'] = $_SERVER['SCRIPT_NAME']; // Script path without slash arguments

    if (stripos($_SERVER['SERVER_SOFTWARE'], 'apache') !== false) {
        //Apache server
        $rurl['scheme']   = empty($_SERVER['HTTPS']) ? 'http' : 'https';
        $rurl['fullpath'] = $_SERVER['REQUEST_URI']; // TODO: verify this is always properly encoded

    } else if (stripos($_SERVER['SERVER_SOFTWARE'], 'lighttpd') !== false) {
        //lighttpd
        $rurl['scheme']   = empty($_SERVER['HTTPS']) ? 'http' : 'https';
        $rurl['fullpath'] = $_SERVER['REQUEST_URI']; // TODO: verify this is always properly encoded

    } else if (stripos($_SERVER['SERVER_SOFTWARE'], 'iis') !== false) {
        //IIS
        $rurl['scheme']   = ($_SERVER['HTTPS'] == 'off') ? 'http' : 'https';
        $rurl['fullpath'] = $_SERVER['SCRIPT_NAME'];

        // NOTE: ignore PATH_INFO because it is incorrectly encoded using 8bit filesystem legacy encoding in IIS
        //       since 2.0 we rely on iis rewrite extenssion like Helicon ISAPI_rewrite
        //       example rule: RewriteRule ^([^\?]+?\.php)(\/.+)$ $1\?file=$2 [QSA]

        if ($_SERVER['QUERY_STRING'] != '') {
            $rurl['fullpath'] .= '?'.$_SERVER['QUERY_STRING'];
        }
        $_SERVER['REQUEST_URI'] = $rurl['fullpath']; // extra IIS compatibility

    } else {
        throw new moodle_exception('unsupportedwebserver', 'error', '', $_SERVER['SERVER_SOFTWARE']);
    }
    return $rurl;
}

/**
 * Initializes our performance info early.
 *
 * Pairs up with get_performance_info() which is actually
 * in moodlelib.php. This function is here so that we can
 * call it before all the libs are pulled in.
 *
 * @uses $PERF
 */
function init_performance_info() {

    global $PERF, $CFG, $USER;

    $PERF = new object();
    $PERF->logwrites = 0;
    if (function_exists('microtime')) {
        $PERF->starttime = microtime();
    }
    if (function_exists('memory_get_usage')) {
        $PERF->startmemory = memory_get_usage();
    }
    if (function_exists('posix_times')) {
        $PERF->startposixtimes = posix_times();
    }
    if (function_exists('apd_set_pprof_trace')) {
        // APD profiling
        if ($USER->id > 0 && $CFG->perfdebug >= 15) {
            $tempdir = $CFG->dataroot . '/temp/profile/' . $USER->id;
            mkdir($tempdir);
            apd_set_pprof_trace($tempdir);
            $PERF->profiling = true;
        }
    }
}

/**
 * Indicates whether we are in the middle of the initial Moodle install.
 *
 * Very occasionally it is necessary avoid running certain bits of code before the
 * Moodle installation has completed. The installed flag is set in admin/index.php
 * after Moodle core and all the plugins have been installed, but just before
 * the person doing the initial install is asked to choose the admin password.
 *
 * @return boolean true if the initial install is not complete.
 */
function during_initial_install() {
    global $CFG;
    return empty($CFG->rolesactive);
}

/**
 * Function to raise the memory limit to a new value.
 * Will respect the memory limit if it is higher, thus allowing
 * settings in php.ini, apache conf or command line switches
 * to override it
 *
 * The memory limit should be expressed with a string (eg:'64M')
 *
 * @param string $newlimit the new memory limit
 * @return bool
 */
function raise_memory_limit($newlimit) {

    if (empty($newlimit)) {
        return false;
    }

    $cur = @ini_get('memory_limit');
    if (empty($cur)) {
        // if php is compiled without --enable-memory-limits
        // apparently memory_limit is set to ''
        $cur=0;
    } else {
        if ($cur == -1){
            return true; // unlimited mem!
        }
      $cur = get_real_size($cur);
    }

    $new = get_real_size($newlimit);
    if ($new > $cur) {
        ini_set('memory_limit', $newlimit);
        return true;
    }
    return false;
}

/**
 * Function to reduce the memory limit to a new value.
 * Will respect the memory limit if it is lower, thus allowing
 * settings in php.ini, apache conf or command line switches
 * to override it
 *
 * The memory limit should be expressed with a string (eg:'64M')
 *
 * @param string $newlimit the new memory limit
 * @return bool
 */
function reduce_memory_limit($newlimit) {
    if (empty($newlimit)) {
        return false;
    }
    $cur = @ini_get('memory_limit');
    if (empty($cur)) {
        // if php is compiled without --enable-memory-limits
        // apparently memory_limit is set to ''
        $cur=0;
    } else {
        if ($cur == -1){
            return true; // unlimited mem!
        }
        $cur = get_real_size($cur);
    }

    $new = get_real_size($newlimit);
    // -1 is smaller, but it means unlimited
    if ($new < $cur && $new != -1) {
        ini_set('memory_limit', $newlimit);
        return true;
    }
    return false;
}

/**
 * Converts numbers like 10M into bytes.
 *
 * @param mixed $size The size to be converted
 * @return mixed
 */
function get_real_size($size=0) {
    if (!$size) {
        return 0;
    }
    $scan = array();
    $scan['MB'] = 1048576;
    $scan['Mb'] = 1048576;
    $scan['M'] = 1048576;
    $scan['m'] = 1048576;
    $scan['KB'] = 1024;
    $scan['Kb'] = 1024;
    $scan['K'] = 1024;
    $scan['k'] = 1024;

    while (list($key) = each($scan)) {
        if ((strlen($size)>strlen($key))&&(substr($size, strlen($size) - strlen($key))==$key)) {
            $size = substr($size, 0, strlen($size) - strlen($key)) * $scan[$key];
            break;
        }
    }
    return $size;
}

/**
 * Create a directory.
 *
 * @uses $CFG
 * @param string $directory  a string of directory names under $CFG->dataroot eg  stuff/assignment/1
 * param bool $shownotices If true then notification messages will be printed out on error.
 * @return string|false Returns full path to directory if successful, false if not
 */
function make_upload_directory($directory, $shownotices=true) {

    global $CFG;

    $currdir = $CFG->dataroot;

    umask(0000);

    if (!file_exists($currdir)) {
        if (!mkdir($currdir, $CFG->directorypermissions) or !is_writable($currdir)) {
            if ($shownotices) {
                echo '<div class="notifyproblem" align="center">ERROR: You need to create the directory '.
                     $currdir .' with web server write access</div>'."<br />\n";
            }
            return false;
        }
    }

    // Make sure a .htaccess file is here, JUST IN CASE the files area is in the open
    if (!file_exists($currdir.'/.htaccess')) {
        if ($handle = fopen($currdir.'/.htaccess', 'w')) {   // For safety
            @fwrite($handle, "deny from all\r\nAllowOverride None\r\nNote: this file is broken intentionally, we do not want anybody to undo it in subdirectory!\r\n");
            @fclose($handle);
        }
    }

    $dirarray = explode('/', $directory);

    foreach ($dirarray as $dir) {
        $currdir = $currdir .'/'. $dir;
        if (! file_exists($currdir)) {
            if (! mkdir($currdir, $CFG->directorypermissions)) {
                if ($shownotices) {
                    echo '<div class="notifyproblem" align="center">ERROR: Could not find or create a directory ('.
                         $currdir .')</div>'."<br />\n";
                }
                return false;
            }
            //@chmod($currdir, $CFG->directorypermissions);  // Just in case mkdir didn't do it
        }
    }

    return $currdir;
}

function init_memcached() {
    global $CFG, $MCACHE;

    include_once($CFG->libdir . '/memcached.class.php');
    $MCACHE = new memcached;
    if ($MCACHE->status()) {
        return true;
    }
    unset($MCACHE);
    return false;
}

function init_eaccelerator() {
    global $CFG, $MCACHE;

    include_once($CFG->libdir . '/eaccelerator.class.php');
    $MCACHE = new eaccelerator;
    if ($MCACHE->status()) {
        return true;
    }
    unset($MCACHE);
    return false;
}


/**
 * This class solves the problem of how to initialise $OUTPUT.
 *
 * The problem is caused be two factors
 * <ol>
 * <li>On the one hand, we cannot be sure when output will start. In particular,
 * an error, which needs to be displayed, could br thrown at any time.</li>
 * <li>On the other hand, we cannot be sure when we will have all the information
 * necessary to correctly initialise $OUTPUT. $OUTPUT depends on the theme, which
 * (potentially) depends on the current course, course categories, and logged in user.
 * It also depends on whether the current page requires HTTPS.</li>
 * </ol>
 *
 * So, it is hard to find a single natural place during Moodle script execution,
 * which we can guarantee is the right time to initialise $OUTPUT. Instead we
 * adopt the following strategy
 * <ol>
 * <li>We will initialise $OUTPUT the first time it is used.</li>
 * <li>If, after $OUTPUT has been initialised, the script tries to change something
 * that $OUPTUT depends on, we throw an exception making it clear that the script
 * did something wrong.
 * </ol>
 *
 * The only problem with that is, how do we initialise $OUTPUT on first use if,
 * it is going to be used like $OUTPUT->somthing(...)? Well that is where this
 * class comes in. Initially, we set up $OUTPUT = new bootstrap_renderer(). Then,
 * when any method is called on that object, we initialise $OUTPUT, and pass the call on.
 *
 * Note that this class is used before lib/outputlib.php has been loaded, so we
 * must be careful referring to classes/funtions from there, they may not be
 * defined yet, and we must avoid fatal errors.
 *
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since     Moodle 2.0
 */
class bootstrap_renderer {
    /**
     * Handles re-entrancy. Without this, errors or debugging output that occur
     * during the initialisation of $OUTPUT, cause infinite recursion.
     * @var boolean
     */
    protected $initialising = false;

    /**
     * Have we started output yet?
     * @return boolean true if the header has been printed.
     */
    public function has_started() {
        return false;
    }

    public function __call($method, $arguments) {
        global $OUTPUT, $PAGE;

        $recursing = false;
        if ($method == 'notification') {
            // Catch infinite recursion cuased by debugging output during print_header.
            $backtrace = debug_backtrace();
            array_shift($backtrace);
            array_shift($backtrace);
            $recursing = is_stacktrace_during_output_init($backtrace);
        }

        // If lib/outputlib.php has been loaded, call it.
        if (!empty($PAGE) && !$recursing) {
            $PAGE->initialise_theme_and_output();
            return call_user_func_array(array($OUTPUT, $method), $arguments);
        }

        $this->initialising = true;
        // Too soon to initialise $OUTPUT, provide a couple of key methods.
        $earlymethods = array(
            'fatal_error' => 'early_error',
            'notification' => 'early_notification',
        );
        if (array_key_exists($method, $earlymethods)) {
            return call_user_func_array(array('bootstrap_renderer', $earlymethods[$method]), $arguments);
        }

        throw new coding_exception('Attempt to start output before enough information is known to initialise the theme.');
    }

    /**
     * This function should only be called by this class, or by 
     * @return unknown_type
     */
    public static function early_error($message, $moreinfourl, $link, $backtrace,
                $debuginfo = null, $showerrordebugwarning = false) {
        global $CFG;

        // In the name of protocol correctness, monitoring and performance
        // profiling, set the appropriate error headers for machine comsumption
        if (isset($_SERVER['SERVER_PROTOCOL'])) {
            // Avoid it with cron.php. Note that we assume it's HTTP/1.x
            @header($_SERVER['SERVER_PROTOCOL'] . ' 503 Service Unavailable');
        }

        // better disable any caching
        @header('Content-Type: text/html; charset=utf-8');
        @header('Cache-Control: no-store, no-cache, must-revalidate');
        @header('Cache-Control: post-check=0, pre-check=0', false);
        @header('Pragma: no-cache');
        @header('Expires: Mon, 20 Aug 1969 09:23:00 GMT');
        @header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');

        if (function_exists('get_string') && function_exists('get_html_lang')) {
            $htmllang = get_html_lang();
            $strerror = get_string('error');
        } else {
            $htmllang = '';
            $strerror = 'Error';
        }

        $output = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" ' . $htmllang . '>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>' . $strerror . '</title>
</head><body>
<div style="margin-top: 6em; margin-left:auto; margin-right:auto; color:#990000; text-align:center; font-size:large; border-width:1px;
    border-color:black; background-color:#ffffee; border-style:solid; border-radius: 20px; border-collapse: collapse;
    width: 80%; -moz-border-radius: 20px; padding: 15px">
' . $message . '
</div>';
        if (!empty($CFG->debug) && $CFG->debug >= DEBUG_DEVELOPER) {
            if (!empty($debuginfo)) {
                $output .= '<div class="notifytiny">' . $debuginfo . '</div>';
            }
            if (!empty($backtrace)) {
                $output .= '<div class="notifytiny">Stack trace: ' . format_backtrace($backtrace, false) . '</div>';
            }
        }
    
        $output .= '</body></html>';
        return $output;
    }

    public static function early_notification($message, $classes = 'notifyproblem') {
        return '<div class="' . $classes . '">' . $message . '</div>';
    }
}
