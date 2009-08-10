<?php // $Id$
/**
 * Class representing question categories
 *
 * @author Martin Dougiamas and many others. {@link http://moodle.org}
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package questionbank
 */

// number of categories to display on page
define("QUESTION_PAGE_LENGTH", 25);

require_once("$CFG->libdir/listlib.php");
require_once("$CFG->dirroot/question/category_form.php");
require_once("$CFG->dirroot/question/category_form_randomquestion.php");
require_once('move_form.php');

class question_category_list extends moodle_list {
    public $table = "question_categories";
    public $listitemclassname = 'question_category_list_item';
    /**
     * @var reference to list displayed below this one.
     */
    public $nextlist = null;
    /**
     * @var reference to list displayed above this one.
     */
    public $lastlist = null;

    public $context = null;
    public $sortby = 'parent, sortorder, name';

    public function question_category_list($type='ul', $attributes='', $editable = false, $pageurl=null, $page = 0, $pageparamname = 'page', $itemsperpage = 20, $context = null){
        parent::moodle_list('ul', '', $editable, $pageurl, $page, 'cpage', $itemsperpage);
        $this->context = $context;
    }

    public function get_records() {
        $this->records = get_categories_for_contexts($this->context->id, $this->sortby);
    }
    public function process_actions($left, $right, $moveup, $movedown, $moveupcontext, $movedowncontext, $tocontext){
        global $CFG;
        //parent::procces_actions redirects after any action
        parent::process_actions($left, $right, $moveup, $movedown);
        if ($tocontext == $this->context->id){
            //only called on toplevel list
            if ($moveupcontext){
                $cattomove = $moveupcontext;
                $totop = 0;
            } elseif ($movedowncontext){
                $cattomove = $movedowncontext;
                $totop = 1;
            }
            $toparent = "0,{$this->context->id}";
            redirect($CFG->wwwroot.'/question/contextmove.php?'.
                $this->pageurl->get_query_string(compact('cattomove', 'totop', 'toparent')));
        }
    }
}

class question_category_list_item extends list_item {


    public function set_icon_html($first, $last, &$lastitem){
        global $CFG;
        $category = $this->item;
        $this->icons['edit']= $this->image_icon(get_string('editthiscategory'),
                "{$CFG->wwwroot}/question/category.php?".$this->parentlist->pageurl->get_query_string(array('edit'=>$category->id)), 'edit');
        parent::set_icon_html($first, $last, $lastitem);
        $toplevel = ($this->parentlist->parentitem === null);//this is a top level item
        if (($this->parentlist->nextlist !== null) && $last && $toplevel && (count($this->parentlist->items)>1)){
            $this->icons['down'] = $this->image_icon(
                get_string('shareincontext', 'question', print_context_name($this->parentlist->nextlist->context)),
                $this->parentlist->pageurl->out_action(
                    array('movedowncontext'=>$this->id, 'tocontext'=>$this->parentlist->nextlist->context->id)
                ), 'down');
        }
        if (($this->parentlist->lastlist !== null) && $first && $toplevel && (count($this->parentlist->items)>1)){
            $this->icons['up'] = $this->image_icon(
                get_string('shareincontext', 'question', print_context_name($this->parentlist->lastlist->context)),
                $this->parentlist->pageurl->out_action(
                    array('moveupcontext'=>$this->id, 'tocontext'=>$this->parentlist->lastlist->context->id)
                ), 'up');
        }
    }
    public function item_html($extraargs = array()){
        global $CFG, $OUTPUT;
        $str = $extraargs['str'];
        $category = $this->item;

        $editqestions = get_string('editquestions', 'quiz');

        /// Each section adds html to be displayed as part of this list item
        $questionbankurl = "{$CFG->wwwroot}/question/edit.php?".
                $this->parentlist->pageurl->get_query_string(array('category'=>"$category->id,$category->contextid"));
        $catediturl = $this->parentlist->pageurl->out(false, array('edit'=>$this->id));
        $item = "<b><a title=\"{$str->edit}\" href=\"$catediturl\">".$category->name ."</a></b> <a title=\"$editqestions\" href=\"$questionbankurl\">".'('.$category->questioncount.')</a>';

        $item .= '&nbsp;'. $category->info;

        if (count($this->parentlist->records)!=1){ // don't allow delete if this is the last category in this context.
            $item .=  '<a title="' . $str->delete . '" href="'.$this->parentlist->pageurl->out_action(array('delete'=>$this->id)).'">
                    <img src="' . $OUTPUT->old_icon_url('t/delete.gif') . '" class="iconsmall" alt="' .$str->delete. '" /></a>';
        }

        return $item;
    }

}


