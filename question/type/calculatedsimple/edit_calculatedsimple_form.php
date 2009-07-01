<?php  // $Id$
/**
 * Defines the editing form for the calculated simplequestion type.
 *
 * @copyright &copy; 2007 Jamie Pratt
 * @author Jamie Pratt me@jamiep.org
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package questionbank
 * @subpackage questiontypes
 */

/**
 * calculatedsimple editing form definition.
 */
class question_edit_calculatedsimple_form extends question_edit_form {
    /**
     * Handle to the question type for this question.
     *
     * @var question_calculatedsimple_qtype
     */
    var $qtypeobj;

    var $wildcarddisplay ;

    var $questiondisplay ;

    public $datasetdefs;

    public $reload = false ;
    
    public $maxnumber = -1;

    public $regenerate = true;

    public $noofitems;
    
    public $outsidelimit = false ;
    
    public $commentanswer = array(); 
    
    public $answer = array();

    public $nonemptyanswer = array();
    
    public $numbererrors = array();

    public $formdata = array();      

     

    function question_edit_calculatedsimple_form(&$submiturl, &$question, &$category, &$contexts, $formeditable = true){
        global $QTYPES, $SESSION, $CFG, $DB;
        $this->regenerate = true;
        $this->question = $question;
        $this->qtypeobj =& $QTYPES[$this->question->qtype];
        //get the dataset definitions for this question
        //coming here everytime even when using a NoSubmitButton
        //so this will only set the values to the actual question database content which is not what we want
        //so this should be removed from here
        // get priority to paramdatasets
        
        if  (  "1" == optional_param('reload','', PARAM_INT )) {
            $this->reload = true ;
        }else {
            $this->reload = false ;
        }
        if(!$this->reload ){ // use database data as this is first pass 
            // question->id == 0 so no stored datasets
            // else get datasets 
            if ( !empty($question->id)) {
                if (empty($question->options)) {
                    $this->get_question_options($question);
                }
                $this->datasetdefs = $this->qtypeobj->get_dataset_definitions($question->id, array());
            
                if(!empty($this->datasetdefs)){
                    foreach ($this->datasetdefs as $defid => $datasetdef) {
                        // first get the items in case their number does not correspond to itemcount        
                        if (isset($datasetdef->id)) {
                            $this->datasetdefs[$defid]->items = $this->qtypeobj->get_database_dataset_items($datasetdef->id);
                            if ( $this->datasetdefs[$defid]->items != '') {
                                $datasetdef->itemcount = count($this->datasetdefs[$defid]->items);
                            } else {
                                $datasetdef->itemcount = 0 ;
                            }
                        }
                        // Get maxnumber
                        if ($this->maxnumber == -1 || $datasetdef->itemcount < $this->maxnumber) {
                            $this->maxnumber = $datasetdef->itemcount;
                        }
                    }
                }

                $i = 0 ;
                foreach($this->question->options->answers as $answer){
                     $this->answer[$i] = $answer ;
                     $i++;
                } 
                $this->nonemptyanswer = $this->answer ;
            }        
            $datasettoremove = false;
            $newdatasetvalues = false ; 
            $newdataset = false ; 
        }else { 
            // handle reload to get values from the form-elements
            // answers, datasetdefs and data_items
            // verify for the specific dataset values as the other parameters 
            // unints, feeedback etc are handled elsewhere
            // handle request buttons :
            //    'analyzequestion' (Identify the wild cards {x..} present in answers) 
            //    'addbutton' (create new set of datatitems)
            //    'updatedatasets' is handled automatically on each reload
            // The analyzequestion is done every time on reload  
            // to detect any new wild cards so that the current display reflects
            // the mandatory (i.e. in answers) datasets
            //  to implement : don't do any changes if the question is used in a quiz.
            // If new datadef, new properties should erase items. 
            $dummyform = new stdClass();
            $mandatorydatasets = array();

            if  (  $dummyform->answer =optional_param('answer')) { // there is always at least one answer...
                $fraction = optional_param('fraction') ;
                $feedback = optional_param('feedback') ;
                $tolerance = optional_param('tolerance') ;
                $tolerancetype = optional_param('tolerancetype') ;
                $correctanswerlength = optional_param('correctanswerlength') ;
                $correctanswerformat = optional_param('correctanswerformat') ;
                
                foreach( $dummyform->answer as $key => $answer ) {
                    if(trim($answer) != ''){  // just look for non-empty 
                        $this->answer[$key]=new stdClass();
                        $this->answer[$key]->answer = $answer;
                        $this->answer[$key]->fraction = $fraction[$key];
                        $this->answer[$key]->feedback = $feedback[$key];
                        $this->answer[$key]->tolerance = $tolerance[$key];
                        $this->answer[$key]->tolerancetype = $tolerancetype[$key];
                        $this->answer[$key]->correctanswerlength = $correctanswerlength[$key];
                        $this->answer[$key]->correctanswerformat = $correctanswerformat[$key];
                        $this->nonemptyanswer[]= $this->answer[$key];
                        $mandatorydatasets +=$this->qtypeobj->find_dataset_names($answer);
                    }
                }
            }
            $this->datasetdefs = array();
            // rebuild datasetdefs from old values
            $olddef  = optional_param('datasetdef');
            $oldoptions  = optional_param('defoptions');
            $calcmin = optional_param('calcmin') ;
            $calclength = optional_param('calclength') ;
            $calcmax = optional_param('calcmax') ;
            $newdatasetvalues = false ; 

            for($key = 1 ; $key <= sizeof($olddef) ; $key++) {
                $def = $olddef[$key] ;
                $this->datasetdefs[$def]= new stdClass ;
                $this->datasetdefs[$def]->type = 1;
                $this->datasetdefs[$def]->category = 0;
              //  $this->datasets[$key]->name = $datasetname;
                $this->datasetdefs[$def]->options = $oldoptions[$key] ;
                $this->datasetdefs[$def]->calcmin = $calcmin[$key] ;
                $this->datasetdefs[$def]->calcmax = $calcmax[$key] ;
                $this->datasetdefs[$def]->calclength = $calclength[$key] ;
                //then compare with new values
                if (preg_match('~^(uniform|loguniform):([^:]*):([^:]*):([0-9]*)$~', $this->datasetdefs[$def]->options, $regs)) {
                   if( $this->datasetdefs[$def]->calcmin != $regs[2]||
                    $this->datasetdefs[$def]->calcmax != $regs[3] ||
                    $this->datasetdefs[$def]->calclength != $regs[4]){
                         $newdatasetvalues = true ;
                    }                        
                }
                $this->datasetdefs[$def]->options="uniform:".$this->datasetdefs[$def]->calcmin.":".$this->datasetdefs[$def]->calcmax.":".$this->datasetdefs[$def]->calclength;
            }
            
            // detect new datasets        
            $newdataset = false ; 
            foreach ($mandatorydatasets as $datasetname) {
                if (!isset($this->datasetdefs["1-0-$datasetname"])) {
                    $key = "1-0-$datasetname";
                    $this->datasetdefs[$key]=new stdClass ;//"1-0-$datasetname";
                    $this->datasetdefs[$key]->type = 1;
                    $this->datasetdefs[$key]->category = 0;
                    $this->datasetdefs[$key]->name = $datasetname;
                    $this->datasetdefs[$key]->options = "uniform:1.0:10.0:1";
                    $newdataset = true ;     
                }else {
                    $this->datasetdefs["1-0-$datasetname"]->name = $datasetname ;
                }
            }
            // remove obsolete datasets        
            $datasettoremove = false;
            foreach ($this->datasetdefs as $defkey => $datasetdef){
                if(!isset($datasetdef->name )){
                    $datasettoremove = true;
                    unset($this->datasetdefs[$defkey]);
                }
            }                    
        } // handle reload
        // create items if  $newdataset and noofitems > 0 and !$newdatasetvalues
        // eliminate any items if $newdatasetvalues
        // eliminate any items if $datasettoremove, $newdataset, $newdatasetvalues
        if ($datasettoremove ||$newdataset ||$newdatasetvalues ) {
            foreach ($this->datasetdefs as $defkey => $datasetdef){
                $datasetdef->itemcount = 0;
                unset($datasetdef->items);
            }
        }
        $maxnumber = -1 ;
        if  (  "" !=optional_param('addbutton')){
            $maxnumber = optional_param('selectadd') ;                             
            foreach ($this->datasetdefs as $defid => $datasetdef) {
                $datasetdef->itemcount = $maxnumber;
                unset($datasetdef->items);
                for ($numberadded =1 ; $numberadded <= $maxnumber; $numberadded++){
                    $datasetitem = new stdClass;
                    $datasetitem->itemnumber = $numberadded;
                    $datasetitem->id = 0;
                    $datasetitem->value = $this->qtypeobj->generate_dataset_item($datasetdef->options);
                    $this->datasetdefs[$defid]->items[$numberadded]=$datasetitem ;
                }//for number added
            }// datasetsdefs end
            $this->maxnumber = $maxnumber ;
        }else {
            // Handle reload dataset items
            if  (  "" !=optional_param('definition')&& !($datasettoremove ||$newdataset ||$newdatasetvalues )){              
                $i = 1;
                $fromformdefinition = optional_param('definition');
                $fromformnumber = optional_param('number');
                $fromformitemid = optional_param('itemid');
                ksort($fromformdefinition);
              
                foreach($fromformdefinition as $key => $defid) {
                    $addeditem = new stdClass();
                    $addeditem->id = $fromformitemid[$i]  ;
                    $addeditem->value = $fromformnumber[$i];
                    $addeditem->itemnumber = ceil($i / count($this->datasetdefs));
                    $this->datasetdefs[$defid]->items[$addeditem->itemnumber]=$addeditem ;
                    $this->datasetdefs[$defid]->itemcount = $i ;
                    $i++;
                }
            }
            if (isset($addeditem->itemnumber) && $this->maxnumber < $addeditem->itemnumber){
                $this->maxnumber = $addeditem->itemnumber;
                if(!empty($this->datasetdefs)){                        
                    foreach ($this->datasetdefs as $datasetdef) {
                            $datasetdef->itemcount = $this->maxnumber ;
                    }
                }
            }
        }

        parent::question_edit_form($submiturl, $question, $category, $contexts, $formeditable);
    }

