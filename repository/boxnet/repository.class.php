<?php
require_once($CFG->libdir.'/boxlib.php');

/**
 * repository_boxnet class
 * This is a subclass of repository class
 *
 * @author Dongsheng Cai
 * @version $Id$
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 */
class repository_boxnet extends repository {
    private $box;

    /**
     * Constructor
     * @global object $SESSION
     * @param int $repositoryid
     * @param object $context
     * @param array $options
     */
    public function __construct($repositoryid, $context = SITEID, $options = array()) {
        global $SESSION;
        $options['username']   = optional_param('boxusername', '', PARAM_RAW);
        $options['password']   = optional_param('boxpassword', '', PARAM_RAW);
        $options['ticket']     = optional_param('ticket', '', PARAM_RAW);
        $sess_name = 'box_token'.$this->id;
        $this->sess_name = 'box_token'.$this->id;
        parent::__construct($repositoryid, $context, $options);
        $this->api_key = $this->get_option('api_key');
        // do login
        if(!empty($options['username']) && !empty($options['password']) && !empty($options['ticket']) ) {
            $this->box = new boxclient($this->api_key);
            try {
                $SESSION->$sess_name = $this->box->getAuthToken($options['ticket'], 
                    $options['username'], $options['password']);
            } catch (repository_exception $e) {
                throw $e;
            }
        }
        // already logged
        if(!empty($SESSION->$sess_name)) {
            if(empty($this->box)) {
                $this->box = new boxclient($this->api_key, $SESSION->$sess_name);
            }
            $this->auth_token = $SESSION->$sess_name;
        } else {
            $this->box = new boxclient($this->api_key);
        }
    }

    /**
     *
     * @global object $SESSION
     * @return boolean
     */
    public function check_login() {
        global $SESSION;
        return !empty($SESSION->{$this->sess_name});
    }

    /**
     *
     * @global object $SESSION
     * @return string
     */
    public function logout() {
        global $SESSION;
        unset($SESSION->{$this->sess_name});
        return $this->print_login();
    }

    /**
     *
     * @param array $options
     * @return mixed
     */
    public function set_option($options = array()) {
        if (!empty($options['api_key'])) {
            set_config('api_key', trim($options['api_key']), 'boxnet');
        }
        unset($options['api_key']);
        $ret = parent::set_option($options);
        return $ret;
    }

    /**
     *
     * @param string $config
     * @return mixed
     */
    public function get_option($config = '') {
        if($config==='api_key') {
            return trim(get_config('boxnet', 'api_key'));
        } else {
            $options['api_key'] = trim(get_config('boxnet', 'api_key'));
        }
        $options = parent::get_option($config);
        return $options;
    }

    /**
     *
     * @global object $SESSION
     * @return boolean
     */
    public function global_search() {
        global $SESSION;
        if (empty($SESSION->{$this->sess_name})) {
            return false;
        } else {
            return true;
        }
    }

    /**
     *
     * @global object $DB
     * @return object
     */
    public function get_login() {
        global $DB;
        if ($entry = $DB->get_record('repository_instances', array('id'=>$this->id))) {
            $ret->username = $entry->username;
            $ret->password = $entry->password;
        } else {
            $ret->username = '';
            $ret->password = '';
        }
        return $ret;
    }

    /**
     *
     * @global object $CFG
     * @param string $search_text
     * @return mixed
     */
    public function search($search_text) {
        global $CFG, $OUTPUT;
        $list = array();
        $ret  = array();
        $tree = $this->box->getAccountTree();
        if (!empty($tree)) {
            $filenames = $tree['file_name'];
            $fileids   = $tree['file_id'];
            $filesizes = $tree['file_size'];
            $filedates = $tree['file_date'];
            $fileicon  = $tree['thumbnail'];
            foreach ($filenames as $n=>$v){
                if(strstr(strtolower($v), strtolower($search_text)) !== false) {
                    $list[] = array('title'=>$v, 
                            'size'=>$filesizes[$n],
                            'date'=>$filedates[$n],
                            'source'=>'http://box.net/api/1.0/download/'
                                .$this->auth_token.'/'.$fileids[$n],
                            'thumbnail' => $OUTPUT->old_icon_url(file_extension_icon($v, 32)));
                }
            }
        }
        $ret['list'] = array_filter($list, array($this, 'filter'));
        return $ret;
    }

    /**
     *
     * @global object $CFG
     * @param string $path
     * @return mixed
     */
    public function get_listing($path = '/', $page = ''){
        global $CFG;
        $list = array();
        $ret  = array();
        $ret['list'] = array();
        $tree = $this->box->getfiletree($path);
        $ret['manage'] = 'http://www.box.net/files';
        $ret['path'] = array(array('name'=>'Root', 'path'=>0));
        if(!empty($tree)) {
            $ret['list'] = array_filter($tree, array($this, 'filter'));
        }
        return $ret;
    }

    /**
     *
     * @return array
     */
    public function print_login(){
        $t = $this->box->getTicket();
        $ret = $this->get_login();
        if ($this->options['ajax']) {
            $ticket_field->type = 'hidden';
            $ticket_field->name = 'ticket';
            $ticket_field->value = $t['ticket'];

            $user_field->label = get_string('username', 'repository_boxnet').': ';
            $user_field->id    = 'box_username';
            $user_field->type  = 'text';
            $user_field->name  = 'boxusername';
            $user_field->value = $ret->username;
            
            $passwd_field->label = get_string('password', 'repository_boxnet').': ';
            $passwd_field->id    = 'box_password';
            $passwd_field->type  = 'password';
            $passwd_field->name  = 'boxpassword';

            $ret = array();
            $ret['login'] = array($ticket_field, $user_field, $passwd_field);
            return $ret;
        } else {
            echo '<table>';
            echo '<tr><td><label>'.get_string('username', 'repository_boxnet').'</label></td>';
            echo '<td><input type="text" name="boxusername" /></td></tr>';
            echo '<tr><td><label>'.get_string('password', 'repository_boxnet').'</label></td>';
            echo '<td><input type="password" name="boxpassword" /></td></tr>';
            echo '<input type="hidden" name="ticket" value="'.$t['ticket'].'" />';
            echo '</table>';
            echo '<input type="submit" value="'.get_string('enter', 'repository').'" />';
        }
    }

    /**
     * Names of the plugin settings
     * @return array
     */
    public static function get_type_option_names() {
        return array('api_key');
    }

    /**
     * Add Plugin settings input to Moodle form
     * @param object $mform
     */
    public function type_config_form(&$mform) {
        $public_account = get_config('boxnet', 'public_account');
        $api_key = get_config('boxnet', 'api_key');
        if (empty($api_key)) {
            $api_key = '';
        }
        $strrequired = get_string('required');
        $mform->addElement('text', 'api_key', get_string('apikey', 'repository_boxnet'), array('value'=>$api_key,'size' => '40'));
        $mform->addRule('api_key', $strrequired, 'required', null, 'client');
        $mform->addElement('static', null, '',  get_string('information','repository_boxnet'));
    }
}
?>
