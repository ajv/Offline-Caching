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
 * Classes for rendering HTML output for Moodle.
 *
 * Please see http://docs.moodle.org/en/Developement:How_Moodle_outputs_HTML
 * for an overview.
 *
 * @package   moodlecore
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Simple base class for Moodle renderers.
 *
 * Tracks the xhtml_container_stack to use, which is passed in in the constructor.
 *
 * Also has methods to facilitate generating HTML output.
 *
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since     Moodle 2.0
 */
class moodle_renderer_base {
    /** @var xhtml_container_stack the xhtml_container_stack to use. */
    protected $opencontainers;
    /** @var moodle_page the page we are rendering for. */
    protected $page;

    /**
     * Constructor
     * @param moodle_page $page the page we are doing output for.
     */
    public function __construct($page) {
        $this->opencontainers = $page->opencontainers;
        $this->page = $page;
    }

    /**
     * Have we started output yet?
     * @return boolean true if the header has been printed.
     */
    public function has_started() {
        return $this->page->state >= moodle_page::STATE_IN_BODY;
    }

    /**
     * Outputs a tag with attributes and contents
     * @param string $tagname The name of tag ('a', 'img', 'span' etc.)
     * @param array $attributes The tag attributes (array('src' => $url, 'class' => 'class1') etc.)
     * @param string $contents What goes between the opening and closing tags
     * @return string HTML fragment
     */
    protected function output_tag($tagname, $attributes, $contents) {
        return $this->output_start_tag($tagname, $attributes) . $contents .
                $this->output_end_tag($tagname);
    }

    /**
     * Outputs an opening tag with attributes
     * @param string $tagname The name of tag ('a', 'img', 'span' etc.)
     * @param array $attributes The tag attributes (array('src' => $url, 'class' => 'class1') etc.)
     * @return string HTML fragment
     */
    protected function output_start_tag($tagname, $attributes) {
        return '<' . $tagname . $this->output_attributes($attributes) . '>';
    }

    /**
     * Outputs a closing tag
     * @param string $tagname The name of tag ('a', 'img', 'span' etc.)
     * @return string HTML fragment
     */
    protected function output_end_tag($tagname) {
        return '</' . $tagname . '>';
    }

    /**
     * Outputs an empty tag with attributes
     * @param string $tagname The name of tag ('input', 'img', 'br' etc.)
     * @param array $attributes The tag attributes (array('src' => $url, 'class' => 'class1') etc.)
     * @return string HTML fragment
     */
    protected function output_empty_tag($tagname, $attributes) {
        return '<' . $tagname . $this->output_attributes($attributes) . ' />';
    }

    /**
     * Outputs a HTML attribute and value
     * @param string $name The name of the attribute ('src', 'href', 'class' etc.)
     * @param string $value The value of the attribute. The value will be escaped with {@link s()}
     * @return string HTML fragment
     */
    protected function output_attribute($name, $value) {
        if (is_array($value)) {
            debugging("Passed an array for the HTML attribute $name", DEBUG_DEVELOPER);
        }

        $value = trim($value);
        if ($value == HTML_ATTR_EMPTY) {
            return ' ' . $name . '=""';
        } else if ($value || is_numeric($value)) { // We want 0 to be output.
            return ' ' . $name . '="' . s($value) . '"';
        }
    }

    /**
     * Outputs a list of HTML attributes and values
     * @param array $attributes The tag attributes (array('src' => $url, 'class' => 'class1') etc.)
     *       The values will be escaped with {@link s()}
     * @return string HTML fragment
     */
    protected function output_attributes($attributes) {
        if (empty($attributes)) {
            $attributes = array();
        }
        $output = '';
        foreach ($attributes as $name => $value) {
            $output .= $this->output_attribute($name, $value);
        }
        return $output;
    }

    /**
     * Given an array or space-separated list of classes, prepares and returns the HTML class attribute value
     * @param mixed $classes Space-separated string or array of classes
     * @return string HTML class attribute value
     */
    public static function prepare_classes($classes) {
        if (is_array($classes)) {
            return implode(' ', array_unique($classes));
        }
        return $classes;
    }

    /**
     * Return the URL for an icon identified as in pre-Moodle 2.0 code.
     *
     * Suppose you have old code like $url = "$CFG->pixpath/i/course.gif";
     * then old_icon_url('i/course'); will return the equivalent URL that is correct now.
     *
     * @param string $iconname the name of the icon.
     * @return string the URL for that icon.
     */
    public function old_icon_url($iconname) {
        return $this->page->theme->old_icon_url($iconname);
    }

    /**
     * Return the URL for an icon identified as in pre-Moodle 2.0 code.
     *
     * Suppose you have old code like $url = "$CFG->modpixpath/$mod/icon.gif";
     * then mod_icon_url('icon', $mod); will return the equivalent URL that is correct now.
     *
     * @param string $iconname the name of the icon.
     * @param string $module the module the icon belongs to.
     * @return string the URL for that icon.
     */
    public function mod_icon_url($iconname, $module) {
        return $this->page->theme->mod_icon_url($iconname, $module);
    }

    /**
     * A helper function that takes a moodle_html_component subclass as param.
     * If that component has an id attribute and an array of valid component_action objects,
     * it sets up the appropriate event handlers.
     *
     * @param moodle_html_component $component
     * @return void;
     */
    protected function prepare_event_handlers(&$component) {
        $actions = $component->get_actions();
        if (!empty($actions) && is_array($actions) && $actions[0] instanceof component_action) {
            foreach ($actions as $action) {
                if (!empty($action->jsfunction)) {
                    $this->page->requires->event_handler($component->id, $action->event, $action->jsfunction, $action->jsfunctionargs);
                }
            }
        }
    }

    /**
     * Given a moodle_html_component with height and/or width set, translates them
     * to appropriate CSS rules.
     *
     * @param moodle_html_component $component
     * @return string CSS rules
     */
    protected function prepare_legacy_width_and_height($component) {
        $output = '';
        if (!empty($component->height)) {
            // We need a more intelligent way to handle these warnings. If $component->height have come from
            // somewhere in deprecatedlib.php, then there is no point outputting a warning here.
            // debugging('Explicit height given to moodle_html_component leads to inline css. Use a proper CSS class instead.', DEBUG_DEVELOPER);
            $output .= "height: {$component->height}px;";
        }
        if (!empty($component->width)) {
            // debugging('Explicit width given to moodle_html_component leads to inline css. Use a proper CSS class instead.', DEBUG_DEVELOPER);
            $output .= "width: {$component->width}px;";
        }
        return $output;
    }
}


/**
 * This is the templated renderer which copies the API of another class, replacing
 * all methods calls with instantiation of a template.
 *
 * When the method method_name is called, this class will search for a template
 * called method_name.php in the folders in $searchpaths, taking the first one
 * that it finds. Then it will set up variables for each of the arguments of that
 * method, and render the template. This is implemented in the {@link __call()}
 * PHP magic method.
 *
 * Methods like print_box_start and print_box_end are handles specially, and
 * implemented in terms of the print_box.php method.
 *
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since     Moodle 2.0
 */
class template_renderer extends moodle_renderer_base {
    /** @var ReflectionClass information about the class whose API we are copying. */
    protected $copiedclass;
    /** @var array of places to search for templates. */
    protected $searchpaths;
    protected $rendererfactory;

    /**
     * Magic word used when breaking apart container templates to implement
     * _start and _end methods.
     */
    const CONTENTSTOKEN = '-@#-Contents-go-here-#@-';

    /**
     * Constructor
     * @param string $copiedclass the name of a class whose API we should be copying.
     * @param array $searchpaths a list of folders to search for templates in.
     * @param moodle_page $page the page we are doing output for.
     */
    public function __construct($copiedclass, $searchpaths, $page) {
        parent::__construct($page);
        $this->copiedclass = new ReflectionClass($copiedclass);
        $this->searchpaths = $searchpaths;
    }

    /**
     * PHP magic method implementation. Do not use this method directly.
     * @param string $method The method to call
     * @param array $arguments The arguments to pass to the method
     * @return mixed The return value of the called method
     */
    public function __call($method, $arguments) {
        if (substr($method, -6) == '_start') {
            return $this->process_start(substr($method, 0, -6), $arguments);
        } else if (substr($method, -4) == '_end') {
            return $this->process_end(substr($method, 0, -4), $arguments);
        } else {
            return $this->process_template($method, $arguments);
        }
    }

    /**
     * Render the template for a given method of the renderer class we are copying,
     * using the arguments passed.
     * @param string $method the method that was called.
     * @param array $arguments the arguments that were passed to it.
     * @return string the HTML to be output.
     */
    protected function process_template($method, $arguments) {
        if (!$this->copiedclass->hasMethod($method) ||
                !$this->copiedclass->getMethod($method)->isPublic()) {
            throw new coding_exception('Unknown method ' . $method);
        }

        // Find the template file for this method.
        $template = $this->find_template($method);

        // Use the reflection API to find out what variable names the arguments
        // should be stored in, and fill in any missing ones with the defaults.
        $namedarguments = array();
        $expectedparams = $this->copiedclass->getMethod($method)->getParameters();
        foreach ($expectedparams as $param) {
            $paramname = $param->getName();
            if (!empty($arguments)) {
                $namedarguments[$paramname] = array_shift($arguments);
            } else if ($param->isDefaultValueAvailable()) {
                $namedarguments[$paramname] = $param->getDefaultValue();
            } else {
                throw new coding_exception('Missing required argument ' . $paramname);
            }
        }

        // Actually render the template.
        return $this->render_template($template, $namedarguments);
    }

