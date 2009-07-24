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
