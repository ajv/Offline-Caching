<?php

/**
 * Retrieve all static files for the turbo manifest
 *
 * @return string[] The array of static files
 */
function offline_get_static_files(){
	
	global $CFG, $THEME;
	
	// Include static JavaScript files
	$files = array(
	    $CFG->wwwroot.'/lib/javascript-static.js',
	    $CFG->wwwroot.'/lib/javascript-deprecated.js',
	    $CFG->wwwroot.'/lib/javascript-mod.php',
	    $CFG->wwwroot.'/lib/overlib/overlib.js',
	    $CFG->wwwroot.'/lib/overlib/overlib_cssstyle.js',
	    $CFG->wwwroot.'/lib/cookies.js',
	    $CFG->wwwroot.'/lib/ufo.js',
	    $CFG->wwwroot.'/lib/dropdown.js',
	    $CFG->wwwroot.'/mod/forum/forum.js',
	  );

	foreach(get_list_of_plugins() as $module){
	    $files[] = $CFG->wwwroot.'/mod/'.$module.'/icon.gif';
	}

	$themefiles = offline_get_files_from_dir($CFG->dirroot.'/theme/'.current_theme());
	$themefiles = str_replace($CFG->dirroot.'/theme',$CFG->themewww,$themefiles);
	
	$files = array_merge($files, $THEME->get_stylesheet_urls(), $themefiles);
	$files[] = $CFG->wwwroot.'/lib/offline/gears_init.js';
	$files = str_replace('&amp;','&', $files);
	
	return $files;
}

/**
 * Retrieve all static files for the turbo manifest
 *
 * @return string[] The array of static files
 */
function offline_get_turbo_files(){
	
	global $CFG, $THEME;
	
	require_once($CFG->dirroot .'/course/lib.php');
	require_once($CFG->libdir .'/ajax/ajaxlib.php');
	
	$pixfiles = offline_get_files_from_dir($CFG->dirroot.'/pix');
	$pixfiles = str_replace($CFG->dirroot,$CFG->wwwroot,$pixfiles);
	$tinymcefiles = offline_get_files_from_dir($CFG->dirroot.'/lib/editor/tinymce');
	$tinymcefiles = str_replace($CFG->dirroot.'/lib/editor/tinymce', $CFG->wwwroot.'/lib/editor/tinymce', $tinymcefiles);
	$yuifiles = offline_get_files_from_dir($CFG->dirroot.'/lib/yui');
	$yuifiles = str_replace($CFG->dirroot.'/lib/yui', $CFG->wwwroot.'/lib/yui', $yuifiles);

	$files = array_merge($pixfiles, $tinymcefiles, $yuifiles);
	$files = str_replace('&amp;','&', $files);
	
    return $files;
}

/**
 * Retrieve all dynamic files
 *
 * @return string[] The array of dynamic files
 */
function offline_get_dynamic_files(){
	global $CFG, $COURSE, $USER, $DB;
	
	require_once($CFG->dirroot .'/course/lib.php');
	
	// Include homepage and accessible course pages
	$files = array(
	    '.',
	    $CFG->wwwroot.'/',
	    $CFG->wwwroot.'/index.php',
	    $CFG->wwwroot.'/lib/offline/go_offline.js',
	  );

	// get all accessible courses
	if (isloggedin() and !has_capability('moodle/site:config', get_context_instance(CONTEXT_SYSTEM)) 
	    and !isguest() and empty($CFG->disablemycourses)) {

	    $courses  = get_my_courses($USER->id, 'visible DESC,sortorder ASC', array('summary'));

	} else if ((!has_capability('moodle/site:config', get_context_instance(CONTEXT_SYSTEM)) 
	    and !isguest()) or ($DB->count_records('course') <= FRONTPAGECOURSELIMIT)) {

	    $categories = get_child_categories(0);  
	    if (is_array($categories) && count($categories) == 1) {
	        $category   = array_shift($categories);
	        $courses    = get_courses_wmanagers($category->id,
	                                            'c.sortorder ASC',
	                                            array('password','summary','currency'));
	    } else {
	        $courses    = get_courses_wmanagers('all',
	                                            'c.sortorder ASC',
	                                            array('password','summary','currency'));
	    }
	    unset($categories);
	} 

	// make sure the course is visible and retrieve other modules and main course pages
	foreach ($courses as $course) {
	    if ($course->visible == 1
	        || has_capability('moodle/course:viewhiddencourses',$course->context)) {
	        $files[] = $CFG->wwwroot.'/course/view.php?id='.$course->id;

	        //Get all the module main pages
	        foreach(get_list_of_plugins() as $module){
	            if($module != 'label') {
	                $files[] = $CFG->wwwroot.'/mod/'.$module.'/index.php?id='.$course->id;
	            }
	        }

	        require_once($CFG->dirroot . '/mod/forum/lib.php');
	        //Get all the relevant forums
	        $forums = forum_get_readable_forums($USER->id, $course->id);
	        foreach ($forums as $forum) {
	            $files[] = $CFG->wwwroot.'/mod/forum/view.php?f='.$forum->id;
	        }
	        $modinfo =& get_fast_modinfo($COURSE);
	        get_all_mods($course->id, $mods, $modnames, $modnamesplural, $modnamesused);
	        foreach($mods as $mod) {
	            if ($mod->modname == 'forum') {
	                $files[] = $CFG->wwwroot.'/mod/forum/view.php?id='.$mod->id;
	                $cm = get_coursemodule_from_id('forum', $mod->id);
	                $discussions = forum_get_discussions($cm);
	                foreach($discussions as $d){ 
	                    $files[] = $CFG->wwwroot.'/mod/forum/discuss.php?d='.$d->discussion;
	                }               
	            }
	        }
	    }
	}
	$files = str_replace('&amp;','&', $files);
    return $files;
}

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
    $PAGE->requires->yui_lib('connection');
    //$PAGE->requires->yui_lib('container');

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

