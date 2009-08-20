<?php  // $Id$

    require_once("../../config.php");
    require_once("lib.php");

    $id = required_param('id',PARAM_INT);   // course

    if (!$course = $DB->get_record('course', array('id'=>$id))) {
        print_error('invalidcourseid');
    }

    require_course_login($course);

    add_to_log($course->id, "choice", "view all", "index?id=$course->id", "");

    $strchoice = get_string("modulename", "choice");
    $strchoices = get_string("modulenameplural", "choice");
    $navlinks = array();
    $navlinks[] = array('name' => $strchoices, 'link' => '', 'type' => 'activity');
    $navigation = build_navigation($navlinks);

    print_header_simple("$strchoices", "", $navigation, "", "", true, "", navmenu($course));


    if (! $choices = get_all_instances_in_course("choice", $course)) {
        notice(get_string('thereareno', 'moodle', $strchoices), "../../course/view.php?id=$course->id");
    }

    $sql = "SELECT cha.*
              FROM {choice} ch, {choice_answers} cha
             WHERE cha.choiceid = ch.id AND
                   ch.course = ? AND cha.userid = ?";

    $answers = array () ;
    if (isloggedin() and !isguestuser() and $allanswers = $DB->get_records_sql($sql, array($course->id, $USER->id))) {
        foreach ($allanswers as $aa) {
            $answers[$aa->choiceid] = $aa;
        }
        unset($allanswers);
    }


    $timenow = time();

    $table = new html_table();

    if ($course->format == "weeks") {
        $table->head  = array (get_string("week"), get_string("question"), get_string("answer"));
        $table->align = array ("center", "left", "left");
    } else if ($course->format == "topics") {
        $table->head  = array (get_string("topic"), get_string("question"), get_string("answer"));
        $table->align = array ("center", "left", "left");
    } else {
        $table->head  = array (get_string("question"), get_string("answer"));
        $table->align = array ("left", "left");
    }

    $currentsection = "";

    foreach ($choices as $choice) {
        if (!empty($answers[$choice->id])) {
            $answer = $answers[$choice->id];
        } else {
            $answer = "";
        }
        if (!empty($answer->optionid)) {
            $aa = format_string(choice_get_option_text($choice, $answer->optionid));
        } else {
            $aa = "";
        }
        $printsection = "";
        if ($choice->section !== $currentsection) {
            if ($choice->section) {
                $printsection = $choice->section;
            }
            if ($currentsection !== "") {
                $table->data[] = 'hr';
            }
            $currentsection = $choice->section;
        }

        //Calculate the href
        if (!$choice->visible) {
            //Show dimmed if the mod is hidden
            $tt_href = "<a class=\"dimmed\" href=\"view.php?id=$choice->coursemodule\">".format_string($choice->name,true)."</a>";
        } else {
            //Show normal if the mod is visible
            $tt_href = "<a href=\"view.php?id=$choice->coursemodule\">".format_string($choice->name,true)."</a>";
        }
        if ($course->format == "weeks" || $course->format == "topics") {
            $table->data[] = array ($printsection, $tt_href, $aa);
        } else {
            $table->data[] = array ($tt_href, $aa);
        }
    }
    echo "<br />";
    echo $OUTPUT->table($table);

    echo $OUTPUT->footer();

?>
