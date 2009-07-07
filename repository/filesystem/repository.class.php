<?php // $Id$

/**
 * repository_filesystem class
 * Create a repository from your local filesystem
 * *NOTE* for security issue, we use a fixed repository path
 * which is %moodledata%/repository
 *
 * @author Dongsheng Cai <dongsheng@moodle.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 */
class repository_filesystem extends repository {
    public function __construct($repositoryid, $context = SITEID, $options = array()) {
        global $CFG;
        parent::__construct($repositoryid, $context, $options);
        $this->root_path = $CFG->dataroot.'/repository/';
        $this->root_path .= ($this->options['fs_path'] . '/');
        if (!empty($options['ajax'])) {
            if (!is_dir($this->root_path)) {
                $created = mkdir($this->root_path, 0700);
                $ret = array();
                $ret['msg'] = get_string('invalidpath', 'repository_filesystem');
                $ret['nosearch'] = true;
                if ($options['ajax'] && !$created) {
                    echo json_encode($ret);
                    exit;
                }
            }
        }
    }
    public function get_listing($path = '', $page = '') {
        global $CFG, $OUTPUT;
        $list = array();
        $list['list'] = array();
        // process breacrumb trail
        $list['path'] = array(
            array('name'=>'Root','path'=>'')
        );
        $trail = '';
        if (!empty($path)) {
            $parts = explode('/', $path);
            if (count($parts) > 1) {
                foreach ($parts as $part) {
                    if (!empty($part)) {
                        $trail .= ('/'.$part);
                        $list['path'][] = array('name'=>$part, 'path'=>$trail);
                    }
                }
            } else {
                $list['path'][] = array('name'=>$path, 'path'=>$path);
            }
            $this->root_path .= ($path.'/');
        }
        $list['manage'] = false;
        $list['dynload'] = true;
        $list['nologin'] = true;
        $list['nosearch'] = true;
        if ($dh = opendir($this->root_path)) {
            while (($file = readdir($dh)) != false) {
                if ( $file != '.' and $file !='..') {
                    if (filetype($this->root_path.$file) == 'file') {
                        $list['list'][] = array(
                            'title' => $file,
                            'source' => $path.'/'.$file,
                            'size' => filesize($this->root_path.$file),
                            'date' => time(),
                            'thumbnail' => $OUTPUT->old_icon_url(file_extension_icon($this->root_path.$file, 32))
                        );
                    } else {
                        if (!empty($path)) {
                            $current_path = $path . '/'. $file;
                        } else {
                            $current_path = $file;
                        }
                        $list['list'][] = array(
                            'title' => $file,
                            'children' => array(),
                            'thumbnail' => $OUTPUT->old_icon_url('f/folder-32'),
                            'path' => $current_path
                            );
                    }
                }
            }
        }
        return $list;
    }
    public function check_login() {
        return true;
    }
    public function print_login() {
        return true;
    }
    public function global_search() {
        return false;
    }
    // move file to local moodle
    public function get_file($file, $title = '') {
        global $CFG;
        if ($file{0} == '/') {
            $file = $this->root_path.substr($file, 1, strlen($file)-1);
        }
        // this is a hack to prevent move_to_file deleteing files
        // in local repository
        $CFG->repository_no_delete = true;
        return $file;
    }

    public function logout() {
        return true;
    }

    public static function get_instance_option_names() {
        return array('fs_path');
    }

    public static function get_type_option_names() {
        return array();
    }
    public function type_config_form(&$mform) {
    }
    public function instance_config_form(&$mform) {
        global $CFG;
        $path = $CFG->dataroot . '/repository/';
        if ($handle = opendir($path)) {
            $fieldname = get_string('path', 'repository_filesystem');
            while (false !== ($file = readdir($handle))) {
                if (is_dir($path.$file) && $file != '.' && $file!= '..') {
                    $mform->addElement('radio', 'fs_path', $fieldname, $file, $file);
                    $fieldname = '';
                }
            }
            closedir($handle);
        }
        $mform->addElement('static', null, '',  get_string('information','repository_filesystem'));
    }
}
