<?php  //$Id$

// This file keeps track of upgrades to
// the authorize enrol plugin
//
// Sometimes, changes between versions involve
// alterations to database structures and other
// major things that may break installations.
//
// The upgrade function in this file will attempt
// to perform all the necessary actions to upgrade
// your older installtion to the current version.
//
// If there's something it cannot do itself, it
// will tell you what you need to do.
//
// The commands in here will all be database-neutral,
// using the methods of database_manager class
//
// Please do not forget to use upgrade_set_timeout()
// before any action that may take longer time to finish.

function xmldb_enrol_authorize_upgrade($oldversion) {
    global $CFG, $DB, $OUTPUT;

    $dbman = $DB->get_manager();
    $result = true;

    //===== 1.9.0 upgrade line ======//

    if ($result && $oldversion < 2008020500 && is_enabled_enrol('authorize')) {
        require_once($CFG->dirroot.'/enrol/authorize/localfuncs.php');
        if (!check_curl_available()) {
            echo $OUTPUT->notification("You are using the authorize.net enrolment plugin for payment handling but cUrl is not available.
                    PHP must be compiled with cURL+SSL support (--with-curl --with-openssl)");
        }

        /// authorize savepoint reached
        upgrade_plugin_savepoint($result, 2008020500, 'enrol', 'authorize');
    }

    if ($result && $oldversion < 2008092700) {
        /// enrol_authorize.transid
        /// Define index transid (not unique) to be dropped form enrol_authorize
        $table = new xmldb_table('enrol_authorize');
        $index = new xmldb_index('transid', XMLDB_INDEX_NOTUNIQUE, array('transid'));
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }

        /// Changing precision of field transid on table enrol_authorize to (20)
        $table = new xmldb_table('enrol_authorize');
        $field = new xmldb_field('transid', XMLDB_TYPE_INTEGER, '20', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '0', 'userid');
        $dbman->change_field_precision($table, $field);

        /// Launch add index transid again
        $table = new xmldb_table('enrol_authorize');
        $index = new xmldb_index('transid', XMLDB_INDEX_NOTUNIQUE, array('transid'));
        $dbman->add_index($table, $index);

        /// enrol_authorize_refunds.transid
        /// Define index transid (not unique) to be dropped form enrol_authorize_refunds
        $table = new xmldb_table('enrol_authorize_refunds');
        $index = new xmldb_index('transid', XMLDB_INDEX_NOTUNIQUE, array('transid'));
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }

        /// Changing precision of field transid on table enrol_authorize_refunds to (20)
        $table = new xmldb_table('enrol_authorize_refunds');
        $field = new xmldb_field('transid', XMLDB_TYPE_INTEGER, '20', XMLDB_UNSIGNED, null, null, '0', 'amount');
        $dbman->change_field_precision($table, $field);

        /// Launch add index transid again
        $table = new xmldb_table('enrol_authorize_refunds');
        $index = new xmldb_index('transid', XMLDB_INDEX_NOTUNIQUE, array('transid'));
        $dbman->add_index($table, $index);

        /// authorize savepoint reached
        upgrade_plugin_savepoint($result, 2008092700, 'enrol', 'authorize');
    }

    /// Dropping all enums/check contraints from core. MDL-18577
    if ($result && $oldversion < 2009042700) {

    /// Changing list of values (enum) of field paymentmethod on table enrol_authorize to none
        $table = new xmldb_table('enrol_authorize');
        $field = new xmldb_field('paymentmethod', XMLDB_TYPE_CHAR, '6', null, XMLDB_NOTNULL, null, 'cc', 'id');

    /// Launch change of list of values for field paymentmethod
        $dbman->drop_enum_from_field($table, $field);

        /// authorize savepoint reached
        upgrade_plugin_savepoint($result, 2009042700, 'enrol', 'authorize');
    }

    return $result;
}

?>
