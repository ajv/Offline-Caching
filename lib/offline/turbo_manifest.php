<?php

require_once('../../config.php');
require_once($CFG->dirroot .'/course/lib.php');
require_once($CFG->libdir .'/offline/lib.php');
require_once($CFG->libdir .'/ajax/ajaxlib.php');


header('Content-type: text/plain');

// Determine the manifest version
$version = offline_get_manifest_version(0);

// Include static JavaScript files
$files = array(
    $CFG->wwwroot.'/lib/offline/gears_init.js',
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
$pixfiles = offline_get_files_from_dir($CFG->dirroot.'/pix');
$pixfiles = str_replace($CFG->dirroot,$CFG->wwwroot,$pixfiles);
$tinymcefiles = offline_get_files_from_dir($CFG->dirroot.'/lib/editor/tinymce');
$tinymcefiles = str_replace($CFG->dirroot.'/lib/editor/tinymce', $CFG->wwwroot.'/lib/editor/tinymce', $tinymcefiles);
$yuifiles = offline_get_files_from_dir($CFG->dirroot.'/lib/yui');
$yuifiles = str_replace($CFG->dirroot.'/lib/yui', $CFG->wwwroot.'/lib/yui', $yuifiles);

$files = array_merge($files, $THEME->get_stylesheet_urls(), $themefiles, $pixfiles, $tinymcefiles, $yuifiles);

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