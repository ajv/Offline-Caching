<?php

require_once dirname(dirname(__FILE__)) . '/config.php';
httpsrequired();

$username = required_param('u', PARAM_ALPHANUM);
$sesskey = sesskey();

// if you are logged in then you shouldn't be here
if (isloggedin() and !isguestuser()) {
    redirect( $CFG->wwwroot.'/', get_string('loginalready'), 5);
}
$navigation = build_navigation(array(array('name' => 'MNET ID Provider', 'link' => null, 'type' => 'misc')));

print_header('MNET ID Provider', 'MNET ID Provider', $navigation, 'form.email' );

if ($form = data_submitted() and confirm_sesskey()) {
    if ($user = $DB->get_record('user', array('username'=>$username, 'email'=>$form->email))) {
        if (!empty($user->mnethostid) and $host = $DB->get_record('mnet_host', array('id'=>$user->mnethostid))) {
            notice("You should be able to login at your <a href=\"{$host->wwwroot}/login/\">{$host->name}</a> provider.");
        }
    }
}

echo '<p>&nbsp;</p>';
echo $OUTPUT->box_start('generalbox boxaligncenter boxwidthnormal');

?>
  <form method="post">
    <input type="hidden" name="sesskey" value="<?php echo $sesskey; ?>">
    <?php echo get_string('email') ?>:
    <input type="text" name="email" size="" maxlength="100" />
    <input type="submit" value="Find Login" />
  </form>
<?php

echo $OUTPUT->box_end();
echo $OUTPUT->footer();

?>
