<?php

/**
 * Retrieve recursively all the files in a directory, except
 * .php and system files
 *
 * @param string The directory path
 * @return string[] The array of files in the directory
 */
function offline_get_files_from_dir($dir){
    if (!is_dir($dir)) {
        return null;
    }
    $handle = opendir($dir);
    $files = array();
    while ($file = readdir($handle)) {
        $path = $dir . '/' . $file;
        if ($file !== '.' && $file !== '..' && is_dir($dir . '/'.$file) ) {
            $files = array_merge($files, offline_get_files_from_dir($dir.'/'.$file));
        }  else if (strpos($file, '.') != 0 && !strchr($file,'.php')) {
            $files[] = $path;
        }
    }
    return $files;
}

/**
 * Get all the links in a given URL
 *
 * @param string The URL
 * @return object The list of links and names
 */
function offline_get_page_links($link) {
    $ret = array();
    $dom = new domDocument;

    @$dom->loadHTML(file_get_contents($link));
    $dom->preserveWhiteSpace = false;
    $links = $dom->getElementsByTagName('a');

    foreach ($links as $tag) {
        $ret[$tag->getAttribute('href')] = $tag->childNodes->item(0)->nodeValue;
    }
    return $ret;
}

/**
 * Determine the manifest version
 *
 * @param string An existing previous version
 * @return string The new version
 */
function offline_get_manifest_version($version) {
    $dir = dirname($_SERVER['SCRIPT_FILENAME']);
    $handle = opendir($dir);
    while ($file = readdir($handle)) {
        if (file_exists("$dir/$file")) {
            $v = filemtime("$dir/$file");
            if ($v > $version) {
                $version = $v;
            }
        }
    }
    return $version;
}

/**
 * This output function will display a menu for offline mode.
 *
 * @param string $menu The original menu 
 * @return string $menu The modified menu with the offline option
 */
function offline_output_menu($menu) {
    
    global $PAGE, $CFG, $USER;

    $PAGE->requires->yui_lib('animation');
    $PAGE->requires->yui_lib('element');
    $PAGE->requires->yui_lib('container');
    $PAGE->requires->yui_lib('connection');

    $PAGE->requires->js('lib/offline/progressbar-debug.js');
    $PAGE->requires->css('lib/offline/progressbar.css');


    $PAGE->requires->string_for_js('gooffline', 'moodle');
    $PAGE->requires->string_for_js('goofflinetitle', 'moodle');
    $PAGE->requires->string_for_js('goonline', 'moodle');
    $PAGE->requires->string_for_js('goonlinetitle', 'moodle');
    $PAGE->requires->string_for_js('pleasewait', 'moodle');
    $PAGE->requires->string_for_js('cantdetectconnection', 'moodle');
    $PAGE->requires->string_for_js('mustinstallgears', 'moodle');
    $PAGE->requires->string_for_js('unavailableextlink', 'moodle');
    $PAGE->requires->string_for_js('unavailablefeature', 'moodle');

    $menu = '<div id="content" style="visibility:hidden"></div><div id="pb" style="float:left; margin-top:5px; margin-right:0em;"></div><font size="-1"><span id="pb-percentage"></span></font> <span id="offline-message"></span> <span id="offline-img"></span> <span id="offline-status"></span>'.$menu;
    
    $PAGE->requires->js('lib/offline/gears_init.js');
    $PAGE->requires->js('lib/offline/go_offline.js');
    $PAGE->requires->js_function_call('offline_init')->on_dom_ready();

    return $menu;
}

/**
 */
function offline_send_log_data($data) {
    
    global $PAGE;

    $PAGE->requires->data_for_js('logdata', $data);

}

