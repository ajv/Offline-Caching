<?php
/**
 * repository_local class
 * This is a subclass of repository class
 *
 * @version $Id$
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 */

/**
 *
 */
class repository_local extends repository {

    /**
     * @param int $repositoryid
     * @param int $context
     * @param array $options
     */
    public function __construct($repositoryid, $context = SITEID, $options = array()) {
        parent::__construct($repositoryid, $context, $options);
    }

    /**
     * @param boolean $ajax
     * @return mixed
     */
    public function print_login($ajax = true) {
        return $this->get_listing();
    }

    /**
     *
     * @param string $search_text
     * @return mixed
     */
    public function search($search_text) {
        return $this->get_listing('', '', $search_text);
    }

    /**
     *
     * @param string $encodedpath
     * @param string $path not used by this plugin
     * @param string $search
     * @return mixed
     */
    public function get_listing($encodedpath = '', $page = '', $search = '') {
        global $CFG, $USER, $OUTPUT;
        $ret = array();
        $ret['dynload'] = true;
        $ret['nosearch'] = true;
        $list = array();

        if (!empty($encodedpath)) {
            $params = unserialize(base64_decode($encodedpath));
            if (is_array($params)) {
                $itemid   = $params['itemid'];
                $filename = $params['filename'];
                $filearea = $params['filearea'];
                $filepath = $params['filepath'];
                $context  = get_context_instance_by_id($params['contextid']);
            }
        } else {
            $itemid   = null;
            $filename = null;
            $filearea = null;
            $filepath = null;
            $context  = get_system_context();
        }

        try {
            $browser = get_file_browser();

            if ($fileinfo = $browser->get_file_info($context, $filearea, $itemid, '/', $filename)) {
                $level = $fileinfo->get_parent();
                while ($level) {
                    $params = base64_encode(serialize($level->get_params()));
                    $path[] = array('name'=>$level->get_visible_name(), 'path'=>$params);
                    $level = $level->get_parent();
                }
                if (!empty($path) && is_array($path)) {
                    $path = array_reverse($path);
                    $ret['path'] = $path;
                }
                $children = $fileinfo->get_children();
                foreach ($children as $child) {
                    if ($child->is_directory()) {
                        $params = base64_encode(serialize($child->get_params()));
                        $node = array(
                            'title' => $child->get_visible_name(),
                            'size' => 0,
                            'date' => '',
                            'path' => $params,
                            'children'=>array(),
                            'thumbnail' => $OUTPUT->old_icon_url('f/folder-32') . ''
                        );
                        $list[] = $node;
                    } else {
                        $params = base64_encode(serialize($child->get_params()));
                        $node = array(
                            'title' => $child->get_visible_name(),
                            'size' => 0,
                            'date' => '',
                            'source'=> $params,
                            'thumbnail' => $OUTPUT->old_icon_url('f/text-32') . ''
                        );
                        $list[] = $node;
                    }
                }
            }
        } catch (Exception $e) {
            throw new repository_exception('emptyfilelist', 'repository_local');
        }
        $ret['list'] = $list;
        return $ret;
    }

     /**
     * Download a file, this function can be overridden by
     * subclass.
     *
     * @global object $CFG
     * @param string $url the url of file
     * @param string $file save location
     * @return string the location of the file
     * @see curl package
     */
    public function get_file($encoded, $title = '', $itemid = '') {
        global $USER, $DB;
        $ret = array();

        $browser = get_file_browser();
        $params = unserialize(base64_decode($encoded));
        $user_context = get_context_instance(CONTEXT_USER, $USER->id);
        // the final file
        $contextid  = $params['contextid'];
        $filearea   = $params['filearea'];
        $filepath   = $params['filepath'];
        $filename   = $params['filename'];
        $fileitemid = $params['itemid'];
        $context    = get_context_instance_by_id($contextid);
        $file_info  = $browser->get_file_info($context, $filearea, $fileitemid, $filepath, $filename);
        $file_info->copy_to_storage($user_context->id, 'user_draft', $itemid, '/', $title);

        $ret['itemid'] = $itemid;
        $ret['title']  = $title;
        $ret['contextid'] = $user_context->id;

        return $ret;
    }

    /**
     *
     * @return string
     */
    public function get_name(){
        return get_string('repositoryname', 'repository_local');;
    }
}
?>
