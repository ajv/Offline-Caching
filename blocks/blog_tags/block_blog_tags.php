<?PHP //$Id$

define('BLOGDEFAULTTIMEWITHIN', 90);
define('BLOGDEFAULTNUMBEROFTAGS', 20);
define('BLOGDEFAULTSORT', 'name');

require_once($CFG->dirroot .'/blog/lib.php');

class block_blog_tags extends block_base {
    function init() {
        $this->version = 2007101509;
        $this->title = get_string('blocktagstitle', 'blog');
    }

    function instance_allow_multiple() {
        return true;
    }

    function has_config() {
        return false;
    }

    function applicable_formats() {
        return array('all' => true, 'my' => false, 'tag' => false);
    }

    function instance_allow_config() {
        return true;
    }

    function specialization() {

        // load userdefined title and make sure it's never empty
        if (empty($this->config->title)) {
            $this->title = get_string('blocktagstitle','blog');
        } else {
            $this->title = $this->config->title;
        }
    }

    function get_content() {
        global $CFG, $SITE, $USER, $DB;

        if (empty($CFG->usetags) || empty($CFG->bloglevel)) {
            $this->content->text = '';
            return $this->content;
        }

        if (empty($this->config->timewithin)) {
            $this->config->timewithin = BLOGDEFAULTTIMEWITHIN;
        }
        if (empty($this->config->numberoftags)) {
            $this->config->numberoftags = BLOGDEFAULTNUMBEROFTAGS;
        }
        if (empty($this->config->sort)) {
            $this->config->sort = BLOGDEFAULTSORT;
        }

        if ($this->content !== NULL) {
            return $this->content;
        }

        $this->content = new stdClass;
        $this->content->text = '';
        $this->content->footer = '';

        /// Get a list of tags

        $timewithin = time() - $this->config->timewithin * 24 * 60 * 60; /// convert to seconds

        // admins should be able to read all tags
        $type = '';
        if (!has_capability('moodle/user:readuserblogs', get_context_instance(CONTEXT_SYSTEM))) {
            $type = " AND (p.publishstate = 'site' or p.publishstate='public')";
        }

        $sql  = "SELECT t.id, t.tagtype, t.rawname, t.name, COUNT(DISTINCT ti.id) AS ct
                   FROM {tag} t, {tag_instance} ti, {post} p
                  WHERE t.id = ti.tagid AND p.id = ti.itemid
                        $type
                        AND ti.itemtype = 'post'
                        AND ti.timemodified > $timewithin
               GROUP BY t.id, t.tagtype, t.name, t.rawname
               ORDER BY ct DESC, t.name ASC";

        if ($tags = $DB->get_records_sql($sql, null, 0, $this->config->numberoftags)) {

        /// There are 2 things to do:
        /// 1. tags with the same count should have the same size class
        /// 2. however many tags we have should be spread evenly over the
        ///    20 size classes

            $totaltags  = count($tags);
            $currenttag = 0;

            $size = 20;
            $lasttagct = -1;

            $etags = array();
            foreach ($tags as $tag) {

                $currenttag++;

                if ($currenttag == 1) {
                    $lasttagct = $tag->ct;
                    $size = 20;
                } else if ($tag->ct != $lasttagct) {
                    $lasttagct = $tag->ct;
                    $size = 20 - ( (int)((($currenttag - 1) / $totaltags) * 20) );
                }

                $tag->class = "$tag->tagtype s$size";
                $etags[] = $tag;

            }

        /// Now we sort the tag display order
            $CFG->tagsort = $this->config->sort;
            usort($etags, "blog_tags_sort");

        /// Finally we create the output
        /// Accessibility: markup as a list.
            $this->content->text .= "\n<ul class='inline-list'>\n";
            foreach ($etags as $tag) {
                switch ($CFG->bloglevel) {
                    case BLOG_USER_LEVEL:
                        $filtertype = 'user';
                        $filterselect = $USER->id;
                    break;

                    default:
                        if ($this->page->course->id != SITEID) {
                            $filtertype = 'course';
                            $filterselect = $this->page->course->id;
                        } else {
                            $filtertype = 'site';
                            $filterselect = SITEID;
                        }
                    break;
                }

                $link = blog_get_blogs_url(array($filtertype => $filterselect, 'tag'=>$tag->id));
                $this->content->text .= '<li><a href="'.$link.'" '.
                                        'class="'.$tag->class.'" '.
                                        'title="'.get_string('numberofentries','blog',$tag->ct).'">'.
                                        tag_display_name($tag) .'</a></li> ';
            }
            $this->content->text .= "\n</ul>\n";

        }
        return $this->content;
    }
}

function blog_tags_sort($a, $b) {
    global $CFG;

    if (empty($CFG->tagsort)) {
        return 0;
    } else {
        $tagsort = $CFG->tagsort;
    }

    if (is_numeric($a->$tagsort)) {
        return ($a->$tagsort == $b->$tagsort) ? 0 : ($a->$tagsort > $b->$tagsort) ? 1 : -1;
    } elseif (is_string($a->$tagsort)) {
        return strcmp($a->$tagsort, $b->$tagsort);
    } else {
        return 0;
    }
}

?>
