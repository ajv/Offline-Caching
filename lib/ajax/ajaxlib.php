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
 * Library functions to facilitate the use of JavaScript in Moodle.
 *
 * @package   moodlecore
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * Initialise a {@link page_requirements_manager} with the bits of JavaScript that every
 * Moodle page should have.
 *
 * @param page_requirements_manager $requires The page_requirements_manager to initialise.
 */
function setup_core_javascript(page_requirements_manager $requires) {
    global $CFG;

    // JavaScript should always work with $CFG->httpswwwroot rather than $CFG->wwwroot.
    // Otherwise, in some situations, users will get warnings about insecure content
    // on sercure pages from their web browser.

    $config = array(
        'wwwroot' => $CFG->httpswwwroot, // Yes, really. See above.
        'sesskey' => sesskey(),
    );
    if (debugging('', DEBUG_DEVELOPER)) {
        $config['developerdebug'] = true;
    }
    $requires->data_for_js('moodle_cfg', $config)->in_head();

    if (debugging('', DEBUG_DEVELOPER)) {
        $requires->yui_lib('logger');
    }

    // Note that, as a short-cut, the code 
    // $js = "document.body.className += ' jsenabled';\n";
    // is hard-coded in {@link page_requirements_manager::get_top_of_body_code)
}


/**
 * This class tracks all the things that are needed by the current page.
 *
 * Normally, the only instance of this  class you will need to work with is the
 * one accessible via $PAGE->requires.
 *
 * Typical useage would be
 * <pre>
 *     $PAGE->requires->css('mod/mymod/styles.css');
 *     $PAGE->requires->js('mod/mymod/script.js');
 *     $PAGE->requires->js('mod/mymod/small_but_urgent.js')->in_head();
 *     $PAGE->requires->js_function_call('init_mymod', array($data))->on_dom_ready();
 * </pre>
 *
 * There are some natural restrictions on some methods. For example, {@link css()}
 * can only be called before the <head> tag is output. See the comments on the
 * individual methods for details.
 *
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since Moodle 2.0
 */
class page_requirements_manager {
    const WHEN_IN_HEAD = 0;
    const WHEN_TOP_OF_BODY = 10;
    const WHEN_AT_END = 20;
    const WHEN_ON_DOM_READY = 30;

    protected $linkedrequiremets = array();
    protected $stringsforjs = array();
    protected $requiredjscode = array();

    protected $variablesinitialised = array('mstr' => 1); // 'mstr' is special. See string_for_js.

    protected $headdone = false;
    protected $topofbodydone = false;

    /**
     * Ensure that the specified JavaScript file is linked to from this page.
     *
     * By default the link is put at the end of the page, since this gives best page-load performance.
     *
     * Even if a particular script is requested more than once, it will only be linked
     * to once.
     *
     * @param $jsfile The path to the .js file, relative to $CFG->dirroot / $CFG->wwwroot.
     *      No leading slash. For example 'mod/mymod/customscripts.js';
     * @param boolean $fullurl This parameter is intended for internal use only.
     *      However, in exceptional circumstances you may wish to use it to link
     *      to JavaScript on another server. For example, lib/recaptchalib.php has to
     *      do this. This really should only be done in exceptional circumstances. This
     *      may change in the future without warning.
     *      (If true, $jsfile is treaded as a full URL, not relative $CFG->wwwroot.)
     * @return required_js The required_js object. This allows you to control when the
     *      link to the script is output by calling methods like {@link required_js::asap()} or
     *      {@link required_js::in_head()}.
     */
    public function js($jsfile, $fullurl = false) {
        global $CFG;
        if (!$fullurl) {
            // strtok is used to trim off any GET string arguments before looking for the file
            if (!file_exists($CFG->dirroot . '/' . strtok($jsfile, '?'))) {
                throw new coding_exception('Attept to require a JavaScript file that does not exist.', $jsfile);
            }
            $url = $CFG->httpswwwroot . '/' . $jsfile;
        } else {
            $url = $jsfile;
        }
        if (!isset($this->linkedrequiremets[$url])) {
            $this->linkedrequiremets[$url] = new required_js($this, $url);
        }
        return $this->linkedrequiremets[$url];
    }

    /**
     * Ensure that the specified YUI library file, and all its required dependancies,
     * are linked to from this page.
     *
     * By default the link is put at the end of the page, since this gives best page-load
     * performance. Optional dependencies are not loaded automatically - if you want
     * them you will need to load them first with other calls to this method.
     *
     * If the YUI library you ask for requires one or more CSS files, and if
     * <head> has already been printed, then an exception will be thrown.
     * Therefore, you are strongly advised to request all the YUI libraries you
     * will need before output is started.
     *
     * Even if a particular library is requested more than once (perhaps as a dependancy
     * of other libraries) it will only be linked to once.
     *
     * @param $libname the name of the YUI library you require. For example 'autocomplete'.
     * @return required_yui_lib A requried_yui_lib object. This allows you to control when the
     *      link to the script is output by calling methods like {@link required_yui_lib::asap()} or
     *      {@link required_yui_lib::in_head()}.
     */
    public function yui_lib($libname) {
        $key = 'yui:' . $libname;
        if (!isset($this->linkedrequiremets[$key])) {
            $this->linkedrequiremets[$key] = new required_yui_lib($this, $libname);
        }
        return $this->linkedrequiremets[$key];
    }

    /**
     * Ensure that the specified CSS file is linked to from this page.
     *
     * Because stylesheet links must go in the <head> part of the HTML, you must call
     * this function before {@link get_head_code()} is called. That normally means before
     * the call to print_header. If you call it when it is too late, an exception
     * will be thrown.
     *
     * Even if a particular style sheet is requested more than once, it will only
     * be linked to once.
     *
     * @param string $stylesheet The path to the .css file, relative to
     *      $CFG->dirroot / $CFG->wwwroot. No leading slash. For example
     *      'mod/mymod/styles.css';
     * @param boolean $fullurl This parameter is intended for internal use only.
     *      (If true, $stylesheet is treaded as a full URL, not relative $CFG->wwwroot.)
     */
    public function css($stylesheet, $fullurl = false) {
        global $CFG;

        if ($this->headdone) {
            throw new coding_exception('Cannot require a CSS file after &lt;head> has been printed.', $stylesheet);
        }
        if (!$fullurl) {
            if (!file_exists($CFG->dirroot . '/' . $stylesheet)) {
                throw new coding_exception('Attempt to require a CSS file that does not exist.', $stylesheet);
            }
            $url = $CFG->httpswwwroot . '/' . $stylesheet;
        } else {
            $url = $stylesheet;
        }
        if (!isset($this->linkedrequiremets[$url])) {
            $this->linkedrequiremets[$url] = new required_css($this, $url);
        }
    }

