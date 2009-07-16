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
 * Functions for file handling.
 *
 * @package    moodlecore
 * @subpackage file
 * @copyright  1999 onwards Martin Dougiamas (http://dougiamas.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @var string unique string constant. */
define('BYTESERVING_BOUNDARY', 's1k2o3d4a5k6s7');

require_once("$CFG->libdir/file/file_exceptions.php");
require_once("$CFG->libdir/file/file_storage.php");
require_once("$CFG->libdir/file/file_browser.php");

require_once("$CFG->libdir/packer/zip_packer.php");

/**
 * Given a physical path to a file, returns the URL through which it can be reached in Moodle.
 *
 * @global object
 * @global string
 * @param string $path Physical path to a file
 * @param array $options associative array of GET variables to append to the URL
 * @param string $type (questionfile|rssfile|user|usergroup|httpscoursefile|coursefile)
 * @return string URL to file
 */
function get_file_url($path, $options=null, $type='coursefile') {
    global $CFG, $HTTPSPAGEREQUIRED;

    $path = str_replace('//', '/', $path);
    $path = trim($path, '/'); // no leading and trailing slashes

    // type of file
    switch ($type) {
       case 'questionfile':
            $url = $CFG->wwwroot."/question/exportfile.php";
            break;
       case 'rssfile':
            $url = $CFG->wwwroot."/rss/file.php";
            break;
        case 'user':
            if (!empty($HTTPSPAGEREQUIRED)) {
                $wwwroot = $CFG->httpswwwroot;
            }
            else {
                $wwwroot = $CFG->wwwroot;
            }
            $url = $wwwroot."/user/pix.php";
            break;
        case 'usergroup':
            $url = $CFG->wwwroot."/user/grouppix.php";
            break;
        case 'httpscoursefile':
            $url = $CFG->httpswwwroot."/file.php";
            break;
         case 'coursefile':
        default:
            $url = $CFG->wwwroot."/file.php";
    }

    if ($CFG->slasharguments) {
        $parts = explode('/', $path);
        foreach ($parts as $key => $part) {
        /// anchor dash character should not be encoded
            $subparts = explode('#', $part);
            $subparts = array_map('rawurlencode', $subparts);
            $parts[$key] = implode('#', $subparts);
        }
        $path  = implode('/', $parts);
        $ffurl = $url.'/'.$path;
        $separator = '?';
    } else {
        $path = rawurlencode('/'.$path);
        $ffurl = $url.'?file='.$path;
        $separator = '&amp;';
    }

    if ($options) {
        foreach ($options as $name=>$value) {
            $ffurl = $ffurl.$separator.$name.'='.$value;
            $separator = '&amp;';
        }
    }

    return $ffurl;
}


/**
 * Encodes file serving url
 *
 * @todo decide if we really need this
 * @global object
 * @param string $urlbase
 * @param string $path /filearea/itemid/dir/dir/file.exe
 * @param bool $forcedownload
 * @param bool $https https url required
 * @return string encoded file url
 */
function file_encode_url($urlbase, $path, $forcedownload=false, $https=false) {
    global $CFG;

    if ($CFG->slasharguments) {
        $parts = explode('/', $path);
        $parts = array_map('rawurlencode', $parts);
        $path  = implode('/', $parts);
        $return = $urlbase.$path;
        if ($forcedownload) {
            $return .= '?forcedownload=1';
        }
    } else {
        $path = rawurlencode($path);
        $return = $urlbase.'?file='.$path;
        if ($forcedownload) {
            $return .= '&amp;forcedownload=1';
        }
    }

    if ($https) {
        $return = str_replace('http://', 'https://', $return);
    }

    return $return;
}

/**
 * Prepares standardised text field fro editing with Editor formslib element
 *
 * @param object $data $database entry field
 * @param string $field name of data field
 * @param array $options various options
 * @param object $context context, required for existing data
 * @param string $filearea file area name
 * @param int $itemid item id, required if item exists
 * @return object modified data object
 */
function file_prepare_standard_editor($data, $field, array $options, $context=null, $filearea=null, $itemid=null) {
    $options = (array)$options;
    if (!isset($options['trusttext'])) {
        $options['trusttext'] = false;
    }
    if (!isset($options['forcehttps'])) {
        $options['forcehttps'] = false;
    }
    if (!isset($options['subdirs'])) {
        $options['subdirs'] = false;
    }
    if (!isset($options['maxfiles'])) {
        $options['maxfiles'] = 0; // no files by default
    }
    if (!isset($options['noclean'])) {
        $options['noclean'] = false;
    }

    if (empty($data->id) or empty($context)) {
        $contextid = null;
        $data->id = null;
        if (!isset($data->{$field})) {
            $data->{$field} = '';
        }
        if (!isset($data->{$field.'format'})) {
            $data->{$field.'format'} = FORMAT_HTML; // TODO: use better default based on user preferences and browser capabilities
        }
        if (!$options['noclean']) {
            $data->{$field} = clean_text($data->{$field}, $data->{$field.'format'});
        }

    } else {
        if ($options['trusttext']) {
            // noclean ignored if trusttext enabled
            if (!isset($data->{$field.'trust'})) {
                $data->{$field.'trust'} = 0;
            }
            $data = trusttext_pre_edit($data, $field, $context);
        } else {
            if (!$options['noclean']) {
                $data->{$field} = clean_text($data->{$field}, $data->{$field.'format'});
            }
        }
        $contextid = $context->id;
    }

    if ($options['maxfiles'] != 0) {
        $draftid_editor = file_get_submitted_draft_itemid($field);
        $currenttext = file_prepare_draft_area($draftid_editor, $contextid, $filearea, $data->id, $options, $data->{$field});
        $data->{$field.'_editor'} = array('text'=>$currenttext, 'format'=>$data->{$field.'format'}, 'itemid'=>$draftid_editor);
    } else {
        $data->{$field.'_editor'} = array('text'=>$data->{$field}, 'format'=>$data->{$field.'format'}, 0);
    }

    return $data;
}

/**
 * Prepares editing with File manager formslib element
 *
 * @param object $data $database entry field
 * @param string $field name of data field
 * @param array $options various options
 * @param object $context context, required for existing data
 * @param string $filearea file area name
 * @param int $itemid item id, required if item exists
 * @return object modified data object
 */
function file_postupdate_standard_editor($data, $field, array $options, $context, $filearea=null, $itemid=null) {
    $options = (array)$options;
    if (!isset($options['trusttext'])) {
        $options['trusttext'] = false;
    }
    if (!isset($options['forcehttps'])) {
        $options['forcehttps'] = false;
    }
    if (!isset($options['subdirs'])) {
        $options['subdirs'] = false;
    }
    if (!isset($options['maxfiles'])) {
        $options['maxfiles'] = 0; // no files by default
    }
    if (!isset($options['maxbytes'])) {
        $options['maxbytes'] = 0; // unlimited
    }

    if ($options['trusttext']) {
        $data->definitiontrust = trusttext_trusted($context);
    } else {
        $data->definitiontrust = 0;
    }

    $editor = $data->{$field.'_editor'};

    if ($options['maxfiles'] == 0 or is_null($filearea) or is_null($itemid)) {
        $data->{$field} = $editor['text'];
    } else {
        $data->{$field} = file_save_draft_area_files($editor['itemid'], $context->id, $filearea, $itemid, $options, $editor['text'], $options['forcehttps']);
    }
    $data->{$field.'format'} = $editor['format'];

    return $data;
}

/**
 * Saves text and files modified by Editor formslib element
 *
 * @param object $data $database entry field
 * @param string $field name of data field
 * @param array $options various options
 * @param object $context context - must already exist
 * @param string $filearea file area name
 * @param int $itemid must already exist, usually means data is in db
 * @return object modified data obejct
 */
function file_prepare_standard_filemanager($data, $field, array $options, $context=null, $filearea=null, $itemid=null) {
    $options = (array)$options;
    if (!isset($options['subdirs'])) {
        $options['subdirs'] = false;
    }
    if (empty($data->id) or empty($context)) {
        $data->id = null;
        $contextid = null;
    } else {
        $contextid = $context->id;
    }

    $draftid_editor = file_get_submitted_draft_itemid($field.'_filemanager');
    file_prepare_draft_area($draftid_editor, $contextid, $filearea, $data->id, $options);
    $data->{$field.'_filemanager'} = $draftid_editor;

    return $data;
}

/**
 * Saves files modified by File manager formslib element
 *
 * @param object $data $database entry field
 * @param string $field name of data field
 * @param array $options various options
 * @param object $context context - must already exist
 * @param string $filearea file area name
 * @param int $itemid must already exist, usually means data is in db
 * @return object modified data obejct
 */
function file_postupdate_standard_filemanager($data, $field, array $options, $context, $filearea, $itemid) {
    $options = (array)$options;
    if (!isset($options['subdirs'])) {
        $options['subdirs'] = false;
    }
    if (!isset($options['maxfiles'])) {
        $options['maxfiles'] = -1; // unlimited
    }
    if (!isset($options['maxbytes'])) {
        $options['maxbytes'] = 0; // unlimited
    }

    if (empty($data->{$field.'_filemanager'})) {
        $data->$field = '';

    } else {
        file_save_draft_area_files($data->{$field.'_filemanager'}, $context->id, $filearea, $data->id, $options);
        $fs = get_file_storage();

        if ($fs->get_area_files($context->id, $filearea, $itemid)) {
            $data->$field = '1'; // TODO: this is an ugly hack
        } else {
            $data->$field = '';
        }
    }

    return $data;
}

/**
 *
 * @global object
 * @global object
 * @return int a random but available draft itemid that can be used to create a new draft
 * file area.
 */
function file_get_unused_draft_itemid() {
    global $DB, $USER;

    if (isguestuser() or !isloggedin()) {
        // guests and not-logged-in users can not be allowed to upload anything!!!!!!
        print_error('noguest');
    }

    $contextid = get_context_instance(CONTEXT_USER, $USER->id)->id;
    $filearea  = 'user_draft';

    $fs = get_file_storage();
    $draftitemid = rand(1, 999999999);
    while ($files = $fs->get_area_files($contextid, $filearea, $draftitemid)) {
        $draftitemid = rand(1, 999999999);
    }

    return $draftitemid;
}

/**
 * Initialise a draft file area from a real one by copying the files. A draft
 * area will be created if one does not already exist. Normally you should
 * get $draftitemid by calling file_get_submitted_draft_itemid('elementname');
 *
 * @global object
 * @global object
 * @param int &$draftitemid the id of the draft area to use, or 0 to create a new one, in which case this parameter is updated.
 * @param integer $contextid This parameter and the next two identify the file area to copy files from.
 * @param string $filearea helps indentify the file area.
 * @param integer $itemid helps identify the file area. Can be null if there are no files yet.
 * @param array $options text and file options ('subdirs'=>false, 'forcehttps'=>false)
 * @param string $text some html content that needs to have embedded links rewritten to point to the draft area.
 * @return string if $text was passed in, the rewritten $text is returned. Otherwise NULL.
 */
function file_prepare_draft_area(&$draftitemid, $contextid, $filearea, $itemid, array $options=null, $text=null) {
    global $CFG, $USER;

    $options = (array)$options;
    if (!isset($options['subdirs'])) {
        $options['subdirs'] = false;
    }
    if (!isset($options['forcehttps'])) {
        $options['forcehttps'] = false;
    }

    $usercontext = get_context_instance(CONTEXT_USER, $USER->id);
    $fs = get_file_storage();

    if (empty($draftitemid)) {
        // create a new area and copy existing files into
        $draftitemid = file_get_unused_draft_itemid();
        $file_record = array('contextid'=>$usercontext->id, 'filearea'=>'user_draft', 'itemid'=>$draftitemid);
        if (!is_null($itemid) and $files = $fs->get_area_files($contextid, $filearea, $itemid)) {
            foreach ($files as $file) {
                if (!$options['subdirs'] and ($file->is_directory() or $file->get_filepath() !== '/')) {
                    continue;
                }
                $fs->create_file_from_storedfile($file_record, $file);
            }
        }
    } else {
        // nothing to do
    }

    if (is_null($text)) {
        return null;
    }

    // relink embedded files - editor can not handle @@PLUGINFILE@@ !
    return file_rewrite_pluginfile_urls($text, 'draftfile.php', $usercontext->id, 'user_draft', $draftitemid, $options);
}

/**
 * Convert encoded URLs in $text from the @@PLUGINFILE@@/... form to an actual URL.
 *
 * @global object
 * @param string $text The content that may contain ULRs in need of rewriting.
 * @param string $file The script that should be used to serve these files. pluginfile.php, draftfile.php, etc.
 * @param integer $contextid This parameter and the next two identify the file area to use.
 * @param string $filearea helps indentify the file area.
 * @param integer $itemid helps identify the file area.
 * @param array $options text and file options ('forcehttps'=>false)
 * @return string the processed text.
 */
function file_rewrite_pluginfile_urls($text, $file, $contextid, $filearea, $itemid, array $options=null) {
    global $CFG;

    $options = (array)$options;
    if (!isset($options['forcehttps'])) {
        $options['forcehttps'] = false;
    }

    if (!$CFG->slasharguments) {
        $file = $file . '?file=';
    }

    $baseurl = "$CFG->wwwroot/$file/$contextid/$filearea/";

    if ($itemid !== null) {
        $baseurl .= "$itemid/";
    }

    if ($options['forcehttps']) {
        $baseurl = str_replace('http://', 'https://', $baseurl);
    }

    return str_replace('@@PLUGINFILE@@/', $baseurl, $text);
}

/**
 * Returns information about files in a draft area.
 *
 * @global object
 * @global object
 * @param integer $draftitemid the draft area item id.
 * @return array with the following entries:
 *      'filecount' => number of files in the draft area.
 * (more information will be added as needed).
 */
function file_get_draft_area_info($draftitemid) {
    global $CFG, $USER;

    $usercontext = get_context_instance(CONTEXT_USER, $USER->id);
    $fs = get_file_storage();

    $results = array();

    // The number of files
    $draftfiles = $fs->get_area_files($usercontext->id, 'user_draft', $draftitemid, 'id', false);
    $results['filecount'] = count($draftfiles);

    return $results;
}

/**
 * Returns draft area itemid for a given element.
 *
 * @param string $elname name of formlib editor element, or a hidden form field that stores the draft area item id, etc.
 * @return inteter the itemid, or 0 if there is not one yet.
 */
function file_get_submitted_draft_itemid($elname) {
    $param = optional_param($elname, 0, PARAM_INT);
    if ($param) {
        confirm_sesskey();
    }
    if (is_array($param)) {
        $param = $param['itemid'];
    }
    return $param;
}

/**
 * Saves files from a draft file area to a real one (merging the list of files).
 * Can rewrite URLs in some content at the same time if desired.
 *
 * @global object
 * @global object
 * @param integer $draftitemid the id of the draft area to use. Normally obtained
 *      from file_get_submitted_draft_itemid('elementname') or similar.
 * @param integer $contextid This parameter and the next two identify the file area to save to.
 * @param string $filearea indentifies the file area.
 * @param integer $itemid helps identifies the file area.
 * @param array $options area options (subdirs=>false, maxfiles=-1, maxbytes=0)
 * @param string $text some html content that needs to have embedded links rewritten
 *      to the @@PLUGINFILE@@ form for saving in the database.
 * @param boolean $forcehttps force https urls.
 * @return string if $text was passed in, the rewritten $text is returned. Otherwise NULL.
 */
function file_save_draft_area_files($draftitemid, $contextid, $filearea, $itemid, array $options=null, $text=null, $forcehttps=false) {
    global $CFG, $USER;

    $usercontext = get_context_instance(CONTEXT_USER, $USER->id);
    $fs = get_file_storage();

    $options = (array)$options;
    if (!isset($options['subdirs'])) {
        $options['subdirs'] = false;
    }
    if (!isset($options['maxfiles'])) {
        $options['maxfiles'] = -1; // unlimited
    }
    if (!isset($options['maxbytes'])) {
        $options['maxbytes'] = 0; // unlimited
    }

    $draftfiles = $fs->get_area_files($usercontext->id, 'user_draft', $draftitemid, 'id');
    $oldfiles   = $fs->get_area_files($contextid, $filearea, $itemid, 'id');

    if (count($draftfiles) < 2) {
        // means there are no files - one file means root dir only ;-)
        $fs->delete_area_files($contextid, $filearea, $itemid);

    } else if (count($oldfiles) < 2) {
        $filecount = 0;
        // there were no files before - one file means root dir only ;-)
        $file_record = array('contextid'=>$contextid, 'filearea'=>$filearea, 'itemid'=>$itemid);
        foreach ($draftfiles as $file) {
            if (!$options['subdirs']) {
                if ($file->get_filepath() !== '/' or $file->is_directory()) {
                    continue;
                }
            }
            if ($options['maxbytes'] and $options['maxbytes'] < $file->get_filesize()) {
                // oversized file - should not get here at all
                continue;
            }
            if ($options['maxfiles'] != -1 and $options['maxfiles'] <= $filecount) {
                // more files - should not get here at all
                break;
            }
            if (!$file->is_directory()) {
                $filecount++;
            }
            $fs->create_file_from_storedfile($file_record, $file);
        }

    } else {
        // we have to merge old and new files - we want to keep file ids for files that were not changed
        $file_record = array('contextid'=>$contextid, 'filearea'=>$filearea, 'itemid'=>$itemid);

        $newhashes = array();
        foreach ($draftfiles as $file) {
            $newhash = sha1($contextid.$filearea.$itemid.$file->get_filepath().$file->get_filename());
            $newhashes[$newhash] = $file;
        }
        $filecount = 0;
        foreach ($oldfiles as $file) {
            $oldhash = $file->get_pathnamehash();
            if (isset($newhashes[$oldhash])) {
                if (!$file->is_directory()) {
                    $filecount++;
                }
                // unchanged file already there
                unset($newhashes[$oldhash]);
            } else {
                // delete files not needed any more
                $file->delete();
            }
        }

        // now add new files
        foreach ($newhashes as $file) {
            if (!$options['subdirs']) {
                if ($file->get_filepath() !== '/' or $file->is_directory()) {
                    continue;
                }
            }
            if ($options['maxbytes'] and $options['maxbytes'] < $file->get_filesize()) {
                // oversized file - should not get here at all
                continue;
            }
            if ($options['maxfiles'] != -1 and $options['maxfiles'] <= $filecount) {
                // more files - should not get here at all
                break;
            }
            if (!$file->is_directory()) {
                $filecount++;
            }
            $fs->create_file_from_storedfile($file_record, $file);
        }
    }

    // purge the draft area
    $fs->delete_area_files($usercontext->id, 'user_draft', $draftitemid);

    if (is_null($text)) {
        return null;
    }

    // relink embedded files if text submitted - no absolute links allowed in database!
    if ($CFG->slasharguments) {
        $draftbase = "$CFG->wwwroot/draftfile.php/$usercontext->id/user_draft/$draftitemid/";
    } else {
        $draftbase = "$CFG->wwwroot/draftfile.php?file=/$usercontext->id/user_draft/$draftitemid/";
    }

    if ($forcehttps) {
        $draftbase = str_replace('http://', 'https://', $draftbase);
    }

    $text = str_ireplace($draftbase, '@@PLUGINFILE@@/', $text);

    return $text;
}

/**
 * Returns description of upload error
 *
 * @param int $errorcode found in $_FILES['filename.ext']['error']
 * @return string error description string, '' if ok
 */
function file_get_upload_error($errorcode) {

    switch ($errorcode) {
    case 0: // UPLOAD_ERR_OK - no error
        $errmessage = '';
        break;

    case 1: // UPLOAD_ERR_INI_SIZE
        $errmessage = get_string('uploadserverlimit');
        break;

    case 2: // UPLOAD_ERR_FORM_SIZE
        $errmessage = get_string('uploadformlimit');
        break;

    case 3: // UPLOAD_ERR_PARTIAL
        $errmessage = get_string('uploadpartialfile');
        break;

    case 4: // UPLOAD_ERR_NO_FILE
        $errmessage = get_string('uploadnofilefound');
        break;

    // Note: there is no error with a value of 5

    case 6: // UPLOAD_ERR_NO_TMP_DIR
        $errmessage = get_string('uploadnotempdir');
        break;

    case 7: // UPLOAD_ERR_CANT_WRITE
        $errmessage = get_string('uploadcantwrite');
        break;

    case 8: // UPLOAD_ERR_EXTENSION
        $errmessage = get_string('uploadextension');
        break;

    default:
        $errmessage = get_string('uploadproblem');
    }

    return $errmessage;
}

/**
 * Fetches content of file from Internet (using proxy if defined). Uses cURL extension if present.
 * Due to security concerns only downloads from http(s) sources are supported.
 *
 * @global object
 * @param string $url file url starting with http(s)://
 * @param array $headers http headers, null if none. If set, should be an
 *   associative array of header name => value pairs.
 * @param array $postdata array means use POST request with given parameters
 * @param bool $fullresponse return headers, responses, etc in a similar way snoopy does
 *   (if false, just returns content)
 * @param int $timeout timeout for complete download process including all file transfer
 *   (default 5 minutes)
 * @param int $connecttimeout timeout for connection to server; this is the timeout that
 *   usually happens if the remote server is completely down (default 20 seconds);
 *   may not work when using proxy
 * @param bool $skipcertverify If true, the peer's SSL certificate will not be checked. Only use this when already in a trusted location.
 * @return mixed false if request failed or content of the file as string if ok.
 */
function download_file_content($url, $headers=null, $postdata=null, $fullresponse=false, $timeout=300, $connecttimeout=20, $skipcertverify=false) {
    global $CFG;

    // some extra security
    $newlines = array("\r", "\n");
    if (is_array($headers) ) {
        foreach ($headers as $key => $value) {
            $headers[$key] = str_replace($newlines, '', $value);
        }
    }
    $url = str_replace($newlines, '', $url);
    if (!preg_match('|^https?://|i', $url)) {
        if ($fullresponse) {
            $response = new object();
            $response->status        = 0;
            $response->headers       = array();
            $response->response_code = 'Invalid protocol specified in url';
            $response->results       = '';
            $response->error         = 'Invalid protocol specified in url';
            return $response;
        } else {
            return false;
        }
    }

    // check if proxy (if used) should be bypassed for this url
    $proxybypass = is_proxybypass($url);

    if (!$ch = curl_init($url)) {
        debugging('Can not init curl.');
        return false;
    }

    // set extra headers
    if (is_array($headers) ) {
        $headers2 = array();
        foreach ($headers as $key => $value) {
            $headers2[] = "$key: $value";
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers2);
    }


    if ($skipcertverify) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    }

    // use POST if requested
    if (is_array($postdata)) {
        foreach ($postdata as $k=>$v) {
            $postdata[$k] = urlencode($k).'='.urlencode($v);
        }
        $postdata = implode('&', $postdata);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connecttimeout);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    if (!ini_get('open_basedir') and !ini_get('safe_mode')) {
        // TODO: add version test for '7.10.5'
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    }

    if (!empty($CFG->proxyhost) and !$proxybypass) {
        // SOCKS supported in PHP5 only
        if (!empty($CFG->proxytype) and ($CFG->proxytype == 'SOCKS5')) {
            if (defined('CURLPROXY_SOCKS5')) {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
            } else {
                curl_close($ch);
                if ($fullresponse) {
                    $response = new object();
                    $response->status        = '0';
                    $response->headers       = array();
                    $response->response_code = 'SOCKS5 proxy is not supported in PHP4';
                    $response->results       = '';
                    $response->error         = 'SOCKS5 proxy is not supported in PHP4';
                    return $response;
                } else {
                    debugging("SOCKS5 proxy is not supported in PHP4.", DEBUG_ALL);
                    return false;
                }
            }
        }

        curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, false);

        if (empty($CFG->proxyport)) {
            curl_setopt($ch, CURLOPT_PROXY, $CFG->proxyhost);
        } else {
            curl_setopt($ch, CURLOPT_PROXY, $CFG->proxyhost.':'.$CFG->proxyport);
        }

        if (!empty($CFG->proxyuser) and !empty($CFG->proxypassword)) {
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, $CFG->proxyuser.':'.$CFG->proxypassword);
            if (defined('CURLOPT_PROXYAUTH')) {
                // any proxy authentication if PHP 5.1
                curl_setopt($ch, CURLOPT_PROXYAUTH, CURLAUTH_BASIC | CURLAUTH_NTLM);
            }
        }
    }

    $data = curl_exec($ch);

    // try to detect encoding problems
    if ((curl_errno($ch) == 23 or curl_errno($ch) == 61) and defined('CURLOPT_ENCODING')) {
        curl_setopt($ch, CURLOPT_ENCODING, 'none');
        $data = curl_exec($ch);
    }

    if (curl_errno($ch)) {
        $error    = curl_error($ch);
        $error_no = curl_errno($ch);
        curl_close($ch);

        if ($fullresponse) {
            $response = new object();
            if ($error_no == 28) {
                $response->status    = '-100'; // mimic snoopy
            } else {
                $response->status    = '0';
            }
            $response->headers       = array();
            $response->response_code = $error;
            $response->results       = '';
            $response->error         = $error;
            return $response;
        } else {
            debugging("cURL request for \"$url\" failed with: $error ($error_no)", DEBUG_ALL);
            return false;
        }

    } else {
        $info = curl_getinfo($ch);
        curl_close($ch);

        if (empty($info['http_code'])) {
            // for security reasons we support only true http connections (Location: file:// exploit prevention)
            $response = new object();
            $response->status        = '0';
            $response->headers       = array();
            $response->response_code = 'Unknown cURL error';
            $response->results       = ''; // do NOT change this!
            $response->error         = 'Unknown cURL error';

        } else {
            // strip redirect headers and get headers array and content
            $data = explode("\r\n\r\n", $data, $info['redirect_count'] + 2);
            $results = array_pop($data);
            $headers = array_pop($data);
            $headers = explode("\r\n", trim($headers));

            $response = new object();;
            $response->status        = (string)$info['http_code'];
            $response->headers       = $headers;
            $response->response_code = $headers[0];
            $response->results       = $results;
            $response->error         = '';
        }

        if ($fullresponse) {
            return $response;
        } else if ($info['http_code'] != 200) {
            debugging("cURL request for \"$url\" failed, HTTP response code: ".$response->response_code, DEBUG_ALL);
            return false;
        } else {
            return $response->results;
        }
    }
}

