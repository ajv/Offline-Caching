<?php //$Id$
require_once('../../config.php');
require_once($CFG->libdir.'/adminlib.php');
require_once($CFG->dirroot.'/message/lib.php');
require_once('user_message_form.php');

$msg     = optional_param('msg', '', PARAM_CLEAN);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

require_login();
admin_externalpage_setup('userbulk');
require_capability('moodle/site:readallmessages', get_context_instance(CONTEXT_SYSTEM));

$return = $CFG->wwwroot.'/'.$CFG->admin.'/user/user_bulk.php';

if (empty($SESSION->bulk_users)) {
    redirect($return);
}

if (empty($CFG->messaging)) {
    print_error('messagingdisable', 'error');
}

//TODO: add support for large number of users

if ($confirm and !empty($msg) and confirm_sesskey()) {
    $in = implode(',', $SESSION->bulk_users);
    if ($rs = $DB->get_recordset_select('user', "id IN ($in)", null)) {
        foreach ($rs as $user) {
            message_post_message($USER, $user, $msg, FORMAT_HTML, 'direct');
        }
        $rs->close();
    }
    redirect($return);
}

// disable html editor if not enabled in preferences
if (!get_user_preferences('message_usehtmleditor', 0)) {
    $CFG->htmleditor = '';
}

$msgform = new user_message_form('user_bulk_message.php');

if ($msgform->is_cancelled()) {
    redirect($return);

} else if ($formdata = $msgform->get_data()) {
    $options = new object();
    $options->para     = false;
    $options->newlines = true;
    $options->smiley   = false;

    $msg = format_text($formdata->messagebody, $formdata->format, $options);

    $in = implode(',', $SESSION->bulk_users);
    $userlist = $DB->get_records_select_menu('user', "id IN ($in)", null, 'fullname', 'id,'.$DB->sql_fullname().' AS fullname');
    $usernames = implode(', ', $userlist);
    admin_externalpage_print_header();
    echo $OUTPUT->heading(get_string('confirmation', 'admin'));
    echo $OUTPUT->box($msg, 'boxwidthnarrow boxaligncenter generalbox', 'preview');
    
    $formcontinue = html_form::make_button('user_bulk_message.php', array('confirm' => 1, 'msg' => $msg), get_string('yes'));
    $formcancel = html_form::make_button('user_bulk.php', $optionsno, get_string('no'), 'get');
    echo $OUTPUT->confirm(get_string('confirmmessage', 'bulkusers', $usernames), $formcontinue, $formcancel);
    echo $OUTPUT->footer();
    die;
}

admin_externalpage_print_header();
$msgform->display();
echo $OUTPUT->footer();
?>
