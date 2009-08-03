<?PHP //$Id$

include_once($CFG->dirroot . '/course/lib.php');

class block_course_list extends block_list {
    function init() {
        $this->title = get_string('courses');
        $this->version = 2007101509;
    }
    
    function has_config() {
        return true;
    }

    function get_content() {
        global $CFG, $USER, $DB, $OUTPUT;

        if($this->content !== NULL) {
            return $this->content;
        }

        $this->content = new stdClass;
        $this->content->items = array();
        $this->content->icons = array();
        $this->content->footer = '';

        $icon  = "<img src=\"" . $OUTPUT->old_icon_url('i/course') . "\"".
                 " class=\"icon\" alt=\"".get_string("coursecategory")."\" />";
        $networkicon  = '<img src="' . $OUTPUT->old_icon_url('i/mnethost') . "\"".
                 " class=\"icon\" alt=\"".get_string('course')."\" />";
       
        $adminseesall = true;

        $mneturl = array($CFG->wwwroot . '/auth/mnet/jump.php?hostid=', '&wantsurl=' . urlencode('/course/view.php?id='));
        $localurl = $CFG->wwwroot . '/course/view.php?id=';
        if (isset($CFG->block_course_list_adminview)) {
           if ( $CFG->block_course_list_adminview == 'own'){
               $adminseesall = false;
           }
        }

        if (empty($CFG->disablemycourses) and 
            !empty($USER->id) and 
            !(has_capability('moodle/course:update', get_context_instance(CONTEXT_SYSTEM)) and $adminseesall) and
            !isguest()) {    // Just print My Courses
            if ($courses = get_my_courses($USER->id, 'visible DESC, fullname ASC')) {
                foreach ($courses as $course) {
                    if ($course->id == SITEID) {
                        continue;
                    }
                    $linkclasses = $course->visible ? "" : "dimmed";
                    if (empty($course->mnetpeer)
                            || empty($course->remotecourseid)
                            || has_capability('moodle/course:seemnetshell', $course->context)) {
                        $linkclasses .= ' local';
                        $contentlink = $localurl . $course->id;
                        $this->content->icons[]=$icon;
                    } else {
                        $linkclasses .= ' remote';
                        $contentlink = $mneturl[0] . $course->mnetpeer . $mneturl[1] . $course->remotecourseid;
                        $this->content->icons[]=$networkicon;
                    }
                    $linkcss = ' classes="' . $linkclasses . '" ';
                    $contentitem = '<a ' . $linkcss . 'title="' . format_string($course->shortname) . '" href="';
                    $contentitem .= $contentlink . '">' . format_string($course->fullname) . '</a>';
                    $this->content->items[] = $contentitem;
                }
                $this->title = get_string('mycourses');
            /// If we can update any course of the view all isn't hidden, show the view all courses link
                if (has_capability('moodle/course:update', get_context_instance(CONTEXT_SYSTEM)) || empty($CFG->block_course_list_hideallcourseslink)) {
                    $this->content->footer = "<a href=\"$CFG->wwwroot/course/index.php\">".get_string("fulllistofcourses")."</a> ...";
                }
            }
            if ($this->content->items) { // make sure we don't return an empty list
                return $this->content;
            }
        }

        $categories = get_categories("0");  // Parent = 0   ie top-level categories only
        if ($categories) {   //Check we have categories
            if (count($categories) > 1 || (count($categories) == 1 && $DB->count_records('course') > 200)) {     // Just print top level category links
                foreach ($categories as $category) {
                    $linkcss = $category->visible ? "" : " class=\"dimmed\" ";
                    $this->content->items[]="<a $linkcss href=\"$CFG->wwwroot/course/category.php?id=$category->id\">" . format_string($category->name) . "</a>";
                    $this->content->icons[]=$icon;
                }
            /// If we can update any course of the view all isn't hidden, show the view all courses link
                if (has_capability('moodle/course:update', get_context_instance(CONTEXT_SYSTEM)) || empty($CFG->block_course_list_hideallcourseslink)) {
                    $this->content->footer .= "<a href=\"$CFG->wwwroot/course/index.php\">".get_string('fulllistofcourses').'</a> ...';
                }
                $this->title = get_string('categories');
            } else {                          // Just print course names of single category
                $category = array_shift($categories);
                $courses = get_courses($category->id);

                if ($courses) {
                    foreach ($courses as $course) {
                        $linkcss = $course->visible ? "" : " class=\"dimmed\" ";

                        $this->content->items[]="<a $linkcss title=\""
                                   . format_string($course->shortname)."\" ".
                                   "href=\"$CFG->wwwroot/course/view.php?id=$course->id\">" 
                                   .  format_string($course->fullname) . "</a>";
                        $this->content->icons[]=$icon;
                    }
                /// If we can update any course of the view all isn't hidden, show the view all courses link
                    if (has_capability('moodle/course:update', get_context_instance(CONTEXT_SYSTEM)) || empty($CFG->block_course_list_hideallcourseslink)) {
                        $this->content->footer .= "<a href=\"$CFG->wwwroot/course/index.php\">".get_string('fulllistofcourses').'</a> ...';
                    }
                } else {
                    
                    $this->content->icons[] = '';
                    $this->content->items[] = get_string('nocoursesyet');
                    if (has_capability('moodle/course:create', get_context_instance(CONTEXT_COURSECAT, $category->id))) {
                        $this->content->footer = '<a href="'.$CFG->wwwroot.'/course/edit.php?category='.$category->id.'">'.get_string("addnewcourse").'</a> ...';
                    }
                }
                $this->title = get_string('courses');
            }
        }

        return $this->content;
    }
}

?>
