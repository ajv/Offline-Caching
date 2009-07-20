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
 * This file contains the moodle_page class. There is normally a single instance
 * of this class in the $PAGE global variable. This class is a central reporitory
 * of information about the page we are building up to send back to the user.
 *
 * @package   moodlecore
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * $PAGE is a central store of information about the current page we are
 * generating in response to the user's request.
 *
 * It does not do very much itself
 * except keep track of information, however, it serves as the access point to
 * some more significant components like $PAGE->theme, $PAGE->requires,
 * $PAGE->blocks, etc.
 *
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since Moodle 2.0
 *
 * @property-read string $generaltype the general type of page this is. For example 'normal', 'popup', 'home'.
 *      Allows the theme to display things differently, if it wishes to.
 * @property-read string $title the title that should go in the <head> section of the HTML of this page.
 * @property-read string $heading the main heading that should be displayed at the top of the <body>.
 * @property-read string $cacheable defaults to true. Set to false to stop the page being cached at all.
 * @property-read page_requirements_manager $requires Tracks resources (for example required .css and .js files) required by this page.
 * @property-read int $periodicrefreshdelay Returns the delay in seconds to use in a meta refresh call set this by calling {@link set_periodicrefreshdelay()}
 */
class moodle_page {
    /**#@+ Tracks the where we are in the generation of the page. */
    const STATE_BEFORE_HEADER = 0;
    const STATE_PRINTING_HEADER = 1;
    const STATE_IN_BODY = 2;
    const STATE_DONE = 3;
    /**#@-*/

/// Field declarations =========================================================

    protected $_state = self::STATE_BEFORE_HEADER;

    protected $_course = null;

    /**
     * If this page belongs to a module, this is the row from the course_modules
     * table, as fetched by get_coursemodule_from_id or get_coursemodule_from_instance,
     * so the extra modname and name fields are present.
     */
    protected $_cm = null;

    /**
     * If $_cm is not null, then this will hold the corresponding row from the
     * modname table. For example, if $_cm->modname is 'quiz', this will be a
     * row from the quiz table.
     */
    protected $_module = null;

    /**
     * The context that this page belongs to.
     */
    protected $_context = null;

    /**
     * This holds any categories that $_course belongs to, starting with the
     * particular category it belongs to, and working out through any parent
     * categories to the top level. These are loaded progressively, if neaded.
     * There are three states. $_categories = null initially when nothing is
     * loaded; $_categories = array($id => $cat, $parentid => null) when we have
     * loaded $_course->category, but not any parents; and a complete array once
     * everything is loaded.
     */
    protected $_categories = null;

    protected $_bodyclasses = array();

    protected $_title = '';

    protected $_heading = '';

    protected $_pagetype = null;

    protected $_generaltype = 'normal';

    protected $_subpage = '';

    protected $_docspath = null;

    protected $_legacyclass = null;

    protected $_url = null;

    protected $_alternateversions = array();

    protected $_blocks = null;

    protected $_requires = null;

    protected $_blockseditingcap = 'moodle/site:manageblocks';

    protected $_othereditingcaps = array();

    protected $_cacheable = true;

    protected $_focuscontrol = '';

    protected $_button = '';

    protected $_theme = null;

    /**
     * Then the theme is initialsed, we save the stack trace, for use in error messages.
     * @var array stack trace.
     */
    protected $_wherethemewasinitialised = null;

    /** @var xhtml_container_stack tracks XHTML tags on this page that have been opened but not closed. */
    protected $_opencontainers;

    /**
     * Sets the page to refresh after a given delay (in seconds) using meta refresh
     * in {@link standard_head_html()} in outputlib.php
     * If set to null(default) the page is not refreshed
     * @var int|null
     */
    protected $_periodicrefreshdelay = null;

    /**
     * This is simply to improve backwards compatability. If old code relies on
     * a page class that implements print_header, or complex logic in
     * user_allowed_editing then we stash an instance of that other class here,
     * and delegate to it in certani situations.
     */
    protected $_legacypageobject = null;

/// Getter methods =============================================================
/// Due to the __get magic below, you normally do not call these as $PAGE->get_x
/// methods, but instead use the $PAGE->x syntax.

    /**
     * Please do not call this method directly, use the ->state syntax. {@link __get()}.
     * @return integer one of the STATE_... constants. You should not normally need
     * to use this in your code. It is indended for internal use by this class
     * and its friends like print_header, to check that everything is working as
     * expected. Also accessible as $PAGE->state.
     */
    public function get_state() {
        return $this->_state;
    }

    /**
     * Please do not call this method directly, use the ->headerprinted syntax. {@link __get()}.
     * @return boolean has the header already been printed?
     */
    public function get_headerprinted() {
        return $this->_state >= self::STATE_IN_BODY;
    }

    /**
     * Please do not call this method directly, use the ->course syntax. {@link __get()}.
     *
     * @global object
     * @return object the current course that we are inside - a row from the
     * course table. (Also available as $COURSE global.) If we are not inside
     * an actual course, this will be the site course.
     */
    public function get_course() {
        global $SITE;
        if (is_null($this->_course)) {
            return $SITE;
        }
        return $this->_course;
    }

    /**
     * Please do not call this method directly, use the ->cm syntax. {@link __get()}.
     * @return object the course_module that this page belongs to. Will be null
     * if this page is not within a module. This is a full cm object, as loaded
     * by get_coursemodule_from_id or get_coursemodule_from_instance,
     * so the extra modname and name fields are present.
     */
    public function get_cm() {
        return $this->_cm;
    }

    /**
     * Please do not call this method directly, use the ->activityrecord syntax. {@link __get()}.
     * @return object the row from the activities own database table (for example
     * the forum or quiz table) that this page belongs to. Will be null
     * if this page is not within a module.
     */
    public function get_activityrecord() {
        if (is_null($this->_module) && !is_null($this->_cm)) {
            $this->load_activity_record();
        }
        return $this->_module;
    }

