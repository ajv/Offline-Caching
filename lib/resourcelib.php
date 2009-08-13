<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Recourse module like helper functions
 *
 * @package   moodlecore
 * @copyright 2009 Petr Skoda (http://skodak.org)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Try the best way */
define('RESOURCELIB_DISPLAY_AUTO', 0);
/** Display using object tag */
define('RESOURCELIB_DISPLAY_EMBED', 1);
/** Display inside frame */
define('RESOURCELIB_DISPLAY_FRAME', 2);
/** Display normal link in new window */
define('RESOURCELIB_DISPLAY_NEW', 3);
/** Force download of file instead of display */
define('RESOURCELIB_DISPLAY_DOWNLOAD', 4);
/** Open directly */
define('RESOURCELIB_DISPLAY_OPEN', 5);
/** Open in "emulated" pop-up without navigation */
define('RESOURCELIB_DISPLAY_POPUP', 6);

/** Legacy files not needed or new resource */
define('RESOURCELIB_LEGACYFILES_NO', 0);
/** Legacy files conversion marked as completed */
define('RESOURCE_LEGACYYFILES_DONE', 1);
/** Legacy files conversion in progress*/
define('RESOURCELIB_LEGACYFILES_ACTIVE', 2);


/**
 * Returns list of available display options
 * @param array $enabled list of options enabled in module configuration
 * @param int $current current dispaly options for existing instances
 * @return array of key=>name pairs
 */
function resourcelib_get_displayoptions(array $enabled, $current=null) {
    if (is_number($current)) {
        $enabled[] = $current;
    }

    $options = array(RESOURCELIB_DISPLAY_AUTO     => get_string('displayauto', 'resource'),
                     RESOURCELIB_DISPLAY_EMBED    => get_string('displayembed', 'resource'),
                     RESOURCELIB_DISPLAY_FRAME    => get_string('displayframe', 'resource'),
                     RESOURCELIB_DISPLAY_NEW      => get_string('displaynew', 'resource'),
                     RESOURCELIB_DISPLAY_DOWNLOAD => get_string('displaydownload', 'resource'),
                     RESOURCELIB_DISPLAY_OPEN     => get_string('displayopen', 'resource'),
                     RESOURCELIB_DISPLAY_POPUP    => get_string('displaypopup', 'resource'));

    $result = array();

    foreach ($options as $key=>$value) {
        if (in_array($key, $enabled)) {
            $result[$key] = $value;
        }
    }

    if (empty($result)) {
        // there should be always something in case admin misconfigures module
        $result[RESOURCELIB_DISPLAY_OPEN] = $options[RESOURCELIB_DISPLAY_OPEN];
    }

    return $result;
}

/**
 * Tries to guess correct mimetype for arbitrary URL
 * @param string $fullurl
 * @return string mimetype
 */
function resourcelib_guess_url_mimetype($fullurl) {
    global $CFG;
    require_once("$CFG->libdir/filelib.php");

    if (preg_match("|^(.*)/[a-z]*file.php(\?file=)?(/[^&\?]*)|", $fullurl, $matches)) {
        // remove the special moodle file serving hacks so that the *file.php is ignored
        $fullurl = $matches[1].$matches[3];
    }

    if (strpos($fullurl, '.php')){
        // we do not really know what is in general php script
        return 'text/html';

    } else if (substr($fullurl, -1) === '/') {
        // directory index (http://example.com/smaples/)
        return 'text/html';

    } else if (strpos($fullurl, '//') !== false and substr_count($fullurl, '/') == 2) {
        // just a host name (http://example.com), solves Australian servers "audio" problem too
        return 'text/html';

    } else {
        // ok, this finally looks like a real file
        return mimeinfo('type', $fullurl);
    }
}

/**
 * Returns image embedding html.
 * @param string $fullurl
 * @param string $title
 * @return string html
 */
function resourcelib_embed_image($fullurl, $title) {
    $code = '';
    $code .= '<div class="resourcecontent resourceimg">';
    $code .= "<img title=\"".strip_tags(format_string($title))."\" class=\"resourceimage\" src=\"$fullurl\" alt=\"\" />";
    $code .= '</div>';

    return $code;
}

/**
 * Returns mp3 embedding html.
 * @param string $fullurl
 * @param string $title
 * @param string $clicktoopen
 * @return string html
 */
function resourcelib_embed_mp3($fullurl, $title, $clicktoopen) {
    global $CFG, $THEME, $PAGE;

    if (!empty($THEME->resource_mp3player_colors)) {
        $c = $THEME->resource_mp3player_colors;   // You can set this up in your theme/xxx/config.php
    } else {
        $c = 'bgColour=000000&btnColour=ffffff&btnBorderColour=cccccc&iconColour=000000&'.
             'iconOverColour=00cc00&trackColour=cccccc&handleColour=ffffff&loaderColour=ffffff&'.
             'font=Arial&fontColour=FF33FF&buffer=10&waitForPlay=no&autoPlay=yes';
    }
    $c .= '&volText='.get_string('vol', 'resource').'&panText='.get_string('pan','resource');
    $id = 'filter_mp3_'.time(); //we need something unique because it might be stored in text cache

    $ufoargs = array('movie'        => $CFG->wwwroot.'/lib/mp3player/mp3player.swf?src='.addslashes_js($fullurl),
                     'width'        => 600,
                     'height'       => 70,
                     'majorversion' => 6,
                     'build'        => 40,
                     'flashvars'    => $c,
                     'quality'      => 'high');

    // If we have Javascript, use UFO to embed the MP3 player, otherwise depend on plugins
    $code = <<<OET
<div class="resourcecontent resourcemp3">
  <span class="mediaplugin mediaplugin_mp3" id="$id"></span>
  <noscript>
    <object type="audio/mpeg" data="$fullurl" width="600" height="70">
      <param name="src" value="$fullurl" />
      <param name="quality" value="high" />
      <param name="autoplay" value="true" />
      <param name="autostart" value="true" />
    </object>
    $clicktoopen
  </noscript>
</div>
OET;

    $PAGE->requires->yui_lib('dom')->in_head();
    $PAGE->requires->js('lib/ufo.js')->in_head();
    $PAGE->requires->js('lib/resourcelib.js')->in_head();
    $code .= $PAGE->requires->data_for_js('FO', $ufoargs)->asap();
    $code .= $PAGE->requires->js_function_call('resourcelib_create_UFO_object', array($id))->asap();
    return $code;
}

/**
 * Returns flash video embedding html.
 * @param string $fullurl
 * @param string $title
 * @param string $clicktoopen
 * @return string html
 */
function resourcelib_embed_flashvideo($fullurl, $title, $clicktoopen) {
    global $CFG, $PAGE;

    $id = 'filter_flv_'.time(); //we need something unique because it might be stored in text cache

    $ufoargs = array('movie'             => $CFG->wwwroot.'/filter/mediaplugin/flvplayer.swf?file='.addslashes_js($fullurl),
                     'width'             => 600,
                     'height'            => 400,
                     'majorversion'      => 6,
                     'build'             => 40,
                     'allowscriptaccess' => 'never',
                     'allowfullscreen'   => 'true',
                     'quality'           => 'high');

    // If we have Javascript, use UFO to embed the FLV player, otherwise depend on plugins

    $code = <<<EOT
<div class="resourcecontent resourceflv">
  <span class="mediaplugin mediaplugin_flv" id="$id"></span>
  <noscript>
    <object type="video/x-flv" data="$fullurl" width="600" height="400">
      <param name="src" value="$fullurl" />
      <param name="quality" value="high" />
      <param name="autoplay" value="true" />
      <param name="autostart" value="true" />
    </object>
    $clicktoopen
  </noscript>
</div>
EOT;

    $PAGE->requires->yui_lib('dom')->in_head();
    $PAGE->requires->js('lib/ufo.js')->in_head();
    $PAGE->requires->js('lib/resourcelib.js')->in_head();
    $code .= $PAGE->requires->data_for_js('FO', $ufoargs)->asap();
    $code .= $PAGE->requires->js_function_call('resourcelib_create_UFO_object', array($id))->asap();
    return $code;
}

/**
 * Returns flash embedding html.
 * @param string $fullurl
 * @param string $title
 * @param string $clicktoopen
 * @return string html
 */
function resourcelib_embed_flash($fullurl, $title, $clicktoopen) {
    $code = <<<EOT
<div class="resourcecontent resourceswf">
  <object classid="clsid:D27CDB6E-AE6D-11cf-96B8-444553540000">
    <param name="movie" value="$fullurl" />
    <param name="autoplay" value="true" />
    <param name="loop" value="true" />
    <param name="controller" value="true" />
    <param name="scale" value="aspect" />
    <param name="base" value="." />
<!--[if !IE]>-->
    <object type="application/x-shockwave-flash" data="$fullurl">
      <param name="controller" value="true" />
      <param name="autoplay" value="true" />
      <param name="loop" value="true" />
      <param name="scale" value="aspect" />
      <param name="base" value="." />
<!--<![endif]-->
$clicktoopen
<!--[if !IE]>-->
    </object>
<!--<![endif]-->
  </object>
</div>
EOT;

    return $code;
}

/**
 * Returns ms media embedding html.
 * @param string $fullurl
 * @param string $title
 * @param string $clicktoopen
 * @return string html
 */
function resourcelib_embed_mediaplayer($fullurl, $title, $clicktoopen) {
    $code = <<<EOT
<div class="resourcecontent resourcewmv">
  <object type="video/x-ms-wmv" data="$fullurl">
    <param name="controller" value="true" />
    <param name="autostart" value="true" />
    <param name="src" value="$fullurl" />
    <param name="scale" value="noScale" />
    $clicktoopen
  </object>
</div>
EOT;

    return $code;
}

/**
 * Returns quicktime embedding html.
 * @param string $fullurl
 * @param string $title
 * @param string $clicktoopen
 * @return string html
 */
function resourcelib_embed_quicktime($fullurl, $title, $clicktoopen) {
    $code = <<<EOT
<div class="resourcecontent resourceqt">
  <object classid="clsid:02BF25D5-8C17-4B23-BC80-D3488ABDDC6B" codebase="http://www.apple.com/qtactivex/qtplugin.cab">
    <param name="src" value="$fullurl" />
    <param name="autoplay" value="true" />
    <param name="loop" value="true" />
    <param name="controller" value="true" />
    <param name="scale" value="aspect" />
<!--[if !IE]>-->
    <object type="video/quicktime" data="$fullurl">
      <param name="controller" value="true" />
      <param name="autoplay" value="true" />
      <param name="loop" value="true" />
      <param name="scale" value="aspect" />
<!--<![endif]-->
$clicktoopen
<!--[if !IE]>-->
    </object>
<!--<![endif]-->
  </object>
</div>
EOT;

    return $code;
}

/**
 * Returns mpeg embedding html.
 * @param string $fullurl
 * @param string $title
 * @param string $clicktoopen
 * @return string html
 */
function resourcelib_embed_mpeg($fullurl, $title, $clicktoopen) {
    $code = <<<EOT
<div class="resourcecontent resourcempeg">
  <object classid="CLSID:22d6f312-b0f6-11d0-94ab-0080c74c7e95" codebase="http://activex.microsoft.com/activex/controls/mplayer/en/nsm p2inf.cab#Version=5,1,52,701" type="application/x-oleobject">
    <param name="fileName" value="$fullurl" />
    <param name="autoStart" value="true" />
    <param name="animationatStart" value="true" />
    <param name="transparentatStart" value="true" />
    <param name="showControls" value="true" />
    <param name="Volume" value="-450" />
<!--[if !IE]>-->
    <object type="video/mpeg" data="$fullurl">
      <param name="controller" value="true" />
      <param name="autostart" value="true" />
      <param name="src" value="$fullurl" />
<!--<![endif]-->
$clicktoopen
<!--[if !IE]>-->
    </object>
<!--<![endif]-->
  </object>
</div>
EOT;

    return $code;
}

/**
 * Returns real media embedding html.
 * @param string $fullurl
 * @param string $title
 * @param string $clicktoopen
 * @return string html
 */
function resourcelib_embed_real($fullurl, $title, $clicktoopen) {
    $code = <<<EOT
<div class="resourcecontent resourcerm">
  <object classid="clsid:CFCDAA03-8BE4-11cf-B84B-0020AFBBCCFA" width="320" height="240">
    <param name="src" value="$fullurl" />
    <param name="controls" value="All" />
<!--[if !IE]>-->
    <object type="audio/x-pn-realaudio-plugin" data="$fullurl" width="320" height="240">
      <param name="controls" value="All" />
<!--<![endif]-->
$clicktoopen
<!--[if !IE]>-->
    </object>
<!--<![endif]-->
  </object>
</div>
EOT;

    return $code;
}

/**
 * Returns general link or file embedding html.
 * @param string $fullurl
 * @param string $title
 * @param string $clicktoopen
 * @return string html
 */
function resourcelib_embed_general($fullurl, $title, $clicktoopen, $mimetype) {
    global $CFG, $PAGE;

    $iframe = false;
    // IE can not embed stuff properly if stored on different server
    // that is why we use iframe instead, unfortunately this tag does not validate
    // in xhtml strict mode
    if ($mimetype === 'text/html' and check_browser_version('MSIE', 5)) {
        if (preg_match('(^https?://[^/]*)', $fullurl, $matches)) {
            if (strpos($CFG->wwwroot, $matches[0]) !== 0) {
                $iframe = true;
            }
        }
    }

    if ($iframe) {
        $code = <<<EOT
<div class="resourcecontent resourcegeneral">
  <iframe id="resourceobject" src="$fullurl">
    $clicktoopen
  </iframe>
</div>
EOT;
    } else {
        $code = <<<EOT
<div class="resourcecontent resourcegeneral">
  <object id="resourceobject" data="$fullurl" type="$mimetype">
    <param name="src" value="$fullurl" />
    $clicktoopen
  </object>
</div>
EOT;
    }

    return $code;
}