    /**
     * Actually do the work of rendering the template.
     * @param string $_template the full path to the template file.
     * @param array $_namedarguments an array variable name => value, the variables
     *      that should be available to the template.
     * @return string the HTML to be output.
     */
    protected function render_template($_template, $_namedarguments) {
        // Note, we intentionally break the coding guidelines with regards to
        // local variable names used in this function, so that they do not clash
        // with the names of any variables being passed to the template.

        global $CFG, $SITE, $THEME, $USER;
        // The next lines are a bit tricky. The point is, here we are in a method
        // of a renderer class, and this object may, or may not, be the same as
        // the global $OUTPUT object. When rendering the template, we want to use
        // this object. However, people writing Moodle code expect the current
        // renderer to be called $OUTPUT, not $this, so define a variable called
        // $OUTPUT pointing at $this. The same comment applies to $PAGE and $COURSE.
        $OUTPUT = $this;
        $PAGE = $this->page;
        $COURSE = $this->page->course;

        // And the parameters from the function call.
        extract($_namedarguments);

        // Include the template, capturing the output.
        ob_start();
        include($_template);
        $_result = ob_get_contents();
        ob_end_clean();

        return $_result;
    }

    /**
     * Searches the folders in {@link $searchpaths} to try to find a template for
     * this method name. Throws an exception if one cannot be found.
     * @param string $method the method name.
     * @return string the full path of the template to use.
     */
    protected function find_template($method) {
        foreach ($this->searchpaths as $path) {
            $filename = $path . '/' . $method . '.php';
            if (file_exists($filename)) {
                return $filename;
            }
        }
        throw new coding_exception('Cannot find template for ' . $this->copiedclass->getName() . '::' . $method);
    }

    /**
     * Handle methods like print_box_start by using the print_box template,
     * splitting the result, pushing the end onto the stack, then returning the start.
     * @param string $method the method that was called, with _start stripped off.
     * @param array $arguments the arguments that were passed to it.
     * @return string the HTML to be output.
     */
    protected function process_start($method, $arguments) {
        array_unshift($arguments, self::CONTENTSTOKEN);
        $html = $this->process_template($method, $arguments);
        list($start, $end) = explode(self::CONTENTSTOKEN, $html, 2);
        $this->opencontainers->push($method, $end);
        return $start;
    }

    /**
     * Handle methods like print_box_end, we just need to pop the end HTML from
     * the stack.
     * @param string $method the method that was called, with _end stripped off.
     * @param array $arguments not used. Assumed to be irrelevant.
     * @return string the HTML to be output.
     */
    protected function process_end($method, $arguments) {
        return $this->opencontainers->pop($method);
    }

    /**
     * @return array the list of paths where this class searches for templates.
     */
    public function get_search_paths() {
        return $this->searchpaths;
    }

    /**
     * @return string the name of the class whose API we are copying.
     */
    public function get_copied_class() {
        return $this->copiedclass->getName();
    }
}

/**
 * The standard implementation of the moodle_core_renderer interface.
 *
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since     Moodle 2.0
 */
class moodle_core_renderer extends moodle_renderer_base {
    /** @var string used in {@link header()}. */
    const PERFORMANCE_INFO_TOKEN = '%%PERFORMANCEINFO%%';
    /** @var string used in {@link header()}. */
    const END_HTML_TOKEN = '%%ENDHTML%%';
    /** @var string used in {@link header()}. */
    const MAIN_CONTENT_TOKEN = '[MAIN CONTENT GOES HERE]';
    /** @var string used to pass information from {@link doctype()} to {@link standard_head_html()}. */
    protected $contenttype;
    /** @var string used by {@link redirect_message()} method to communicate with {@link header()}. */
    protected $metarefreshtag = '';

    /**
     * Get the DOCTYPE declaration that should be used with this page. Designed to
     * be called in theme layout.php files.
     * @return string the DOCTYPE declaration (and any XML prologue) that should be used.
     */
    public function doctype() {
        global $CFG;

        $doctype = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">' . "\n";
        $this->contenttype = 'text/html; charset=utf-8';

        if (empty($CFG->xmlstrictheaders)) {
            return $doctype;
        }

        // We want to serve the page with an XML content type, to force well-formedness errors to be reported.
        $prolog = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
        if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/xhtml+xml') !== false) {
            // Firefox and other browsers that can cope natively with XHTML.
            $this->contenttype = 'application/xhtml+xml; charset=utf-8';

        } else if (preg_match('/MSIE.*Windows NT/', $_SERVER['HTTP_USER_AGENT'])) {
            // IE can't cope with application/xhtml+xml, but it will cope if we send application/xml with an XSL stylesheet.
            $this->contenttype = 'application/xml; charset=utf-8';
            $prolog .= '<?xml-stylesheet type="text/xsl" href="' . $CFG->httpswwwroot . '/lib/xhtml.xsl"?>' . "\n";

        } else {
            $prolog = '';
        }