    /**
     * Please do not call this method directly, use the ->activityname syntax. {@link __get()}.
     * @return string|null the The type of activity we are in, for example 'forum' or 'quiz'.
     * Will be null if this page is not within a module.
     */
    public function get_activityname() {
        if (is_null($this->_cm)) {
            return null;
        }
        return $this->_cm->modname;
    }

    /**
     * Please do not call this method directly, use the ->category syntax. {@link __get()}.
     * @return mixed the category that the page course belongs to. If there isn't one
     * (that is, if this is the front page course) returns null.
     */
    public function get_category() {
        $this->ensure_category_loaded();
        if (!empty($this->_categories)) {
            return reset($this->_categories);
        } else {
            return null;
        }
    }

    /**
     * Please do not call this method directly, use the ->categories syntax. {@link __get()}.
     * @return array an array of all the categories the page course belongs to,
     * starting with the immediately containing category, and working out to
     * the top-level category. This may be the empty array if we are in the
     * front page course.
     */
    public function get_categories() {
        $this->ensure_categories_loaded();
        return $this->_categories;
    }

    /**
     * Please do not call this method directly, use the ->context syntax. {@link __get()}.
     * @return object the main context to which this page belongs.
     */
    public function get_context() {
        if (is_null($this->_context)) {
            throw new coding_exception('$PAGE->context accessed before it was known.');
        }
        return $this->_context;
    }

    /**
     * Please do not call this method directly, use the ->pagetype syntax. {@link __get()}.
     * @return string e.g. 'my-index' or 'mod-quiz-attempt'. Same as the id attribute on <body>.
     */
    public function get_pagetype() {
        if (is_null($this->_pagetype) || isset($CFG->pagepath)) {
            $this->initialise_default_pagetype();
        }
        return $this->_pagetype;
    }

    /**
     * Please do not call this method directly, use the ->generaltype syntax. {@link __get()}.
     * @return string the general type of page this is. For example 'normal', 'popup', 'home'.
     *      Allows the theme to display things differently, if it wishes to.
     */
    public function get_generaltype() {
        return $this->_generaltype;
    }

    /**
     * Please do not call this method directly, use the ->subpage syntax. {@link __get()}.
     * @return string|null The subpage identifier, if any.
     */
    public function get_subpage() {
        return $this->_subpage;
    }

    /**
     * Please do not call this method directly, use the ->bodyclasses syntax. {@link __get()}.
     * @return string the class names to put on the body element in the HTML.
     */
    public function get_bodyclasses() {
        return implode(' ', array_keys($this->_bodyclasses));
    }

    /**
     * Please do not call this method directly, use the ->title syntax. {@link __get()}.
     * @return string the title that should go in the <head> section of the HTML of this page.
     */
    public function get_title() {
        return $this->_title;
    }

    /**
     * Please do not call this method directly, use the ->heading syntax. {@link __get()}.
     * @return string the main heading that should be displayed at the top of the <body>.
     */
    public function get_heading() {
        return $this->_heading;
    }

    /**
     * Please do not call this method directly, use the ->docspath syntax. {@link __get()}.
     * @return string the path to the Moodle docs for this page.
     */
    public function get_docspath() {
        if (is_string($this->_docspath)) {
            return $this->_docspath;
        } else {
            return str_replace('-', '/', $this->pagetype);
        }
    }

    /**
     * Please do not call this method directly, use the ->url syntax. {@link __get()}.
     * @return moodle_url the clean URL required to load the current page. (You
     * should normally use this in preference to $ME or $FULLME.)
     */
    public function get_url() {
        if (is_null($this->_url)) {
            debugging('This page did no call $PAGE->set_url(...). Relaying on a guess.', DEBUG_DEVELOPER);
            global $FULLME;
            $this->_url = new moodle_url($FULLME);
            // Make sure the guessed URL cannot lead to dangerous redirects.
            $this->_url->remove_params('sesskey');
        }
        return new moodle_url($this->_url); // Return a clone for safety.
    }

    /**
     * The list of alternate versions of this page.
     * @return array mime type => object with ->url and ->title.
     */
    public function get_alternateversions() {
        return $this->_alternateversions;
    }

    /**
     * Please do not call this method directly, use the ->blocks syntax. {@link __get()}.
     * @return blocks_manager the blocks manager object for this page.
     */
    public function get_blocks() {
        global $CFG, $THEME;
        if (is_null($this->_blocks)) {
            if (!empty($CFG->blockmanagerclass)) {
                $classname = $CFG->blockmanagerclass;
            } else {
                $classname = 'block_manager';
            }
            $this->_blocks = new $classname($this);
        }
        return $this->_blocks;
    }

    /**
     * Please do not call this method directly, use the ->requires syntax. {@link __get()}.
     * @return page_requirements_manager tracks the JavaScript, CSS files, etc. required by this page.
     */
    public function get_requires() {
        global $CFG;
        if (is_null($this->_requires)) {
            $this->_requires = new page_requirements_manager();
        }
        return $this->_requires;
    }

    /**
     * Please do not call this method directly, use the ->cacheable syntax. {@link __get()}.
     * @return boolean can this page be cached by the user's browser.
     */
    public function get_cacheable() {
        return $this->_cacheable;
    }

    /**
     * Please do not call this method directly, use the ->focuscontrol syntax. {@link __get()}.
     * @return string the id of the HTML element to be focussed when the page has loaded.
     */
    public function get_focuscontrol() {
        return $this->_focuscontrol;
    }

    /**
     * Please do not call this method directly, use the ->button syntax. {@link __get()}.
     * @return string the HTML to go where the Turn editing on button normaly goes.
     */
    public function get_button() {
        return $this->_button;
    }

    /**
     * Please do not call this method directly, use the ->theme syntax. {@link __get()}.
     * @return string the initialised theme for this page.
     */
    public function get_theme() {
        if (is_null($this->_theme)) {
            $this->initialise_theme_and_output();
        }
        return $this->_theme;
    }

