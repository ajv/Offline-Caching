<?php // $Id$
/**
* deletes a template
*
* @version $Id$
* @author Andreas Grabs
* @license http://www.gnu.org/copyleft/gpl.html GNU Public License
* @package feedback
*/

    require_once("../../config.php");
    require_once("lib.php");
    require_once('delete_template_form.php');

    // $SESSION->feedback->current_tab = 'templates';
    $current_tab = 'templates';

    $id = required_param('id', PARAM_INT);
    $canceldelete = optional_param('canceldelete', false, PARAM_INT);
    $shoulddelete = optional_param('shoulddelete', false, PARAM_INT);
    $deletetempl = optional_param('deletetempl', false, PARAM_INT);
    // $formdata = data_submitted();

    if(($formdata = data_submitted()) AND !confirm_sesskey()) {
        print_error('invalidsesskey');
    }

    if($canceldelete == 1){
        redirect(htmlspecialchars('edit.php?id='.$id.'&do_show=templates'));
    }

    if ($id) {
        if (! $cm = get_coursemodule_from_id('feedback', $id)) {
            print_error('invalidcoursemodule');
        }

        if (! $course = $DB->get_record("course", array("id"=>$cm->course))) {
            print_error('coursemisconf');
        }

        if (! $feedback = $DB->get_record("feedback", array("id"=>$cm->instance))) {
            print_error('invalidcoursemodule');
        }
    }
    $capabilities = feedback_load_capabilities($cm->id);

    require_login($course->id, true, $cm);

    if(!$capabilities->deletetemplate){
        print_error('error');
    }

    $mform = new mod_feedback_delete_template_form();
    $newformdata = array('id'=>$id,
                        'deletetempl'=>$deletetempl,
                        'confirmdelete'=>'1');

    $mform->set_data($newformdata);
    $formdata = $mform->get_data();

    if ($mform->is_cancelled()) {
        redirect(htmlspecialchars('delete_template.php?id='.$id));
    }

    if(isset($formdata->confirmdelete) AND $formdata->confirmdelete == 1){
        feedback_delete_template($formdata->deletetempl);
        redirect(htmlspecialchars('delete_template.php?id=' . $id));
    }

    /// Print the page header
    $strfeedbacks = get_string("modulenameplural", "feedback");
    $strfeedback  = get_string("modulename", "feedback");
    $buttontext = update_module_button($cm->id, $course->id, $strfeedback);

    $navlinks = array();
    $navlinks[] = array('name' => $strfeedbacks, 'link' => "index.php?id=$course->id", 'type' => 'activity');
    $navlinks[] = array('name' => format_string($feedback->name), 'link' => "", 'type' => 'activityinstance');

    $navigation = build_navigation($navlinks);

    print_header_simple(format_string($feedback->name), "",
                 $navigation, "", "", true, $buttontext, navmenu($course, $cm));

    /// print the tabs
    include('tabs.php');

    /// Print the main part of the page
    ///////////////////////////////////////////////////////////////////////////
    ///////////////////////////////////////////////////////////////////////////
    ///////////////////////////////////////////////////////////////////////////
    echo $OUTPUT->heading(get_string('delete_template','feedback'));
    if($shoulddelete == 1) {

        // print_simple_box_start("center", "60%", "#FFAAAA", 20, "noticebox");
        echo $OUTPUT->box_start('generalbox errorboxcontent boxaligncenter boxwidthnormal');
        echo $OUTPUT->heading(get_string('confirmdeletetemplate', 'feedback'));
        $mform->display();
        // print_simple_box_end();
        echo $OUTPUT->box_end();
    }else {
        $templates = feedback_get_template_list($course, true);
        echo '<div class="mdl-align">';
        if(!is_array($templates)) {
            // print_simple_box(get_string('no_templates_available_yet', 'feedback'), "center");
            echo $OUTPUT->box(get_string('no_templates_available_yet', 'feedback'), 'generalbox boxaligncenter');
        }else {
            echo '<table width="30%">';
            echo '<tr><th>'.get_string('templates', 'feedback').'</th><th>&nbsp;</th></tr>';
            foreach($templates as $template) {
                echo '<tr><td align="center">'.$template->name.'</td>';
                echo '<td align="center">';
                echo '<form action="'.$ME.'" method="post">';
                echo '<input title="'.get_string('delete_template','feedback').'" type="image" src="'.$OUTPUT->old_icon_url('t/delete') . '" hspace="1" height="11" width="11" border="0" />';
                echo '<input type="hidden" name="deletetempl" value="'.$template->id.'" />';
                echo '<input type="hidden" name="shoulddelete" value="1" />';
                echo '<input type="hidden" name="id" value="'.$id.'" />';
                echo '<input type="hidden" name="sesskey" value="'.sesskey().'" />';
                echo '</form>';
                echo '</td></tr>';
            }
            echo '</table>';
        }
?>
        <form name="frm" action="<?php echo $ME;?>" method="post">
            <input type="hidden" name="sesskey" value="<?php echo sesskey() ?>" />
            <input type="hidden" name="id" value="<?php echo $id;?>" />
            <input type="hidden" name="canceldelete" value="0" />
            <button type="button" onclick="this.form.canceldelete.value=1;this.form.submit();"><?php print_string('cancel');?></button>
        </form>
        </div>
<?php
    }

    echo $OUTPUT->footer();

?>