    /**
     * Ensure that a skip link to a given target is printed at the top of the <body>.
     *
     * You must call this function before {@link get_top_of_body_code()}, (if not, an exception
     * will be thrown). That normally means you must call this before the call to print_header.
     *
     * If you ask for a particular skip link to be printed, it is then your responsibility
     * to ensure that the appropraite <a name="..."> tag is printed in the body of the
     * page, so that the skip link goes somewhere.
     *
     * Even if a particular skip link is requested more than once, only one copy of it will be output.
     *
     * @param $target the name of anchor this link should go to. For example 'maincontent'.
     * @param $linktext The text to use for the skip link. Normally get_string('skipto', 'access', ...);
     */
    public function skip_link_to($target, $linktext) {
        if (!isset($this->linkedrequiremets[$target])) {
            $this->linkedrequiremets[$target] = new required_skip_link($this, $target, $linktext);
        }
    }

    /**
     * Ensure that the specified JavaScript function is called from an inline script
     * somewhere on this page.
     *
     * By default the call will be put in a script tag at the
     * end of the page, since this gives best page-load performance.
     *
     * If you request that a particular function is called several times, then
     * that is what will happen (unlike linking to a CSS or JS file, where only
     * one link will be output).
     *
     * @param string $function the name of the JavaScritp function to call. Can
     *      be a compound name like 'YAHOO.util.Event.addListener'. Can also be
     *      used to create and object by using a 'function name' like 'new user_selector'.
     * @param array $arguments and array of arguments to be passed to the function.
     *      When generating the function call, this will be escaped using json_encode,
     *      so passing objects and arrays should work.
     * @return required_js_function_call The required_js_function_call object.
     *      This allows you to control when the link to the script is output by
     *      calling methods like {@link required_js_function_call::in_head()},
     *      {@link required_js_function_call::at_top_of_body()},
     *      {@link required_js_function_call::on_dom_ready()} or
     *      {@link required_js_function_call::after_delay()} methods.
     */
    public function js_function_call($function, $arguments = array()) {
        $requirement = new required_js_function_call($this, $function, $arguments);
        $this->requiredjscode[] = $requirement;
        return $requirement;
    }

    /**
     * Make a language string available to JavaScript.
     * 
     * All the strings will be available in a mstr object in the global namespace.
     * So, for example, after a call to $PAGE->requires->string_for_js('course', 'moodle');
     * then the JavaScript variable mstr.moodle.course will be 'Course', or the
     * equivalent in the current language.
     *
     * The arguments to this function are just like the arguments to get_string
     * except that $module is not optional, and there are limitations on how you
     * use $a. Because each string is only stored once in the JavaScript (based
     * on $identifier and $module) you cannot get the same string with two different
     * values of $a. If you try, an exception will be thrown.
     *
     * If you do need the same string expanded with different $a values, then
     * the solution is to put them in your own data structure (e.g. and array)
     * that you pass to JavaScript with {@link data_for_js()}.
     *
     * @param string $identifier the desired string.
     * @param string $module the language file to look in.
     * @param mixed $a any extra data to add into the string (optional).
     */
    public function string_for_js($identifier, $module, $a = NULL) {
        $string = get_string($identifier, $module, $a);
        if (!$module) {
            throw new coding_exception('The $module parameter is required for page_requirements_manager::string_for_js.');
        }
        if (isset($this->stringsforjs[$module][$identifier]) && $this->stringsforjs[$module][$identifier] != $string) {
            throw new coding_exception("Attempt to re-define already required string '$identifier' " .
                    "from lang file '$module'. Did you already ask for it with a different \$a?");
        }
        $this->stringsforjs[$module][$identifier] = $string;
    }

    /**
     * Make an array of language strings available for JS
     *
     * This function calls the above function {@link string_for_js()} for each requested
     * string in the $identifiers array that is passed to the argument for a single module
     * passed in $module.
     *
     * <code>
     * $PAGE->strings_for_js(Array('one', 'two', 'three'), 'mymod', Array('a', null, 3));
     *
     * // The above is identifical to calling
     *
     * $PAGE->string_for_js('one', 'mymod', 'a');
     * $PAGE->string_for_js('two', 'mymod');
     * $PAGE->string_for_js('three', 'mymod', 3);
     * </code>
     *
     * @param array $identifiers An array of desired strings
     * @param string $module The module to load for
     * @param mixed $a This can either be a single variable that gets passed as extra
     *         information for every string or it can be an array of mixed data where the
     *         key for the data matches that of the identifier it is meant for.
     *
     */
    public function strings_for_js($identifiers, $module, $a=NULL) {
        foreach ($identifiers as $key => $identifier) {
            if (is_array($a) && array_key_exists($key, $a)) {
                $extra = $a[$key];
            } else {
                $extra = $a;
            }
            $this->string_for_js($identifier, $module, $extra);
        }
    }

