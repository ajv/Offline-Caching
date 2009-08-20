<?php //  $Id$

if (!isset($frm->enrol_cost)) $frm->enrol_cost = '5';
if (!isset($frm->enrol_currency)) $frm->enrol_currency = 'USD';
if (!isset($frm->enrol_mailstudents)) $frm->enrol_mailstudents = '';
if (!isset($frm->enrol_mailteachers)) $frm->enrol_mailteachers = '';
if (!isset($frm->enrol_mailadmins)) $frm->enrol_mailadmins = '';

if (!isset($frm->an_login)) $frm->an_login = '';
if (!isset($frm->an_tran_key)) $frm->an_tran_key = '';
if (!isset($frm->an_password)) $frm->an_password = '';
if (!isset($frm->delete_current)) $frm->delete_current = '';
if (!isset($frm->an_referer)) $frm->an_referer = 'http://';
if (!isset($frm->an_avs)) $frm->an_avs = '';
if (!isset($frm->an_authcode)) $frm->an_authcode = '';
if (!isset($frm->an_test)) $frm->an_test = '';
if (!isset($frm->an_review)) $frm->an_review = '';
if (!isset($frm->an_capture_day)) $frm->an_capture_day = '5';
if (!isset($frm->an_emailexpired)) $frm->an_emailexpired = '2';
if (!isset($frm->an_emailexpiredteacher)) $frm->an_emailexpiredteacher = '';
if (!isset($frm->an_sorttype)) $frm->an_sorttype = 'ttl';

if (isset($CFG->an_cutoff)) {
    $cutoff = intval($CFG->an_cutoff);
    $mins = $cutoff % 60; $hrs = ($cutoff - $mins) / 60;
    $frm->an_cutoff_hour = $hrs; $frm->an_cutoff_min = $mins;
}
if (!isset($frm->an_cutoff_hour)) {
    $timezone = round(get_user_timezone_offset(), 1);
    $frm->an_cutoff_hour = intval($timezone);
    $frm->an_cutoff_min = (intval(round($timezone)) != intval($timezone)) ? 35 : 5;
}

if (!isset($frm->acceptmethods)) {
    $frm->acceptmethods = get_list_of_payment_methods();
    $CFG->an_acceptmethods = implode(',', $frm->acceptmethods);
}

if (!isset($frm->acceptccs)) {
    $frm->acceptccs = array_keys(get_list_of_creditcards());
    $CFG->an_acceptccs = implode(',', $frm->acceptccs);
}

if (!isset($frm->acceptechecktypes)) {
    $frm->acceptechecktypes = get_list_of_bank_account_types();
    $CFG->an_acceptechecktypes = implode(',', $frm->acceptechecktypes);
}

?>

<table cellspacing="0" cellpadding="5" border="0" class="boxaligncenter">

<tr valign="top">
    <td colspan="2" align="right"><a href="../enrol/authorize/index.php"><?php print_string("paymentmanagement", "enrol_authorize") ?></a></td>
</tr>

<tr valign="top"><td colspan="2"><h4><?php print_string("adminauthorizewide", "enrol_authorize") ?></h4></td></tr>

<tr valign="top">
    <td align="right">enrol_cost:</td>
    <td><input type="text" size="5" name="enrol_cost" value="<?php p($frm->enrol_cost) ?>" /><br />
        <?php print_string("costdefault") ?>. <?php print_string("costdefaultdesc", "enrol_authorize") ?></td>
</tr>

<tr valign="top">
    <td align="right">enrol_currency:</td>
    <td><?php 
        $select = html_select::make(get_list_of_currencies(), "enrol_currency", $frm->enrol_currency, false);
        $select->nothingvalue = '';
        echo $OUTPUT->select($select);
        ?>
        <br />
        <?php print_string("currency") ?>
    </td>
</tr>

<tr valign="top"><td colspan="2"><h4><?php print_string("adminauthorizesettings", "enrol_authorize") ?></h4></td></tr>

<tr valign="top">
    <td align="right">&nbsp;&nbsp;</td>
    <td><?php print_string("logininfo", "enrol_authorize") ?><br />
    <?php if (!optional_param('verifyaccount', 0, PARAM_INT) && isset($mconfig->an_login) && (isset($mconfig->an_tran_key) || isset($mconfig->an_password))) { ?>
        <br /><a href="enrol_config.php?enrol=authorize&amp;verifyaccount=1"><b><?php print_string("verifyaccount", "enrol_authorize") ?></b></a><br />
    <?php } ?></td>
</tr>

