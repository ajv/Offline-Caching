<?php  // $Id$

function xmldb_quiz_statistics_upgrade($oldversion) {

    global $DB;

    $dbman = $DB->get_manager();

    $result = true;

//===== 1.9.0 upgrade line ======//

    if ($result && $oldversion < 2008072401) {
        //register cron to run every 5 hours.
        $result = $result && $DB->set_field('quiz_report', 'cron', HOURSECS*5, array('name'=>'statistics'));

    /// statistics savepoint reached
        upgrade_plugin_savepoint($result, 2008072401, 'quizreport', 'statistics');
    }

    if ($result && $oldversion < 2008072500) {

    /// Define field s to be added to quiz_question_statistics
        $table = new xmldb_table('quiz_question_statistics');
        $field = new xmldb_field('s', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '0', 'subquestion');

    /// Conditionally launch add field s
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

    /// statistics savepoint reached
        upgrade_plugin_savepoint($result, 2008072500, 'quizreport', 'statistics');
    }

    if ($result && $oldversion < 2008072800) {

    /// Define field maxgrade to be added to quiz_question_statistics
        $table = new xmldb_table('quiz_question_statistics');
        $field = new xmldb_field('maxgrade', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, null, null, null, 'subquestions');

    /// Conditionally launch add field maxgrade
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

    /// statistics savepoint reached
        upgrade_plugin_savepoint($result, 2008072800, 'quizreport', 'statistics');
    }

    if ($result && $oldversion < 2008072801) {

    /// Define field positions to be added to quiz_question_statistics
        $table = new xmldb_table('quiz_question_statistics');
        $field = new xmldb_field('positions', XMLDB_TYPE_TEXT, 'medium', null, null, null, null, 'maxgrade');

    /// Conditionally launch add field positions
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

    /// statistics savepoint reached
        upgrade_plugin_savepoint($result, 2008072801, 'quizreport', 'statistics');
    }

    if ($result && $oldversion < 2008081500) {
    /// Changing type of field maxgrade on table quiz_question_statistics to number
        $table = new xmldb_table('quiz_question_statistics');
        $field = new xmldb_field('maxgrade', XMLDB_TYPE_NUMBER, '12, 7', null, null, null, null, 'subquestions');

    /// Launch change of type for field maxgrade
        $dbman->change_field_type($table, $field);

    /// statistics savepoint reached
        upgrade_plugin_savepoint($result, 2008081500, 'quizreport', 'statistics');
    }

    if ($result && $oldversion < 2008082600) {

    /// Define table quiz_question_response_stats to be created
        $table = new xmldb_table('quiz_question_response_stats');

    /// Adding fields to table quiz_question_response_stats
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('quizstatisticsid', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, null);
        $table->add_field('questionid', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, null);
        $table->add_field('anssubqid', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, null, null, null);
        $table->add_field('response', XMLDB_TYPE_TEXT, 'big', null, null, null, null);
        $table->add_field('rcount', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, null, null, null);
        $table->add_field('credit', XMLDB_TYPE_NUMBER, '15, 5', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, null);

    /// Adding keys to table quiz_question_response_stats
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));

    /// Conditionally launch create table for quiz_question_response_stats
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

    /// statistics savepoint reached
        upgrade_plugin_savepoint($result, 2008082600, 'quizreport', 'statistics');
    }

    if ($result && $oldversion < 2008090500) {
        //delete all cached results first
        $result = $result && $DB->delete_records('quiz_statistics');
        $result = $result && $DB->delete_records('quiz_question_statistics');
        $result = $result && $DB->delete_records('quiz_question_response_stats');
        if ($result){
        /// Define field anssubqid to be dropped from quiz_question_response_stats
            $table = new xmldb_table('quiz_question_response_stats');
            $field = new xmldb_field('anssubqid');

        /// Conditionally launch drop field subqid
            if ($dbman->field_exists($table, $field)) {
                $dbman->drop_field($table, $field);
            }

        /// Define field subqid to be added to quiz_question_response_stats
            $field = new xmldb_field('subqid', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, null, 'questionid');

        /// Conditionally launch add field subqid
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }

        /// Define field aid to be added to quiz_question_response_stats
            $field = new xmldb_field('aid', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, null, 'subqid');

        /// Conditionally launch add field aid
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

    /// statistics savepoint reached
        upgrade_plugin_savepoint($result, 2008090500, 'quizreport', 'statistics');
    }

    if ($result && $oldversion < 2008111000) {
        //delete all cached results first
        $result = $result && $DB->delete_records('quiz_statistics');
        $result = $result && $DB->delete_records('quiz_question_statistics');
        $result = $result && $DB->delete_records('quiz_question_response_stats');
        if ($result){
        /// Define field anssubqid to be dropped from quiz_question_response_stats
            $table = new xmldb_table('quiz_question_statistics');
        /// Define field subqid to be added to quiz_question_response_stats
            $field = new xmldb_field('negcovar', XMLDB_TYPE_INTEGER, '2', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '0', 'effectiveweight');

        /// Conditionally launch add field subqid
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

    /// statistics savepoint reached
        upgrade_plugin_savepoint($result, 2008111000, 'quizreport', 'statistics');
    }

    if ($result && $oldversion < 2008112100) {
        $result = $result && $DB->set_field('quiz_report', 'capability', 'quizreport/statistics:view', array('name'=>'statistics'));

    /// statistics savepoint reached
        upgrade_plugin_savepoint($result, 2008112100, 'quizreport', 'statistics');
    }

    return $result;
}

