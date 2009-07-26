<?php

require_once($CFG->libdir . '/commentlib.php');

class block_comments extends block_base {

    function init() {
        $this->title = get_string('comments');
        $this->version = 2009072000;
    }

    function specialization() {
        // require js for commenting
        comment::js();
    }
    function applicable_formats() {
        return array('all' => true);
    }

    function instance_allow_multiple() {
        return false;
    }

    function get_content() {
        if ($this->content !== NULL) {
            return $this->content;
        }
        if (empty($this->instance)) {
            return null;
        }
        $this->content->footer = '';
        $this->content->text = '';
        if (isloggedin() && !isguestuser()) {   // Show the block
            $cmt = new stdclass;
            $cmt->context   = $this->instance->context;
            $cmt->area      = 'block_comments';
            $cmt->itemid    = $this->instance->id;
            $cmt->course    = $this->page->course;
            // this is a hack to adjust commenting UI
            // in block_comments 
            $cmt->env       = 'block_comments';
            $cmt->linktext  = get_string('showcomments');
            $comment = new comment($cmt);
            $this->content = new stdClass;
            $this->content->text = $comment->init(true);
            $this->content->footer = '';

        }
        return $this->content;
    }
}