<tr valign="top">
    <td align="right">an_login:<br /><?php echo (isset($mconfig->an_login)) ? '<span style="color:green">'.get_string('dataentered', 'enrol_authorize').'</span>' : ''; ?></td>
    <td><?php print_string("anlogin", "enrol_authorize") ?><br /><input type="text" name="an_login" size="26" value="" /><sup>*</sup></td>
</tr>

<tr valign="top">
    <td align="right">an_tran_key:<br /><?php echo (isset($mconfig->an_tran_key)) ? '<span style="color:green">'.get_string('dataentered', 'enrol_authorize').'</span>' : ''; ?></td>
    <td><?php print_string("antrankey", "enrol_authorize") ?><br /><input type="text" name="an_tran_key" size="26" value="" /><sup>#1</sup></td>
</tr>

<tr valign="top">
    <td align="right">an_password:<br /><?php echo (isset($mconfig->an_password)) ? '<span style="color:green">'.get_string('dataentered', 'enrol_authorize').'</span>' : ''; ?></td>
    <td><?php print_string("anpassword", "enrol_authorize") ?><br /><input type="text" name="an_password" size="26" value="" /><sup>#2</sup></td>
</tr>

<tr valign="top">
    <td align="right">delete_current:</td>
    <td><?php echo $OUTPUT->checkbox(html_select_option::make_checkbox('1', !empty($frm->delete_current)), 'delete_current');?> <br />
        <?php print_string("deletecheck", "moodle", get_string('oldpassword')) ?><br /><hr /></td>
</tr>

<tr valign="top">
    <td align="right">an_referer:</td>
    <td><input type="text" name="an_referer" size="35" value="<?php p($frm->an_referer) ?>" /><br />
        <?php print_string("anreferer", "enrol_authorize") ?></td>
</tr>

<tr valign="top">
    <td align="right">an_cutoff:</td>
    <td><?php
        $curtime = make_timestamp(2000,1,1,$frm->an_cutoff_hour,$frm->an_cutoff_min);
        $hourselector = html_select::make_time_selector('hours', 'an_cutoff_hour', $curtime);
        $minselector = html_select::make_time_selector('minutes', 'an_cutoff_min', $curtime);
        echo $OUTPUT->select($hourselector) . $OUTPUT->select($minselector);
        ?><br />
        <?php print_string("cutofftime", "enrol_authorize") ?></td>
</tr>

<tr valign="top">
    <td align="right">an_avs:</td>
    <td><?php echo $OUTPUT->checkbox(html_select_option::make_checkbox('1', !empty($frm->an_avs)), 'an_avs'); ?><br />
        <?php print_string("adminavs", "enrol_authorize") ?></td>
</tr>

<tr valign="top">
    <td align="right">an_authcode:</td>
    <td><?php echo $OUTPUT->checkbox(html_select_option::make_checkbox('1', !empty($frm->an_authcode)), 'an_authcode');  ?>
        <?php echo $OUTPUT->help_icon(moodle_help_icon::make('authcode', 'authcode', 'enrol/authorize')); ?><br />
        <?php print_string("adminauthcode", "enrol_authorize") ?></td>
</tr>

<tr valign="top">
    <td align="right">an_test:</td>
    <td><?php echo $OUTPUT->checkbox(html_select_option::make_checkbox('1', !empty($frm->an_test)), 'an_test');  ?><br />
        <?php print_string("antestmode", "enrol_authorize") ?></td>
</tr>

<tr valign="top">
    <td align="right">accepts:</td>
    <td><?php print_string("adminaccepts", "enrol_authorize") ?><br /><br /><?php
    $paymentmethodsenabled = get_list_of_payment_methods();
    $allpaymentmethods = get_list_of_payment_methods(true);
    foreach ($allpaymentmethods as $key) {
        if ($key == AN_METHOD_CC) {
            echo $OUTPUT->checkbox(html_select_option::make_checkbox(AN_METHOD_CC, in_array(AN_METHOD_CC, $paymentmethodsenabled), get_string('method'.AN_METHOD_CC,'enrol_authorize')), 'acceptmethods[]');
            echo("<ul>"); // blockquote breaks <span> and <br> tags
            $acceptedccs = array_keys(get_list_of_creditcards());
            $allccs = get_list_of_creditcards(true);
            foreach ($allccs as $key => $val) {
                echo "<li>";
                echo $OUTPUT->checkbox(html_select_option::make_checkbox($key, in_array($key, $acceptedccs), $val), 'acceptccs[]');
                echo "</li>";
            }
            echo("</ul>");
        }
        elseif ($key == AN_METHOD_ECHECK) {
            $checkbox = html_select_option::make_checkbox(AN_METHOD_ECHECK, in_array(AN_METHOD_ECHECK, $paymentmethodsenabled), get_string('method'.AN_METHOD_ECHECK,'enrol_authorize'));
            echo $OUTPUT->checkbox($checkbox, 'acceptmethods[]');
            echo("<ul>"); // blockquote breaks <span> and <br> tags
            $echecktypesenabled = get_list_of_bank_account_types();
            $allechecktypes = get_list_of_bank_account_types(true);
            foreach ($allechecktypes as $key) {
                echo "<li>";
                echo $OUTPUT->checkbox(html_select_option::make_checkbox($key, in_array($key, $echecktypesenabled), get_string('echeck'.strtolower($key),'enrol_authorize')), 'acceptechecktypes[]');
                echo "</li>";
            }
            echo("</ul>");
        }
    }
    ?><br /></td>
