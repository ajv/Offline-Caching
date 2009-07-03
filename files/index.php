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
 * Temporary file manager for all moodle files. To be replaced by something much better.
 *
 * @package    moodlecore
 * @subpackage file
 * @copyright  1999 onwards Martin Dougiamas (http://dougiamas.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../config.php');
require_once($CFG->libdir.'/filelib.php');
require_once($CFG->libdir.'/adminlib.php');

$courseid   = optional_param('id', 0, PARAM_INT);

$contextid  = optional_param('contextid', SYSCONTEXTID, PARAM_INT);
$filearea   = optional_param('filearea', '', PARAM_ALPHAEXT);
$itemid     = optional_param('itemid', -1, PARAM_INT);
$filepath   = optional_param('filepath', '', PARAM_PATH);
$filename   = optional_param('filename', '', PARAM_FILE);

$newdirname = optional_param('newdirname', '', PARAM_FILE);
$delete     = optional_param('delete', 0, PARAM_BOOL);

if ($courseid) {
    if (!$course = $DB->get_record('course', array('id'=>$courseid))) {
        print_error('invalidcourseid');
    }
    if (!$context = get_context_instance(CONTEXT_COURSE, $course->id)) {
        print_error('invalidcontext');
    }
    redirect('index.php?contextid='.$context->id.'&amp;itemid=0&amp;filearea=course_content');
}

if (!$context = get_context_instance_by_id($contextid)) {
    print_error('invalidcontext');
}

require_login();
require_capability('moodle/course:managefiles', $context);

if ($filearea === '') {
    $filearea = null;
}

if ($itemid < 0) {
    $itemid = null;
}

if ($filepath === '') {
    $filepath = null;
}

if ($filename === '') {
    $filename = null;
}

$error = '';

$browser = get_file_browser();

$file_info = $browser->get_file_info($context, $filearea, $itemid, $filepath, $filename);

/// process actions
if ($file_info and $file_info->is_directory() and $file_info->is_writable() and $newdirname !== '' and data_submitted() and confirm_sesskey()) {
    if ($newdir_info = $file_info->create_directory($newdirname, $USER->id)) {
        $params = $newdir_info->get_params_rawencoded();
        $params = implode('&amp;', $params);
        redirect("index.php?$params");
    } else {
        $error = "Could not create new dir"; // TODO: localise
    }
}

if ($file_info and $file_info->is_directory() and $file_info->is_writable() and isset($_FILES['newfile']) and data_submitted() and confirm_sesskey()) {
    $file = $_FILES['newfile'];
    $newfilename = clean_param($file['name'], PARAM_FILE);
    if (is_uploaded_file($_FILES['newfile']['tmp_name'])) {
        try {
            if ($newfile = $file_info->create_file_from_pathname($newfilename, $_FILES['newfile']['tmp_name'], $USER->id)) {
                $params = $file_info->get_params_rawencoded();
                $params = implode('&amp;', $params);
                redirect("index.php?$params");

            } else {
                $error = "Could not create upload file"; // TODO: localise
            }
        } catch (file_exception $e) {
            $error = "Exception: Could not create upload file"; // TODO: localise
        }
    }
}

if ($file_info and $delete) {
    if (!data_submitted() or !confirm_sesskey()) {
        print_header();
        notify(get_string('deletecheckwarning').': '.$file_info->get_visible_name());
        $parent_info = $file_info->get_parent();

        $optionsno  = $parent_info->get_params();
        $optionsyes = $file_info->get_params();
        $optionsyes['delete'] = 1;
        $optionsyes['sesskey'] = sesskey();

        notice_yesno (get_string('deletecheckfiles'), 'index.php', 'index.php', $optionsyes, $optionsno, 'post', 'get');
        print_footer();
        die;
    }

    if ($parent_info = $file_info->get_parent() and $parent_info->is_writable()) {
        if (!$file_info->delete()) {
            $error = "Could not delete file!"; // TODO: localise
        }
        $params = $parent_info->get_params_rawencoded();
        $params = implode('&amp;', $params);
        redirect("index.php?$params", $error);
    }
}


/// print dir listing
html_header($context, $file_info);

if ($error !== '') {
    notify($error);
}

displaydir($file_info);

if ($file_info and $file_info->is_directory() and $file_info->is_writable()) {
    echo '<br />';

    echo '<form action="index.php" method="post"><div>';
    echo '<input type="hidden" name="contextid" value="'.$contextid.'" />';
    echo '<input type="hidden" name="filearea" value="'.$filearea.'" />';
    echo '<input type="hidden" name="itemid" value="'.$itemid.'" />';
    echo '<input type="hidden" name="filepath" value="'.s($filepath).'" />';
    echo '<input type="hidden" name="filename" value="'.s($filename).'" />';
    echo '<input type="hidden" name="sesskey" value="'.sesskey().'" />';
    echo '<input type="text" name="newdirname" value="" />';
    echo '<input type="submit" value="'.get_string('makeafolder').'" />';
    echo '</div></form>';

    echo '<br />';

    echo '<form enctype="multipart/form-data" method="post" action="index.php"><div>';
    echo '<input type="hidden" name="contextid" value="'.$contextid.'" />';
    echo '<input type="hidden" name="filearea" value="'.$filearea.'" />';
    echo '<input type="hidden" name="itemid" value="'.$itemid.'" />';
    echo '<input type="hidden" name="filepath" value="'.s($filepath).'" />';
    echo '<input type="hidden" name="filename" value="'.s($filename).'" />';
    echo '<input type="hidden" name="sesskey" value="'.sesskey().'" />';
    echo '<input name="newfile" type="file" />';
    echo '<input type="submit" value="'.get_string('uploadafile').'" />';
    echo '</div></form>';
}

html_footer();

/// UI functions /////////////////////////

function html_footer() {
    echo '</td></tr></table>';
    print_footer();
}

function html_header($context, $file_info){
    global $CFG, $SITE;

    $navlinks = array();
    $strfiles = get_string("files");

    $navlinks[] = array('name' => $strfiles, 'link' => null, 'type' => 'misc');

    $navigation = build_navigation($navlinks);
    print_header("$SITE->shortname: $strfiles", '', $navigation);

    echo "<table border=\"0\" style=\"margin-left:auto;margin-right:auto\" cellspacing=\"3\" cellpadding=\"3\" width=\"740\">";
    echo "<tr>";
    echo "<td colspan=\"2\">";
}

/// FILE FUNCTIONS ///////////////////////////////////////////////////////////

function print_cell($alignment='center', $text='&nbsp;', $class='') {
    if ($class) {
        $class = ' class="'.$class.'"';
    }
    echo '<td align="'.$alignment.'" style="white-space:nowrap "'.$class.'>'.$text.'</td>';
}

function displaydir($file_info) {
    global $CFG, $OUTPUT;

    $children = $file_info->get_children();
    $parent_info = $file_info->get_parent();

    $strname     = get_string('name');
    $strsize     = get_string('size');
    $strmodified = get_string('modified');
    $strfolder   = get_string('folder');
    $strfile     = get_string('file');
    $strdownload = get_string('download');
    $strdelete   = get_string('delete');
    $straction   = get_string('action');

    $path = array();
    $params = $file_info->get_params_rawencoded();
    $params = implode('&amp;', $params);
    $path[] = $file_info->get_visible_name();

    $level = $parent_info;
    while ($level) {
        $params = $level->get_params_rawencoded();
        $params = implode('&amp;', $params);
        $path[] = '<a href="index.php?'.$params.'">'.$level->get_visible_name().'</a>';
        $level = $level->get_parent();
    }

    $path = array_reverse($path);

    $path = implode (' / ', $path);
    echo $path. ' /';

    echo "<div>";
    echo "<hr/>";
    echo "<table border=\"0\" cellspacing=\"2\" cellpadding=\"2\" width=\"740\" class=\"files\">";
    echo "<tr>";
    echo "<th class=\"header\" scope=\"col\"></th>";
    echo "<th class=\"header name\" scope=\"col\">$strname</th>";
    echo "<th class=\"header size\" scope=\"col\">$strsize</th>";
    echo "<th class=\"header date\" scope=\"col\">$strmodified</th>";
    echo "<th class=\"header commands\" scope=\"col\">$straction</th>";
    echo "</tr>\n";

    $parentwritable = $file_info->is_writable();

    if ($parent_info) {
        $params = $parent_info->get_params_rawencoded();
        $params = implode('&amp;', $params);

        echo "<tr class=\"folder\">";
        print_cell();
        print_cell('left', '<a href="index.php?'.$params.'"><img src="'.$OUTPUT->old_icon_url('f/parent') . '" class="icon" alt="" />&nbsp;'.get_string('parentfolder').'</a>', 'name');
        print_cell();
        print_cell();
        print_cell();

        echo "</tr>";
    }

    if ($children) {
        foreach ($children as $child_info) {
            $filename = $child_info->get_visible_name();
            $filesize = $child_info->get_filesize();
            $filesize = $filesize ? display_size($filesize) : '';
            $filedate = $child_info->get_timemodified();
            $filedate = $filedate ? userdate($filedate) : '';

            $mimetype = $child_info->get_mimetype();

            $params = $child_info->get_params_rawencoded();
            $params = implode('&amp;', $params);

            if ($child_info->is_directory()) {

                echo "<tr class=\"folder\">";
                print_cell();
                print_cell("left", "<a href=\"index.php?$params\"><img src=\"" . $OUTPUT->old_icon_url('f/folder') . "\" class=\"icon\" alt=\"$strfolder\" />&nbsp;".s($filename)."</a>", 'name');
                print_cell("right", $filesize, 'size');
                print_cell("right", $filedate, 'date');
                if ($parentwritable) {
                    print_cell("right", "<a href=\"index.php?$params&amp;sesskey=".sesskey()."&amp;delete=1\"><img src=\"" . $OUTPUT->old_icon_url('t/delete') . "\" class=\"iconsmall\" alt=\"$strdelete\" /></a>", 'command');
                } else {
                    print_cell();
                }
                echo "</tr>";

            } else {

                $icon = str_replace(array('.gif', '.png'), '', mimeinfo_from_type("icon", $mimetype));
                if ($downloadurl = $child_info->get_url(true)) {
                    $downloadurl = "&nbsp;<a href=\"$downloadurl\" title=\"" . get_string('downloadfile') . "\"><img src=\"" . $OUTPUT->old_icon_url('t/down') . "\" class=\"iconsmall\" alt=\"$strdownload\" /></a>";
                } else {
                    $downloadurl = '';
                }

                if ($viewurl = $child_info->get_url()) {
                    $viewurl = "&nbsp;".link_to_popup_window ($viewurl, "display",
                                                     "<img src=\"" . $OUTPUT->old_icon_url('t/preview') . "\" class=\"iconsmall\" alt=\"$strfile\" />&nbsp;",
                                                     480, 640, get_string('viewfileinpopup'), null, true);
                } else {
                    $viewurl = '';
                }



                echo "<tr class=\"file\">";
                print_cell();
                print_cell("left", "<img src=\"" . $OUTPUT->old_icon_url('f/' . $icon) . "\" class=\"icon\" alt=\"$strfile\" />&nbsp;".s($filename).$downloadurl.$viewurl, 'name');
                print_cell("right", $filesize, 'size');
                print_cell("right", $filedate, 'date');
                if ($parentwritable) {
                    print_cell("right", "<a href=\"index.php?$params&amp;sesskey=".sesskey()."&amp;delete=1\"><img src=\"" . $OUTPUT->old_icon_url('t/delete') . "\" class=\"iconsmall\" alt=\"$strdelete\" /></a>", 'command');
                } else {
                    print_cell();
                }
                echo "</tr>";
            }
        }
    }

    echo "</table>";
    echo "</div>";
    echo "<hr/>";

}


