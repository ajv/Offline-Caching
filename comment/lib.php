<?php

/**
 * comment_manager is class to manage moodle comments
 *
 * @package   moodlecore
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class comment_manager {

    /**
     * Return comments by pages
     * @param int $page 
     * @return mixed
     */
    function get_comments($page) {
        global $DB, $CFG, $USER;
        $params = array();
        if ($page == 0) {
            $start = 0;
        } else {
            $start = $page*$this->perpage;
        }
        $sql = "SELECT c.id, c.contextid, c.itemid, c.commentarea, c.userid, c.content, u.username, u.firstname, u.lastname, c.timecreated 
            FROM {comments} c, {user} u
            WHERE u.id=c.userid ORDER BY c.timecreated ASC";

        $comments = array();
        if ($records = $DB->get_records_sql($sql, array(), $start, $this->perpage)) {
            foreach ($records as $item) {
                $item->username = fullname($item);
                $item->time = userdate($item->timecreated);
                $item->content = format_text($item->content);
                $comments[] = $item;
                unset($item->firstname);
                unset($item->lastname);
                unset($item->timecreated);
            }
        }

        return $comments;
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

    private function _setup_plugin($comment) {
        global $DB;
        $this->context = get_context_instance_by_id($comment->contextid);
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
     * Print comments
     * @param int $page 
     */
    function print_comments($page=0) {
        global $CFG, $OUTPUT, $DB;
        $this->perpage = 10;
        $count = $DB->count_records_sql('SELECT COUNT(*) FROM {comments} c');
        $comments = $this->get_comments($page);
        $table = new stdclass;
        $table->head = array ('<input type="checkbox" id="comment_select_all"/>', 'author', 'content', 'action');
        $table->align = array ('left', 'left', 'left', 'left');
        $table->width = "95%";
        $table->data = array();
        foreach ($comments as $c) {
            $this->_setup_plugin($c);
            if (!empty($this->plugintype)) {
                $url = plugin_callback($this->plugintype, $this->pluginname, FEATURE_COMMENT, 'url', array($c));
            }
            $checkbox = '<input type="checkbox" name="comments" value="'. $c->id .'" />';
            $action = '';
            $action .= "<a href='{$CFG->wwwroot}/comment/index.php?action=delete&amp;sesskey=".sesskey()."&amp;commentid={$c->id}'>".get_string('delete').'</a>';
            $action .= "<br />";
            if (!empty($url)) {
                $action .= "<a target='_blank' href='{$url}'>".get_string('commentincontext').'</a>';
            }
            $table->data[] = array($checkbox, $c->username, $c->content, $action);
        } 
        print_table($table);
        print_paging_bar($count, $page, $this->perpage, $CFG->wwwroot.'/comment/index.php?', 'page');
    }

    /**
     * delete a comment
     * @param int $commentid 
     */
    public function delete_comment($commentid) {
        global $DB;
        if ($comment = $DB->get_record('comments', array('id'=>$commentid))) {
            return $DB->delete_records('comments', array('id'=>$commentid));
        }
        return false;
    }
    /**
     * delete comments
     * @param int $commentid 
     */
    public function delete_comments($list) {
        global $DB;
        $ids = explode('-', $list);
        foreach ($ids as $id) {
            if (is_int((int)$id)) {
                if ($comment = $DB->get_record('comments', array('id'=>$id))) {
                    $DB->delete_records('comments', array('id'=>$comment->id));
                }
            }
        }
        return true;
    }
}
