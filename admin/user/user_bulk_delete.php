<?php //$Id$
/**
* script for bulk user delete operations
*/

require_once('../../config.php');
require_once($CFG->libdir.'/adminlib.php');

$confirm = optional_param('confirm', 0, PARAM_BOOL);

require_login();
admin_externalpage_setup('userbulk');
require_capability('moodle/user:delete', get_context_instance(CONTEXT_SYSTEM));

$return = $CFG->wwwroot.'/'.$CFG->admin.'/user/user_bulk.php';

if (empty($SESSION->bulk_users)) {
    redirect($return);
}

admin_externalpage_print_header();

//TODO: add support for large number of users

if ($confirm and confirm_sesskey()) {
    $primaryadmin = get_admin();

    $in = implode(',', $SESSION->bulk_users);
    if ($rs = $DB->get_recordset_select('user', "id IN ($in)", null)) {
        foreach ($rs as $user) {
            if ($primaryadmin->id != $user->id and $USER->id != $user->id and delete_user($user)) {
                unset($SESSION->bulk_users[$user->id]);
            } else {
                notify(get_string('deletednot', '', fullname($user, true)));
            }
        }
        $rs->close;
    }
    session_gc(); // remove stale sessions
    redirect($return, get_string('changessaved'));

} else {
    $in = implode(',', $SESSION->bulk_users);
    $userlist = $DB->get_records_select_menu('user', "id IN ($in)", null, 'fullname', 'id,'.$DB->sql_fullname().' AS fullname');
    $usernames = implode(', ', $userlist);
    $optionsyes = array();
    $optionsyes['confirm'] = 1;
    $optionsyes['sesskey'] = sesskey();
    echo $OUTPUT->heading(get_string('confirmation', 'admin'));
    notice_yesno(get_string('deletecheckfull', '', $usernames), 'user_bulk_delete.php', 'user_bulk.php', $optionsyes, NULL, 'post', 'get');
}

echo $OUTPUT->footer();
?>