</tr>

<tr valign="top"><td colspan="2"><h4><?php print_string("adminauthorizeccapture", "enrol_authorize") ?>
                                     <?php echo $OUTPUT->help_icon(moodle_help_icon::make('orderreview', 'orderreview', 'enrol/authorize')); ?>
                                 </h4></td></tr>

<tr valign="top">
    <td align="right">an_review:</td>
    <td><?php echo $OUTPUT->checkbox(html_select_option::make_checkbox('1', !empty($frm->an_review)), 'an_review'); ?>
        <?php echo $OUTPUT->help_icon(moodle_help_icon::make('review', get_string('adminhelpreviewtitle', 'enrol_authorize'), 'enrol/authorize')); ?><br />
        <?php print_string("adminreview", "enrol_authorize") ?></td>
</tr>

<tr valign="top">
    <td align="right">an_capture_day:</td>
    <td><input type="text" name="an_capture_day" size="2" maxlength="2" value="<?php p($frm->an_capture_day) ?>" />
        <?php echo $OUTPUT->help_icon(moodle_help_icon::make('captureday', get_string('adminhelpcapturetitle', 'enrol_authorize'), 'enrol/authorize')); ?><br />
        <?php print_string("reviewday", "enrol_authorize", $frm->an_capture_day) ?></td>
</tr>

<tr valign="top"><td colspan="2"><h4><?php print_string("adminauthorizeemail", "enrol_authorize") ?></h4></td></tr>

<tr valign="top">
    <td align="right">an_emailexpired:</td>
    <td><input type="text" name="an_emailexpired" size="1" maxlength="1" value="<?php p($frm->an_emailexpired) ?>" /><br />
        <?php print_string("adminemailexpired", "enrol_authorize", $frm->an_emailexpired) ?><br />
        <?php print_string("adminemailexpsetting", "enrol_authorize") ?></td>
</tr>

<tr valign="top">
    <td align="right">an_emailexpiredteacher:</td>
    <td><?php echo $OUTPUT->checkbox(html_select_option::make_checkbox('1', !empty($frm->an_emailexpiredteacher)), 'an_emailexpiredteacher');  ?><br />
        <?php print_string("adminemailexpiredteacher", "enrol_authorize") ?></td>
</tr>

<tr valign="top">
    <td align="right">an_sorttype:</td>
    <td><?php
    $sorts = array('ttl' => get_string('adminemailexpiredsortsum', 'enrol_authorize'),
                   'cnt' => get_string('adminemailexpiredsortcount', 'enrol_authorize'));
    $select = html_select::make($sorts, "an_sorttype", $frm->an_sorttype, false);
    $select->nothingvalue = '';
    echo $OUTPUT->select($select);
    ?>
    <br />
    <?php print_string("adminemailexpiredsort", "enrol_authorize") ?></td>
</tr>

<tr valign="top">
    <td align="right">enrol_mailstudents:</td>
    <td><?php echo $OUTPUT->checkbox(html_select_option::make_checkbox('1', !empty($frm->enrol_mailstudents)), 'enrol_mailstudents'); ?><br />
        <?php print_string("mailstudents") ?></td>
</tr>

<tr valign="top">
    <td align="right">enrol_mailteachers:</td>
    <td><?php echo $OUTPUT->checkbox(html_select_option::make_checkbox('1', !empty($frm->enrol_mailteachers)), 'enrol_mailteachers');  ?><br />
        <?php print_string("mailteachers") ?></td>
</tr>

<tr valign="top">
    <td align="right">enrol_mailadmins:</td>
    <td><?php echo $OUTPUT->checkbox(html_select_option::make_checkbox('1', !empty($frm->enrol_mailadmins)), 'enrol_mailadmins');  ?><br />
        <?php print_string("mailadmins") ?></td>
</tr>

</table>
