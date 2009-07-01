<?php
/**
 * repository_flickr class
 * This plugin is used to access user's private flickr repository
 *
 * @author Dongsheng Cai <dongsheng@moodle.com>
 * @version $Id$
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 */

require_once($CFG->libdir.'/flickrlib.php');

/**
 *
 */
class repository_flickr extends repository {
    private $flickr;
    public $photos;

    /**
     *
     * @global <type> $SESSION
     * @global <type> $CFG
     * @param <type> $repositoryid
     * @param <type> $context
     * @param <type> $options
     */
    public function __construct($repositoryid, $context = SITEID, $options = array()) {
        global $SESSION, $CFG;
        $options['page']    = optional_param('p', 1, PARAM_INT);
        parent::__construct($repositoryid, $context, $options);

        $this->setting = 'flickr_';

        $this->api_key = $this->get_option('api_key');
        $this->secret  = $this->get_option('secret');

        $this->token = get_user_preferences($this->setting, '');
        $this->nsid  = get_user_preferences($this->setting.'_nsid', '');

        $this->flickr = new phpFlickr($this->api_key, $this->secret, $this->token);

        $frob  = optional_param('frob', '', PARAM_RAW);
        if (empty($this->token) && !empty($frob)) {
            $auth_info = $this->flickr->auth_getToken($frob);
            $this->token = $auth_info['token'];
            $this->nsid  = $auth_info['user']['nsid'];
            set_user_preference($this->setting, $auth_info['token']);
            set_user_preference($this->setting.'_nsid', $auth_info['user']['nsid']);
        }

    }

    /**
     *
     * @return <type>
     */
    public function check_login() {
        return !empty($this->token);
    }

    /**
     *
     * @return <type>
     */
    public function logout() {
        set_user_preference($this->setting, '');
        set_user_preference($this->setting.'_nsid', '');
        $this->token = '';
        $this->nsid  = '';
        return $this->print_login();
    }

    /**
     *
     * @param <type> $options
     * @return <type>
     */
    public function set_option($options = array()) {
        if (!empty($options['api_key'])) {
            set_config('api_key', trim($options['api_key']), 'flickr');
        }
        if (!empty($options['secret'])) {
            set_config('secret', trim($options['secret']), 'flickr');
        }
        unset($options['api_key']);
        unset($options['secret']);
        $ret = parent::set_option($options);
        return $ret;
    }

    /**
     *
     * @param <type> $config
     * @return <type>
     */
    public function get_option($config = '') {
        if ($config==='api_key') {
            return trim(get_config('flickr', 'api_key'));
        } elseif ($config ==='secret') {
            return trim(get_config('flickr', 'secret'));
        } else {
            $options['api_key'] = trim(get_config('flickr', 'api_key'));
            $options['secret']  = trim(get_config('flickr', 'secret'));
        }
        $options = parent::get_option($config);
        return $options;
    }

    /**
     *
     * @return <type>
     */
    public function global_search() {
        if (empty($this->token)) {
            return false;
        } else {
            return true;
        }
    }

    /**
     *
     * @param <type> $ajax
     * @return <type>
     */
    public function print_login() {
        if ($this->options['ajax']) {
            $ret = array();
            $popup_btn = new stdclass;
            $popup_btn->type = 'popup';
            $popup_btn->url = $this->flickr->auth();
            $ret['login'] = array($popup_btn);
            return $ret;
        } else {
            echo '<a target="_blank" href="'.$this->flickr->auth().'">'.get_string('login', 'repository').'</a>';
        }
    }

