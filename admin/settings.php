<?php // $Id$

require_once('../config.php');
require_once($CFG->libdir.'/adminlib.php');

$section = required_param('section', PARAM_SAFEDIR);
$return = optional_param('return','', PARAM_ALPHA);
$adminediting = optional_param('adminedit', -1, PARAM_BOOL);

/// no guest autologin
require_login(0, false);
$PAGE->set_context(get_context_instance(CONTEXT_SYSTEM));
$PAGE->set_url($CFG->admin . '/settings.php', array('section' => $section));
$PAGE->set_pagetype('admin-setting-' . $section);

$adminroot = admin_get_root(); // need all settings
$settingspage = $adminroot->locate($section, true);

if (empty($settingspage) or !($settingspage instanceof admin_settingpage)) {
    print_error('sectionerror', 'admin', "$CFG->wwwroot/$CFG->admin/");
    die;
}

if (!($settingspage->check_access())) {
    print_error('accessdenied', 'admin');
    die;
}

/// WRITING SUBMITTED DATA (IF ANY) -------------------------------------------------------------------------------

$statusmsg = '';
$errormsg  = '';
$focus = '';

if ($data = data_submitted() and confirm_sesskey()) {
    if (admin_write_settings($data)) {
        $statusmsg = get_string('changessaved');
    }

    if (empty($adminroot->errors)) {
        switch ($return) {
            case 'site': redirect("$CFG->wwwroot/");
            case 'admin': redirect("$CFG->wwwroot/$CFG->admin/");
        }
    } else {
        $errormsg = get_string('errorwithsettings', 'admin');
        $firsterror = reset($adminroot->errors);
        $focus = $firsterror->id;
    }
    $adminroot = admin_get_root(true); //reload tree
    $settingspage = $adminroot->locate($section, true);
}

if ($PAGE->user_allowed_editing() && $adminediting != -1) {
    $USER->editing = $adminediting;
}

/// print header stuff ------------------------------------------------------------
if (empty($SITE->fullname)) {
    print_header($settingspage->visiblename, $settingspage->visiblename, '', $focus);
    echo $OUTPUT->box(get_string('configintrosite', 'admin'));

    if ($errormsg !== '') {
        echo $OUTPUT->notification($errormsg);

    } else if ($statusmsg !== '') {
        echo $OUTPUT->notification($statusmsg, 'notifysuccess');
    }

    // ---------------------------------------------------------------------------------------------------------------

    echo '<form action="settings.php" method="post" id="adminsettings">';
    echo '<div class="settingsform clearfix">';
    echo $PAGE->url->hidden_params_out();
    echo '<input type="hidden" name="sesskey" value="'.sesskey().'" />';
    echo '<input type="hidden" name="return" value="'.$return.'" />';

    echo $settingspage->output_html();

    echo '<div class="form-buttons"><input class="form-submit" type="submit" value="'.get_string('savechanges','admin').'" /></div>';

    echo '</div>';
    echo '</form>';

} else {
    if ($PAGE->user_allowed_editing()) {
        $options = $PAGE->url->params();
        if ($PAGE->user_is_editing()) {
            $caption = get_string('blockseditoff');
            $options['adminedit'] = 'off';
        } else {
            $caption = get_string('blocksediton');
            $options['adminedit'] = 'on';
        }
        $buttons = $OUTPUT->button(html_form::make_button($PAGE->url->out(false), $options, $caption, 'get'));
    }

    $visiblepathtosection = array_reverse($settingspage->visiblepath);
    $navlinks = array();
    foreach ($visiblepathtosection as $element) {
        $navlinks[] = array('name' => $element, 'link' => null, 'type' => 'misc');
    }
    $navigation = build_navigation($navlinks);

    print_header("$SITE->shortname: " . implode(": ",$visiblepathtosection), $SITE->fullname, $navigation, $focus, '', true, $buttons, '');

    if ($errormsg !== '') {
        echo $OUTPUT->notification($errormsg);

    } else if ($statusmsg !== '') {
        echo $OUTPUT->notification($statusmsg, 'notifysuccess');
    }

    // ---------------------------------------------------------------------------------------------------------------

    echo '<form action="settings.php" method="post" id="adminsettings">';
    echo '<div class="settingsform clearfix">';
    echo $PAGE->url->hidden_params_out();
    echo '<input type="hidden" name="sesskey" value="'.sesskey().'" />';
    echo '<input type="hidden" name="return" value="'.$return.'" />';
    echo $OUTPUT->heading($settingspage->visiblename);

    echo $settingspage->output_html();

    echo '<div class="form-buttons"><input class="form-submit" type="submit" value="'.get_string('savechanges','admin').'" /></div>';

    echo '</div>';
    echo '</form>';
}

echo $OUTPUT->footer();

?>
