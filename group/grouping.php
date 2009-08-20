<?php
/**
 * Create grouping OR edit grouping settings.
 *
 * @copyright &copy; 2006 The Open University
 * @author N.D.Freear AT open.ac.uk
 * @author J.White AT open.ac.uk
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package groups
 */
require_once('../config.php');
require_once('lib.php');
require_once('grouping_form.php');

/// get url variables
$courseid = optional_param('courseid', 0, PARAM_INT);
$id       = optional_param('id', 0, PARAM_INT);
$delete   = optional_param('delete', 0, PARAM_BOOL);
$confirm  = optional_param('confirm', 0, PARAM_BOOL);

if ($id) {
    if (!$grouping = $DB->get_record('groupings', array('id'=>$id))) {
        print_error('invalidgroupid');
    }
    $grouping->description = clean_text($grouping->description);
    if (empty($courseid)) {
        $courseid = $grouping->courseid;

    } else if ($courseid != $grouping->courseid) {
        print_error('invalidcourseid');
    }

    if (!$course = $DB->get_record('course', array('id'=>$courseid))) {
        print_error('invalidcourseid');
    }

} else {
    if (!$course = $DB->get_record('course', array('id'=>$courseid))) {
        print_error('invalidcourseid');
    }
    $grouping = new object();
    $grouping->courseid = $course->id;
}

require_login($course);
$context = get_context_instance(CONTEXT_COURSE, $course->id);
require_capability('moodle/course:managegroups', $context);

$returnurl = $CFG->wwwroot.'/group/groupings.php?id='.$course->id;


if ($id and $delete) {
    if (!$confirm) {
        print_header(get_string('deletegrouping', 'group'), get_string('deletegrouping', 'group'));
        $optionsyes = array('id'=>$id, 'delete'=>1, 'courseid'=>$courseid, 'sesskey'=>sesskey(), 'confirm'=>1);
        $optionsno  = array('id'=>$courseid);
        $formcontinue = html_form::make_button('grouping.php', $optionsyes, get_string('yes'), 'get');
        $formcancel = html_form::make_button('groupings.php', $optionsno, get_string('no'), 'get');
        echo $OUTPUT->confirm(get_string('deletegroupingconfirm', 'group', $grouping->name), $formcontinue, $formcancel);
        echo $OUTPUT->footer();
        die;

    } else if (confirm_sesskey()){
        if (groups_delete_grouping($id)) {
            redirect($returnurl);
        } else {
            print_error('erroreditgrouping', 'group', $returnurl);
        }
    }
}

/// First create the form
$editform = new grouping_form();
$editform->set_data($grouping);

if ($editform->is_cancelled()) {
    redirect($returnurl);

} elseif ($data = $editform->get_data()) {
    $success = true;

    if ($data->id) {
        groups_update_grouping($data);

    } else {
        groups_create_grouping($data);
    }

    redirect($returnurl);

}

$strgroupings    = get_string('groupings', 'group');
$strparticipants = get_string('participants');

if ($id) {
    $strheading = get_string('editgroupingsettings', 'group');
} else {
    $strheading = get_string('creategrouping', 'group');
}

$navlinks = array(array('name'=>$strparticipants, 'link'=>$CFG->wwwroot.'/user/index.php?id='.$courseid, 'type'=>'misc'),
                  array('name'=>$strgroupings, 'link'=>$CFG->wwwroot.'/group/groupings.php?id='.$courseid, 'type'=>'misc'),
                  array('name'=>$strheading, 'link'=>'', 'type'=>'misc'));
$navigation = build_navigation($navlinks);

/// Print header
print_header_simple($strgroupings, ': '.$strgroupings, $navigation, '', '', true, '', navmenu($course));


echo $OUTPUT->heading($strheading);
$editform->display();
echo $OUTPUT->footer();

?>
