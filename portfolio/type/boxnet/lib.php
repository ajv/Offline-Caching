<?
require_once($CFG->libdir.'/filelib.php');
require_once($CFG->dirroot.'/repository/boxnet/boxlibphp5.php');

class portfolio_plugin_boxnet extends portfolio_plugin_base {

    private $boxclient;
    private $ticket;
    private $authtoken;
    private $workdir;
    private $folders;

    public static function supported_formats() {
        return array(PORTFOLIO_FORMAT_FILE, PORTFOLIO_FORMAT_HTML);
    }

    public function prepare_package($tempdir) {
        $this->workdir = $tempdir;
        return true; // don't do anything else for this plugin, we want to send all files as they are.
    }

    public function send_package() {
        $ret = array();
        foreach (get_directory_list($this->workdir) as $file) {
            $file = $this->workdir . '/' . $file;
            $ret[] = $this->boxclient->uploadFile(
                array(
                    'file'      => $file,
                    'folder_id' => $this->get_export_config('folder')
                )
            );
        }
        if ($this->boxclient->isError()) {
            return false;
        }
        return is_array($ret) && !empty($ret);
    }

    public function set_export_config($config) {
        parent::set_export_config($config);
        if (array_key_exists('newfolder', $config) && !empty($config['newfolder'])) {
            if (!$created = $this->boxclient->createFolder($config['newfolder'])) {
                portfolio_exporter::raise_error('foldercreatefailed', 'portfolio_boxnet');
            }
            $this->folders[$created['folder_id']] = $created['folder_type'];
            parent::set_export_config(array('folder' => $created['folder_id']));
        }
    }

    public function get_export_summary() {
        $allfolders = $this->get_folder_list();
        return array(
            get_string('targetfolder', 'portfolio_boxnet') => $allfolders[$this->get_export_config('folder')]
        );
    }

    public function get_continue_url() {
        // @todo this was a *guess* based on what urls I got clicking around the interface.
        // the #0:f:<folderid> part seems fragile...
        // but I couldn't find a documented permalink scheme.
        return 'http://box.net/files#0:f:' . $this->get_export_config('folder');
    }

    public function expected_time($callertime) {
        return $callertime;
    }

    public static function has_admin_config() {
        return true;
    }

    public static function get_allowed_config() {
        return array('apikey');
    }

    public function has_export_config() {
        return true;
    }

    public function get_allowed_user_config() {
        return array('authtoken', 'authtokenctime');
    }

    public function get_allowed_export_config() {
        return array('folder', 'newfolder');
    }

    public function export_config_form(&$mform) {
        $folders = $this->get_folder_list();
        $strrequired = get_string('required');
        $mform->addElement('text', 'plugin_newfolder', get_string('newfolder', 'portfolio_boxnet'));
        if (empty($folders)) {
            $mform->addRule('plugin_newfolder', $strrequired, 'required', null, 'client');
        }
        else {
            $mform->addElement('select', 'plugin_folder', get_string('existingfolder', 'portfolio_boxnet'), $folders);
        }
    }

    public function export_config_validation($data) {
        if ((!array_key_exists('plugin_folder', $data) || empty($data['plugin_folder']))
            && (!array_key_exists('plugin_newfolder', $data) || empty($data['plugin_newfolder']))) {
            return array(
                'plugin_folder' => get_string('notarget', 'portfolio_boxnet'),
                'plugin_newfolder' => get_string('notarget', 'portfolio_boxnet'));
        }
        $allfolders = $this->get_folder_list();
        if (in_array($data['plugin_newfolder'], $allfolders)) {
            return array('plugin_newfolder' => get_string('folderclash', 'portfolio_boxnet'));
        }
    }

    public function admin_config_form(&$mform) {
        $strrequired = get_string('required');
        $mform->addElement('text', 'apikey', get_string('apikey', 'portfolio_boxnet'));
        $mform->addRule('apikey', $strrequired, 'required', null, 'client');
    }

    public function steal_control($stage) {
        if ($stage != PORTFOLIO_STAGE_CONFIG) {
            return false;
        }
        if ($this->authtoken) {
            return false;
        }
        if (!$this->ensure_ticket()) {
            portfolio_exporter::raise_error('noticket', 'portfolio_boxnet');
        }
        $token = $this->get_user_config('authtoken', $this->get('user')->id);
        $ctime= $this->get_user_config('authtokenctime', $this->get('user')->id);
        if (!empty($token) && (($ctime + 60*60*20) > time())) {
            $this->authtoken = $token;
            $this->boxclient->auth_token = $token;
            return false;
        }
        return 'http://www.box.net/api/1.0/auth/'.$this->ticket;
    }

    public function post_control($stage, $params) {
        if ($stage != PORTFOLIO_STAGE_CONFIG) {
            return;
        }
        if (!array_key_exists('auth_token', $params) || empty($params['auth_token'])) {
            portfolio_exporter::raise_error('noauthtoken', 'portfolio_boxnet');
        }
        $this->authtoken = $params['auth_token'];
        $this->boxclient->auth_token = $this->authtoken;
        $this->set_user_config(array('authtoken' => $this->authtoken, 'authtokenctime' => time()), $this->get('user')->id);
    }

    private function ensure_ticket() {
        if (!empty($this->boxclient)) {
            return true;
        }
        $this->boxclient = new boxclient($this->get_config('apikey'), '');
        $ticket_return = $this->boxclient->getTicket();
        if ($this->boxclient->isError() || empty($ticket_return)) {
            portfolio_exporter::raise_error('noticket', 'portfolio_boxnet');
        }
        $this->ticket = $ticket_return['ticket'];
    }

    private function get_folder_list() {
        if (!empty($this->folders)) {
            return $this->folders;
        }
        if (empty($this->ticket)
            || empty($this->authtoken)
            || empty($this->boxclient)) {
            // if we don't have these we're pretty much screwed
            portfolio_exporter::raise_error('folderlistfailed', 'portfolio_boxnet');
            return false;
        }
        $rawfolders = $this->boxclient->getAccountTree();
        if ($this->boxclient->isError()) {
            portfolio_exporter::raise_error('folderlistfailed', 'portfolio_boxnet');
        }
        if (!is_array($rawfolders)) {
            return false;
        }
        $folders = array();
        foreach ($rawfolders['folder_id'] as $key => $id) {
            if (empty($id)) {
                continue;
            }
            $name = $rawfolders['folder_name'][$key];
            if (!empty($rawfolders['shared'][$key])) {
                $name .= ' (' . get_string('sharedfolder', 'portfolio_boxnet') . ')';
            }
            $folders[$id] = $name;
        }
        $this->folders = $folders;
        return $folders;
    }

    public function instance_sanity_check() {
        if (!$this->get_config('apikey')) {
            return 'err_noapikey';
        }
    //@TODO see if we can verify the api key without actually getting an authentication token
    }

    public static function allows_multiple() {
        return false;
    }
}