/**
 * Class representing question categories
 *
 * @package questionbank
 */
class question_category_object {

    var $str;
    /**
     * Nested lists to display categories.
     *
     * @var array
     */
    var $editlists = array();
    var $newtable;
    var $tab;
    var $tabsize = 3;
//------------------------------------------------------
    /**
     * @var moodle_url Object representing url for this page
     */
    var $pageurl;
    /**
     * @var question_category_edit_form Object representing form for adding / editing categories.
     */
    var $catform;
    /**
     * @var question_category_edit_form_randomquestion Object representing simplified form for adding a category in order to add it into a quiz as a random question.
     */
    var $catform_rand;

    /**
     * Constructor
     *
     * Gets necessary strings and sets relevant path information
     */
    public function question_category_object($page, $pageurl, $contexts, $currentcat, $defaultcategory, $todelete, $addcontexts) {
        global $CFG, $COURSE, $OUTPUT;

        $this->tab = str_repeat('&nbsp;', $this->tabsize);

        $this->str->course         = get_string('course');
        $this->str->category       = get_string('category', 'quiz');
        $this->str->categoryinfo   = get_string('categoryinfo', 'quiz');
        $this->str->questions      = get_string('questions', 'quiz');
        $this->str->add            = get_string('add');
        $this->str->delete         = get_string('delete');
        $this->str->moveup         = get_string('moveup');
        $this->str->movedown       = get_string('movedown');
        $this->str->edit           = get_string('editthiscategory');
        $this->str->hide           = get_string('hide');
        $this->str->publish        = get_string('publish', 'quiz');
        $this->str->order          = get_string('order');
        $this->str->parent         = get_string('parent', 'quiz');
        $this->str->add            = get_string('add');
        $this->str->action         = get_string('action');
        $this->str->top            = get_string('top', 'quiz');
        $this->str->addcategory    = get_string('addcategory', 'quiz');
        $this->str->editcategory   = get_string('editcategory', 'quiz');
        $this->str->cancel         = get_string('cancel');
        $this->str->editcategories = get_string('editcategories', 'quiz');
        $this->str->page           = get_string('page');

        $this->pageurl = $pageurl;

        $this->initialize($page, $contexts, $currentcat, $defaultcategory, $todelete, $addcontexts);

    }



    /**
     * Initializes this classes general category-related variables
     */
    public function initialize($page, $contexts, $currentcat, $defaultcategory, $todelete, $addcontexts) {
        $lastlist = null;
        foreach ($contexts as $context){
            $this->editlists[$context->id] = new question_category_list('ul', '', true, $this->pageurl, $page, 'cpage', QUESTION_PAGE_LENGTH, $context);
            $this->editlists[$context->id]->lastlist =& $lastlist;
            if ($lastlist!== null){
                $lastlist->nextlist =& $this->editlists[$context->id];
            }
            $lastlist =& $this->editlists[$context->id];
        }

        $count = 1;
        $paged = false;
        foreach ($this->editlists as $key => $list){
            list($paged, $count) = $this->editlists[$key]->list_from_records($paged, $count);
        }
        $this->catform = new question_category_edit_form($this->pageurl, compact('contexts', 'currentcat'));
        $this->catform_rand = new question_category_edit_form_randomquestion($this->pageurl, compact('contexts', 'currentcat'));
        if (!$currentcat){
            $this->catform->set_data(array('parent'=>$defaultcategory));
            $this->catform_rand->set_data(array('parent'=>$defaultcategory));
        }
    }
    /**
     * Displays the user interface
     *
     */
    public function display_user_interface() {

        /// Interface for editing existing categories
        $this->output_edit_lists();


        echo '<br />';
        /// Interface for adding a new category:
        $this->output_new_table();
        echo '<br />';

    }
    /**
     * Displays the user interface
     *
     */
    function display_randomquestion_user_interface($addonpage=0) {
        $this->catform_rand->set_data(array('addonpage'=>$addonpage));
        /// Interface for adding a new category:
        $this->output_new_randomquestion_table();

    }

    /**
     * Outputs a table to allow entry of a new category
     */
    public function output_new_table() {
        $this->catform->display();
    }

    /**
     * Outputs a table to allow entry of a new category
     */
    function output_new_randomquestion_table() {
        $this->catform_rand->display();
    }


