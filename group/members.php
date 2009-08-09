<?php // $Id$
/**
 * Add/remove members from group.
 *
 * @copyright &copy; 2006 The Open University and others
 * @author N.D.Freear AT open.ac.uk
 * @author J.White AT open.ac.uk and others
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package groups
 */
require_once(dirname(__FILE__) . '/../config.php');
require_once(dirname(__FILE__) . '/lib.php');
require_once($CFG->dirroot . '/user/selector/lib.php');
require_once($CFG->dirroot . '/course/lib.php');

$groupid = required_param('group', PARAM_INT);

if (!$group = $DB->get_record('groups', array('id'=>$groupid))) {
    print_error('invalidgroupid');
}

if (!$course = $DB->get_record('course', array('id'=>$group->courseid))) {
    print_error('invalidcourse');
}
$courseid = $course->id;

require_login($course);
$context = get_context_instance(CONTEXT_COURSE, $courseid);
require_capability('moodle/course:managegroups', $context);

$returnurl = $CFG->wwwroot.'/group/index.php?id='.$courseid.'&group='.$group->id;

if (optional_param('cancel', false, PARAM_BOOL)) {
    redirect($returnurl);
}

$groupmembersselector = new group_members_selector('removeselect',
        array('groupid' => $groupid, 'courseid' => $course->id));
$groupmembersselector->set_extra_fields(array());
$potentialmembersselector = new group_non_members_selector('addselect',
        array('groupid' => $groupid, 'courseid' => $course->id));
$potentialmembersselector->set_extra_fields(array());
        
if (optional_param('add', false, PARAM_BOOL) && confirm_sesskey()) {
    $userstoadd = $potentialmembersselector->get_selected_users();
    if (!empty($userstoadd)) {
        foreach ($userstoadd as $user) {
            if (!groups_add_member($groupid, $user->id)) {
                print_error('erroraddremoveuser', 'group', $returnurl);
            }
            $groupmembersselector->invalidate_selected_users();
            $potentialmembersselector->invalidate_selected_users();
        }
    }
}

if (optional_param('remove', false, PARAM_BOOL) && confirm_sesskey()) {
    $userstoremove = $groupmembersselector->get_selected_users();
    if (!empty($userstoremove)) {
        foreach ($userstoremove as $user) {
            if (!groups_remove_member($groupid, $user->id)) {
                print_error('erroraddremoveuser', 'group', $returnurl);
            }
            $groupmembersselector->invalidate_selected_users();
            $potentialmembersselector->invalidate_selected_users();
        }
    }
}

// Print the page and form
$strgroups = get_string('groups');
$strparticipants = get_string('participants');
$stradduserstogroup = get_string('adduserstogroup', 'group');
$strusergroupmembership = get_string('usergroupmembership', 'group');

$groupname = format_string($group->name);

$navlinks = array();
$navlinks[] = array('name' => $strparticipants, 'link' => "$CFG->wwwroot/user/index.php?id=$courseid", 'type' => 'misc');
$navlinks[] = array('name' => $strgroups, 'link' => "$CFG->wwwroot/group/index.php?id=$courseid", 'type' => 'misc');
$navlinks[] = array('name' => $stradduserstogroup, 'link' => null, 'type' => 'misc');
$navigation = build_navigation($navlinks);

$PAGE->requires->js('group/clientlib.js');
$PAGE->requires->js_function_call('init_add_remove_members_page');
print_header("$course->shortname: $strgroups", $course->fullname, $navigation, '', '', true, '', user_login_string($course, $USER));
check_theme_arrows();
?>

<div id="addmembersform">
    <h3 class="main"><?php print_string('adduserstogroup', 'group'); echo ": $groupname"; ?></h3>

    <form id="assignform" method="post" action="<?php echo $CFG->wwwroot; ?>/group/members.php?group=<?php echo $groupid; ?>">
    <div>
    <input type="hidden" name="sesskey" value="<?php p(sesskey()); ?>" />

    <table class="generaltable generalbox groupmanagementtable boxaligncenter" summary="">
    <tr>
      <td id='existingcell'>
          <p>
            <label for="removeselect"><?php print_string('groupmembers', 'group'); ?></label>
          </p>
          <?php $groupmembersselector->display(); ?>
          </td>
      <td id='buttonscell'>
        <p class="arrow_button">
            <input name="add" id="add" type="submit" value="<?php echo $THEME->larrow.'&nbsp;'.get_string('add'); ?>" title="<?php print_string('add'); ?>" /><br />
            <input name="remove" id="remove" type="submit" value="<?php echo get_string('remove').'&nbsp;'.$THEME->rarrow; ?>" title="<?php print_string('remove'); ?>" />
        </p>
      </td>
      <td id='potentialcell'>
          <p>
            <label for="addselect"><?php print_string('potentialmembs', 'group'); ?></label>
          </p>
          <?php $potentialmembersselector->display(); ?>
      </td>
    </tr>
    <tr><td colspan="3" id='backcell'>
        <input type="submit" name="cancel" value="<?php print_string('backtogroups', 'group'); ?>" />
    </td></tr>
    </table>
    </div>
    </form>
</div>

<?php
    echo $OUTPUT->footer();
?>