    /**
     * Make some data from PHP available to JavaScript code.
     * 
     * For example, if you call
     * <pre>
     *      $PAGE->requires->data_for_js('mydata', array('name' => 'Moodle'));
     * </pre>
     * then in JavsScript mydata.name will be 'Moodle'.
     *
     * You cannot call this function more than once with the same variable name
     * (if you try, it will throw an exception). Your code should prepare all the
     * date you want, and then pass it to this method. There is no way to change
     * the value associated with a particular variable later.
     *
     * @param string $variable the the name of the JavaScript variable to assign the data to.
     *      Will probably work if you use a compound name like 'mybuttons.button[1]', but this
     *      should be considered an experimental feature.
     * @param mixed $data The data to pass to JavaScript. This will be escaped using json_encode,
     *      so passing objects and arrays should work.
     * @return required_data_for_js The required_data_for_js object.
     *      This allows you to control when the link to the script is output by
     *      calling methods like {@link required_data_for_js::asap()},
     *      {@link required_data_for_js::in_head()} or
     *      {@link required_data_for_js::at_top_of_body()} methods.
     */
    public function data_for_js($variable, $data) {
        if (isset($this->variablesinitialised[$variable])) {
            throw new coding_exception("A variable called '" . $variable .
                    "' has already been passed ot JavaScript. You cannot overwrite it.");
        }
        $requirement = new required_data_for_js($this, $variable, $data);
        $this->requiredjscode[] = $requirement;
        $this->variablesinitialised[$variable] = 1;
        return $requirement;
    }

    /**
     * Get the code for the linked resources that need to appear in a particular place.
     * @param $when one of the WHEN_... constants.
     * @return string the HTML that should be output in that place.
     */
    protected function get_linked_resources_code($when) {
        $output = '';
        foreach ($this->linkedrequiremets as $requirement) {
            if (!$requirement->is_done() && $requirement->get_when() == $when) {
                $output .= $requirement->get_html();
                $requirement->mark_done();
            }
        }
        return $output;
    }

    /**
     * Get the inline JavaScript code that need to appear in a particular place.
     * @param $when one of the WHEN_... constants.
     * @return string the javascript that should be output in that place.
     */
    protected function get_javascript_code($when, $indent = '') {
        $output = '';
        foreach ($this->requiredjscode as $requirement) {
            if (!$requirement->is_done() && $requirement->get_when() == $when) {
                $output .= $indent . $requirement->get_js_code();
                $requirement->mark_done();
            }
        }
        return $output;
    }

    /**
     * Generate any HTML that needs to go inside the <head> tag.
     *
     * Normally, this method is called automatically by the code that prints the
     * <head> tag. You should not normally need to call it in your own code.
     *
     * @return string the HTML code to to inside the <head> tag.
     */
    public function get_head_code() {
        setup_core_javascript($this);
        $output = $this->get_linked_resources_code(self::WHEN_IN_HEAD);
        $js = $this->get_javascript_code(self::WHEN_IN_HEAD);
        $output .= ajax_generate_script_tag($js);
        $this->headdone = true;
        return $output;
    }

    /**
     * Generate any HTML that needs to go at the start of the <body> tag.
     *
     * Normally, this method is called automatically by the code that prints the
     * <head> tag. You should not normally need to call it in your own code.
     *
     * @return string the HTML code to go at the start of the <body> tag.
     */
    public function get_top_of_body_code() {
        $output = $this->get_linked_resources_code(self::WHEN_TOP_OF_BODY);
        $js = "document.body.className += ' jsenabled';\n";
        $js .= $this->get_javascript_code(self::WHEN_TOP_OF_BODY);
        $output .= ajax_generate_script_tag($js);
        $this->topofbodydone = true;
        return $output;
    }

    /**
     * Generate any HTML that needs to go at the end of the page.
     *
     * Normally, this method is called automatically by the code that prints the
     * page footer. You should not normally need to call it in your own code.
     *
     * @return string the HTML code to to at the end of the page.
     */
    public function get_end_code() {
        $output = $this->get_linked_resources_code(self::WHEN_AT_END);

        if (!empty($this->stringsforjs)) {
            array_unshift($this->requiredjscode, new required_data_for_js($this, 'mstr', $this->stringsforjs));
        }

        $js = $this->get_javascript_code(self::WHEN_AT_END);

        $ondomreadyjs = $this->get_javascript_code(self::WHEN_ON_DOM_READY, '    ');
        if ($ondomreadyjs) {
            $js .= "YAHOO.util.Event.onDOMReady(function() {\n" . $ondomreadyjs . "});\n";
        }

        $output .= ajax_generate_script_tag($js);

        return $output;
    }

    /**
     * @return boolean Have we already output the code in the <head> tag?
     */
    public function is_head_done() {
        return $this->headdone;
    }

    /**
     * @return boolean Have we already output the code at the start of the <body> tag?
     */
    public function is_top_of_body_done() {
        return $this->topofbodydone;
    }
}


/**
 * This is the base class for all sorts of requirements. just to factor out some
 * common code.
 *
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since Moodle 2.0
 */
abstract class requirement_base {
    protected $manager;
    protected $when;
    protected $done = false;

    /**
     * Constructor. Normally the class and its subclasses should not be created
     * directly. Client code should create them via a page_requirements_manager
     * method like ->js(...).
     *
     * @param page_requirements_manager $manager the page_requirements_manager we are associated with.
     */
    protected function __construct(page_requirements_manager $manager) {
        $this->manager = $manager;
    }

    /**
     * Mark that this requirement has been satisfied (that is, that the HTML
     * returned by {@link get_html()} has been output.
     * @return boolean has this requirement been satisfied yet? That is, has
     *      that the HTML returned by {@link get_html()} has been output already.
     */
    public function is_done() {
        return $this->done;
    }

    /**
     * Mark that this requirement has been satisfied (that is, that the HTML
     * returned by {@link get_html()} has been output.
     */
    public function mark_done() {
        $this->done = true;
    }

    /**
     * Where on the page the HTML this requirement is meant to go.
     * @return integer One of the {@link page_requirements_manager}::WHEN_... constants.
     */
    public function get_when() {
        return $this->when;
    }
}

/**
 * This class represents something that must be output somewhere in the HTML.
 *
 * Examples include links to JavaScript or CSS files. However, it should not
 * necessarily be output immediately, we may have to wait for an appropriate time.
 *
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since Moodle 2.0
 */
abstract class linked_requirement extends requirement_base {
    protected $url;