    /**
     * Please do not call this method directly use the ->periodicrefreshdelay syntax
     * {@link __get()}
     * @return int The periodic refresh delay to use with meta refresh
     */
    public function get_periodicrefreshdelay() {
        return $this->_periodicrefreshdelay;
    }

    /**
     * Please do not call this method directly use the ->opencontainers syntax. {@link __get()}
     * @return xhtml_container_stack tracks XHTML tags on this page that have been opened but not closed.
     *      mainly for internal use by the rendering code.
     */
    public function get_opencontainers() {
        if (is_null($this->_opencontainers)) {
            $this->_opencontainers = new xhtml_container_stack();
        }
        return $this->_opencontainers;
    }

    /**
     * PHP overloading magic to make the $PAGE->course syntax work by redirecting
     * it to the corresponding $PAGE->get_course() method if there is one, and
     * throwing an exception if not.
     *
     *
     */
    public function __get($field) {
        $getmethod = 'get_' . $field;
        if (method_exists($this, $getmethod)) {
            return $this->$getmethod();
        } else {
            throw new coding_exception('Unknown field ' . $field . ' of $PAGE.');
        }
    }

/// Other information getting methods ==========================================

    /**
     * @return boolean should the current user see this page in editing mode.
     * That is, are they allowed to edit this page, and are they currently in
     * editing mode.
     */
    public function user_is_editing() {
        global $USER;
        return !empty($USER->editing) && $this->user_allowed_editing();
    }

    /**
     * @return boolean does the user have permission to edit blocks on this page.
     */
    public function user_can_edit_blocks() {
        return has_capability($this->_blockseditingcap, $this->_context);
    }

    /**
     * @return boolean does the user have permission to see this page in editing mode.
     */
    public function user_allowed_editing() {
        if ($this->_legacypageobject) {
            return $this->_legacypageobject->user_allowed_editing();
        }
        return has_any_capability($this->all_editing_caps(), $this->context);
    }

    /**
     * @return string a description of this page (context, page-type and sub-page.
     */
    public function debug_summary() {
        $summary = 'Context ' . print_context_name($this->context) . ' (context id ' . $this->context->id . '). ';
        $summary .= 'Page type ' . $this->pagetype .  '. ';
        if ($this->subpage) {
            'Sub-page ' . $this->subpage .  '. ';
        }
        return $summary;
    }

/// Setter methods =============================================================

    /**
     * Set the state. The state must be one of that STATE_... constants, and
     * the state is only allowed to advance one step at a time.
     * @param integer $state the new state.
     */
    public function set_state($state) {
        if ($state != $this->_state + 1 || $state > self::STATE_DONE) {
            throw new coding_exception('Invalid state passed to moodle_page::set_state. We are in state ' .
                    $this->_state . ' and state ' . $state . ' was requestsed.');
        }

        if ($state == self::STATE_PRINTING_HEADER) {
            $this->starting_output();
        }

        $this->_state = $state;
    }

    /**
     * Set the current course. This sets both $PAGE->course and $COURSE. It also
     * sets the right theme and locale.
     *
     * Normally you don't need to call this function yourself, require_login will
     * call it for you if you pass a $course to it. You can use this function
     * on pages that do need to call require_login().
     *
     * Sets $PAGE->context to the course context, if it is not already set.
     *
     * @param object the course to set as the global course.
     */
    public function set_course($course) {
        global $COURSE, $PAGE;

        if (empty($course->id)) {
            throw new coding_exception('$course passed to moodle_page::set_course does not look like a proper course object.');
        }

        $this->ensure_theme_not_set();

        if (!empty($this->_course->id) && $this->_course->id != $course->id) {
            $this->_categories = null;
        }

        $this->_course = clone($course);

        if ($this === $PAGE) {
            $COURSE = $this->_course;
            moodle_setlocale();
        }

        if (!$this->_context) {
            $this->set_context(get_context_instance(CONTEXT_COURSE, $this->_course->id));
        }
    }

    /**
     * Set the main context to which this page belongs.
     * @param object $context a context object, normally obtained with get_context_instance.
     */
    public function set_context($context) {
        $this->_context = $context;
    }

    /**
     * The course module that this page belongs to (if it does belong to one).
     *
     * @param objcet $cm a full cm objcet obtained from get_coursemodule_from_id or get_coursemodule_from_instance.
     */
    public function set_cm($cm, $course = null, $module = null) {
        if (!isset($cm->name) || !isset($cm->modname)) {
            throw new coding_exception('The $cm you set on $PAGE must have been obtained with get_coursemodule_from_id or get_coursemodule_from_instance. That is, the ->name and -> modname fields must be present and correct.');
        }
        $this->_cm = $cm;
        if (!$this->_context) {
            $this->set_context(get_context_instance(CONTEXT_MODULE, $cm->id));
        }
        if (!$this->_course || $this->_course->id != $cm->course) {
            if (!$course) {
                global $DB;
                $course = $DB->get_record('course', array('id' => $cm->course));
            }
            if ($course->id != $cm->course) {
                throw new coding_exception('The course you passed to $PAGE->set_cm does not seem to correspond to the $cm.');
            }
            $this->set_course($course);
        }
        if ($module) {
            $this->set_activity_record($module);
        }
    }

    /**
     * @param $module a row from the main database table for the module that this
     * page belongs to. For example, if ->cm is a forum, then you can pass the
     * corresponding row from the forum table here if you have it (saves a database
     * query sometimes).
     */
    public function set_activity_record($module) {
        if (is_null($this->_cm)) {
            throw new coding_exception('You cannot call $PAGE->set_activity_record until after $PAGE->cm has been set.');
        }
        if ($module->id != $this->_cm->instance || $module->course != $this->_course->id) {
            throw new coding_exception('The activity record your are trying to set does not seem to correspond to the cm that has been set.');
        }
        $this->_module = $module;
    }

    /**
     * @param string $pagetype e.g. 'my-index' or 'mod-quiz-attempt'. Normally
     * you do not need to set this manually, it is automatically created from the
     * script name. However, on some pages this is overridden. For example, the
     * page type for coures/view.php includes the course format, for example
     * 'coures-view-weeks'. This gets used as the id attribute on <body> and
     * also for determining which blocks are displayed.
     */
    public function set_pagetype($pagetype) {
        $this->_pagetype = $pagetype;
    }

