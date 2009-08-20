<?php  //$Id$

// This file keeps track of upgrades to
// the data module
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

function xmldb_data_upgrade($oldversion) {
    global $CFG, $DB, $OUTPUT;

    $dbman = $DB->get_manager();
    $result = true;

//===== 1.9.0 upgrade line ======//

    if ($result && $oldversion < 2007101512) {
    /// Launch add field asearchtemplate again if does not exists yet - reported on several sites

        $table = new xmldb_table('data');
        $field = new xmldb_field('asearchtemplate', XMLDB_TYPE_TEXT, 'small', null, null, null, null, 'jstemplate');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint($result, 2007101512, 'data');
    }

    if ($result && $oldversion < 2007101513) {
        // Upgrade all the data->notification currently being
        // NULL to 0
        $sql = "UPDATE {data} SET notification=0 WHERE notification IS NULL";
        $result = $DB->execute($sql);

        $table = new xmldb_table('data');
        $field = new xmldb_field('notification', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '0', 'editany');
        // First step, Set NOT NULL
        $dbman->change_field_notnull($table, $field);
        // Second step, Set default to 0
        $dbman->change_field_default($table, $field);
        upgrade_mod_savepoint($result, 2007101513, 'data');
    }

    if ($result && $oldversion < 2008081400) {
        if ($rs = $DB->get_recordset('data')) {
            $pattern = '/\#\#delete\#\#(\s+)\#\#approve\#\#/';
            $replacement = '##delete##$1##approve##$1##export##';
            foreach ($rs as $data) {
                $data->listtemplate = preg_replace($pattern, $replacement, $data->listtemplate);
                $data->singletemplate = preg_replace($pattern, $replacement, $data->singletemplate);
                $DB->update_record('data', $data);
            }
            $rs->close();
        }
        upgrade_mod_savepoint($result, 2008081400, 'data');
    }

    if ($result && $oldversion < 2008091400) {

        /////////////////////////////////////
        /// new file storage upgrade code ///
        /////////////////////////////////////

        $fs = get_file_storage();

        $empty = $DB->sql_empty(); // silly oracle empty string handling workaround

        $sqlfrom = "FROM {data_content} c
                    JOIN {data_fields} f     ON f.id = c.fieldid
                    JOIN {data_records} r    ON r.id = c.recordid
                    JOIN {data} d            ON d.id = r.dataid
                    JOIN {modules} m         ON m.name = 'data'
                    JOIN {course_modules} cm ON (cm.module = m.id AND cm.instance = d.id)
                   WHERE c.content <> '$empty' AND c.content IS NOT NULL
                         AND (f.type = 'file' OR f.type = 'picture')";

        $count = $DB->count_records_sql("SELECT COUNT('x') $sqlfrom");

        if ($rs = $DB->get_recordset_sql("SELECT c.id, f.type, r.dataid, c.recordid, f.id AS fieldid, r.userid, c.content, c.content1, d.course, r.userid, cm.id AS cmid $sqlfrom ORDER BY d.course, d.id")) {

            $pbar = new progress_bar('migratedatafiles', 500, true);

            $i = 0;
            foreach ($rs as $content) {
                $i++;
                upgrade_set_timeout(60); // set up timeout, may also abort execution
                $pbar->update($i, $count, "Migrating data entries - $i/$count.");

                $filepath = "$CFG->dataroot/$content->course/$CFG->moddata/data/$content->dataid/$content->fieldid/$content->recordid/$content->content";
                $context = get_context_instance(CONTEXT_MODULE, $content->cmid);

                if (!file_exists($filepath)) {
                    continue;
                }

                $filearea = 'data_content';
                $oldfilename = $content->content;
                $filename    = clean_param($oldfilename, PARAM_FILE);
                if ($filename === '') {
                    continue;
                }
                if (!$fs->file_exists($context->id, $filearea, $content->id, '/', $filename)) {
                    $file_record = array('contextid'=>$context->id, 'filearea'=>$filearea, 'itemid'=>$content->id, 'filepath'=>'/', 'filename'=>$filename, 'userid'=>$content->userid);
                    if ($fs->create_file_from_pathname($file_record, $filepath)) {
                        unlink($filepath);
                        if ($oldfilename !== $filename) {
                            // update filename if needed
                            $DB->set_field('data_content', 'content', $filename, array('id'=>$content->id));
                        }
                        if ($content->type == 'picture') {
                            // migrate thumb
                            $filepath = "$CFG->dataroot/$content->course/$CFG->moddata/data/$content->dataid/$content->fieldid/$content->recordid/thumb/$content->content";
                            if (!$fs->file_exists($context->id, $filearea, $content->id, '/', 'thumb_'.$filename)) {
                                $file_record['filename'] = 'thumb_'.$file_record['filename'];
                                $fs->create_file_from_pathname($file_record, $filepath);
                                unlink($filepath);
                            }
                        }
                    }
                }

                // remove dirs if empty
                @rmdir("$CFG->dataroot/$content->course/$CFG->moddata/data/$content->dataid/$content->fieldid/$content->recordid/thumb");
                @rmdir("$CFG->dataroot/$content->course/$CFG->moddata/data/$content->dataid/$content->fieldid/$content->recordid");
                @rmdir("$CFG->dataroot/$content->course/$CFG->moddata/data/$content->dataid/$content->fieldid");
                @rmdir("$CFG->dataroot/$content->course/$CFG->moddata/data/$content->dataid");
                @rmdir("$CFG->dataroot/$content->course/$CFG->moddata/data");
                @rmdir("$CFG->dataroot/$content->course/$CFG->moddata");
            }
            $rs->close();
        }
        upgrade_mod_savepoint($result, 2008091400, 'data');
    }

    if ($result && $oldversion < 2008112700) {
        if (!get_config('data', 'requiredentriesfixflag')) {
            $databases = $DB->get_records_sql("SELECT d.*, c.fullname
                                                 FROM {data} d, {course} c
                                                WHERE d.course = c.id
                                                      AND (d.requiredentries > 0 OR d.requiredentriestoview > 0)
                                             ORDER BY c.fullname, d.name");
            if (!empty($databases)) {
                $a = new object();
                $a->text = '';
                foreach($databases as $database) {
                    $a->text .= $database->fullname." - " .$database->name. " (course id: ".$database->course." - database id: ".$database->id.")<br/>";
                }
                //TODO: MDL-17427 send this info to "upgrade log" which will be implemented in 2.0
                echo $OUTPUT->notification(get_string('requiredentrieschanged', 'admin', $a));
            }
        }
        unset_config('requiredentriesfixflag', 'data'); // remove old flag
        upgrade_mod_savepoint($result, 2008112700, 'data');
    }

    if ($result && $oldversion < 2009042000) {

    /// Define field introformat to be added to data
        $table = new xmldb_table('data');
        $field = new xmldb_field('introformat', XMLDB_TYPE_INTEGER, '4', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '0', 'intro');

    /// Launch add field introformat
        $dbman->add_field($table, $field);

    /// data savepoint reached
        upgrade_mod_savepoint($result, 2009042000, 'data');
    }

    return $result;
}

?>
