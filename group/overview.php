<?php // $Id$
/**
 * Print an overview of groupings & group membership
 *
 * @author  Matt Clarkson mattc@catalyst.net.nz
 * @version 0.0.1
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package groups
 */

require_once('../config.php');

$courseid   = required_param('id', PARAM_INT);
$groupid    = optional_param('group', 0, PARAM_INT);
$groupingid = optional_param('grouping', 0, PARAM_INT);

$returnurl = $CFG->wwwroot.'/group/index.php?id='.$courseid;
$rooturl   = $CFG->wwwroot.'/group/overview.php?id='.$courseid;

if (!$course = $DB->get_record('course', array('id'=>$courseid))) {
    print_error('invalidcourse');
}

// Make sure that the user has permissions to manage groups.
require_login($course);

$context = get_context_instance(CONTEXT_COURSE, $courseid);
require_capability('moodle/course:managegroups', $context);

$strgroups           = get_string('groups');
$strparticipants     = get_string('participants');
$stroverview         = get_string('overview', 'group');
$strgrouping         = get_string('grouping', 'group');
$strgroup            = get_string('group', 'group');
$strnotingrouping    = get_string('notingrouping', 'group');
$strfiltergroups     = get_string('filtergroups', 'group');
$strnogroups         = get_string('nogroups', 'group');
$strdescription      = get_string('description');

// Get all groupings
if (empty($CFG->enablegroupings)) {
    $groupings  = array();
    $members    = array(-1 => array()); //groups not in a grouping
    $groupingid = 0;
} else {
    $groupings = $DB->get_records('groupings', array('courseid'=>$courseid), 'name');
    $members = array();
    foreach ($groupings as $grouping) {
        $members[$grouping->id] = array();
    }
    $members[-1] = array(); //groups not in a grouping
}

// Get all groups
$groups = $DB->get_records('groups', array('courseid'=>$courseid), 'name');

$params = array('courseid'=>$courseid);
if ($groupid) {
    $groupwhere = "AND g.id = :groupid";
    $params['groupid']   = $groupid;
} else {
    $groupwhere = "";
}

if (empty($CFG->enablegroupings)) {
    $sql = "SELECT g.id AS groupid, NULL AS groupingid, u.id AS userid, u.firstname, u.lastname, u.idnumber, u.username
              FROM {groups} g
                   LEFT JOIN {groups_members} gm ON g.id = gm.groupid
                   LEFT JOIN {user} u ON gm.userid = u.id
             WHERE g.courseid = :courseid $groupwhere
          ORDER BY g.name, u.lastname, u.firstname";
} else {
    if ($groupingid) {
        $groupingwhere = "AND gg.groupingid = :groupingid";
        $params['groupingid'] = $groupingid;
    } else {
        $groupingwhere = "";
    }
    $sql = "SELECT g.id AS groupid, gg.groupingid, u.id AS userid, u.firstname, u.lastname, u.idnumber, u.username
              FROM {groups} g
                   LEFT JOIN {groupings_groups} gg ON g.id = gg.groupid
                   LEFT JOIN {groups_members} gm ON g.id = gm.groupid
                   LEFT JOIN {user} u ON gm.userid = u.id
             WHERE g.courseid = :courseid $groupwhere $groupingwhere
          ORDER BY g.name, u.lastname, u.firstname";
}

if ($rs = $DB->get_recordset_sql($sql, $params)) {
    foreach ($rs as $row) {
        $user = new object();
        $user->id        = $row->userid;
        $user->firstname = $row->firstname;
        $user->lastname  = $row->lastname;
        $user->username  = $row->username;
        $user->idnumber  = $row->idnumber;
        if (!$row->groupingid) {
            $row->groupingid = -1;
        }
        if (!array_key_exists($row->groupid, $members[$row->groupingid])) {
            $members[$row->groupingid][$row->groupid] = array();
        }
        if(isset($user->id)){
           $members[$row->groupingid][$row->groupid][] = $user;
        }
    }
    $rs->close();
}


// Print the page and form
$navlinks = array(array('name'=>$strparticipants, 'link'=>$CFG->wwwroot.'/user/index.php?id='.$courseid, 'type'=>'misc'),
                  array('name'=>$strgroups, 'link'=>'', 'type'=>'misc'));
$navigation = build_navigation($navlinks);

/// Print header
print_header_simple($strgroups, ': '.$strgroups, $navigation, '', '', true, '', navmenu($course));
// Add tabs
$currenttab = 'overview';
require('tabs.php');

/// Print overview
echo $OUTPUT->heading(format_string($course->shortname) .' '.$stroverview, 3);

echo $strfiltergroups;

if (!empty($CFG->enablegroupings)) {
    $options = array();
    $options[0] = get_string('all');
    foreach ($groupings as $grouping) {
        $options[$grouping->id] = strip_tags(format_string($grouping->name));
    }
    $popupurl = $rooturl.'&group='.$groupid;
    $select = html_select::make_popup_form($popupurl, 'grouping', $options, 'selectgrouping', $groupingid);
    $select->set_label($strgrouping);
    echo $OUTPUT->select($select);
}

$options = array();
$options[0] = get_string('all');
foreach ($groups as $group) {
    $options[$group->id] = strip_tags(format_string($group->name));
}
$popupurl = $rooturl.'&grouping='.$groupingid;
$select = html_select::make_popup_form($popupurl, 'group', $options, 'selectgroup', $groupid);
$select->set_label($strgroup);
echo $OUTPUT->select($select);

/// Print table
$printed = false;
foreach ($members as $gpgid=>$groupdata) {
    if ($groupingid and $groupingid != $gpgid) {
        continue; // do not show
    }
    $table = new object();
    $table->head  = array(get_string('groupscount', 'group', count($groupdata)), get_string('groupmembers', 'group'), get_string('usercount', 'group'));
    $table->size  = array('20%', '70%', '10%');
    $table->align = array('left', 'left', 'center');
    $table->width = '90%';
    $table->data  = array();
    foreach ($groupdata as $gpid=>$users) {
        if ($groupid and $groupid != $gpid) {
            continue;
        }
        $line = array();
        $name = format_string($groups[$gpid]->name);
        $jsdescription = addslashes_js(trim(format_text($groups[$gpid]->description)));
        if (empty($jsdescription)) {
            $line[] = $name;
        } else {
            $jsstrdescription = addslashes_js($strdescription);
            $overlib = "return overlib('$jsdescription', BORDER, 0, FGCLASS, 'description', "
                      ."CAPTIONFONTCLASS, 'caption', CAPTION, '$jsstrdescription');";
            $line[] = '<span onmouseover="'.s($overlib).'" onmouseout="return nd();">'.$name.'</span>';
        }
        $fullnames = array();
        foreach ($users as $user) {
            $fullnames[] = '<a href="'.$CFG->wwwroot.'/user/view.php?id='.$user->id.'&amp;course='.$course->id.'">'.fullname($user, true).'</a>';
        }
        $line[] = implode(', ', $fullnames);
        $line[] = count($users);
        $table->data[] = $line;
    }
    if ($groupid and empty($table->data)) {
        continue;
    }
    if (!empty($CFG->enablegroupings)) {
        if ($gpgid < 0) {
            echo $OUTPUT->heading($strnotingrouping, 3);
        } else {
            echo $OUTPUT->heading(format_string($groupings[$gpgid]->name), 3);
            echo $OUTPUT->box(format_text($groupings[$gpgid]->description), 'generalbox boxwidthnarrow boxaligncenter');
        }
    }
    print_table($table, false);
    $printed = true;
}

echo $OUTPUT->footer();
?>