    /**
     * Constructor. Normally the class and its subclasses should not be created
     * directly. Client code should create them via a page_requirements_manager
     * method like ->js(...).
     *
     * @param page_requirements_manager $manager the page_requirements_manager we are associated with.
     * @param string $url The URL of the thing we are linking to.
     */
    protected function __construct(page_requirements_manager $manager, $url) {
        parent::__construct($manager);
        $this->url = $url;
    }

    /**
     * @return string the HTML needed to satisfy this requirement.
     */
    abstract public function get_html();
}


/**
 * A subclass of {@link linked_requirement} to represent a requried JavaScript file.
 *
 * You should not create instances of this class directly. Instead you should
 * work with a {@link page_requirements_manager} - and probably the only
 * page_requirements_manager you will ever need is the one at $PAGE->requires.
 *
 * The methods {@link asap()}, {@link in_head()} and {@link at_top_of_body()}
 * are indented to be used as a fluid API, so you can say things like
 *     $PAGE->requires->js('mod/mymod/script.js')->in_head();
 *
 * However, by default JavaScript files are included at the end of the HTML.
 * This is recommended practice because it means that the web browser will only
 * start loading the javascript files after the rest of the page is loaded, and
 * that gives the best performance for users.
 *
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since Moodle 2.0
 */
class required_js extends linked_requirement {
    /**
     * Constructor. Normally instances of this class should not be created
     * directly. Client code should create them via the page_requirements_manager
     * method {@link page_requirements_manager::js()}.
     *
     * @param page_requirements_manager $manager the page_requirements_manager we are associated with.
     * @param string $url The URL of the JavaScript file we are linking to.
     */
    public function __construct(page_requirements_manager $manager, $url) {
        parent::__construct($manager, $url);
        $this->when = page_requirements_manager::WHEN_AT_END;
    }

    public function get_html() {
        return ajax_get_link_to_script($this->url);
    }

    /**
     * Indicate that the link to this JavaScript file should be output as soon as
     * possible. That is, if this requirement has already been output, this method
     * does nothing. Otherwise, if the <head> tag has not yet been printed, the link
     * to this script will be put in <head>. Otherwise, this method returns a
     * fragment of HTML that the caller is responsible for outputting as soon as
     * possible. In fact, it is recommended that you only call this function from
     * an echo statement, like:
     * <pre>
     *     echo $PAGE->requires->js(...)->asap();
     * </pre>
     *
     * @return string The HTML required to include this JavaScript file. The caller
     * is responsible for outputting this HTML promptly.
     */
    public function asap() {
        if ($this->is_done()) {
            return;
        }
        if (!$this->manager->is_head_done()) {
            $this->in_head();
            return '';
        }
        $output = $this->get_html();
        $this->mark_done();
        return $output;
    }

    /**
     * Indicate that the link to this JavaScript file should be output in the
     * <head> section of the HTML. If it too late for this request to be
     * satisfied, an exception is thrown.
     */
    public function in_head() {
        if ($this->is_done() || $this->when <= page_requirements_manager::WHEN_IN_HEAD) {
            return;
        }
        if ($this->manager->is_head_done()) {
            throw new coding_exception('Too late to ask for a JavaScript file to be linked to from &lt;head>.');
        }
        $this->when = page_requirements_manager::WHEN_IN_HEAD;
    }

    /**
     * Indicate that the link to this JavaScript file should be output at the top
     * of the <body> section of the HTML. If it too late for this request to be
     * satisfied, an exception is thrown.
     */
    public function at_top_of_body() {
        if ($this->is_done() || $this->when <= page_requirements_manager::WHEN_TOP_OF_BODY) {
            return;
        }
        if ($this->manager->is_top_of_body_done()) {
            throw new coding_exception('Too late to ask for a JavaScript file to be linked to from the top of &lt;body>.');
        }
        $this->when = page_requirements_manager::WHEN_TOP_OF_BODY;
    }
}


/**
 * A subclass of {@link linked_requirement} to represent a requried YUI library.
 *
 * You should not create instances of this class directly. Instead you should
 * work with a {@link page_requirements_manager} - and probably the only
 * page_requirements_manager you will ever need is the one at $PAGE->requires.
 *
 * The methods {@link asap()}, {@link in_head()} and {@link at_top_of_body()}
 * are indented to be used as a fluid API, so you can say things like
 *     $PAGE->requires->yui_lib('autocomplete')->in_head();
 *
 * This class (with the help of {@link ajax_resolve_yui_lib()}) knows about the
 * dependancies between the different YUI libraries, and will include all the
 * other libraries required by the one you ask for. It also knows which YUI
 * libraries require css files. If the library you ask for requires CSS files,
 * then you should ask for it before <head> is output, or an exception will
 * be thrown.
 *
 * By default JavaScript files are included at the end of the HTML.
 * This is recommended practice because it means that the web browser will only
 * start loading the javascript files after the rest of the page is loaded, and
 * that gives the best performance for users.
 *
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since Moodle 2.0
 */
class required_yui_lib extends linked_requirement {
    protected $jss = array();

    /**
     * Constructor. Normally instances of this class should not be created
     * directly. Client code should create them via the page_requirements_manager
     * method {@link page_requirements_manager::yui_lib()}.
     *
     * @param page_requirements_manager $manager the page_requirements_manager we are associated with.
     * @param string $libname The name of the YUI library you want. See the array
     * defined in {@link ajax_resolve_yui_lib()} for a list of known libraries.
     */
    public function __construct(page_requirements_manager $manager, $libname) {
        parent::__construct($manager, '');
        $this->when = page_requirements_manager::WHEN_AT_END;

        list($jsurls, $cssurls) = ajax_resolve_yui_lib($libname);
        foreach ($jsurls as $jsurl) {
            $this->jss[] = $manager->js($jsurl, true);
        }
        foreach ($cssurls as $cssurl) {
            // this might be a bit problematic because it requires yui to be
            // requested before print_header() - this was not required in 1.9.x
            $manager->css($cssurl, true);
        }
    }

    public function get_html() {
        // Since we create a required_js for each of our files, that will generate the HTML.
        return '';
    }

