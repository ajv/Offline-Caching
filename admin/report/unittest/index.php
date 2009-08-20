<?php
/**
 * Run the unit tests.
 *
 * @copyright &copy; 2006 The Open University
 * @author N.D.Freear@open.ac.uk, T.J.Hunt@open.ac.uk
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @version $Id$
 * @package SimpleTestEx
 */

/** */
require_once(dirname(__FILE__).'/../../../config.php');
require_once($CFG->libdir.'/adminlib.php');
require_once($CFG->libdir.'/simpletestcoveragelib.php');
require_once('ex_simple_test.php');
require_once('ex_reporter.php');

// Always run the unit tests in developer debug mode.
$CFG->debug = DEBUG_DEVELOPER;
error_reporting($CFG->debug);
raise_memory_limit('256M');

// page parameters
$path         = optional_param('path', null, PARAM_PATH);
$showpasses   = optional_param('showpasses', false, PARAM_BOOL);
$codecoverage = optional_param('codecoverage', false, PARAM_BOOL);
$showsearch   = optional_param('showsearch', false, PARAM_BOOL);

admin_externalpage_setup('reportsimpletest', '', array('showpasses'=>$showpasses, 'showsearch'=>$showsearch));

$langfile = 'simpletest';
$unittest = true;

global $UNITTEST;
$UNITTEST = new object();

// Print the header.
$strtitle = get_string('unittests', $langfile);

if (!is_null($path)) {
    // Turn off xmlstrictheaders during the unit test run.
    $origxmlstrictheaders = !empty($CFG->xmlstrictheaders);
    $CFG->xmlstrictheaders = false;
    admin_externalpage_print_header();
    $CFG->xmlstrictheaders = $origxmlstrictheaders;
    unset($origxmlstrictheaders);

    // Create the group of tests.
    $test = new autogroup_test_coverage($showsearch, true, $codecoverage, 'Moodle Unit Tests Code Coverage Report', 'unittest');

    // OU specific. We use the _nonproject folder for stuff we want to
    // keep in CVS, but which is not really relevant. It does no harm
    // to leave this here.
    $test->addIgnoreFolder($CFG->dirroot . '/_nonproject');

    // Make the reporter, which is what displays the results.
    $reporter = new ExHtmlReporter($showpasses);

    if ($showsearch) {
        echo $OUTPUT->heading('Searching for test cases');
    }
    flush();

    // Work out what to test.
    if (substr($path, 0, 1) == '/') {
        $path = substr($path, 1);
    }
    $path = $CFG->dirroot . '/' . $path;
    if (substr($path, -1) == '/') {
        $path = substr($path, 0, -1);
    }
    $displaypath = substr($path, strlen($CFG->dirroot) + 1);
    $ok = true;
    if (is_file($path)) {
        $test->addTestFile($path);
    } else if (is_dir($path)){
        $test->findTestFiles($path);
    } else {
        echo $OUTPUT->box(get_string('pathdoesnotexist', $langfile, $path), 'errorbox');
        $ok = false;
    }

    // If we have something to test, do it.
    if ($ok) {
        if ($path == $CFG->dirroot) {
            $title = get_string('moodleunittests', $langfile, get_string('all', $langfile));
        } else {
            $title = get_string('moodleunittests', $langfile, $displaypath);
        }
        echo $OUTPUT->heading($title);
        set_time_limit(300); // 5 mins
        $test->run($reporter);
    }

    $formheader = get_string('retest', $langfile);
} else {
    $displaypath = '';
    admin_externalpage_print_header();
    $formheader = get_string('rununittests', $langfile);
}
// Print the form for adjusting options.
echo $OUTPUT->box_start('generalbox boxwidthwide boxaligncenter');
echo $OUTPUT->heading($formheader);
echo '<form method="get" action="index.php">';
echo '<fieldset class="invisiblefieldset">';
echo '<p>'; echo $OUTPUT->checkbox(html_select_option::make_checkbox(1, $showpasses, get_string('showpasses', $langfile)), 'showpasses') ; echo '</p>';
echo '<p>'; echo $OUTPUT->checkbox(html_select_option::make_checkbox(1, $showsearch, get_string('showsearch', $langfile)), 'showsearch') ; echo '</p>';
if (moodle_coverage_recorder::can_run_codecoverage()) {
    echo '<p>'; echo $OUTPUT->checkbox(html_select_option::make_checkbox(1, $codecoverage, get_string('codecoverageanalysis', 'simpletest')), 'codecoverage') ; echo '</p>';
} else {
    echo '<p>'; print_string('codecoveragedisabled', 'simpletest'); echo '<input type="hidden" name="codecoverage" value="0" /></p>';
}
echo '<p>';
    echo '<label for="path">', get_string('onlytest', $langfile), '</label> ';
    echo '<input type="text" id="path" name="path" value="', $displaypath, '" size="40" />';
echo '</p>';
echo '<input type="submit" value="' . get_string('runtests', $langfile) . '" />';
echo '</fieldset>';
echo '</form>';
echo $OUTPUT->box_end();

echo $OUTPUT->box_start('generalbox boxwidthwide boxaligncenter');
if (true) {
    echo "<p>Fake test tables are disabled for now, sorry</p>"; // DO NOT LOCALISE!!! to be removed soon

} else if (empty($CFG->unittestprefix)) {
    echo $OUTPUT->heading(get_string('testdboperations', 'simpletest'));
    // TODO: localise
    echo '<p>Please add $CFG->unittestprefix="tst_"; or some other unique test table prefix if you want to execute all tests';

} else {
    echo $OUTPUT->heading(get_string('testdboperations', 'simpletest'));
    echo '<p>'.get_string('unittestprefixsetting', 'simpletest', $CFG).'</p>';

    echo '<form style="display:inline" method="get" action="index.php">';
    echo '<fieldset class="invisiblefieldset">';
    echo '<input type="hidden" name="setuptesttables" value="1" />';
    echo '<input type="submit" value="' . get_string('reinstalltesttables', 'simpletest') . '" />';
    echo '</fieldset>';
    echo '</form>';
}
echo $OUTPUT->box_end();

// Print link to latest code coverage for this report type
if (is_null($path) || !$codecoverage) {
    moodle_coverage_reporter::print_link_to_latest('unittest');
}

// Footer.
echo $OUTPUT->footer();

?>
