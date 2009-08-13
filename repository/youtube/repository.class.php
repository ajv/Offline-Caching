<?php
/**
 * repository_youtube class
 *
 * @author Dongsheng Cai <dongsheng@moodle.com>
 * @version $Id$
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 */

class repository_youtube extends repository {
    public function __construct($repositoryid, $context = SITEID, $options = array()) {
        $this->keyword = optional_param('youtube_keyword', '', PARAM_RAW);
        $this->start =1;
        $this->max = 27;
        $this->sort = 'published';
        parent::__construct($repositoryid, $context, $options);
    }

    public function check_login() {
        return !empty($this->keyword);
    }

    public function search($search_text) {
        $ret  = array();
        $ret['nologin'] = true;
        $ret['list'] = $this->_get_collection($this->keyword, $this->start, $this->max, $this->sort);
        return $ret;
    }

    private function _get_collection($keyword, $start, $max, $sort) {
        $list = array();
        $this->feed_url = 'http://gdata.youtube.com/feeds/api/videos?q=' . urlencode($keyword) . '&format=5&start-index=' . $start . '&max-results=' .$max . '&orderby=' . $sort;
        $c = new curl(array('cache'=>true, 'module_cache'=>'repository'));
        $content = $c->get($this->feed_url);
		$xml = simplexml_load_string($content);
        $media = $xml->entry->children('http://search.yahoo.com/mrss/');
	    $links = $xml->children('http://www.w3.org/2005/Atom');
        foreach ($xml->entry as $entry) {
            $media = $entry->children('http://search.yahoo.com/mrss/');
            $title = $media->group->title;
            $attrs = $media->group->thumbnail->attributes();
            $thumbnail = $attrs['url'];
            $arr = explode('/', $entry->id);
            $id = $arr[count($arr)-1];
            $source = 'http://www.youtube.com/v/'.$id;
            $list[] = array(
                'title'=>(string)$title,
                'thumbnail'=>(string)$attrs['url'],
                'thumbnail_width'=>150,
                'thumbnail_height'=>120,
                'size'=>'',
                'date'=>'',
                'source'=>$source
            );
        } 
        return $list;
    }

    public function get_file($url, $title) {
        return $url;
    }
    public function global_search() {
        return false;
    }
    public function get_listing($path='', $page = '') {
        $ret  = array();
        $ret['nologin'] = true;
        $ret['list'] = $this->_get_collection($this->keyword, $this->start, $this->max, $this->sort);
        return $ret;
    }

    public function print_login($ajax = true) {
        $ret = array();
        $search = new stdclass;
        $search->type = 'text';
        $search->id   = 'youtube_search';
        $search->name = 'youtube_keyword';
        $search->label = get_string('search', 'repository_youtube').': ';
        $ret['login'] = array($search);
        $ret['login_btn_label'] = get_string('search');
        $ret['login_btn_action'] = 'search';
        return $ret;
    }
    public function supported_return_value() {
        return 'link';
    }
    public function supported_filetypes() {
        return array('web_video');
    }
}
