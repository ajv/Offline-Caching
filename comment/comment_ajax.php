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
 * Handling all ajax request for comments API
 */
require_once('../config.php');
require_once($CFG->libdir.'/commentlib.php');

$courseid  = optional_param('courseid',  SITEID, PARAM_INT);
$contextid = optional_param('contextid', SYSCONTEXTID, PARAM_INT);

$context   = get_context_instance_by_id($contextid);
$cm        = get_coursemodule_from_id('', $context->instanceid);
require_login($courseid, true, $cm);

$err = new stdclass;

if (!confirm_sesskey()) {
    $err->error = get_string('invalidsesskey');
    die(json_encode($err));
}

if (!isloggedin()){
    $err->error = get_string('loggedinnot');
    die(json_encode($err));
}

if (isguestuser()) {
    $err->error = get_string('loggedinnot');
    die(json_encode($err));
}

$action    = optional_param('action',    '',     PARAM_ALPHA);
$area      = optional_param('area',      '',     PARAM_ALPHAEXT);
$client_id = optional_param('client_id', '',     PARAM_RAW);
$commentid = optional_param('commentid', -1,     PARAM_INT);
$content   = optional_param('content',   '',     PARAM_RAW);
$itemid    = optional_param('itemid',    '',     PARAM_INT);
$page      = optional_param('page',      0,      PARAM_INT);

if (!empty($client_id)) {
    $cmt = new stdclass;
    $cmt->contextid = $contextid;
    $cmt->courseid  = $courseid;
    $cmt->area      = $area;
    $cmt->itemid    = $itemid;
    $cmt->client_id = $client_id;
    $comment = new comment($cmt);
}
switch ($action) {
case 'add':
    $cmt = $comment->add($content);
    if (!empty($cmt) && is_object($cmt)) {
        $cmt->client_id = $client_id;
        echo json_encode($cmt);
    } else if ($cmt === COMMENT_ERROR_DB) {
        $err->error = get_string('dbupdatefailed');
        echo json_encode($err);
    } else if ($cmt === COMMENT_ERROR_MODULE_REJECT) {
        $err->error = get_string('modulererejectcomment');
        echo json_encode($err);
    } else if ($cmt === COMMENT_ERROR_INSUFFICIENT_CAPS) {
        $err->error = get_string('nopermissiontocomment');
        echo json_encode($err);
    }
    break;
case 'delete':
    $result = $comment->delete($commentid);
    if ($result === true) {
        echo json_encode(array('client_id'=>$client_id, 'commentid'=>$commentid));
    } else if ($result == COMMENT_ERROR_INSUFFICIENT_CAPS) {
        $err->error = get_string('nopermissiontoeditcomment');
        echo json_encode($err);
    } else if ($result == COMMENT_ERROR_DB) {
        $err->error = get_string('dbupdatefailed');
        echo json_encode($err);
    }
    break;
case 'get':
default:
    $ret = array();
    $comments = $comment->get_comments($page);
    $ret['list'] = $comments;
    $ret['pagination'] = $comment->get_pagination($page);
    $ret['client_id']  = $client_id;
    echo json_encode($ret);
    exit;
}