    /**
     * @param string $generaltype the general type of page this is. For example 'popup', 'home'.
     * This properly defaults to 'normal', so you only need to call this function if
     * you want something different. The exact range of supported page types is not
     * strictly defined, this value is just passed to the theme. However, at the moment
     * only 'normal', 'popup' amd 'home' are used.
     */
    public function set_generaltype($generaltype) {
        $this->_generaltype = $generaltype;
    }

    /**
     * If context->id and pagetype are not enough to uniquely identify this page,
     * then you can set a subpage id as well. For example, the tags page sets
     * @param string $subpage an arbitrary identifier that, along with context->id
     *      and pagetype, uniquely identifies this page.
     */
    public function set_subpage($subpage) {
        if (empty($subpage)) {
            $this->_subpage = '';
        } else {
            $this->_subpage = $subpage;
        }
    }

    /**
     * @param string $class add this class name ot the class attribute on the body tag.
     */
    public function add_body_class($class) {
        if ($this->_state > self::STATE_BEFORE_HEADER) {
            throw new coding_exception('Cannot call moodle_page::add_body_class after output has been started.');
        }
        $this->_bodyclasses[$class] = 1;
    }

    /**
     * @param array $classes this utility method calls add_body_class for each array element.
     */
    public function add_body_classes($classes) {
        foreach ($classes as $class) {
            $this->add_body_class($class);
        }
    }

    /**
     * $param string $title the title that should go in the <head> section of the HTML of this page.
     */
    public function set_title($title) {
        $title = format_string($title);
        $title = str_replace('"', '&quot;', $title);
        $this->_title = $title;
    }

    /**
     * $param string $heading the main heading that should be displayed at the top of the <body>.
     */
    public function set_heading($heading) {
        $this->_heading = format_string($heading);
    }

    /**
     * Set the course category this page belongs to manually. This automatically
     * sets $PAGE->course to be the site coures. You cannot use this method if
     * you have already set $PAGE->course - in that case, the category must be
     * the one that the course belongs to. This also automatically sets the
     * page context to the category context.
     * @param integer $categoryid The id of the category to set.
     */
    public function set_category_by_id($categoryid) {
        global $SITE, $DB;
        if (!is_null($this->_course)) {
            throw new coding_exception('Attempt to manually set the course category when the course has been set. This is not allowed.');
        }
        if (is_array($this->_categories)) {
            throw new coding_exception('Course category has already been set. You are not allowed to change it.');
        }
        $this->ensure_theme_not_set();
        $this->set_course($SITE);
        $this->load_category($categoryid);
        $this->set_context(get_context_instance(CONTEXT_COURSECAT, $categoryid));
    }

    /**
     * Set a different path to use for the 'Moodle docs for this page' link.
     * By default, it uses the pagetype, which is normally the same as the
     * script name. So, for example, for mod/quiz/attempt.php, pagetype is
     * mod-quiz-attempt, and so docspath is mod/quiz/attempt.
     * @param string $path the path to use at the end of the moodle docs URL.
     */
    public function set_docs_path($path) {
        $this->_docspath = $path;
    }

    /**
     * You should call this method from every page to set the cleaned-up URL
     * that should be used to return to this page. Used, for example, by the
     * blocks editing UI to know where to return the user after an action.
     * For example, course/view.php does:
     *      $id = optional_param('id', 0, PARAM_INT);
     *      $PAGE->set_url('course/view.php', array('id' => $id));
     * @param string $url a URL, relative to $CFG->wwwroot.
     * @param array $params paramters to add ot the URL.
     */
    public function set_url($url, $params = array()) {
        global $CFG;
        $this->_url = new moodle_url($CFG->wwwroot . '/' . $url, $params);
        if (is_null($this->_pagetype)) {
            $this->initialise_default_pagetype($url);
        }
        if (!is_null($this->_legacypageobject)) {
            $this->_legacypageobject->set_url($url, $params);
        }
    }

    /**
     * Make sure page URL does not contain the given URL parameter.
     *
     * This should not be necessary if the script has called set_url properly.
     * However, in some situations like the block editing actions; when the URL
     * has been guessed, it will contain dangerous block-related actions.
     * Therefore, the blocks code calls this function to clean up such parameters
     * before doing any redirect.
     * 
     * @param string $param the name of the parameter to make sure is not in the
     * page URL.
     */
    public function ensure_param_not_in_url($param) {
        $discard = $this->url; // Make sure $this->url is lazy-loaded;
        $this->_url->remove_params($param);
    }

    /**
     * There can be alternate versions of some pages (for example an RSS feed version).
     * If such other version exist, call this method, and a link to the alternate
     * version will be included in the <head> of the page.
     *
     * @param $title The title to give the alternate version.
     * @param $url The URL of the alternate version.
     * @param $mimetype The mime-type of the alternate version.
     */
    public function add_alternate_version($title, $url, $mimetype) {
        if ($this->_state > self::STATE_BEFORE_HEADER) {
            throw new coding_exception('Cannot call moodle_page::add_alternate_version after output has been started.');
        }
        $alt = new stdClass;
        $alt->title = $title;
        $alt->url = url;
        $this->_alternateversions[$mimetype] = $alt;
    }

    /**
     * Specify a form control should be focussed when the page has loaded.
     *
     * @param string $controlid the id of the HTML element to be focussed.
     */
    public function set_focuscontrol($controlid) {
        $this->_focuscontrol = $controlid;
    }

    /**
     * Specify a fragment of HTML that goes where the 'Turn editing on' button normally goes.
     *
     * @param string $html the HTML to display there.
     */
    public function set_button($html) {
        $this->_button = $html;
    }