        return $prolog . $doctype;
    }

    /**
     * The attributes that should be added to the <html> tag. Designed to
     * be called in theme layout.php files.
     * @return string HTML fragment.
     */
    public function htmlattributes() {
        return get_html_lang(true) . ' xmlns="http://www.w3.org/1999/xhtml"';
    }

    /**
     * The standard tags (meta tags, links to stylesheets and JavaScript, etc.)
     * that should be included in the <head> tag. Designed to be called in theme
     * layout.php files.
     * @return string HTML fragment.
     */
    public function standard_head_html() {
        global $CFG;
        $output = '';
        $output .= '<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />' . "\n";
        $output .= '<meta name="keywords" content="moodle, ' . $this->page->title . '" />' . "\n";
        if (!$this->page->cacheable) {
            $output .= '<meta http-equiv="pragma" content="no-cache" />' . "\n";
            $output .= '<meta http-equiv="expires" content="0" />' . "\n";
        }
        // This is only set by the {@link redirect()} method
        $output .= $this->metarefreshtag;

        // Check if a periodic refresh delay has been set and make sure we arn't
        // already meta refreshing
        if ($this->metarefreshtag=='' && $this->page->periodicrefreshdelay!==null) {
            $output .= '<meta http-equiv="refresh" content="'.$this->page->periodicrefreshdelay.';url='.$this->page->url->out().'" />';
        }

        $this->page->requires->js('lib/javascript-static.js')->in_head();
        $this->page->requires->js('lib/javascript-deprecated.js')->in_head();
        $this->page->requires->js('lib/javascript-mod.php')->in_head();
        $this->page->requires->js('lib/overlib/overlib.js')->in_head();
        $this->page->requires->js('lib/overlib/overlib_cssstyle.js')->in_head();
        $this->page->requires->js('lib/cookies.js')->in_head();
        $this->page->requires->js_function_call('setTimeout', Array('fix_column_widths()', 20));

        $focus = $this->page->focuscontrol;
        if (!empty($focus)) {
            if (preg_match("#forms\['([a-zA-Z0-9]+)'\].elements\['([a-zA-Z0-9]+)'\]#", $focus, $matches)) {
                // This is a horrifically bad way to handle focus but it is passed in
                // through messy formslib::moodleform
                $this->page->requires->js_function_call('old_onload_focus', Array($matches[1], $matches[2]));
            } else if (strpos($focus, '.')!==false) {
                // Old style of focus, bad way to do it
                debugging('This code is using the old style focus event, Please update this code to focus on an element id or the moodleform focus method.', DEBUG_DEVELOPER);
                $this->page->requires->js_function_call('old_onload_focus', explode('.', $focus, 2));
            } else {
                // Focus element with given id
                $this->page->requires->js_function_call('focuscontrol', Array($focus));
            }
        }

        // Add the meta tags from the themes if any were requested.
        $output .= $this->page->theme->get_meta_tags($this->page);

        // Get any HTML from the page_requirements_manager.
        $output .= $this->page->requires->get_head_code();

        // List alternate versions.
        foreach ($this->page->alternateversions as $type => $alt) {
            $output .= $this->output_empty_tag('link', array('rel' => 'alternate',
                    'type' => $type, 'title' => $alt->title, 'href' => $alt->url));
        }

        return $output;
    }

    /**
     * The standard tags (typically skip links) that should be output just inside
     * the start of the <body> tag. Designed to be called in theme layout.php files.
     * @return string HTML fragment.
     */
    public function standard_top_of_body_html() {
        return  $this->page->requires->get_top_of_body_code();
    }

    /**
     * The standard tags (typically performance information and validation links,
     * if we are in developer debug mode) that should be output in the footer area
     * of the page. Designed to be called in theme layout.php files.
     * @return string HTML fragment.
     */
    public function standard_footer_html() {
        global $CFG;

        // This function is normally called from a layout.php file in {@link header()}
        // but some of the content won't be known until later, so we return a placeholder
        // for now. This will be replaced with the real content in {@link footer()}.
        $output = self::PERFORMANCE_INFO_TOKEN;
        if (!empty($CFG->debugpageinfo)) {
            $output .= '<div class="performanceinfo">This page is: ' . $this->page->debug_summary() . '</div>';
        }
        if (!empty($CFG->debugvalidators)) {
            $output .= '<div class="validators"><ul>
              <li><a href="http://validator.w3.org/check?verbose=1&amp;ss=1&amp;uri=' . urlencode(qualified_me()) . '">Validate HTML</a></li>
              <li><a href="http://www.contentquality.com/mynewtester/cynthia.exe?rptmode=-1&amp;url1=' . urlencode(qualified_me()) . '">Section 508 Check</a></li>
              <li><a href="http://www.contentquality.com/mynewtester/cynthia.exe?rptmode=0&amp;warnp2n3e=1&amp;url1=' . urlencode(qualified_me()) . '">WCAG 1 (2,3) Check</a></li>
            </ul></div>';
        }
        return $output;
    }

    /**
     * The standard tags (typically script tags that are not needed earlier) that
     * should be output after everything else, . Designed to be called in theme layout.php files.
     * @return string HTML fragment.
     */
    public function standard_end_of_body_html() {
        // This function is normally called from a layout.php file in {@link header()}
        // but some of the content won't be known until later, so we return a placeholder
        // for now. This will be replaced with the real content in {@link footer()}.
        echo self::END_HTML_TOKEN;
    }

    /**
     * Return the standard string that says whether you are logged in (and switched
     * roles/logged in as another user).
     * @return string HTML fragment.
     */
    public function login_info() {
        global $USER;
        return user_login_string($this->page->course, $USER);
    }

    /**
     * Return the 'back' link that normally appears in the footer.
     * @return string HTML fragment.
     */
    public function home_link() {
        global $CFG, $SITE;

        if ($this->page->pagetype == 'site-index') {
            // Special case for site home page - please do not remove
            return '<div class="sitelink">' .
                   '<a title="Moodle ' . $CFG->release . '" href="http://moodle.org/">' .
                   '<img style="width:100px;height:30px" src="' . $CFG->httpswwwroot . '/pix/moodlelogo.gif" alt="moodlelogo" /></a></div>';

        } else if (!empty($CFG->target_release) && $CFG->target_release != $CFG->release) {
            // Special case for during install/upgrade.
            return '<div class="sitelink">'.
                   '<a title="Moodle ' . $CFG->target_release . '" href="http://docs.moodle.org/en/Administrator_documentation" onclick="this.target=\'_blank\'">' .
                   '<img style="width:100px;height:30px" src="' . $CFG->httpswwwroot . '/pix/moodlelogo.gif" alt="moodlelogo" /></a></div>';

        } else if ($this->page->course->id == $SITE->id || strpos($this->page->pagetype, 'course-view') === 0) {
            return '<div class="homelink"><a href="' . $CFG->wwwroot . '/">' .
                    get_string('home') . '</a></div>';

        } else {
            return '<div class="homelink"><a href="' . $CFG->wwwroot . '/course/view.php?id=' . $this->page->course->id . '">' .
                    format_string($this->page->course->shortname) . '</a></div>';
        }
    }

    /**
     * Redirects the user by any means possible given the current state
     *
     * This function should not be called directly, it should always be called using
     * the redirect function in lib/weblib.php
     *
     * The redirect function should really only be called before page output has started
     * however it will allow itself to be called during the state STATE_IN_BODY
     *
     * @param string $encodedurl The URL to send to encoded if required
     * @param string $message The message to display to the user if any
     * @param int $delay The delay before redirecting a user, if $message has been
     *         set this is a requirement and defaults to 3, set to 0 no delay
     * @param boolean $debugdisableredirect this redirect has been disabled for
     *         debugging purposes. Display a message that explains, and don't
     *         trigger the redirect.
     * @return string The HTML to display to the user before dying, may contain
     *         meta refresh, javascript refresh, and may have set header redirects
     */
    public function redirect_message($encodedurl, $message, $delay, $debugdisableredirect) {
        global $CFG;
        $url = str_replace('&amp;', '&', $encodedurl);

        switch ($this->page->state) {
            case moodle_page::STATE_BEFORE_HEADER :
                // No output yet it is safe to delivery the full arsenal of redirect methods
                if (!$debugdisableredirect) {
                    // Don't use exactly the same time here, it can cause problems when both redirects fire at the same time.
                    $this->metarefreshtag = '<meta http-equiv="refresh" content="'. $delay .'; url='. $encodedurl .'" />'."\n";
                    $this->page->requires->js_function_call('document.location.replace', array($url))->after_delay($delay + 3);
                }
                $output = $this->header();
                break;
            case moodle_page::STATE_PRINTING_HEADER :
                // We should hopefully never get here
                throw new coding_exception('You cannot redirect while printing the page header');
                break;
            case moodle_page::STATE_IN_BODY :
                // We really shouldn't be here but we can deal with this
                debugging("You should really redirect before you start page output");
                if (!$debugdisableredirect) {
                    $this->page->requires->js_function_call('document.location.replace', array($url))->after_delay($delay);
                }
                $output = $this->opencontainers->pop_all_but_last();
                break;
            case moodle_page::STATE_DONE :
                // Too late to be calling redirect now
                throw new coding_exception('You cannot redirect after the entire page has been generated');
                break;
        }
        $output .= $this->notification($message, 'redirectmessage');
        $output .= '<a href="'. $encodedurl .'">'. get_string('continue') .'</a>';
        if ($debugdisableredirect) {
            $output .= '<p><strong>Error output, so disabling automatic redirect.</strong></p>';
        }
        $output .= $this->footer();
        return $output;
    }

    /**
     * Start output by sending the HTTP headers, and printing the HTML <head>
     * and the start of the <body>.
     *
     * To control what is printed, you should set properties on $PAGE. If you
     * are familiar with the old {@link print_header()} function from Moodle 1.9
     * you will find that there are properties on $PAGE that correspond to most
     * of the old parameters to could be passed to print_header.
     *
     * Not that, in due course, the remaining $navigation, $menu parameters here
     * will be replaced by more properties of $PAGE, but that is still to do.
     *
     * @param string $navigation legacy, like the old parameter to print_header. Will be
     *      removed when there is a $PAGE->... replacement.
     * @param string $menu legacy, like the old parameter to print_header. Will be
     *      removed when there is a $PAGE->... replacement.
     * @return string HTML that you must output this, preferably immediately.
     */
    public function header($navigation = '', $menu='') {
        // TODO remove $navigation and $menu arguments - replace with $PAGE->navigation
        global $USER, $CFG;

        $this->page->set_state(moodle_page::STATE_PRINTING_HEADER);

        // Find the appropriate page template, based on $this->page->generaltype.
        $templatefile = $this->page->theme->template_for_page($this->page->generaltype);
        if ($templatefile) {
            // Render the template.
            $template = $this->render_page_template($templatefile, $menu, $navigation);
        } else {
            // New style template not found, fall back to using header.html and footer.html.
            $template = $this->handle_legacy_theme($navigation, $menu);
        }

        // Slice the template output into header and footer.
        $cutpos = strpos($template, self::MAIN_CONTENT_TOKEN);
        if ($cutpos === false) {
            throw new coding_exception('Layout template ' . $templatefile .
                    ' does not contain the string "' . self::MAIN_CONTENT_TOKEN . '".');
        }
        $header = substr($template, 0, $cutpos);
        $footer = substr($template, $cutpos + strlen(self::MAIN_CONTENT_TOKEN));

        if (empty($this->contenttype)) {
            debugging('The layout template did not call $OUTPUT->doctype()');
            $this->doctype();
        }

        send_headers($this->contenttype, $this->page->cacheable);
        $this->opencontainers->push('header/footer', $footer);
        $this->page->set_state(moodle_page::STATE_IN_BODY);
        return $header . $this->skip_link_target();
    }

    /**
     * Renders and outputs the page template.
     * @param string $templatefile The name of the template's file
     * @param array $menu The menu that will be used in the included file
     * @param array $navigation The navigation that will be used in the included file
     * @return string HTML code
     */
    protected function render_page_template($templatefile, $menu, $navigation) {
        global $CFG, $SITE, $THEME, $USER;
        // The next lines are a bit tricky. The point is, here we are in a method
        // of a renderer class, and this object may, or may not, be the same as
        // the global $OUTPUT object. When rendering the template, we want to use
        // this object. However, people writing Moodle code expect the current
        // renderer to be called $OUTPUT, not $this, so define a variable called
        // $OUTPUT pointing at $this. The same comment applies to $PAGE and $COURSE.
        $OUTPUT = $this;
        $PAGE = $this->page;
        $COURSE = $this->page->course;

        ob_start();
        include($templatefile);
        $template = ob_get_contents();
        ob_end_clean();
        return $template;
    }

    /**
     * Renders and outputs a legacy template.
     * @param array $navigation The navigation that will be used in the included file
     * @param array $menu The menu that will be used in the included file
     * @return string HTML code
     */
    protected function handle_legacy_theme($navigation, $menu) {
        global $CFG, $SITE, $USER;
        // Set a pretend global from the properties of this class.
        // See the comment in render_page_template for a fuller explanation.
        $COURSE = $this->page->course;
        $THEME = $this->page->theme;

        // Set up local variables that header.html expects.
        $direction = $this->htmlattributes();
        $title = $this->page->title;
        $heading = $this->page->heading;
        $focus = $this->page->focuscontrol;
        $button = $this->page->button;
        $pageid = $this->page->pagetype;
        $pageclass = $this->page->bodyclasses;
        $bodytags = ' class="' . $pageclass . '" id="' . $pageid . '"';
        $home = $this->page->generaltype == 'home';

        $meta = $this->standard_head_html();
        // The next line is a nasty hack. having set $meta to standard_head_html, we have already
        // got the contents of include($CFG->javascript). However, legacy themes are going to
        // include($CFG->javascript) again. We want to make sure that when they do, nothing is output.
        $CFG->javascript = $CFG->libdir . '/emptyfile.php';

        // Set up local variables that footer.html expects.
        $homelink = $this->home_link();
        $loggedinas = $this->login_info();
        $course = $this->page->course;
        $performanceinfo = self::PERFORMANCE_INFO_TOKEN;

        if (!$menu && $navigation) {
            $menu = $loggedinas;
        }

        if (!empty($this->page->theme->layouttable)) {
            $lt = $this->page->theme->layouttable;
        } else {
            $lt = array('left', 'middle', 'right');
        }

        if (!empty($this->page->theme->block_l_max_width)) {
            $preferredwidthleft = $this->page->theme->block_l_max_width;
        } else {
            $preferredwidthleft = 210;
        }
        if (!empty($this->page->theme->block_r_max_width)) {
            $preferredwidthright = $this->page->theme->block_r_max_width;
        } else {
            $preferredwidthright = 210;
        }

        ob_start();
        include($this->page->theme->dir . '/header.html');

        echo '<table id="layout-table"><tr>';
        foreach ($lt as $column) {
            if ($column == 'left' && $this->page->blocks->region_has_content(BLOCK_POS_LEFT, $this)) {
                echo '<td id="left-column" class="block-region" style="width: ' . $preferredwidthright . 'px; vertical-align: top;">';
                echo $this->container_start();
                echo $this->blocks_for_region(BLOCK_POS_LEFT);
                echo $this->container_end();
                echo '</td>';

            } else if ($column == 'middle') {
                echo '<td id="middle-column" style="vertical-align: top;">';
                echo $this->container_start();
                echo $this->skip_link_target();
                echo self::MAIN_CONTENT_TOKEN;
                echo $this->container_end();
                echo '</td>';

            } else if ($column == 'right' && $this->page->blocks->region_has_content(BLOCK_POS_RIGHT, $this)) {
                echo '<td id="right-column" class="block-region" style="width: ' . $preferredwidthright . 'px; vertical-align: top;">';
                echo $this->container_start();
                echo $this->blocks_for_region(BLOCK_POS_RIGHT);
                echo $this->container_end();
                echo '</td>';
            }
        }
        echo '</tr></table>';

        $menu = str_replace('navmenu', 'navmenufooter', $menu);
        include($THEME->dir . '/footer.html');

        $output = ob_get_contents();
        ob_end_clean();

        // Put in the start of body code. Bit of a hack, put it in before the first
        // <div or <table.
        $divpos = strpos($output, '<div');
        $tablepos = strpos($output, '<table');
        if ($divpos === false || ($tablepos !== false && $tablepos < $divpos)) {
            $pos = $tablepos;
        } else {
            $pos = $divpos;
        }
        $output = substr($output, 0, $divpos) . $this->standard_top_of_body_html() .
                substr($output, $divpos);

        // Put in the end token before the end of body.
        $output = str_replace('</body>', self::END_HTML_TOKEN . '</body>', $output);

        // Make sure we use the correct doctype.
        $output = preg_replace('/(<!DOCTYPE.+?>)/s', $this->doctype(), $output);

        return $output;
    }

    /**
     * Outputs the page's footer
     * @return string HTML fragment
     */
    public function footer() {
        $output = $this->opencontainers->pop_all_but_last(true);

        $footer = $this->opencontainers->pop('header/footer');

        // Provide some performance info if required
        $performanceinfo = '';
        if (defined('MDL_PERF') || (!empty($CFG->perfdebug) and $CFG->perfdebug > 7)) {
            $perf = get_performance_info();
            if (defined('MDL_PERFTOLOG') && !function_exists('register_shutdown_function')) {
                error_log("PERF: " . $perf['txt']);
            }
            if (defined('MDL_PERFTOFOOT') || debugging() || $CFG->perfdebug > 7) {
                $performanceinfo = $perf['html'];
            }
        }
        $footer = str_replace(self::PERFORMANCE_INFO_TOKEN, $performanceinfo, $footer);

        $footer = str_replace(self::END_HTML_TOKEN, $this->page->requires->get_end_code(), $footer);

        $this->page->set_state(moodle_page::STATE_DONE);


        return $output . $footer;
    }

    /**
     * Output the row of editing icons for a block, as defined by the controls array.
     * @param array $controls an array like {@link block_contents::$controls}.
     * @return HTML fragment.
     */
    public function block_controls($controls) {
        if (empty($controls)) {
            return '';
        }
        $controlshtml = array();
        foreach ($controls as $control) {
            $controlshtml[] = $this->output_tag('a', array('class' => 'icon',
                    'title' => $control['caption'], 'href' => $control['url']),
                    $this->output_empty_tag('img',  array('src' => $this->old_icon_url($control['icon']),
                    'alt' => $control['caption'])));
        }
        return $this->output_tag('div', array('class' => 'commands'), implode('', $controlshtml));
    }

    /**
     * Prints a nice side block with an optional header.
     *
     * The content is described
     * by a {@link block_contents} object.
     *
     * @param block_contents $bc HTML for the content
     * @param string $region the region the block is appearing in.
     * @return string the HTML to be output.
     */
    function block($bc, $region) {
        $bc = clone($bc); // Avoid messing up the object passed in.
        $bc->prepare();

        $skiptitle = strip_tags($bc->title);
        if (empty($skiptitle)) {
            $output = '';
            $skipdest = '';
        } else {
            $output = $this->output_tag('a', array('href' => '#sb-' . $bc->skipid, 'class' => 'skip-block'),
                    get_string('skipa', 'access', $skiptitle));
            $skipdest = $this->output_tag('span', array('id' => 'sb-' . $bc->skipid, 'class' => 'skip-block-to'), '');
        }

        $bc->attributes['id'] = $bc->id;
        $bc->attributes['class'] = $bc->get_classes_string();
        $output .= $this->output_start_tag('div', $bc->attributes);

        $controlshtml = $this->block_controls($bc->controls);

        $title = '';
        if ($bc->title) {
            $title = $this->output_tag('h2', null, $bc->title);
        }

        if ($title || $controlshtml) {
            $output .= $this->output_tag('div', array('class' => 'header'),
                    $this->output_tag('div', array('class' => 'title'),
                    $title . $controlshtml));
        }

        $output .= $this->output_start_tag('div', array('class' => 'content'));
        $output .= $bc->content;

        if ($bc->footer) {
            $output .= $this->output_tag('div', array('class' => 'footer'), $bc->footer);
        }

        $output .= $this->output_end_tag('div');
        $output .= $this->output_end_tag('div');

        if ($bc->annotation) {
            $output .= $this->output_tag('div', array('class' => 'blockannotation'), $bc->annotation);
        }
        $output .= $skipdest;

        $this->init_block_hider_js($bc);
        return $output;
    }

    /**
     * Calls the JS require function to hide a block.
     * @param block_contents $bc A block_contents object
     * @return void
     */
    protected function init_block_hider_js($bc) {
        if ($bc->collapsible != block_contents::NOT_HIDEABLE) {
            $userpref = 'block' . $bc->blockinstanceid . 'hidden';
            user_preference_allow_ajax_update($userpref, PARAM_BOOL);
            $this->page->requires->yui_lib('dom');
            $this->page->requires->yui_lib('event');
            $plaintitle = strip_tags($bc->title);
            $this->page->requires->js_function_call('new block_hider', array($bc->id, $userpref,
                    get_string('hideblocka', 'access', $plaintitle), get_string('showblocka', 'access', $plaintitle),
                    $this->old_icon_url('t/switch_minus'), $this->old_icon_url('t/switch_plus')));
        }
    }

    /**
     * Render the contents of a block_list.
     * @param array $icons the icon for each item.
     * @param array $items the content of each item.
     * @return string HTML
     */
    public function list_block_contents($icons, $items) {
        $row = 0;
        $lis = array();
        foreach ($items as $key => $string) {
            $item = $this->output_start_tag('li', array('class' => 'r' . $row));
            if ($icons) {
                $item .= $this->output_tag('div', array('class' => 'icon column c0'), $icons[$key]);
            }
            $item .= $this->output_tag('div', array('class' => 'column c1'), $string);
            $item .= $this->output_end_tag('li');
            $lis[] = $item;
            $row = 1 - $row; // Flip even/odd.
        }
        return $this->output_tag('ul', array('class' => 'list'), implode("\n", $lis));
    }

    /**
     * Output all the blocks in a particular region.
     * @param string $region the name of a region on this page.
     * @return string the HTML to be output.
     */
    public function blocks_for_region($region) {
        $blockcontents = $this->page->blocks->get_content_for_region($region, $this);

        $output = '';
        foreach ($blockcontents as $bc) {
            if ($bc instanceof block_contents) {
                $output .= $this->block($bc, $region);
            } else if ($bc instanceof block_move_target) {
                $output .= $this->block_move_target($bc);
            } else {
                throw new coding_exception('Unexpected type of thing (' . get_class($bc) . ') found in list of block contents.');
            }
        }
        return $output;
    }

    /**
     * Output a place where the block that is currently being moved can be dropped.
     * @param block_move_target $target with the necessary details.
     * @return string the HTML to be output.
     */
    public function block_move_target($target) {
        return $this->output_tag('a', array('href' => $target->url, 'class' => 'blockmovetarget'),
                $this->output_tag('span', array('class' => 'accesshide'), $target->text));
    }

    /**
     * Given a html_link object, outputs an <a> tag that uses the object's attributes.
     *
     * @param mixed $link A html_link object or a string URL (text param required in second case)
     * @param string $text A descriptive text for the link. If $link is a html_link, this is not required.
     * @return string HTML fragment
     */
    public function link($link, $text=null) {
        $attributes = array();

        if (is_a($link, 'html_link')) {
            $link = clone($link);
            $link->prepare();
            $this->prepare_event_handlers($link);
            $attributes['href'] = prepare_url($link->url);
            $attributes['class'] = $link->get_classes_string();
            $attributes['title'] = $link->title;
            $attributes['id'] = $link->id;

            $text = $link->text;

        } else if (empty($text)) {
            throw new coding_exception('$OUTPUT->link() must have a string as second parameter if the first param ($link) is a string');

        } else {
            $attributes['href'] = prepare_url($link);
        }

        return $this->output_tag('a', $attributes, $text);
    }

   /**
    * Print a message along with button choices for Continue/Cancel. Labels default to Yes(Continue)/No(Cancel).
    * If a string or moodle_url is given instead of a html_button, method defaults to post and text to Yes/No
    * @param string $message The question to ask the user
    * @param mixed $continue The html_form component representing the Continue answer. Can also be a moodle_url or string URL
    * @param mixed $cancel The html_form component representing the Cancel answer. Can also be a moodle_url or string URL
    * @return string HTML fragment
    */
    public function confirm($message, $continue, $cancel) {
        if ($continue instanceof html_form) {
            $continue = clone($continue);
        } else if (is_string($continue)) {
            $continueform = new html_form();
            $continueform->url = new moodle_url($continue);
            $continue = $continueform;
        } else if ($continue instanceof moodle_url) {
            $continueform = new html_form();
            $continueform->url = $continue;
            $continue = $continueform;
        } else {
            throw new coding_exception('The continue param to $OUTPUT->confirm must be either a URL (string/moodle_url) or a html_form object.');
        }

        if ($cancel instanceof html_form) {
            $cancel = clone($cancel);
        } else if (is_string($cancel)) {
            $cancelform = new html_form();
            $cancelform->url = new moodle_url($cancel);
            $cancel = $cancelform;
        } else if ($cancel instanceof moodle_url) {
            $cancelform = new html_form();
            $cancelform->url = $cancel;
            $cancel = $cancelform;
        } else {
            throw new coding_exception('The cancel param to $OUTPUT->confirm must be either a URL (string/moodle_url) or a html_form object.');
        }

        if (empty($continue->button->text)) {
            $continue->button->text = get_string('yes');
        }
        if (empty($cancel->button->text)) {
            $cancel->button->text = get_string('no');
        }

        $output = $this->box_start('generalbox', 'notice');
        $output .= $this->output_tag('p', array(), $message);
        $output .= $this->output_tag('div', array('class' => 'buttons'), $this->button($continue) . $this->button($cancel));
        $output .= $this->box_end();
        return $output;
    }

    /**
     * Given a html_form object, outputs an <input> tag within a form that uses the object's attributes.
     *
     * @param html_form $form A html_form object
     * @return string HTML fragment
     */
    public function button($form) {
        if (empty($form->button) or !($form->button instanceof html_button)) {
            throw new coding_exception('$OUTPUT->button($form) requires $form to have a button (html_button) value');
        }
        $form = clone($form);
        $form->button->prepare();

        $this->prepare_event_handlers($form->button);

        $buttonattributes = array('class' => $form->button->get_classes_string(),
                                  'type' => 'submit',
                                  'value' => $form->button->text,
                                  'disabled' => $form->button->disabled,
                                  'id' => $form->button->id);

        $buttonoutput = $this->output_empty_tag('input', $buttonattributes);

        // Removing the button so it doesn't get output again
        unset($form->button);

        return $this->form($form, $buttonoutput);
    }

    /**
     * Given a html_form component and an optional rendered submit button,
     * outputs a HTML form with correct divs and inputs and a single submit button.
     * This doesn't render any other visible inputs. Use moodleforms for these.
     * @param html_form $form A html_form instance
     * @param string $contents HTML fragment to put inside the form. If given, must contain at least the submit button.
     * @return string HTML fragment
     */
    public function form($form, $contents=null) {
        $form = clone($form);
        $form->prepare();
        $this->prepare_event_handlers($form);
        $buttonoutput = null;

        if (empty($contents) && !empty($form->button)) {
            debugging("You probably want to use \$OUTPUT->button(\$form), please read that function's documentation", DEBUG_DEVELOPER);
        } else if (empty($contents)) {
            $contents = $this->output_empty_tag('input', array('type' => 'submit', 'value' => get_string('ok')));
        } else if (!empty($form->button)) {
            $form->button->prepare();
            $buttonoutput = $this->output_start_tag('div', array('id' => "noscript$form->id"));
            $this->prepare_event_handlers($form->button);

            $buttonattributes = array('class' => $form->button->get_classes_string(),
                                      'type' => 'submit',
                                      'value' => $form->button->text,
                                      'disabled' => $form->button->disabled,
                                      'id' => $form->button->id);

            $buttonoutput .= $this->output_empty_tag('input', $buttonattributes);
            $buttonoutput .= $this->output_end_tag('div');
            $this->page->requires->js_function_call('hide_item', array("noscript$form->id"));

        }

        $hiddenoutput = '';

        foreach ($form->url->params() as $var => $val) {
            $hiddenoutput .= $this->output_empty_tag('input', array('type' => 'hidden', 'name' => $var, 'value' => $val));
        }

        $formattributes = array(
                'method' => $form->method,
                'action' => prepare_url($form->url, true),
                'id' => $form->id,
                'class' => $form->get_classes_string());

        $divoutput = $this->output_tag('div', array(), $hiddenoutput . $contents . $buttonoutput);
        $formoutput = $this->output_tag('form', $formattributes, $divoutput);
        $output = $this->output_tag('div', array('class' => 'singlebutton'), $formoutput);

        return $output;
    }

    /**
     * Returns a string containing a link to the user documentation.
     * Also contains an icon by default. Shown to teachers and admin only.
     * @param string $path The page link after doc root and language, no leading slash.
     * @param string $text The text to be displayed for the link
     * @param string $iconpath The path to the icon to be displayed
     */
    public function doc_link($path, $text=false, $iconpath=false) {
        global $CFG, $OUTPUT;
        $icon = new moodle_action_icon();
        $icon->linktext = $text;
        $icon->image->alt = $text;
        $icon->image->add_class('iconhelp');
        $icon->link->url = new moodle_url(get_docs_url($path));

        if (!empty($iconpath)) {
            $icon->image->src = $iconpath;
        } else {
            $icon->image->src = $this->old_icon_url('docs');
        }

        if (!empty($CFG->doctonewwindow)) {
            $icon->actions[] = new popup_action('click', $icon->link->url);
        }

        return $this->action_icon($icon);

    }

    /**
     * Given a moodle_action_icon object, outputs an image linking to an action (URL or AJAX).
     *
     * @param moodle_action_icon $icon A moodle_action_icon object
     * @return string HTML fragment
     */
    public function action_icon($icon) {
        $icon = clone($icon);
        $icon->prepare();
        $imageoutput = $this->image($icon->image);

        if ($icon->linktext) {
            $imageoutput .= $icon->linktext;
        }
        $icon->link->text = $imageoutput;

        return $this->link($icon->link);
    }

    /*
     * Centered heading with attached help button (same title text)
     * and optional icon attached
     * @param moodle_help_icon $helpicon A moodle_help_icon object
     * @param mixed $image An image URL or a html_image object
     * @return string HTML fragment
     */
    public function heading_with_help($helpicon, $image=false) {
        if (!($image instanceof html_image) && !empty($image)) {
            $htmlimage = new html_image();
            $htmlimage->src = $image;
            $image = $htmlimage;
        }
        return $this->container($this->image($image) . $this->heading($helpicon->text, 2, 'main help') . $this->help_icon($helpicon), 'heading-with-help');
    }

    /**
     * Print a help icon.
     *
     * @param moodle_help_icon $helpicon A moodle_help_icon object, subclass of html_link
     *
     * @return string  HTML fragment
     */
    public function help_icon($icon) {
        global $COURSE;
        $icon = clone($icon);
        $icon->prepare();

        $popup = new popup_action('click', $icon->link->url);
        $icon->link->add_action($popup);

        $image = null;

        if (!empty($icon->image)) {
            $image = $icon->image;
            $image->add_class('iconhelp');
        }

        return $this->output_tag('span', array('class' => 'helplink'), $this->link_to_popup($icon->link, $image));
    }

    /**
     * Creates and returns a button to a popup window
     *
     * @param html_link $link Subclass of moodle_html_component
     * @param moodle_popup $popup A moodle_popup object
     * @param html_image $image An optional image replacing the link text
     *
     * @return string HTML fragment
     */
    public function link_to_popup($link, $image=null) {
        $link = clone($link);
        $link->prepare();

        $this->prepare_event_handlers($link);

        if (empty($link->url)) {
            throw new coding_exception('Called $OUTPUT->link_to_popup($link) method without $link->url set.');
        }

        $linkurl = prepare_url($link->url);

        $tagoptions = array(
                'title' => $link->title,
                'id' => $link->id,
                'href' => ($linkurl) ? $linkurl : prepare_url($popup->url),
                'class' => $link->get_classes_string());

        // Use image if one is given
        if (!empty($image) && $image instanceof html_image) {

            if (empty($image->alt)) {
                $image->alt = $link->text;
            }

            $link->text = $this->image($image);

            if (!empty($link->linktext)) {
                $link->text = "$link->title &nbsp; $link->text";
            }
        }

        return $this->output_tag('a', $tagoptions, $link->text);
    }

    /**
     * Creates and returns a spacer image with optional line break.
     *
     * @param html_image $image Subclass of moodle_html_component
     *
     * @return string HTML fragment
     */
    public function spacer($image) {
        $image = clone($image);
        $image->prepare();
        $image->add_class('spacer');

        if (empty($image->src)) {
            $image->src = $this->old_icon_url('spacer');
        }

        $output = $this->image($image);

        return $output;
    }

    /**
     * Creates and returns an image.
     *
     * @param html_image $image Subclass of moodle_html_component
     *
     * @return string HTML fragment
     */
    public function image($image) {
        if ($image === false) {
            return false;
        }

        $image = clone($image);
        $image->prepare();

        $this->prepare_event_handlers($image);

        $attributes = array('class' => $image->get_classes_string(),
                            'src' => prepare_url($image->src),
                            'alt' => $image->alt,
                            'style' => $image->style,
                            'title' => $image->title,
                            'id' => $image->id);

        if (!empty($image->height) || !empty($image->width)) {
            $attributes['style'] .= $this->prepare_legacy_width_and_height($image);
        }
        return $this->output_empty_tag('img', $attributes);
    }

    /**
     * Print the specified user's avatar.
     *
     * This method can be used in two ways:
     * <pre>
     * // Option 1:
     * $userpic = new moodle_user_picture();
     * // Set properties of $userpic
     * $OUTPUT->user_picture($userpic);
     *
     * // Option 2: (shortcut for simple cases)
     * // $user has come from the DB and has fields id, picture, imagealt, firstname and lastname
     * $OUTPUT->user_picture($user, $COURSE->id);
     * </pre>
     *
     * @param object $userpic Object with at least fields id, picture, imagealt, firstname, lastname
     *     If any of these are missing, or if a userid is passed, the database is queried. Avoid this
     *     if at all possible, particularly for reports. It is very bad for performance.
     *     A moodle_user_picture object is a better parameter.
     * @param int $courseid courseid Used when constructing the link to the user's profile. Required if $userpic
     *     is not a moodle_user_picture object
     * @return string HTML fragment
     */
    public function user_picture($userpic, $courseid=null) {
        // Instantiate a moodle_user_picture object if $user is not already one
        if (!($userpic instanceof moodle_user_picture)) {
            if (empty($courseid)) {
                throw new coding_exception('Called $OUTPUT->user_picture with a $user object but no $courseid.');
            }

            $user = $userpic;
            $userpic = new moodle_user_picture();
            $userpic->user = $user;
            $userpic->courseid = $courseid;
        } else {
            $userpic = clone($userpic);
        }

        $userpic->prepare();

        $output = $this->image($userpic->image);

        if (!empty($userpic->url)) {
            $actions = $userpic->get_actions();
            if ($userpic->popup && !empty($actions)) {
                $link = new html_link();
                $link->url = $userpic->url;
                $link->text = fullname($userpic->user);
                $link->title = fullname($userpic->user);

                foreach ($actions as $action) {
                    $link->add_action($action);
                }
                $output = $this->link_to_popup($link, $userpic->image);
            } else {
                $output = $this->link(prepare_url($userpic->url), $output);
            }
        }

        return $output;
    }

    /**
     * Prints the 'Update this Modulename' button that appears on module pages.
     *
     * @param string $cmid the course_module id.
     * @param string $modulename the module name, eg. "forum", "quiz" or "workshop"
     * @return string the HTML for the button, if this user has permission to edit it, else an empty string.
     */
    public function update_module_button($cmid, $modulename) {
        global $CFG;
        if (has_capability('moodle/course:manageactivities', get_context_instance(CONTEXT_MODULE, $cmid))) {
            $modulename = get_string('modulename', $modulename);
            $string = get_string('updatethis', '', $modulename);

            $form = new html_form();
            $form->url = new moodle_url("$CFG->wwwroot/course/mod.php", array('update' => $cmid, 'return' => true, 'sesskey' => sesskey()));
            $form->button->text = $string;
            return $this->button($form);
        } else {
            return '';
        }
    }

    /**
     * Prints a "Turn editing on/off" button in a form.
     * @param moodle_url $url The URL + params to send through when clicking the button
     * @return string HTML the button
     */
    public function edit_button(moodle_url $url) {
        global $USER;
        if (!empty($USER->editing)) {
            $string = get_string('turneditingoff');
            $edit = '0';
        } else {
            $string = get_string('turneditingon');
            $edit = '1';
        }

        $form = new html_form();
        $form->url = $url;
        $form->url->param('edit', $edit);
        $form->button->text = $string;

        return $this->button($form);
    }

    /**
     * Outputs a HTML nested list
     *
     * @param html_list $list A html_list object
     * @return string HTML structure
     */
    public function htmllist($list) {
        $list = clone($list);
        $list->prepare();

        $this->prepare_event_handlers($list);

        if ($list->type == 'ordered') {
            $tag = 'ol';
        } else if ($list->type == 'unordered') {
            $tag = 'ul';
        }

        $output = $this->output_start_tag($tag, array('class' => $list->get_classes_string()));

        foreach ($list->items as $listitem) {
            if ($listitem instanceof html_list) {
                $output .= $this->output_start_tag('li', array());
                $output .= $this->htmllist($listitem);
                $output .= $this->output_end_tag('li');
            } else if ($listitem instanceof html_list_item) {
                $listitem->prepare();
                $this->prepare_event_handlers($listitem);
                $output .= $this->output_tag('li', array('class' => $listitem->get_classes_string()), $listitem->value);
            }
        }

        return $output . $this->output_end_tag($tag);
    }

    /**
     * Prints a simple button to close a window
     *
     * @global objec)t
     * @param string $text The lang string for the button's label (already output from get_string())
     * @return string|void if $return is true, void otherwise
     */
    public function close_window_button($text) {
        if (empty($text)) {
            $text = get_string('closewindow');
        }
        $closeform = new html_form();
        $closeform->url = '#';
        $closeform->button->text = $text;
        $closeform->button->add_action('click', 'close_window');
        $closeform->button->prepare();
        return $this->container($this->button($closeform), 'closewindow');
    }

    /**
     * Outputs a <select> menu or a list of radio/checkbox inputs.
     *
     * This method is extremely versatile, and can be used to output yes/no menus,
     * form-enclosed menus with automatic redirects when an option is selected,
     * descriptive labels and help icons. By default it just outputs a select
     * menu.
     *
     * To add a descriptive label, use moodle_select::set_label($text, $for) or
     * moodle_select::set_label($label) passing a html_label object
     *
     * To add a help icon, use moodle_select::set_help($page, $text, $linktext) or
     * moodle_select::set_help($helpicon) passing a moodle_help_icon object
     *
     * If you moodle_select::$rendertype to "radio", it will render radio buttons
     * instead of a <select> menu, unless $multiple is true, in which case it
     * will render checkboxes.
     *
     * To surround the menu with a form, simply set moodle_select->form as a
     * valid html_form object. Note that this function will NOT automatically
     * add a form for non-JS browsers. If you do not set one up, it assumes
     * that you are providing your own form in some other way.
     *
     * You can either call this function with a single moodle_select argument
     * or, with a list of parameters, in which case those parameters are sent to
     * the moodle_select constructor.
     *
     * @param moodle_select $select a moodle_select that describes
     *      the select menu you want output.
     * @return string the HTML for the <select>
     */
    public function select($select) {
        $select = clone($select);
        $select->prepare();

        $this->prepare_event_handlers($select);

        if (empty($select->id)) {
            $select->id = 'menu' . str_replace(array('[', ']'), '', $select->name);
        }

        $attributes = array(
            'name' => $select->name,
            'id' => $select->id,
            'class' => $select->get_classes_string()
        );
        if ($select->disabled) {
            $attributes['disabled'] = 'disabled';
        }
        if ($select->tabindex) {
            $attributes['tabindex'] = $tabindex;
        }

        if ($select->rendertype == 'menu' && $select->listbox) {
            if (is_integer($select->listbox)) {
                $size = $select->listbox;
            } else {
                $size = min($select->maxautosize, count($select->options));
            }
            $attributes['size'] = $size;
            if ($select->multiple) {
                $attributes['multiple'] = 'multiple';
            }
        }

        $html = '';

        if (!empty($select->label)) {
            $html .= $this->label($select->label);
        }

        if (!empty($select->helpicon) && $select->helpicon instanceof moodle_help_icon) {
            $html .= $this->help_icon($select->helpicon);
        }

        if ($select->rendertype == 'menu') {
            $html .= $this->output_start_tag('select', $attributes) . "\n";

            foreach ($select->options as $option) {
                // $OUTPUT->select_option detects if $option is an option or an optgroup
                $html .= $this->select_option($option);
            }

            $html .= $this->output_end_tag('select') . "\n";
        } else if ($select->rendertype == 'radio') {
            $currentradio = 0;
            foreach ($select->options as $option) {
                $html .= $this->radio($option, $select->name);
                $currentradio++;
            }
        } else if ($select->rendertype == 'checkbox') {
            $currentcheckbox = 0;
            foreach ($select->options as $option) {
                $html .= $this->checkbox($option, $select->name);
                $currentcheckbox++;
            }
        }

        if (!empty($select->form) && $select->form instanceof html_form) {
            $html = $this->form($select->form, $html);
        }

        return $html;
    }

    /**
     * Outputs a <input type="radio" /> element. Optgroups are ignored, so do not
     * pass a html_select_optgroup as a param to this function.
     *
     * @param html_select_option $option a html_select_option
     * @return string the HTML for the <input type="radio">
     */
    public function radio($option, $name='unnamed') {
        if ($option instanceof html_select_optgroup) {
            throw new coding_exception('$OUTPUT->radio($option) does not support a html_select_optgroup object as param.');
        } else if (!($option instanceof html_select_option)) {
            throw new coding_exception('$OUTPUT->radio($option) only accepts a html_select_option object as param.');
        }
        $option = clone($option);
        $option->prepare();
        $option->label->for = $option->id;
        $this->prepare_event_handlers($option);

        $output = $this->output_start_tag('span', array('class' => "radiogroup $select->name rb$currentradio")) . "\n";
        $output .= $this->label($option->label);

        if ($option->selected == 'selected') {
            $option->selected = 'checked';
        }

        $output .= $this->output_empty_tag('input', array(
                'type' => 'radio',
                'value' => $option->value,
                'name' => $name,
                'alt' => $option->alt,
                'id' => $option->id,
                'class' => $option->get_classes_string(),
                'checked' => $option->selected));

        $output .= $this->output_end_tag('span');

        return $output;
    }

    /**
     * Outputs a <input type="checkbox" /> element. Optgroups are ignored, so do not
     * pass a html_select_optgroup as a param to this function.
     *
     * @param html_select_option $option a html_select_option
     * @return string the HTML for the <input type="checkbox">
     */
    public function checkbox($option, $name='unnamed') {
        if ($option instanceof html_select_optgroup) {
            throw new coding_exception('$OUTPUT->checkbox($option) does not support a html_select_optgroup object as param.');
        } else if (!($option instanceof html_select_option)) {
            throw new coding_exception('$OUTPUT->checkbox($option) only accepts a html_select_option object as param.');
        }
        $option = clone($option);
        $option->prepare();

        $option->label->for = $option->id;
        $this->prepare_event_handlers($option);

        $output = $this->output_start_tag('span', array('class' => "checkbox $name")) . "\n";

        if ($option->selected == 'selected') {
            $option->selected = 'checked';
        }

        $output .= $this->output_empty_tag('input', array(
                'type' => 'checkbox',
                'value' => $option->value,
                'name' => $name,
                'id' => $option->id,
                'alt' => $option->alt,
                'class' => $option->get_classes_string(),
                'checked' => $option->selected));
        $output .= $this->label($option->label);

        $output .= $this->output_end_tag('span');

        return $output;
    }

    /**
     * Output an <option> or <optgroup> element. If an optgroup element is detected,
     * this will recursively output its options as well.
     *
     * @param mixed $option a html_select_option or moodle_select_optgroup
     * @return string the HTML for the <option> or <optgroup>
     */
    public function select_option($option) {
        $option = clone($option);
        $option->prepare();
        $this->prepare_event_handlers($option);

        if ($option instanceof html_select_option) {
            return $this->output_tag('option', array(
                    'value' => $option->value,
                    'class' => $option->get_classes_string(),
                    'selected' => $option->selected), $option->text);
        } else if ($option instanceof html_select_optgroup) {
            $output = $this->output_start_tag('optgroup', array('label' => $option->text, 'class' => $option->get_classes_string()));
            foreach ($option->options as $selectoption) {
                $output .= $this->select_option($selectoption);
            }
            $output .= $this->output_end_tag('optgroup');
            return $output;
        }
    }

    /**
     * Output an <input type="text"> element
     *
     * @param html_field $field a html_field object
     * @return string the HTML for the <input>
     */
    public function textfield($field) {
        $field = clone($field);
        $field->prepare();
        $this->prepare_event_handlers($field);
        $output = $this->output_start_tag('span', array('class' => "textfield $field->name"));
        $output .= $this->output_empty_tag('input', array(
                'type' => 'text',
                'name' => $field->name,
                'id' => $field->id,
                'value' => $field->value,
                'style' => $field->style,
                'alt' => $field->alt,
                'maxlength' => $field->maxlength));
        $output .= $this->output_end_tag('span');
        return $output;
    }

    /**
     * Outputs a <label> element.
     * @param html_label $label A html_label object
     * @return HTML fragment
     */
    public function label($label) {
        $label = clone($label);
        $label->prepare();
        $this->prepare_event_handlers($label);
        return $this->output_tag('label', array('for' => $label->for, 'class' => $label->get_classes_string()), $label->text);
    }

    /**
     * Output an error message. By default wraps the error message in <span class="error">.
     * If the error message is blank, nothing is output.
     * @param string $message the error message.
     * @return string the HTML to output.
     */
    public function error_text($message) {
        if (empty($message)) {
            return '';
        }
        return $this->output_tag('span', array('class' => 'error'), $message);
    }

    /**
     * Do not call this function directly.
     *
     * To terminate the current script with a fatal error, call the {@link print_error}
     * function, or throw an exception. Doing either of those things will then call this
     * function to display the error, before terminating the execution.
     *
     * @param string $message The message to output
     * @param string $moreinfourl URL where more info can be found about the error
     * @param string $link Link for the Continue button
     * @param array $backtrace The execution backtrace
     * @param string $debuginfo Debugging information
     * @param bool $showerrordebugwarning Whether or not to show a debugging warning
     * @return string the HTML to output.
     */
    public function fatal_error($message, $moreinfourl, $link, $backtrace,
                $debuginfo = null, $showerrordebugwarning = false) {

        $output = '';

        if ($this->has_started()) {
            $output .= $this->opencontainers->pop_all_but_last();
        } else {
            // Header not yet printed
            @header('HTTP/1.0 404 Not Found');
            $this->page->set_title(get_string('error'));
            $output .= $this->header();
        }

        $message = '<p class="errormessage">' . $message . '</p>'.
                '<p class="errorcode"><a href="' . $moreinfourl . '">' .
                get_string('moreinformation') . '</a></p>';
        $output .= $this->box($message, 'errorbox');

        if (debugging('', DEBUG_DEVELOPER)) {
            if ($showerrordebugwarning) {
                $output .= $this->notification('error() is a deprecated function. ' .
                        'Please call print_error() instead of error()', 'notifytiny');
            }
            if (!empty($debuginfo)) {
                $output .= $this->notification($debuginfo, 'notifytiny');
            }
            if (!empty($backtrace)) {
                $output .= $this->notification('Stack trace: ' .
                        format_backtrace($backtrace), 'notifytiny');
            }
        }

        if (!empty($link)) {
            $output .= $this->continue_button($link);
        }

        $output .= $this->footer();

        // Padding to encourage IE to display our error page, rather than its own.
        $output .= str_repeat(' ', 512);

        return $output;
    }

    /**
     * Output a notification (that is, a status message about something that has
     * just happened).
     *
     * @param string $message the message to print out
     * @param string $classes normally 'notifyproblem' or 'notifysuccess'.
     * @return string the HTML to output.
     */
    public function notification($message, $classes = 'notifyproblem') {
        return $this->output_tag('div', array('class' =>
                moodle_renderer_base::prepare_classes($classes)), clean_text($message));
    }

    /**
     * Print a continue button that goes to a particular URL.
     *
     * @param string|moodle_url $link The url the button goes to.
     * @return string the HTML to output.
     */
    public function continue_button($link) {
        if (!is_a($link, 'moodle_url')) {
            $link = new moodle_url($link);
        }
        $form = new html_form();
        $form->url = $link;
        $form->values = $link->params();
        $form->button->text = get_string('continue');
        $form->method = 'get';

        return $this->output_tag('div', array('class' => 'continuebutton') , $this->button($form));
    }

    /**
     * Prints a single paging bar to provide access to other pages  (usually in a search)
     *
     * @param string|moodle_url $link The url the button goes to.
     * @return string the HTML to output.
     */
    public function paging_bar($pagingbar) {
        $output = '';
        $pagingbar = clone($pagingbar);
        $pagingbar->prepare();

        if ($pagingbar->totalcount > $pagingbar->perpage) {
            $output .= get_string('page') . ':';

            if (!empty($pagingbar->previouslink)) {
                $output .= '&nbsp;(' . $this->link($pagingbar->previouslink) . ')&nbsp;';
            }

            if (!empty($pagingbar->firstlink)) {
                $output .= '&nbsp;' . $this->link($pagingbar->firstlink) . '&nbsp;...';
            }

            foreach ($pagingbar->pagelinks as $link) {
                if ($link instanceof html_link) {
                    $output .= '&nbsp;&nbsp;' . $this->link($link);
                } else {
                    $output .= "&nbsp;&nbsp;$link";
                }
            }

            if (!empty($pagingbar->lastlink)) {
                $output .= '&nbsp;...' . $this->link($pagingbar->lastlink) . '&nbsp;';
            }

            if (!empty($pagingbar->nextlink)) {
                $output .= '&nbsp;&nbsp;(' . $this->link($pagingbar->nextlink) . ')';
            }
        }

        return $this->output_tag('div', array('class' => 'paging'), $output);
    }

    /**
     * Render a HTML table
     *
     * @param object $table {@link html_table} instance containing all the information needed
     * @return string the HTML to output.
     */
    public function table(html_table $table) {
        $table = clone($table);
        $table->prepare();
        $attributes = array(
                'id'            => $table->id,
                'width'         => $table->width,
                'summary'       => $table->summary,
                'cellpadding'   => $table->cellpadding,
                'cellspacing'   => $table->cellspacing,
                'class'         => $table->get_classes_string());
        $output = $this->output_start_tag('table', $attributes) . "\n";

        $countcols = 0;

        if (!empty($table->head)) {
            $countcols = count($table->head);
            $output .= $this->output_start_tag('thead', array()) . "\n";
            $output .= $this->output_start_tag('tr', array()) . "\n";
            $keys = array_keys($table->head);
            $lastkey = end($keys);
            foreach ($table->head as $key => $heading) {
                $classes = array('header', 'c' . $key);
                if (isset($table->headspan[$key]) && $table->headspan[$key] > 1) {
                    $colspan = $table->headspan[$key];
                    $countcols += $table->headspan[$key] - 1;
                } else {
                    $colspan = '';
                }
                if ($key == $lastkey) {
                    $classes[] = 'lastcol';
                }
                if (isset($table->colclasses[$key])) {
                    $classes[] = $table->colclasses[$key];
                }
                if ($table->rotateheaders) {
                    // we need to wrap the heading content
                    $heading = $this->output_tag('span', '', $heading);
                }
                $attributes = array(
                        'style'     => $table->align[$key] . $table->size[$key] . 'white-space:nowrap;',
                        'class'     => moodle_renderer_base::prepare_classes($classes),
                        'scope'     => 'col',
                        'colspan'   => $colspan);
                $output .= $this->output_tag('th', $attributes, $heading) . "\n";
            }
            $output .= $this->output_end_tag('tr') . "\n";
            $output .= $this->output_end_tag('thead') . "\n";
        }

        if (!empty($table->data)) {
            $oddeven    = 1;
            $keys       = array_keys($table->data);
            $lastrowkey = end($keys);
            $output .= $this->output_start_tag('tbody', array()) . "\n";

            foreach ($table->data as $key => $row) {
                if (($row === 'hr') && ($countcols)) {
                    $output .= $this->output_tag('td', array('colspan' => $countcols),
                                                 $this->output_tag('div', array('class' => 'tabledivider'), '')) . "\n";
                } else {
                    // Convert array rows to html_table_rows and cell strings to html_table_cell objects
                    if (!($row instanceof html_table_row)) {
                        $newrow = new html_table_row();

                        foreach ($row as $unused => $item) {
                            $cell = new html_table_cell();
                            $cell->text = $item;
                            $newrow->cells[] = $cell;
                        }
                        $row = $newrow;
                    }

                    $oddeven = $oddeven ? 0 : 1;
                    if (isset($table->rowclasses[$key])) {
                        $row->add_classes(array_unique(moodle_html_component::clean_classes($table->rowclasses[$key])));
                    }

                    $row->add_class('r' . $oddeven);
                    if ($key == $lastrowkey) {
                        $row->add_class('lastrow');
                    }

                    $output .= $this->output_start_tag('tr', array('class' => $row->get_classes_string(), 'style' => $row->style, 'id' => $row->id)) . "\n";
                    $keys2 = array_keys($row->cells);
                    $lastkey = end($keys2);

                    foreach ($row->cells as $key => $cell) {
                        if (isset($table->colclasses[$key])) {
                            $cell->add_classes(array_unique(moodle_html_component::clean_classes($table->colclasses[$key])));
                        }

                        $cell->add_classes('cell');
                        $cell->add_classes('c' . $key);
                        if ($key == $lastkey) {
                            $cell->add_classes('lastcol');
                        }
                        $tdstyle = '';
                        $tdstyle .= isset($table->align[$key]) ? $table->align[$key] : '';
                        $tdstyle .= isset($table->size[$key]) ? $table->size[$key] : '';
                        $tdstyle .= isset($table->wrap[$key]) ? $table->wrap[$key] : '';
                        $tdattributes = array(
                                'style' => $tdstyle . $cell->style,
                                'colspan' => $cell->colspan,
                                'rowspan' => $cell->rowspan,
                                'id' => $cell->id,
                                'class' => $cell->get_classes_string(),
                                'abbr' => $cell->abbr,
                                'scope' => $cell->scope);

                        $output .= $this->output_tag('td', $tdattributes, $cell->text) . "\n";
                    }
                }
                $output .= $this->output_end_tag('tr') . "\n";
            }
            $output .= $this->output_end_tag('tbody') . "\n";
        }
        $output .= $this->output_end_tag('table') . "\n";

        if ($table->rotateheaders && can_use_rotated_text()) {
            $this->page->requires->yui_lib('event');
            $this->page->requires->js('course/report/progress/textrotate.js');
        }

        return $output;
    }

    /**
     * Output the place a skip link goes to.
     * @param string $id The target name from the corresponding $PAGE->requires->skip_link_to($target) call.
     * @return string the HTML to output.
     */
    public function skip_link_target($id = '') {
        return $this->output_tag('span', array('id' => $id), '');
    }

    /**
     * Outputs a heading
     * @param string $text The text of the heading
     * @param int $level The level of importance of the heading. Defaulting to 2
     * @param string $classes A space-separated list of CSS classes
     * @param string $id An optional ID
     * @return string the HTML to output.
     */
    public function heading($text, $level = 2, $classes = 'main', $id = '') {
        $level = (integer) $level;
        if ($level < 1 or $level > 6) {
            throw new coding_exception('Heading level must be an integer between 1 and 6.');
        }
        return $this->output_tag('h' . $level,
                array('id' => $id, 'class' => moodle_renderer_base::prepare_classes($classes)), $text);
    }

    /**
     * Outputs a box.
     * @param string $contents The contents of the box
     * @param string $classes A space-separated list of CSS classes
     * @param string $id An optional ID
     * @return string the HTML to output.
     */
    public function box($contents, $classes = 'generalbox', $id = '') {
        return $this->box_start($classes, $id) . $contents . $this->box_end();
    }

    /**
     * Outputs the opening section of a box.
     * @param string $classes A space-separated list of CSS classes
     * @param string $id An optional ID
     * @return string the HTML to output.
     */
    public function box_start($classes = 'generalbox', $id = '') {
        $this->opencontainers->push('box', $this->output_end_tag('div'));
        return $this->output_start_tag('div', array('id' => $id,
                'class' => 'box ' . moodle_renderer_base::prepare_classes($classes)));
    }

    /**
     * Outputs the closing section of a box.
     * @return string the HTML to output.
     */
    public function box_end() {
        return $this->opencontainers->pop('box');
    }

    /**
     * Outputs a container.
     * @param string $contents The contents of the box
     * @param string $classes A space-separated list of CSS classes
     * @param string $id An optional ID
     * @return string the HTML to output.
     */
    public function container($contents, $classes = '', $id = '') {
        return $this->container_start($classes, $id) . $contents . $this->container_end();
    }

    /**
     * Outputs the opening section of a container.
     * @param string $classes A space-separated list of CSS classes
     * @param string $id An optional ID
     * @return string the HTML to output.
     */
    public function container_start($classes = '', $id = '') {
        $this->opencontainers->push('container', $this->output_end_tag('div'));
        return $this->output_start_tag('div', array('id' => $id,
                'class' => moodle_renderer_base::prepare_classes($classes)));
    }

    /**
     * Outputs the closing section of a container.
     * @return string the HTML to output.
     */
    public function container_end() {
        return $this->opencontainers->pop('container');
    }
}


