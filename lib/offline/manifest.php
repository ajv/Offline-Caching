<?php

require_once('../../config.php');
require_once('../../course/lib.php');

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
      else if ($file != "." && $file != ".." && $file != ".DS_Store" && !strchr($file,'.php')) {
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
	$CFG->httpswwwroot.'/',
	$CFG->httpswwwroot.'/index.php',
	$CFG->httpswwwroot.'/lib/javascript-static.js',
	$CFG->httpswwwroot.'/lib/javascript-mod.php',
	$CFG->httpswwwroot.'/lib/overlib/overlib.js',
	$CFG->httpswwwroot.'/lib/overlib/overlib_cssstyle.js',
	$CFG->httpswwwroot.'/lib/cookies.js',
	$CFG->httpswwwroot.'/lib/ufo.js',
	$CFG->httpswwwroot.'/lib/dropdown.js',
	$CFG->httpswwwroot.'/lib/offline/go_offline.js',
	$CFG->httpswwwroot.'/lib/offline/gears_init.js',
  );

$usehttps = (int)($CFG->httpswwwroot !== $CFG->wwwroot); 
$files[] = $CFG->httpswwwroot.'/lib/editor/tinymce/extra/tinymce.js.php?elanguage='.current_language().'&etheme='.current_theme().'&eusehttps='.$usehttps;

$tinymcefiles = get_files_from_dir($CFG->dirroot.'/lib/editor/tinymce');
$tinymcefiles = str_replace($CFG->libdir.'/editor/tinymce', $CFG->httpswwwroot.'/lib/editor/tinymce', $tinymcefiles);
$yuifiles = get_files_from_dir($CFG->dirroot.'/lib/yui');
$yuifiles = str_replace($CFG->libdir.'/yui', $CFG->httpswwwroot.'/lib/yui', $yuifiles);
$pixfiles = get_files_from_dir($CFG->dirroot.'/pix');
$pixfiles = str_replace($CFG->dirroot,$CFG->httpswwwroot,$pixfiles);
$themefiles = get_files_from_dir($CFG->dirroot.'/theme/'.current_theme());
$themefiles = str_replace($CFG->dirroot.'/theme',$CFG->themewww,$themefiles);

ob_start();
style_sheet_setup();
ob_end_clean();

$files = array_merge($files, $tinymcefiles, $yuifiles, $pixfiles, $themefiles, $CFG->stylesheets);

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
	/*	foreach(get_list_of_plugins() as $module){
			$files[] = $CFG->wwwroot.'/mod/'.$module.'/index.php?id='.$course->id;
		}
	*/
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