    /**
     * Set the capability that allows users to edit blocks on this page. Normally
     * the default of 'moodle/site:manageblocks' is used, but a few pages like
     * the My Moodle page need to use a different capability like 'moodle/my:manageblocks'.
     * @param string $capability a capability.
     */
    public function set_blocks_editing_capability($capability) {
        $this->_blockseditingcap = $capability;
    }

    /**
     * Some pages let you turn editing on for reasons other than editing blocks.
     * If that is the case, you can pass other capabilitise that let the user
     * edit this page here.
     * @param string|array $capability either a capability, or an array of capabilities.
     */
    public function set_other_editing_capability($capability) {
        if (is_array($capability)) {
            $this->_othereditingcaps = array_unique($this->_othereditingcaps + $capability);
        } else {
            $this->_othereditingcaps[] = $capability;
        }
    }

    /**
     * @return boolean $cacheable can this page be cached by the user's browser.
     */
    public function set_cacheable($cacheable) {
        $this->_cacheable = $cacheable;
    }

    /**
     * Sets the page to periodically refresh
     *
     * This function must be called before $OUTPUT->header has been called or
     * a coding exception will be thrown.
     *
     * @param int $delay Sets the delay before refreshing the page, if set to null
     *                    refresh is cancelled
     */
    public function set_periodic_refresh_delay($delay=null) {
        if ($this->_state > self::STATE_BEFORE_HEADER) {
            throw new coding_exception('You cannot set a periodic refresh delay after the header has been printed');
        }
        if ($delay===null) {
            $this->_periodicrefreshdelay = null;
        } else if (is_int($delay)) {
            $this->_periodicrefreshdelay = $delay;
        }
    }

    /**
     * Force this page to use a particular theme.
     *
     * Please use this cautiously. It is only intended to be used by the themes selector
     * admin page, and theme/styles.php.
     *
     * @param $themename the name of the theme to use.
     */
    public function force_theme($themename) {
        global $PAGE, $THEME;
        $this->ensure_theme_not_set();
        $this->_theme = theme_config::load($themename);
        if ($this === $PAGE) {
            $THEME = $this->_theme;
            // Support legacy code.
            $this->_theme->setup_legacy_pix_paths();
        }
    }

    /**
     * This function sets the $HTTPSPAGEREQUIRED global
     * (used in some parts of moodle to change some links)
     * and calculate the proper wwwroot to be used
     *
     * By using this function properly, we can ensure 100% https-ized pages
     * at our entire discretion (login, forgot_password, change_password)
     */
    public function https_required() {
        global $CFG, $HTTPSPAGEREQUIRED;

        $this->ensure_theme_not_set();

        if (!empty($CFG->loginhttps)) {
            $HTTPSPAGEREQUIRED = true;
            $CFG->httpswwwroot = str_replace('http:', 'https:', $CFG->wwwroot);
            $CFG->httpsthemewww = str_replace('http:', 'https:', $CFG->themewww);
        } else {
            $CFG->httpswwwroot = $CFG->wwwroot;
            $CFG->httpsthemewww = $CFG->themewww;
        }
    }

/// Initialisation methods =====================================================
/// These set various things up in a default way.

    /**
     * This method is called when the page first moves out of the STATE_BEFORE_HEADER
     * state. This is our last change to initialise things.
     */
    protected function starting_output() {
        global $CFG;

        if (!during_initial_install()) {
            $this->blocks->load_blocks();
            if (empty($this->_block_actions_done) && block_process_url_actions($this)) {
                $this->_block_actions_done = true;
                redirect($this->url->out(false, array(), false));
            }
            $this->blocks->create_all_block_instances();
        }

        // If maintenance mode is on, change the page header.
        if (!empty($CFG->maintenance_enabled)) {
            $this->set_button('<a href="' . $CFG->wwwroot . '/' . $CFG->admin .
                    '/settings.php?section=maintenancemode">' . get_string('maintenancemode', 'admin') .
                    '</a> ' . $this->button);

            $title = $this->title;
            if ($title) {
                $title .= ' - ';
            }
            $this->set_title($title . get_string('maintenancemode', 'admin'));
        }

        // Show the messaging popup, if there are messages.
        message_popup_window();

        // Add any stylesheets required using the horrible legacy mechanism.
        if (!empty($CFG->stylesheets)) {
            debugging('Some code on this page is using the horrible legacy mechanism $CFG->stylesheets to include links to ' .
                    'extra stylesheets. This is deprecated. Please use $PAGE->requires->css(...) instead.', DEBUG_DEVELOPER);
            foreach ($CFG->stylesheets as $stylesheet) {
                $this->requires->css($stylesheet, true);
            }
        }

        // Require theme stylesheets.
        $stylesheets = $this->theme->get_stylesheet_urls();
        foreach ($stylesheets as $stylesheet) {
            $this->requires->css($stylesheet, true);
        }

        $this->initialise_standard_body_classes();
    }

    /**
     * Method for use by Moodle core to set up the theme. Do not
     * use this in your own code.
     *
     * Make sure the right theme for this page is loaded. Tell our
     * blocks_manager about the theme block regions, and then, if
     * we are $PAGE, set up the globals $THEME and $OUTPUT.
     */
    public function initialise_theme_and_output() {
        global $OUTPUT, $PAGE, $SITE, $THEME;

        if (!empty($this->_wherethemewasinitialised)) {
            return;
        }

        if (!$this->_course && !during_initial_install()) {
            $this->set_course($SITE);
        }

        if (is_null($this->_theme)) {
            $themename = $this->resolve_theme();
            $this->_theme = theme_config::load($themename);
        }

        $this->_theme->setup_blocks($this->generaltype, $this->blocks);

        if ($this === $PAGE) {
            $THEME = $this->_theme;
            $OUTPUT = $this->_theme->get_renderer('core', $this);
            // Support legacy code.
            $this->_theme->setup_legacy_pix_paths();
        }

        $this->_wherethemewasinitialised = debug_backtrace();
    }

