<?php // $Id$
      // Display the whole course as "weeks" made of of modules
      // Included from "view.php"
/**
 * Evaluation weekly format for course display - NO layout tables, for accessibility, etc.
 * 
 * A duplicate course format to enable the Moodle development team to evaluate 
 * CSS for the multi-column layout in place of layout tables. 
 * Less risk for the Moodle 1.6 beta release.
 *   1. Straight copy of weeks/format.php
 *   2. Replace <table> and <td> with DIVs; inline styles.
 *   3. Reorder columns so that in linear view content is first then blocks;
 * styles to maintain original graphical (side by side) view.
 *
 * Target: 3-column graphical view using relative widths for pixel screen sizes 
 * 800x600, 1024x768... on IE6, Firefox. Below 800 columns will shift downwards.
 * 
 * http://www.maxdesign.com.au/presentation/em/ Ideal length for content.
 * http://www.svendtofte.com/code/max_width_in_ie/ Max width in IE.
 *
 * @copyright &copy; 2006 The Open University
 * @author N.D.Freear@open.ac.uk, and others.
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package  
 */
//TODO (nfreear): Accessibility: evaluation, lang/en_utf8/moodle.php: $string['formatweekscss']

    require_once($CFG->libdir.'/ajax/ajaxlib.php');
    require_once($CFG->libdir.'/filelib.php');

    $week = optional_param('week', -1, PARAM_INT);

    if ($week != -1) {
        $displaysection = course_set_display($course->id, $week);
    } else {
        if (isset($USER->display[$course->id])) {
            $displaysection = $USER->display[$course->id];
        } else {
            $displaysection = course_set_display($course->id, 0);
        }
    }

    $streditsummary  = get_string('editsummary');
    $stradd          = get_string('add');
    $stractivities   = get_string('activities');
    $strshowallweeks = get_string('showallweeks');
    $strweek         = get_string('week');
    $strgroups       = get_string('groups');
    $strgroupmy      = get_string('groupmy');
    $editing         = $PAGE->user_is_editing();

    if ($editing) {
        $strweekhide = get_string('hideweekfromothers');
        $strweekshow = get_string('showweekfromothers');
        $strmoveup   = get_string('moveup');
        $strmovedown = get_string('movedown');
    }

    $context = get_context_instance(CONTEXT_COURSE, $course->id);

    //Print the Your progress icon if the track completion is enabled
    $completioninfo = new completion_info($course);
    $completioninfo->print_help_icon();

    echo $OUTPUT->heading(get_string('weeklyoutline'), 2, 'headingblock header outline');

    // Note, an ordered list would confuse - "1" could be the clipboard or summary.
    echo "<ul class='weeks'>\n";

/// If currently moving a file then show the current clipboard
    if (ismoving($course->id)) {
        $stractivityclipboard = strip_tags(get_string('activityclipboard', '', $USER->activitycopyname));
        $strcancel= get_string('cancel');
        echo '<li class="clipboard">';
        echo $stractivityclipboard.'&nbsp;&nbsp;(<a href="mod.php?cancelcopy=true&amp;sesskey='.sesskey().'">'.$strcancel.'</a>)';
        echo "</li>\n";
    }

/// Print Section 0 with general activities

    $section = 0;
    $thissection = $sections[$section];

    if ($thissection->summary or $thissection->sequence or $PAGE->user_is_editing()) {

        // Note, 'right side' is BEFORE content.
        echo '<li id="section-0" class="section main" >';
        echo '<div class="left side">&nbsp;</div>';
        echo '<div class="right side" >&nbsp;</div>';        
        echo '<div class="content">';
        
        echo '<div class="summary">';

        $coursecontext = get_context_instance(CONTEXT_COURSE, $course->id);
        $summarytext = file_rewrite_pluginfile_urls($thissection->summary, 'pluginfile.php', $coursecontext->id, 'course_section', $thissection->id);
        $summaryformatoptions = new object();
        $summaryformatoptions->noclean = true;
        echo format_text($summarytext, FORMAT_HTML, $summaryformatoptions);

        if ($PAGE->user_is_editing() && has_capability('moodle/course:update', get_context_instance(CONTEXT_COURSE, $course->id))) {
            echo '<p><a title="'.$streditsummary.'" '.
                 ' href="editsection.php?id='.$thissection->id.'"><img src="'.$OUTPUT->old_icon_url('t/edit') . '" '.
                 ' class="icon edit" alt="'.$streditsummary.'" /></a></p>';
        }
        echo '</div>';
        
        print_section($course, $thissection, $mods, $modnamesused);

        if ($PAGE->user_is_editing()) {
            print_section_add_menus($course, $section, $modnames);
        }

        echo '</div>';
        echo "</li>\n";
    }