    /**
     * Outputs a list to allow editing/rearranging of existing categories
     *
     * $this->initialize() must have already been called
     *
     */
    public function output_edit_lists() {
        global $OUTPUT;
        print_heading_with_help(get_string('editcategories', 'quiz'), 'categories', 'question');
        foreach ($this->editlists as $context => $list){
            $listhtml = $list->to_html(0, array('str'=>$this->str));
            if ($listhtml){
                echo $OUTPUT->box_start('boxwidthwide boxaligncenter generalbox questioncategories contextlevel' . $list->context->contextlevel);
                echo $OUTPUT->heading(get_string('questioncatsfor', 'question', print_context_name(get_context_instance_by_id($context))), 3);
                echo $listhtml;
                echo $OUTPUT->box_end();
            }
        }
        echo $list->display_page_numbers();
     }



    /**
     * gets all the courseids for the given categories
     *
     * @param array categories contains category objects in  a tree representation
     * @return array courseids flat array in form categoryid=>courseid
     */
    public function get_course_ids($categories) {
        $courseids = array();
        foreach ($categories as $key=>$cat) {
            $courseids[$key] = $cat->course;
            if (!empty($cat->children)) {
                $courseids = array_merge($courseids, $this->get_course_ids($cat->children));
            }
        }
        return $courseids;
    }



    public function edit_single_category($categoryid) {
    /// Interface for adding a new category
        global $COURSE, $DB;
        /// Interface for editing existing categories
        if ($category = $DB->get_record("question_categories", array("id" => $categoryid))) {

            $category->parent = "$category->parent,$category->contextid";
            $category->submitbutton = get_string('savechanges');
            $category->categoryheader = $this->str->edit;
            $this->catform->set_data($category);
            $this->catform->display();
        } else {
            print_error('invalidcategory', '', '', $categoryid);
        }
    }


    /**
     * Sets the viable parents
     *
     *  Viable parents are any except for the category itself, or any of it's descendants
     *  The parentstrings parameter is passed by reference and changed by this function.
     *
     * @param    array parentstrings a list of parentstrings
     * @param   object category
     */
    public function set_viable_parents(&$parentstrings, $category) {

        unset($parentstrings[$category->id]);
        if (isset($category->children)) {
            foreach ($category->children as $child) {
                $this->set_viable_parents($parentstrings, $child);
            }
        }
    }

    /**
     * Gets question categories
     *
     * @param    int parent - if given, restrict records to those with this parent id.
     * @param    string sort - [[sortfield [,sortfield]] {ASC|DESC}]
     * @return   array categories
     */
    public function get_question_categories($parent=null, $sort="sortorder ASC") {
        global $COURSE, $DB;
        if (is_null($parent)) {
            $categories = $DB->get_records('question_categories', array('course' => $COURSE->id), $sort);
        } else {
            $select = "parent = ? AND course = ?";
            $categories = $DB->get_records_select('question_categories', $select, array($parent, $COURSE->id), $sort);
        }
        return $categories;
    }

    /**
     * Deletes an existing question category
     *
     * @param int deletecat id of category to delete
     */
    public function delete_category($categoryid) {
        global $CFG, $DB;
        question_can_delete_cat($categoryid);
        if (!$category = $DB->get_record("question_categories", array("id" => $categoryid))) {  // security
            print_error('unknowcategory');
        }
        /// Send the children categories to live with their grandparent
        $DB->set_field("question_categories", "parent", $category->parent, array("parent" => $category->id));

        /// Finally delete the category itself
        $DB->delete_records("question_categories", array("id" => $category->id));
    }

    public function move_questions_and_delete_category($oldcat, $newcat){
        question_can_delete_cat($oldcat);
        $this->move_questions($oldcat, $newcat);
        $this->delete_category($oldcat);
    }

    public function display_move_form($questionsincategory, $category){
        global $OUTPUT;
        $vars = new stdClass;
        $vars->name = $category->name;
        $vars->count = $questionsincategory;
        echo $OUTPUT->box(get_string('categorymove', 'quiz', $vars), 'generalbox boxaligncenter');
        $this->moveform->display();
    }

    public function move_questions($oldcat, $newcat){
        global $DB;
        $questionids = $DB->get_records_select_menu('question', "category = ? AND (parent = 0 OR parent = id)", array($oldcat), '', 'id,1');
        $ids = implode(',', array_keys($questionids));
        if (!question_move_questions_to_category($ids, $newcat)) {
            print_error('errormovingquestions', 'question', $this->pageurl->out(), $ids);
        }
    }