/**
 * @return array List of information about file types based on extensions.
 *   Associative array of extension (lower-case) to associative array
 *   from 'element name' to data. Current element names are 'type' and 'icon'.
 *   Unknown types should use the 'xxx' entry which includes defaults.
 */
function get_mimetypes_array() {
    static $mimearray = array (
        'xxx'  => array ('type'=>'document/unknown', 'icon'=>'unknown.gif'),
        '3gp'  => array ('type'=>'video/quicktime', 'icon'=>'video.gif'),
        'ai'   => array ('type'=>'application/postscript', 'icon'=>'image.gif'),
        'aif'  => array ('type'=>'audio/x-aiff', 'icon'=>'audio.gif'),
        'aiff' => array ('type'=>'audio/x-aiff', 'icon'=>'audio.gif'),
        'aifc' => array ('type'=>'audio/x-aiff', 'icon'=>'audio.gif'),
        'applescript'  => array ('type'=>'text/plain', 'icon'=>'text.gif'),
        'asc'  => array ('type'=>'text/plain', 'icon'=>'text.gif'),
        'asm'  => array ('type'=>'text/plain', 'icon'=>'text.gif'),
        'au'   => array ('type'=>'audio/au', 'icon'=>'audio.gif'),
        'avi'  => array ('type'=>'video/x-ms-wm', 'icon'=>'avi.gif'),
        'bmp'  => array ('type'=>'image/bmp', 'icon'=>'image.gif'),
        'c'    => array ('type'=>'text/plain', 'icon'=>'text.gif'),
        'cct'  => array ('type'=>'shockwave/director', 'icon'=>'flash.gif'),
        'cpp'  => array ('type'=>'text/plain', 'icon'=>'text.gif'),
        'cs'   => array ('type'=>'application/x-csh', 'icon'=>'text.gif'),
        'css'  => array ('type'=>'text/css', 'icon'=>'text.gif'),
        'csv'  => array ('type'=>'text/csv', 'icon'=>'excel.gif'),
        'dv'   => array ('type'=>'video/x-dv', 'icon'=>'video.gif'),
        'dmg'  => array ('type'=>'application/octet-stream', 'icon'=>'dmg.gif'),

        'doc'  => array ('type'=>'application/msword', 'icon'=>'word.gif'),
        'docx' => array ('type'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'icon'=>'docx.gif'),
        'docm' => array ('type'=>'application/vnd.ms-word.document.macroEnabled.12', 'icon'=>'docm.gif'),
        'dotx' => array ('type'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.template', 'icon'=>'dotx.gif'),
        'dotm' => array ('type'=>'application/vnd.ms-word.template.macroEnabled.12', 'icon'=>'dotm.gif'),

        'dcr'  => array ('type'=>'application/x-director', 'icon'=>'flash.gif'),
        'dif'  => array ('type'=>'video/x-dv', 'icon'=>'video.gif'),
        'dir'  => array ('type'=>'application/x-director', 'icon'=>'flash.gif'),
        'dxr'  => array ('type'=>'application/x-director', 'icon'=>'flash.gif'),
        'eps'  => array ('type'=>'application/postscript', 'icon'=>'pdf.gif'),
        'fdf'  => array ('type'=>'application/pdf', 'icon'=>'pdf.gif'),
        'flv'  => array ('type'=>'video/x-flv', 'icon'=>'video.gif'),
        'gif'  => array ('type'=>'image/gif', 'icon'=>'image.gif'),
        'gtar' => array ('type'=>'application/x-gtar', 'icon'=>'zip.gif'),
        'tgz'  => array ('type'=>'application/g-zip', 'icon'=>'zip.gif'),
        'gz'   => array ('type'=>'application/g-zip', 'icon'=>'zip.gif'),
        'gzip' => array ('type'=>'application/g-zip', 'icon'=>'zip.gif'),
        'h'    => array ('type'=>'text/plain', 'icon'=>'text.gif'),
        'hpp'  => array ('type'=>'text/plain', 'icon'=>'text.gif'),
        'hqx'  => array ('type'=>'application/mac-binhex40', 'icon'=>'zip.gif'),
        'htc'  => array ('type'=>'text/x-component', 'icon'=>'text.gif'),
        'html' => array ('type'=>'text/html', 'icon'=>'html.gif'),
        'xhtml'=> array ('type'=>'application/xhtml+xml', 'icon'=>'html.gif'),
        'htm'  => array ('type'=>'text/html', 'icon'=>'html.gif'),
        'ico'  => array ('type'=>'image/vnd.microsoft.icon', 'icon'=>'image.gif'),
        'ics'  => array ('type'=>'text/calendar', 'icon'=>'text.gif'),
        'isf'  => array ('type'=>'application/inspiration', 'icon'=>'isf.gif'),
        'ist'  => array ('type'=>'application/inspiration.template', 'icon'=>'isf.gif'),
        'java' => array ('type'=>'text/plain', 'icon'=>'text.gif'),
        'jcb'  => array ('type'=>'text/xml', 'icon'=>'jcb.gif'),
        'jcl'  => array ('type'=>'text/xml', 'icon'=>'jcl.gif'),
        'jcw'  => array ('type'=>'text/xml', 'icon'=>'jcw.gif'),
        'jmt'  => array ('type'=>'text/xml', 'icon'=>'jmt.gif'),
        'jmx'  => array ('type'=>'text/xml', 'icon'=>'jmx.gif'),
        'jpe'  => array ('type'=>'image/jpeg', 'icon'=>'image.gif'),
        'jpeg' => array ('type'=>'image/jpeg', 'icon'=>'image.gif'),
        'jpg'  => array ('type'=>'image/jpeg', 'icon'=>'image.gif'),
        'jqz'  => array ('type'=>'text/xml', 'icon'=>'jqz.gif'),
        'js'   => array ('type'=>'application/x-javascript', 'icon'=>'text.gif'),
        'latex'=> array ('type'=>'application/x-latex', 'icon'=>'text.gif'),
        'm'    => array ('type'=>'text/plain', 'icon'=>'text.gif'),
        'mov'  => array ('type'=>'video/quicktime', 'icon'=>'video.gif'),
        'movie'=> array ('type'=>'video/x-sgi-movie', 'icon'=>'video.gif'),
        'm3u'  => array ('type'=>'audio/x-mpegurl', 'icon'=>'audio.gif'),
        'mp3'  => array ('type'=>'audio/mp3', 'icon'=>'audio.gif'),
        'mp4'  => array ('type'=>'video/mp4', 'icon'=>'video.gif'),
        'mpeg' => array ('type'=>'video/mpeg', 'icon'=>'video.gif'),
        'mpe'  => array ('type'=>'video/mpeg', 'icon'=>'video.gif'),
        'mpg'  => array ('type'=>'video/mpeg', 'icon'=>'video.gif'),

        'odt'  => array ('type'=>'application/vnd.oasis.opendocument.text', 'icon'=>'odt.gif'),
        'ott'  => array ('type'=>'application/vnd.oasis.opendocument.text-template', 'icon'=>'odt.gif'),
        'oth'  => array ('type'=>'application/vnd.oasis.opendocument.text-web', 'icon'=>'odt.gif'),
        'odm'  => array ('type'=>'application/vnd.oasis.opendocument.text-master', 'icon'=>'odm.gif'),
        'odg'  => array ('type'=>'application/vnd.oasis.opendocument.graphics', 'icon'=>'odg.gif'),
        'otg'  => array ('type'=>'application/vnd.oasis.opendocument.graphics-template', 'icon'=>'odg.gif'),
        'odp'  => array ('type'=>'application/vnd.oasis.opendocument.presentation', 'icon'=>'odp.gif'),
        'otp'  => array ('type'=>'application/vnd.oasis.opendocument.presentation-template', 'icon'=>'odp.gif'),
        'ods'  => array ('type'=>'application/vnd.oasis.opendocument.spreadsheet', 'icon'=>'ods.gif'),
        'ots'  => array ('type'=>'application/vnd.oasis.opendocument.spreadsheet-template', 'icon'=>'ods.gif'),
        'odc'  => array ('type'=>'application/vnd.oasis.opendocument.chart', 'icon'=>'odc.gif'),
        'odf'  => array ('type'=>'application/vnd.oasis.opendocument.formula', 'icon'=>'odf.gif'),
        'odb'  => array ('type'=>'application/vnd.oasis.opendocument.database', 'icon'=>'odb.gif'),
        'odi'  => array ('type'=>'application/vnd.oasis.opendocument.image', 'icon'=>'odi.gif'),

        'pct'  => array ('type'=>'image/pict', 'icon'=>'image.gif'),
        'pdf'  => array ('type'=>'application/pdf', 'icon'=>'pdf.gif'),
        'php'  => array ('type'=>'text/plain', 'icon'=>'text.gif'),
        'pic'  => array ('type'=>'image/pict', 'icon'=>'image.gif'),
        'pict' => array ('type'=>'image/pict', 'icon'=>'image.gif'),
        'png'  => array ('type'=>'image/png', 'icon'=>'image.gif'),

        'pps'  => array ('type'=>'application/vnd.ms-powerpoint', 'icon'=>'powerpoint.gif'),
        'ppt'  => array ('type'=>'application/vnd.ms-powerpoint', 'icon'=>'powerpoint.gif'),
        'pptx' => array ('type'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'icon'=>'pptx.gif'),
        'pptm' => array ('type'=>'application/vnd.ms-powerpoint.presentation.macroEnabled.12', 'icon'=>'pptm.gif'),
        'potx' => array ('type'=>'application/vnd.openxmlformats-officedocument.presentationml.template', 'icon'=>'potx.gif'),
        'potm' => array ('type'=>'application/vnd.ms-powerpoint.template.macroEnabled.12', 'icon'=>'potm.gif'),
        'ppam' => array ('type'=>'application/vnd.ms-powerpoint.addin.macroEnabled.12', 'icon'=>'ppam.gif'),
        'ppsx' => array ('type'=>'application/vnd.openxmlformats-officedocument.presentationml.slideshow', 'icon'=>'ppsx.gif'),
        'ppsm' => array ('type'=>'application/vnd.ms-powerpoint.slideshow.macroEnabled.12', 'icon'=>'ppsm.gif'),

        'ps'   => array ('type'=>'application/postscript', 'icon'=>'pdf.gif'),
        'qt'   => array ('type'=>'video/quicktime', 'icon'=>'video.gif'),
        'ra'   => array ('type'=>'audio/x-realaudio-plugin', 'icon'=>'audio.gif'),
        'ram'  => array ('type'=>'audio/x-pn-realaudio-plugin', 'icon'=>'audio.gif'),
        'rhb'  => array ('type'=>'text/xml', 'icon'=>'xml.gif'),
        'rm'   => array ('type'=>'audio/x-pn-realaudio-plugin', 'icon'=>'audio.gif'),
        'rtf'  => array ('type'=>'text/rtf', 'icon'=>'text.gif'),
        'rtx'  => array ('type'=>'text/richtext', 'icon'=>'text.gif'),
        'sh'   => array ('type'=>'application/x-sh', 'icon'=>'text.gif'),
        'sit'  => array ('type'=>'application/x-stuffit', 'icon'=>'zip.gif'),
        'smi'  => array ('type'=>'application/smil', 'icon'=>'text.gif'),
        'smil' => array ('type'=>'application/smil', 'icon'=>'text.gif'),
        'sqt'  => array ('type'=>'text/xml', 'icon'=>'xml.gif'),
        'svg'  => array ('type'=>'image/svg+xml', 'icon'=>'image.gif'),
        'svgz' => array ('type'=>'image/svg+xml', 'icon'=>'image.gif'),
        'swa'  => array ('type'=>'application/x-director', 'icon'=>'flash.gif'),
        'swf'  => array ('type'=>'application/x-shockwave-flash', 'icon'=>'flash.gif'),
        'swfl' => array ('type'=>'application/x-shockwave-flash', 'icon'=>'flash.gif'),

        'sxw'  => array ('type'=>'application/vnd.sun.xml.writer', 'icon'=>'odt.gif'),
        'stw'  => array ('type'=>'application/vnd.sun.xml.writer.template', 'icon'=>'odt.gif'),
        'sxc'  => array ('type'=>'application/vnd.sun.xml.calc', 'icon'=>'odt.gif'),
        'stc'  => array ('type'=>'application/vnd.sun.xml.calc.template', 'icon'=>'odt.gif'),
        'sxd'  => array ('type'=>'application/vnd.sun.xml.draw', 'icon'=>'odt.gif'),
        'std'  => array ('type'=>'application/vnd.sun.xml.draw.template', 'icon'=>'odt.gif'),
        'sxi'  => array ('type'=>'application/vnd.sun.xml.impress', 'icon'=>'odt.gif'),
        'sti'  => array ('type'=>'application/vnd.sun.xml.impress.template', 'icon'=>'odt.gif'),
        'sxg'  => array ('type'=>'application/vnd.sun.xml.writer.global', 'icon'=>'odt.gif'),
        'sxm'  => array ('type'=>'application/vnd.sun.xml.math', 'icon'=>'odt.gif'),

        'tar'  => array ('type'=>'application/x-tar', 'icon'=>'zip.gif'),
        'tif'  => array ('type'=>'image/tiff', 'icon'=>'image.gif'),
        'tiff' => array ('type'=>'image/tiff', 'icon'=>'image.gif'),
        'tex'  => array ('type'=>'application/x-tex', 'icon'=>'text.gif'),
        'texi' => array ('type'=>'application/x-texinfo', 'icon'=>'text.gif'),
        'texinfo'  => array ('type'=>'application/x-texinfo', 'icon'=>'text.gif'),
        'tsv'  => array ('type'=>'text/tab-separated-values', 'icon'=>'text.gif'),
        'txt'  => array ('type'=>'text/plain', 'icon'=>'text.gif'),
        'wav'  => array ('type'=>'audio/wav', 'icon'=>'audio.gif'),
        'wmv'  => array ('type'=>'video/x-ms-wmv', 'icon'=>'avi.gif'),
        'asf'  => array ('type'=>'video/x-ms-asf', 'icon'=>'avi.gif'),
        'xdp'  => array ('type'=>'application/pdf', 'icon'=>'pdf.gif'),
        'xfd'  => array ('type'=>'application/pdf', 'icon'=>'pdf.gif'),
        'xfdf' => array ('type'=>'application/pdf', 'icon'=>'pdf.gif'),

        'xls'  => array ('type'=>'application/vnd.ms-excel', 'icon'=>'excel.gif'),
        'xlsx' => array ('type'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'icon'=>'xlsx.gif'),
        'xlsm' => array ('type'=>'application/vnd.ms-excel.sheet.macroEnabled.12', 'icon'=>'xlsm.gif'),
        'xltx' => array ('type'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.template', 'icon'=>'xltx.gif'),
        'xltm' => array ('type'=>'application/vnd.ms-excel.template.macroEnabled.12', 'icon'=>'xltm.gif'),
        'xlsb' => array ('type'=>'application/vnd.ms-excel.sheet.binary.macroEnabled.12', 'icon'=>'xlsb.gif'),
        'xlam' => array ('type'=>'application/vnd.ms-excel.addin.macroEnabled.12', 'icon'=>'xlam.gif'),

        'xml'  => array ('type'=>'application/xml', 'icon'=>'xml.gif'),
        'xsl'  => array ('type'=>'text/xml', 'icon'=>'xml.gif'),
        'zip'  => array ('type'=>'application/zip', 'icon'=>'zip.gif')
    );
    return $mimearray;
}

/**
 * Obtains information about a filetype based on its extension. Will
 * use a default if no information is present about that particular
 * extension.
 *
 * @global object
 * @param string $element Desired information (usually 'icon'
 *   for icon filename or 'type' for MIME type)
 * @param string $filename Filename we're looking up
 * @return string Requested piece of information from array
 */
function mimeinfo($element, $filename) {
    global $CFG;
    $mimeinfo = get_mimetypes_array();

    if (eregi('\.([a-z0-9]+)$', $filename, $match)) {
        if (isset($mimeinfo[strtolower($match[1])][$element])) {
            return $mimeinfo[strtolower($match[1])][$element];
        } else {
            if ($element == 'icon32') {
                if (isset($mimeinfo[strtolower($match[1])]['icon'])) {
                    $filename = substr($mimeinfo[strtolower($match[1])]['icon'], 0, -4);
                } else {
                    $filename = 'unknown';
                }
                $filename .= '-32.png';
                if (file_exists($CFG->dirroot.'/pix/f/'.$filename)) {
                    return $filename;
                } else {
                    return 'unknown-32.png';
                }
            } else {
                return $mimeinfo['xxx'][$element];   // By default
            }
        }
    } else {
        if ($element == 'icon32') {
            return 'unknown-32.png';
        }
        return $mimeinfo['xxx'][$element];   // By default
    }
}

/**
 * Obtains information about a filetype based on the MIME type rather than
 * the other way around.
 *
 * @param string $element Desired information (usually 'icon')
 * @param string $mimetype MIME type we're looking up
 * @return string Requested piece of information from array
 */
function mimeinfo_from_type($element, $mimetype) {
    $mimeinfo = get_mimetypes_array();

    foreach($mimeinfo as $values) {
        if($values['type']==$mimetype) {
            if(isset($values[$element])) {
                return $values[$element];
            }
            break;
        }
    }
    return $mimeinfo['xxx'][$element]; // Default
}

/**
 * Get information about a filetype based on the icon file.
 *
 * @param string $element Desired information (usually 'icon')
 * @param string $icon Icon file path.
 * @param boolean $all return all matching entries (defaults to false - last match)
 * @return string Requested piece of information from array
 */
function mimeinfo_from_icon($element, $icon, $all=false) {
    $mimeinfo = get_mimetypes_array();

    if (preg_match("/\/(.*)/", $icon, $matches)) {
        $icon = $matches[1];
    }
    $info = array($mimeinfo['xxx'][$element]); // Default
    foreach($mimeinfo as $values) {
        if($values['icon']==$icon) {
            if(isset($values[$element])) {
                $info[] = $values[$element];
            }
            //No break, for example for 'excel.gif' we don't want 'csv'!
        }
    }
    if ($all) {
        return $info;
    }
    return array_pop($info); // Return last match (mimicking behaviour/comment inside foreach loop)
}

/**
 * Returns the relative icon path for a given mime type
 *
 * This function should be used in conjuction with $OUTPUT->old_icon_url to produce
 * a return the full path to an icon.
 *
 * <code>
 * $mimetype = 'image/jpg';
 * $icon = $OUTPUT->old_icon_url(file_mimetype_icon($mimetype));
 * echo '<img src="'.$icon.'" alt="'.$mimetype.'" />';
 * </code>
 *
 * @todo When an $OUTPUT->icon method is available this function should be altered
 * to conform with that.
 *
 * @param string $mimetype The mimetype to fetch an icon for
 * @param int $size The size of the icon. Not yet implemented
 * @return string The relative path to the icon
 */
function file_mimetype_icon($mimetype, $size=null) {
    $icon = mimeinfo_from_type('icon', $mimetype);
    $icon = substr($icon, 0, strrpos($icon, '.'));
    if ($size!=null && is_int($size)) {
        $icon .= '-'.$size;
    }
    return 'f/'.$icon;
}

/**
 * Returns the relative icon path for a given file name
 *
 * This function should be used in conjuction with $OUTPUT->old_icon_url to produce
 * a return the full path to an icon.
 *
 * <code>
 * $filename = 'jpg';
 * $icon = $OUTPUT->old_icon_url(file_extension_icon($filename));
 * echo '<img src="'.$icon.'" alt="blah" />';
 * </code>
 *
 * @todo When an $OUTPUT->icon method is available this function should be altered
 * to conform with that.
 * @todo Implement $size
 *
 * @param string filename The filename to get the icon for
 * @param int $size The size of the icon. Defaults to null can also be 32
 * @return string
 */
function file_extension_icon($filename, $size=null) {
    $element = 'icon';
    if ($size!==null) {
        $element .= (string)$size;
    }
    $icon = mimeinfo($element, $filename);
    return 'f/'.$icon;
}

/**
 * Obtains descriptions for file types (e.g. 'Microsoft Word document') from the
 * mimetypes.php language file.
 *
 * @param string $mimetype MIME type (can be obtained using the mimeinfo function)
 * @param bool $capitalise If true, capitalises first character of result
 * @return string Text description
 */
function get_mimetype_description($mimetype,$capitalise=false) {
    $result=get_string($mimetype,'mimetypes');
    // Surrounded by square brackets indicates that there isn't a string for that
    // (maybe there is a better way to find this out?)
    if(strpos($result,'[')===0) {
        $result=get_string('document/unknown','mimetypes');
    }
    if($capitalise) {
        $result=ucfirst($result);
    }
    return $result;
}

/**
 * Reprot file is not found or not accessible
 *
 * @return does not return, terminates script
 */
function send_file_not_found() {
    global $CFG, $COURSE;
    header('HTTP/1.0 404 not found');
    print_error('filenotfound', 'error', $CFG->wwwroot.'/course/view.php?id='.$COURSE->id); //this is not displayed on IIS??
}

/**
 * Handles the sending of temporary file to user, download is forced.
 * File is deleted after abort or succesful sending.
 *
 * @global object
 * @param string $path path to file, preferably from moodledata/temp/something; or content of file itself
 * @param string $filename proposed file name when saving file
 * @param bool $path is content of file
 * @return does not return, script terminated
 */
function send_temp_file($path, $filename, $pathisstring=false) {
    global $CFG;

    // close session - not needed anymore
    @session_get_instance()->write_close();

    if (!$pathisstring) {
        if (!file_exists($path)) {
            header('HTTP/1.0 404 not found');
            print_error('filenotfound', 'error', $CFG->wwwroot.'/');
        }
        // executed after normal finish or abort
        @register_shutdown_function('send_temp_file_finished', $path);
    }

    //IE compatibiltiy HACK!
    if (ini_get('zlib.output_compression')) {
        ini_set('zlib.output_compression', 'Off');
    }

    // if user is using IE, urlencode the filename so that multibyte file name will show up correctly on popup
    if (check_browser_version('MSIE')) {
        $filename = urlencode($filename);
    }

    $filesize = $pathisstring ? strlen($path) : filesize($path);

    @header('Content-Disposition: attachment; filename='.$filename);
    @header('Content-Length: '.$filesize);
    if (strpos($CFG->wwwroot, 'https://') === 0) { //https sites - watch out for IE! KB812935 and KB316431
        @header('Cache-Control: max-age=10');
        @header('Expires: '. gmdate('D, d M Y H:i:s', 0) .' GMT');
        @header('Pragma: ');
    } else { //normal http - prevent caching at all cost
        @header('Cache-Control: private, must-revalidate, pre-check=0, post-check=0, max-age=0');
        @header('Expires: '. gmdate('D, d M Y H:i:s', 0) .' GMT');
        @header('Pragma: no-cache');
    }
    @header('Accept-Ranges: none'); // Do not allow byteserving

    while (@ob_end_flush()); //flush the buffers - save memory and disable sid rewrite
    if ($pathisstring) {
        echo $path;
    } else {
        @readfile($path);
    }

    die; //no more chars to output
}

/**
 * Internal callnack function used by send_temp_file()
 */
function send_temp_file_finished($path) {
    if (file_exists($path)) {
        @unlink($path);
    }
}

/**
 * Handles the sending of file data to the user's browser, including support for
 * byteranges etc.
 *
 * @global object
 * @global object
 * @global object
 * @param string $path Path of file on disk (including real filename), or actual content of file as string
 * @param string $filename Filename to send
 * @param int $lifetime Number of seconds before the file should expire from caches (default 24 hours)
 * @param int $filter 0 (default)=no filtering, 1=all files, 2=html files only
 * @param bool $pathisstring If true (default false), $path is the content to send and not the pathname
 * @param bool $forcedownload If true (default false), forces download of file rather than view in browser/plugin
 * @param string $mimetype Include to specify the MIME type; leave blank to have it guess the type from $filename
 * @param bool $dontdie - return control to caller afterwards. this is not recommended and only used for cleanup tasks.
 *                        if this is passed as true, ignore_user_abort is called.  if you don't want your processing to continue on cancel,
 *                        you must detect this case when control is returned using connection_aborted. Please not that session is closed
 *                        and should not be reopened.
 * @return no return or void, script execution stopped unless $dontdie is true
 */
function send_file($path, $filename, $lifetime = 'default' , $filter=0, $pathisstring=false, $forcedownload=false, $mimetype='', $dontdie=false) {
    global $CFG, $COURSE, $SESSION;

    if ($dontdie) {
        ignore_user_abort(true);
    }

    // MDL-11789, apply $CFG->filelifetime here
    if ($lifetime === 'default') {
        if (!empty($CFG->filelifetime)) {
            $lifetime = $CFG->filelifetime;
        } else {
            $lifetime = 86400;
        }
    }

    session_get_instance()->write_close(); // unlock session during fileserving

    // Use given MIME type if specified, otherwise guess it using mimeinfo.
    // IE, Konqueror and Opera open html file directly in browser from web even when directed to save it to disk :-O
    // only Firefox saves all files locally before opening when content-disposition: attachment stated
    $isFF         = check_browser_version('Firefox', '1.5'); // only FF > 1.5 properly tested
    $mimetype     = ($forcedownload and !$isFF) ? 'application/x-forcedownload' :
                         ($mimetype ? $mimetype : mimeinfo('type', $filename));
    $lastmodified = $pathisstring ? time() : filemtime($path);
    $filesize     = $pathisstring ? strlen($path) : filesize($path);

/* - MDL-13949
    //Adobe Acrobat Reader XSS prevention
    if ($mimetype=='application/pdf' or mimeinfo('type', $filename)=='application/pdf') {
        //please note that it prevents opening of pdfs in browser when http referer disabled
        //or file linked from another site; browser caching of pdfs is now disabled too
        if (!empty($_SERVER['HTTP_RANGE'])) {
            //already byteserving
            $lifetime = 1; // >0 needed for byteserving
        } else if (empty($_SERVER['HTTP_REFERER']) or strpos($_SERVER['HTTP_REFERER'], $CFG->wwwroot)!==0) {
            $mimetype = 'application/x-forcedownload';
            $forcedownload = true;
            $lifetime = 0;
        } else {
            $lifetime = 1; // >0 needed for byteserving
        }
    }
*/

    //IE compatibiltiy HACK!
    if (ini_get('zlib.output_compression')) {
        ini_set('zlib.output_compression', 'Off');
    }

    //try to disable automatic sid rewrite in cookieless mode
    @ini_set("session.use_trans_sid", "false");

    //do not put '@' before the next header to detect incorrect moodle configurations,
    //error should be better than "weird" empty lines for admins/users
    //TODO: should we remove all those @ before the header()? Are all of the values supported on all servers?
    header('Last-Modified: '. gmdate('D, d M Y H:i:s', $lastmodified) .' GMT');

    // if user is using IE, urlencode the filename so that multibyte file name will show up correctly on popup
    if (check_browser_version('MSIE')) {
        $filename = rawurlencode($filename);
    }

    if ($forcedownload) {
        @header('Content-Disposition: attachment; filename="'.$filename.'"');
    } else {
        @header('Content-Disposition: inline; filename="'.$filename.'"');
    }

    if ($lifetime > 0) {
        @header('Cache-Control: max-age='.$lifetime);
        @header('Expires: '. gmdate('D, d M Y H:i:s', time() + $lifetime) .' GMT');
        @header('Pragma: ');

        if (empty($CFG->disablebyteserving) && !$pathisstring && $mimetype != 'text/plain' && $mimetype != 'text/html') {

            @header('Accept-Ranges: bytes');

            if (!empty($_SERVER['HTTP_RANGE']) && strpos($_SERVER['HTTP_RANGE'],'bytes=') !== FALSE) {
                // byteserving stuff - for acrobat reader and download accelerators
                // see: http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.35
                // inspired by: http://www.coneural.org/florian/papers/04_byteserving.php
                $ranges = false;
                if (preg_match_all('/(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $ranges, PREG_SET_ORDER)) {
                    foreach ($ranges as $key=>$value) {
                        if ($ranges[$key][1] == '') {
                            //suffix case
                            $ranges[$key][1] = $filesize - $ranges[$key][2];
                            $ranges[$key][2] = $filesize - 1;
                        } else if ($ranges[$key][2] == '' || $ranges[$key][2] > $filesize - 1) {
                            //fix range length
                            $ranges[$key][2] = $filesize - 1;
                        }
                        if ($ranges[$key][2] != '' && $ranges[$key][2] < $ranges[$key][1]) {
                            //invalid byte-range ==> ignore header
                            $ranges = false;
                            break;
                        }
                        //prepare multipart header
                        $ranges[$key][0] =  "\r\n--".BYTESERVING_BOUNDARY."\r\nContent-Type: $mimetype\r\n";
                        $ranges[$key][0] .= "Content-Range: bytes {$ranges[$key][1]}-{$ranges[$key][2]}/$filesize\r\n\r\n";
                    }
                } else {
                    $ranges = false;
                }
                if ($ranges) {
                    $handle = fopen($filename, 'rb');
                    byteserving_send_file($handle, $mimetype, $ranges, $filesize);
                }
            }
        } else {
            /// Do not byteserve (disabled, strings, text and html files).
            @header('Accept-Ranges: none');
        }
    } else { // Do not cache files in proxies and browsers
        if (strpos($CFG->wwwroot, 'https://') === 0) { //https sites - watch out for IE! KB812935 and KB316431
            @header('Cache-Control: max-age=10');
            @header('Expires: '. gmdate('D, d M Y H:i:s', 0) .' GMT');
            @header('Pragma: ');
        } else { //normal http - prevent caching at all cost
            @header('Cache-Control: private, must-revalidate, pre-check=0, post-check=0, max-age=0');
            @header('Expires: '. gmdate('D, d M Y H:i:s', 0) .' GMT');
            @header('Pragma: no-cache');
        }
        @header('Accept-Ranges: none'); // Do not allow byteserving when caching disabled
    }

    if (empty($filter)) {
        if ($mimetype == 'text/html' && !empty($CFG->usesid)) {
            //cookieless mode - rewrite links
            @header('Content-Type: text/html');
            $path = $pathisstring ? $path : implode('', file($path));
            $path = sid_ob_rewrite($path);
            $filesize = strlen($path);
            $pathisstring = true;
        } else if ($mimetype == 'text/plain') {
            @header('Content-Type: Text/plain; charset=utf-8'); //add encoding
        } else {
            @header('Content-Type: '.$mimetype);
        }
        @header('Content-Length: '.$filesize);
        while (@ob_end_flush()); //flush the buffers - save memory and disable sid rewrite
        if ($pathisstring) {
            echo $path;
        } else {
            @readfile($path);
        }
    } else {     // Try to put the file through filters
        if ($mimetype == 'text/html') {
            $options = new object();
            $options->noclean = true;
            $options->nocache = true; // temporary workaround for MDL-5136
            $text = $pathisstring ? $path : implode('', file($path));

            $text = file_modify_html_header($text);
            $output = format_text($text, FORMAT_HTML, $options, $COURSE->id);
            if (!empty($CFG->usesid)) {
                //cookieless mode - rewrite links
                $output = sid_ob_rewrite($output);
            }

            @header('Content-Length: '.strlen($output));
            @header('Content-Type: text/html');
            while (@ob_end_flush()); //flush the buffers - save memory and disable sid rewrite
            echo $output;
        // only filter text if filter all files is selected
        } else if (($mimetype == 'text/plain') and ($filter == 1)) {
            $options = new object();
            $options->newlines = false;
            $options->noclean = true;
            $text = htmlentities($pathisstring ? $path : implode('', file($path)));
            $output = '<pre>'. format_text($text, FORMAT_MOODLE, $options, $COURSE->id) .'</pre>';
            if (!empty($CFG->usesid)) {
                //cookieless mode - rewrite links
                $output = sid_ob_rewrite($output);
            }

            @header('Content-Length: '.strlen($output));
            @header('Content-Type: text/html; charset=utf-8'); //add encoding
            while (@ob_end_flush()); //flush the buffers - save memory and disable sid rewrite
            echo $output;
        } else {    // Just send it out raw
            @header('Content-Length: '.$filesize);
            @header('Content-Type: '.$mimetype);
            while (@ob_end_flush()); //flush the buffers - save memory and disable sid rewrite
            if ($pathisstring) {
                echo $path;
            }else {
                @readfile($path);
            }
        }
    }
    if ($dontdie) {
        return;
    }
    die; //no more chars to output!!!
}

/**
 * Handles the sending of file data to the user's browser, including support for
 * byteranges etc.
 *
 * @global object
 * @global object
 * @global object
 * @param object $stored_file local file object
 * @param int $lifetime Number of seconds before the file should expire from caches (default 24 hours)
 * @param int $filter 0 (default)=no filtering, 1=all files, 2=html files only
 * @param bool $forcedownload If true (default false), forces download of file rather than view in browser/plugin
 * @param string $filename Override filename
 * @param bool $dontdie - return control to caller afterwards. this is not recommended and only used for cleanup tasks.
 *                        if this is passed as true, ignore_user_abort is called.  if you don't want your processing to continue on cancel,
 *                        you must detect this case when control is returned using connection_aborted. Please not that session is closed
 *                        and should not be reopened.
 * @return void no return or void, script execution stopped unless $dontdie is true
 */
function send_stored_file($stored_file, $lifetime=86400 , $filter=0, $forcedownload=false, $filename=null, $dontdie=false) {
    global $CFG, $COURSE, $SESSION;

    if ($dontdie) {
        ignore_user_abort(true);
    }

    session_get_instance()->write_close(); // unlock session during fileserving

    // Use given MIME type if specified, otherwise guess it using mimeinfo.
    // IE, Konqueror and Opera open html file directly in browser from web even when directed to save it to disk :-O
    // only Firefox saves all files locally before opening when content-disposition: attachment stated
    $filename     = is_null($filename) ? $stored_file->get_filename() : $filename;
    $isFF         = check_browser_version('Firefox', '1.5'); // only FF > 1.5 properly tested
    $mimetype     = ($forcedownload and !$isFF) ? 'application/x-forcedownload' :
                         ($stored_file->get_mimetype() ? $stored_file->get_mimetype() : mimeinfo('type', $filename));
    $lastmodified = $stored_file->get_timemodified();
    $filesize     = $stored_file->get_filesize();

    //IE compatibiltiy HACK!
    if (ini_get('zlib.output_compression')) {
        ini_set('zlib.output_compression', 'Off');
    }

    //try to disable automatic sid rewrite in cookieless mode
    @ini_set("session.use_trans_sid", "false");

    //do not put '@' before the next header to detect incorrect moodle configurations,
    //error should be better than "weird" empty lines for admins/users
    //TODO: should we remove all those @ before the header()? Are all of the values supported on all servers?
    header('Last-Modified: '. gmdate('D, d M Y H:i:s', $lastmodified) .' GMT');

    // if user is using IE, urlencode the filename so that multibyte file name will show up correctly on popup
    if (check_browser_version('MSIE')) {
        $filename = rawurlencode($filename);
    }

    if ($forcedownload) {
        @header('Content-Disposition: attachment; filename="'.$filename.'"');
    } else {
        @header('Content-Disposition: inline; filename="'.$filename.'"');
    }

    if ($lifetime > 0) {
        @header('Cache-Control: max-age='.$lifetime);
        @header('Expires: '. gmdate('D, d M Y H:i:s', time() + $lifetime) .' GMT');
        @header('Pragma: ');

        if (empty($CFG->disablebyteserving) && $mimetype != 'text/plain' && $mimetype != 'text/html') {

            @header('Accept-Ranges: bytes');

            if (!empty($_SERVER['HTTP_RANGE']) && strpos($_SERVER['HTTP_RANGE'],'bytes=') !== FALSE) {
                // byteserving stuff - for acrobat reader and download accelerators
                // see: http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.35
                // inspired by: http://www.coneural.org/florian/papers/04_byteserving.php
                $ranges = false;
                if (preg_match_all('/(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $ranges, PREG_SET_ORDER)) {
                    foreach ($ranges as $key=>$value) {
                        if ($ranges[$key][1] == '') {
                            //suffix case
                            $ranges[$key][1] = $filesize - $ranges[$key][2];
                            $ranges[$key][2] = $filesize - 1;
                        } else if ($ranges[$key][2] == '' || $ranges[$key][2] > $filesize - 1) {
                            //fix range length
                            $ranges[$key][2] = $filesize - 1;
                        }
                        if ($ranges[$key][2] != '' && $ranges[$key][2] < $ranges[$key][1]) {
                            //invalid byte-range ==> ignore header
                            $ranges = false;
                            break;
                        }
                        //prepare multipart header
                        $ranges[$key][0] =  "\r\n--".BYTESERVING_BOUNDARY."\r\nContent-Type: $mimetype\r\n";
                        $ranges[$key][0] .= "Content-Range: bytes {$ranges[$key][1]}-{$ranges[$key][2]}/$filesize\r\n\r\n";
                    }
                } else {
                    $ranges = false;
                }
                if ($ranges) {
                    byteserving_send_file($stored_file->get_content_file_handle(), $mimetype, $ranges, $filesize);
                }
            }
        } else {
            /// Do not byteserve (disabled, strings, text and html files).
            @header('Accept-Ranges: none');
        }
    } else { // Do not cache files in proxies and browsers
        if (strpos($CFG->wwwroot, 'https://') === 0) { //https sites - watch out for IE! KB812935 and KB316431
            @header('Cache-Control: max-age=10');
            @header('Expires: '. gmdate('D, d M Y H:i:s', 0) .' GMT');
            @header('Pragma: ');
        } else { //normal http - prevent caching at all cost
            @header('Cache-Control: private, must-revalidate, pre-check=0, post-check=0, max-age=0');
            @header('Expires: '. gmdate('D, d M Y H:i:s', 0) .' GMT');
            @header('Pragma: no-cache');
        }
        @header('Accept-Ranges: none'); // Do not allow byteserving when caching disabled
    }

    if (empty($filter)) {
        $filtered = false;
        if ($mimetype == 'text/html' && !empty($CFG->usesid)) {
            //cookieless mode - rewrite links
            @header('Content-Type: text/html');
            $text = $stored_file->get_content();
            $text = sid_ob_rewrite($text);
            $filesize = strlen($text);
            $filtered = true;
        } else if ($mimetype == 'text/plain') {
            @header('Content-Type: Text/plain; charset=utf-8'); //add encoding
        } else {
            @header('Content-Type: '.$mimetype);
        }
        @header('Content-Length: '.$filesize);
        while (@ob_end_flush()); //flush the buffers - save memory and disable sid rewrite
        if ($filtered) {
            echo $text;
        } else {
            $stored_file->readfile();
        }

    } else {     // Try to put the file through filters
        if ($mimetype == 'text/html') {
            $options = new object();
            $options->noclean = true;
            $options->nocache = true; // temporary workaround for MDL-5136
            $text = $stored_file->get_content();
            $text = file_modify_html_header($text);
            $output = format_text($text, FORMAT_HTML, $options, $COURSE->id);
            if (!empty($CFG->usesid)) {
                //cookieless mode - rewrite links
                $output = sid_ob_rewrite($output);
            }

            @header('Content-Length: '.strlen($output));
            @header('Content-Type: text/html');
            while (@ob_end_flush()); //flush the buffers - save memory and disable sid rewrite
            echo $output;
        // only filter text if filter all files is selected
        } else if (($mimetype == 'text/plain') and ($filter == 1)) {
            $options = new object();
            $options->newlines = false;
            $options->noclean = true;
            $text = $stored_file->get_content();
            $output = '<pre>'. format_text($text, FORMAT_MOODLE, $options, $COURSE->id) .'</pre>';
            if (!empty($CFG->usesid)) {
                //cookieless mode - rewrite links
                $output = sid_ob_rewrite($output);
            }

            @header('Content-Length: '.strlen($output));
            @header('Content-Type: text/html; charset=utf-8'); //add encoding
            while (@ob_end_flush()); //flush the buffers - save memory and disable sid rewrite
            echo $output;
        } else {    // Just send it out raw
            @header('Content-Length: '.$filesize);
            @header('Content-Type: '.$mimetype);
            while (@ob_end_flush()); //flush the buffers - save memory and disable sid rewrite
            $stored_file->readfile();
        }
    }
    if ($dontdie) {
        return;
    }
    die; //no more chars to output!!!
}

/**
 * Retreievs an array of records from a CSV file and places
 * them into a given table structure
 *
 * @global object
 * @global object
 * @param string $file The path to a CSV file
 * @param string $table The table to retrieve columns from
 * @return bool|array Returns an array of CSV records or false
 */
function get_records_csv($file, $table) {
    global $CFG, $DB;

    if (!$metacolumns = $DB->get_columns($table)) {
        return false;
    }

    if(!($handle = @fopen($file, 'r'))) {
        print_error('get_records_csv failed to open '.$file);
    }

    $fieldnames = fgetcsv($handle, 4096);
    if(empty($fieldnames)) {
        fclose($handle);
        return false;
    }

    $columns = array();

    foreach($metacolumns as $metacolumn) {
        $ord = array_search($metacolumn->name, $fieldnames);
        if(is_int($ord)) {
            $columns[$metacolumn->name] = $ord;
        }
    }

    $rows = array();

    while (($data = fgetcsv($handle, 4096)) !== false) {
        $item = new stdClass;
        foreach($columns as $name => $ord) {
            $item->$name = $data[$ord];
        }
        $rows[] = $item;
    }

    fclose($handle);
    return $rows;
}

/**
 * 
 * @global object
 * @global object
 * @param string $file The file to put the CSV content into
 * @param array $records An array of records to write to a CSV file
 * @param string $table The table to get columns from
 * @return bool success
 */
function put_records_csv($file, $records, $table = NULL) {
    global $CFG, $DB;

    if (empty($records)) {
        return true;
    }

    $metacolumns = NULL;
    if ($table !== NULL && !$metacolumns = $DB->get_columns($table)) {
        return false;
    }

    echo "x";

    if(!($fp = @fopen($CFG->dataroot.'/temp/'.$file, 'w'))) {
        print_error('put_records_csv failed to open '.$file);
    }

    $proto = reset($records);
    if(is_object($proto)) {
        $fields_records = array_keys(get_object_vars($proto));
    }
    else if(is_array($proto)) {
        $fields_records = array_keys($proto);
    }
    else {
        return false;
    }
    echo "x";

    if(!empty($metacolumns)) {
        $fields_table = array_map(create_function('$a', 'return $a->name;'), $metacolumns);
        $fields = array_intersect($fields_records, $fields_table);
    }
    else {
        $fields = $fields_records;
    }

    fwrite($fp, implode(',', $fields));
    fwrite($fp, "\r\n");

    foreach($records as $record) {
        $array  = (array)$record;
        $values = array();
        foreach($fields as $field) {
            if(strpos($array[$field], ',')) {
                $values[] = '"'.str_replace('"', '\"', $array[$field]).'"';
            }
            else {
                $values[] = $array[$field];
            }
        }
        fwrite($fp, implode(',', $values)."\r\n");
    }

    fclose($fp);
    return true;
}


/**
 * Recursively delete the file or folder with path $location. That is,
 * if it is a file delete it. If it is a folder, delete all its content
 * then delete it. If $location does not exist to start, that is not
 * considered an error.
 *
 * @param $location the path to remove.
 * @return bool
 */
function fulldelete($location) {
    if (is_dir($location)) {
        $currdir = opendir($location);
        while (false !== ($file = readdir($currdir))) {
            if ($file <> ".." && $file <> ".") {
                $fullfile = $location."/".$file;
                if (is_dir($fullfile)) {
                    if (!fulldelete($fullfile)) {
                        return false;
                    }
                } else {
                    if (!unlink($fullfile)) {
                        return false;
                    }
                }
            }
        }
        closedir($currdir);
        if (! rmdir($location)) {
            return false;
        }

    } else if (file_exists($location)) {
        if (!unlink($location)) {
            return false;
        }
    }
    return true;
}

/**
 * Send requested byterange of file.
 *
 * @param object $handle A file handle
 * @param string $mimetype The mimetype for the output
 * @param array $ranges An array of ranges to send
 * @param string $filesize The size of the content if only one range is used
 */
function byteserving_send_file($handle, $mimetype, $ranges, $filesize) {
    $chunksize = 1*(1024*1024); // 1MB chunks - must be less than 2MB!
    if ($handle === false) {
        die;
    }
    if (count($ranges) == 1) { //only one range requested
        $length = $ranges[0][2] - $ranges[0][1] + 1;
        @header('HTTP/1.1 206 Partial content');
        @header('Content-Length: '.$length);
        @header('Content-Range: bytes '.$ranges[0][1].'-'.$ranges[0][2].'/'.$filesize);
        @header('Content-Type: '.$mimetype);
        while (@ob_end_flush()); //flush the buffers - save memory and disable sid rewrite
        $buffer = '';
        fseek($handle, $ranges[0][1]);
        while (!feof($handle) && $length > 0) {
            @set_time_limit(60*60); //reset time limit to 60 min - should be enough for 1 MB chunk
            $buffer = fread($handle, ($chunksize < $length ? $chunksize : $length));
            echo $buffer;
            flush();
            $length -= strlen($buffer);
        }
        fclose($handle);
        die;
    } else { // multiple ranges requested - not tested much
        $totallength = 0;
        foreach($ranges as $range) {
            $totallength += strlen($range[0]) + $range[2] - $range[1] + 1;
        }
        $totallength += strlen("\r\n--".BYTESERVING_BOUNDARY."--\r\n");
        @header('HTTP/1.1 206 Partial content');
        @header('Content-Length: '.$totallength);
        @header('Content-Type: multipart/byteranges; boundary='.BYTESERVING_BOUNDARY);
        //TODO: check if "multipart/x-byteranges" is more compatible with current readers/browsers/servers
        while (@ob_end_flush()); //flush the buffers - save memory and disable sid rewrite
        foreach($ranges as $range) {
            $length = $range[2] - $range[1] + 1;
            echo $range[0];
            $buffer = '';
            fseek($handle, $range[1]);
            while (!feof($handle) && $length > 0) {
                @set_time_limit(60*60); //reset time limit to 60 min - should be enough for 1 MB chunk
                $buffer = fread($handle, ($chunksize < $length ? $chunksize : $length));
                echo $buffer;
                flush();
                $length -= strlen($buffer);
            }
        }
        echo "\r\n--".BYTESERVING_BOUNDARY."--\r\n";
        fclose($handle);
        die;
    }
}

/**
 * add includes (js and css) into uploaded files
 * before returning them, useful for themes and utf.js includes
 *
 * @global object
 * @param string $text text to search and replace
 * @return string text with added head includes
 */
function file_modify_html_header($text) {
    // first look for <head> tag
    global $CFG;

    $stylesheetshtml = '';
    foreach ($CFG->stylesheets as $stylesheet) {
        $stylesheetshtml .= '<link rel="stylesheet" type="text/css" href="'.$stylesheet.'" />'."\n";
    }

    $ufo = '';
    if (filter_is_enabled('filter/mediaplugin')) {
        // this script is needed by most media filter plugins.
        $ufo = ajax_get_link_to_script($CFG->wwwroot . '/lib/ufo.js');
    }

    preg_match('/\<head\>|\<HEAD\>/', $text, $matches);
    if ($matches) {
        $replacement = '<head>'.$ufo.$stylesheetshtml;
        $text = preg_replace('/\<head\>|\<HEAD\>/', $replacement, $text, 1);
        return $text;
    }

    // if not, look for <html> tag, and stick <head> right after
    preg_match('/\<html\>|\<HTML\>/', $text, $matches);
    if ($matches) {
        // replace <html> tag with <html><head>includes</head>
        $replacement = '<html>'."\n".'<head>'.$ufo.$stylesheetshtml.'</head>';
        $text = preg_replace('/\<html\>|\<HTML\>/', $replacement, $text, 1);
        return $text;
    }

    // if not, look for <body> tag, and stick <head> before body
    preg_match('/\<body\>|\<BODY\>/', $text, $matches);
    if ($matches) {
        $replacement = '<head>'.$ufo.$stylesheetshtml.'</head>'."\n".'<body>';
        $text = preg_replace('/\<body\>|\<BODY\>/', $replacement, $text, 1);
        return $text;
    }

    // if not, just stick a <head> tag at the beginning
    $text = '<head>'.$ufo.$stylesheetshtml.'</head>'."\n".$text;
    return $text;
}

/**
 * RESTful cURL class
 *
 * This is a wrapper class for curl, it is quite easy to use:
 * <code>
 * $c = new curl;
 * // enable cache
 * $c = new curl(array('cache'=>true));
 * // enable cookie
 * $c = new curl(array('cookie'=>true));
 * // enable proxy
 * $c = new curl(array('proxy'=>true));
 *
 * // HTTP GET Method
 * $html = $c->get('http://example.com');
 * // HTTP POST Method
 * $html = $c->post('http://example.com/', array('q'=>'words', 'name'=>'moodle'));
 * // HTTP PUT Method
 * $html = $c->put('http://example.com/', array('file'=>'/var/www/test.txt');
 * </code>
 *
 * @package moodlecore
 * @author Dongsheng Cai <dongsheng@cvs.moodle.org>
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 */

class curl {
    /** @var bool */
    public  $cache    = false;
    public  $proxy    = false;
    /** @var string */
    public  $version  = '0.4 dev';
    /** @var array */
    public  $response = array();
    public  $header   = array();
    /** @var string */
    public  $info;
    public  $error;
    
    /** @var array */
    private $options;
    /** @var string */
    private $proxy_host = '';
    private $proxy_auth = '';
    private $proxy_type = '';
    /** @var bool */
    private $debug    = false;
    private $cookie   = false;

    /**
     * @global object
     * @param array $options
     */
    public function __construct($options = array()){
        global $CFG;
        if (!function_exists('curl_init')) {
            $this->error = 'cURL module must be enabled!';
            trigger_error($this->error, E_USER_ERROR);
            return false;
        }
        // the options of curl should be init here.
        $this->resetopt();
        if (!empty($options['debug'])) {
            $this->debug = true;
        }
        if(!empty($options['cookie'])) {
            if($options['cookie'] === true) {
                $this->cookie = $CFG->dataroot.'/curl_cookie.txt';
            } else {
                $this->cookie = $options['cookie'];
            }
        }
        if (!empty($options['cache'])) {
            if (class_exists('curl_cache')) {
                if (!empty($options['module_cache'])) {
                    $this->cache = new curl_cache($options['module_cache']);
                } else {
                    $this->cache = new curl_cache('misc');
                }
            }
        }
        if (!empty($CFG->proxyhost)) {
            if (empty($CFG->proxyport)) {
                $this->proxy_host = $CFG->proxyhost;
            } else {
                $this->proxy_host = $CFG->proxyhost.':'.$CFG->proxyport;
            }
            if (!empty($CFG->proxyuser) and !empty($CFG->proxypassword)) {
                $this->proxy_auth = $CFG->proxyuser.':'.$CFG->proxypassword;
                $this->setopt(array(
                            'proxyauth'=> CURLAUTH_BASIC | CURLAUTH_NTLM,
                            'proxyuserpwd'=>$this->proxy_auth));
            }
            if (!empty($CFG->proxytype)) {
                if ($CFG->proxytype == 'SOCKS5') {
                    $this->proxy_type = CURLPROXY_SOCKS5;
                } else {
                    $this->proxy_type = CURLPROXY_HTTP;
                    $this->setopt(array('httpproxytunnel'=>false));
                }
                $this->setopt(array('proxytype'=>$this->proxy_type));
            }
        }
        if (!empty($this->proxy_host)) {
            $this->proxy = array('proxy'=>$this->proxy_host);
        }
    }
    /**
     * Resets the CURL options that have already been set
     */
    public function resetopt(){
        $this->options = array();
        $this->options['CURLOPT_USERAGENT']         = 'MoodleBot/1.0';
        // True to include the header in the output
        $this->options['CURLOPT_HEADER']            = 0;
        // True to Exclude the body from the output
        $this->options['CURLOPT_NOBODY']            = 0;
        // TRUE to follow any "Location: " header that the server
        // sends as part of the HTTP header (note this is recursive,
        // PHP will follow as many "Location: " headers that it is sent,
        // unless CURLOPT_MAXREDIRS is set).
        $this->options['CURLOPT_FOLLOWLOCATION']    = 1;
        $this->options['CURLOPT_MAXREDIRS']         = 10;
        $this->options['CURLOPT_ENCODING']          = '';
        // TRUE to return the transfer as a string of the return
        // value of curl_exec() instead of outputting it out directly.
        $this->options['CURLOPT_RETURNTRANSFER']    = 1;
        $this->options['CURLOPT_BINARYTRANSFER']    = 0;
        $this->options['CURLOPT_SSL_VERIFYPEER']    = 0;
        $this->options['CURLOPT_SSL_VERIFYHOST']    = 2;
        $this->options['CURLOPT_CONNECTTIMEOUT']    = 30;
    }

    /**
     * Reset Cookie
     */
    public function resetcookie() {
        if (!empty($this->cookie)) {
            if (is_file($this->cookie)) {
                $fp = fopen($this->cookie, 'w');
                if (!empty($fp)) {
                    fwrite($fp, '');
                    fclose($fp);
                }
            }
        }
    }

    /**
     * Set curl options
     *
     * @param array $options If array is null, this function will
     * reset the options to default value.
     *
     */
    public function setopt($options = array()) {
        if (is_array($options)) {
            foreach($options as $name => $val){
                if (stripos($name, 'CURLOPT_') === false) {
                    $name = strtoupper('CURLOPT_'.$name);
                }
                $this->options[$name] = $val;
            }
        }
    }
    /**
     * Reset http method
     *
     */
    public function cleanopt(){
        unset($this->options['CURLOPT_HTTPGET']);
        unset($this->options['CURLOPT_POST']);
        unset($this->options['CURLOPT_POSTFIELDS']);
        unset($this->options['CURLOPT_PUT']);
        unset($this->options['CURLOPT_INFILE']);
        unset($this->options['CURLOPT_INFILESIZE']);
        unset($this->options['CURLOPT_CUSTOMREQUEST']);
    }

    /**
     * Set HTTP Request Header
     *
     * @param array $headers
     *
     */
    public function setHeader($header) {
        if (is_array($header)){
            foreach ($header as $v) {
                $this->setHeader($v);
            }
        } else {
            $this->header[] = $header;
        }
    }
    /**
     * Set HTTP Response Header
     *
     */
    public function getResponse(){
        return $this->response;
    }
    /**
     * private callback function
     * Formatting HTTP Response Header
     *
     * @param mixed $ch Apparently not used
     * @param string $header
     * @return int The strlen of the header
     */
    private function formatHeader($ch, $header)
    {
        $this->count++;
        if (strlen($header) > 2) {
            list($key, $value) = explode(" ", rtrim($header, "\r\n"), 2);
            $key = rtrim($key, ':');
            if (!empty($this->response[$key])) {
                if (is_array($this->response[$key])){
                    $this->response[$key][] = $value;
                } else {
                    $tmp = $this->response[$key];
                    $this->response[$key] = array();
                    $this->response[$key][] = $tmp;
                    $this->response[$key][] = $value;

                }
            } else {
                $this->response[$key] = $value;
            }
        }
        return strlen($header);
    }

    /**
     * Set options for individual curl instance
     *
     * @param object $curl A curl handle
     * @param array $options
     * @return object The curl handle
     */
    private function apply_opt($curl, $options) {
        // Clean up
        $this->cleanopt();
        // set cookie
        if (!empty($this->cookie) || !empty($options['cookie'])) {
            $this->setopt(array('cookiejar'=>$this->cookie,
                            'cookiefile'=>$this->cookie
                             ));
        }

        // set proxy
        if (!empty($this->proxy) || !empty($options['proxy'])) {
            $this->setopt($this->proxy);
        }
        $this->setopt($options);
        // reset before set options
        curl_setopt($curl, CURLOPT_HEADERFUNCTION, array(&$this,'formatHeader'));
        // set headers
        if (empty($this->header)){
            $this->setHeader(array(
                'User-Agent: MoodleBot/1.0',
                'Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7',
                'Connection: keep-alive'
                ));
        }
        curl_setopt($curl, CURLOPT_HTTPHEADER, $this->header);

        if ($this->debug){
            echo '<h1>Options</h1>';
            var_dump($this->options);
            echo '<h1>Header</h1>';
            var_dump($this->header);
        }

        // set options
        foreach($this->options as $name => $val) {
            if (is_string($name)) {
                $name = constant(strtoupper($name));
            }
            curl_setopt($curl, $name, $val);
        }
        return $curl;
    }
    /**
     * Download multiple files in parallel
     *
     * Calls {@link multi()} with specific download headers
     *
     * <code>
     * $c = new curl;
     * $c->download(array(
     *              array('url'=>'http://localhost/', 'file'=>fopen('a', 'wb')),
     *              array('url'=>'http://localhost/20/', 'file'=>fopen('b', 'wb'))
     *              ));
     * </code>
     *
     * @param array $requests An array of files to request
     * @param array $options An array of options to set
     * @return array An array of results
     */
    public function download($requests, $options = array()) {
        $options['CURLOPT_BINARYTRANSFER'] = 1;
        $options['RETURNTRANSFER'] = false;
        return $this->multi($requests, $options);
    }
    /*
     * Mulit HTTP Requests
     * This function could run multi-requests in parallel.
     *
     * @param array $requests An array of files to request
     * @param array $options An array of options to set
     * @return array An array of results
     */
    protected function multi($requests, $options = array()) {
        $count   = count($requests);
        $handles = array();
        $results = array();
        $main    = curl_multi_init();
        for ($i = 0; $i < $count; $i++) {
            $url = $requests[$i];
            foreach($url as $n=>$v){
                $options[$n] = $url[$n];
            }
            $handles[$i] = curl_init($url['url']);
            $this->apply_opt($handles[$i], $options);
            curl_multi_add_handle($main, $handles[$i]);
        }
        $running = 0;
        do {
            curl_multi_exec($main, $running);
        } while($running > 0);
        for ($i = 0; $i < $count; $i++) {
            if (!empty($optins['CURLOPT_RETURNTRANSFER'])) {
                $results[] = true;
            } else {
                $results[] = curl_multi_getcontent($handles[$i]);
            }
            curl_multi_remove_handle($main, $handles[$i]);
        }
        curl_multi_close($main);
        return $results;
    }
    /**
     * Single HTTP Request
     *
     * @param string $url The URL to request
     * @param array $options
     * @return bool
     */
    protected function request($url, $options = array()){
        // create curl instance
        $curl = curl_init($url);
        $options['url'] = $url;
        $this->apply_opt($curl, $options);
        if ($this->cache && $ret = $this->cache->get($this->options)) {
            return $ret;
        } else {
            $ret = curl_exec($curl);
            if ($this->cache) {
                $this->cache->set($this->options, $ret);
            }
        }

        $this->info  = curl_getinfo($curl);
        $this->error = curl_error($curl);

        if ($this->debug){
            echo '<h1>Return Data</h1>';
            var_dump($ret);
            echo '<h1>Info</h1>';
            var_dump($this->info);
            echo '<h1>Error</h1>';
            var_dump($this->error);
        }

        curl_close($curl);

        if (empty($this->error)){
            return $ret;
        } else {
            return $this->error;
            // exception is not ajax friendly
            //throw new moodle_exception($this->error, 'curl');
        }
    }

    /**
     * HTTP HEAD method
     *
     * @see request() 
     *
     * @param string $url
     * @param array $options
     * @return bool
     */
    public function head($url, $options = array()){
        $options['CURLOPT_HTTPGET'] = 0;
        $options['CURLOPT_HEADER']  = 1;
        $options['CURLOPT_NOBODY']  = 1;
        return $this->request($url, $options);
    }

    /**
     * HTTP POST method
     *
     * @param string $url
     * @param array|string $params 
     * @param array $options
     * @return bool
     */
    public function post($url, $params = '', $options = array()){
        $options['CURLOPT_POST']       = 1;
        if (is_array($params)) {
            $this->_tmp_file_post_params = array();
            foreach ($params as $key => $value) {
                if ($value instanceof stored_file) {
                    $value->add_to_curl_request($this, $key);
                } else {
                    $this->_tmp_file_post_params[$key] = $value;
                }
            }
            $options['CURLOPT_POSTFIELDS'] = $this->_tmp_file_post_params;
            unset($this->_tmp_file_post_params);
        } else {
            // $params is the raw post data
            $options['CURLOPT_POSTFIELDS'] = $params;
        }
        return $this->request($url, $options);
    }

    /**
     * HTTP GET method
     *
     * @param string $url
     * @param array $params 
     * @param array $options
     * @return bool
     */
    public function get($url, $params = array(), $options = array()){
        $options['CURLOPT_HTTPGET'] = 1;

        if (!empty($params)){
            $url .= (stripos($url, '?') !== false) ? '&' : '?';
            $url .= http_build_query($params, '', '&');
        }
        return $this->request($url, $options);
    }

    /**
     * HTTP PUT method
     *
     * @param string $url
     * @param array $params 
     * @param array $options
     * @return bool
     */
    public function put($url, $params = array(), $options = array()){
        $file = $params['file'];
        if (!is_file($file)){
            return null;
        }
        $fp   = fopen($file, 'r');
        $size = filesize($file);
        $options['CURLOPT_PUT']        = 1;
        $options['CURLOPT_INFILESIZE'] = $size;
        $options['CURLOPT_INFILE']     = $fp;
        if (!isset($this->options['CURLOPT_USERPWD'])){
            $this->setopt(array('CURLOPT_USERPWD'=>'anonymous: noreply@moodle.org'));
        }
        $ret = $this->request($url, $options);
        fclose($fp);
        return $ret;
    }

    /**
     * HTTP DELETE method
     *
     * @param string $url
     * @param array $params 
     * @param array $options
     * @return bool
     */
    public function delete($url, $param = array(), $options = array()){
        $options['CURLOPT_CUSTOMREQUEST'] = 'DELETE';
        if (!isset($options['CURLOPT_USERPWD'])) {
            $options['CURLOPT_USERPWD'] = 'anonymous: noreply@moodle.org';
        }
        $ret = $this->request($url, $options);
        return $ret;
    }
    /**
     * HTTP TRACE method
     *
     * @param string $url
     * @param array $options
     * @return bool
     */
    public function trace($url, $options = array()){
        $options['CURLOPT_CUSTOMREQUEST'] = 'TRACE';
        $ret = $this->request($url, $options);
        return $ret;
    }
    /**
     * HTTP OPTIONS method
     *
     * @param string $url
     * @param array $options
     * @return bool
     */
    public function options($url, $options = array()){
        $options['CURLOPT_CUSTOMREQUEST'] = 'OPTIONS';
        $ret = $this->request($url, $options);
        return $ret;
    }
    public function get_info() {
        return $this->info;
    }
}

/**
 * This class is used by cURL class, use case:
 *
 * <code>
 * $CFG->repositorycacheexpire = 120;
 * $CFG->curlcache = 120;
 *
 * $c = new curl(array('cache'=>true), 'module_cache'=>'repository');
 * $ret = $c->get('http://www.google.com');
 * </code>
 *
 * @package moodlecore
 * @subpackage file
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class curl_cache {
    /** @var string */
    public $dir = '';
    /**
     *
     * @global object
     * @param string @module which module is using curl_cache
     *
     */
    function __construct($module = 'repository'){
        global $CFG;
        if (!empty($module)) {
            $this->dir = $CFG->dataroot.'/cache/'.$module.'/';
        } else {
            $this->dir = $CFG->dataroot.'/cache/misc/';
        }
        if (!file_exists($this->dir)) {
            mkdir($this->dir, 0700, true);
        }
        if ($module == 'repository') {
            if (empty($CFG->repositorycacheexpire)) {
                $CFG->repositorycacheexpire = 120;
            }
            $this->ttl = $CFG->repositorycacheexpire;
        } else {
            if (empty($CFG->curlcache)) {
                $CFG->curlcache = 120;
            }
            $this->ttl = $CFG->curlcache;
        }
    }

    /**
     * @todo Document this function
     *
     * @global object
     * @global object
     * @param mixed $param
     * @return bool|string
     */
    public function get($param){
        global $CFG, $USER;
        $this->cleanup($this->ttl);
        $filename = 'u'.$USER->id.'_'.md5(serialize($param));
        if(file_exists($this->dir.$filename)) {
            $lasttime = filemtime($this->dir.$filename);
            if(time()-$lasttime > $this->ttl)
            {
                return false;
            } else {
                $fp = fopen($this->dir.$filename, 'r');
                $size = filesize($this->dir.$filename);
                $content = fread($fp, $size);
                return unserialize($content);
            }
        }
        return false;
    }

    /**
     * @todo Document this function
     *
     * @global object
     * @global object
     * @param mixed $param
     * @param mixed $val
     */
    public function set($param, $val){
        global $CFG, $USER;
        $filename = 'u'.$USER->id.'_'.md5(serialize($param));
        $fp = fopen($this->dir.$filename, 'w');
        fwrite($fp, serialize($val));
        fclose($fp);
    }

    /**
     * @todo Document this function
     *
     * @param int $expire The number os seconds before expiry
     */
    public function cleanup($expire){
        if($dir = opendir($this->dir)){
            while (false !== ($file = readdir($dir))) {
                if(!is_dir($file) && $file != '.' && $file != '..') {
                    $lasttime = @filemtime($this->dir.$file);
                    if(time() - $lasttime > $expire){
                        @unlink($this->dir.$file);
                    }
                }
            }
        }
    }
    /**
     * delete current user's cache file
     *
     * @global object
     * @global object
     */
    public function refresh(){
        global $CFG, $USER;
        if($dir = opendir($this->dir)){
            while (false !== ($file = readdir($dir))) {
                if(!is_dir($file) && $file != '.' && $file != '..') {
                    if(strpos($file, 'u'.$USER->id.'_')!==false){
                        @unlink($this->dir.$file);
                    }
                }
            }
        }
    }
}

/**
 * @todo Document this class
 *
 * @package moodlecore
 * @subpackage file
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class file_type_to_ext {
    /**
     * @todo Document this function
     * @global object
     * @param string $file
     */
    public function __construct($file = '') {
        global $CFG;
        if (empty($file)) {
            $this->file = $CFG->libdir.'/file/file_types.mm';
        } else {
            $this->file = $file;
        }
        $this->tree = array();
        $this->result = array();
    }

    /**
     * @todo Document this function
     * @param array $parent
     * @param array $types
     */
    private function _browse_nodes($parent, $types) {
        $key = (string)$parent['TEXT'];
        if(isset($parent->node)) {
            $this->tree[$key] = array();
            if (in_array((string)$parent['TEXT'], $types)) {
                $this->_select_nodes($parent, $this->result);
            } else {
                foreach($parent->node as $v){
                    $this->_browse_nodes($v, $types);
                }
            }
        } else {
            $this->tree[] = $key;
        }
    }

    /**
     * @todo Document this function
     * @param array $parent
     */
    private function _select_nodes($parent){
        if(isset($parent->node)) {
            foreach($parent->node as $v){
                $this->_select_nodes($v, $this->result);
            }
        } else {
            $this->result[] = (string)$parent['TEXT'];
        }
    }


    /**
     * @todo Document this function
     * @param array $types
     * @return mixed
     */
    public function get_file_ext($types) {
        $this->result = array();
        if ((is_array($types) && in_array('*', $types)) ||
            $types == '*' || empty($types)) {
            return array('*');
        }
        foreach ($types as $key=>$value){
            if (strpos($value, '.') !== false) {
                $this->result[] = $value;
                unset($types[$key]);
            }
        }
        if (file_exists($this->file)) {
            $xml = simplexml_load_file($this->file);
            foreach($xml->node->node as $v){
                if (in_array((string)$v['TEXT'], $types)) {
                    $this->_select_nodes($v);
                } else {
                    $this->_browse_nodes($v, $types);
                }
            }
        } else {
            exit('Failed to open test.xml.');
        }
        return $this->result;
    }
}
