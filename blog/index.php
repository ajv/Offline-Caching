<?php // $Id$

/**
 * file index.php
 * index page to view blogs. if no blog is specified then site wide entries are shown
 * if a blog id is specified then the latest entries from that blog are shown
 */

require_once('../config.php');
require_once($CFG->dirroot .'/blog/lib.php');

$id           = optional_param('id', 0, PARAM_INT);
$start        = optional_param('formstart', 0, PARAM_INT);
$userid       = optional_param('userid', 0, PARAM_INT);
$tag          = optional_param('tag', '', PARAM_NOTAGS);
$tagid        = optional_param('tagid', 0, PARAM_INT);
$postid       = optional_param('postid', 0, PARAM_INT);
$listing_type = optional_param('listing_type', '', PARAM_ALPHA);
$listing_id   = optional_param('listing_id', null, PARAM_INT);
$edit         = optional_param('edit', -1, PARAM_BOOL);
$courseid     = optional_param('courseid', 0, PARAM_INT); // needed for user tabs and course tracking

//correct tagid if a text tag is provided as a param
if (!empty($tag)) {  //text tag parameter takes precedence
    if ($tagrec = $DB->get_record_sql("SELECT * FROM {tag} WHERE name LIKE ?", array($tag))) {
        $tagid = $tagrec->id;
    } else {
        unset($tagid);
    }
}

//add courseid if modid or groupid is specified
if (!empty($modid) and empty($courseid)) {
    $courseid = $DB->get_field('course_modules', 'course', array('id'=>$modid));
}

if (!empty($groupid) and empty($courseid)) {
    $courseid = $DB->get_field('groups', 'courseid', array('id'=>$groupid));
}

if (empty($CFG->bloglevel)) {
    print_error('blogdisable', 'blog');
}

$sitecontext = get_context_instance(CONTEXT_SYSTEM);

// change block edit staus if not guest and logged in
if (isloggedin() and !isguest() and $edit != -1) {
    $USER->editing = $edit;
}

if (!$userid and has_capability('moodle/blog:view', $sitecontext) and $CFG->bloglevel > BLOG_USER_LEVEL) {
    if ($postid) {
        if (!$postobject = $DB->get_record('post', array('module'=>'blog', 'id'=>$postid))) {
            print_error('nosuchentry', 'blog');
        }
        $userid = $postobject->userid;
    }
} else if (!$userid) {
    // user might have capability to write blogs, but not read blogs at site level
    // users might enter this url manually without parameters
    $userid = $USER->id;
}
/// check access and prepare filters

if (!empty($modid)) {  //check mod access
    if ($CFG->bloglevel < BLOG_SITE_LEVEL) {
        print_error(get_string('nocourseblogs', 'blog'));
    }
    if (!$mod = $DB->get_record('course_modules', array('id' => $modid))) {
        print_error(get_string('invalidmodid', 'blog'));
    }
    $courseid = $mod->course;
}

if ((empty($courseid) ? true : $courseid == SITEID) and empty($userid)) {  //check site access
    if ($CFG->bloglevel < BLOG_SITE_LEVEL) {
        print_error('siteblogdisable', 'blog');
    }
    if ($CFG->bloglevel < BLOG_GLOBAL_LEVEL) {
        require_login();
    }
    if (!has_capability('moodle/blog:view', $sitecontext)) {
        print_error('cannotviewsiteblog', 'blog');
    }

    $COURSE = $DB->get_record('course', array('format'=>'site'));
    $courseid = $COURSE->id;
}

if (!empty($courseid)) {
    if (!$course = $DB->get_record('course', array('id'=>$courseid))) {
        print_error('invalidcourseid');
    }

    $courseid = $course->id;
    $coursecontext = get_context_instance(CONTEXT_COURSE, $course->id);

    require_login($course);

    if (!has_capability('moodle/blog:view', $coursecontext)) {
        print_error('cannotviewcourseblog', 'blog');
    }
} else {
    $coursecontext = get_context_instance(CONTEXT_COURSE, SITEID);
}

if (!empty($groupid)) {
    if ($CFG->bloglevel < BLOG_SITE_LEVEL) {
        print_error('groupblogdisable', 'blog');
    }

        // fix for MDL-9268
    if (! $group = groups_get_group($groupid)) { //TODO:check.
        print_error(get_string('invalidgroupid', 'blog'));
    }

    if (!$course = $DB->get_record('course', array('id'=>$group->courseid))) {
        print_error(get_string('invalidcourseid', 'blog'));
    }

    $coursecontext = get_context_instance(CONTEXT_COURSE, $course->id);
    $courseid = $course->id;
    require_login($course);

    if (!has_capability('moodle/blog:view', $coursecontext)) {
        print_error(get_string('cannotviewcourseorgroupblog', 'blog'));
    }

    if (groups_get_course_groupmode($course) == SEPARATEGROUPS and !has_capability('moodle/site:accessallgroups', $coursecontext)) {
        if (!groups_is_member($groupid)) {
            print_error('notmemberofgroup');
        }
    }
}

if (!empty($user)) {
    if ($CFG->bloglevel < BLOG_USER_LEVEL) {
        print_error('blogdisable', 'blog');
    }

    if (!$user = $DB->get_record('user', array('id'=>$userid))) {
        print_error('invaliduserid');
    }

    if ($user->deleted) {
        print_header();
        echo $OUTPUT->heading(get_string('userdeleted'));
        echo $OUTPUT->footer();
        die;
    }

    if ($USER->id == $userid) {
        if (!has_capability('moodle/blog:create', $sitecontext)
          and !has_capability('moodle/blog:view', $sitecontext)) {
            print_error('donothaveblog', 'blog');
        }
    } else {
        $personalcontext = get_context_instance(CONTEXT_USER, $userid);

        if (!has_capability('moodle/blog:view', $sitecontext) and !has_capability('moodle/user:readuserblogs', $personalcontext)) {
            print_error('cannotviewuserblog', 'blog');
        }

        if (!blog_user_can_view_user_post($userid)) {
            print_error('cannotviewcourseblog', 'blog');
        }
    }
}

if (empty($courseid)) {
    $courseid = SITEID;
}

if(!empty($postid)) {
    $filters['post'] = $postid;
}

if(!empty($courseid)) {
    $filters['course'] = $courseid;
}

if(!empty($modid)) {
    $filters['mod'] = $modid;
}

if(!empty($groupid)) {
    $filters['group'] = $groupid;
}

if(!empty($userid)) {
    $filters['user'] = $userid;
}

if(!empty($tagid)) {
    $filters['tag'] = $tagid;
}

$PAGE->title = get_string('blog');
include($CFG->dirroot .'/blog/header.php');

blog_print_html_formatted_entries($postid, $filtertype, $filterselect, $tagid, $tag);

add_to_log($courseid, 'blog', 'view', 'index.php?filtertype='.$filtertype.'&amp;filterselect='.$filterselect.'&amp;postid='.$postid.'&amp;tagid='.$tagid.'&amp;tag='.$tag, 'view blog entry');

include($CFG->dirroot .'/blog/footer.php');

echo $OUTPUT->footer();

?>