    /**
     * Work out the theme this page should use.
     *
     * This depends on numerous $CFG settings, and the properties of this page.
     *
     * @return string the name of the theme that should be used on this page.
     */
    protected function resolve_theme() {
        global $CFG, $USER, $SESSION;

        if (empty($CFG->themeorder)) {
            $themeorder = array('course', 'category', 'session', 'user', 'site');
        } else {
            $themeorder = $CFG->themeorder;
            // Just in case, make sure we always use the site theme if nothing else matched.
            $themeorder[] = 'site';
        }

        $mnetpeertheme = '';
        if (isloggedin() and isset($CFG->mnet_localhost_id) and $USER->mnethostid != $CFG->mnet_localhost_id) {
            require_once($CFG->dirroot.'/mnet/peer.php');
            $mnetpeer = new mnet_peer();
            $mnetpeer->set_id($USER->mnethostid);
            if ($mnetpeer->force_theme == 1 && $mnetpeer->theme != '') {
                $mnetpeertheme = $mnetpeer->theme;
            }
        }

        $theme = '';
        foreach ($themeorder as $themetype) {
            switch ($themetype) {
                case 'course':
                    if (!empty($CFG->allowcoursethemes) and !empty($this->course->theme)) {
                        return $this->course->theme;
                    }

                case 'category':
                    if (!empty($CFG->allowcategorythemes)) {
                        $categories = $this->categories;
                        foreach ($categories as $category) {
                            if (!empty($category->theme)) {
                                return $category->theme;
                            }
                        }
                    }

                case 'session':
                    if (!empty($SESSION->theme)) {
                        return $SESSION->theme;
                    }

                case 'user':
                    if (!empty($CFG->allowuserthemes) and !empty($USER->theme)) {
                        if ($mnetpeertheme) {
                            return $mnetpeertheme;
                        } else {
                            return $USER->theme;
                        }
                    }

                case 'site':
                    if ($mnetpeertheme) {
                        return $mnetpeertheme;
                    } else {
                        return $CFG->theme;
                    }
            }
        }
    }

    /**
     * Sets ->pagetype from the script name. For example, if the script that was
     * run is mod/quiz/view.php, ->pagetype will be set to 'mod-quiz-view'.
     * @param string $script the path to the script that should be used to
     * initialise ->pagetype. If not passed the $SCRIPT global will be used.
     * If legacy code has set $CFG->pagepath that will be used instead, and a
     * developer warning issued.
     */
    protected function initialise_default_pagetype($script = null) {
        global $CFG, $SCRIPT;

        if (isset($CFG->pagepath)) {
            debugging('Some code appears to have set $CFG->pagepath. That was a horrible deprecated thing. ' .
                    'Don\'t do it! Try calling $PAGE->set_pagetype() instead.');
            $script = $CFG->pagepath;
            unset($CFG->pagepath);
        }

        if (is_null($script)) {
            $script = ltrim($SCRIPT, '/');
            $len = strlen($CFG->admin);
            if (substr($script, 0, $len) == $CFG->admin) {
                $script = 'admin' . substr($script, $len);
            }
        }

        $path = str_replace('.php', '', $script);
        if (substr($path, -1) == '/') {
            $path .= 'index';
        }

        if (empty($path) || $path == 'index') {
            $this->_pagetype = 'site-index';
        } else {
            $this->_pagetype = str_replace('/', '-', $path);
        }
    }

    protected function initialise_standard_body_classes() {
        global $CFG, $USER;

        $pagetype = $this->pagetype;
        if ($pagetype == 'site-index') {
            $this->_legacyclass = 'course';
        } else if (substr($pagetype, 0, 6) == 'admin-') {
            $this->_legacyclass = 'admin';
        } else {
            $this->_legacyclass = substr($pagetype, 0, strrpos($pagetype, '-'));
        }
        $this->add_body_class($this->_legacyclass);

        $this->add_body_classes(get_browser_version_classes());
        $this->add_body_class('dir-' . get_string('thisdirection'));
        $this->add_body_class('lang-' . current_language());
        $this->add_body_class('yui-skin-sam'); // Make YUI happy, if it is used.
        $this->add_body_class($this->url_to_class_name($CFG->wwwroot));

        if (!during_initial_install()) {
            $this->add_body_class('course-' . $this->_course->id);
            $this->add_body_class('context-' . $this->context->id);
        }

        if (!empty($this->_cm)) {
            $this->add_body_class('cmid-' . $this->_cm->id);
        }

        if (!empty($CFG->allowcategorythemes)) {
            $this->ensure_category_loaded();
            foreach ($this->_categories as $catid => $notused) {
                $this->add_body_class('category-' . $catid);
            }
        } else {
            $catid = 0;
            if (is_array($this->_categories)) {
                $catids = array_keys($this->_categories);
                $catid = reset($catids);
            } else if (!empty($this->_course->category)) {
                $catid = $this->_course->category;
            }
            if ($catid) {
                $this->add_body_class('category-' . $catid);
            }
        }

        if (!isloggedin()) {
            $this->add_body_class('notloggedin');
        }

        if (!empty($USER->editing)) {
            $this->add_body_class('editing');
        }

        if (!empty($CFG->blocksdrag)) {
            $this->add_body_class('drag');
        }
    }

    protected function load_activity_record() {
        global $DB;
        if (is_null($this->_cm)) {
            return;
        }
        $this->_module = $DB->get_record($this->_cm->modname, array('id' => $this->_cm->instance));
    }

    protected function ensure_category_loaded() {
        if (is_array($this->_categories)) {
            return; // Already done.
        }
        if (is_null($this->_course)) {
            throw new coding_exception('Attempt to get the course category for this page before the course was set.');
        }
        if ($this->_course->category == 0) {
            $this->_categories = array();
        } else {
            $this->load_category($this->_course->category);
        }
    }

    protected function load_category($categoryid) {
        global $DB;
        $category = $DB->get_record('course_categories', array('id' => $categoryid));
        if (!$category) {
            throw new moodle_exception('unknowncategory');
        }
        $this->_categories[$category->id] = $category;
        $parentcategoryids = explode('/', trim($category->path, '/'));
        array_pop($parentcategoryids);
        foreach (array_reverse($parentcategoryids) as $catid) {
            $this->_categories[$catid] = null;
        }
    }

