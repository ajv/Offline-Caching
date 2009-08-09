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

define('COMMENT_ERROR_DB', 1);
define('COMMENT_ERROR_INSUFFICIENT_CAPS', 2);
define('COMMENT_ERROR_MODULE_REJECT', 3);

/**
 * comment is class to process moodle comments
 *
 * @package   moodlecore
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

class comment {
    /**
     * @var integer
     */
    private $page;
    /**
     * there may be several comment box in one page
     * so we need a client_id to recognize them
     * @var integer
     */
    private $cid;
    private $contextid;
    /**
     * commentarea is used to specify different
     * parts shared the same itemid
     * @var string
     */
    private $commentarea;
    /**
     * itemid is used to associate with commenting content
     * @var integer
     */
    private $itemid;

    /**
     * this html snippet will be used as a template
     * to build comment content
     * @var string
     */
    private $template;
    private $context;
    private $course;
    /**
     * course module object
     * @var string
     */
    private $cm;
    private $plugintype;
    private $pluginname;
    private $viewcap;
    private $postcap;
    /**
     * to tell comments api where it is used
     * @var string
     */
    private $env;
    /**
     * to costomize link text
     * @var string
     */
    private $linktext;
    private static $nonjs = false;
    // will be used by non-js comments UI
    private static $comment_itemid = null;
    private static $comment_context = null;
    private static $comment_area = null;
    /**
     * Construct function of comment class, initilize
     * class members
     * @param object $options
     */
    public function __construct($options) {
        global $CFG;
        $this->viewcap = false;
        $this->postcap = false;
        if (!empty($options->client_id)) {
            $this->cid = $options->client_id;
        } else {
            $this->cid = uniqid();
        }
        if (!empty($options->context)) {
            $this->context = $options->context;
            $this->contextid = $this->context->id;
        } else if(!empty($options->contextid)) {
            $this->contextid = $options->contextid;
            $this->context = get_context_instance_by_id($this->contextid);
        } else {
            print_error('invalidcontext');
        }
        if (!empty($options->area)) {
            $this->commentarea = $options->area;
        }
        if (!empty($options->itemid)) {
            $this->itemid = $options->itemid;
        }
        if (!empty($options->env)) {
            $this->env = $options->env;
        } else {
            $this->env = '';
        }

        if (!empty($options->linktext)) {
            $this->linktext = $options->linktext;
        } else {
            $this->linktext = get_string('comments');
        }

        $this->_setup_plugin();

        $this->options = new stdclass;
        $this->options->context     = $this->context;
        $this->options->commentarea = $this->commentarea;
        $this->options->itemid      = $this->itemid;

        // load template
        $this->template = <<<EOD
<div class="comment-userpicture">___picture___</div>
<div class="comment-content">
    ___name___ - <span>___time___</span>
    <div>___content___</div>
</div>
EOD;
        if (!empty($this->plugintype)) {
            $this->template = plugin_callback($this->plugintype, $this->pluginname, FEATURE_COMMENT, 'template', $this->options, $this->template);
        }

        // setting post and view permissions
        $this->_check_permissions();

        // setup course
        if (!empty($options->course)) {
            $this->course   = $options->course;
        } else if (!empty($options->courseid)) {
            $courseid = $options->courseid;
            $this->_setup_course($courseid);
        } else {
            $courseid = SITEID;
            $this->_setup_course($courseid);
        }

        unset($options);
    }

    /**
     * Print required YUI libraries, must be called before html head printed
     * @return boolean
     */
    public static function js() {
        global $PAGE, $CFG;
        // setup variables for non-js interface
        self::$nonjs = optional_param('nonjscomment', '', PARAM_ALPHA);
        self::$comment_itemid  = optional_param('comment_itemid',  '', PARAM_INT);
        self::$comment_context = optional_param('comment_context', '', PARAM_INT);
        self::$comment_area    = optional_param('comment_area',    '', PARAM_ALPHAEXT);

        $PAGE->requires->yui_lib('yahoo')->in_head();
        $PAGE->requires->yui_lib('dom')->in_head();
        $PAGE->requires->yui_lib('event')->in_head();
        $PAGE->requires->yui_lib('animation')->in_head();
        $PAGE->requires->yui_lib('json')->in_head();
        $PAGE->requires->yui_lib('connection')->in_head();
        $PAGE->requires->js('comment/comment.js')->in_head();
        $PAGE->requires->string_for_js('addcomment', 'moodle');
        $PAGE->requires->string_for_js('deletecomment', 'moodle');
    }

    private function _setup_course($courseid) {
        global $COURSE, $DB;
        if (!empty($this->course)) {
            // already set, stop
            return;
        }
        if ($courseid == $COURSE->id) {
            $this->course = $COURSE;
        } else if (!$this->course = $DB->get_record('course', array('id'=>$courseid))) {
            $this->course = null;
        }
    }

    /**
     * Setting module info
     *
     */
    private function _setup_plugin() {
        global $DB;
        // blog needs to set env as "blog"
        if ($this->env == 'blog') {
            $this->plugintype = 'moodle';
            $this->pluginname = 'blog';
        }
        // tag page needs to set env as "tag"
        if ($this->env == 'tag') {
            $this->plugintype = 'moodle';
            $this->pluginname = 'tag';
        }
        if ($this->context->contextlevel == CONTEXT_BLOCK) {
            if ($block = $DB->get_record('block_instances', array('id'=>$this->context->instanceid))) {
                $this->plugintype = 'block';
                $this->pluginname = $block->blockname;
            }
        }
        if ($this->context->contextlevel == CONTEXT_MODULE) {
            $this->plugintype = 'mod';
            $this->cm = get_coursemodule_from_id('', $this->context->instanceid);
            $this->_setup_course($this->cm->course);
            $this->modinfo = get_fast_modinfo($this->course);
            $this->pluginname = $this->modinfo->cms[$this->cm->id]->modname;
        }
    }

    /**
     * check posting comments permission
     * It will check based on user roles and ask modules
     * If you need to check permission by modules, a
     * function named $pluginname_check_comment_post must be implemented
     */
    private function _check_permissions() {
        global $CFG;
        $this->postcap = has_capability('moodle/comment:post', $this->context);
        $this->viewcap = has_capability('moodle/comment:view', $this->context);
        if (!empty($this->plugintype)) {
            $permissions = plugin_callback($this->plugintype, $this->pluginname, FEATURE_COMMENT, 'permissions', $this->options, array('post'=>true, 'view'=>true));
            $this->postcap = $this->postcap && $permissions['post'];
            $this->viewcap = $this->viewcap && $permissions['view'];
        }
    }

    /**
     * Prepare comment code in html
     * @param  boolean $return
     * @return mixed
     */
    public function init($return = true) {
        global $CFG, $COURSE, $PAGE;

        $this->link = qualified_me();
        $murl = new moodle_url($this->link);
        $murl->remove_params('nonjscomment');
        $murl->param('nonjscomment', 'true');
        $murl->param('comment_itemid', $this->options->itemid);
        $murl->param('comment_context', $this->options->context->id);
        $murl->param('comment_area', $this->options->commentarea);
        $murl->remove_params('page');
        $this->link = $murl->out();
        if ($this->env === 'block_comments') {
            // auto expends comments
            $PAGE->requires->js_function_call('view_comments', array($this->cid, $this->commentarea, $this->itemid, 0))->on_dom_ready();
            $PAGE->requires->js_function_call('comment_hide_link', array($this->cid))->on_dom_ready();
        }

        if (!empty(self::$nonjs)) {
            return $this->print_comments($this->page, $return);
        }
        $strsubmit = get_string('submit');
        $strcancel = get_string('cancel');
        $sesskey = sesskey();

        // print html template
        if (empty($CFG->commentcommentcode) && !empty($this->viewcap)) {
            echo '<div style="display:none" id="cmt-tmpl">' . $this->template . '</div>';

            // shared parameters for commenting
            $params = new stdclass;
            $params->courseid = $this->course->id;
            $params->contextid = $this->contextid;
            $PAGE->requires->data_for_js('comment_params', $params);

            $CFG->commentcommentcode = true;
        }

        if (!empty($this->viewcap)) {
            // print commenting icon and tooltip
            $html = <<<EOD
<a id="comment-link-{$this->cid}" onclick="return view_comments('{$this->cid}', '{$this->commentarea}', '{$this->itemid}', 0)" href="{$this->link}">
<img id="comment-img-{$this->cid}" src="{$CFG->wwwroot}/pix/t/collapsed.png" alt="{$this->linktext}" title="{$this->linktext}" />
<span>{$this->linktext}</span>
</a>
<div id="comment-ctrl-{$this->cid}" class="comment-ctrl">
    <div class="comment-list-wrapper">
            <ul id="comment-list-{$this->cid}" class="comment-list">
            </ul>
    </div>
    <div id="comment-pagination-{$this->cid}" class="comment-pagination"></div>
EOD;

            // print posting textarea
            if (!empty($this->postcap)) {
                $html .= <<<EOD
<div class='comment-area'>
    <div class="bd">
        <form method="POST" id="comment-form-{$this->cid}" action="{$CFG->wwwroot}/comment/comment_post.php">
        <textarea name="content" rows="1" id="dlg-content-{$this->cid}"></textarea>
        <input type="hidden" name="contextid" value="$this->contextid" />
        <input type="hidden" name="action" value="add" />
        <input type="hidden" name="area" value="$this->commentarea" />
        <input type="hidden" name="itemid" value="$this->itemid" />
        <input type="hidden" name="courseid" value="{$this->course->id}" />
        <input type="hidden" name="sesskey" value="{$sesskey}" />
        <input type="hidden" name="client_id" value="$this->cid" />
        </form>
    </div>
    <div class="fd" id="comment-action-{$this->cid}">
        <a href="###" onclick="post_comment('{$this->cid}')"> {$strsubmit} </a>
EOD;
        if ($this->env != 'block_comments') {
            $html .= <<<EOD
        <span> | </span>
        <a href="###" onclick="view_comments('{$this->cid}')"> {$strcancel} </a>
EOD;
        }

        $html .= <<<EOD
    </div>
</div>
<div style="clear:both"></div>
EOD;
            }

            $html .= <<<EOD
</div>
EOD;
        } else {
            $html = '';
        }

        if ($return) {
            return $html;
        } else {
            echo $html;
        }
    }

    /**
     * Return matched comments
     * @param  int $page
     * @return mixed
     */
    public function get_comments($page = '') {
        global $DB, $CFG, $USER, $OUTPUT;
        if (empty($this->viewcap)) {
            return false;
        }
        // TODO: find a apropriate add page to add this option
        $CFG->commentsperpage = 15;
        if (!is_numeric($page)) {
            $page = 0;
        }
        $this->page = $page;
        $params = array();
        $start = $page * $CFG->commentsperpage;
        $sql = "SELECT c.id, c.userid, c.content, c.format, c.timecreated, u.picture, u.imagealt, u.username, u.firstname, u.lastname
            FROM {comments} c, {user} u WHERE u.id=c.userid AND c.contextid=? AND c.commentarea=? AND c.itemid=?
            ORDER BY c.timecreated DESC";
        $params[] = $this->contextid;
        $params[] = $this->commentarea;
        $params[] = $this->itemid;

        $comments = array();
        $candelete = has_capability('moodle/comment:delete', $this->context);
        if ($records = $DB->get_records_sql($sql, $params, $start, $CFG->commentsperpage)) {
            foreach ($records as &$c) {
                $url = $CFG->httpswwwroot.'/user/view.php?id='.$c->userid.'&amp;course='.$this->course->id;
                $c->username = '<a href="'.$url.'">'.fullname($c).'</a>';
                $c->time = userdate($c->timecreated, get_string('strftimerecent', 'langconfig'));
                $user = new stdclass;
                $user->id = $c->userid;
                $user->picture = $c->picture;
                $user->firstname = $c->firstname;
                $user->lastname  = $c->lastname;
                $user->imagealt  = $c->imagealt;
                $c->content = format_text($c->content, $c->format);
                $userpic = new user_picture();
                $userpic->user = $user;
                $userpic->courseid = $this->course->id;
                $userpic->link = true;
                $userpic->size = 18;
                $userpic->alttext = true;
                $c->avatar = $OUTPUT->user_picture($userpic);
                if (($USER->id == $c->userid) || !empty($candelete)) {
                    $c->delete = true;
                }
                $comments[] = $c;
            }
        }

        if (!empty($this->plugintype)) {
            // moodle module will filter comments
            plugin_callback($this->plugintype, $this->pluginname, FEATURE_COMMENT, 'display', array(&$comments, $this->options));
        }

        return $comments;
    }

    public function get_pagination($page = 0) {
        global $DB, $CFG, $OUTPUT;
        if (!$count = $DB->count_records_sql("SELECT COUNT(*) from {comments} c, {user} u WHERE u.id=c.userid AND c.contextid=? AND c.commentarea=? AND c.itemid=?", array($this->contextid, $this->commentarea, $this->itemid))) {
        }
        $pages = (int)ceil($count/$CFG->commentsperpage);
        if ($pages == 1 || $pages == 0) {
            return '';
        }
        if (!empty(self::$nonjs)) {
            // used in non-js interface
            return $OUTPUT->paging_bar(moodle_paging_bar::make($count, $page, $CFG->commentsperpage, $this->link));
        } else {
            // return ajax paging bar
            $str = '';
            $str .= '<div class="comment-paging">';
            for ($p=0; $p<$pages; $p++) {
                $extra = '';
                if ($p == $page) {
                    $extra = ' style="border:1px solid grey" ';
                }
                $str .= '<a class="pageno" href="###"'.$extra.' onclick="get_comments(\''.$this->cid.'\', \''.$this->commentarea.'\', \''.$this->itemid.'\', \''.$p.'\')">'.($p+1).'</a> ';
            }
            $str .= '</div>';
        }
        return $str;
    }

    /**
     * Add a new comment
     * @param string $content
     * @return mixed
     */
    public function add($content) {
        global $CFG, $DB, $USER;
        if (empty($this->postcap)) {
            return COMMENT_ERROR_INSUFFICIENT_CAPS;
        }
        $now = time();
        $newcmt = new stdclass;
        $newcmt->contextid    = $this->contextid;
        $newcmt->commentarea  = $this->commentarea;
        $newcmt->itemid       = $this->itemid;
        $newcmt->content      = $content;
        $newcmt->format       = FORMAT_MOODLE;
        $newcmt->userid       = $USER->id;
        $newcmt->timecreated  = $now;

        if (!empty($this->plugintype)) {
            // moodle module will check content
            $ret = plugin_callback($this->plugintype, $this->pluginname, FEATURE_COMMENT, 'add', array(&$newcmt, $this->options), true);
            if (!$ret) {
                return COMMENT_ERROR_MODULE_REJECT;
            }
        }

        $cmt_id = $DB->insert_record('comments', $newcmt);
        if (!empty($cmt_id)) {
            $newcmt->id = $cmt_id;
            $newcmt->time = userdate($now, get_string('strftimerecent', 'langconfig'));
            $newcmt->username = fullname($USER);
            $newcmt->content = format_text($newcmt->content);
            $newcmt->avatar = print_user_picture($USER, $this->course->id, NULL, 16, true);
            return $newcmt;
        } else {
            return COMMENT_ERROR_DB;
        }
    }

    /**
     * Delete a comment
     * @param  int $commentid
     * @return mixed
     */
    public function delete($commentid) {
        global $DB, $USER;
        $candelete = has_capability('moodle/comment:delete', $this->context);
        if (!$comment = $DB->get_record('comments', array('id'=>$commentid))) {
            return COMMENT_ERROR_DB;
        }
        if (!($USER->id == $comment->userid || !empty($candelete))) {
            return COMMENT_ERROR_INSUFFICIENT_CAPS;
        }
        return $DB->delete_records('comments', array('id'=>$commentid));
    }

    public function print_comments($page = 0, $return = true) {
        global $DB, $CFG;
        $html = '';
        if (!(self::$comment_itemid == $this->options->itemid &&
            self::$comment_context == $this->options->context->id &&
            self::$comment_area == $this->options->commentarea)) {
            $page = 0;
        }
        $comments = $this->get_comments($page);

        $html .= '<h3>'.get_string('comments').'</h3>';
        $html .= "<ul id='comment-list-$this->cid' class='comment-list'>";
        $results = array();
        $list = '';
        foreach ($comments as $cmt) {
            $list = $this->print_comment($cmt, $this->contextid, $this->commentarea, $this->itemid) . $list;
        }
        $html .= $list;
        $html .= '</ul>';
        $html .= $this->get_pagination($page);
        $sesskey = sesskey();
        $returnurl = qualified_me();
        $strsubmit = get_string('submit');
        $html .= <<<EOD
<form method="POST" action="{$CFG->wwwroot}/comment/comment_post.php">
<textarea name="content" rows="1"></textarea>
<input type="hidden" name="contextid" value="$this->contextid" />
<input type="hidden" name="action" value="add" />
<input type="hidden" name="area" value="$this->commentarea" />
<input type="hidden" name="itemid" value="$this->itemid" />
<input type="hidden" name="courseid" value="{$this->course->id}" />
<input type="hidden" name="sesskey" value="{$sesskey}" />
<input type="hidden" name="returnurl" value="{$returnurl}" />
<input type="submit" value="{$strsubmit}" />
</form>
EOD;
        if ($return) {
            return $html;
        } else {
            echo $html;
        }
    }

    public function print_comment($cmt) {
        $patterns = array();
        $replacements = array();

        $patterns[] = '___picture___';
        $patterns[] = '___name___';
        $patterns[] = '___content___';
        $patterns[] = '___time___';
        $replacements[] = $cmt->avatar;
        $replacements[] = fullname($cmt);
        $replacements[] = $cmt->content;
        $replacements[] = userdate($cmt->timecreated, get_string('strftimerecent', 'langconfig'));

        // use html template to format a single comment.
        return str_replace($patterns, $replacements, $this->template);
    }
}