/// RENDERERS

/**
 * A renderer that generates output for command-line scripts.
 *
 * The implementation of this renderer is probably incomplete.
 *
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since     Moodle 2.0
 */
class cli_core_renderer extends moodle_core_renderer {
    /**
     * Returns the page header.
     * @return string HTML fragment
     */
    public function header() {
        output_starting_hook();
        return $this->page->heading . "\n";
    }

    /**
     * Returns a template fragment representing a Heading.
     * @param string $text The text of the heading
     * @param int $level The level of importance of the heading
     * @param string $classes A space-separated list of CSS classes
     * @param string $id An optional ID
     * @return string A template fragment for a heading
     */
    public function heading($text, $level, $classes = 'main', $id = '') {
        $text .= "\n";
        switch ($level) {
            case 1:
                return '=>' . $text;
            case 2:
                return '-->' . $text;
            default:
                return $text;
        }
    }

    /**
     * Returns a template fragment representing a fatal error.
     * @param string $message The message to output
     * @param string $moreinfourl URL where more info can be found about the error
     * @param string $link Link for the Continue button
     * @param array $backtrace The execution backtrace
     * @param string $debuginfo Debugging information
     * @param bool $showerrordebugwarning Whether or not to show a debugging warning
     * @return string A template fragment for a fatal error
     */
    public function fatal_error($message, $moreinfourl, $link, $backtrace,
                $debuginfo = null, $showerrordebugwarning = false) {
        $output = "!!! $message !!!\n";

        if (debugging('', DEBUG_DEVELOPER)) {
            if (!empty($debuginfo)) {
                $this->notification($debuginfo, 'notifytiny');
            }
            if (!empty($backtrace)) {
                $this->notification('Stack trace: ' . format_backtrace($backtrace, true), 'notifytiny');
            }
        }
    }

    /**
     * Returns a template fragment representing a notification.
     * @param string $message The message to include
     * @param string $classes A space-separated list of CSS classes
     * @return string A template fragment for a notification
     */
    public function notification($message, $classes = 'notifyproblem') {
        $message = clean_text($message);
        if ($classes === 'notifysuccess') {
            return "++ $message ++\n";
        }
        return "!! $message !!\n";
    }
}

