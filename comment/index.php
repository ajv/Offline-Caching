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

/*
 * Comments management interface
 */
require_once('../config.php');
require_once($CFG->libdir.'/adminlib.php');
require_once('lib.php');
$context = get_context_instance(CONTEXT_SYSTEM);
require_capability('moodle/comment:delete', $context);
$PAGE->requires->yui_lib('yahoo')->in_head();
$PAGE->requires->yui_lib('dom')->in_head();
$PAGE->requires->yui_lib('event')->in_head();
$PAGE->requires->yui_lib('animation')->in_head();
$PAGE->requires->yui_lib('json')->in_head();
$PAGE->requires->yui_lib('connection')->in_head();
$PAGE->requires->js('comment/admin.js')->in_head();

$action     = optional_param('action', '', PARAM_ALPHA);
$commentid  = optional_param('commentid', 0, PARAM_INT);
$commentids = optional_param('commentids', '', PARAM_ALPHANUMEXT);
$page       = optional_param('page', 0, PARAM_INT);
$manager = new comment_manager();

if (!empty($action)) {
    confirm_sesskey();
}

if ($action === 'delete') {
    // delete a single comment
    if (!empty($commentid)) {
        if ($manager->delete_comment($commentid)) {
            redirect($CFG->httpswwwroot.'/comment/', get_string('deleted'));
        } else {
            $err = 'cannotdeletecomment';
        }
    }
    // delete a list of comments
    if (!empty($commentids)) {
        if ($manager->delete_comments($commentids)) {
            die('yes');
        } else {
            die('no');
        }
    }
}

admin_externalpage_setup('comments');
admin_externalpage_print_header();
print_heading(get_string('comments'));
if (!empty($err)) {
    print_error($err, 'error', $CFG->httpswwwroot.'/comment/');
}
if (empty($action)) {
    $manager->print_comments($page);
    echo '<div class="mdl-align">';
    echo '<button id="comments_delete">'.get_string('delete').'</button>';
    echo '<div>';
}
admin_externalpage_print_footer();
