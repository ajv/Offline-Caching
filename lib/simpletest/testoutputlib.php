<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.


/**
 * Unit tests for (some of) ../outputlib.php.
 *
 * @package   moodlecore
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later (5)
 */

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.');    ///  It must be included from a Moodle page
}
require_once($CFG->libdir . '/outputlib.php');


/**
 * Unit tests for the pix_icon_finder class.
 *
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pix_icon_finder_test extends UnitTestCase {
    public function test_old_icon_url() {
        global $CFG;
        $if = new pix_icon_finder(new theme_config());
        $this->assertEqual($CFG->httpswwwroot . '/pix/i/course.gif', $if->old_icon_url('i/course'));
    }

    /* Implement interface method. */
    public function test_mod_icon_url() {
        global $CFG;
        $if = new pix_icon_finder(new theme_config());
        $this->assertEqual($CFG->httpswwwroot . '/mod/quiz/icon.gif', $if->mod_icon_url('icon', 'quiz'));
    }
}


/**
 * Unit tests for the standard_renderer_factory class.
 *
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class theme_icon_finder_test extends UnitTestCase {
    public function test_old_icon_url_test() {
        global $CFG;
        $theme = new theme_config();
        $theme->name = 'test';
        $if = new theme_icon_finder($theme);
        $this->assertEqual($CFG->httpsthemewww . '/test/pix/i/course.gif', $if->old_icon_url('i/course'));
    }

    /* Implement interface method. */
    public function test_mod_icon_url() {
        global $CFG;
        $theme = new theme_config();
        $theme->name = 'test';
        $if = new theme_icon_finder($theme);
        $this->assertEqual($CFG->httpsthemewww . '/test/pix/mod/quiz/icon.gif', $if->mod_icon_url('icon', 'quiz'));
    }
}


