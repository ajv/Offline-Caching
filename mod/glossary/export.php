<?php   // $Id$

    require_once("../../config.php");
    require_once("lib.php");

    $id = required_param('id', PARAM_INT);      // Course Module ID

    $mode= optional_param('mode', '', PARAM_ALPHA);           // term entry cat date letter search author approval
    $hook= optional_param('hook', '', PARAM_CLEAN);           // the term, entry, cat, etc... to look for based on mode
    $cat = optional_param('cat',0, PARAM_ALPHANUM);

    if (! $cm = get_coursemodule_from_id('glossary', $id)) {
        print_error('invalidcoursemodule');
    }

    if (! $course = $DB->get_record("course", array("id"=>$cm->course))) {
        print_error('coursemisconf');
    }

    if (! $glossary = $DB->get_record("glossary", array("id"=>$cm->instance))) {
        print_error('invalidid', 'glossary');
    }

    require_login($course->id, false, $cm);

    $context = get_context_instance(CONTEXT_MODULE, $cm->id);
    require_capability('mod/glossary:export', $context);

    $strglossaries = get_string("modulenameplural", "glossary");
    $strglossary = get_string("modulename", "glossary");
    $strallcategories = get_string("allcategories", "glossary");
    $straddentry = get_string("addentry", "glossary");
    $strnoentries = get_string("noentries", "glossary");
    $strsearchconcept = get_string("searchconcept", "glossary");
    $strsearchindefinition = get_string("searchindefinition", "glossary");
    $strsearch = get_string("search");
    $strexportfile = get_string("exportfile", "glossary");
    $strexportentries = get_string('exportentriestoxml', 'glossary');

    $navigation = build_navigation($strexportentries, $cm);
    print_header_simple(format_string($glossary->name), "",$navigation,
        "", "", true, update_module_button($cm->id, $course->id, $strglossary),
        navmenu($course, $cm));

    echo $OUTPUT->heading($strexportentries);

    echo $OUTPUT->box_start('glossarydisplay generalbox');
    ?>
    <form action="exportfile.php" method="post">
    <table border="0" cellpadding="6" cellspacing="6" width="100%">
    <tr><td align="center">
        <input type="submit" value="<?php p($strexportfile)?>" />
    </td></tr></table>
    <div>
    <input type="hidden" name="id" value="<?php p($id)?>" />
    <input type="hidden" name="cat" value="<?php p($cat)?>" />
    </div>
    </form>
<?php
    // don't need cap check here, we share with the general export.
    if ($DB->count_records('glossary_entries', array('glossaryid' => $glossary->id))) {
        require_once($CFG->libdir . '/portfoliolib.php');
        $button = new portfolio_add_button();
        $button->set_callback_options('glossary_csv_portfolio_caller', array('id' => $cm->id), '/mod/glossary/lib.php');
        $button->render();
    }
    echo $OUTPUT->box_end();
    echo $OUTPUT->footer();
?>
