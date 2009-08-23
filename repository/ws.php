<?php  // $Id$

/// The Web service script that is called from the filepicker front end

    require_once('../config.php');
    require_once('../lib/filelib.php');
    require_once('lib.php');
    require_login();

/// Parameters
    $page  = optional_param('page', '', PARAM_RAW);           // page
    $client_id = optional_param('client_id', SITEID, PARAM_RAW);    // client ID
    $env   = optional_param('env', 'filepicker', PARAM_ALPHA);// opened in editor or moodleform
    $file  = optional_param('file', '', PARAM_RAW);           // file to download
    $title = optional_param('title', '', PARAM_FILE);         // new file name
    $itemid = optional_param('itemid', '', PARAM_INT);
    $action = optional_param('action', '', PARAM_ALPHA);
    $ctx_id = optional_param('ctx_id', SITEID, PARAM_INT);    // context ID
    $repo_id   = optional_param('repo_id', 1, PARAM_INT);     // repository ID
    $req_path  = optional_param('p', '', PARAM_RAW);          // path
    $save_path = optional_param('savepath', '/', PARAM_PATH);
    $callback  = optional_param('callback', '', PARAM_CLEANHTML);
    $search_text = optional_param('s', '', PARAM_CLEANHTML);

/// Headers to make it not cacheable
    header("Cache-Control: no-cache, must-revalidate");
    header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
    $err = new stdclass;
    $err->client_id = $client_id;

/// Check permissions
    if (! (isloggedin() && repository::check_context($ctx_id)) ) {
        $err->e = get_string('nopermissiontoaccess', 'repository');
        die(json_encode($err));
    }

/// Wait as long as it takes for this script to finish
    set_time_limit(0);

/// Check for actions that do not need repository ID
    switch ($action) {
        // delete a file from filemanger
        case 'delete':
            try {
                if (!$context = get_context_instance(CONTEXT_USER, $USER->id)) {
                }
                $contextid = $context->id;
                $fs = get_file_storage();
                if ($file = $fs->get_file($contextid, 'user_draft', $itemid, '/', $title)) {
                    if($result = $file->delete()) {
                        echo $client_id;
                    } else {
                        echo '';
                    }
                } else {
                    echo '';
                }
                exit;
            } catch (repository_exception $e) {
                $err->e = $e->getMessage();
                die(json_encode($err));
            }
            break;
        case 'gsearch':     //  Global Search
            $repos = repository::get_instances(array(get_context_instance_by_id($ctx_id), get_system_context()));
            $list = array();
            foreach($repos as $repo){
                if ($repo->global_search()) {
                    try {
                        $ret = $repo->search($search_text);
                        array_walk($ret['list'], 'repository_attach_id', $repo->id);  // See function below
                        $tmp = array_merge($list, $ret['list']);
                        $list = $tmp;
                    } catch (repository_exception $e) {
                        $err->e = $e->getMessage();
                        die(json_encode($err));
                    }
                }
            }
            $listing = array('list'=>$list);
            $listing['gsearch'] = true;
            $listing['client_id'] = $client_id;
            die(json_encode($listing));
            break;

        case 'ccache':      // Clean cache
            $cache = new curl_cache;
            $cache->refresh();
            $action = 'list';
            break;
    }

/// Get repository instance information
    $sql = 'SELECT i.name, i.typeid, r.type FROM {repository} r, {repository_instances} i '.
           'WHERE i.id=? AND i.typeid=r.id';
    if (!$repository = $DB->get_record_sql($sql, array($repo_id))) {
        $err->e = get_string('invalidrepositoryid', 'repository');
        die(json_encode($err));
    } else {
        $type = $repository->type;
    }

    if (file_exists($CFG->dirroot.'/repository/'.$type.'/repository.class.php')) {
        require_once($CFG->dirroot.'/repository/'.$type.'/repository.class.php');
        $classname = 'repository_' . $type;
        try {
            $repo = new $classname($repo_id, $ctx_id, array('ajax'=>true, 'name'=>$repository->name, 'client_id'=>$client_id));
        } catch (repository_exception $e){
            $err->e = $e->getMessage();
            die(json_encode($err));
        }
    } else {
        $err->e = get_string('invalidplugin', 'repository', $type);
        die(json_encode($err));
    }

    if (!empty($callback)) {
        // call opener window to refresh repository
        // the callback url should be something like this:
        // http://xx.moodle.com/repository/ws.php?callback=yes&repo_id=1&sid=xxx
        // sid is the attached auth token from external source
        // If Moodle is working on HTTPS mode, then we are not allowed to access
        // parent window, in this case, we need to alert user to refresh the repository
        // manually.
        $strhttpsbug = get_string('cannotaccessparentwin', 'repository');
        $strrefreshnonjs = get_string('refreshnonjsfilepicker', 'repository');
        $js  =<<<EOD
<html><head>
<script type="text/javascript">
if(window.opener){
    window.opener.repository_callback($repo_id);
    window.close();
} else {
    alert("{$strhttpsbug }");
}
</script>
<body>
<noscript>
{$strrefreshnonjs}
</noscript>
</body>
</html>
EOD;
        echo $js;
        die;
    }


