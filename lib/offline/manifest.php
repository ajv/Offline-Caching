<?php

require_once('../../config.php');
require_once($CFG->dirroot .'/course/lib.php');
require_once($CFG->libdir .'/offline/lib.php');


header('Content-type: text/plain');

// Determine the manifest version
$version = offline_get_manifest_version(0);

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