    protected function ensure_categories_loaded() {
        global $DB;
        $this->ensure_category_loaded();
        if (!is_null(end($this->_categories))) {
            return; // Already done.
        }
        $idstoload = array_keys($this->_categories);
        array_shift($idstoload);
        $categories = $DB->get_records_list('course_categories', 'id', $idstoload);
        foreach ($idstoload as $catid) {
            $this->_categories[$catid] = $categories[$catid];
        }
    }

    protected function ensure_theme_not_set() {
        if (!is_null($this->_theme)) {
            throw new coding_exception('The theme has already been set up for this page ready for output. ' .
                    'Therefore, you can no longer change the theme, or anything that might affect what ' .
                    'the current theme is, for example, the course.',
                    'Stack trace when the theme was set up: ' . format_backtrace($this->_wherethemewasinitialised));
        }
    }

    protected function url_to_class_name($url) {
        $bits = parse_url($url);
        $class = str_replace('.', '-', $bits['host']);
        if (!empty($bits['port'])) {
            $class .= '--' . $bits['port'];
        }
        if (!empty($bits['path'])) {
            $path = trim($bits['path'], '/');
            if ($path) {
                $class .= '--' . str_replace('/', '-', $path);
            }
        }
        return $class;
    }

    protected function all_editing_caps() {
        $caps = $this->_othereditingcaps;
        $caps[] = $this->_blockseditingcap;
        return $caps;
    }

/// Deprecated fields and methods for backwards compatibility ==================

    /**
     * @deprecated since Moodle 2.0 - use $PAGE->pagetype instead.
     * @return string page type.
     */
    public function get_type() {
        debugging('Call to deprecated method moodle_page::get_type. Please use $PAGE->pagetype instead.');
        return $this->get_pagetype();
    }

    /**
     * @deprecated since Moodle 2.0 - use $PAGE->pagetype instead.
     * @return string this is what page_id_and_class used to return via the $getclass parameter.
     */
    function get_format_name() {
        return $this->get_pagetype();
    }

    /**
     * @deprecated since Moodle 2.0 - use $PAGE->course instead.
     * @return object course.
     */
    public function get_courserecord() {
        debugging('Call to deprecated method moodle_page::get_courserecord. Please use $PAGE->course instead.');
        return $this->get_course();
    }

    /**
     * @deprecated since Moodle 2.0
     * @return string this is what page_id_and_class used to return via the $getclass parameter.
     */
    public function get_legacyclass() {
        if (is_null($this->_legacyclass)) {
            $this->initialise_standard_body_classes();
        }
        debugging('Call to deprecated method moodle_page::get_legacyclass.');
        return $this->_legacyclass;
    }

    /**
     * @deprecated since Moodle 2.0 - use $PAGE->blocks->get_regions() instead
     * @return string the places on this page where blocks can go.
     */
    function blocks_get_positions() {
        debugging('Call to deprecated method moodle_page::blocks_get_positions. Use $PAGE->blocks->get_regions() instead.');
        return $this->blocks->get_regions();
    }

    /**
     * @deprecated since Moodle 2.0 - use $PAGE->blocks->get_default_region() instead
     * @return string the default place for blocks on this page.
     */
    function blocks_default_position() {
        debugging('Call to deprecated method moodle_page::blocks_default_position. Use $PAGE->blocks->get_default_region() instead.');
        return $this->blocks->get_default_region();
    }

    /**
     * @deprecated since Moodle 2.0 - no longer used.
     */
    function blocks_get_default() {
        debugging('Call to deprecated method moodle_page::blocks_get_default. This method has no function any more.');
    }

    /**
     * @deprecated since Moodle 2.0 - no longer used.
     */
    function blocks_move_position(&$instance, $move) {
        debugging('Call to deprecated method moodle_page::blocks_move_position. This method has no function any more.');
    }

    /**
     * @deprecated since Moodle 2.0 - use $this->url->params() instead.
     * @return array URL parameters for this page.
     */
    function url_get_parameters() {
        debugging('Call to deprecated method moodle_page::url_get_parameters. Use $this->url->params() instead.');
        return $this->url->params();
    }

    /**
     * @deprecated since Moodle 2.0 - use $this->url->params() instead.
     * @return string URL for this page without parameters.
     */
    function url_get_path() {
        debugging('Call to deprecated method moodle_page::url_get_path. Use $this->url->out(false) instead.');
        return $this->url->out(false);
    }

    /**
     * @deprecated since Moodle 2.0 - use $this->url->out() instead.
     * @return string full URL for this page.
     */
    function url_get_full($extraparams = array()) {
        debugging('Call to deprecated method moodle_page::url_get_full. Use $this->url->out() instead.');
        return $this->url->out($extraparams);
    }

    /**
     * @deprecated since Moodle 2.0 - just a backwards compatibility hook.
     */
    function set_legacy_page_object($pageobject) {
        return $this->_legacypageobject = $pageobject;
    }

    /**
     * @deprecated since Moodle 2.0 - page objects should no longer be doing print_header.
     * @param $_,...
     */
    function print_header($_) {
        if (is_null($this->_legacypageobject)) {
            throw new coding_exception('You have called print_header on $PAGE when there is not a legacy page class present.');
        }
        debugging('You should not longer be doing print_header via a page class.', DEBUG_DEVELOPER);
        $args = func_get_args();
        call_user_func_array(array($this->_legacypageobject, 'print_header'), $args);
    }

    /**
     * @deprecated since Moodle 2.0
     * @return the 'page id'. This concept no longer exists.
     */
    function get_id() {
        debugging('Call to deprecated method moodle_page::get_id(). It should not be necessary any more.', DEBUG_DEVELOPER);
        if (!is_null($this->_legacypageobject)) {
            return $this->_legacypageobject->get_id();
        }
        return 0;
    }