/// These actions all occur on the currently active repository instance
    switch ($action) {
        case 'sign':
        case 'list':
            if ($repo->check_login()) {
                try {
                    $listing = $repo->get_listing($req_path, $page);
                    $listing['client_id'] = $client_id;
                    $listing['repo_id'] = $repo_id;
                    echo json_encode($listing);
                } catch (repository_exception $e) {
                    $err->e = $e->getMessage();
                    die(json_encode($err));
                }
                break;
            } else {
                $action = 'login';
            }
        case 'login':
            try {
                $listing = $repo->print_login();
                $listing['client_id'] = $client_id;
                $listing['repo_id'] = $repo_id;
                echo json_encode($listing);
            } catch (repository_exception $e){
                $err->e = $e->getMessage();
                die(json_encode($err));
            }
            break;
        case 'logout':
            $logout = $repo->logout();
            $logout['client_id'] = $client_id;
            $logout['repo_id'] = $repo_id;
            echo json_encode($logout);
            break;
        case 'searchform':
            $search_form['form'] = $repo->print_search($client_id);
            $search_form['client_id'] = $client_id;
            echo json_encode($search_form);
            break;
        case 'search':
            try {
                $search_result = $repo->search($search_text);
                $search_result['search_result'] = true;
                $search_result['client_id'] = $client_id;
                $search_result['repo_id'] = $repo_id;
                echo json_encode($search_result);
            } catch (repository_exception $e) {
                $err->e = $e->getMessage();
                die(json_encode($err));
            }
            break;
        case 'download':
            try {
                $filepath = $repo->get_file($file, $title, $itemid);
                if ($filepath === false) {
                    $err->e = get_string('cannotdownload', 'repository');
                    die(json_encode($err));
                }
                if (empty($itemid)) {
                    $itemid = (int)substr(hexdec(uniqid()), 0, 9)+rand(1,100);
                }
                if (preg_match('#(https?://([-\w\.]+)+(:\d+)?(/([\w/_\.]*(\?\S+)?)?)?)#', $filepath)) {
                    // youtube plugin return a url instead a file path
                    $url = $filepath;
                    echo json_encode(array(
                                /* File picker need to know this is a link
                                 * in order to attach title to url
                                 */
                                'type'=>'link',
                                'client_id'=>$client_id,
                                'url'=>$url,
                                'id'=>$url,
                                'file'=>$url
                                )
                            );
                } else if (is_array($filepath)) {
                    // file api don't have real file path, so we need more file api specific info for "local" plugin
                    $fileinfo = $filepath;
                    $info = array();
                    $info['client_id'] = $client_id;
                    $info['file'] = $fileinfo['title'];
                    $info['id'] = $itemid;
                    $info['url'] = $CFG->httpswwwroot.'/draftfile.php/'.$fileinfo['contextid'].'/user_draft/'.$itemid.'/'.$fileinfo['title'];
                    echo json_encode($info);
                } else {
                    // normal file path name
                    $info = repository::move_to_filepool($filepath, $title, $itemid, $save_path);
                    $info['client_id'] = $client_id;
                    echo json_encode($info);
                }
            } catch (repository_exception $e){
                $err->e = $e->getMessage();
                die(json_encode($err));
            } catch (Exception $e) {
                $err->e = $e->getMessage();
                die(json_encode($err));
            }
            break;
        case 'upload':
            try {
                $upload = $repo->get_listing();
                $upload['client_id'] = $client_id;
                echo json_encode($upload);
            } catch (repository_exception $e){
                $err->e = $e->getMessage();
                die(json_encode($err));
            }
            break;
    }

/**
 * Small function to walk an array to attach repository ID
 */
function repository_attach_id(&$value, $key, $id){
    $value['repo_id'] = $id;
}