/// Now all the normal modules by week
/// Everything below uses "section" terminology - each "section" is a week.

    $timenow = time();
    $weekdate = $course->startdate;    // this should be 0:00 Monday of that week
    $weekdate += 7200;                 // Add two hours to avoid possible DST problems
    $section = 1;
    $sectionmenu = array();
    $weekofseconds = 604800;
    $course->enddate = $course->startdate + ($weekofseconds * $course->numsections);

    $popupurl = $CFG->wwwroot.'/course/view.php?id='.$course->id.'&week=';

    $strftimedateshort = ' '.get_string('strftimedateshort');

    while ($weekdate < $course->enddate) {

        $nextweekdate = $weekdate + ($weekofseconds);
        $weekday = userdate($weekdate, $strftimedateshort);
        $endweekday = userdate($weekdate+518400, $strftimedateshort);

        if (!empty($sections[$section])) {
            $thissection = $sections[$section];

        } else {
            unset($thissection);
            $thissection->course = $course->id;   // Create a new week structure
            $thissection->section = $section;
            $thissection->summary = '';
            $thissection->visible = 1;
            $thissection->id = $DB->insert_record('course_sections', $thissection);
        }

        $showsection = (has_capability('moodle/course:viewhiddensections', $context) or $thissection->visible or !$course->hiddensections);

        if (!empty($displaysection) and $displaysection != $section) {  // Check this week is visible
            if ($showsection) {
                $sectionmenu[$popupurl.$section] = s("$strweek $section |     $weekday - $endweekday");
            }
            $section++;
            $weekdate = $nextweekdate;
            continue;
        }

        if ($showsection) {

            $currentweek = (($weekdate <= $timenow) && ($timenow < $nextweekdate));

            $currenttext = '';
            if (!$thissection->visible) {
                $sectionstyle = ' hidden';
            } else if ($currentweek) {
                $sectionstyle = ' current';
                $currenttext = get_accesshide(get_string('currentweek','access'));
            } else {
                $sectionstyle = '';
            }

            echo '<li id="section-'.$section.'" class="section main'.$sectionstyle.'" >';

	    echo '<div class="left side">&nbsp;'.$currenttext.'</div>';

            // Note, 'right side' is BEFORE content.
            echo '<div class="right side">';
            
            if ($displaysection == $section) {
                echo '<a href="view.php?id='.$course->id.'&amp;week=0#section-'.$section.'" title="'.$strshowallweeks.'">'.
                     '<img src="'.$OUTPUT->old_icon_url('i/all') . '" class="icon wkall" alt="'.$strshowallweeks.'" /></a><br />';
            } else {
                $strshowonlyweek = get_string("showonlyweek", "", $section);
                echo '<a href="view.php?id='.$course->id.'&amp;week='.$section.'" title="'.$strshowonlyweek.'">'.
                     '<img src="'.$OUTPUT->old_icon_url('i/one') . '" class="icon wkone" alt="'.$strshowonlyweek.'" /></a><br />';
            }

            if ($PAGE->user_is_editing() && has_capability('moodle/course:update', get_context_instance(CONTEXT_COURSE, $course->id))) {
                if ($thissection->visible) {        // Show the hide/show eye
                    echo '<a href="view.php?id='.$course->id.'&amp;hide='.$section.'&amp;sesskey='.sesskey().'#section-'.$section.'" title="'.$strweekhide.'">'.
                         '<img src="'.$OUTPUT->old_icon_url('i/hide') . '" class="icon hide" alt="'.$strweekhide.'" /></a><br />';
                } else {
                    echo '<a href="view.php?id='.$course->id.'&amp;show='.$section.'&amp;sesskey='.sesskey().'#section-'.$section.'" title="'.$strweekshow.'">'.
                         '<img src="'.$OUTPUT->old_icon_url('i/show') . '" class="icon hide" alt="'.$strweekshow.'" /></a><br />';
                }
                if ($section > 1) {                       // Add a arrow to move section up
                    echo '<a href="view.php?id='.$course->id.'&amp;random='.rand(1,10000).'&amp;section='.$section.'&amp;move=-1&amp;sesskey='.sesskey().'#section-'.($section-1).'" title="'.$strmoveup.'">'.
                         '<img src="'.$OUTPUT->old_icon_url('t/up') . '" class="icon up" alt="'.$strmoveup.'" /></a><br />';
                }

                if ($section < $course->numsections) {    // Add a arrow to move section down
                    echo '<a href="view.php?id='.$course->id.'&amp;random='.rand(1,10000).'&amp;section='.$section.'&amp;move=1&amp;sesskey='.sesskey().'#section-'.($section+1).'" title="'.$strmovedown.'">'.
                         '<img src="'.$OUTPUT->old_icon_url('t/down') . '" class="icon down" alt="'.$strmovedown.'" /></a><br />';
                }
            }
            echo '</div>';

            $weekperiod = $weekday.' - '.$endweekday;

            echo '<div class="content">';
            if (!has_capability('moodle/course:viewhiddensections', $context) and !$thissection->visible) {   // Hidden for students
                echo $OUTPUT->heading($currenttext.$weekperiod.' ('.get_string('notavailable').')', 3, 'weekdates');

            } else {
                echo $OUTPUT->heading($currenttext.$weekperiod, 3, 'weekdates');

                echo '<div class="summary">';
                $summaryformatoptions->noclean = true;
                echo format_text($thissection->summary, FORMAT_HTML, $summaryformatoptions);

                if ($PAGE->user_is_editing() && has_capability('moodle/course:update', get_context_instance(CONTEXT_COURSE, $course->id))) {
                    echo ' <a title="'.$streditsummary.'" href="editsection.php?id='.$thissection->id.'">'.
                         '<img src="'.$OUTPUT->old_icon_url('t/edit') . '" class="icon edit" alt="'.$streditsummary.'" /></a><br /><br />';
                }
                echo '</div>';

                print_section($course, $thissection, $mods, $modnamesused);

                if ($PAGE->user_is_editing()) {
                    print_section_add_menus($course, $section, $modnames);
                }
            }

            echo '</div>';
            echo "</li>\n";
        }

        $section++;
        $weekdate = $nextweekdate;
    }
    echo "</ul>\n";

    if (!empty($sectionmenu)) {
        echo '<div class="jumpmenu">';
        $select = moodle_select::make_popup_form($sectionmenu, 'sectionmenu');
        $select->set_label(get_string('jumpto'));
        echo $OUTPUT->select($select);
        echo '</div>';
    }
