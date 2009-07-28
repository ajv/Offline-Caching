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
 * Core file storage class definition.
 *
 * @package    moodlecore
 * @subpackage file-storage
 * @copyright  2008 Petr Skoda (http://skodak.org)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once("$CFG->libdir/file/stored_file.php");

/**
 * File storage class used for low level access to stored files.
 * Only owner of file area may use this class to access own files,
 * for example only code in mod/assignment/* may access assignment
 * attachments. When core needs to access files of modules it has
 * to use file_browser class instead.
 */
class file_storage {
    /** Directory with file contents */
    private $filedir;
    /** Contents of deleted files not needed any more */
    private $trashdir;
    /** Permissions for new directories */
    private $dirpermissions;
    /** Permissions for new files */
    private $filepermissions;
    /**
     * Contructor
     * @param string $filedir full path to pool directory
     */
    public function __construct($filedir, $trashdir, $dirpermissions, $filepermissions) {
        $this->filedir         = $filedir;
        $this->trashdir        = $trashdir;
        $this->dirpermissions  = $dirpermissions;
        $this->filepermissions = $filepermissions;

        // make sure the file pool directory exists
        if (!is_dir($this->filedir)) {
            if (!mkdir($this->filedir, $this->dirpermissions, true)) {
                throw new file_exception('storedfilecannotcreatefiledirs'); // permission trouble
            }
            // place warning file in file pool root
            if (!file_exists($this->filedir.'/warning.txt')) {
                file_put_contents($this->filedir.'/warning.txt',
                                  'This directory contains the content of uploaded files and is controlled by Moodle code. Do not manually move, change or rename any of the files and subdirectories here.');
            }
        }
        // make sure the file pool directory exists
        if (!is_dir($this->trashdir)) {
            if (!mkdir($this->trashdir, $this->dirpermissions, true)) {
                throw new file_exception('storedfilecannotcreatefiledirs'); // permission trouble
            }
        }
    }

    /**
     * Returns location of filedir (file pool)
     * Do not use, this method is intended for stored_file instances only.
     * @return string pathname
     */
    public function get_filedir() {
        return $this->filedir;
    }

    /**
     * Calculates sha1 hash of unique full path name information,
     * this hash is a unique file identifier. This improves performance
     * and overcomes db index size limits.
     * @param int $contextid
     * @param string $filearea
     * @param int $itemid
     * @param string $filepath
     * @param string $filename
     * @return string
     */
    public static function get_pathname_hash($contextid, $filearea, $itemid, $filepath, $filename) {
        return sha1($contextid.$filearea.$itemid.$filepath.$filename);
    }

    /**
     * Does this file exist?
     * @param int $contextid
     * @param string $filearea
     * @param int $itemid
     * @param string $filepath
     * @param string $filename
     * @return bool
     */
    public function file_exists($contextid, $filearea, $itemid, $filepath, $filename) {
        $filepath = clean_param($filepath, PARAM_PATH);
        $filename = clean_param($filename, PARAM_FILE);

        if ($filename === '') {
            $filename = '.';
        }

        $pathnamehash = $this->get_pathname_hash($contextid, $filearea, $itemid, $filepath, $filename);
        return $this->file_exists_by_hash($pathnamehash);
    }

    /**
     * Does this file exist?
     * @param string $pathnamehash
     * @return bool
     */
    public function file_exists_by_hash($pathnamehash) {
        global $DB;

        return $DB->record_exists('files', array('pathnamehash'=>$pathnamehash));
    }

    /**
     * Fetch file using local file id.
     * Please do not rely on file ids, it is usually easier to use
     * pathname hashes instead.
     * @param int $fileid
     * @return mixed stored_file instance if exists, false if not
     */
    public function get_file_by_id($fileid) {
        global $DB;

        if ($file_record = $DB->get_record('files', array('id'=>$fileid))) {
            return new stored_file($this, $file_record);
        } else {
            return false;
        }
    }

    /**
     * Fetch file using local file full pathname hash
     * @param string $pathnamehash
     * @return mixed stored_file instance if exists, false if not
     */
    public function get_file_by_hash($pathnamehash) {
        global $DB;

        if ($file_record = $DB->get_record('files', array('pathnamehash'=>$pathnamehash))) {
            return new stored_file($this, $file_record);
        } else {
            return false;
        }
    }

    /**
     * Fetch localy stored file.
     * @param int $contextid
     * @param string $filearea
     * @param int $itemid
     * @param string $filepath
     * @param string $filename
     * @return mixed stored_file instance if exists, false if not
     */
    public function get_file($contextid, $filearea, $itemid, $filepath, $filename) {
        global $DB;

        $filepath = clean_param($filepath, PARAM_PATH);
        $filename = clean_param($filename, PARAM_FILE);

        if ($filename === '') {
            $filename = '.';
        }

        $pathnamehash = $this->get_pathname_hash($contextid, $filearea, $itemid, $filepath, $filename);
        return $this->get_file_by_hash($pathnamehash);
    }

    /**
     * Returns all area files (optionally limited by itemid)
     * @param int $contextid
     * @param string $filearea
     * @param int $itemid (all files if not specified)
     * @param string $sort
     * @param bool $includedirs
     * @return array of stored_files indexed by pathanmehash
     */
    public function get_area_files($contextid, $filearea, $itemid=false, $sort="itemid, filepath, filename", $includedirs=true) {
        global $DB;

        $conditions = array('contextid'=>$contextid, 'filearea'=>$filearea);
        if ($itemid !== false) {
            $conditions['itemid'] = $itemid;
        }

        $result = array();
        $file_records = $DB->get_records('files', $conditions, $sort);
        foreach ($file_records as $file_record) {
            if (!$includedirs and $file_record->filename === '.') {
                continue;
            }
            $result[$file_record->pathnamehash] = new stored_file($this, $file_record);
        }
        return $result;
    }

    /**
     * Returns array based tree structure of area files
     * @param int $contextid
     * @param string $filearea
     * @param int $itemid
     * @return array each dir represented by dirname, subdirs, files and dirfile array elements
     */
    public function get_area_tree($contextid, $filearea, $itemid) {
        $result = array('dirname'=>'', 'dirfile'=>null, 'subdirs'=>array(), 'files'=>array());
        $files = $this->get_area_files($contextid, $filearea, $itemid, $sort="itemid, filepath, filename", true);
        // first create directory structure
        foreach ($files as $hash=>$dir) {
            if (!$dir->is_directory()) {
                continue;
            }
            unset($files[$hash]);
            if ($dir->get_filepath() === '/') {
                $result['dirfile'] = $dir;
                continue;
            }
            $parts = explode('/', trim($dir->get_filepath(),'/'));
            $pointer =& $result;
            foreach ($parts as $part) {
                if ($part === '') {
                    continue;
                }
                if (!isset($pointer['subdirs'][$part])) {
                    $pointer['subdirs'][$part] = array('dirname'=>$part, 'dirfile'=>null, 'subdirs'=>array(), 'files'=>array());
                }
                $pointer =& $pointer['subdirs'][$part];
            }
            $pointer['dirfile'] = $dir;
            unset($pointer);
        }
        foreach ($files as $hash=>$file) {
            $parts = explode('/', trim($file->get_filepath(),'/'));
            $pointer =& $result;
            foreach ($parts as $part) {
                if ($part === '') {
                    continue;
                }
                $pointer =& $pointer['subdirs'][$part];
            }
            $pointer['files'][$file->get_filename()] = $file;
            unset($pointer);
        }
        return $result;
    }

    /**
     * Returns all files and otionally directories
     * @param int $contextid
     * @param string $filearea
     * @param int $itemid
     * @param int $filepath directory path
     * @param bool $recursive include all subdirectories
     * @param bool $includedirs include files and directories
     * @param string $sort
     * @return array of stored_files indexed by pathanmehash
     */
    public function get_directory_files($contextid, $filearea, $itemid, $filepath, $recursive=false, $includedirs=true, $sort="filepath, filename") {
        global $DB;

        if (!$directory = $this->get_file($contextid, $filearea, $itemid, $filepath, '.')) {
            return array();
        }

        if ($recursive) {

            $dirs = $includedirs ? "" : "AND filename <> '.'";
            $length = textlib_get_instance()->strlen($filepath);

            $sql = "SELECT *
                      FROM {files}
                     WHERE contextid = :contextid AND filearea = :filearea AND itemid = :itemid
                           AND ".$DB->sql_substr("filepath", 1, $length)." = :filepath
                           AND id <> :dirid
                           $dirs
                  ORDER BY $sort";
            $params = array('contextid'=>$contextid, 'filearea'=>$filearea, 'itemid'=>$itemid, 'filepath'=>$filepath, 'dirid'=>$directory->get_id());

            $files = array();
            $dirs  = array();
            $file_records = $DB->get_records_sql($sql, $params);
            foreach ($file_records as $file_record) {
                if ($file_record->filename == '.') {
                    $dirs[$file_record->pathnamehash] = new stored_file($this, $file_record);
                } else {
                    $files[$file_record->pathnamehash] = new stored_file($this, $file_record);
                }
            }
            $result = array_merge($dirs, $files);

        } else {
            $result = array();
            $params = array('contextid'=>$contextid, 'filearea'=>$filearea, 'itemid'=>$itemid, 'filepath'=>$filepath, 'dirid'=>$directory->get_id());

            $length = textlib_get_instance()->strlen($filepath);

            if ($includedirs) {
                $sql = "SELECT *
                          FROM {files}
                         WHERE contextid = :contextid AND filearea = :filearea
                               AND itemid = :itemid AND filename = '.'
                               AND ".$DB->sql_substr("filepath", 1, $length)." = :filepath
                               AND id <> :dirid
                      ORDER BY $sort";
                $reqlevel = substr_count($filepath, '/') + 1;
                $file_records = $DB->get_records_sql($sql, $params);
                foreach ($file_records as $file_record) {
                    if (substr_count($file_record->filepath, '/') !== $reqlevel) {
                        continue;
                    }
                    $result[$file_record->pathnamehash] = new stored_file($this, $file_record);
                }
            }

            $sql = "SELECT *
                      FROM {files}
                     WHERE contextid = :contextid AND filearea = :filearea AND itemid = :itemid
                           AND filepath = :filepath AND filename <> '.'
                  ORDER BY $sort";

            $file_records = $DB->get_records_sql($sql, $params);
            foreach ($file_records as $file_record) {
                $result[$file_record->pathnamehash] = new stored_file($this, $file_record);
            }
        }

        return $result;
    }

    /**
     * Delete all area files (optionally limited by itemid)
     * @param int $contextid
     * @param string $filearea (all areas in context if not specified)
     * @param int $itemid (all files if not specified)
     * @return success
     */
    public function delete_area_files($contextid, $filearea=false, $itemid=false) {
        global $DB;

        $conditions = array('contextid'=>$contextid);
        if ($filearea !== false) {
            $conditions['filearea'] = $filearea;
        }
        if ($itemid !== false) {
            $conditions['itemid'] = $itemid;
        }

        $success = true;

        $file_records = $DB->get_records('files', $conditions);
        foreach ($file_records as $file_record) {
            $stored_file = new stored_file($this, $file_record);
            $success = $stored_file->delete() && $success;
        }

        return $success;
    }

    /**
     * Recursively creates directory
     * @param int $contextid
     * @param string $filearea
     * @param int $itemid
     * @param string $filepath
     * @param string $filename
     * @return bool success
     */
    public function create_directory($contextid, $filearea, $itemid, $filepath, $userid=null) {
        global $DB;

        // validate all parameters, we do not want any rubbish stored in database, right?
        if (!is_number($contextid) or $contextid < 1) {
            throw new file_exception('storedfileproblem', 'Invalid contextid');
        }

        $filearea = clean_param($filearea, PARAM_ALPHAEXT);
        if ($filearea === '') {
            throw new file_exception('storedfileproblem', 'Invalid filearea');
        }

        if (!is_number($itemid) or $itemid < 0) {
            throw new file_exception('storedfileproblem', 'Invalid itemid');
        }

        $filepath = clean_param($filepath, PARAM_PATH);
        if (strpos($filepath, '/') !== 0 or strrpos($filepath, '/') !== strlen($filepath)-1) {
            // path must start and end with '/'
            throw new file_exception('storedfileproblem', 'Invalid file path');
        }

        $pathnamehash = $this->get_pathname_hash($contextid, $filearea, $itemid, $filepath, '.');

        if ($dir_info = $this->get_file_by_hash($pathnamehash)) {
            return $dir_info;
        }

        static $contenthash = null;
        if (!$contenthash) {
            $this->add_string_to_pool('');
            $contenthash = sha1('');
        }

        $now = time();

        $dir_record = new object();
        $dir_record->contextid = $contextid;
        $dir_record->filearea  = $filearea;
        $dir_record->itemid    = $itemid;
        $dir_record->filepath  = $filepath;
        $dir_record->filename  = '.';
        $dir_record->contenthash  = $contenthash;
        $dir_record->filesize  = 0;

        $dir_record->timecreated  = $now;
        $dir_record->timemodified = $now;
        $dir_record->mimetype     = null;
        $dir_record->userid       = $userid;

        $dir_record->pathnamehash = $pathnamehash;

        $DB->insert_record('files', $dir_record);
        $dir_info = $this->get_file_by_hash($pathnamehash);

        if ($filepath !== '/') {
            //recurse to parent dirs
            $filepath = trim($filepath, '/');
            $filepath = explode('/', $filepath);
            array_pop($filepath);
            $filepath = implode('/', $filepath);
            $filepath = ($filepath === '') ? '/' : "/$filepath/";
            $this->create_directory($contextid, $filearea, $itemid, $filepath, $userid);
        }

        return $dir_info;
    }

    /**
     * Add new local file based on existing local file
     * @param mixed $file_record object or array describing changes
     * @param int $fid id of existing local file
     * @return object stored_file instance
     */
    public function create_file_from_storedfile($file_record, $fid) {
        global $DB;

        if ($fid instanceof stored_file) {
            $fid = $fid->get_id();
        }

        $file_record = (array)$file_record; // we support arrays too, do not modify the submitted record!

        unset($file_record['id']);
        unset($file_record['filesize']);
        unset($file_record['contenthash']);
        unset($file_record['pathnamehash']);

        $now = time();

        if (!$newrecord = $DB->get_record('files', array('id'=>$fid))) {
            throw new file_exception('storedfileproblem', 'File does not exist');
        }

        unset($newrecord->id);

        foreach ($file_record as $key=>$value) {
            // validate all parameters, we do not want any rubbish stored in database, right?
            if ($key == 'contextid' and (!is_number($value) or $value < 1)) {
                throw new file_exception('storedfileproblem', 'Invalid contextid');
            }

            if ($key == 'filearea') {
                $value = clean_param($value, PARAM_ALPHAEXT);
                if ($value === '') {
                    throw new file_exception('storedfileproblem', 'Invalid filearea');
                }
            }

            if ($key == 'itemid' and (!is_number($value) or $value < 0)) {
                throw new file_exception('storedfileproblem', 'Invalid itemid');
            }


            if ($key == 'filepath') {
                $value = clean_param($value, PARAM_PATH);
                if (strpos($value, '/') !== 0 or strrpos($value, '/') !== strlen($value)-1) {
                    // path must start and end with '/'
                    throw new file_exception('storedfileproblem', 'Invalid file path');
                }
            }

            if ($key == 'filename') {
                $value = clean_param($value, PARAM_FILE);
                if ($value === '') {
                    // path must start and end with '/'
                    throw new file_exception('storedfileproblem', 'Invalid file name');
                }
            }

            $newrecord->$key = $value;
        }

        $newrecord->pathnamehash = $this->get_pathname_hash($newrecord->contextid, $newrecord->filearea, $newrecord->itemid, $newrecord->filepath, $newrecord->filename);

        if ($newrecord->filename === '.') {
            // special case - only this function supports directories ;-)
            $directory = $this->create_directory($newrecord->contextid, $newrecord->filearea, $newrecord->itemid, $newrecord->filepath, $newrecord->userid);
            // update the existing directory with the new data
            $newrecord->id = $directory->get_id();
            $DB->update_record('files', $newrecord);
            return new stored_file($this, $newrecord);
        }

        try {
            $newrecord->id = $DB->insert_record('files', $newrecord);
        } catch (database_exception $e) {
            $newrecord->id = false;
        }

        if (!$newrecord->id) {
            throw new stored_file_creation_exception($newrecord->contextid, $newrecord->filearea, $newrecord->itemid,
                                                     $newrecord->filepath, $newrecord->filename);
        }

        $this->create_directory($newrecord->contextid, $newrecord->filearea, $newrecord->itemid, $newrecord->filepath, $newrecord->userid);

        return new stored_file($this, $newrecord);
    }

    /**
     * Add new local file
     * @param mixed $file_record object or array describing file
     * @param string $path path to file or content of file
     * @param array $options @see download_file_content() options
     * @return object stored_file instance
     */
    public function create_file_from_url($file_record, $url, $options=null) {

        $file_record = (array)$file_record;  //do not modify the submitted record, this cast unlinks objects
        $file_record = (object)$file_record; // we support arrays too

        $headers        = isset($options['headers'])        ? $options['headers'] : null;
        $postdata       = isset($options['postdata'])       ? $options['postdata'] : null;
        $fullresponse   = isset($options['fullresponse'])   ? $options['fullresponse'] : false;
        $timeout        = isset($options['timeout'])        ? $options['timeout'] : 300;
        $connecttimeout = isset($options['connecttimeout']) ? $options['connecttimeout'] : 20;
        $skipcertverify = isset($options['skipcertverify']) ? $options['skipcertverify'] : false;

        // TODO: it might be better to add a new option to file file content to temp file,
        //       the problem here is that the size of file is limited by available memory

        $content = download_file_content($url, $headers, $postdata, $fullresponse, $timeout, $connecttimeout, $skipcertverify);

        if (!isset($file_record->filename)) {
            $parts = explode('/', $url);
            $filename = array_pop($parts);
            $file_record->filename = clean_param($filename, PARAM_FILE);
        }

        return $this->create_file_from_string($file_record, $content);
    }

    /**
     * Add new local file
     * @param mixed $file_record object or array describing file
     * @param string $path path to file or content of file
     * @return object stored_file instance
     */
    public function create_file_from_pathname($file_record, $pathname) {
        global $DB;

        $file_record = (array)$file_record;  //do not modify the submitted record, this cast unlinks objects
        $file_record = (object)$file_record; // we support arrays too

        // validate all parameters, we do not want any rubbish stored in database, right?
        if (!is_number($file_record->contextid) or $file_record->contextid < 1) {
            throw new file_exception('storedfileproblem', 'Invalid contextid');
        }

        $file_record->filearea = clean_param($file_record->filearea, PARAM_ALPHAEXT);
        if ($file_record->filearea === '') {
            throw new file_exception('storedfileproblem', 'Invalid filearea');
        }

        if (!is_number($file_record->itemid) or $file_record->itemid < 0) {
            throw new file_exception('storedfileproblem', 'Invalid itemid');
        }

        $file_record->filepath = clean_param($file_record->filepath, PARAM_PATH);
        if (strpos($file_record->filepath, '/') !== 0 or strrpos($file_record->filepath, '/') !== strlen($file_record->filepath)-1) {
            // path must start and end with '/'
            throw new file_exception('storedfileproblem', 'Invalid file path');
        }

        $file_record->filename = clean_param($file_record->filename, PARAM_FILE);
        if ($file_record->filename === '') {
            // filename must not be empty
            throw new file_exception('storedfileproblem', 'Invalid file name');
        }

        $now = time();

        $newrecord = new object();

        $newrecord->contextid = $file_record->contextid;
        $newrecord->filearea  = $file_record->filearea;
        $newrecord->itemid    = $file_record->itemid;
        $newrecord->filepath  = $file_record->filepath;
        $newrecord->filename  = $file_record->filename;

        $newrecord->timecreated  = empty($file_record->timecreated) ? $now : $file_record->timecreated;
        $newrecord->timemodified = empty($file_record->timemodified) ? $now : $file_record->timemodified;
        $newrecord->mimetype     = empty($file_record->mimetype) ? mimeinfo('type', $file_record->filename) : $file_record->mimetype;
        $newrecord->userid       = empty($file_record->userid) ? null : $file_record->userid;

        list($newrecord->contenthash, $newrecord->filesize, $newfile) = $this->add_file_to_pool($pathname);

        $newrecord->pathnamehash = $this->get_pathname_hash($newrecord->contextid, $newrecord->filearea, $newrecord->itemid, $newrecord->filepath, $newrecord->filename);

        try {
            $newrecord->id = $DB->insert_record('files', $newrecord);
        } catch (database_exception $e) {
            $newrecord->id = false;
        }

        if (!$newrecord->id) {
            if ($newfile) {
                $this->deleted_file_cleanup($newrecord->contenthash);
            }
            throw new stored_file_creation_exception($newrecord->contextid, $newrecord->filearea, $newrecord->itemid,
                                                    $newrecord->filepath, $newrecord->filename);
        }

        $this->create_directory($newrecord->contextid, $newrecord->filearea, $newrecord->itemid, $newrecord->filepath, $newrecord->userid);

        return new stored_file($this, $newrecord);
    }

    /**
     * Add new local file
     * @param mixed $file_record object or array describing file
     * @param string $content content of file
     * @return object stored_file instance
     */
    public function create_file_from_string($file_record, $content) {
        global $DB;

        $file_record = (array)$file_record;  //do not modify the submitted record, this cast unlinks objects
        $file_record = (object)$file_record; // we support arrays too

        // validate all parameters, we do not want any rubbish stored in database, right?
        if (!is_number($file_record->contextid) or $file_record->contextid < 1) {
            throw new file_exception('storedfileproblem', 'Invalid contextid');
        }

        $file_record->filearea = clean_param($file_record->filearea, PARAM_ALPHAEXT);
        if ($file_record->filearea === '') {
            throw new file_exception('storedfileproblem', 'Invalid filearea');
        }

        if (!is_number($file_record->itemid) or $file_record->itemid < 0) {
            throw new file_exception('storedfileproblem', 'Invalid itemid');
        }

        $file_record->filepath = clean_param($file_record->filepath, PARAM_PATH);
        if (strpos($file_record->filepath, '/') !== 0 or strrpos($file_record->filepath, '/') !== strlen($file_record->filepath)-1) {
            // path must start and end with '/'
            throw new file_exception('storedfileproblem', 'Invalid file path');
        }

        $file_record->filename = clean_param($file_record->filename, PARAM_FILE);
        if ($file_record->filename === '') {
            // path must start and end with '/'
            throw new file_exception('storedfileproblem', 'Invalid file name');
        }

        $now = time();

        $newrecord = new object();

        $newrecord->contextid = $file_record->contextid;
        $newrecord->filearea  = $file_record->filearea;
        $newrecord->itemid    = $file_record->itemid;
        $newrecord->filepath  = $file_record->filepath;
        $newrecord->filename  = $file_record->filename;

        $newrecord->timecreated  = empty($file_record->timecreated) ? $now : $file_record->timecreated;
        $newrecord->timemodified = empty($file_record->timemodified) ? $now : $file_record->timemodified;
        $newrecord->mimetype     = empty($file_record->mimetype) ? mimeinfo('type', $file_record->filename) : $file_record->mimetype;
        $newrecord->userid       = empty($file_record->userid) ? null : $file_record->userid;

        list($newrecord->contenthash, $newrecord->filesize, $newfile) = $this->add_string_to_pool($content);

        $newrecord->pathnamehash = $this->get_pathname_hash($newrecord->contextid, $newrecord->filearea, $newrecord->itemid, $newrecord->filepath, $newrecord->filename);

        try {
            $newrecord->id = $DB->insert_record('files', $newrecord);
        } catch (database_exception $e) {
            $newrecord->id = false;
        }

        if (!$newrecord->id) {
            if ($newfile) {
                $this->deleted_file_cleanup($newrecord->contenthash);
            }
            throw new stored_file_creation_exception($newrecord->contextid, $newrecord->filearea, $newrecord->itemid,
                                                    $newrecord->filepath, $newrecord->filename);
        }

        $this->create_directory($newrecord->contextid, $newrecord->filearea, $newrecord->itemid, $newrecord->filepath, $newrecord->userid);

        return new stored_file($this, $newrecord);
    }

    /**
     * Creates new image file from existing.
     * @param mixed $file_record object or array describing new file
     * @param mixed file id or stored file object
     * @param int $newwidth in pixels
     * @param int $newheight in pixels
     * @param bool $keepaspectratio
     * @param int $quality depending on image type 0-100 for jpeg, 0-9 (0 means no comppression) for png
     * @return object stored_file instance
     */
    public function convert_image($file_record, $fid, $newwidth=null, $newheight=null, $keepaspectratio=true, $quality=null) {
        global $DB;

        if ($fid instanceof stored_file) {
            $fid = $fid->get_id();
        }

        $file_record = (array)$file_record; // we support arrays too, do not modify the submitted record!

        if (!$file = $this->get_file_by_id($fid)) { // make sure file really exists and we we correct data
            throw new file_exception('storedfileproblem', 'File does not exist');
        }

        if (!$imageinfo = $file->get_imageinfo()) {
            throw new file_exception('storedfileproblem', 'File is not an image');
        }

        if (!isset($file_record['filename'])) {
            $file_record['filename'] == $file->get_filename();
        }

        if (!isset($file_record['mimetype'])) {
            $file_record['mimetype'] = mimeinfo('type', $file_record['filename']);
        }

        $width    = $imageinfo['width'];
        $height   = $imageinfo['height'];
        $mimetype = $imageinfo['mimetype'];

        if ($keepaspectratio) {
            if (0 >= $newwidth and 0 >= $newheight) {
                // no sizes specified
                $newwidth  = $width;
                $newheight = $height;

            } else if (0 < $newwidth and 0 < $newheight) {
                $xheight = ($newwidth*($height/$width));
                if ($xheight < $newheight) {
                    $newheight = (int)$xheight;
                } else {
                    $newwidth = (int)($newheight*($width/$height));
                }

            } else if (0 < $newwidth) {
                $newheight = (int)($newwidth*($height/$width));

            } else { //0 < $newheight
                $newwidth = (int)($newheight*($width/$height));
            }

        } else {
            if (0 >= $newwidth) {
                $newwidth = $width;
            }
            if (0 >= $newheight) {
                $newheight = $height;
            }
        }

        $img = imagecreatefromstring($file->get_content());
        if ($height != $newheight or $width != $newwidth) {
            $newimg = imagecreatetruecolor($newwidth, $newheight);
            if (!imagecopyresized($newimg, $img, 0, 0, 0, 0, $newwidth, $newheight, $width, $height)) {
                // weird
                throw new file_exception('storedfileproblem', 'Can not resize image');
            }
            imagedestroy($img);
            $img = $newimg;
        }

        ob_start();
        switch ($file_record['mimetype']) {
            case 'image/gif':
                imagegif($img);
                break;

            case 'image/jpeg':
                if (is_null($quality)) {
                    imagejpeg($img);
                } else {
                    imagejpeg($img, NULL, $quality);
                }
                break;

            case 'image/png':
                $quality = (int)$quality;
                imagepng($img, NULL, $quality, NULL);
                break;

            default:
                throw new file_exception('storedfileproblem', 'Unsupported mime type');
        }

        $content = ob_get_contents();
        ob_end_clean();
        imagedestroy($img);

        if (!$content) {
            throw new file_exception('storedfileproblem', 'Can not convert image');
        }

        return $this->create_file_from_string($file_record, $content);
    }

    /**
     * Add file content to sha1 pool
     * @param string $pathname path to file
     * @param string sha1 hash of content if known (performance only)
     * @return array(contenthash, filesize, newfile)
     */
    public function add_file_to_pool($pathname, $contenthash=null) {
        if (!is_readable($pathname)) {
            throw new file_exception('storedfilecannotread');
        }

        if (is_null($contenthash)) {
            $contenthash = sha1_file($pathname);
        }

        $filesize = filesize($pathname);

        $hashpath = $this->path_from_hash($contenthash);
        $hashfile = "$hashpath/$contenthash";

        if (file_exists($hashfile)) {
            if (filesize($hashfile) !== $filesize) {
                throw new file_pool_content_exception($contenthash);
            }
            $newfile = false;

        } else {
            if (!is_dir($hashpath)) {
                if (!mkdir($hashpath, $this->dirpermissions, true)) {
                    throw new file_exception('storedfilecannotcreatefiledirs'); // permission trouble
                }
            }
            $newfile = true;

            if (!copy($pathname, $hashfile)) {
                throw new file_exception('storedfilecannotread');
            }

            if (filesize($hashfile) !== $filesize) {
                @unlink($hashfile);
                throw new file_pool_content_exception($contenthash);
            }
            chmod($hashfile, $this->filepermissions); // fix permissions if needed
        }


        return array($contenthash, $filesize, $newfile);
    }

    /**
     * Add string content to sha1 pool
     * @param string $content file content - binary string
     * @return array(contenthash, filesize, newfile)
     */
    public function add_string_to_pool($content) {
        $contenthash = sha1($content);
        $filesize = strlen($content); // binary length

        $hashpath = $this->path_from_hash($contenthash);
        $hashfile = "$hashpath/$contenthash";


        if (file_exists($hashfile)) {
            if (filesize($hashfile) !== $filesize) {
                throw new file_pool_content_exception($contenthash);
            }
            $newfile = false;

        } else {
            if (!is_dir($hashpath)) {
                if (!mkdir($hashpath, $this->dirpermissions, true)) {
                    throw new file_exception('storedfilecannotcreatefiledirs'); // permission trouble
                }
            }
            $newfile = true;

            file_put_contents($hashfile, $content);

            if (filesize($hashfile) !== $filesize) {
                @unlink($hashfile);
                throw new file_pool_content_exception($contenthash);
            }
            chmod($hashfile, $this->filepermissions); // fix permissions if needed
        }

        return array($contenthash, $filesize, $newfile);
    }

    /**
     * Return path to file with given hash
     *
     * NOTE: must not be public, files in pool must not be modified
     *
     * @param string $contenthash
     * @return string expected file location
     */
    protected function path_from_hash($contenthash) {
        $l1 = $contenthash[0].$contenthash[1];
        $l2 = $contenthash[2].$contenthash[3];
        $l3 = $contenthash[4].$contenthash[5];
        return "$this->filedir/$l1/$l2/$l3";
    }

    /**
     * Return path to file with given hash
     *
     * NOTE: must not be public, files in pool must not be modified
     *
     * @param string $contenthash
     * @return string expected file location
     */
    protected function trash_path_from_hash($contenthash) {
        $l1 = $contenthash[0].$contenthash[1];
        $l2 = $contenthash[2].$contenthash[3];
        $l3 = $contenthash[4].$contenthash[5];
        return "$this->trashdir/$l1/$l2/$l3";
    }

    /**
     * Tries to recover missing content of file from trash
     * @param object $file_record
     * @return bool success
     */
    public function try_content_recovery($file) {
        $contenthash = $file->get_contenthash();
        $trashfile = $this->trash_path_from_hash($contenthash).'/'.$contenthash;
        if (!is_readable($trashfile)) {
            if (!is_readable($this->trashdir.'/'.$contenthash)) {
                return false;
            }
            // nice, at least alternative trash file in trash root exists
            $trashfile = $this->trashdir.'/'.$contenthash;
        }
        if (filesize($trashfile) != $file->get_filesize() or sha1_file($trashfile) != $contenthash) {
            //weird, better fail early
            return false;
        }
        $contentdir  = $this->path_from_hash($contenthash);
        $contentfile = $contentdir.'/'.$contenthash;
        if (file_exists($contentfile)) {
            //strange, no need to recover anything
            return true;
        }
        if (!is_dir($contentdir)) {
            if (!mkdir($contentdir, $this->dirpermissions, true)) {
                return false;
            }
        }
        return rename($trashfile, $contentfile);
    }

    /**
     * Marks pool file as candidate for deleting
     * DO NOT call directly - reserved for core!
     * @param string $contenthash
     * @return void
     */
    public function deleted_file_cleanup($contenthash) {
        global $DB;

        //Note: this section is critical - in theory file could be reused at the same
        //      time, if this happens we can still recover the file from trash
        if ($DB->record_exists('files', array('contenthash'=>$contenthash))) {
            // file content is still used
            return;
        }
        //move content file to trash
        $contentfile = $this->path_from_hash($contenthash).'/'.$contenthash;
        if (!file_exists($contentfile)) {
            //weird, but no problem
            return;
        }
        $trashpath = $this->trash_path_from_hash($contenthash);
        $trashfile = $trashpath.'/'.$contenthash;
        if (file_exists($trashfile)) {
            // we already have this content in trash, no need to move it there
            unlink($contentfile);
            return;
        }
        if (!is_dir($trashpath)) {
            mkdir($trashpath, $this->dirpermissions, true);
        }
        rename($contentfile, $trashfile);
        chmod($trashfile, $this->filepermissions); // fix permissions if needed
    }

    /**
     * Cron cleanup job.
     */
    public function cron() {
        global $CFG;
        // remove trash pool files once a day
        // if you want to disable purging of trash put $CFG->fileslastcleanup=time(); into config.php
        if (empty($CFG->fileslastcleanup) or $CFG->fileslastcleanup < time() - 60*60*24) {
            require_once($CFG->libdir.'/filelib.php');
            mtrace('Deleting trash files... ', '');
            fulldelete($this->trashdir);
            set_config('fileslastcleanup', time());
            mtrace('done.');
        }
    }
}
