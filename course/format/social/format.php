<?php // $Id$
      // format.php - course format featuring social forum
      //              included from view.php

    $strgroups  = get_string('groups');
    $strgroupmy = get_string('groupmy');
    $editing    = $PAGE->user_is_editing();

    if ($forum = forum_get_course_forum($course->id, 'social')) {

    /// Print forum intro above posts  MDL-18483
        if (trim($forum->intro) != '') {
            $options = new stdclass;
            $options->para = false;
            echo $OUTPUT->box(format_text($forum->intro, FORMAT_MOODLE, $options), 'generalbox', 'intro');
        }

        $cm = get_coursemodule_from_instance('forum', $forum->id);
        $context = get_context_instance(CONTEXT_MODULE, $cm->id);
        echo '<div class="subscribelink">', forum_get_subscribe_link($forum, $context), '</div>';
        forum_print_latest_discussions($course, $forum, 10, 'plain', '', false);

    } else {
        notify('Could not find or create a social forum here');
    }
