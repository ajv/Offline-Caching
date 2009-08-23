<?php

require_once('../../config.php');
require_once($CFG->libdir .'/offline/lib.php');

header('Content-type: text/plain');

$version = offline_get_manifest_version(0);

$files   = array_merge(offline_get_dynamic_files(), offline_get_turbo_files());

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