    function get_per_answer_fields(&$mform, $label, $gradeoptions, &$repeatedoptions, &$answersoption) {
        $repeated = parent::get_per_answer_fields(&$mform, $label, $gradeoptions, $repeatedoptions, $answersoption);
        $mform->setType('answer', PARAM_NOTAGS);
        $addrepeated = array();
        $addrepeated[] =& $mform->createElement('text', 'tolerance', get_string('tolerance', 'qtype_calculated'));
        $repeatedoptions['tolerance']['type'] = PARAM_NUMBER;
        $repeatedoptions['tolerance']['default'] = 0.01;
        $addrepeated[] =& $mform->createElement('select', 'tolerancetype', get_string('tolerancetype', 'quiz'), $this->qtypeobj->tolerance_types());
        $addrepeated[] =&  $mform->createElement('select', 'correctanswerlength', get_string('correctanswershows', 'qtype_calculated'), range(0, 9));
        $repeatedoptions['correctanswerlength']['default'] = 2;

        $answerlengthformats = array('1' => get_string('decimalformat', 'quiz'), '2' => get_string('significantfiguresformat', 'quiz'));
        $addrepeated[] =&  $mform->createElement('select', 'correctanswerformat', get_string('correctanswershowsformat', 'qtype_calculated'), $answerlengthformats);
        array_splice($repeated, 3, 0, $addrepeated);
        $repeated[1]->setLabel(get_string('correctanswerformula', 'quiz').'=');

        return $repeated;
    }

