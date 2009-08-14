<?php

global $CFG;

require_once("HTML/QuickForm/button.php");
require_once($CFG->dirroot.'/repository/lib.php');

/**
 * HTML class for a single filepicker element (based on button)
 *
 * @author       Moodle.com
 * @version      1.0
 * @since        Moodle 2.0
 * @access       public
 */
class MoodleQuickForm_filepicker extends HTML_QuickForm_input {
    protected $_helpbutton = '';
    protected $_options    = array('maxbytes'=>0, 'filetypes'=>'*', 'returnvalue'=>'*');

    function MoodleQuickForm_filepicker($elementName=null, $elementLabel=null, $attributes=null, $options=null) {
        global $CFG;
        require_once("$CFG->dirroot/repository/lib.php");

        $options = (array)$options;
        foreach ($options as $name=>$value) {
            if (array_key_exists($name, $this->_options)) {
                $this->_options[$name] = $value;
            }
        }
        if (!empty($options['maxbytes'])) {
            $this->_options['maxbytes'] = get_max_upload_file_size($CFG->maxbytes, $options['maxbytes']);
        }
        parent::HTML_QuickForm_input($elementName, $elementLabel, $attributes);

        repository_head_setup();
    }

    function setHelpButton($helpbuttonargs, $function='helpbutton') {
        if (!is_array($helpbuttonargs)) {
            $helpbuttonargs = array($helpbuttonargs);
        } else {
            $helpbuttonargs = $helpbuttonargs;
        }
        //we do this to to return html instead of printing it
        //without having to specify it in every call to make a button.
        if ('helpbutton' == $function){
            $defaultargs = array('', '', 'moodle', true, false, '', true);
            $helpbuttonargs = $helpbuttonargs + $defaultargs ;
        }
        $this->_helpbutton=call_user_func_array($function, $helpbuttonargs);
    }

    function getHelpButton() {
        return $this->_helpbutton;
    }

    function getElementTemplateType() {
        if ($this->_flagFrozen){
            return 'nodisplay';
        } else {
            return 'default';
        }
    }

    function toHtml() {
        global $CFG, $COURSE, $USER, $PAGE;


        if ($this->_flagFrozen) {
            return $this->getFrozenHtml();
        }

        $strsaved = get_string('filesaved', 'repository');
        $straddfile = get_string('openpicker', 'repository');
        $currentfile = '';
        $draftvalue  = '';
        if ($draftitemid = (int)$this->getValue()) {
            $fs = get_file_storage();
            $usercontext = get_context_instance(CONTEXT_USER, $USER->id);
            if ($files = $fs->get_area_files($usercontext->id, 'user_draft', $draftitemid, 'id DESC', false)) {
                $file = reset($files);
                $currentfile = $file->get_filename();
                $draftvalue = 'value="'.$draftitemid.'"';
            }
        }
        if ($COURSE->id == SITEID) {
            $context = get_context_instance(CONTEXT_SYSTEM);
        } else {
            $context = get_context_instance(CONTEXT_COURSE, $COURSE->id);
        }
        $client_id = uniqid();
        $repojs = repository_get_client($context, $client_id, $this->_options['filetypes'], $this->_options['returnvalue']);
        $PAGE->requires->data_for_js('filepicker', array('maxbytes'=>$this->_options['maxbytes'],'maxfiles'=>1));
        $PAGE->requires->js('lib/form/filepicker.js');

        $id     = $this->_attributes['id'];
        $elname = $this->_attributes['name'];

        $str = $this->_getTabs();
        $str .= '<input type="hidden" name="'.$elname.'" id="'.$id.'" '.$draftvalue.' />';
        $str .= $repojs;

        $str .= <<<EOD
<a href="#nonjsfp" onclick="return launch_filepicker('$id', '$client_id', '$draftvalue')">$straddfile</a>
<span id="file_info_{$client_id}" class="notifysuccess">$currentfile</span>

<noscript>
<a name="nonjsfp"></a>
<object type="text/html" data="{$CFG->httpswwwroot}/repository/filepicker.php?action=embedded&itemid={$draftitemid}&ctx_id=$context->id" height="300" width="800" style="border:1px solid #000">Error</object>
</noscript>
EOD;
        return $str;
    }

    function exportValue(&$submitValues, $assoc = false) {
        global $USER;

        // make sure max one file is present and it is not too big
        if ($draftitemid = $submitValues[$this->_attributes['name']]) {
            $fs = get_file_storage();
            $usercontext = get_context_instance(CONTEXT_USER, $USER->id);
            if ($files = $fs->get_area_files($usercontext->id, 'user_draft', $draftitemid, 'id DESC', false)) {
                $file = array_shift($files);
                if ($this->_options['maxbytes'] and $file->get_filesize() > $this->_options['maxbytes']) {
                    // bad luck, somebody tries to sneak in oversized file
                    $file->delete();
                }
                foreach ($files as $file) {
                    // only one file expected
                    $file->delete();
                }
            }
        }

        return array($this->_attributes['name'] => $submitValues[$this->_attributes['name']]);
    }
}