    /**
     * Indicate that the link to this YUI library file should be output as soon as
     * possible. The comment above {@link required_js::asap()} applies to this method too.
     *
     * @return string The HTML required to include this JavaScript file. The caller
     * is responsible for outputting this HTML promptly. For example, a good way to
     * call this method is like
     * <pre>
     *     echo $PAGE->requires->yui_lib(...)->asap();
     * </pre>
     */
    public function asap() {
        if ($this->is_done()) {
            return;
        }

        if (!$this->manager->is_head_done()) {
            $this->in_head();
            return '';
        }

        $output = '';
        foreach ($this->jss as $requiredjs) {
            $output .= $requiredjs->asap();
        }
        $this->mark_done();
        return $output;
    }

    /**
     * Indicate that the links to this  YUI library should be output in the
     * <head> section of the HTML. If it too late for this request to be
     * satisfied, an exception is thrown.
     */
    public function in_head() {
        if ($this->is_done() || $this->when <= page_requirements_manager::WHEN_IN_HEAD) {
            return;
        }

        if ($this->manager->is_head_done()) {
            throw new coding_exception('Too late to ask for a YUI library to be linked to from &lt;head>.');
        }

        $this->when = page_requirements_manager::WHEN_IN_HEAD;
        foreach ($this->jss as $requiredjs) {
            $requiredjs->in_head();
        }
    }

    /**
     * Indicate that the links to this YUI library should be output in the
     * <head> section of the HTML. If it too late for this request to be
     * satisfied, an exception is thrown.
     */
    public function at_top_of_body() {
        if ($this->is_done() || $this->when <= page_requirements_manager::WHEN_TOP_OF_BODY) {
            return;
        }

        if ($this->manager->is_top_of_body_done()) {
            throw new coding_exception('Too late to ask for a YUI library to be linked to from the top of &lt;body>.');
        }

        $this->when = page_requirements_manager::WHEN_TOP_OF_BODY;
        foreach ($this->jss as $requiredjs) {
            $output .= $requiredjs->at_top_of_body();
        }
    }
}


/**
 * A subclass of {@link linked_requirement} to represent a required CSS file.
 * Of course, all links to CSS files must go in the <head> section of the HTML.
 *
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since Moodle 2.0
 */
class required_css extends linked_requirement {
    /**
     * Constructor. Normally instances of this class should not be created directly.
     * Client code should create them via the page_requirements_manager
     * method {@link page_requirements_manager::css()}
     *
     * @param page_requirements_manager $manager the page_requirements_manager we are associated with.
     * @param string $url The URL of the CSS file we are linking to.
     */
    public function __construct(page_requirements_manager $manager, $url) {
        parent::__construct($manager, $url);
        $this->when = page_requirements_manager::WHEN_IN_HEAD;
    }

    public function get_html() {
        return '<link rel="stylesheet" type="text/css" href="' . $this->url . '" />' . "\n";;
    }
}


/**
 * A subclass of {@link linked_requirement} to represent a skip link.
 * A skip link is a concept from accessibility. You have some links like
 * 'Skip to main content' linking to an #maincontent anchor, at the start of the
 * <body> tag, so that users using assistive technologies like screen readers
 * can easily get to the main content without having to work their way through
 * any navigation, blocks, etc. that comes before it in the HTML.
 *
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since Moodle 2.0
 */
class required_skip_link extends linked_requirement {
    protected $linktext;

    /**
     * Constructor. Normally instances of this class should not be created directly.
     * Client code should create them via the page_requirements_manager
     * method {@link page_requirements_manager::yui_lib()}.
     *
     * @param page_requirements_manager $manager the page_requirements_manager we are associated with.
     * @param string $target the name of the anchor in the page we are linking to.
     * @param string $linktext the test to use for the link.
     */
    public function __construct(page_requirements_manager $manager, $target, $linktext) {
        parent::__construct($manager, $target);
        $this->when = page_requirements_manager::WHEN_TOP_OF_BODY;
        $this->linktext = $linktext;
    }

    public function get_html() {
        return '<a class="skip" href="#' . $this->url . '">' . $this->linktext . "</a>\n";
    }
}


/**
 * This is the base class for requirements that are JavaScript code.
 *
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since Moodle 2.0
 */
abstract class required_js_code extends requirement_base {

    /**
     * Constructor.
     * @param page_requirements_manager $manager the page_requirements_manager we are associated with.
     */
    protected function __construct(page_requirements_manager $manager) {
        parent::__construct($manager);
        $this->when = page_requirements_manager::WHEN_AT_END;
    }

    /**
     * @return string the JavaScript code needed to satisfy this requirement.
     */
    abstract public function get_js_code();

   /**
     * Indicate that the link to this JavaScript file should be output as soon as
     * possible. That is, if this requirement has already been output, this method
     * does nothing. Otherwise, if the <head> tag has not yet been printed, the link
     * to this script will be put in <head>. Otherwise, this method returns a
     * fragment of HTML that the caller is responsible for outputting as soon as
     * possible. In fact, it is recommended that you only call this function from
     * an echo statement, like:
     * <pre>
     *     echo $PAGE->requires->js(...)->asap();
     * </pre>
     *
     * @return string The HTML required to include this JavaScript file. The caller
     * is responsible for outputting this HTML promptly.
     */
    public function asap() {
        if ($this->is_done()) {
            return;
        }
        if (!$this->manager->is_head_done()) {
            $this->in_head();
            return '';
        }
        $js = $this->get_js_code();
        $output = ajax_generate_script_tag($js);
        $this->mark_done();
        return $output;
    }

    /**
     * Indicate that the link to this JavaScript file should be output in the
     * <head> section of the HTML. If it too late for this request to be
     * satisfied, an exception is thrown.
     */
    public function in_head() {
        if ($this->is_done() || $this->when <= page_requirements_manager::WHEN_IN_HEAD) {
            return;
        }
        if ($this->manager->is_head_done()) {
            throw new coding_exception('Too late to ask for some JavaScript code to be output in &lt;head>.');
        }
        $this->when = page_requirements_manager::WHEN_IN_HEAD;
    }