    /**
     * Creates a new category with given params
     */
    public function add_category($newparent, $newcategory, $newinfo, $return=false) {
        global $DB;
        if (empty($newcategory)) {
            print_error('categorynamecantbeblank', 'quiz');
        }
        list($parentid, $contextid) = explode(',', $newparent);
        //moodle_form makes sure select element output is legal no need for further cleaning
        require_capability('moodle/question:managecategory', get_context_instance_by_id($contextid));

        if ($parentid) {
            if(!($DB->get_field('question_categories', 'contextid', array('id' => $parentid)) == $contextid)) {
                print_error('cannotinsertquestioncatecontext', 'question', '', array($newcategory, $contextid));
            }
        }

        $cat = new object();
        $cat->parent = $parentid;
        $cat->contextid = $contextid;
        $cat->name = $newcategory;
        $cat->info = $newinfo;
        $cat->sortorder = 999;
        $cat->stamp = make_unique_id_code();
        $categoryid = $DB->insert_record("question_categories", $cat);
        if ($return) {
            return $categoryid;
        } else {
            redirect($this->pageurl);//always redirect after successful action
        }
    }

    /**
     * Updates an existing category with given params
     */
    public function update_category($updateid, $newparent, $newname, $newinfo) {
        global $CFG, $QTYPES, $DB;
        if (empty($newname)) {
            print_error('categorynamecantbeblank', 'quiz');
        }

        // Get the record we are updating.
        $oldcat = $DB->get_record('question_categories', array('id' => $updateid));
        $lastcategoryinthiscontext = question_is_only_toplevel_category_in_context($updateid);

        if (!empty($newparent) && !$lastcategoryinthiscontext) {
            list($parentid, $tocontextid) = explode(',', $newparent);
        } else {
            $parentid = $oldcat->parent;
            $tocontextid = $oldcat->contextid;
        }

        // Check permissions.
        $fromcontext = get_context_instance_by_id($oldcat->contextid);
        require_capability('moodle/question:managecategory', $fromcontext);

        // If moving to another context, check permissions some more.
        if ($oldcat->contextid != $tocontextid){
            $tocontext = get_context_instance_by_id($tocontextid);
            require_capability('moodle/question:managecategory', $tocontext);
        }

        // Update the category record.
        $cat = NULL;
        $cat->id = $updateid;
        $cat->name = $newname;
        $cat->info = $newinfo;
        $cat->parent = $parentid;
        // We don't change $cat->contextid here, if necessary we redirect to contextmove.php later.
        $DB->update_record('question_categories', $cat);

        // If the category name has changed, rename any random questions in that category.
        if ($oldcat->name != $cat->name) {
            $where = "qtype = 'random' AND category = ? AND " . $DB->sql_compare_text('questiontext') . " = ?";

            $randomqname = $QTYPES[RANDOM]->question_name($cat, false);
            $DB->set_field_select('question', 'name', $randomqname, $where, array($cat->id, '0'));

            $randomqname = $QTYPES[RANDOM]->question_name($cat, true);
            $DB->set_field_select('question', 'name', $randomqname, $where, array($cat->id, '1'));
        }

        // Then redirect to an appropriate place.
        if ($oldcat->contextid == $tocontextid) { // not moving contexts
            redirect($this->pageurl);
        } else {
            redirect($CFG->wwwroot.'/question/contextmove.php?' .
                    $this->pageurl->get_query_string(array(
                    'cattomove' => $updateid, 'toparent'=>$newparent)));
        }
    }

    public function move_question_from_cat_confirm($fromcat, $fromcourse, $tocat=null, $question=null){
        global $QTYPES, $DB;
        if (!$question){
            $questions[] = $question;
        } else {
            $questions = $DB->get_records('question', array('category' => $tocat->id));
        }
        $urls = array();
        foreach ($questions as $question){
            $urls = array_merge($urls, $QTYPES[$question->qtype]->find_file_links_in_question($question));
        }
        if ($fromcourse){
            $append = 'tocourse';
        } else {
            $append = 'tosite';
        }
        if ($tocat){
            echo '<p>'.get_string('needtomovethesefilesincat','question').'</p>';
        } else {
            echo '<p>'.get_string('needtomovethesefilesinquestion','question').'</p>';
        }
    }




}

?>
