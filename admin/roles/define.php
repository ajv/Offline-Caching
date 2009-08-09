<?php  // $Id$

///////////////////////////////////////////////////////////////////////////
//                                                                       //
// NOTICE OF COPYRIGHT                                                   //
//                                                                       //
// Moodle - Modular Object-Oriented Dynamic Learning Environment         //
//          http://moodle.org                                            //
//                                                                       //
// Copyright (C) 1999 onwards Martin Dougiamas  http://dougiamas.com     //
//                                                                       //
// This program is free software; you can redistribute it and/or modify  //
// it under the terms of the GNU General Public License as published by  //
// the Free Software Foundation; either version 2 of the License, or     //
// (at your option) any later version.                                   //
//                                                                       //
// This program is distributed in the hope that it will be useful,       //
// but WITHOUT ANY WARRANTY; without even the implied warranty of        //
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the         //
// GNU General Public License for more details:                          //
//                                                                       //
//          http://www.gnu.org/copyleft/gpl.html                         //
//                                                                       //
///////////////////////////////////////////////////////////////////////////

/**
 * Lets the user edit role definitions.
 *
 * Responds to actions:
 *   add       - add a new role
 *   duplicate - like add, only initialise the new role by using an existing one.
 *   edit      - edit the definition of a role
 *   view      - view the definition of a role
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package roles
 *//** */

    require_once(dirname(__FILE__) . '/../../config.php');
    require_once($CFG->dirroot . '/' . $CFG->admin . '/roles/lib.php');

    $action = required_param('action', PARAM_ALPHA);
    if (!in_array($action, array('add', 'duplicate', 'edit', 'view'))) {
        throw new moodle_exception('invalidaccess');
    }
    if ($action != 'add') {
        $roleid = required_param('roleid', PARAM_INTEGER);
    } else {
        $roleid = 0;
    }

/// Get the base URL for this and related pages into a convenient variable.
    $manageurl = $CFG->wwwroot . '/' . $CFG->admin . '/roles/manage.php';
    $defineurl = $CFG->wwwroot . '/' . $CFG->admin . '/roles/define.php';
    if ($action == 'duplicate') {
        $baseurl = $defineurl . '?action=add';
    } else {
        $baseurl = $defineurl . '?action=' . $action;
        if ($roleid) {
            $baseurl .= '&amp;roleid=' . $roleid;
        }
    }

/// Check access permissions.
    $systemcontext = get_context_instance(CONTEXT_SYSTEM);
    require_login();
    require_capability('moodle/role:manage', $systemcontext);
    admin_externalpage_setup('defineroles', '', array($action, $roleid), $defineurl);

/// Handle the cancel button.
    if (optional_param('cancel', false, PARAM_BOOL)) {
        redirect($manageurl);
    }

/// Handle the toggle advanced mode button.
    $showadvanced = get_user_preferences('definerole_showadvanced', false);
    if (optional_param('toggleadvanced', false, PARAM_BOOL)) {
        $showadvanced = !$showadvanced;
        set_user_preference('definerole_showadvanced', $showadvanced);
    }

/// Get some basic data we are going to need.
    $roles = get_all_roles();
    $rolenames = role_fix_names($roles, $systemcontext, ROLENAME_ORIGINAL);
    $rolescount = count($roles);

/// Create the table object.
    if ($action == 'view') {
        $definitiontable = new view_role_definition_table($systemcontext, $roleid);
    } else if ($showadvanced) {
        $definitiontable = new define_role_table_advanced($systemcontext, $roleid);
    } else {
        $definitiontable = new define_role_table_basic($systemcontext, $roleid);
    }
    $definitiontable->read_submitted_permissions();
    if ($action == 'duplicate') {
        $definitiontable->make_copy();
    }

/// Process submission in necessary.
    if (optional_param('savechanges', false, PARAM_BOOL) && confirm_sesskey() && $definitiontable->is_submission_valid()) {
        $definitiontable->save_changes();
        add_to_log(SITEID, 'role', $action, 'admin/roles/define.php?action=view&roleid=' .
                $definitiontable->get_role_id(), $definitiontable->get_role_name(), '', $USER->id);
        redirect($manageurl);
    }

/// Print the page header and tabs.
    admin_externalpage_print_header();

    $currenttab = 'manage';
    include_once('managetabs.php');

    if ($action == 'add') {
        $title = get_string('addinganewrole', 'role');
    } else if ($action == 'duplicate') {
        $title = get_string('addingrolebycopying', 'role', $rolenames[$roleid]->localname);
    } else if ($action == 'view') {
        $title = get_string('viewingdefinitionofrolex', 'role', $rolenames[$roleid]->localname);
    } else if ($action == 'edit') {
        $title = get_string('editingrolex', 'role', $rolenames[$roleid]->localname);
    }
    print_heading_with_help($title, 'roles');

/// Work out some button labels.
    if ($action == 'add' || $action == 'duplicate') {
        $submitlabel = get_string('createthisrole', 'role');
    } else {
        $submitlabel = get_string('savechanges');
    }

/// On the view page, show some extra controls at the top.
    if ($action == 'view') {
        echo '<div class="buttons">';
        $options = array();
        $options['roleid'] = $roleid;
        $options['action'] = 'edit';
        print_single_button($defineurl, $options, get_string('edit'));
        $options['action'] = 'reset';
        if ($definitiontable->get_legacy_type()) {
            print_single_button($manageurl, $options, get_string('resetrole', 'role'));
        } else {
            print_single_button($manageurl, $options, get_string('resetrolenolegacy', 'role'));
        }
        $options['action'] = 'duplicate';
        print_single_button($defineurl, $options, get_string('duplicaterole', 'role'));
        print_single_button($manageurl, null, get_string('listallroles', 'role'));
        echo "</div>\n";
    }

    // Start the form.
    print_box_start('generalbox');
    if ($action == 'view') {
        echo '<div class="mform">';
    } else {
    ?>
<form id="rolesform" class="mform" action="<?php echo $baseurl; ?>" method="post"><div>
<input type="hidden" name="sesskey" value="<?php p(sesskey()) ?>" />
<div class="submit buttons">
    <input type="submit" name="savechanges" value="<?php echo $submitlabel; ?>" />
    <input type="submit" name="cancel" value="<?php print_string('cancel'); ?>" />
</div>
    <?php
    }

    // Print the form controls.
    $definitiontable->display();

/// Close the stuff we left open above.
    if ($action == 'view') {
        echo '</div>';
    } else {
        ?>
<div class="submit buttons">
    <input type="submit" name="savechanges" value="<?php echo $submitlabel; ?>" />
    <input type="submit" name="cancel" value="<?php print_string('cancel'); ?>" />
</div>
</div></form>
        <?php
    }
    print_box_end();

/// Print a link back to the all roles list.
    echo '<div class="backlink">';
    echo '<p><a href="' . $manageurl . '">' . get_string('backtoallroles', 'role') . '</a></p>';
    echo '</div>';

    echo $OUTPUT->footer();
?>
