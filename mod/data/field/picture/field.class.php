<?php  // $Id$
///////////////////////////////////////////////////////////////////////////
//                                                                       //
// NOTICE OF COPYRIGHT                                                   //
//                                                                       //
// Moodle - Modular Object-Oriented Dynamic Learning Environment         //
//          http://moodle.org                                            //
//                                                                       //
// Copyright (C) 1999-onwards Moodle Pty Ltd  http://moodle.com          //
//                                                                       //
// This program is free software; you can redistribute it and/or modify  //
// it under the terms of the GNU General Public License as published by  //
// the Free Software Foundation; either version 2 of the License, or     //
// (at your option) any later version.                                   //
//                                                                       //
// This program is distributed in the hope that it will be useful,       //
// but WITHOUT ANY WARRANTY; without even the implied warranty of        //
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the         //
// GNU General Public License for more details:                          //
//                                                                       //
//          http://www.gnu.org/copyleft/gpl.html                         //
//                                                                       //
///////////////////////////////////////////////////////////////////////////

class data_field_picture extends data_field_base {
    var $type = 'picture';
    var $previewwidth  = 50;
    var $previewheight = 50;

    function display_add_field($recordid=0) {
        global $CFG, $DB;

        $file        = false;
        $content     = false;
        $displayname = '';
        $alttext     = '';
        $fs = get_file_storage();
        if ($recordid){
            if ($content = $DB->get_record('data_content', array('fieldid'=>$this->field->id, 'recordid'=>$recordid))) {
                if (!empty($content->content)) {
                    if ($file = $FS->get_file($this->context->id, 'data_content', $content->id, '/', $content->content)) {
                        if (empty($content->content1)) {
                            $displayname = $file->get_filename();
                        } else {
                            $displayname = $content->content1;
                        }
                    }
                }
                $alttext = $content->content1;
            }
        }

        $str = '<div title="'.s($this->field->description).'">';
        $str .= '<fieldset><legend><span class="accesshide">'.$this->field->name.'</span></legend>';
        $str .= '<input type="hidden" name ="field_'.$this->field->id.'_file" id="field_'.$this->field->id.'_file"  value="fakevalue" />';
        $str .= '<label for="field_'.$this->field->id.'">'.get_string('picture','data'). '</label>&nbsp;<input type="file" name ="field_'.$this->field->id.'" id="field_'.$this->field->id.'" /><br />';
        $str .= '<label for="field_'.$this->field->id.'_alttext">'.get_string('alttext','data') .'</label>&nbsp;<input type="text" name="field_'
                .$this->field->id.'_alttext" id="field_'.$this->field->id.'_alttext" value="'.s($alttext).'" /><br />';
        //$str .= '<input type="hidden" name="MAX_FILE_SIZE" value="'.s($this->field->param3).'" />';
        if ($file) {
            $browser = get_file_browser();
            $src     = file_encode_url($CFG->wwwroot.'/pluginfile.php', $this->context->id.'/data_content/'.$content->id.'/'.$file->get_filename());
            $str .= '<img width="'.s($this->previewwidth).'" height="'.s($this->previewheight).'" src="'.$src.'" alt="" />';
        }
        $str .= '</fieldset>';
        $str .= '</div>';

        return $str;
    }

    // TODO delete this function and instead subclass data_field_file - see MDL-16493

    function get_file($recordid, $content=null) {
        global $DB;
        if (empty($content)) {
            if (!$content = $DB->get_record('data_content', array('fieldid'=>$this->field->id, 'recordid'=>$recordid))) {
                return null;
            }
        }
        $fs = get_file_storage();
        if (!$file = $fs->get_file($this->context->id, 'data_content', $content->id, '/', $content->content)) {
            return null;
        }

        return $file;
    }

    function display_search_field($value = '') {
        return '<input type="text" size="16" name="f_'.$this->field->id.'" value="'.$value.'" />';
    }

    function parse_search_field() {
        return optional_param('f_'.$this->field->id, '', PARAM_NOTAGS);
    }

    function generate_sql($tablealias, $value) {
        global $DB;

        $ILIKE = $DB->sql_ilike();

        static $i=0;
        $i++;
        $name = "df_picture_$i";
        return array(" ({$tablealias}.fieldid = {$this->field->id} AND {$tablealias}.content $ILIKE :$name) ", array($name=>"%$value%"));
    }

