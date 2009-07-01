<?php
/**
 * repository_webdav class
 *
 * @author Dongsheng Cai
 * @version $Id$
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 */

require_once($CFG->libdir.'/webdavlib.php');

class repository_webdav extends repository {
    public function __construct($repositoryid, $context = SITEID, $options = array()) {
        parent::__construct($repositoryid, $context, $options);
        // set up webdav client
        $this->wd = new webdav_client();
        if (empty($this->options['webdav_server'])) {
            return;
        }
        if (empty($this->options['webdav_type'])) {
            $this->options['webdav_type'] = '';
        } else {
            $this->options['webdav_type'] = 'ssl://';
        }
        $this->wd->set_server($this->options['webdav_server']);
        if (empty($this->options['webdav_port'])) {
            if (empty($this->options['webdav_type'])) {
                $this->wd->set_port(80);
            } else {
                $this->wd->set_port(443);
            }
            $port = '';
        } else {
            $this->wd->set_port($this->options['webdav_port']);
            $port = ':'.$this->options['webdav_port'];
        }
        $this->webdav_host = $this->options['webdav_type'].$this->options['webdav_server'].$port;
        if(!empty($this->options['webdav_user'])){
            $this->wd->set_user($this->options['webdav_user']);
        }
        if(!empty($this->options['webdav_password'])) {
            $this->wd->set_pass($this->options['webdav_password']);
        }
        $this->wd->set_protocol(1);
        $this->wd->set_debug(false);
    }
    public function check_login() {
        return true;
    }
    public function get_file($url, $title) {
        global $CFG;
        $path = $this->prepare_file($title);
        $buffer = '';
        if (!$this->wd->open()) {
            return false;
        }
        $this->wd->get($url, $buffer);
        $fp = fopen($path, 'wb');
        fwrite($fp, $buffer);
        return $path;
    }
    public function global_search() {
        return false;
    }
    public function get_listing($path='', $page = '') {
        global $CFG, $OUTPUT;
        $OUTPUT->initialise_deprecated_cfg_pixpath();
        $list = array();
        $ret  = array();
        $ret['dynload'] = true;
        $ret['nosearch'] = true;
        $ret['nologin'] = true;
        $ret['path'] = array(array('name'=>'Root', 'path'=>0));
        $ret['list'] = array();
        if (!$this->wd->open()) {
            return $ret;
        }
        if (empty($path)) {
            $path = '/';
            $dir = $this->wd->ls($path);
        } else {
            if (empty($this->options['webdav_type'])) {
                $partern = '#http://'.$this->webdav_host.'/#';
            } else {
                $partern = '#http://'.$this->webdav_type.$this->webdav_host.'/#';
            }
            $path = '/'.preg_replace($partern, '', $path);
            $dir = $this->wd->ls($path);
        }
        if (!is_array($dir)) {
            return $ret;
        }
        foreach ($dir as $v) {
            if (!empty($v['creationdate'])) {
                $ts = $this->wd->iso8601totime($v['creationdate']);
                $filedate = userdate($ts);
            } else {
                $filedate = '';
            }
            if (!empty($v['resourcetype']) && $v['resourcetype'] == 'collection') {
                // a folder
                if ($path != $v['href']) {
                    $matches = array();
                    preg_match('#(\w+)$#i', $v['href'], $matches);
                    if (!empty($matches[1])) {
                        $title = urldecode($matches[1]);
                    } else {
                        $title = urldecode($v['href']);
                    }
                    $ret['list'][] = array(
                        'title'=>$title,
                        'thumbnail'=>$CFG->pixpath.'/f/folder-32.png',
                        'children'=>array(),
                        'date'=>$filedate,
                        'size'=>0,
                        'path'=>$v['href']
                    );
                }
            }else{
                // a file
                $title = urldecode(substr($v['href'], strpos($v['href'], $path)+strlen($path)));
                $size = !empty($v['getcontentlength'])? $v['getcontentlength']:'';
                $ret['list'][] = array(
                    'title'=>$title,
                    'thumbnail' => $CFG->pixpath .'/f/'. mimeinfo('icon32', $title),
                    'size'=>$size,
                    'date'=>$filedate,
                    'source'=>$v['href']
                );
            }
        }
        return $ret;
    }
    public static function get_instance_option_names() {
        return array('webdav_type', 'webdav_server', 'webdav_port', 'webdav_user', 'webdav_password');
    }

    public function instance_config_form(&$mform) {
        $choices = array(0 => get_string('http', 'repository_webdav'), 1 => get_string('https', 'repository_webdav'));
        $mform->addElement('select', 'webdav_type', get_string('webdav_type', 'repository_webdav'), $choices);
        $mform->addRule('webdav_type', get_string('required'), 'required', null, 'client');

        $mform->addElement('text', 'webdav_server', get_string('webdav_server', 'repository_webdav'), array('size' => '40'));
        $mform->addRule('webdav_server', get_string('required'), 'required', null, 'client');
        $mform->addElement('text', 'webdav_port', get_string('webdav_port', 'repository_webdav'), array('size' => '40'));
        $mform->addElement('text', 'webdav_user', get_string('webdav_user', 'repository_webdav'), array('size' => '40'));
        $mform->addElement('text', 'webdav_password', get_string('webdav_password', 'repository_webdav'), array('size' => '40'));
    }
}
