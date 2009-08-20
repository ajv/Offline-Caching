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

class data_field_menu extends data_field_base {

    var $type = 'menu';

    function display_add_field($recordid=0) {
        global $DB, $OUTPUT;

        if ($recordid){
            $content = $DB->get_field('data_content', 'content', array('fieldid'=>$this->field->id, 'recordid'=>$recordid));
            $content = trim($content);
        } else {
            $content = '';
        }

        $str = '<div title="'.s($this->field->description).'">';

        $rawoptions = explode("\n",$this->field->param1);
        foreach ($rawoptions as $option) {
            $option = trim($option);
            if ($option) {
                $options[$option] = $option;
            }
        }
        

        $select = html_select::make($options, 'field_'.$this->field->id, $content, get_string('menuchoose', 'data'));
        $select->nothingvalue = '';
        $select->id = 'field_'.$this->field->id;

        $str .= $OUTPUT->select($select);

        $str .= '</div>';

        return $str;
    }
    
    function display_search_field($content = '') {
        global $CFG, $DB, $OUTPUT;

        $usedoptions = array();
        $sql = "SELECT DISTINCT content
                  FROM {data_content}
                 WHERE fieldid=: AND content IS NOT NULL";
        if ($used = $DB->get_records_sql($sql, array($this->field->id))) {
            foreach ($used as $data) {
                $value = $data->content;
                if ($value === '') {
                    continue;
                }
                $usedoptions[$value] = $value;
            }
        }

        $options = array();
        foreach (explode("\n",$this->field->param1) as $option) {
            $option = trim($option);
            if (!isset($usedoptions[$option])) {
                continue;
            }
            $options[$option] = $option;
        }
        if (!$options) {
            // oh, nothing to search for
            return '';
        }

        return $OUTPUT->select(html_select::make($options, 'f_'.$this->field->id, $content, '&nbsp;'));    
    }

     function parse_search_field() {
            return optional_param('f_'.$this->field->id, '', PARAM_NOTAGS);
     }

    function generate_sql($tablealias, $value) {
        static $i=0;
        $i++;
        $name = "df_menu_$i";
        return array(" ({$tablealias}.fieldid = {$this->field->id} AND {$tablealias}.content = :$name) ", array($name=>$value)); 
    }

}

?>