    /**
     * Indicate that the link to this JavaScript file should be output at the top
     * of the <body> section of the HTML. If it too late for this request to be
     * satisfied, an exception is thrown.
     */
    public function at_top_of_body() {
        if ($this->is_done() || $this->when <= page_requirements_manager::WHEN_TOP_OF_BODY) {
            return;
        }
        if ($this->manager->is_top_of_body_done()) {
            throw new coding_exception('Too late to ask for some JavaScript code to be output at the top of &lt;body>.');
        }
        $this->when = page_requirements_manager::WHEN_TOP_OF_BODY;
    }
}


/**
 * This class represents a JavaScript function that must be called from the HTML
 * page. By default the call will be made at the end of the page, but you can
 * chage that using the {@link asap()}, {@link in_head()}, etc. methods.
 *
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since Moodle 2.0
 */
class required_js_function_call extends required_js_code {
    protected $function;
    protected $arguments;
    protected $delay = 0;

    /**
     * Constructor. Normally instances of this class should not be created directly.
     * Client code should create them via the page_requirements_manager
     * method {@link page_requirements_manager::js_function_call()}.
     *
     * @param page_requirements_manager $manager the page_requirements_manager we are associated with.
     * @param string $function the name of the JavaScritp function to call.
     *      Can be a compound name like 'YAHOO.util.Event.addListener'.
     * @param array $arguments and array of arguments to be passed to the function.
     *      When generating the function call, this will be escaped using json_encode,
     *      so passing objects and arrays should work.
     */
    public function __construct(page_requirements_manager $manager, $function, $arguments) {
        parent::__construct($manager);
        $this->function = $function;
        $this->arguments = $arguments;
    }

    public function get_js_code() {
        $quotedargs = array();
        foreach ($this->arguments as $arg) {
            $quotedargs[] = json_encode($arg);
        }
        $js = $this->function . '(' . implode(', ', $quotedargs) . ');';
        if ($this->delay) {
            $js = 'setTimeout(function() { ' . $js . ' }, ' . ($this->delay * 1000) . ');';
        }
        return $js . "\n";
    }

    /**
     * Indicate that this function should be called in YUI's onDomReady event.
     *
     * Not that this is probably not necessary most of the time. Just having the
     * function call at the end of the HTML should normally be sufficient.
     */
    public function on_dom_ready() {
        if ($this->is_done() || $this->when < page_requirements_manager::WHEN_AT_END) {
            return;
        }
        $this->manager->yui_lib('event');
        $this->when = page_requirements_manager::WHEN_ON_DOM_READY;
    }

    /**
     * Indicate that this function should be called a certain number of seconds
     * after the page has finished loading. (More exactly, a number of seconds
     * after the onDomReady event fires.)
     *
     * @param integer $seconds the number of seconds delay.
     */
    public function after_delay($seconds) {
        if ($seconds) {
            $this->on_dom_ready();
        }
        $this->delay = $seconds;
    }
}


/**
 * This class represents some data from PHP that needs to be made available in a
 * global JavaScript variable. By default the data will be output at the end of
 * the page, but you can chage that using the {@link asap()}, {@link in_head()}, etc. methods.
 *
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since Moodle 2.0
 */
class required_data_for_js extends required_js_code {
    protected $variable;
    protected $data;

    /**
     * Constructor. Normally the class and its subclasses should not be created directly.
     * Client code should create them via the page_requirements_manager
     * method {@link page_requirements_manager::data_for_js()}.
     *
     * @param page_requirements_manager $manager the page_requirements_manager we are associated with.
     * @param string $variable the the name of the JavaScript variable to assign the data to.
     *      Will probably work if you use a compound name like 'mybuttons.button[1]', but this
     *      should be considered an experimental feature.
     * @param mixed $data The data to pass to JavaScript. This will be escaped using json_encode,
     *      so passing objects and arrays should work.
     */
    public function __construct(page_requirements_manager $manager, $variable, $data) {
        parent::__construct($manager);
        $this->variable = $variable;
        $this->data = json_encode($data);
        // json_encode immediately, so that if $data is an object (and therefore was
        // passed in by reference) we get the data at the time the call was made, and
        // not whatever the data happened to be when this is output.
    }

    public function get_js_code() {
        $prefix = 'var ';
        if (strpos($this->variable, '.') || strpos($this->variable, '[')) {
            $prefix = '';
        }
        return $prefix . $this->variable . ' = ' . $this->data . ";\n";
    }
}


/**
 * Generate a script tag containing the the specified code.
 *
 * @param string $js the JavaScript code
 * @return string HTML, the code wrapped in <script> tags.
 */
function ajax_generate_script_tag($js) {
    if ($js) {
        return '<script type="text/javascript">' . "\n//<![CDATA[\n" .
                $js . "//]]>\n</script>\n";
    } else {
        return '';
    }
}


/**
 * Given the name of a YUI library, return a list of the .js and .css files that
 * it requries.
 *
 * This method takes note of the $CFG->useexternalyui setting.
 *
 * If $CFG->debug is set to DEBUG_DEVELOPER then this method will return links to
 * the -debug version of the YUI files, otherwise it will return links to the -min versions.
 *
 * @param string $libname the name of a YUI library, for example 'autocomplete'.
 * @return array with two elementes. The first is an array of the JavaScript URLs
 *      that must be loaded to make this library work, in the order they should be
 *      loaded. The second element is a (possibly empty) list of CSS files that
 *      need to be loaded.
 */
