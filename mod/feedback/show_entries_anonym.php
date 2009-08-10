<?php // $Id$
/**
* print the single-values of anonymous completeds
*
* @version $Id$
* @author Andreas Grabs
* @license http://www.gnu.org/copyleft/gpl.html GNU Public License
* @package feedback
*/

    require_once("../../config.php");
    require_once("lib.php");

    // $SESSION->feedback->current_tab = 'showoneentry';
    $current_tab = 'showentries';

    $id = required_param('id', PARAM_INT);
    $userid = optional_param('userid', false, PARAM_INT);

    if(($formdata = data_submitted()) AND !confirm_sesskey()) {
        print_error('invalidsesskey');
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

    if(!$capabilities->viewreports){
        print_error('error');
    }


    //get the completeds
    // if a new anonymous record has not been assigned a random response number
    if ($feedbackcompleteds = $DB->get_records('feedback_completed', array('feedback'=>$feedback->id, 'random_response'=>0, 'anonymous_response'=>FEEDBACK_ANONYMOUS_YES), 'random_response')){ //arb
        //then get all of the anonymous records and go through them
        $feedbackcompleteds = $DB->get_records('feedback_completed', array('feedback'=>$feedback->id, 'anonymous_response'=>FEEDBACK_ANONYMOUS_YES), 'id'); //arb
        shuffle($feedbackcompleteds);
        $num = 1;
        foreach($feedbackcompleteds as $compl){
            $compl->random_response = $num;
            $DB->update_record('feedback_completed', $compl);
            $num++;
        }
    }
    $feedbackcompleteds = $DB->get_records('feedback_completed', array('feedback'=>$feedback->id, 'anonymous_response'=>FEEDBACK_ANONYMOUS_YES), 'random_response'); //arb

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

    /// Print the main part of the page
    ///////////////////////////////////////////////////////////////////////////
    ///////////////////////////////////////////////////////////////////////////
    ///////////////////////////////////////////////////////////////////////////
    include('tabs.php');

    echo $OUTPUT->heading(format_text($feedback->name));

    print_continue(htmlspecialchars('show_entries.php?id='.$id.'&do_show=showentries'));
    //print the list with anonymous completeds
    // print_simple_box_start("center");
    echo $OUTPUT->box_start('generalbox boxaligncenter boxwidthwide');
    $PAGE->requires->js('mod/feedback/feedback.js');
?>
    <div class="mdl-align">
    <form name="frm" action="<?php echo me();?>" method="post">
        <table>
            <tr>
                <td>
                    <input type="hidden" name="sesskey" value="<?php echo sesskey() ?>" />
                    <select name="completedid" size="<?php echo (sizeof($feedbackcompleteds)>10)?10:5;?>">
<?php
                    if(is_array($feedbackcompleteds)) {
                        $num = 1;
                        foreach($feedbackcompleteds as $compl) {
                            $selected = (isset($formdata->completedid) AND $formdata->completedid == $compl->id)?'selected="selected"':'';
                            echo '<option value="'.$compl->id.'" '. $selected .'>'.get_string('response_nr', 'feedback').': '. $compl->random_response. '</option>';//arb
                            $num++;
                        }
                    }
?>
                    </select>
                    <input type="hidden" name="showanonym" value="<?php echo FEEDBACK_ANONYMOUS_YES;?>" />
                    <input type="hidden" name="id" value="<?php echo $id;?>" />
                </td>
                <td valign="top">
                    <button type="submit"><?php print_string('show_entry', 'feedback');?></button><br />
                    <button type="button" onclick="feedbackGo2delete(this.form);"><?php print_string('delete_entry', 'feedback');?></button>
                </td>
            </tr>
        </table>
    </form>
    </div>
<?php
    // print_simple_box_end();
    echo $OUTPUT->box_end();
    if(!isset($formdata->completedid)) {
        $formdata = null;
    }
    //print the items
    if(isset($formdata->showanonym) && $formdata->showanonym == FEEDBACK_ANONYMOUS_YES) {
        //get the feedbackitems
        $feedbackitems = $DB->get_records('feedback_item', array('feedback'=>$feedback->id), 'position');
        $feedbackcompleted = $DB->get_record('feedback_completed', array('id'=>$formdata->completedid));
        if(is_array($feedbackitems)){
            if($feedbackcompleted) {
                echo '<p align="center">'.get_string('chosen_feedback_response', 'feedback').'<br />('.get_string('anonymous', 'feedback').')</p>';//arb
            } else {
                echo '<p align="center">'.get_string('not_completed_yet','feedback').'</p>';
            }
            // print_simple_box_start("center", '50%');
            echo $OUTPUT->box_start('generalbox boxaligncenter boxwidthnormal');
            echo '<form>';
            echo '<input type="hidden" name="sesskey" value="'.sesskey().'" />';
            echo '<table width="100%">';
            $itemnr = 0;
            foreach($feedbackitems as $feedbackitem){
                //get the values
                $value = $DB->get_record('feedback_value', array('completed'=>$feedbackcompleted->id, 'item'=>$feedbackitem->id));
                echo '<tr>';
                if($feedbackitem->hasvalue == 1 AND $feedback->autonumbering) {
                    $itemnr++;
                    echo '<td valign="top">' . $itemnr . '.&nbsp;</td>';
                } else {
                    echo '<td>&nbsp;</td>';
                }
                if($feedbackitem->typ != 'pagebreak') {
                    $itemvalue = isset($value->value) ? $value->value : false;
                    feedback_print_item($feedbackitem, $itemvalue, true);
                }else {
                    echo '<td colspan="2"><hr /></td>';
                }
                echo '</tr>';
            }
            echo '<tr><td colspan="2" align="center">';
            echo '</td></tr>';
            echo '</table>';
            echo '</form>';
            // print_simple_box_end();
            echo $OUTPUT->box_end();
        }
    }
    /// Finish the page
    ///////////////////////////////////////////////////////////////////////////
    ///////////////////////////////////////////////////////////////////////////
    ///////////////////////////////////////////////////////////////////////////

    echo $OUTPUT->footer();

?>