    /**
     * Add question-type specific form fields.
     *
     * @param MoodleQuickForm $mform the form being built.
     */
    function definition_inner(&$mform) {
        global $QTYPES;
        $this->qtypeobj =& $QTYPES[$this->qtype()];
        $strquestionlabel = $this->qtypeobj->comment_header($this->nonemptyanswer);
        $label = get_string("sharedwildcards", "qtype_datasetdependent");
        $mform->addElement('hidden', 'initialcategory', 1);
        $mform->addElement('hidden', 'reload', 1);
        $addfieldsname='updatequestion value';
        $addstring=get_string("updatecategory", "qtype_calculated");
        $mform->registerNoSubmitButton($addfieldsname);
//put a submit button to stop supplementary answers on update answers parameters
//        $mform->insertElementBefore(    $mform->createElement('submit', $addfieldsname, $addstring),'listcategory');

        $creategrades = get_grade_options();
        $this->add_per_answer_fields($mform, get_string('answerhdr', 'qtype_calculated', '{no}'),
                $creategrades->gradeoptions, 1, 1);

        $repeated = array();
        $repeated[] =& $mform->createElement('header', 'unithdr', get_string('unithdr', 'qtype_numerical', '{no}'));

        $repeated[] =& $mform->createElement('text', 'unit', get_string('unit', 'quiz'));
        $mform->setType('unit', PARAM_NOTAGS);

        $repeated[] =& $mform->createElement('text', 'multiplier', get_string('multiplier', 'quiz'));
        $mform->setType('multiplier', PARAM_NUMBER);

        if (isset($this->question->options)){
            $countunits = count($this->question->options->units);
        } else {
            $countunits = 0;
        }
        if ($this->question->formoptions->repeatelements){
            $repeatsatstart = $countunits + 1;
        } else {
            $repeatsatstart = $countunits;
        }
        $this->repeat_elements($repeated, $repeatsatstart, array(), 'nounits', 'addunits', 2, get_string('addmoreunitblanks', 'qtype_calculated', '{no}'));

        if ($mform->elementExists('multiplier[0]')){
            $firstunit =& $mform->getElement('multiplier[0]');
            $firstunit->freeze();
            $firstunit->setValue('1.0');
            $firstunit->setPersistantFreeze(true);
        }
        //hidden elements
   //     $mform->addElement('hidden', 'wizard', 'datasetdefinitions');
   //     $mform->setType('wizard', PARAM_ALPHA);
     //   $mform->addElement('header', '', '');
        $label = "<div class='mdl-align'></div><div class='mdl-align'>".get_string('wildcardrole', 'qtype_calculatedsimple')."</div>";
        $mform->addElement('html', "<div class='mdl-align'>&nbsp;</div>");
        $mform->addElement('html', $label);// explaining the role of datasets so other strings can be shortened

        $mform->addElement('submit', 'analyzequestion', get_string('findwildcards','qtype_calculatedsimple'));
        $mform->registerNoSubmitButton('analyzequestion');
        $mform->closeHeaderBefore('analyzequestion');
        if  (  "" != optional_param('analyzequestion','', PARAM_RAW)) {

            $this->wizarddisplay = true;

        }else {
            $this->wizwarddisplay = false;
        }
        if ($this->maxnumber != -1){
            $this->noofitems = $this->maxnumber;
        } else {
            $this->noofitems = 0;
        }
        if(!empty($this->datasetdefs)){//So there are some datadefs
        // we put them on the page
            $key = 0;
            $mform->addElement('header', 'additemhdr', get_string('wildcardparam', 'qtype_calculatedsimple'));
            $idx = 1;
            if(!empty($this->datasetdefs)){// unnecessary test
                $j = (($this->noofitems) * count($this->datasetdefs))+1;//
                foreach ($this->datasetdefs as $defkey => $datasetdef){
                    $mform->addElement('static', "na[$j]", get_string('param', 'qtype_datasetdependent', $datasetdef->name));
                    $this->qtypeobj->custom_generator_tools_part($mform, $idx, $j);
                    $mform->addElement('hidden', "datasetdef[$idx]");
                    $mform->setType("datasetdef[$idx]", PARAM_RAW);
                    $idx++;
                    $mform->addElement('static', "divider[$j]", '', '<hr />');
                    $j++;
                }
            }
            //this should be done before the elements are created and stored as $this->formdata ;
            //fill out all data sets and also the fields for the next item to add.
        /*Here we do already the values error analysis so that 
        * we could force all wild cards values display if there is an error in values.
        * as using a , in a number */
        $this->numbererrors = array();
            if(!empty($this->datasetdefs)){
                    $j = $this->noofitems * count($this->datasetdefs);
                    for ($itemnumber = $this->noofitems; $itemnumber >= 1; $itemnumber--){
                        $data = array();
                        $numbererrors = array() ;
                        $comment = new stdClass;
                            $comment->stranswers = array();
                            $comment->outsidelimit = false ;
                            $comment->answers = array();

                        foreach ($this->datasetdefs as $defid => $datasetdef){
                            if (isset($datasetdef->items[$itemnumber])){
                                $this->formdata["definition[$j]"] = $defid;
                                $this->formdata["itemid[$j]"] = $datasetdef->items[$itemnumber]->id;
                                $data[$datasetdef->name] = $datasetdef->items[$itemnumber]->value;
                                $this->formdata["number[$j]"] = $number = $datasetdef->items[$itemnumber]->value;
                                        if(! is_numeric($number)){
                                        $a = new stdClass;
                                        $a->name = '{'.$datasetdef->name.'}' ;
                                        $a->value = $datasetdef->items[$itemnumber]->value ;
                if (stristr($number,',')){
                                    $this->numbererrors["number[$j]"]=get_string('nocommaallowed', 'qtype_datasetdependent');
                                $numbererrors .= $this->numbererrors['number['.$j.']']."<br />";
                    
                }else {
                                    $this->numbererrors["number[$j]"]= get_string('notvalidnumber','qtype_datasetdependent',$a);
                                    $numbererrors .= $this->numbererrors['number['.$j.']']."<br />";
                                    //$comment->outsidelimit = false ;
                                  }
            }else if( stristr($number,'x')){ // hexa will pass the test                
                $a = new stdClass;
                $a->name = '{'.$datasetdef->name.'}' ;
                $a->value = $datasetdef->items[$itemnumber]->value ;
                    $this->numbererrors['number['.$j.']']= get_string('hexanotallowed','qtype_datasetdependent',$a);
                                    $numbererrors .= $this->numbererrors['number['.$j.']']."<br />";
                    } else if( is_nan($number)){
                        $a = new stdClass;
                        $a->name = '{'.$datasetdef->name.'}' ;
                        $a->value = $datasetdef->items[$itemnumber]->value ;
                                            $this->numbererrors["number[$j]"]= get_string('notvalidnumber','qtype_datasetdependent',$a);
                                            $numbererrors .= $this->numbererrors['number['.$j.']']."<br />";
                         //   $val = 1.0 ;
                    }                    
                            }
                            $j--;
                        }
                        if($this->noofitems != 0 ) {
                                if (empty($numbererrors )){
                                    if(!isset($question->id)) $question->id = 0 ;
                                        $comment = $this->qtypeobj->comment_on_datasetitems($question->id,$this->nonemptyanswer, $data, $itemnumber);//$this->
                                        if ($comment->outsidelimit) {
                                            $this->outsidelimit=$comment->outsidelimit ;
                                        }
                                        $totalcomment='';
        
                                        foreach ($this->nonemptyanswer as $key => $answer) {
                                            $totalcomment .= $comment->stranswers[$key].'<br/>';
                                        }
        
                                        $this->formdata['answercomment['.$itemnumber.']'] = $totalcomment ;
                                }
                            }
                    }
                    $this->formdata['selectdelete'] = '1';
                    $this->formdata['selectadd'] = '1';
                    $j = $this->noofitems * count($this->datasetdefs)+1;
                    $data = array(); // data for comment_on_datasetitems later
                $idx =1 ;
                foreach ($this->datasetdefs as $defid => $datasetdef){
                    $this->formdata["datasetdef[$idx]"] = $defid;
                    $idx++;
                }
                    $this->formdata = $this->qtypeobj->custom_generator_set_data($this->datasetdefs, $this->formdata);
                }

         
        $addoptions = Array();
        $addoptions['1']='1';
        for ($i=10; $i<=100 ; $i+=10){
             $addoptions["$i"]="$i";
        }
        $showoptions = Array();
        $showoptions['1']='1';
        $showoptions['2']='2';
        $showoptions['5']='5';
        for ($i=10; $i<=100 ; $i+=10){
             $showoptions["$i"]="$i";
        }
        $mform->closeHeaderBefore('additemhdr');
        $addgrp = array();
        $addgrp[] =& $mform->createElement('submit', 'addbutton', get_string('generatenewitemsset', 'qtype_calculatedsimple'));
        $addgrp[] =& $mform->createElement('select', "selectadd", '', $addoptions);
        $addgrp[] = & $mform->createElement('static',"stat",'',get_string('newsetwildcardvalues', 'qtype_calculatedsimple'));
        $mform->addGroup($addgrp, 'addgrp', '', '   ', false);
        $mform->registerNoSubmitButton('addbutton');
        $mform->closeHeaderBefore('addgrp');
        $addgrp1 = array();
        $addgrp1[] =& $mform->createElement('submit', 'showbutton', get_string('showitems', 'qtype_calculatedsimple'));
        $addgrp1[] =& $mform->createElement('select', "selectshow",'' , $showoptions);
        $addgrp1[] = & $mform->createElement('static',"stat",'',get_string('setwildcardvalues', 'qtype_calculatedsimple'));
        $mform->addGroup($addgrp1, 'addgrp1', '', '   ', false);
        $mform->registerNoSubmitButton('showbutton');
        $mform->closeHeaderBefore('addgrp1');
        $mform->addElement('static', "divideradd", '', '');
        if ($this->noofitems == 0) {
           $mform->addElement('static','warningnoitems','','<span class="error">'.get_string('youmustaddatleastonevalue', 'qtype_calculatedsimple').'</span>');
             $mform->closeHeaderBefore('warningnoitems');
        }else {
            $mform->addElement('header', 'additemhdr1', get_string('wildcardvalues', 'qtype_calculatedsimple'));
            $mform->closeHeaderBefore('additemhdr1');
         //   $mform->addElement('header', '', get_string('itemno', 'qtype_datasetdependent', ""));
         if( !empty($this->numbererrors) || $this->outsidelimit) {
        $mform->addElement('static', "alert", '', '<span class="error">'.get_string('useadvance', 'qtype_calculatedsimple').'</span>');
        }
            
          $mform->addElement('submit', 'updatedatasets', get_string('updatewildcardvalues', 'qtype_calculatedsimple'));
          $mform->registerNoSubmitButton('updatedatasets');
          $mform->setAdvanced("updatedatasets",true);

//------------------------------------------------------------------------------------------------------------------------------
        $j = $this->noofitems * count($this->datasetdefs);
        $k = 1 ;
        if ("" != optional_param('selectshow')){
        $k = optional_param('selectshow') ; 
      }

        for ($i = $this->noofitems; $i >= 1 ; $i--){
            foreach ($this->datasetdefs as $defkey => $datasetdef){
                if($k > 0 ||  $this->outsidelimit || !empty($this->numbererrors ) ){
                $mform->addElement('text',"number[$j]" , get_string('wildcard', 'qtype_calculatedsimple', $datasetdef->name));
                $mform->setAdvanced("number[$j]",true);
                if(!empty($this->numbererrors['number['.$j.']']) ){ 
                    $mform->addElement('static', "numbercomment[$j]",'','<span class="error">'.$this->numbererrors['number['.$j.']'].'</span>');
                $mform->setAdvanced("numbercomment[$j]",true);
              }
              }else {
                $mform->addElement('hidden',"number[$j]" , get_string('wildcard', 'qtype_calculatedsimple', $datasetdef->name));
              }
                $mform->setType("number[$j]", PARAM_NUMBER);
                
                $mform->addElement('hidden', "itemid[$j]");
                $mform->setType("itemid[$j]", PARAM_INT);

                $mform->addElement('hidden', "definition[$j]");
                $mform->setType("definition[$j]", PARAM_NOTAGS);

                $j--;
            }
            if (!empty( $strquestionlabel) && ($k > 0 ||  $this->outsidelimit || !empty($this->numbererrors ) ) ){
             //   $repeated[] =& $mform->addElement('static', "answercomment[$i]", $strquestionlabel);
                    $mform->addElement('static', "answercomment[$i]", "<b>".get_string('setno', 'qtype_calculatedsimple', $i)."</b>&nbsp;&nbsp;".$strquestionlabel);
                    
            }
               if($k > 0 ||  $this->outsidelimit || !empty($this->numbererrors )){             
                $mform->addElement('static', "divider1[$j]", '', '<hr />');
               
               }
                        $k-- ;
        }
    }
      //  if ($this->outsidelimit){
         //   $mform->addElement('static','outsidelimit','','');
      //  }
    }else {
        $mform->addElement('static','warningnowildcards','','<span class="error">'.get_string('atleastonewildcard', 'qtype_calculatedsimple').'</span>');
        $mform->closeHeaderBefore('warningnowildcards');
    }

//------------------------------------------------------------------------------------------------------------------------------
        //non standard name for button element needed so not using add_action_buttons
        //hidden elements

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'courseid');
        $mform->setType('courseid', PARAM_INT);
        $mform->setDefault('courseid', 0);

