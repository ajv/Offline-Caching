<?php

require_once('../../config.php');
require_once($CFG->dirroot .'/course/lib.php');

//define('MOODLE_INTERNAL', FALSE);


header('Content-type: text/plain');

/**
 * Retrieve recursively all the files in a directory, except
 * .php and system files
 *
 * @uses $_SERVER
 * @param string The directory path
 * @return string[] The array of files in the directory
 */ 
function get_files_from_dir($dir){
	$handle = opendir($dir);
	$files = array();
	while (false !== ($file = readdir($handle))) {
	  if (!strchr($file,'.')) {
        $files = array_merge($files, get_files_from_dir($dir.'/'.$file));
	  }
      else if (strpos($file,'.') != 0 && !strchr($file,'.php')) {
	    $files[] = $dir.'/'.$file;
	  }
	}
	return $files;
}

// Determine the manifest version
$version = 0;
$dir = dirname($_SERVER['SCRIPT_FILENAME']);
$handle = opendir($dir);
while (false !== ($file = readdir($handle))) {
  	if (file_exists("$dir/$file")) {
    	$v = filemtime("$dir/$file");
    	if ($v > $version) {
      		$version = $v;
    	}
  	}
}

// Include homepage, static files and accessible course pages
$files = array(
	'.',
	$CFG->wwwroot.'/',
	$CFG->wwwroot.'/index.php',
	$CFG->wwwroot.'/lib/javascript-static.js',
	$CFG->wwwroot.'/lib/javascript-mod.php',
	$CFG->wwwroot.'/lib/overlib/overlib.js',
	$CFG->wwwroot.'/lib/overlib/overlib_cssstyle.js',
	$CFG->wwwroot.'/lib/cookies.js',
	$CFG->wwwroot.'/lib/ufo.js',
	$CFG->wwwroot.'/lib/dropdown.js',
	$CFG->wwwroot.'/lib/offline/go_offline.js',
	$CFG->wwwroot.'/lib/offline/gears_init.js',
  );

//$use = (int)($CFG->wwwroot !== $CFG->wwwroot); 
//$files[] = $CFG->wwwroot.'/lib/editor/tinymce/extra/tinymce.js.php?elanguage='.current_language().'&etheme='.current_theme().'&euse='.$use;

foreach(get_list_of_plugins() as $module){
    $files[] = $OUTPUT->mod_icon_url('icon', $module);
}


$tinymcefiles = get_files_from_dir($CFG->dirroot.'/lib/editor/tinymce');
$tinymcefiles = str_replace($CFG->dirroot.'/lib/editor/tinymce', $CFG->wwwroot.'/lib/editor/tinymce', $tinymcefiles);
$yuifiles = get_files_from_dir($CFG->dirroot.'/lib/yui');
$yuifiles = str_replace($CFG->dirroot.'/lib/yui', $CFG->wwwroot.'/lib/yui', $yuifiles);
$pixfiles = get_files_from_dir($CFG->dirroot.'/pix');
$pixfiles = str_replace($CFG->dirroot,$CFG->wwwroot,$pixfiles);
$themefiles = get_files_from_dir($CFG->dirroot.'/theme/'.current_theme());
$themefiles = str_replace($CFG->dirroot.'/theme',$CFG->themewww,$themefiles);

$files = array_merge($files, $tinymcefiles, $yuifiles, $pixfiles, $themefiles, $THEME->get_stylesheet_urls());

// accessible courses if logged in as admin
if (isloggedin() and !has_capability('moodle/site:config', get_context_instance(CONTEXT_SYSTEM)) and !isguest() and empty($CFG->disablemycourses)) {
    $courses  = get_my_courses($USER->id, 'visible DESC,sortorder ASC', array('summary'));
} else if ((!has_capability('moodle/site:config', get_context_instance(CONTEXT_SYSTEM)) and !isguest()) or ($DB->count_records('course') <= FRONTPAGECOURSELIMIT)) {
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
// all visible courses
foreach ($courses as $course) {
    if ($course->visible == 1
        || has_capability('moodle/course:viewhiddencourses',$course->context)) {
        $files[] = $CFG->wwwroot.'/course/view.php?id='.$course->id;
		foreach(get_list_of_plugins() as $module){
			$files[] = $CFG->wwwroot.'/mod/'.$module.'/index.php?id='.$course->id;
		}	 
    }
}


$entries = array();
foreach ($files as $file) {
  array_push($entries, "    {\"url\": \"$file\"}");
}
?>
{
  "betaManifestVersion": 1,
  "version": "<?php echo $version; ?>",
  "entries": [
<?php echo implode(",\n", $entries); ?>

  ]
}
