<?php

require_once('../../config.php');
require_once($CFG->dirroot .'/course/lib.php');
require_once($CFG->dirroot . '/mod/forum/lib.php');

header('Content-type: text/plain');

/**
 * Retrieve recursively all the files in a directory, except
 * .php and system files
 *
 * @param string The directory path
 * @return string[] The array of files in the directory
 */ 
function manifest_get_files_from_dir($dir){
    $handle = opendir($dir);
    $files = array();
    while (false !== ($file = readdir($handle))) {
        if (!strchr($file,'.')) {
            $files = array_merge($files, manifest_get_files_from_dir($dir.'/'.$file));
        }
        else if (strpos($file,'.') != 0 && !strchr($file,'.php')) {
            $files[] = $dir.'/'.$file;
        }
    }
    return $files;
}

/**
 * Get all the links in a given URL
 *
 * @return object The list of links and names
 */
function manifest_get_page_links($link) {
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

foreach(get_list_of_plugins() as $module){
    $files[] = $CFG->wwwroot.'/mod/'.$module.'/icon.gif';
}


$tinymcefiles = manifest_get_files_from_dir($CFG->dirroot.'/lib/editor/tinymce');
$tinymcefiles = str_replace($CFG->dirroot.'/lib/editor/tinymce', $CFG->wwwroot.'/lib/editor/tinymce', $tinymcefiles);
$yuifiles = manifest_get_files_from_dir($CFG->dirroot.'/lib/yui');
$yuifiles = str_replace($CFG->dirroot.'/lib/yui', $CFG->wwwroot.'/lib/yui', $yuifiles);
$pixfiles = manifest_get_files_from_dir($CFG->dirroot.'/pix');
$pixfiles = str_replace($CFG->dirroot,$CFG->wwwroot,$pixfiles);
$themefiles = manifest_get_files_from_dir($CFG->dirroot.'/theme/'.current_theme());
$themefiles = str_replace($CFG->dirroot.'/theme',$CFG->themewww,$themefiles);

$files = array_merge($files, $tinymcefiles, $yuifiles, $pixfiles, $themefiles, $THEME->get_stylesheet_urls());

$entries = array();
$files = str_replace('&amp;','&', $files);
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
