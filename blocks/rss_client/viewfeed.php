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
 * Script to let a user edit the properties of a particular RSS feed.
 *
 * @package   moodlecore
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->libdir .'/simplepie/moodle_simplepie.php');

require_login();
if (isguest()) {
    echo_error('guestsarenotallowed');
}

$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);
$courseid = optional_param('courseid', 0, PARAM_INTEGER);
$rssid = required_param('rssid', PARAM_INTEGER);

if ($courseid = SITEID) {
    $courseid = 0;
}
if ($courseid) {
    $course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
    $PAGE->set_course($course);
    $context = $PAGE->context;
} else {
    $context = get_context_instance(CONTEXT_SYSTEM);
    $PAGE->set_context($context);
}

$urlparams = array('rssid' => $rssid);
$manageparams = array();
if ($courseid) {
    $urlparams['courseid'] = $courseid;
    $manageparams[] = 'courseid=$courseid';
}
if ($returnurl) {
    $urlparams['returnurl'] = $returnurl;
    $manageparams = 'returnurl=' . $returnurl;
}
if ($manageparams) {
    $manageparams = '?' . implode('&', $manageparams);
} else {
    $manageparams = '';
}
$PAGE->set_url('blocks/rss_client/viewfeed.php', $urlparams);
$PAGE->set_generaltype('popup');

$rssrecord = $DB->get_record('block_rss_client', array('id' => $rssid), '*', MUST_EXIST);

$rss = new moodle_simplepie($rssrecord->url);

if ($rss->error()) {
    debugging($rss->error());
    print_error('errorfetchingrssfeed');
}

$strviewfeed = get_string('viewfeed', 'block_rss_client');

$PAGE->set_title($strviewfeed);
$PAGE->set_heading($strviewfeed);

$navlinks = array(
    array('name' => get_string('administration'), 'link' => "$CFG->wwwroot/$CFG->admin/index.php", 'type' => 'misc'),
    array('name' => get_string('managemodules'), 'link' => null, 'type' => 'misc'),
    array('name' => get_string('blocks'), 'link' => null, 'type' => 'misc'),
    array('name' => get_string('feedstitle', 'block_rss_client'), 'link' => "$CFG->wwwroot/$CFG->admin/settings.php?section=blocksettingrss_client", 'type' => 'misc'),
    array('name' => get_string('managefeeds', 'block_rss_client'), 'link' => $CFG->wwwroot . '/blocks/rss_client/managefeeds.php' . $manageparams, 'type' => 'misc'),
    array('name' => $strviewfeed, 'link' => null,  'type' => 'misc'),
);
$navigation = build_navigation($navlinks);

echo $OUTPUT->header($navigation);


if (!empty($rssrecord->preferredtitle)) {
    $feedtitle = $rssrecord->preferredtitle;
} else {
    $feedtitle =  $rss->get_title();
}
echo '<table align="center" width="50%" cellspacing="1">'."\n";
echo '<tr><td colspan="2"><strong>'. $feedtitle .'</strong></td></tr>'."\n";
foreach ($rss->get_items() as $item) {
    echo '<tr><td valign="middle">'."\n";
    echo '<a href="'. $item->get_link() .'" target="_blank"><strong>'. $item->get_title();
    echo '</strong></a>'."\n";
    echo '</td>'."\n";
    echo '</tr>'."\n";
    echo '<tr><td colspan="2"><small>';
    echo $item->get_description() .'</small></td></tr>'."\n";
}
echo '</table>'."\n";

echo $OUTPUT->footer();