    /**
     *
     * @param <type> $photos
     * @param <type> $page
     * @return <type>
     */
    private function build_list($photos, $page = 1) {
        $photos_url = $this->flickr->urls_getUserPhotos($this->nsid);
        $ret = array();
        $ret['manage'] = $photos_url;
        $ret['list']  = array();
        $ret['pages'] = $photos['pages'];
        $ret['total'] = $photos['total'];
        $ret['perpage'] = $photos['perpage'];
        if($page <= $ret['pages']) {
            $ret['page'] = $page;
        } else {
            $ret['page'] = 1;
        }
        if (!empty($photos['photo'])) {
            foreach ($photos['photo'] as $p) {
                if(empty($p['title'])) {
                    $p['title'] = get_string('notitle', 'repository_flickr');
                }
                if (isset($p['originalformat'])) {
                    $format = $p['originalformat'];
                } else {
                    $format = 'jpg';
                }
                $format = '.'.$format;
                // append extensions to the files
                if (substr($p['title'], strlen($p['title'])-strlen($format)) != $format) {
                    $p['title'] .= $format; 
                }
                $ret['list'][] = array('title'=>$p['title'],'source'=>$p['id'],
                    'id'=>$p['id'],'thumbnail'=>$this->flickr->buildPhotoURL($p, 'Square'),
                    'date'=>'', 'size'=>'unknown', 'url'=>$photos_url.$p['id']);
            }
        }
        return $ret;
    }

    /**
     *
     * @param <type> $search_text
     * @return <type>
     */
    public function search($search_text) {
        $photos = $this->flickr->photos_search(array(
            'user_id'=>$this->nsid,
            'per_page'=>24,
            'extras'=>'original_format',
            'text'=>$search_text
            ));
        $ret = $this->build_list($photos);
        $ret['list'] = array_filter($ret['list'], array($this, 'filter'));
        return $ret;
    }

    /**
     *
     * @param string $path
     * @param int $page
     * @return <type>
     */
    public function get_listing($path = '', $page = '1') {
        $photos_url = $this->flickr->urls_getUserPhotos($this->nsid);

        $photos = $this->flickr->photos_search(array(
            'user_id'=>$this->nsid,
            'per_page'=>24,
            'page'=>$page,
            'extras'=>'original_format'
            ));
        return $this->build_list($photos, $page);
    }

    /**
     *
     * @global <type> $CFG
     * @param <type> $photo_id
     * @param <type> $file
     * @return <type>
     */
    public function get_file($photo_id, $file = '') {
        global $CFG;
        $result = $this->flickr->photos_getSizes($photo_id);
        $url = '';
        if(!empty($result[4])) {
            $url = $result[4]['source'];
        } elseif(!empty($result[3])) {
            $url = $result[3]['source'];
        } elseif(!empty($result[2])) {
            $url = $result[2]['source'];
        }
        $path = $this->prepare_file($file);
        $fp = fopen($path, 'w');
        $c = new curl;
        $c->download(array(
            array('url'=>$url, 'file'=>$fp)
        ));
        return $path;
    }

    /**
     * Add Plugin settings input to Moodle form
     * @global <type> $CFG
     * @param <type> $
     */
    public function type_config_form(&$mform) {
        global $CFG;
        $api_key = get_config('flickr', 'api_key');
        $secret = get_config('flickr', 'secret');

        if (empty($api_key)) {
            $api_key = '';
        }
        if (empty($secret)) {
            $secret = '';
        }

        $strrequired = get_string('required');
        $mform->addElement('text', 'api_key', get_string('apikey', 'repository_flickr'), array('value'=>$api_key,'size' => '40'));
        $mform->addElement('text', 'secret', get_string('secret', 'repository_flickr'), array('value'=>$secret,'size' => '40'));

        //retrieve the flickr instances
        $instances = repository::get_instances(array(),null,false,"flickr");
        if (empty($instances)) {
            $callbackurl = get_string("callbackwarning","repository_flickr");
             $mform->addElement('static', null, '',  $callbackurl);
        }
        else {
             $callbackurl = $CFG->wwwroot.'/repository/ws.php?callback=yes&amp;repo_id='.$instances[0]->id;
              $mform->addElement('static', 'callbackurl', '', get_string('callbackurltext', 'repository_flickr', $callbackurl));
        }

        $mform->addRule('api_key', $strrequired, 'required', null, 'client');
        $mform->addRule('secret', $strrequired, 'required', null, 'client');
    }

    /**
     * Names of the plugin settings
     * @return <type>
     */
    public static function get_type_option_names() {
        return array('api_key', 'secret');
    }
    public function supported_filetypes() {
        return array('web_image');
    }
}