function ajax_resolve_yui_lib($libname) {
    global $CFG;

    // Note, we always use yahoo-dom-event, even if we are only asked for part of it.
    // because another part of the code may later ask for other bits. It is easier, and
    // not very inefficient, just to always use (and get browsers to cache) the combined file.
    static $translatelist = array(
        'yahoo' => 'yahoo-dom-event',
        'animation' => array('yahoo-dom-event', 'animation'),
        'autocomplete' => array(
                'js' => array('yahoo-dom-event', 'datasource', 'autocomplete'),
                'css' => array('autocomplete/assets/skins/sam/autocomplete.css')),
        'button' => array(
                'js' => array('yahoo-dom-event', 'element', 'button'),
                'css' => array('button/assets/skins/sam/button.css')),
        'calendar' => array(
                'js' => array('yahoo-dom-event', 'calendar'),
                'css' => array('calendar/assets/skins/sam/calendar.css')),
        'carousel' => array(
                'js' => array('yahoo-dom-event', 'element', 'carousel'),
                'css' => array('carousel/assets/skins/sam/carousel.css')),
        'charts' => array('yahoo-dom-event', 'element', 'datasource', 'json', 'charts'),
        'colorpicker' => array(
                'js' => array('utilities', 'slider', 'colorpicker'),
                'css' => array('colorpicker/assets/skins/sam/colorpicker.css')),
        'connection' => array('yahoo-dom-event', 'connection'),
        'container' => array(
                'js' => array('yahoo-dom-event', 'container'),
                'css' => array('container/assets/skins/sam/container.css')),
        'cookie' => array('yahoo-dom-event', 'cookie'),
        'datasource' => array('yahoo-dom-event', 'datasource'),
        'datatable' => array(
                'js' => array('yahoo-dom-event', 'element', 'datasource', 'datatable'),
                'css' => array('datatable/assets/skins/sam/datatable.css')),
        'dom' => 'yahoo-dom-event',
        'dom-event' => 'yahoo-dom-event',
        'dragdrop' => array('yahoo-dom-event', 'dragdrop'),
        'editor' => array(
                'js' => array('yahoo-dom-event', 'element', 'container', 'menu', 'button', 'editor'),
                'css' => array('assets/skins/sam/skin.css')),
        'element' => array('yahoo-dom-event', 'element'),
        'event' => 'yahoo-dom-event',
        'get' => array('yahoo-dom-event', 'get'),
        'history' => array('yahoo-dom-event', 'history'),
        'imagecropper' => array(
                'js' => array('yahoo-dom-event', 'dragdrop', 'element', 'resize', 'imagecropper'),
                'css' => array('assets/skins/sam/resize.css', 'assets/skins/sam/imagecropper.css')),
        'imageloader' => array('yahoo-dom-event', 'imageloader'),
        'json' => array('yahoo-dom-event', 'json'),
        'layout' => array(
                'js' => array('yahoo-dom-event', 'dragdrop', 'element', 'layout'),
                'css' => array('reset-fonts-grids/reset-fonts-grids.css', 'assets/skins/sam/layout.css')),
        'logger' => array(
                'js' => array('yahoo-dom-event', 'logger'),
                'css' => array('logger/assets/skins/sam/logger.css')),
        'menu' => array(
                'js' => array('yahoo-dom-event', 'container', 'menu'),
                'css' => array('menu/assets/skins/sam/menu.css')),
        'paginator' => array(
                'js' => array('yahoo-dom-event', 'element', 'paginator'),
                'css' => array('paginator/assets/skins/sam/paginator.css')),
        'profiler' => array('yahoo-dom-event', 'profiler'),
        'profilerviewer' => array('yuiloader-dom-event', 'element', 'profiler', 'profilerviewer'),
        'resize' => array(
                'js' => array('yahoo-dom-event', 'dragdrop', 'element', 'resize'),
                'css' => array('assets/skins/sam/resize.css')),
        'selector' => array('yahoo-dom-event', 'selector'),
        'simpleeditor' => array(
                'js' => array('yahoo-dom-event', 'element', 'container', 'simpleeditor'),
                'css' => array('assets/skins/sam/skin.css')),
        'slider' => array('yahoo-dom-event', 'gragdrop', 'slider'),
        'stylesheet' => array('yahoo-dom-event', 'stylesheet'),
        'tabview' => array(
                'js' => array('yahoo-dom-event', 'element', 'tabview'),
                'css' => array('assets/skins/sam/skin.css')),
        'treeview' => array(
                'js' => array('yahoo-dom-event', 'treeview'),
                'css' => array('treeview/assets/skins/sam/treeview.css')),
        'uploader' => array('yahoo-dom-event', 'element', 'uploader'),
        'utilities' => array('yahoo-dom-event', 'connection', 'animation', 'dragdrop', 'element', 'get'),
        'yuiloader' => 'yuiloader',
        'yuitest' => array(
                'js' => array('yahoo-dom-event', 'logger', 'yuitest'),
                'css' => array('logger/assets/logger.css', 'yuitest/assets/testlogger.css')),
    );
    if (!isset($translatelist[$libname])) {
        throw new coding_exception('Unknown YUI library ' . $libname);
    }

    $data = $translatelist[$libname];
    if (!is_array($data)) {
        $jsnames = array($data);
        $cssfiles = array();
    } else if (isset($data['js']) && isset($data['css'])) {
        $jsnames = $data['js'];
        $cssfiles = $data['css'];
    } else {
        $jsnames = $data;
        $cssfiles = array();
    }

    $debugging = debugging('', DEBUG_DEVELOPER);
    if ($debugging) {
        $suffix = '-debug.js';
    } else {
        $suffix = '-min.js';
    }
    $libpath = $CFG->httpswwwroot . '/lib/yui/';

    $externalyui = !empty($CFG->useexternalyui);
    if ($externalyui) {
        include($CFG->libdir.'/yui/version.php'); // Sets $yuiversion.
        $libpath = 'http://yui.yahooapis.com/' . $yuiversion . '/build/';
    }

    $jsurls = array();
    foreach ($jsnames as $js) {
        if ($js == 'yahoo-dom-event') {
            if ($debugging) {
                $jsurls[] = $libpath . 'yahoo/yahoo' . $suffix;
                $jsurls[] = $libpath . 'dom/dom' . $suffix;
                $jsurls[] = $libpath . 'event/event' . $suffix;
            } else {
                $jsurls[] = $libpath . $js . '/' . $js . '.js';
            }
        } else {
            $jsurls[] = $libpath . $js . '/' . $js . $suffix;
        }
    }

    $cssurls = array();
    foreach ($cssfiles as $css) {
        $cssurls[] = $libpath . $css;
    }

    return array($jsurls, $cssurls);
}

/**
 * Return the HTML required to link to a JavaScript file.
 * @param $url the URL of a JavaScript file.
 * @return string the required HTML.
 */
