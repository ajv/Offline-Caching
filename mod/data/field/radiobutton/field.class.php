<?php // $Id$
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

class data_field_radiobutton extends data_field_base {

    var $type = 'radiobutton';

    function display_add_field($recordid=0) {
        global $CFG, $DB;

        if ($recordid){
            $content = trim($DB->get_field('data_content', 'content', array('fieldid'=>$this->field->id, 'recordid'=>$recordid)));
        } else {
            $content = '';
        }

        $str = '<div title="'.s($this->field->description).'">';
        $str .= '<fieldset><legend><span class="accesshide">'.$this->field->name.'</span></legend>';

        $i = 0;
        foreach (explode("\n",$this->field->param1) as $radio) {
            $radio = trim($radio);
            if ($radio === '') {
                continue; // skip empty lines
            }
            $str .= '<input type="radio" id="field_'.$this->field->id.'_'.$i.'" name="field_' . $this->field->id . '" ';
            $str .= 'value="' . s($radio) . '" ';

            if ($content == $radio) {
                // Selected by user.
                $str .= 'checked />';
            } else {
                $str .= '/>';
            }

            $str .= '<label for="field_'.$this->field->id.'_'.$i.'">'.$radio.'</label><br />';
            $i++;
        }
        $str .= '</fieldset>';
        $str .= '</div>';
        return $str;
    }
    
     function display_search_field($value = '') {
        global $CFG, $DB, $OUTPUT;
        $temp = $DB->get_records_sql_menu('SELECT id, content FROM {data_content} WHERE fieldid=? GROUP BY content ORDER BY content', array($this->field->id));
        $options = array();
        if(!empty($temp)) {
            $options[''] = '';              //Make first index blank.
            foreach ($temp as $key) {
                $options[$key] = $key;  //Build following indicies from the sql.
            }
        }
        return $OUTPUT->select(html_select::make($options, 'f_'.$this->field->id, $value));
    }

    function parse_search_field() {
        return optional_param('f_'.$this->field->id, '', PARAM_NOTAGS);
    }
    
    function generate_sql($tablealias, $value) {
        static $i=0;
        $i++;
        $name = "df_number_$i";
        return array(" ({$tablealias}.fieldid = {$this->field->id} AND {$tablealias}.content = :$name) ", array($name=>$value)); 
    }

}
?>