    function display_browse_field($recordid, $template) {
        global $CFG, $DB;

        if (!$content = $DB->get_record('data_content', array('fieldid'=>$this->field->id, 'recordid'=>$recordid))) {
            return false;
        }

        if (empty($content->content)) {
            return '';
        }

        $browser = get_file_browser();

        $alt   = $content->content1;
        $title = $alt;

        if ($template == 'listtemplate') {
            $src = file_encode_url($CFG->wwwroot.'/pluginfile.php', '/'.$this->context->id.'/data_content/'.$content->id.'/'.'thumb_'.$content->content);
            // no need to add width/height, because the thumb is resized properly
            $str = '<a href="view.php?d='.$this->field->dataid.'&amp;rid='.$recordid.'"><img src="'.$src.'" alt="'.s($alt).'" title="'.s($title).'" style="border:0px" /></a>';

        } else {
            $src = file_encode_url($CFG->wwwroot.'/pluginfile.php', '/'.$this->context->id.'/data_content/'.$content->id.'/'.$content->content);
            $width  = $this->field->param1 ? ' width="'.s($this->field->param1).'" ':' ';
            $height = $this->field->param2 ? ' height="'.s($this->field->param2).'" ':' ';
            $str = '<a href="'.$src.'"><img '.$width.$height.' src="'.$src.'" alt="'.s($alt).'" title="'.s($title).'" style="border:0px" /></a>';
        }

        return $str;
    }

    function update_field() {
        global $DB, $OUTPUT;

        // Get the old field data so that we can check whether the thumbnail dimensions have changed
        $oldfield = $DB->get_record('data_fields', array('id'=>$this->field->id));
        $DB->update_record('data_fields', $this->field);

        // Have the thumbnail dimensions changed?
        if ($oldfield && ($oldfield->param4 != $this->field->param4 || $oldfield->param5 != $this->field->param5)) {
            // Check through all existing records and update the thumbnail
            if ($contents = $DB->get_records('data_content', array('fieldid'=>$this->field->id))) {
                $fs = get_file_storage();
                if (count($contents) > 20) {
                    echo $OUTPUT->notification(get_string('resizingimages', 'data'), 'notifysuccess');
                    echo "\n\n";
                    // To make sure that ob_flush() has the desired effect
                    ob_flush();
                }
                foreach ($contents as $content) {
                    if (!$file = $fs->get_file($this->context->id, 'data_content', $content->id, '/', $content->content)) {
                        continue;
                    }
                    if ($thumbfile = $fs->get_file($this->context->id, 'data_content', $content->id, '/', 'thumb_'.$content->content)) {
                        $thumbfile->delete();
                    }
                    @set_time_limit(300);
                    // Might be slow!
                    $this->update_thumbnail($content, $file);
                }
            }
        }
        return true;
    }

    function update_content($recordid, $value, $name) {
        global $CFG, $DB;

        if (!$content = $DB->get_record('data_content', array('fieldid'=>$this->field->id, 'recordid'=>$recordid))) {
        // Quickly make one now!
            $content = new object();
            $content->fieldid  = $this->field->id;
            $content->recordid = $recordid;
            $id = $DB->insert_record('data_content', $content);
            $content = $DB->get_record('data_content', array('id'=>$id));
        }

        $names = explode('_', $name);
        switch ($names[2]) {
            case 'file':
                // file just uploaded
                $tmpfile = $_FILES[$names[0].'_'.$names[1]];
                $filename = $tmpfile['name'];
                $pathanme = $tmpfile['tmp_name'];
                if ($filename){
                    $fs = get_file_storage();
                    // TODO: uploaded file processing will be in file picker ;-)
                    $fs->delete_area_files($this->context->id, 'data_content', $content->id);
                    $file_record = array('contextid'=>$this->context->id, 'filearea'=>'data_content', 'itemid'=>$content->id, 'filepath'=>'/', 'filename'=>$filename);
                    if ($file = $fs->create_file_from_pathname($file_record, $pathanme)) {
                        $content->content = $file->get_filename();
                        $DB->update_record('data_content', $content);
                        // Regenerate the thumbnail
                        $this->update_thumbnail($content, $file);
                    }
                }
                break;

            case 'alttext':
                // only changing alt tag
                $content->content1 = clean_param($value, PARAM_NOTAGS);
                $DB->update_record('data_content', $content);
                break;

            default:
                break;
        }
    }

    function update_thumbnail($content, $file) {
        // (Re)generate thumbnail image according to the dimensions specified in the field settings.
        // If thumbnail width and height are BOTH not specified then no thumbnail is generated, and
        // additionally an attempted delete of the existing thumbnail takes place.
        $fs = get_file_storage();
        $file_record = array('contextid'=>$file->get_contextid(), 'filearea'=>$file->get_filearea(),
                             'itemid'=>$file->get_itemid(), 'filepath'=>$file->get_filepath(),
                             'filename'=>'thumb_'.$file->get_filename(), 'userid'=>$file->get_userid());
        try {
            // this may fail for various reasons
            $fs->convert_image($file_record, $file, $this->field->param4, $this->field->param5, true);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    function notemptyfield($value, $name) {
        $names = explode('_',$name);
        if ($names[2] == 'file') {
            $filename = $_FILES[$names[0].'_'.$names[1]];
            return !empty($filename['name']);
            // if there's a file in $_FILES, not empty
        }
        return false;
    }

    function text_export_supported() {
        return false;
    }

    function file_ok($path) {
        return true;
    }
}

?>