function ajax_get_link_to_script($url) {
    return '<script type="text/javascript"  src="' . $url . '"></script>' . "\n";
}


/**
 * Returns whether ajax is enabled/allowed or not.
 */
function ajaxenabled($browsers = array()) {

    global $CFG, $USER;

    if (!empty($browsers)) {
        $valid = false;
        foreach ($browsers as $brand => $version) {
            if (check_browser_version($brand, $version)) {
                $valid = true;
            }
        }

        if (!$valid) {
            return false;
        }
    }

    $ie = check_browser_version('MSIE', 6.0);
    $ff = check_browser_version('Gecko', 20051106);
    $op = check_browser_version('Opera', 9.0);
    $sa = check_browser_version('Safari', 412);

    if (!$ie && !$ff && !$op && !$sa) {
        /** @see http://en.wikipedia.org/wiki/User_agent */
        // Gecko build 20051107 is what is in Firefox 1.5.
        // We still have issues with AJAX in other browsers.
        return false;
    }

    if (!empty($CFG->enableajax) && (!empty($USER->ajax) || !isloggedin())) {
        return true;
    } else {
        return false;
    }
}


/**
 * Used to create view of document to be passed to JavaScript on pageload.
 * We use this class to pass data from PHP to JavaScript.
 */
class jsportal {

    var $currentblocksection = null;
    var $blocks = array();


    /**
     * Takes id of block and adds it
     */
    function block_add($id, $hidden=false){
        $hidden_binary = 0;

        if ($hidden) {
            $hidden_binary = 1;
        }
        $this->blocks[count($this->blocks)] = array($this->currentblocksection, $id, $hidden_binary);
    }


    /**
     * Prints the JavaScript code needed to set up AJAX for the course.
     */
    function print_javascript($courseid, $return=false) {
        global $CFG, $USER;

        $blocksoutput = $output = '';
        for ($i=0; $i<count($this->blocks); $i++) {
            $blocksoutput .= "['".$this->blocks[$i][0]."',
                             '".$this->blocks[$i][1]."',
                             '".$this->blocks[$i][2]."']";

            if ($i != (count($this->blocks) - 1)) {
                $blocksoutput .= ',';
            }
        }
        $output .= "<script type=\"text/javascript\">\n";
        $output .= "    main.portal.id = ".$courseid.";\n";
        $output .= "    main.portal.blocks = new Array(".$blocksoutput.");\n";
        $output .= "    main.portal.strings['wwwroot']='".$CFG->wwwroot."';\n";
        $output .= "    main.portal.strings['marker']='".get_string('markthistopic', '', '_var_')."';\n";
        $output .= "    main.portal.strings['marked']='".get_string('markedthistopic', '', '_var_')."';\n";
        $output .= "    main.portal.strings['hide']='".get_string('hide')."';\n";
        $output .= "    main.portal.strings['hidesection']='".get_string('hidesection', '', '_var_')."';\n";
        $output .= "    main.portal.strings['show']='".get_string('show')."';\n";
        $output .= "    main.portal.strings['delete']='".get_string('delete')."';\n";
        $output .= "    main.portal.strings['move']='".get_string('move')."';\n";
        $output .= "    main.portal.strings['movesection']='".get_string('movesection', '', '_var_')."';\n";
        $output .= "    main.portal.strings['moveleft']='".get_string('moveleft')."';\n";
        $output .= "    main.portal.strings['moveright']='".get_string('moveright')."';\n";
        $output .= "    main.portal.strings['update']='".get_string('update')."';\n";
        $output .= "    main.portal.strings['groupsnone']='".get_string('groupsnone')."';\n";
        $output .= "    main.portal.strings['groupsseparate']='".get_string('groupsseparate')."';\n";
        $output .= "    main.portal.strings['groupsvisible']='".get_string('groupsvisible')."';\n";
        $output .= "    main.portal.strings['clicktochange']='".get_string('clicktochange')."';\n";
        $output .= "    main.portal.strings['deletecheck']='".get_string('deletecheck','','_var_')."';\n";
        $output .= "    main.portal.strings['resource']='".get_string('resource')."';\n";
        $output .= "    main.portal.strings['activity']='".get_string('activity')."';\n";
        $output .= "    main.portal.strings['sesskey']='".sesskey()."';\n";
        $output .= "    main.portal.icons['spacerimg']='".$OUTPUT->old_icon_url('spaces')."';\n";
        $output .= "    main.portal.icons['marker']='".$OUTPUT->old_icon_url('i/marker')."';\n";
        $output .= "    main.portal.icons['ihide']='".$OUTPUT->old_icon_url('i/hide')."';\n";
        $output .= "    main.portal.icons['move_2d']='".$OUTPUT->old_icon_url('i/move_2d')."';\n";
        $output .= "    main.portal.icons['show']='".$OUTPUT->old_icon_url('t/show')."';\n";
        $output .= "    main.portal.icons['hide']='".$OUTPUT->old_icon_url('t/hide')."';\n";
        $output .= "    main.portal.icons['delete']='".$OUTPUT->old_icon_url('t/delete')."';\n";
        $output .= "    main.portal.icons['groupn']='".$OUTPUT->old_icon_url('t/groupn')."';\n";
        $output .= "    main.portal.icons['groups']='".$OUTPUT->old_icon_url('t/groups')."';\n";
        $output .= "    main.portal.icons['groupv']='".$OUTPUT->old_icon_url('t/groupv')."';\n";
        if (right_to_left()) {
            $output .= "    main.portal.icons['backwards']='".$OUTPUT->old_icon_url('t/right')."';\n";
            $output .= "    main.portal.icons['forwards']='".$OUTPUT->old_icon_url('t/left')."';\n";
        } else {
            $output .= "    main.portal.icons['backwards']='".$OUTPUT->old_icon_url('t/left')."';\n";
            $output .= "    main.portal.icons['forwards']='".$OUTPUT->old_icon_url('t/right')."';\n";
        }

        $output .= "    onloadobj.load();\n";
        $output .= "    main.process_blocks();\n";
        $output .= "</script>";
        if ($return) {
            return $output;
        } else {
            echo $output;
        }
    }

}

?>