/**
 * Subclass of renderer_factory_base for testing. Implement abstract method and
 * count calls, so we can test caching behaviour.
 *
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class testable_renderer_factory extends renderer_factory_base {
    public $createcalls = array();

    public function __construct() {
        parent::__construct(null);
    }

    public function get_renderer($module, $page, $subtype=null) {
        if (!in_array(array($module, $subtype), $this->createcalls)) {
            $this->createcalls[] = array($module, $subtype);
        }
        return new moodle_core_renderer($page);
    }

    public function standard_renderer_class_for_module($module, $subtype=null) {
        return parent::standard_renderer_class_for_module($module, $subtype);
    }
}


/**
 * Renderer class for testing.
 *
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_mod_test_renderer extends moodle_core_renderer {
    public function __construct($containerstack, $page) {
        parent::__construct($containerstack, $page, null);
    }

    public function greeting($name = 'world') {
        return '<h1>Hello ' . $name . '!</h1>';
    }

    public function box($content, $id = '') {
        return box_start($id) . $content . box_end();
    }

    public function box_start($id = '') {
        if ($id) {
            $id = ' id="' . $id . '"';
        }
        $this->containerstack->push('box', '</div>');
        return '<div' . $id . '>';
    }

    public function box_end() {
        return $this->containerstack->pop('box');
    }
}


/**
 * Renderer class for testing subrendering feature
 *
 * @copyright 2009 David Mudrak
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_mod_test_subtype_renderer extends moodle_core_renderer {
    public function __construct($containerstack, $page) {
        parent::__construct($containerstack, $page, null);
    }

    public function signature($user = 'Administrator') {
        return '<div class="signature">Best regards, ' . $user . '</div>';
    }
}


/**
 * Unit tests for the requriement_base base class.
 *
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer_factory_base_test extends UnitTestCase {

    public static $includecoverage = array('lib/outputlib.php');

    public function test_get_calls_create() {
        // Set up.
        $factory = new testable_renderer_factory();
        // Exercise SUT.
        $renderer    = $factory->get_renderer('modulename', new moodle_page);
        $subrenderer = $factory->get_renderer('modulename', new moodle_page, 'subtype');
        $cached      = $factory->get_renderer('modulename', new moodle_page);
        // Verify outcome
        $this->assertEqual(array(array('modulename', null), array('modulename', 'subtype')), $factory->createcalls);

    }

    public function test_standard_renderer_class_for_module_core() {
        // Set up.
        $factory = new testable_renderer_factory();
        // Exercise SUT.
        $classname = $factory->standard_renderer_class_for_module('core');
        // Verify outcome
        $this->assertEqual('moodle_core_renderer', $classname);
    }

    public function test_standard_renderer_class_for_module_test() {
        // Set up.
        $factory = new testable_renderer_factory();
        // Exercise SUT.
        $classname = $factory->standard_renderer_class_for_module('mod_test');
        // Verify outcome
        $this->assertEqual('moodle_mod_test_renderer', $classname);
    }

    public function test_standard_renderer_class_for_module_test_with_subtype() {
        // Set up.
        $factory = new testable_renderer_factory();
        // Exercise SUT.
        $classname = $factory->standard_renderer_class_for_module('mod_test', 'subtype');
        // Verify outcome
        $this->assertEqual('moodle_mod_test_subtype_renderer', $classname);
    }

    public function test_standard_renderer_class_for_module_unknown() {
        // Set up.
        $factory = new testable_renderer_factory();
        $this->expectException();
        // Exercise SUT.
        $classname = $factory->standard_renderer_class_for_module('something_that_does_not_exist');
    }
}


/**
 * Unit tests for the standard_renderer_factory class.
 *
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class standard_renderer_factory_test extends UnitTestCase {
    protected $factory;

    public function setUp() {
        parent::setUp();
        $this->factory = new standard_renderer_factory(null, null);
    }

    public function tearDown() {
        $this->factory = null;
        parent::tearDown();
    }

    public function test_get_core_renderer() {
        $renderer = $this->factory->get_renderer('core', new moodle_page);
        $this->assertIsA($renderer, 'moodle_core_renderer');
    }

    public function test_get_test_renderer() {
        $renderer = $this->factory->get_renderer('mod_test', new moodle_page);
        $this->assertIsA($renderer, 'moodle_mod_test_renderer');
    }

    public function test_get_test_subtype_renderer() {
        $renderer = $this->factory->get_renderer('mod_test', new moodle_page, 'subtype');
        $this->assertIsA($renderer, 'moodle_mod_test_subtype_renderer');
    }
}


/**
 * Unit tests for the custom_corners_renderer_factory class.
 *
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_corners_renderer_factory_test extends UnitTestCase {
    protected $factory;

    public function setUp() {
        parent::setUp();
        $this->factory = new custom_corners_renderer_factory(null, null);
    }

    public function tearDown() {
        $this->factory = null;
        parent::tearDown();
    }

    public function test_get_core_renderer() {
        $renderer = $this->factory->get_renderer('core', new moodle_page);
        $this->assertIsA($renderer, 'custom_corners_core_renderer');
    }

    public function test_get_test_renderer() {
        $renderer = $this->factory->get_renderer('mod_test', new moodle_page);
        $this->assertIsA($renderer, 'moodle_mod_test_renderer');
    }

    public function test_get_test_subtype_renderer() {
        $renderer = $this->factory->get_renderer('mod_test', new moodle_page, 'subtype');
        $this->assertIsA($renderer, 'moodle_mod_test_subtype_renderer');
    }
}


/**
 * Test-specific subclass that implements a getter for $prefixes.
 *
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class testable_theme_overridden_renderer_factory extends theme_overridden_renderer_factory {
    public function get_prefixes() {
        return $this->prefixes;
    }
}


/**
 * Unit tests for the theme_overridden_renderer_factory class.
 *
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class theme_overridden_renderer_factory_test extends UnitTestCase {
    protected $originalcfgthemedir;
    protected $workspace;
    protected $page;
    protected $foldertocleanup = null;

    public function setUp() {
        global $CFG;
        parent::setUp();
        $this->originalcfgthemedir = $CFG->themedir;

        $this->workspace = 'temp/theme_overridden_renderer_factory_fixtures';
        make_upload_directory($this->workspace);
        $CFG->themedir = $CFG->dataroot . '/' . $this->workspace;
        $this->foldertocleanup = $CFG->themedir;

        $this->page = new stdClass;
    }

    public function tearDown() {
        global $CFG;
        if (!empty($this->foldertocleanup)) {
            fulldelete($this->foldertocleanup);
            $this->foldertocleanup = null;
        }
        $CFG->themedir = $this->originalcfgthemedir;
        parent::tearDown();
    }

    protected function make_theme($name) {
        global $CFG;
        $theme = new stdClass;
        $theme->name = $name;
        $theme->dir = $CFG->themedir . '/' . $name;
        make_upload_directory($this->workspace . '/' . $name);
        return $theme;
    }

    protected function write_renderers_file($theme, $code) {
        $filename = $theme->dir . '/renderers.php';
        file_put_contents($filename, "<?php\n" . $code);
    }

    public function test_constructor_theme_with_renderes() {
        // Set up.
        $theme = $this->make_theme('mytheme');
        $this->write_renderers_file($theme, '');

        // Exercise SUT.
        $factory = new testable_theme_overridden_renderer_factory($theme, $this->page);

        // Verify outcome
        $this->assertEqual(array('mytheme_'), $factory->get_prefixes());
    }

    public function test_constructor_theme_without_renderes() {
        // Set up.
        $theme = $this->make_theme('mytheme');

        // Exercise SUT.
        $factory = new testable_theme_overridden_renderer_factory($theme, $this->page);

        // Verify outcome
        $this->assertEqual(array(), $factory->get_prefixes());
    }

    public function test_constructor_theme_with_parent() {
        // Set up.
        $theme = $this->make_theme('mytheme');
        $theme->parent = 'parenttheme';
        $parenttheme = $this->make_theme('parenttheme');
        $this->write_renderers_file($parenttheme, '');

        // Exercise SUT.
        $factory = new testable_theme_overridden_renderer_factory($theme, $this->page);

        // Verify outcome
        $this->assertEqual(array('parenttheme_'), $factory->get_prefixes());
    }

    public function test_get_renderer_not_overridden() {
        // Set up.
        $theme = $this->make_theme('mytheme');
        $this->write_renderers_file($theme, '');
        $factory = new testable_theme_overridden_renderer_factory($theme, $this->page);

        // Exercise SUT.
        $renderer    = $factory->get_renderer('mod_test', new moodle_page);
        $subrenderer = $factory->get_renderer('mod_test', new moodle_page, 'subtype');

        // Verify outcome
        $this->assertIsA($renderer, 'moodle_mod_test_renderer');
        $this->assertIsA($subrenderer, 'moodle_mod_test_subtype_renderer');
    }

    public function test_get_renderer_overridden() {
        // Set up - be very careful because the class under test uses require-once. Pick a unique theme name.
        $theme = $this->make_theme('testrenderertheme');
        $this->write_renderers_file($theme, '
        class testrenderertheme_mod_test_renderer extends moodle_mod_test_renderer {
        }');
        $factory = new testable_theme_overridden_renderer_factory($theme, $this->page);

        // Exercise SUT.
        $renderer    = $factory->get_renderer('mod_test', new moodle_page);
        $subrenderer = $factory->get_renderer('mod_test', new moodle_page, 'subtype');

        // Verify outcome
        $this->assertIsA($renderer, 'testrenderertheme_mod_test_renderer');
        $this->assertIsA($subrenderer, 'moodle_mod_test_subtype_renderer');
    }

    public function test_get_renderer_overridden_in_parent() {
        // Set up.
        $theme = $this->make_theme('childtheme');
        $theme->parent = 'parentrenderertheme';
        $parenttheme = $this->make_theme('parentrenderertheme');
        $this->write_renderers_file($theme, '');
        $this->write_renderers_file($parenttheme, '
        class parentrenderertheme_core_renderer extends moodle_core_renderer {
        }');
        $factory = new testable_theme_overridden_renderer_factory($theme, $this->page);

        // Exercise SUT.
        $renderer = $factory->get_renderer('core', new moodle_page);

        // Verify outcome
        $this->assertIsA($renderer, 'parentrenderertheme_core_renderer');
    }

    public function test_get_renderer_overridden_in_both() {
        // Set up.
        $theme = $this->make_theme('ctheme');
        $theme->parent = 'ptheme';
        $parenttheme = $this->make_theme('ptheme');
        $this->write_renderers_file($theme, '
        class ctheme_core_renderer extends moodle_core_renderer {
        }');
        $this->write_renderers_file($parenttheme, '
        class ptheme_core_renderer extends moodle_core_renderer {
        }');
        $factory = new testable_theme_overridden_renderer_factory($theme, $this->page);

        // Exercise SUT.
        $renderer = $factory->get_renderer('core', new moodle_page);

        // Verify outcome
        $this->assertIsA($renderer, 'ctheme_core_renderer');
    }
}


/**
 * Test-specific subclass that implements a getter for $searchpaths.
 *
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class testable_template_renderer_factory extends template_renderer_factory {
    public function get_search_paths() {
        return $this->searchpaths;
    }
}


/**
 * Unit tests for the template_renderer_factory class.
 *
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_renderer_factory_test extends UnitTestCase {
    protected $originalcfgthemedir;
    protected $workspace;
    protected $page;
    protected $foldertocleanup = null;

    public function setUp() {
        global $CFG;
        parent::setUp();
        $this->originalcfgthemedir = $CFG->themedir;

        $this->workspace = 'temp/template_renderer_factory_fixtures';
        make_upload_directory($this->workspace);
        $CFG->themedir = $CFG->dataroot . '/' . $this->workspace;
        $this->foldertocleanup = $CFG->themedir;

        $this->page = new moodle_page;
    }

    public function tearDown() {
        global $CFG;
        if (!empty($this->foldertocleanup)) {
            fulldelete($this->foldertocleanup);
            $this->foldertocleanup = null;
        }
        $CFG->themedir = $this->originalcfgthemedir;
        parent::tearDown();
    }

    protected function make_theme($name) {
        global $CFG;
        $theme = new stdClass;
        $theme->name = $name;
        $theme->dir = $CFG->themedir . '/' . $name;
        make_upload_directory($this->workspace . '/' . $name);
        return $theme;
    }

    protected function make_theme_template_dir($name, $module = '', $submodule = '') {
        $path = $this->workspace . '/' . $name . '/templates';
        if ($module) {
            $path .= '/' . $module;
        }
        if ($submodule) {
            $path .= '/' . $submodule;
        }
        make_upload_directory($path);
    }

    public function test_constructor_standardtemplate() {
        global $CFG;
        // Set up.
        $theme = $this->make_theme('standardtemplate');
        $this->make_theme_template_dir('standardtemplate');

        // Exercise SUT.
        $factory = new testable_template_renderer_factory($theme, $this->page);

        // Verify outcome
        $this->assertEqual(array($CFG->themedir . '/standardtemplate/templates'),
                $factory->get_search_paths());
    }

    public function test_constructor_mytheme() {
        global $CFG;
        // Set up.
        $theme = $this->make_theme('mytheme');
        $this->make_theme_template_dir('mytheme');
        $this->make_theme_template_dir('standardtemplate');

        // Exercise SUT.
        $factory = new testable_template_renderer_factory($theme, $this->page);

        // Verify outcome
        $this->assertEqual(array(
                $CFG->themedir . '/mytheme/templates',
                $CFG->themedir . '/standardtemplate/templates'),
                $factory->get_search_paths());
    }

    public function test_constructor_mytheme_no_templates() {
        global $CFG;
        // Set up.
        $theme = $this->make_theme('mytheme');
        $this->make_theme_template_dir('standardtemplate');

        // Exercise SUT.
        $factory = new testable_template_renderer_factory($theme, $this->page);

        // Verify outcome
        $this->assertEqual(array($CFG->themedir . '/standardtemplate/templates'),
                $factory->get_search_paths());
    }

    public function test_constructor_mytheme_with_parent() {
        global $CFG;
        // Set up.
        $theme = $this->make_theme('mytheme');
        $theme->parent = 'parenttheme';
        $this->make_theme_template_dir('mytheme');
        $this->make_theme_template_dir('parenttheme');
        $this->make_theme_template_dir('standardtemplate');

        // Exercise SUT.
        $factory = new testable_template_renderer_factory($theme, $this->page);

        // Verify outcome
        $this->assertEqual(array(
                $CFG->themedir . '/mytheme/templates',
                $CFG->themedir . '/parenttheme/templates',
                $CFG->themedir . '/standardtemplate/templates'),
                $factory->get_search_paths());
    }

    public function test_constructor_mytheme_with_parent_no_templates() {
        global $CFG;
        // Set up.
        $theme = $this->make_theme('mytheme');
        $theme->parent = 'parenttheme';
        $this->make_theme_template_dir('mytheme');
        $this->make_theme_template_dir('standardtemplate');

        // Exercise SUT.
        $factory    = new testable_template_renderer_factory($theme, $this->page);
        $subfactory = new testable_template_renderer_factory($theme, $this->page, 'subtype');

        // Verify outcome
        $this->assertEqual(array(
                $CFG->themedir . '/mytheme/templates',
                $CFG->themedir . '/standardtemplate/templates'),
                $factory->get_search_paths());
        $this->assertEqual(array(
                $CFG->themedir . '/mytheme/templates',
                $CFG->themedir . '/standardtemplate/templates'),
                $subfactory->get_search_paths());
    }

    public function test_get_renderer() {
        global $CFG;
        // Set up.
        $theme = $this->make_theme('mytheme');
        $theme->parent = 'parenttheme';
        $this->make_theme_template_dir('mytheme', 'core');
        $this->make_theme_template_dir('parenttheme', 'mod_test');
        $this->make_theme_template_dir('standardtemplate', 'mod_test');
        $this->make_theme_template_dir('parenttheme', 'mod_test', 'subtype');
        $this->make_theme_template_dir('standardtemplate', 'mod_test', 'subtype');
        $factory = new testable_template_renderer_factory($theme);

        // Exercise SUT.
        $renderer    = $factory->get_renderer('mod_test', $this->page);
        $subrenderer = $factory->get_renderer('mod_test', $this->page, 'subtype');

        // Verify outcome
        $this->assertEqual('moodle_mod_test_renderer', $renderer->get_copied_class());
        $this->assertEqual(array(
                $CFG->themedir . '/parenttheme/templates/mod_test',
                $CFG->themedir . '/standardtemplate/templates/mod_test'),
                $renderer->get_search_paths());
        $this->assertEqual('moodle_mod_test_subtype_renderer', $subrenderer->get_copied_class());
        $this->assertEqual(array(
                $CFG->themedir . '/parenttheme/templates/mod_test/subtype',
                $CFG->themedir . '/standardtemplate/templates/mod_test/subtype'),
                $subrenderer->get_search_paths());
    }
}


/**
 * Unit tests for the xhtml_container_stack class.
 *
 * These tests assume that developer debug mode is on, which, at the time of
 * writing, is true. admin/report/unittest/index.php forces it on.
 *
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class xhtml_container_stack_test extends UnitTestCase {
    protected function start_capture() {
        ob_start();
    }

    protected function end_capture() {
        $result = ob_get_contents();
        ob_end_clean();
        return $result;
    }

    public function test_push_then_pop() {
        // Set up.
        $stack = new xhtml_container_stack();
        // Exercise SUT.
        $this->start_capture();
        $stack->push('testtype', '</div>');
        $html = $stack->pop('testtype');
        $errors = $this->end_capture();
        // Verify outcome
        $this->assertEqual('</div>', $html);
        $this->assertEqual('', $errors);
    }

    public function test_mismatched_pop_prints_warning() {
        // Set up.
        $stack = new xhtml_container_stack();
        $stack->push('testtype', '</div>');
        // Exercise SUT.
        $this->start_capture();
        $html = $stack->pop('mismatch');
        $errors = $this->end_capture();
        // Verify outcome
        $this->assertEqual('</div>', $html);
        $this->assertNotEqual('', $errors);
    }

    public function test_pop_when_empty_prints_warning() {
        // Set up.
        $stack = new xhtml_container_stack();
        // Exercise SUT.
        $this->start_capture();
        $html = $stack->pop('testtype');
        $errors = $this->end_capture();
        // Verify outcome
        $this->assertEqual('', $html);
        $this->assertNotEqual('', $errors);
    }

    public function test_correct_nesting() {
        // Set up.
        $stack = new xhtml_container_stack();
        // Exercise SUT.
        $this->start_capture();
        $stack->push('testdiv', '</div>');
        $stack->push('testp', '</p>');
        $html2 = $stack->pop('testp');
        $html1 = $stack->pop('testdiv');
        $errors = $this->end_capture();
        // Verify outcome
        $this->assertEqual('</p>', $html2);
        $this->assertEqual('</div>', $html1);
        $this->assertEqual('', $errors);
    }

    public function test_pop_all_but_last() {
        // Set up.
        $stack = new xhtml_container_stack();
        $stack->push('test1', '</h1>');
        $stack->push('test2', '</h2>');
        $stack->push('test3', '</h3>');
        // Exercise SUT.
        $this->start_capture();
        $html = $stack->pop_all_but_last();
        $errors = $this->end_capture();
        // Verify outcome
        $this->assertEqual('</h3></h2>', $html);
        $this->assertEqual('', $errors);
        // Tear down.
        $stack->discard();
    }

    public function test_pop_all_but_last_only_one() {
        // Set up.
        $stack = new xhtml_container_stack();
        $stack->push('test1', '</h1>');
        // Exercise SUT.
        $this->start_capture();
        $html = $stack->pop_all_but_last();
        $errors = $this->end_capture();
        // Verify outcome
        $this->assertEqual('', $html);
        $this->assertEqual('', $errors);
        // Tear down.
        $stack->discard();
    }

    public function test_pop_all_but_last_empty() {
        // Set up.
        $stack = new xhtml_container_stack();
        // Exercise SUT.
        $this->start_capture();
        $html = $stack->pop_all_but_last();
        $errors = $this->end_capture();
        // Verify outcome
        $this->assertEqual('', $html);
        $this->assertEqual('', $errors);
    }

    public function test_destruct() {
        // Set up.
        $stack = new xhtml_container_stack();
        $stack->push('test1', '</somethingdistinctive>');
        // Exercise SUT.
        $this->start_capture();
        $stack = null;
        $errors = $this->end_capture();
        // Verify outcome
        $this->assertPattern('/<\/somethingdistinctive>/', $errors);
    }

    public function test_destruct_empty() {
        // Set up.
        $stack = new xhtml_container_stack();
        // Exercise SUT.
        $this->start_capture();
        $stack = null;
        $errors = $this->end_capture();
        // Verify outcome
        $this->assertEqual('', $errors);
    }

    public function test_discard() {
        // Set up.
        $stack = new xhtml_container_stack();
        $stack->push('test1', '</somethingdistinctive>');
        $stack->discard();
        // Exercise SUT.
        $this->start_capture();
        $stack = null;
        $errors = $this->end_capture();
        // Verify outcome
        $this->assertEqual('', $errors);
    }
}


/**
 * Unit tests for the template_renderer class.
 *
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_renderer_test extends UnitTestCase {
    protected $renderer;
    protected $templatefolder;
    protected $savedtemplates;

    public function setUp() {
        global $CFG;
        parent::setUp();
        $this->templatefolder = $CFG->dataroot . '/temp/template_renderer_fixtures/test';
        make_upload_directory('temp/template_renderer_fixtures/test');
        $page = new moodle_page;
        $this->renderer = new template_renderer('moodle_mod_test_renderer',
                array($this->templatefolder), $page);
    }

    public function tearDown() {
        $this->renderer = null;
        foreach ($this->savedtemplates as $template) {
            unlink($template);
        }
        $this->savedtemplates = array();
        parent::tearDown();
    }

    protected function save_template($name, $contents) {
        $filename = $this->templatefolder . '/' . $name . '.php';
        $this->savedtemplates[] = $filename;
        file_put_contents($filename, $contents);
    }

    public function test_simple_template() {
        $this->save_template('greeting', '<p>Hello <?php echo $name ?>!</p>');

        $html = $this->renderer->greeting('Moodle');
        $this->assertEqual('<p>Hello Moodle!</p>', $html);
    }

    public function test_simple_template_default_argument_value() {
        $this->save_template('greeting', '<p>Hello <?php echo $name ?>!</p>');

        $html = $this->renderer->greeting();
        $this->assertEqual('<p>Hello world!</p>', $html);
    }

    public function test_box_template() {
        $this->save_template('box', '<div class="box"<?php echo $id ?>><?php echo $content ?></div>');

        $html = $this->renderer->box('This is a message in a box', 'messagediv');
        $this->assertEqual('<div class="box"messagediv>This is a message in a box</div>', $html);
    }

    public function test_box_start_end_templates() {
        $this->save_template('box', '<div class="box"<?php echo $id ?>><?php echo $content ?></div>');

        $html = $this->renderer->box_start('messagediv');
        $this->assertEqual('<div class="box"messagediv>', $html);

        $html = $this->renderer->box_end();
        $this->assertEqual('</div>', $html);
    }
}


/**
 * Unit tests for the moodle_core_renderer class.
 *
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_core_renderer_test extends UnitTestCase {
    protected $renderer;

    public function setUp() {
        parent::setUp();
        $this->renderer = new moodle_core_renderer(new moodle_page);
    }

    public function test_select_simple() {
        $select = moodle_select::make(array(10 => 'ten', 'c2' => 'two'), 'mymenu');
        $html = $this->renderer->select($select);
        $this->assert(new ContainsTagWithAttributes('select', array('class' => 'menumymenu select', 'name' => 'mymenu', 'id' => 'menumymenu')), $html);
        $this->assert(new ContainsTagWithContents('option', 'ten'), $html);
        $this->assert(new ContainsTagWithAttribute('option', 'value', '10'), $html);
        $this->assert(new ContainsTagWithContents('option', 'two'), $html);
        $this->assert(new ContainsTagWithAttribute('option', 'value', 'c2'), $html);
    }

    public function test_error_text() {
        $html = $this->renderer->error_text('message');
        $this->assert(new ContainsTagWithContents('span', 'message'), $html);
        $this->assert(new ContainsTagWithAttribute('span', 'class', 'error'), $html);
    }

    public function test_error_text_blank() {
        $html = $this->renderer->error_text('');
        $this->assertEqual('', $html);
    }

    public function test_link_to_popup_empty_link() {
        // Empty link object: link MUST have a text value
        $link = new html_link();
        $popupaction = new popup_action('click', 'http://test.com', 'my_popup');
        $link->add_action($popupaction);
        $this->expectException();
        $html = $this->renderer->link_to_popup($link);
    }

    public function test_link_to_popup() {
        $link = new html_link();
        $link->text = 'Click here';
        $link->url = 'http://test.com';
        $link->title = 'Popup window';
        $popupaction = new popup_action('click', 'http://test.com', 'my_popup');
        $link->add_action($popupaction);

        $html = $this->renderer->link_to_popup($link);
        $expectedattributes = array('title' => 'Popup window', 'href' => 'http://test.com');
        $this->assert(new ContainsTagWithAttributes('a', $expectedattributes), $html);
        $this->assert(new ContainsTagWithContents('a', 'Click here'), $html);

        // Try a different url for the link than for the popup
        $link->url = 'http://otheraddress.com';
        $html = $this->renderer->link_to_popup($link);

        $this->assert(new ContainsTagWithAttribute('a', 'title', 'Popup window'), $html);
        $this->assert(new ContainsTagWithAttribute('a', 'href', 'http://otheraddress.com'), $html);
        $this->assert(new ContainsTagWithContents('a', 'Click here'), $html);

        // Give it a moodle_url object instead of a string
        $link->url = new moodle_url('http://otheraddress.com');
        $html = $this->renderer->link_to_popup($link);
        $this->assert(new ContainsTagWithAttribute('a', 'title', 'Popup window'), $html);
        $this->assert(new ContainsTagWithAttribute('a', 'href', 'http://otheraddress.com'), $html);
        $this->assert(new ContainsTagWithContents('a', 'Click here'), $html);

    }

    public function test_button() {
        global $CFG;
        $originalform = new html_form();
        $originalform->button->text = 'Click Here';
        $originalform->url = '/index.php';

        $form = clone($originalform);

        $html = $this->renderer->button($form);
        $this->assert(new ContainsTagWithAttribute('div', 'class', 'singlebutton'), $html);
        $this->assert(new ContainsTagWithAttributes('form', array('method' => 'post', 'action' => $CFG->wwwroot . '/index.php')), $html);
        $this->assert(new ContainsTagWithAttributes('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey())), $html);
        $this->assert(new ContainsTagWithAttributes('input', array('value' => 'Click Here', 'type' => 'submit')), $html);

        $form = clone($originalform);
        $form->button->confirmmessage = 'Are you sure?';

        $html = $this->renderer->button($form);
        $this->assert(new ContainsTagWithAttribute('div', 'class', 'singlebutton'), $html);
        $this->assert(new ContainsTagWithAttributes('form', array('method' => 'post', 'action' => $CFG->wwwroot . '/index.php')), $html);
        $this->assert(new ContainsTagWithAttribute('input', 'type', 'submit'), $html);

        $form = clone($originalform);
        $form->url = new moodle_url($form->url, array('var1' => 'value1', 'var2' => 'value2'));
        $html = $this->renderer->button($form);
        $this->assert(new ContainsTagWithAttributes('input', array('name' => 'var1', 'type' => 'hidden', 'value' => 'value1')), $html);

    }

    public function test_link() {
        $link = new html_link();
        $link->url = 'http://test.com';
        $link->text = 'Resource 1';

        $html = $this->renderer->link($link);
        $this->assert(new ContainsTagWithAttribute('a', 'href', 'http://test.com'), $html);
        $this->assert(new ContainsTagWithContents('a', 'Resource 1'), $html);

        // Add a title
        $link->title = 'Link to resource 1';
        $html = $this->renderer->link($link);
        $this->assert(new ContainsTagWithAttributes('a', array('title' => 'Link to resource 1', 'href' => 'http://test.com')), $html);
        $this->assert(new ContainsTagWithContents('a', 'Resource 1'), $html);

        // Use a moodle_url object instead of string
        $link->url = new moodle_url($link->url);
        $html = $this->renderer->link($link);
        $this->assert(new ContainsTagWithAttributes('a', array('title' => 'Link to resource 1', 'href' => 'http://test.com')), $html);
        $this->assert(new ContainsTagWithContents('a', 'Resource 1'), $html);

        // Add a few classes to the link object
        $link->add_classes('cool blue');
        $html = $this->renderer->link($link);
        $this->assert(new ContainsTagWithAttributes('a', array('title' => 'Link to resource 1', 'class' => 'cool blue', 'href' => 'http://test.com')), $html);
        $this->assert(new ContainsTagWithContents('a', 'Resource 1'), $html);

        // Simple use of link() without a html_link object
        $html = $this->renderer->link($link->url->out(), $link->text);
        $expected_html = '<a href="http://test.com">Resource 1</a>';
        $this->assert(new ContainsTagWithAttribute('a', 'href', 'http://test.com'), $html);
        $this->assert(new ContainsTagWithContents('a', 'Resource 1'), $html);

        // Missing second param when first is a string: exception
        $this->expectException();
        $html = $this->renderer->link($link->url->out());
    }

    /**
     * NOTE: consider the degree of detail in which we test HTML output, because
     * the unit tests may be run under a different theme, with different HTML
     * renderers. Maybe we should limit unit tests to standardwhite.
     */
    public function test_confirm() {
        // Basic test with string URLs
        $continueurl = 'http://www.test.com/index.php?continue=1';
        $cancelurl = 'http://www.test.com/index.php?cancel=1';
        $message = 'Are you sure?';

        $html = $this->renderer->confirm($message, $continueurl, $cancelurl);
        $this->assert(new ContainsTagWithAttributes('div', array('id' => 'notice', 'class' => 'box generalbox')), $html);
        $this->assert(new ContainsTagWithContents('p', $message), $html);
        $this->assert(new ContainsTagWithAttribute('div', 'class', 'buttons'), $html);
        $this->assert(new ContainsTagWithAttribute('div', 'class', 'singlebutton'), $html);
        $this->assert(new ContainsTagWithAttributes('form', array('method' => 'post', 'action' => 'http://www.test.com/index.php')), $html);
        $this->assert(new ContainsTagWithAttributes('input', array('type' => 'hidden', 'name' => 'continue', 'value' => 1)), $html);
        $this->assert(new ContainsTagWithAttributes('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey())), $html);
        $this->assert(new ContainsTagWithAttributes('input', array('type' => 'submit', 'value' => get_string('yes'), 'class' => 'singlebutton')), $html);
        $this->assert(new ContainsTagWithAttributes('input', array('type' => 'hidden', 'name' => 'cancel', 'value' => 1)), $html);
        $this->assert(new ContainsTagWithAttributes('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey())), $html);
        $this->assert(new ContainsTagWithAttributes('input', array('type' => 'submit', 'value' => get_string('no'), 'class' => 'singlebutton')), $html);

        // Use html_forms with default values, should produce exactly the same output as above
        $formcontinue = new html_form();
        $formcancel = new html_form();
        $formcontinue->url = new moodle_url($continueurl);
        $formcancel->url = new moodle_url($cancelurl);
        $html = $this->renderer->confirm($message, $formcontinue, $formcancel);
        $this->assert(new ContainsTagWithAttributes('div', array('id' => 'notice', 'class' => 'box generalbox')), $html);
        $this->assert(new ContainsTagWithContents('p', $message), $html);
        $this->assert(new ContainsTagWithAttribute('div', 'class', 'buttons'), $html);
        $this->assert(new ContainsTagWithAttribute('div', 'class', 'singlebutton'), $html);
        $this->assert(new ContainsTagWithAttributes('form', array('method' => 'post', 'action' => 'http://www.test.com/index.php')), $html);
        $this->assert(new ContainsTagWithAttributes('input', array('type' => 'hidden', 'name' => 'continue', 'value' => 1)), $html);
        $this->assert(new ContainsTagWithAttributes('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey())), $html);
        $this->assert(new ContainsTagWithAttributes('input', array('type' => 'submit', 'value' => get_string('yes'), 'class' => 'singlebutton')), $html);
        $this->assert(new ContainsTagWithAttributes('input', array('type' => 'hidden', 'name' => 'cancel', 'value' => 1)), $html);
        $this->assert(new ContainsTagWithAttributes('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey())), $html);
        $this->assert(new ContainsTagWithAttributes('input', array('type' => 'submit', 'value' => get_string('no'), 'class' => 'singlebutton')), $html);

        // Give the buttons some different labels
        $formcontinue = new html_form();
        $formcancel = new html_form();
        $formcontinue->url = new moodle_url($continueurl);
        $formcancel->url = new moodle_url($cancelurl);
        $formcontinue->button->text = 'Continue anyway';
        $formcancel->button->text = 'Wow OK, I get it, backing out!';
        $html = $this->renderer->confirm($message, $formcontinue, $formcancel);
        $this->assert(new ContainsTagWithAttributes('form', array('method' => 'post', 'action' => 'http://www.test.com/index.php')), $html);
        $this->assert(new ContainsTagWithAttributes('input', array('type' => 'hidden', 'name' => 'continue', 'value' => 1)), $html);
        $this->assert(new ContainsTagWithAttributes('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey())), $html);
        $this->assert(new ContainsTagWithAttributes('input', array('type' => 'submit', 'value' => $formcontinue->button->text, 'class' => 'singlebutton')), $html);
        $this->assert(new ContainsTagWithAttributes('input', array('type' => 'hidden', 'name' => 'cancel', 'value' => 1)), $html);
        $this->assert(new ContainsTagWithAttributes('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey())), $html);
        $this->assert(new ContainsTagWithAttributes('input', array('type' => 'submit', 'value' => $formcancel->button->text, 'class' => 'singlebutton')), $html);

        // Change the method and add extra variables
        $formcontinue = new html_form();
        $formcancel = new html_form();
        $formcontinue->url = new moodle_url($continueurl, array('var1' => 'val1', 'var2' => 'val2'));
        $formcancel->url = new moodle_url($cancelurl, array('var3' => 'val3', 'var4' => 'val4'));
        $html = $this->renderer->confirm($message, $formcontinue, $formcancel);
        $this->assert(new ContainsTagWithAttributes('form', array('method' => 'post', 'action' => 'http://www.test.com/index.php')), $html);
        $this->assert(new ContainsTagWithAttributes('input', array('type' => 'hidden', 'name' => 'continue', 'value' => 1)), $html);
        $this->assert(new ContainsTagWithAttributes('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey())), $html);
        $this->assert(new ContainsTagWithAttributes('input', array('type' => 'hidden', 'name' => 'var1', 'value' => 'val1')), $html);
        $this->assert(new ContainsTagWithAttributes('input', array('type' => 'hidden', 'name' => 'var2', 'value' => 'val2')), $html);
        $this->assert(new ContainsTagWithAttributes('input', array('type' => 'submit', 'value' => get_string('yes'), 'class' => 'singlebutton')), $html);
        $this->assert(new ContainsTagWithAttributes('input', array('type' => 'hidden', 'name' => 'cancel', 'value' => 1)), $html);
        $this->assert(new ContainsTagWithAttributes('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey())), $html);
        $this->assert(new ContainsTagWithAttributes('input', array('type' => 'submit', 'value' => get_string('no'), 'class' => 'singlebutton')), $html);
        $this->assert(new ContainsTagWithAttributes('input', array('type' => 'hidden', 'name' => 'var3', 'value' => 'val3')), $html);
        $this->assert(new ContainsTagWithAttributes('input', array('type' => 'hidden', 'name' => 'var4', 'value' => 'val4')), $html);

    }

    public function test_spacer() {
        global $CFG;

        $spacer = new html_image();

        $html = $this->renderer->spacer($spacer);
        $this->assert(new ContainsTagWithAttributes('img', array('class' => 'image spacer',
                 'src' => $CFG->wwwroot . '/pix/spacer.gif',
                 'alt' => ''
                 )), $html);
        $spacer = new html_image();
        $spacer->src = $this->renderer->old_icon_url('myspacer');
        $spacer->alt = 'sometext';
        $spacer->add_class('my');

        $html = $this->renderer->spacer($spacer);

        $this->assert(new ContainsTagWithAttributes('img', array(
                 'class' => 'my image spacer',
                 'src' => $this->renderer->old_icon_url('myspacer'),
                 'alt' => 'sometext')), $html);

    }

    public function test_paging_bar() {
        global $CFG, $OUTPUT;

        $totalcount = 5;
        $perpage = 4;
        $page = 1;
        $baseurl = new moodle_url($CFG->wwwroot.'/index.php');
        $pagevar = 'mypage';

        $pagingbar = new moodle_paging_bar();
        $pagingbar->totalcount = $totalcount;
        $pagingbar->page = $page;
        $pagingbar->perpage = $perpage;
        $pagingbar->baseurl = $baseurl;
        $pagingbar->pagevar = $pagevar;
        $pagingbar->nocurr = true;

        $originalbar = clone($pagingbar);

        $html = $OUTPUT->paging_bar($pagingbar);

        $this->assert(new ContainsTagWithAttribute('div', 'class', 'paging'), $html);
        $this->assert(new ContainsTagWithAttributes('a', array('class' => 'previous', 'href' => $baseurl->out().'?mypage=0')), $html);
        // One of the links to the previous page must not have the 'previous' class
        $this->assert(new ContainsTagWithAttributes('a', array('href' => $baseurl->out().'?mypage=0'), array('class' => 'previous')), $html);
        // The link to the current page must not have the 'next' class: it's the last page
        $this->assert(new ContainsTagWithAttributes('a', array('href' => $baseurl->out().'?mypage=1'), array('class' => 'next')), $html);

        $pagingbar = clone($originalbar); // clone the original bar before each output and set of assertions
        $pagingbar->nocurr = false;
        $html = $OUTPUT->paging_bar($pagingbar);
        $this->assert(new ContainsTagWithAttribute('div', 'class', 'paging'), $html);
        $this->assert(new ContainsTagWithAttributes('a', array('href' => $baseurl->out().'?mypage=0'), array('class' => 'previous')), $html);
        $this->assert(new ContainsTagWithAttributes('a', array('class' => 'previous', 'href' => $baseurl->out().'?mypage=0')), $html);
        $expectation = new ContainsTagWithAttributes('a', array('href' => $baseurl->out().'?mypage=1'), array('class' => 'next'));
        $this->assertFalse($expectation->test($html));

        // TODO test with more different parameters
    }

    public function test_html_list() {
        $htmllist = new html_list();
        $data = array('item1', 'item2', array('item1-1', 'item1-2'));
        $htmllist->load_data($data);
        $htmllist->items[2]->type = 'ordered';
        $html = $this->renderer->htmllist($htmllist);
    }

    public function test_moodle_select() {
        $options = array('var1' => 'value1', 'var2' => 'value2', 'var3' => 'value3');
        $select = moodle_select::make($options, 'mymenu', 'var2');
        $html = $this->renderer->select($select);
        $this->assert(new ContainsTagWithAttributes('select', array('name' => 'mymenu')), $html);
        $this->assert(new ContainsTagWithAttributes('option', array('value' => 'var1'), array('selected' => 'selected')), $html);
        $this->assert(new ContainsTagWithAttributes('option', array('value' => 'var2', 'selected' => 'selected')), $html);
        $this->assert(new ContainsTagWithAttributes('option', array('value' => 'var3'), array('selected' => 'selected')), $html);
        $this->assert(new ContainsTagWithContents('option', 'value1'), $html);
        $this->assert(new ContainsTagWithContents('option', 'value2'), $html);
        $this->assert(new ContainsTagWithContents('option', 'value3'), $html);

        $options = array('group1' => '--group1', 'var1' => 'value1', 'var2' => 'value2', 'group2' => '--', 'group2' => '--group2', 'var3' => 'value3', 'var4' => 'value4');
        $select = moodle_select::make($options, 'mymenu', 'var2');
        $html = $this->renderer->select($select);
        $this->assert(new ContainsTagWithAttributes('select', array('name' => 'mymenu')), $html);
        $this->assert(new ContainsTagWithAttributes('optgroup', array('label' => 'group1')), $html);
        $this->assert(new ContainsTagWithAttributes('optgroup', array('label' => 'group2')), $html);
        $this->assert(new ContainsTagWithAttributes('option', array('value' => 'var1'), array('selected' => 'selected')), $html);
        $this->assert(new ContainsTagWithAttributes('option', array('value' => 'var2', 'selected' => 'selected')), $html);
        $this->assert(new ContainsTagWithAttributes('option', array('value' => 'var3'), array('selected' => 'selected')), $html);
        $this->assert(new ContainsTagWithAttributes('option', array('value' => 'var4'), array('selected' => 'selected')), $html);
        $this->assert(new ContainsTagWithContents('option', 'value1'), $html);
        $this->assert(new ContainsTagWithContents('option', 'value2'), $html);
        $this->assert(new ContainsTagWithContents('option', 'value3'), $html);
        $this->assert(new ContainsTagWithContents('option', 'value4'), $html);
    }

    public function test_userpicture() {
        global $CFG;
        // Set up the user with the required fields
        $user = new stdClass();
        $user->firstname = 'Test';
        $user->lastname = 'User';
        $user->picture = false;
        $user->imagealt = false;
        $user->id = 1;
        $userpic = new moodle_user_picture();
        $userpic->user = $user;
        $userpic->courseid = 1;
        $userpic->url = true;
        // Setting popup to true adds JS for the link to open in a popup
        $userpic->popup = true;
        $html = $this->renderer->user_picture($userpic);
        $this->assert(new ContainsTagWithAttributes('a', array('title' => 'Test User', 'href' => $CFG->wwwroot.'/user/view.php?id=1&course=1')), $html);
    }

    public function test_heading_with_help() {
        $originalicon = new moodle_help_icon();
        $originalicon->page = 'myhelppage';
        $originalicon->text = 'Cool help text';

        $helpicon = clone($originalicon);
        $html = $this->renderer->heading_with_help($helpicon);
        $this->assert(new ContainsTagWithAttribute('div', 'class', 'heading-with-help'), $html);
        $this->assert(new ContainsTagWithAttribute('span', 'class', 'helplink'), $html);
        $this->assert(new ContainsTagWithAttribute('h2', 'class', 'main help'), $html);
        $this->assert(new ContainsTagWithAttributes('img', array('class' => 'iconhelp image', 'src' => $this->renderer->old_icon_url('help'))), $html);
        $this->assert(new ContainsTagWithContents('h2', 'Cool help text'), $html);

        $helpicon = clone($originalicon);
        $helpicon->image = false;

        $html = $this->renderer->heading_with_help($helpicon);
        $this->assert(new ContainsTagWithAttribute('div', 'class', 'heading-with-help'), $html);
        $this->assert(new ContainsTagWithAttribute('span', 'class', 'helplink'), $html);
        $this->assert(new ContainsTagWithAttribute('h2', 'class', 'main help'), $html);
        $this->assert(new ContainsTagWithAttributes('img', array('class' => 'iconhelp image', 'src' => $this->renderer->old_icon_url('help'))), $html);
        $this->assert(new ContainsTagWithContents('h2', 'Cool help text'), $html);
    }
}
