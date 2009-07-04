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
 * This library contains all the Data Manipulation Language (DML) functions
 * used to interact with the DB
 *
 * This library contains all the Data Manipulation Language (DML) functions
 * used to interact with the DB. All the dunctions in this library must be
 * generic and work against the major number of RDBMS possible. This is the
 * list of currently supported and tested DBs: mysql, postresql, mssql, oracle

 * This library is automatically included by Moodle core so you never need to
 * include it yourself.

 * For more info about the functions available in this library, please visit:
 *     http://docs.moodle.org/en/DML_functions
 * (feel free to modify, improve and document such page, thanks!)
 *
 * @package    moodlecore
 * @subpackage DML
 * @copyright  2008 Petr Skoda (http://skodak.org)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Require the essential
require_once($CFG->libdir.'/dml/moodle_database.php');

/** Indicates some record is required to exist */
define('MUST_EXIST', 2);

/**
 * DML exception class, use instead of error() in dml code.
 */
class dml_exception extends moodle_exception {
    /**
     * @param string $errorcode
     * @param string $a
     * @param string $debuginfo
     */
    function __construct($errorcode, $a=NULL, $debuginfo=null) {
        parent::__construct($errorcode, '', '', $a, $debuginfo);
    }
}

/**
 * DML db connection exception - triggered if database not accessible.
 */
class dml_connection_exception extends dml_exception {
    /**
     * Constructor
     * @param string $error
     */
    function __construct($error) {
        $errorinfo = '<em>'.s($error).'</em>';
        parent::__construct('dbconnectionfailed', NULL, $errorinfo);
    }
}

/**
 * DML read exception - triggered by SQL syntax errors, missing tables, etc.
 */
class dml_read_exception extends dml_exception {
    /** @var string */
    public $error;
    /** @var string */
    public $sql;
    /** @var array */
    public $params;
    
    /**
     * Constructor
     * @param string $error
     * @param string $sql
     * @param array $params
     */
    function __construct($error, $sql=null, array $params=null) {
        $this->error  = $error;
        $this->sql    = $sql;
        $this->params = $params;
        $errorinfo = s($error).'<br /><br />'.s($sql).'<br />['.s(var_export($params, true)).']';
        parent::__construct('dmlreadexception', NULL, $errorinfo);
    }
}

/**
 * Caused by multiple records found in get_record() call.
 */
class dml_multiple_records_exception extends dml_exception {
    /** @var string */
    public $sql;
    /** @var array */
    public $params;
    
    /**
     * Constructor
     * @param string $table table name if known, '' if unknown
     * @param string $sql
     * @param array $params
     */
    function __construct($sql='', array $params=null) {
        $errorinfo = s($sql).'<br />['.s(var_export($params, true)).']';
        parent::__construct('multiplerecordsfound', null, $errorinfo);
    }
}

/**
 * Caused by missing record that is required for normal operation.
 */
class dml_missing_record_exception extends dml_exception {
    /** @var string */
    public $table;
    /** @var string */
    public $sql;
    /** @var array */
    public $params;
    
    /**
     * Constructor
     * @param string $table table name if known, '' if unknown
     * @param string $sql
     * @param array $params
     */
    function __construct($tablename, $sql='', array $params=null) {
        if (empty($tablename)) {
            $tablename = null;
        }
        $this->tablename = $tablename;
        $this->sql       = $sql;
        $this->params    = $params;
        
        switch ($tablename) {
            case null:
                $errcode = 'invalidrecordunknown';
                break;
            case 'course':
                $errocode = empty($sql) ? 'invalidcourseid' : 'invalidrecord';
                break;
            case 'course_module':
                $errocode = 'invalidcoursemodule';
                break;
            case 'user':
                $errcode = 'invaliduser';
                break;
            default:
                $errcode = 'invalidrecord';
                break;
        }
        $errorinfo = s($sql).'<br />['.s(var_export($params, true)).']';
        parent::__construct($errcode, $tablename, $errorinfo);
    }
}

/**
 * DML write exception - triggered by SQL syntax errors, missing tables, etc.
 */
class dml_write_exception extends dml_exception {
    /** @var string */
    public $error;
    /** @var string */
    public $sql;
    /** @var array */
    public $params;

    /**
     * Constructor
     * @param string $error
     * @param string $sql
     * @param array $params
     */
    function __construct($error, $sql=null, array $params=null) {
        $this->error  = $error;
        $this->sql    = $sql;
        $this->params = $params;
        $errorinfo = s($error).'<br /><br />'.s($sql).'<br />['.s(var_export($params, true)).']';
        parent::__construct('dmlwriteexception', NULL, $errorinfo);
    }
}

/**
 * Sets up global $DB moodle_database instance
 *
 * @global object
 * @global object
 * @return void
 */
function setup_DB() {
    global $CFG, $DB;

    if (isset($DB)) {
        return;
    }

    if (!isset($CFG->dbuser)) {
        $CFG->dbuser = '';
    }

    if (!isset($CFG->dbpass)) {
        $CFG->dbpass = '';
    }

    if (!isset($CFG->dbname)) {
        $CFG->dbname = '';
    }

    if (!isset($CFG->dblibrary)) {
        switch ($CFG->dbtype) {
            case 'postgres7' :
                $CFG->dbtype = 'pgsql';
                // continue, no break here
            case 'pgsql' :
                $CFG->dblibrary = 'native';
                break;

            case 'mysql' :
                if (!extension_loaded('mysqli')) {
                    $CFG->dblibrary = 'adodb';
                    break;
                }
                $CFG->dbtype = 'mysqli';
                // continue, no break here
            case 'mysqli' :
                $CFG->dblibrary = 'native';
                break;

            default:
                // the rest of drivers is not converted yet - keep adodb for now
                $CFG->dblibrary = 'adodb';
        }
    }

    if (!isset($CFG->dboptions)) {
        $CFG->dboptions = array();
    }

    if (isset($CFG->dbpersist)) {
        $CFG->dboptions['dbpersist'] = $CFG->dbpersist;
    }

    if (!$DB = moodle_database::get_driver_instance($CFG->dbtype, $CFG->dblibrary)) {
        throw new dml_exception('dbdriverproblem', "Unknown driver $CFG->dblibrary/$CFG->dbtype");
    }

    try {
        $DB->connect($CFG->dbhost, $CFG->dbuser, $CFG->dbpass, $CFG->dbname, $CFG->prefix, $CFG->dboptions);
    } catch (moodle_exception $e) {
        if (empty($CFG->noemailever) and !empty($CFG->emailconnectionerrorsto)) {
            if (file_exists($CFG->dataroot.'/emailcount')){
                $fp = @fopen($CFG->dataroot.'/emailcount', 'r');
                $content = @fread($fp, 24);
                @fclose($fp);
                if((time() - (int)$content) > 600){
                    @mail($CFG->emailconnectionerrorsto,
                        'WARNING: Database connection error: '.$CFG->wwwroot,
                        'Connection error: '.$CFG->wwwroot);
                    $fp = @fopen($CFG->dataroot.'/emailcount', 'w');
                    @fwrite($fp, time());
                }
            } else {
               @mail($CFG->emailconnectionerrorsto,
                    'WARNING: Database connection error: '.$CFG->wwwroot,
                    'Connection error: '.$CFG->wwwroot);
               $fp = @fopen($CFG->dataroot.'/emailcount', 'w');
               @fwrite($fp, time());
            }
        }
        // rethrow the exception
        throw $e;
    }

    $CFG->dbfamily = $DB->get_dbfamily(); // TODO: BC only for now

    return true;
}