    /**
     * @deprecated since Moodle 2.0
     * @return the 'page id'. This concept no longer exists.
     */
    function get_pageid() {
        debugging('Call to deprecated method moodle_page::get_pageid(). It should not be necessary any more.', DEBUG_DEVELOPER);
        if (!is_null($this->_legacypageobject)) {
            return $this->_legacypageobject->get_id();
        }
        return 0;
    }

    /**
     * @deprecated since Moodle 2.0 - user $PAGE->cm instead.
     * @return $this->cm;
     */
    function get_modulerecord() {
        return $this->cm;
    }
}

/**
 * @deprecated since Moodle 2.0
 * Not needed any more.
 * @param $path the folder path
 * @return array an array of page types.
 */
function page_import_types($path) {
    global $CFG;
    debugging('Call to deprecated function page_import_types.', DEBUG_DEVELOPER);
}

/**
 * @deprecated since Moodle 2.0
 * Do not use this any more. The global $PAGE is automatically created for you.
 * If you need custom behaviour, you should just set properties of that object.
 * @param integer $instance legacy page instance id.
 * @return the global $PAGE object.
 */
function page_create_instance($instance) {
    global $PAGE;
    return page_create_object($PAGE->pagetype, $instance);
}

/**
 * @deprecated since Moodle 2.0
 * Do not use this any more. The global $PAGE is automatically created for you.
 * If you need custom behaviour, you should just set properties of that object.
 */
function page_create_object($type, $id = NULL) {
    global $CFG, $PAGE, $SITE, $ME;
    debugging('Call to deprecated function page_create_object.', DEBUG_DEVELOPER);

    $data = new stdClass;
    $data->pagetype = $type;
    $data->pageid = $id;

    $classname = page_map_class($type);
    if (!$classname) {
        return $PAGE;
    }
    $legacypage = new $classname;
    $legacypage->init_quick($data);

    $course = $PAGE->course;
    if ($course->id != $SITE->id) {
        $legacypage->set_course($course);
    } else {
        try {
            $category = $PAGE->category;
        } catch (coding_exception $e) {
            // Was not set before, so no need to try to set it again.
            $category = false;
        }
        if ($category) {
            $legacypage->set_category_by_id($category->id);
        } else {
            $legacypage->set_course($SITE);
        }
    }

    $legacypage->set_pagetype($type);

    $legacypage->set_url($ME);
    $PAGE->set_url(str_replace($CFG->wwwroot . '/', '', $legacypage->url_get_full()));

    $PAGE->set_pagetype($type);
    $PAGE->set_legacy_page_object($legacypage);
    return $PAGE;
}

/**
 * @deprecated since Moodle 2.0
 * You should not be writing page subclasses any more. Just set properties on the
 * global $PAGE object to control its behaviour.
 */
function page_map_class($type, $classname = NULL) {
    global $CFG;

    static $mappings = array(
        PAGE_COURSE_VIEW => 'page_course',
    );

    if (!empty($type) && !empty($classname)) {
        $mappings[$type] = $classname;
    }

    if (!isset($mappings[$type])) {
        debugging('Page class mapping requested for unknown type: '.$type);
        return null;
    } else if (empty($classname) && !class_exists($mappings[$type])) {
        debugging('Page class mapping for id "'.$type.'" exists but class "'.$mappings[$type].'" is not defined');
        return null;
    }

    return $mappings[$type];
}

/**
 * @deprecated since Moodle 2.0
 * Parent class from which all Moodle page classes derive
 *
 * @package   moodlecore
 * @subpackage pages
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page_base extends moodle_page {
    /**
     * The numeric identifier of the page being described.
     * @var int $id
     */
    var $id             = NULL;

/// Class Functions

    // HTML OUTPUT SECTION

    // SELF-REPORTING SECTION

    // Simple stuff, do not override this.
    function get_id() {
        return $this->id;
    }

    // Initialize the data members of the parent class
    function init_quick($data) {
        $this->id   = $data->pageid;
    }

    function init_full() {
    }
}

/**
 * @deprecated since Moodle 2.0
 * Class that models the behavior of a moodle course.
 * Although this does nothing, this class declaration should be left for now
 * since there may be legacy class doing class page_... extends page_course
 *
 * @package   moodlecore
 * @subpackage pages
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page_course extends page_base {
}

/**
 * @deprecated since Moodle 2.0
 * Class that models the common parts of all activity modules
 *
 * @package   moodlecore
 * @subpackage pages
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page_generic_activity extends page_base {
    // Although this function is deprecated, it should be left here because
    // people upgrading legacy code need to copy it. See
    // http://docs.moodle.org/en/Development:Migrating_your_code_code_to_the_2.0_rendering_API
    function print_header($title, $morenavlinks = NULL, $bodytags = '', $meta = '') {
        global $USER, $CFG;

        $this->init_full();
        $replacements = array(
            '%fullname%' => format_string($this->activityrecord->name)
        );
        foreach ($replacements as $search => $replace) {
            $title = str_replace($search, $replace, $title);
        }

        $buttons = '<table><tr><td>'.update_module_button($this->modulerecord->id, $this->course->id, get_string('modulename', $this->activityname)).'</td>';
        if ($this->user_allowed_editing() && !empty($CFG->showblocksonmodpages)) {
            $buttons .= '<td><form '.$CFG->frametarget.' method="get" action="view.php"><div>'.
                '<input type="hidden" name="id" value="'.$this->modulerecord->id.'" />'.
                '<input type="hidden" name="edit" value="'.($this->user_is_editing()?'off':'on').'" />'.
                '<input type="submit" value="'.get_string($this->user_is_editing()?'blockseditoff':'blocksediton').'" /></div></form></td>';
        }
        $buttons .= '</tr></table>';

        if (empty($morenavlinks)) {
            $morenavlinks = array();
        }
        $navigation = build_navigation($morenavlinks, $this->modulerecord);
        print_header($title, $this->course->fullname, $navigation, '', $meta, true, $buttons, navmenu($this->course, $this->modulerecord), false, $bodytags);
    }
}

?>