        $mform->addElement('hidden', 'cmid');
        $mform->setType('cmid', PARAM_INT);
        $mform->setDefault('cmid', 0);
        if (!empty($this->question->id)){
            if ($this->question->formoptions->cansaveasnew){
        $mform->addElement('header', 'additemhdr', get_string('converttocalculated', 'qtype_calculatedsimple'));
        $mform->closeHeaderBefore('additemhdr');
                
                $mform->addElement('checkbox', 'convert','' ,get_string('willconverttocalculated', 'qtype_calculatedsimple'));
                                $mform->setDefault('convert', 0);

              }
            }
   //     $mform->addElement('hidden', 'wizard', 'edit_calculatedsimple');
   //     $mform->setType('wizard', PARAM_ALPHA);
/*
        $mform->addElement('hidden', 'returnurl');
        $mform->setType('returnurl', PARAM_LOCALURL);
        $mform->setDefault('returnurl', 0);

*/
    }

    function set_data($question) {
            $answer = $this->answer;
        $default_values = array();
            if (count($answer)) {
                $key = 0;
                foreach ($answer as $answer){
                    $default_values['answer['.$key.']'] = $answer->answer;
                    $default_values['fraction['.$key.']'] = $answer->fraction;
                    $default_values['tolerance['.$key.']'] = $answer->tolerance;
                    $default_values['tolerancetype['.$key.']'] = $answer->tolerancetype;
                    $default_values['correctanswerlength['.$key.']'] = $answer->correctanswerlength;
                    $default_values['correctanswerformat['.$key.']'] = $answer->correctanswerformat;
                    $default_values['feedback['.$key.']'] = $answer->feedback;
                    $key++;
                }
            }
            if (isset($question->options)){
                $units  = array_values($question->options->units);
                // make sure the default unit is at index 0
                usort($units, create_function('$a, $b',
                'if (1.0 === (float)$a->multiplier) { return -1; } else '.
                'if (1.0 === (float)$b->multiplier) { return 1; } else { return 0; }'));
                if (count($units)) {
                    $key = 0;
                    foreach ($units as $unit){
                        $default_values['unit['.$key.']'] = $unit->unit;
                        $default_values['multiplier['.$key.']'] = $unit->multiplier;
                        $key++;
                    }
                }
            }
                      $key = 0 ;

        $formdata = array();
        $fromform = new stdClass();
        //this should be done before the elements are created and stored as $this->formdata ;
        //fill out all data sets and also the fields for the next item to add.
  /*      if(!empty($this->datasetdefs)){
        $j = $this->noofitems * count($this->datasetdefs);
         for ($itemnumber = $this->noofitems; $itemnumber >= 1; $itemnumber--){
            $data = array();
            foreach ($this->datasetdefs as $defid => $datasetdef){
                if (isset($datasetdef->items[$itemnumber])){
                    $formdata["number[$j]"] = $datasetdef->items[$itemnumber]->value;
                    $formdata["definition[$j]"] = $defid;
                    $formdata["itemid[$j]"] = $datasetdef->items[$itemnumber]->id;
                    $data[$datasetdef->name] = $datasetdef->items[$itemnumber]->value;
                }
                $j--;
            }
    //                 echo "<p>answers avant  comment <pre>";print_r($answer);echo"</pre></p>";
    //                 echo "<p>data avant  comment <pre>";print_r($data);echo"</pre></p>";
                     
            if($this->noofitems != 0 ) {
                if(!isset($question->id)) $question->id = 0 ;
            $comment = $this->qtypeobj->comment_on_datasetitems($question->id,$this->nonemptyanswer, $data, $itemnumber);//$this->
             if ($comment->outsidelimit) {
                 $this->outsidelimit=$comment->outsidelimit ;
            }
            $totalcomment='';
       //              echo "<p> comment <pre>";print_r($comment);echo"</pre></p>";

            foreach ($this->nonemptyanswer as $key => $answer) {
                $totalcomment .= $comment->stranswers[$key].'<br/>';
            }

            $formdata['answercomment['.$itemnumber.']'] = $totalcomment ;
        }
        }
    //    $formdata['reload'] = '1';
      //  $formdata['nextpageparam[forceregeneration]'] = $this->regenerate;
        $formdata['selectdelete'] = '1';
        $formdata['selectadd'] = '1';
        $j = $this->noofitems * count($this->datasetdefs)+1;
        $data = array(); // data for comment_on_datasetitems later
           $idx =1 ;
            foreach ($this->datasetdefs as $defid => $datasetdef){
               $formdata["datasetdef[$idx]"] = $defid;
                $idx++;
            }
        $formdata = $this->qtypeobj->custom_generator_set_data($this->datasetdefs, $formdata);
    }*/
        $question = (object)((array)$question + $default_values+$this->formdata );

        parent::set_data($question);
    }

    function qtype() {
        return 'calculatedsimple';
    }

    function validation($data, $files) {
        $errors = parent::validation($data, $files);
        //verifying for errors in {=...} in question text;
        $qtext = "";
        $qtextremaining = $data['questiontext'] ;
        $possibledatasets = $this->qtypeobj->find_dataset_names($data['questiontext']);
            foreach ($possibledatasets as $name => $value) {
            $qtextremaining = str_replace('{'.$name.'}', '1', $qtextremaining);
        }
        while  (preg_match('~\{=([^[:space:]}]*)}~', $qtextremaining, $regs1)) {
            $qtextsplits = explode($regs1[0], $qtextremaining, 2);
            $qtext =$qtext.$qtextsplits[0];
            $qtextremaining = $qtextsplits[1];
            if (!empty($regs1[1]) && $formulaerrors = qtype_calculated_find_formula_errors($regs1[1])) {
                if(!isset($errors['questiontext'])){
                    $errors['questiontext'] = $formulaerrors.':'.$regs1[1] ;
                }else {
                    $errors['questiontext'] .= '<br/>'.$formulaerrors.':'.$regs1[1];
                }
            }
        }
        $answers = $data['answer'];
        $answercount = 0;
        $maxgrade = false;
        $possibledatasets = $this->qtypeobj->find_dataset_names($data['questiontext']);
        $mandatorydatasets = array();
        foreach ($answers as $key => $answer){
            $mandatorydatasets += $this->qtypeobj->find_dataset_names($answer);
        }
        if ( count($mandatorydatasets )==0){
             foreach ($answers as $key => $answer){
                $errors['answer['.$key.']'] = get_string('atleastonewildcard', 'qtype_datasetdependent');
            }
        }
        foreach ($answers as $key => $answer){
            //check no of choices
            // the * for everykind of answer not actually implemented
            $trimmedanswer = trim($answer);
            if (($trimmedanswer!='')||$answercount==0){
                $eqerror = qtype_calculated_find_formula_errors($trimmedanswer);
                if (FALSE !== $eqerror){
                    $errors['answer['.$key.']'] = $eqerror;
                }
            }
            if ($trimmedanswer!=''){
                if ('2' == $data['correctanswerformat'][$key]
                        && '0' == $data['correctanswerlength'][$key]) {
                    $errors['correctanswerlength['.$key.']'] = get_string('zerosignificantfiguresnotallowed','quiz');
                }
                if (!is_numeric($data['tolerance'][$key])){
                    $errors['tolerance['.$key.']'] = get_string('mustbenumeric', 'qtype_calculated');
                }
                if ($data['fraction'][$key] == 1) {
                   $maxgrade = true;
                }

                $answercount++;
            }
            //check grades

            //TODO how should grade checking work here??
            /*if ($answer != '') {
                if ($data['fraction'][$key] > 0) {
                    $totalfraction += $data['fraction'][$key];
                }
                if ($data['fraction'][$key] > $maxfraction) {
                    $maxfraction = $data['fraction'][$key];
                }
            }*/
        }
        //grade checking :
        /// Perform sanity checks on fractional grades
        /*if ( ) {
            if ($maxfraction != 1) {
                $maxfraction = $maxfraction * 100;
                $errors['fraction[0]'] = get_string('errfractionsnomax', 'qtype_multichoice', $maxfraction);
            }
        } else {
            $totalfraction = round($totalfraction,2);
            if ($totalfraction != 1) {
                $totalfraction = $totalfraction * 100;
                $errors['fraction[0]'] = get_string('errfractionsaddwrong', 'qtype_multichoice', $totalfraction);
            }
        }*/
        $units  = $data['unit'];
        if (count($units)) {
            foreach ($units as $key => $unit){
                if (is_numeric($unit)){
                    $errors['unit['.$key.']'] = get_string('mustnotbenumeric', 'qtype_calculated');
                }
                $trimmedunit = trim($unit);
                $trimmedmultiplier = trim($data['multiplier'][$key]);
                if (!empty($trimmedunit)){
                    if (empty($trimmedmultiplier)){
                        $errors['multiplier['.$key.']'] = get_string('youmustenteramultiplierhere', 'qtype_calculated');
                    }
                    if (!is_numeric($trimmedmultiplier)){
                        $errors['multiplier['.$key.']'] = get_string('mustbenumeric', 'qtype_calculated');
                    }

                }
            }
        }
        if ($answercount==0){
            $errors['answer[0]'] = get_string('atleastoneanswer', 'qtype_calculated');
        }
        if ($maxgrade == false) {
            $errors['fraction[0]'] = get_string('fractionsnomax', 'question');
        }
        if (isset($data['backtoquiz']) && ($this->noofitems==0) ){
            $errors['warning'] = get_string('warning', 'mnet');
        } 
        if ($this->outsidelimit){
         //   if(!isset($errors['warning'])) $errors['warning']=' ';
           $errors['outsidelimits'] = get_string('oneanswertrueansweroutsidelimits','qtype_calculated');
        }
                /*Here we use the already done the error analysis so that 
        * we could force all wild cards values display if there is an error in values.
        * as using a , in a number */
        $numbers = $data['number'];
        foreach ($numbers as $key => $number){
            if(! is_numeric($number)){
                if (stristr($number,',')){
                    $errors['number['.$key.']'] = get_string('notvalidnumber', 'qtype_datasetdependent');
                }else {    
                    $errors['number['.$key.']'] = get_string('notvalidnumber', 'qtype_datasetdependent');
                }
            }else if( stristr($number,'x')){
                $errors['number['.$key.']'] = get_string('notvalidnumber', 'qtype_datasetdependent');
            } else if( is_nan($number)){
                $errors['number['.$key.']'] = get_string('notvalidnumber', 'qtype_datasetdependent');
            }        
        }
        
        if ( $this->noofitems==0  ){
            $errors['warning'] = get_string('warning', 'mnet');
        }

        return $errors;
    }
}
?>
