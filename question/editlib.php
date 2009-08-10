<?php // $Id$

///////////////////////////////////////////////////////////////////////////
//                                                                       //
// NOTICE OF COPYRIGHT                                                   //
//                                                                       //
// Moodle - Modular Object-Oriented Dynamic Learning Environment         //
//          http://moodle.org                                            //
//                                                                       //
// Copyright (C) 1999 onwards Martin Dougiamas and others                //
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

/**
 * Functions used to show question editing interface
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package questionbank
 *//** */

require_once($CFG->libdir.'/questionlib.php');

define('DEFAULT_QUESTIONS_PER_PAGE', 20);

function get_module_from_cmid($cmid) {
    global $CFG, $DB;
    if (!$cmrec = $DB->get_record_sql("SELECT cm.*, md.name as modname
                               FROM {course_modules} cm,
                                    {modules} md
                               WHERE cm.id = ? AND
                                     md.id = cm.module", array($cmid))){
        print_error('invalidcoursemodule');
    } elseif (!$modrec =$DB->get_record($cmrec->modname, array('id' => $cmrec->instance))) {
        print_error('invalidcoursemodule');
    }
    $modrec->instance = $modrec->id;
    $modrec->cmid = $cmrec->id;
    $cmrec->name = $modrec->name;

    return array($modrec, $cmrec);
}
/**
* Function to read all questions for category into big array
*
* @param int $category category number
* @param bool $noparent if true only questions with NO parent will be selected
* @param bool $recurse include subdirectories
* @param bool $export set true if this is called by questionbank export
* @author added by Howard Miller June 2004
*/
function get_questions_category( $category, $noparent=false, $recurse=true, $export=true ) {

    global $QTYPES, $DB;

    // questions will be added to an array
    $qresults = array();

    // build sql bit for $noparent
    $npsql = '';
    if ($noparent) {
      $npsql = " and parent='0' ";
    }

    // get (list) of categories
    if ($recurse) {
        $categorylist = question_categorylist( $category->id );
    }
    else {
        $categorylist = $category->id;
    }

    // get the list of questions for the category
    list ($usql, $params) = $DB->get_in_or_equal(explode(',', $categorylist));
    if ($questions = $DB->get_records_select("question","category $usql $npsql", $params, "qtype, name ASC")) {

        // iterate through questions, getting stuff we need
        foreach($questions as $question) {
            $questiontype = $QTYPES[$question->qtype];
            $question->export_process = $export;
            $questiontype->get_question_options( $question );
            $qresults[] = $question;
        }
    }

    return $qresults;
}

/**
 * @param integer $categoryid a category id.
 * @return boolean whether this is the only top-level category in a context.
 */
function question_is_only_toplevel_category_in_context($categoryid) {
    global $DB;
    return 1 == $DB->count_records_sql("
            SELECT count(*)
              FROM {question_categories} c1,
                   {question_categories} c2
             WHERE c2.id = ?
               AND c1.contextid = c2.contextid
               AND c1.parent = 0 AND c2.parent = 0", array($categoryid));
}

/**
 * Check whether this user is allowed to delete this category.
 *
 * @param integer $todelete a category id.
 */
function question_can_delete_cat($todelete) {
    global $DB;
    if (question_is_only_toplevel_category_in_context($todelete)) {
        print_error('cannotdeletecate', 'question');
    } else {
        $contextid = $DB->get_field('question_categories', 'contextid', array('id' => $todelete));
        require_capability('moodle/question:managecategory', get_context_instance_by_id($contextid));
    }
}

abstract class question_bank_column_base {
    /**
     * @var question_bank_view
     */
    protected $qbank;

    /**
     * Constructor.
     * @param $qbank the question_bank_view we are helping to render.
     */
    public function __construct(question_bank_view $qbank) {
        $this->qbank = $qbank;
        $this->init();
    }

    /**
     * A chance for subclasses to initialise themselves, for example to load lang strings,
     * without having to override the constructor.
     */
    protected function init() {
    }

    public function is_extra_row() {
        return false;
    }

    /**
     * Output the column header cell.
     * @param integer $currentsort 0 for none. 1 for normal sort, -1 for reverse sort.
     */
    public function display_header() {
        echo '<th class="header ' . $this->get_classes() . '" scope="col">';
        $sortable = $this->is_sortable();
        $name = $this->get_name();
        $title = $this->get_title();
        $tip = $this->get_title_tip();
        if (is_array($sortable)) {
            if ($title) {
                echo '<div class="title">' . $title . '</div>';
            }
            $links = array();
            foreach ($sortable as $subsort => $details) {
                $links[] = $this->make_sort_link($name . '_' . $subsort,
                        $details['title'], '', !empty($details['reverse']));
            }
            echo '<div class="sorters">' . implode(' / ', $links) . '</div>';
        } else if ($sortable) {
            echo $this->make_sort_link($name, $title, $tip);
        } else {
            if ($tip) {
                echo '<span title="' . $tip . '">';
            }
            echo $title;
            if ($tip) {
                echo '</span>';
            }
        }
        echo "</th>\n";
    }

    /**
     * Title for this column. Not used if is_sortable returns an array.
     * @param object $question the row from the $question table, augmented with extra information.
     * @param string $rowclasses CSS class names that should be applied to this row of output.
     */
    abstract protected function get_title();

    /**
     * @return string a fuller version of the name. Use this when get_title() returns
     * something very short, and you want a longer version as a tool tip.
     */
    protected function get_title_tip() {
        return '';
    }

    /**
     * Get a link that changes the sort order, and indicates the current sort state.
     * @param $name internal name used for this type of sorting.
     * @param $currentsort the current sort order -1, 0, 1 for descending, none, ascending.
     * @param $title the link text.
     * @param $defaultreverse whether the default sort order for this column is descending, rather than ascending.
     * @return string HTML fragment.
     */
    protected function make_sort_link($sort, $title, $tip, $defaultreverse = false) {
        $currentsort = $this->qbank->get_primary_sort_order($sort);
        $newsortreverse = $defaultreverse;
        if ($currentsort) {
            $newsortreverse = $currentsort > 0;
        }
        if (!$tip) {
            $tip = $title;
        }
        if ($newsortreverse) {
            $tip = get_string('sortbyxreverse', '', $tip);
        } else {
            $tip = get_string('sortbyx', '', $tip);
        }
        $link = '<a href="' . $this->qbank->new_sort_url($sort, $newsortreverse) . '" title="' . $tip . '">';
        $link .= $title;
        if ($currentsort) {
            $link .= $this->get_sort_icon($currentsort < 0);
        }
        $link .= '</a>';
        return $link;
    }

    /**
     * Get an icon representing the corrent sort state.
     * @param $reverse sort is descending, not ascending.
     * @return string HTML image tag.
     */
    protected function get_sort_icon($reverse) {
        global $OUTPUT;
        if ($reverse) {
            return ' <img src="' . $OUTPUT->old_icon_url('t/up') . '" alt="' . get_string('desc') . '" />';
        } else {
            return ' <img src="' . $OUTPUT->old_icon_url('t/down') . '" alt="' . get_string('asc') . '" />';
        }
    }

    /**
     * Output this column.
     * @param object $question the row from the $question table, augmented with extra information.
     * @param string $rowclasses CSS class names that should be applied to this row of output.
     */
    public function display($question, $rowclasses) {
        $this->display_start($question, $rowclasses);
        $this->display_content($question, $rowclasses);
        $this->display_end($question, $rowclasses);
    }

    protected function display_start($question, $rowclasses) {
        echo '<td class="' . $this->get_classes() . '">';
    }

    /**
     * @return string the CSS classes to apply to every cell in this column.
     */
    protected function get_classes() {
        $classes = $this->get_extra_classes();
        $classes[] = $this->get_name();
        return implode(' ', $classes);
    }

    /**
     * @param object $question the row from the $question table, augmented with extra information.
     * @return string internal name for this column. Used as a CSS class name,
     *     and to store information about the current sort. Must match PARAM_ALPHA.
     */
    abstract public function get_name();

    /**
     * @return array any extra class names you would like applied to every cell in this column.
     */
    public function get_extra_classes() {
        return array();
    }

    /**
     * Output the contents of this column.
     * @param object $question the row from the $question table, augmented with extra information.
     * @param string $rowclasses CSS class names that should be applied to this row of output.
     */
    abstract protected function display_content($question, $rowclasses);

    protected function display_end($question, $rowclasses) {
        echo "</td>\n";
    }

    /**
     * Return an array 'table_alias' => 'JOIN clause' to bring in any data that
     * this column required.
     *
     * The return values for all the columns will be checked. It is OK if two
     * columns join in the same table with the same alias and identical JOIN clauses.
     * If to columns try to use the same alias with different joins, you get an error.
     * The only table included by default is the question table, which is aliased to 'q'.
     *
     * It is importnat that your join simply adds additional data (or NULLs) to the
     * existing rows of the query. It must not cause additional rows.
     *
     * @return array 'table_alias' => 'JOIN clause'
     */
    public function get_extra_joins() {
        return array();
    }

    /**
     * @return array fields required. use table alias 'q' for the question table, or one of the
     * ones from get_extra_joins. Every field requested must specify a table prefix.
     */
    public function get_required_fields() {
        return array();
    }

    /**
     * Can this column be sorted on? You can return either:
     *  + false for no (the default),
     *  + a field name, if sorting this column corresponds to sorting on that datbase field.
     *  + an array of subnames to sort on as follows
     *  return array(
     *      'firstname' => array('field' => 'uc.firstname', 'title' => get_string('firstname')),
     *      'lastname' => array('field' => 'uc.lastname', 'field' => get_string('lastname')),
     *  );
     * As well as field, and field, you can also add 'revers' => 1 if you want the default sort
     * order to be DESC.
     * @return mixed as above.
     */
    public function is_sortable() {
        return false;
    }

    /**
     * Helper method for building sort clauses.
     * @param boolean $reverse whether the normal direction should be reversed.
     * @param string $normaldir 'ASC' or 'DESC'
     * @return string 'ASC' or 'DESC'
     */
    protected function sortorder($reverse) {
        if ($reverse) {
            return ' DESC';
        } else {
            return ' ASC';
        }
    }

    /**
     * @param $reverse Whether to sort in the reverse of the default sort order.
     * @param $subsort if is_sortable returns an array of subnames, then this will be
     *      one of those. Otherwise will be empty.
     * @return string some SQL to go in the order by clause.
     */
    public function sort_expression($reverse, $subsort) {
        $sortable = $this->is_sortable();
        if (is_array($sortable)) {
            if (array_key_exists($subsort, $sortable)) {
                return $sortable[$subsort]['field'] . $this->sortorder($reverse, !empty($sortable[$subsort]['reverse']));
            } else {
                throw new coding_exception('Unexpected $subsort type: ' . $subsort);
            }
        } else if ($sortable) {
            return $sortable . $this->sortorder($reverse);
        } else {
            throw new coding_exception('sort_expression called on a non-sortable column.');
        }
    }
}

/**
 * A column with a checkbox for each question with name q{questionid}.
 */
class question_bank_checkbox_column extends question_bank_column_base {
    protected $strselect;
    protected $firstrow = true;

    public function init() {
        $this->strselect = get_string('select', 'quiz');
    }

    public function get_name() {
        return 'checkbox';
    }

    protected function get_title() {
        return '<input type="checkbox" disabled="disabled" id="qbheadercheckbox" />';
    }

    protected function get_title_tip() {
        return get_string('selectquestionsforbulk', 'question');
    }

    protected function display_content($question, $rowclasses) {
        global $PAGE;
        echo '<input title="' . $this->strselect . '" type="checkbox" name="q' .
                $question->id . '" id="checkq' . $question->id . '" value="1"/>';
        if ($this->firstrow) {
            $PAGE->requires->js_function_call('question_bank.init_checkbox_column', array(get_string('selectall'),
                    get_string('deselectall'), 'checkq' . $question->id));
            $this->firstrow = false;
        }
    }

    public function get_required_fields() {
        return array('q.id');
    }
}

/**
 * A column type for the name of the question type.
 */
class question_bank_question_type_column extends question_bank_column_base {
    public function get_name() {
        return 'qtype';
    }

    protected function get_title() {
        return get_string('qtypeveryshort', 'question');
    }

    protected function get_title_tip() {
        return get_string('questiontype', 'question');
    }

    protected function display_content($question, $rowclasses) {
        echo print_question_icon($question);
    }

    public function get_required_fields() {
        return array('q.qtype');
    }

    public function is_sortable() {
        return 'q.qtype';
    }
}

/**
 * A column type for the name of the question name.
 */
class question_bank_question_name_column extends question_bank_column_base {
    protected $checkboxespresent = null;

    public function get_name() {
        return 'questionname';
    }

    protected function get_title() {
        return get_string('question');
    }

    protected function label_for($question) {
        if (is_null($this->checkboxespresent)) {
            $this->checkboxespresent = $this->qbank->has_column('checkbox');
        }
        if ($this->checkboxespresent) {
            return 'checkq' . $question->id;
        } else {
            return '';
        }
    }

    protected function display_content($question, $rowclasses) {
        $labelfor = $this->label_for($question);
        if ($labelfor) {
            echo '<label for="' . $labelfor . '">';
        }
        echo format_string($question->name);
        if ($labelfor) {
            echo '</label>';
        }
    }

    public function get_required_fields() {
        return array('q.id', 'q.name');
    }

    public function is_sortable() {
        return 'q.name';
    }
}

/**
 * A column type for the name of the question creator.
 */
class question_bank_creator_name_column extends question_bank_column_base {
    public function get_name() {
        return 'creatorname';
    }

    protected function get_title() {
        return get_string('createdby', 'question');
    }

    protected function display_content($question, $rowclasses) {
        if (!empty($question->creatorfirstname) && !empty($question->creatorlastname)) {
            $u = new stdClass;
            $u->firstname = $question->creatorfirstname;
            $u->lastname = $question->creatorlastname;
            echo fullname($u);
        }
    }

    public function get_extra_joins() {
        return array('uc' => 'LEFT JOIN {user} uc ON uc.id = q.createdby');
    }

    public function get_required_fields() {
        return array('uc.firstname AS creatorfirstname', 'uc.lastname AS creatorlastname');
    }

    public function is_sortable() {
        return array(
            'firstname' => array('field' => 'uc.firstname', 'title' => get_string('firstname')),
            'lastname' => array('field' => 'uc.lastname', 'title' => get_string('lastname')),
        );
    }
}

/**
 * A column type for the name of the question last modifier.
 */
class question_bank_modifier_name_column extends question_bank_column_base {
    public function get_name() {
        return 'modifiername';
    }

    protected function get_title() {
        return get_string('lastmodifiedby', 'question');
    }

    protected function display_content($question, $rowclasses) {
        if (!empty($question->modifierfirstname) && !empty($question->modifierlastname)) {
            $u = new stdClass;
            $u->firstname = $question->modifierfirstname;
            $u->lastname = $question->modifierlastname;
            echo fullname($u);
        }
    }

    public function get_extra_joins() {
        return array('um' => 'LEFT JOIN {user} um ON um.id = q.modifiedby');
    }

    public function get_required_fields() {
        return array('um.firstname AS modifierfirstname', 'um.lastname AS modifierlastname');
    }

    public function is_sortable() {
        return array(
            'firstname' => array('field' => 'um.firstname', 'title' => get_string('firstname')),
            'lastname' => array('field' => 'um.lastname', 'title' => get_string('lastname')),
        );
    }
}

/**
 * A base class for actions that are an icon that lets you manipulate the question in some way.
 */
abstract class question_bank_action_column_base extends question_bank_column_base {

    protected function get_title() {
        return '&#160;';
    }

    public function get_extra_classes() {
        return array('iconcol');
    }

    protected function print_icon($icon, $title, $url) {
        global $OUTPUT;
        echo '<a title="' . $title . '" href="' . $url . '">
                <img src="' . $OUTPUT->old_icon_url($icon) . '" class="iconsmall" alt="' . $title . '" /></a>';
    }

    public function get_required_fields() {
        return array('q.id');
    }
}

class question_bank_edit_action_column extends question_bank_action_column_base {
    protected $stredit;
    protected $strview;

    public function init() {
        parent::init();
        $this->stredit = get_string('edit');
        $this->strview = get_string('view');
    }

    public function get_name() {
        return 'editaction';
    }

    protected function display_content($question, $rowclasses) {
        if (question_has_capability_on($question, 'edit') ||
                question_has_capability_on($question, 'move')) {
            $this->print_icon('t/edit', $this->stredit, $this->qbank->edit_question_url($question->id));
        } else {
            $this->print_icon('i/info', $this->strview, $this->qbank->edit_question_url($question->id));
        }
    }
}

class question_bank_preview_action_column extends question_bank_action_column_base {
    protected $strpreview;

    public function init() {
        parent::init();
        $this->strpreview = get_string('preview');
    }

    public function get_name() {
        return 'previewaction';
    }

    protected function display_content($question, $rowclasses) {
        global $OUTPUT;
        if (question_has_capability_on($question, 'use')) {
            link_to_popup_window($this->qbank->preview_question_url($question->id), 'questionpreview',
                    ' <img src="' . $OUTPUT->old_icon_url('t/preview') . '" class="iconsmall" alt="' . $this->strpreview . '" />',
                    0, 0, $this->strpreview, QUESTION_PREVIEW_POPUP_OPTIONS);
        }
    }

    public function get_required_fields() {
        return array('q.id');
    }
}

class question_bank_move_action_column extends question_bank_action_column_base {
    protected $strmove;

    public function init() {
        parent::init();
        $this->strmove = get_string('move');
    }

    public function get_name() {
        return 'moveaction';
    }

    protected function display_content($question, $rowclasses) {
        if (question_has_capability_on($question, 'move')) {
            $this->print_icon('t/move', $this->strmove, $this->qbank->move_question_url($question->id));
        }
    }
}

/**
 * action to delete (or hide) a question, or restore a previously hidden question.
 */
class question_bank_delete_action_column extends question_bank_action_column_base {
    protected $strdelete;
    protected $strrestore;

    public function init() {
        parent::init();
        $this->strdelete = get_string('delete');
        $this->strrestore = get_string('restore');
    }

    public function get_name() {
        return 'deleteaction';
    }

    protected function display_content($question, $rowclasses) {
        if (question_has_capability_on($question, 'edit')) {
            if ($question->hidden) {
                $this->print_icon('t/restore', $this->strrestore, $this->qbank->base_url()->out_action(array('unhide' => $question->id)));
            } else {
                $this->print_icon('t/delete', $this->strdelete,
                        $this->qbank->base_url()->out_action(array('deleteselected' => $question->id, 'q' . $question->id => 1)));
            }
        }
    }

    public function get_required_fields() {
        return array('q.id', 'q.hidden');
    }
}

/**
 * Base class for 'columns' that are actually displayed as a row following the main question row.
 */
abstract class question_bank_row_base extends question_bank_column_base {
    public function is_extra_row() {
        return true;
    }

    protected function display_start($question, $rowclasses) {
        if ($rowclasses) {
            echo '<tr class="' . $rowclasses . '">' . "\n";
        } else {
            echo "<tr>\n";
        }
        echo '<td colspan="' . $this->qbank->get_column_count() . '" class="' . $this->get_name() . '">';
    }

    protected function display_end($question, $rowclasses) {
        echo "</td></tr>\n";
    }
}

/**
 * A column type for the name of the question name.
 */
class question_bank_question_text_row extends question_bank_row_base {
    protected $formatoptions;

    protected function init() {
        $this->formatoptions = new stdClass;
        $this->formatoptions->noclean = true;
        $this->formatoptions->para = false;
    }

    public function get_name() {
        return 'questiontext';
    }

    protected function get_title() {
        return get_string('questiontext', 'question');
    }

    protected function display_content($question, $rowclasses) {
        $text = format_text($question->questiontext, $question->questiontextformat,
                $this->formatoptions, $this->qbank->get_courseid());
        if ($text == '') {
            $text = '&#160;';
        }
        echo $text;
    }

    public function get_required_fields() {
        return array('q.questiontext', 'q.questiontextformat');
    }
}

/**
 * This class prints a view of the question bank, including
 *  + Some controls to allow users to to select what is displayed.
 *  + A list of questions as a table.
 *  + Further controls to do things with the questions.
 *
 * This class gives a basic view, and provides plenty of hooks where subclasses
 * can override parts of the display.
 *
 * The list of questions presented as a table is generated by creating a list of
 * question_bank_column objects, one for each 'column' to be displayed. These
 * manage
 *  + outputting the contents of that column, given a $question object, but also
 *  + generating the right fragments of SQL to ensure the necessary data is present,
 *    and sorted in the right order.
 *  + outputting table headers.
 */
class question_bank_view {
    const MAX_SORTS = 3;

    protected $baseurl;
    protected $editquestionurl;
    protected $quizorcourseid;
    protected $contexts;
    protected $cm;
    protected $course;
    protected $knowncolumntypes;
    protected $visiblecolumns;
    protected $extrarows;
    protected $requiredcolumns;
    protected $sort;
    protected $lastchangedid;
    protected $countsql;
    protected $loadsql;
    protected $sqlparams;

    public function __construct($contexts, $pageurl, $course, $cm = null) {
        global $CFG, $PAGE;

        $this->contexts = $contexts;
        $this->baseurl = $pageurl;
        $this->course = $course;
        $this->cm = $cm;

        if (!empty($cm) && $cm->modname == 'quiz') {
            $this->quizorcourseid = '&amp;quizid=' . $cm->instance;
        } else {
            $this->quizorcourseid = '&amp;courseid=' .$this->course->id;
        }

        // Create the url of the new question page to forward to.
        $this->editquestionurl = new moodle_url("$CFG->wwwroot/question/question.php",
                array('returnurl' => $pageurl->out()));
        if ($cm !== null){
            $this->editquestionurl->param('cmid', $cm->id);
        } else {
            $this->editquestionurl->param('courseid', $this->course->id);
        }

        $this->lastchangedid = optional_param('lastchanged',0,PARAM_INT);

        $this->init_column_types();
        $this->init_columns($this->wanted_columns());
        $this->init_sort();

        $PAGE->requires->yui_lib('container');
    }

    protected function wanted_columns() {
        $columns = array('checkbox', 'qtype', 'questionname', 'creatorname',
                'modifiername', 'editaction', 'previewaction', 'moveaction', 'deleteaction');
        if (optional_param('qbshowtext', false, PARAM_BOOL)) {
            $columns[] = 'questiontext';
        }
        return $columns;
    }

    protected function known_field_types() {
        return array(
            new question_bank_checkbox_column($this),
            new question_bank_question_type_column($this),
            new question_bank_question_name_column($this),
            new question_bank_creator_name_column($this),
            new question_bank_modifier_name_column($this),
            new question_bank_edit_action_column($this),
            new question_bank_preview_action_column($this),
            new question_bank_move_action_column($this),
            new question_bank_delete_action_column($this),
            new question_bank_question_text_row($this),
        );
    }

    protected function init_column_types() {
        $this->knowncolumntypes = array();
        foreach ($this->known_field_types() as $col) {
            $this->knowncolumntypes[$col->get_name()] = $col;
        }
    }

    protected function init_columns($wanted) {
        $this->visiblecolumns = array();
        $this->extrarows = array();
        foreach ($wanted as $colname) {
            if (!isset($this->knowncolumntypes[$colname])) {
                throw new coding_exception('Unknown column type ' . $colname . ' requested in init columns.');
            }
            $column = $this->knowncolumntypes[$colname];
            if ($column->is_extra_row()) {
                $this->extrarows[$colname] = $column;
            } else {
                $this->visiblecolumns[$colname] = $column;
            }
        }
        $this->requiredcolumns = array_merge($this->visiblecolumns, $this->extrarows);
    }

    /**
     * @param string $colname a column internal name.
     * @return boolean is this column included in the output?
     */
    public function has_column($colname) {
        return isset($this->visiblecolumns[$colname]);
    }

    /**
     * @return integer The number of columns in the table.
     */
    public function get_column_count() {
        return count($this->visiblecolumns);
    }

    public function get_courseid() {
        return $this->course->id;
    }

    protected function init_sort() {
        $this->init_sort_from_params();
        if (empty($this->sort)) {
            $this->sort = $this->default_sort();
        }
    }

    /**
     * Deal with a sort name of the forum columnname, or colname_subsort by
     * breaking it up, validating the bits that are presend, and returning them.
     * If there is no subsort, then $subsort is returned as ''.
     * @return array array($colname, $subsort).
     */
    protected function parse_subsort($sort) {
    /// Do the parsing.
        if (strpos($sort, '_') !== false) {
            list($colname, $subsort) = explode('_', $sort, 2);
        } else {
            $colname = $sort;
            $subsort = '';
        }
    /// Validate the column name.
        if (!isset($this->knowncolumntypes[$colname]) || !$this->knowncolumntypes[$colname]->is_sortable()) {
            for ($i = 1; $i <= question_bank_view::MAX_SORTS; $i++) {
                $this->baseurl->remove_params('qbs' . $i);
            }
            throw new moodle_exception('unknownsortcolumn', '', $link = $this->baseurl->out(), $colname);
        }
    /// Validate the subsort, if present.
        if ($subsort) {
            $subsorts = $this->knowncolumntypes[$colname]->is_sortable();
            if (!is_array($subsorts) || !isset($subsorts[$subsort])) {
                throw new moodle_exception('unknownsortcolumn', '', $link = $this->baseurl->out(), $sort);
            }
        }
        return array($colname, $subsort);
    }

    protected function init_sort_from_params() {
        $this->sort = array();
        for ($i = 1; $i <= question_bank_view::MAX_SORTS; $i++) {
            if (!$sort = optional_param('qbs' . $i, '', PARAM_ALPHAEXT)) {
                break;
            }
            // Work out the appropriate order.
            $order = 1;
            if ($sort[0] == '-') {
                $order = -1;
                $sort = substr($sort, 1);
                if (!$sort) {
                    break;
                }
            }
            // Deal with subsorts.
            list($colname, $subsort) = $this->parse_subsort($sort);
            $this->requiredcolumns[$colname] = $this->knowncolumntypes[$colname];
            $this->sort[$sort] = $order;
        }
    }

    protected function sort_to_params($sorts) {
        $params = array();
        $i = 0;
        foreach ($sorts as $sort => $order) {
            $i += 1;
            if ($order < 0) {
                $sort = '-' . $sort;
            }
            $params['qbs' . $i] = $sort;
        }
        return $params;
    }

    protected function default_sort() {
        return array('qtype' => 1, 'questionname' => 1);
    }

    /**
     * @param $sort a column or column_subsort name.
     * @return integer the current sort order for this column -1, 0, 1
     */
    public function get_primary_sort_order($sort) {
        $order = reset($this->sort);
        $primarysort = key($this->sort);
        if ($sort == $primarysort) {
            return $order;
        } else {
            return 0;
        }
    }

    /**
     * Get a URL to redisplay the page with a new sort for the question bank.
     * @param string $sort the column, or column_subsort to sort on.
     * @param boolean $newsortreverse whether to sort in reverse order.
     * @return string The new URL.
     */
    public function new_sort_url($sort, $newsortreverse) {
        if ($newsortreverse) {
            $order = -1;
        } else {
            $order = 1;
        }
        // Tricky code to add the new sort at the start, removing it from where it was before, if it was present.
        $newsort = array_reverse($this->sort);
        if (isset($newsort[$sort])) {
            unset($newsort[$sort]);
        }
        $newsort[$sort] = $order;
        $newsort = array_reverse($newsort);
        if (count($newsort) > question_bank_view::MAX_SORTS) {
            $newsort = array_slice($newsort, 0, question_bank_view::MAX_SORTS, true);
        }
        return $this->baseurl->out(false, $this->sort_to_params($newsort));
    }

    protected function build_query_sql($category, $recurse, $showhidden) {
        global $DB;

    /// Get the required tables.
        $joins = array();
        foreach ($this->requiredcolumns as $column) {
            $extrajoins = $column->get_extra_joins();
            foreach ($extrajoins as $prefix => $join) {
                if (isset($joins[$prefix]) && $joins[$prefix] != $join) {
                    throw new coding_exception('Join ' . $join . ' conflicts with previous join ' . $joins[$prefix]);
                }
                $joins[$prefix] = $join;
            }
        }

    /// Get the required fields.
        $fields = array('q.hidden', 'q.category');
        foreach ($this->visiblecolumns as $column) {
            $fields = array_merge($fields, $column->get_required_fields());
        }
        foreach ($this->extrarows as $row) {
            $fields = array_merge($fields, $row->get_required_fields());
        }
        $fields = array_unique($fields);

    /// Build the order by clause.
        $sorts = array();
        foreach ($this->sort as $sort => $order) {
            list($colname, $subsort) = $this->parse_subsort($sort);
            $sorts[] = $this->knowncolumntypes[$colname]->sort_expression($order < 0, $subsort);
        }

    /// Build the where clause.
        $tests = array('parent = 0');

        if ($showhidden) {
            $tests[] = 'hidden = 0';
        }

        if ($recurse) {
            $categoryids = explode(',', question_categorylist($category->id));
        } else {
            $categoryids = array($category->id);
        }
        list($catidtest, $params) = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED, 'cat0000');
        $tests[] = 'q.category ' . $catidtest;
        $this->sqlparams = $params;

    /// Build the SQL.
        $sql = ' FROM {question} q ' . implode(' ', $joins);
        $sql .= ' WHERE ' . implode(' AND ', $tests);
        $this->countsql = 'SELECT count(1)' . $sql;
        $this->loadsql = 'SELECT ' . implode(', ', $fields) . $sql . ' ORDER BY ' . implode(', ', $sorts);
        $this->sqlparams = $params;
    }

    protected function get_question_count() {
        global $DB;
        return $DB->count_records_sql($this->countsql, $this->sqlparams);
    }

    protected function load_page_questions($page, $perpage) {
        global $DB;
        $questions = $DB->get_recordset_sql($this->loadsql, $this->sqlparams, $page*$perpage, $perpage);
        if (!$questions->valid()) {
        /// No questions on this page. Reset to page 0.
            $questions = $DB->get_recordset_sql($this->loadsql, $this->sqlparams, 0, $perpage);
        }
        return $questions;
    }

    public function base_url() {
        return $this->baseurl;
    }

    public function edit_question_url($questionid) {
        return $this->editquestionurl->out(false, array('id' => $questionid));
    }

    public function move_question_url($questionid) {
        return $this->editquestionurl->out(false, array('id' => $questionid, 'movecontext' => 1));
    }

    public function preview_question_url($questionid) {
        global $CFG;
        return $CFG->wwwroot . '/question/preview.php?id=' . $questionid . '&amp;courseid=' . $this->course->id;
    }

    /**
     * Shows the question bank editing interface.
     *
     * The function also processes a number of actions:
     *
     * Actions affecting the question pool:
     * move           Moves a question to a different category
     * deleteselected Deletes the selected questions from the category
     * Other actions:
     * category      Chooses the category
     * displayoptions Sets display options
     */
    public function display($tabname, $page, $perpage, $sortorder,
            $sortorderdecoded, $cat, $recurse, $showhidden, $showquestiontext){
        global $PAGE, $OUTPUT;

        if ($this->process_actions_needing_ui()) {
            return;
        }

        $PAGE->requires->js('question/qbank.js');

        // Category selection form
        echo $OUTPUT->heading(get_string('questionbank', 'question'), 2);

        $this->display_category_form($this->contexts->having_one_edit_tab_cap($tabname),
                $this->baseurl, $cat);
        $this->display_options($recurse, $showhidden, $showquestiontext);

        if (!$category = $this->get_current_category($cat)) {
            return;
        }
        $this->print_category_info($category);

        // continues with list of questions
        $this->display_question_list($this->contexts->having_one_edit_tab_cap($tabname), $this->baseurl, $cat, $this->cm,
                $recurse, $page, $perpage, $showhidden, $sortorder, $sortorderdecoded, $showquestiontext,
                $this->contexts->having_cap('moodle/question:add'));
    }

    protected function print_choose_category_message($categoryandcontext) {
        echo "<p style=\"text-align:center;\"><b>";
        print_string("selectcategoryabove", "quiz");
        echo "</b></p>";
    }

    protected function get_current_category($categoryandcontext) {
        global $DB, $OUTPUT;
        list($categoryid, $contextid) = explode(',', $categoryandcontext);
        if (!$categoryid) {
            $this->print_choose_category_message($categoryandcontext);
            return false;
        }

        if (!$category = $DB->get_record('question_categories',
                array('id' => $categoryid, 'contextid' => $contextid))) {
            echo $OUTPUT->box_start('generalbox questionbank');
            notify('Category not found!');
            echo $OUTPUT->box_end();
            return false;
        }

        return $category;
    }

    protected function print_category_info($category) {
        $formatoptions = new stdClass;
        $formatoptions->noclean = true;
        echo '<div class="boxaligncenter">';
        echo format_text($category->info, FORMAT_MOODLE, $formatoptions, $this->course->id);
        echo "</div>\n";
    }

    /**
     * prints a form to choose categories
     */
    protected function display_category_form($contexts, $pageurl, $current) {
        global $CFG, $OUTPUT;

    /// Get all the existing categories now
        echo '<div class="choosecategory">';
        $catmenu = question_category_options($contexts, false, 0, true);
        $select = html_select::make_popup_form('edit.php?'.$pageurl->get_query_string(), 'category', $catmenu, 'catmenu', $current);
        $select->nothinglabel = false;
        $select->set_label(get_string('selectacategory', 'question'));
        echo $OUTPUT->select($select);
        echo "</div>\n";
    }

    protected function display_options($recurse = 1, $showhidden = false, $showquestiontext = false) {
        echo '<form method="get" action="edit.php" id="displayoptions">';
        echo "<fieldset class='invisiblefieldset'>";
        echo $this->baseurl->hidden_params_out(array('recurse', 'showhidden', 'showquestiontext'));
        $this->display_category_form_checkbox('recurse', get_string('recurse', 'quiz'));
        $this->display_category_form_checkbox('showhidden', get_string('showhidden', 'quiz'));
        $this->display_category_form_checkbox('qbshowtext', get_string('showquestiontext', 'quiz'));
        echo '<noscript><div class="centerpara"><input type="submit" value="'. get_string('go') .'" />';
        echo '</div></noscript></fieldset></form>';
    }

    /**
     * Print a single option checkbox. Used by the preceeding.
     */
    protected function display_category_form_checkbox($name, $label) {
        echo '<div><input type="hidden" id="' . $name . '_off" name="' . $name . '" value="0" />';
        echo '<input type="checkbox" id="' . $name . '_on" name="' . $name . '" value="1"';
        if (optional_param($name, false, PARAM_BOOL)) {
            echo ' checked="checked"';
        }
        echo ' onchange="getElementById(\'displayoptions\').submit(); return true;" />';
        echo '<label for="' . $name . '_on">' . $label . '</label>';
        echo "</div>\n";
    }

    protected function create_new_question_form($category, $canadd) {
        global $CFG;
        echo '<div class="createnewquestion">';
        if ($canadd) {
            create_new_question_button($category->id, $this->editquestionurl->params(),
                    get_string('createnewquestion', 'question'));
        } else {
            print_string('nopermissionadd', 'question');
        }
        echo '</div>';
    }

    /**
    * Prints the table of questions in a category with interactions
    *
    * @param object $course   The course object
    * @param int $categoryid  The id of the question category to be displayed
    * @param int $cm      The course module record if we are in the context of a particular module, 0 otherwise
    * @param int $recurse     This is 1 if subcategories should be included, 0 otherwise
    * @param int $page        The number of the page to be displayed
    * @param int $perpage     Number of questions to show per page
    * @param boolean $showhidden   True if also hidden questions should be displayed
    * @param boolean $showquestiontext whether the text of each question should be shown in the list
    */
    protected function display_question_list($contexts, $pageurl, $categoryandcontext,
            $cm = null, $recurse=1, $page=0, $perpage=100, $showhidden=false,
            $sortorder='typename', $sortorderdecoded='qtype, name ASC',
            $showquestiontext = false, $addcontexts = array()) {
        global $CFG, $DB;

        $category = $this->get_current_category($categoryandcontext);

        $cmoptions = new stdClass;
        $cmoptions->hasattempts = !empty($this->quizhasattempts);

        $strselectall = get_string("selectall", "quiz");
        $strselectnone = get_string("selectnone", "quiz");
        $strdelete = get_string("delete");

        list($categoryid, $contextid) = explode(',', $categoryandcontext);
        $catcontext = get_context_instance_by_id($contextid);

        $canadd = has_capability('moodle/question:add', $catcontext);
        $caneditall =has_capability('moodle/question:editall', $catcontext);
        $canuseall =has_capability('moodle/question:useall', $catcontext);
        $canmoveall =has_capability('moodle/question:moveall', $catcontext);

        $this->create_new_question_form($category, $canadd);

        $this->build_query_sql($category, $recurse, $showhidden);
        $totalnumber = $this->get_question_count();
        if ($totalnumber == 0) {
            return;
        }

        $questions = $this->load_page_questions($page, $perpage);

        echo '<div class="categorypagingbarcontainer">';
        $pagingbar = moodle_paging_bar::make($totalnumber, $page, $perpage, $pageurl);
        $pagingbar->pagevar = 'qpage';
        echo $OUTPUT->paging_bar($pagingbar);
        echo '</div>';

        echo '<form method="post" action="edit.php">';
        echo '<fieldset class="invisiblefieldset" style="display: block;">';
        echo '<input type="hidden" name="sesskey" value="'.sesskey().'" />';
        echo $pageurl->hidden_params_out();

        echo '<div class="categoryquestionscontainer">';
        $this->start_table();
        $rowcount = 0;
        foreach ($questions as $question) {
            $this->print_table_row($question, $rowcount);
            $rowcount += 1;
        }
        $this->end_table();
        echo "</div>\n";

        echo '<div class="categorypagingbarcontainer pagingbottom">';
        echo $OUTPUT->paging_bar($pagingbar);
        if ($totalnumber > DEFAULT_QUESTIONS_PER_PAGE) {
            if ($perpage == DEFAULT_QUESTIONS_PER_PAGE) {
                $showall = '<a href="edit.php?'.$pageurl->get_query_string(array('qperpage'=>1000)).'">'.get_string('showall', 'moodle', $totalnumber).'</a>';
            } else {
                $showall = '<a href="edit.php?'.$pageurl->get_query_string(array('qperpage'=>DEFAULT_QUESTIONS_PER_PAGE)).'">'.get_string('showperpage', 'moodle', DEFAULT_QUESTIONS_PER_PAGE).'</a>';
            }
            if ($paging) {
                $paging = substr($paging, 0, strrpos($paging, '</div>'));
                $paging .= "<br />$showall</div>";
            } else {
                $paging = "<div class='paging'>$showall</div>";
            }
        }
        echo $paging;
        echo '</div>';

        echo '<div class="modulespecificbuttonscontainer">';
        if ($caneditall || $canmoveall || $canuseall){
            echo '<strong>&nbsp;'.get_string('withselected', 'quiz').':</strong><br />';

            if (function_exists('module_specific_buttons')) {
                echo module_specific_buttons($this->cm->id,$cmoptions);
            }

            // print delete and move selected question
            if ($caneditall) {
                echo '<input type="submit" name="deleteselected" value="' . $strdelete . "\" />\n";
            }

            if ($canmoveall && count($addcontexts)) {
                echo '<input type="submit" name="move" value="'.get_string('moveto', 'quiz')."\" />\n";
                question_category_select_menu($addcontexts, false, 0, "$category->id,$category->contextid");
            }

            if (function_exists('module_specific_controls') && $canuseall) {
                $modulespecific = module_specific_controls($totalnumber, $recurse, $category, $this->cm->id,$cmoptions);
                if(!empty($modulespecific)){
                    echo "<hr />$modulespecific";
                }
            }
        }
        echo "</div>\n";

        echo '</fieldset>';
        echo "</form>\n";
    }

    protected function start_table() {
        echo '<table id="categoryquestions">' . "\n";
        echo "<thead>\n";
        $this->print_table_headers();
        echo "</thead>\n";
        echo "<tbody>\n";
    }

    protected function end_table() {
        echo "</tbody>\n";
        echo "</table>\n";
    }

    protected function print_table_headers() {
        echo "<tr>\n";
        foreach ($this->visiblecolumns as $column) {
            $column->display_header();
        }
        echo "</tr>\n";
    }

    protected function get_row_classes($question, $rowcount) {
        $classes = array();
        if ($question->hidden) {
            $classes[] = 'dimmed_text';
        }
        if ($question->id == $this->lastchangedid) {
            $classes[] ='highlight';
        }
        if (!empty($this->extrarows)) {
            $classes[] = 'r' . ($rowcount % 2);
        }
        return $classes;
    }

    protected function print_table_row($question, $rowcount) {
        $rowclasses = implode(' ', $this->get_row_classes($question, $rowcount));
        if ($rowclasses) {
            echo '<tr class="' . $rowclasses . '">' . "\n";
        } else {
            echo "<tr>\n";
        }
        foreach ($this->visiblecolumns as $column) {
            $column->display($question, $rowclasses);
        }
        echo "</tr>\n";
        foreach ($this->extrarows as $row) {
            $row->display($question, $rowclasses);
        }
    }

    public function process_actions() {
        global $CFG, $DB;
        /// Now, check for commands on this page and modify variables as necessary
        if (optional_param('move', false, PARAM_BOOL) and confirm_sesskey()) { /// Move selected questions to new category
            $category = required_param('category', PARAM_SEQUENCE);
            list($tocategoryid, $contextid) = explode(',', $category);
            if (! $tocategory = $DB->get_record('question_categories', array('id' => $tocategoryid, 'contextid' => $contextid))) {
                print_error('cannotfindcate', 'question');
            }
            $tocontext = get_context_instance_by_id($contextid);
            require_capability('moodle/question:add', $tocontext);
            $rawdata = (array) data_submitted();
            $questionids = array();
            foreach ($rawdata as $key => $value) {    // Parse input for question ids
                if (preg_match('!^q([0-9]+)$!', $key, $matches)) {
                    $key = $matches[1];
                    $questionids[] = $key;
                }
            }
            if ($questionids){
                list($usql, $params) = $DB->get_in_or_equal($questionids);
                $sql = "SELECT q.*, c.contextid FROM {question} q, {question_categories} c WHERE q.id $usql AND c.id = q.category";
                if (!$questions = $DB->get_records_sql($sql, $params)){
                    print_error('questiondoesnotexist', 'question', $pageurl->out());
                }
                $checkforfiles = false;
                foreach ($questions as $question){
                    //check capabilities
                    question_require_capability_on($question, 'move');
                    $fromcontext = get_context_instance_by_id($question->contextid);
                    if (get_filesdir_from_context($fromcontext) != get_filesdir_from_context($tocontext)){
                        $checkforfiles = true;
                    }
                }
                $returnurl = $this->baseurl->out(false, array('category'=>"$tocategoryid,$contextid"));
                if (!$checkforfiles){
                    if (!question_move_questions_to_category(implode(',', $questionids), $tocategory->id)) {
                        print_error('errormovingquestions', 'question', $returnurl, $questionids);
                    }
                    redirect($returnurl);
                } else {
                    $movecontexturl  = new moodle_url($CFG->wwwroot.'/question/contextmoveq.php',
                                                    array('returnurl' => $returnurl,
                                                            'ids'=>$questionidlist,
                                                            'tocatid'=> $tocategoryid));
                    if ($cm){
                        $movecontexturl->param('cmid', $cm->id);
                    } else {
                        $movecontexturl->param('courseid', $this->course->id);
                    }
                    redirect($movecontexturl);
                }
            }
        }

        if (optional_param('deleteselected', false, PARAM_BOOL)) { // delete selected questions from the category
            if (($confirm = optional_param('confirm', '', PARAM_ALPHANUM)) and confirm_sesskey()) { // teacher has already confirmed the action
                $deleteselected = required_param('deleteselected');
                if ($confirm == md5($deleteselected)) {
                    if ($questionlist = explode(',', $deleteselected)) {
                        // for each question either hide it if it is in use or delete it
                        foreach ($questionlist as $questionid) {
                            question_require_capability_on($questionid, 'edit');
                            if ($DB->record_exists('quiz_question_instances', array('question' => $questionid))) {
                                if (!$DB->set_field('question', 'hidden', 1, array('id' => $questionid))) {
                                    question_require_capability_on($questionid, 'edit');
                                    print_error('cannothidequestion', 'question');
                                }
                            } else {
                                delete_question($questionid);
                            }
                        }
                    }
                    redirect($this->baseurl);
                } else {
                    print_error('invalidconfirm', 'question');
                }
            }
        }

        // Unhide a question
        if(($unhide = optional_param('unhide', '', PARAM_INT)) and confirm_sesskey()) {
            question_require_capability_on($unhide, 'edit');
            if(!$DB->set_field('question', 'hidden', 0, array('id' => $unhide))) {
                print_error('cannotunhidequestion', 'question');
            }
            redirect($this->baseurl);
        }
    }

    public function process_actions_needing_ui() {
        global $DB;
        if (optional_param('deleteselected', false, PARAM_BOOL)) {
            // make a list of all the questions that are selected
            $rawquestions = $_REQUEST; // This code is called by both POST forms and GET links, so cannot use data_submitted.
            $questionlist = '';  // comma separated list of ids of questions to be deleted
            $questionnames = ''; // string with names of questions separated by <br /> with
                                 // an asterix in front of those that are in use
            $inuse = false;      // set to true if at least one of the questions is in use
            foreach ($rawquestions as $key => $value) {    // Parse input for question ids
                if (preg_match('!^q([0-9]+)$!', $key, $matches)) {
                    $key = $matches[1];
                    $questionlist .= $key.',';
                    question_require_capability_on($key, 'edit');
                    if ($DB->record_exists('quiz_question_instances', array('question' => $key))) {
                        $questionnames .= '* ';
                        $inuse = true;
                    }
                    $questionnames .= $DB->get_field('question', 'name', array('id' => $key)) . '<br />';
                }
            }
            if (!$questionlist) { // no questions were selected
                redirect($this->baseurl);
            }
            $questionlist = rtrim($questionlist, ',');

            // Add an explanation about questions in use
            if ($inuse) {
                $questionnames .= '<br />'.get_string('questionsinuse', 'quiz');
            }
            notice_yesno(get_string("deletequestionscheck", "quiz", $questionnames),
                        $this->baseurl->out_action(),
                        $this->baseurl->out(true),
                        array('deleteselected'=>$questionlist, 'confirm'=>md5($questionlist)),
                        $this->baseurl->params(), 'post', 'get');

            return true;
        }
    }
}

/**
 * Common setup for all pages for editing questions.
 * @param string $edittab code for this edit tab
 * @param boolean $requirecmid require cmid? default false
 * @param boolean $requirecourseid require courseid, if cmid is not given? default true
 * @return array $thispageurl, $contexts, $cmid, $cm, $module, $pagevars
 */
function question_edit_setup($edittab, $requirecmid = false, $requirecourseid = true){
    global $QUESTION_EDITTABCAPS, $DB;

    //$thispageurl is used to construct urls for all question edit pages we link to from this page. It contains an array
    //of parameters that are passed from page to page.
    $thispageurl = new moodle_url();
    $thispageurl->remove_params(); // We are going to explicity add back everything important - this avoids unwanted params from being retained.

    if ($requirecmid){
        $cmid =required_param('cmid', PARAM_INT);
    } else {
        $cmid = optional_param('cmid', 0, PARAM_INT);
    }
    if ($cmid){
        list($module, $cm) = get_module_from_cmid($cmid);
        $courseid = $cm->course;
        $thispageurl->params(compact('cmid'));
        require_login($courseid, false, $cm);
        $thiscontext = get_context_instance(CONTEXT_MODULE, $cmid);
    } else {
        $module = null;
        $cm = null;
        if ($requirecourseid){
            $courseid  = required_param('courseid', PARAM_INT);
        } else {
            $courseid  = optional_param('courseid', 0, PARAM_INT);
        }
        if ($courseid){
            $thispageurl->params(compact('courseid'));
            require_login($courseid, false);
            $thiscontext = get_context_instance(CONTEXT_COURSE, $courseid);
        } else {
            $thiscontext = null;
        }
    }

    if ($thiscontext){
        $contexts = new question_edit_contexts($thiscontext);
        $contexts->require_one_edit_tab_cap($edittab);

    } else {
        $contexts = null;
    }



    $pagevars['qpage'] = optional_param('qpage', -1, PARAM_INT);

    //pass 'cat' from page to page and when 'category' comes from a drop down menu
    //then we also reset the qpage so we go to page 1 of
    //a new cat.
    $pagevars['cat'] = optional_param('cat', 0, PARAM_SEQUENCE);// if empty will be set up later
    if  ($category = optional_param('category', 0, PARAM_SEQUENCE)){
        if ($pagevars['cat'] != $category){ // is this a move to a new category?
            $pagevars['cat'] = $category;
            $pagevars['qpage'] = 0;
        }
    }
    if ($pagevars['cat']){
        $thispageurl->param('cat', $pagevars['cat']);
    }
    if ($pagevars['qpage'] > -1) {
        $thispageurl->param('qpage', $pagevars['qpage']);
    } else {
        $pagevars['qpage'] = 0;
    }

    $pagevars['qperpage'] = optional_param('qperpage', -1, PARAM_INT);
    if ($pagevars['qperpage'] > -1) {
        $thispageurl->param('qperpage', $pagevars['qperpage']);
    } else {
        $pagevars['qperpage'] = DEFAULT_QUESTIONS_PER_PAGE;
    }

    $sortoptions = array('alpha' => 'name, qtype ASC',
                          'typealpha' => 'qtype, name ASC',
                          'age' => 'id ASC');

    if ($sortorder = optional_param('qsortorder', '', PARAM_ALPHA)) {
        $pagevars['qsortorderdecoded'] = $sortoptions[$sortorder];
        $pagevars['qsortorder'] = $sortorder;
        $thispageurl->param('qsortorder', $sortorder);
    } else {
        $pagevars['qsortorderdecoded'] = $sortoptions['typealpha'];
        $pagevars['qsortorder'] = 'typealpha';
    }

    $defaultcategory = question_make_default_categories($contexts->all());

    $contextlistarr = array();
    foreach ($contexts->having_one_edit_tab_cap($edittab) as $context){
        $contextlistarr[] = "'$context->id'";
    }
    $contextlist = join($contextlistarr, ' ,');
    if (!empty($pagevars['cat'])){
        $catparts = explode(',', $pagevars['cat']);
        if (!$catparts[0] || (FALSE !== array_search($catparts[1], $contextlistarr)) ||
                !$DB->count_records_select("question_categories", "id = ? AND contextid = ?", array($catparts[0], $catparts[1]))) {
            print_error('invalidcategory', 'quiz');
        }
    } else {
        $category = $defaultcategory;
        $pagevars['cat'] = "$category->id,$category->contextid";
    }

    if(($recurse = optional_param('recurse', -1, PARAM_BOOL)) != -1) {
        $pagevars['recurse'] = $recurse;
        $thispageurl->param('recurse', $recurse);
    } else {
        $pagevars['recurse'] = 1;
    }

    if(($showhidden = optional_param('showhidden', -1, PARAM_BOOL)) != -1) {
        $pagevars['showhidden'] = $showhidden;
        $thispageurl->param('showhidden', $showhidden);
    } else {
        $pagevars['showhidden'] = 0;
    }

    if(($showquestiontext = optional_param('showquestiontext', -1, PARAM_BOOL)) != -1) {
        $pagevars['showquestiontext'] = $showquestiontext;
        $thispageurl->param('showquestiontext', $showquestiontext);
    } else {
        $pagevars['showquestiontext'] = 0;
    }

    //category list page
    $pagevars['cpage'] = optional_param('cpage', 1, PARAM_INT);
    if ($pagevars['cpage'] != 1){
        $thispageurl->param('cpage', $pagevars['cpage']);
    }


    return array($thispageurl, $contexts, $cmid, $cm, $module, $pagevars);
}
class question_edit_contexts{
    var $allcontexts;
    /**
     * @param current context
     */
    function question_edit_contexts($thiscontext){
        $pcontextids = get_parent_contexts($thiscontext);
        $contexts = array($thiscontext);
        foreach ($pcontextids as $pcontextid){
            $contexts[] = get_context_instance_by_id($pcontextid);
        }
        $this->allcontexts = $contexts;
    }
    /**
     * @return array all parent contexts
     */
    function all(){
        return $this->allcontexts;
    }
    /**
     * @return object lowest context which must be either the module or course context
     */
    function lowest(){
        return $this->allcontexts[0];
    }
    /**
     * @param string $cap capability
     * @return array parent contexts having capability, zero based index
     */
    function having_cap($cap){
        $contextswithcap = array();
        foreach ($this->allcontexts as $context){
            if (has_capability($cap, $context)){
                $contextswithcap[] = $context;
            }
        }
        return $contextswithcap;
    }
    /**
     * @param array $caps capabilities
     * @return array parent contexts having at least one of $caps, zero based index
     */
    function having_one_cap($caps){
        $contextswithacap = array();
        foreach ($this->allcontexts as $context){
            foreach ($caps as $cap){
                if (has_capability($cap, $context)){
                    $contextswithacap[] = $context;
                    break; //done with caps loop
                }
            }
        }
        return $contextswithacap;
    }
    /**
     * @param string $tabname edit tab name
     * @return array parent contexts having at least one of $caps, zero based index
     */
    function having_one_edit_tab_cap($tabname){
        global $QUESTION_EDITTABCAPS;
        return $this->having_one_cap($QUESTION_EDITTABCAPS[$tabname]);
    }
    /**
     * Has at least one parent context got the cap $cap?
     *
     * @param string $cap capability
     * @return boolean
     */
    function have_cap($cap){
        return (count($this->having_cap($cap)));
    }

    /**
     * Has at least one parent context got one of the caps $caps?
     *
     * @param string $cap capability
     * @return boolean
     */
    function have_one_cap($caps){
        foreach ($caps as $cap){
            if ($this->have_cap($cap)){
                return true;
            }
        }
        return false;
    }
    /**
     * Has at least one parent context got one of the caps for actions on $tabname
     *
     * @param string $tabname edit tab name
     * @return boolean
     */
    function have_one_edit_tab_cap($tabname){
        global $QUESTION_EDITTABCAPS;
        return $this->have_one_cap($QUESTION_EDITTABCAPS[$tabname]);
    }
    /**
     * Throw error if at least one parent context hasn't got the cap $cap
     *
     * @param string $cap capability
     */
    function require_cap($cap){
        if (!$this->have_cap($cap)){
            print_error('nopermissions', '', '', $cap);
        }
    }
    /**
     * Throw error if at least one parent context hasn't got one of the caps $caps
     *
     * @param array $cap capabilities
     */
     function require_one_cap($caps){
        if (!$this->have_one_cap($caps)){
            $capsstring = join($caps, ', ');
            print_error('nopermissions', '', '', $capsstring);
        }
    }
    /**
     * Throw error if at least one parent context hasn't got one of the caps $caps
     *
     * @param string $tabname edit tab name
     */
     function require_one_edit_tab_cap($tabname){
        if (!$this->have_one_edit_tab_cap($tabname)){
            print_error('nopermissions', '', '', 'access question edit tab '.$tabname);
        }
    }
}

//capabilities for each page of edit tab.
//this determines which contexts' categories are available. At least one
//page is displayed if user has one of the capability on at least one context
$QUESTION_EDITTABCAPS = array(
        'editq' => array('moodle/question:add',
            'moodle/question:editmine',
            'moodle/question:editall',
            'moodle/question:viewmine',
            'moodle/question:viewall',
            'moodle/question:usemine',
            'moodle/question:useall',
            'moodle/question:movemine',
            'moodle/question:moveall'),
        'questions'=>array('moodle/question:add',
            'moodle/question:editmine',
            'moodle/question:editall',
            'moodle/question:viewmine',
            'moodle/question:viewall',
            'moodle/question:movemine',
            'moodle/question:moveall'),
        'categories'=>array('moodle/question:managecategory'),
        'import'=>array('moodle/question:add'),
        'export'=>array('moodle/question:viewall', 'moodle/question:viewmine'));

/**
 * Make sure user is logged in as required in this context.
 */
function require_login_in_context($contextorid = null){
    global $DB;
    if (!is_object($contextorid)){
        $context = get_context_instance_by_id($contextorid);
    } else {
        $context = $contextorid;
    }
    if ($context && ($context->contextlevel == CONTEXT_COURSE)) {
        require_login($context->instanceid);
    } else if ($context && ($context->contextlevel == CONTEXT_MODULE)) {
        if ($cm = $DB->get_record('course_modules',array('id' =>$context->instanceid))) {
            if (!$course = $DB->get_record('course', array('id' => $cm->course))) {
                print_error('invalidcourseid');
            }
            require_course_login($course, true, $cm);

        } else {
            print_error('invalidcoursemodule');
        }
    } else if ($context && ($context->contextlevel == CONTEXT_SYSTEM)) {
        if (!empty($CFG->forcelogin)) {
            require_login();
        }

    } else {
        require_login();
    }
}

/**
 * Print a form to let the user choose which question type to add.
 * When the form is submitted, it goes to the question.php script.
 * @param $hiddenparams hidden parameters to add to the form, in addition to
 * the qtype radio buttons.
 */
function print_choose_qtype_to_add_form($hiddenparams) {
    global $CFG, $QTYPES, $PAGE, $OUTPUT;
    $PAGE->requires->js('question/qbank.js');
    echo '<div id="chooseqtypehead" class="hd">' . "\n";
    echo $OUTPUT->heading(get_string('chooseqtypetoadd', 'question'), 3);
    echo "</div>\n";
    echo '<div id="chooseqtype">' . "\n";
    echo '<form action="' . $CFG->wwwroot . '/question/question.php" method="get"><div id="qtypeformdiv">' . "\n";
    foreach ($hiddenparams as $name => $value) {
        echo '<input type="hidden" name="' . s($name) . '" value="' . s($value) . '" />' . "\n";
    }
    echo "</div>\n";
    echo '<div class="qtypes">' . "\n";
    echo '<div class="instruction">' . get_string('selectaqtypefordescription', 'question') . "</div>\n";
    echo '<div class="realqtypes">' . "\n";
    $types = question_type_menu();
    $fakeqtypes = array();
    foreach ($types as $qtype => $localizedname) {
        if ($QTYPES[$qtype]->is_real_question_type()) {
            print_qtype_to_add_option($qtype, $localizedname);
        } else {
            $fakeqtypes[$qtype] = $localizedname;
        }
    }
    echo "</div>\n";
    echo '<div class="fakeqtypes">' . "\n";
    foreach ($fakeqtypes as $qtype => $localizedname) {
        print_qtype_to_add_option($qtype, $localizedname);
    }
    echo "</div>\n";
    echo "</div>\n";
    echo '<div class="submitbuttons">' . "\n";
    echo '<input type="submit" value="' . get_string('next') . '" id="chooseqtype_submit" />' . "\n";
    echo '<input type="submit" id="chooseqtypecancel" name="addcancel" value="' . get_string('cancel') . '" />' . "\n";
    echo "</div></form>\n";
    echo "</div>\n";
    $PAGE->requires->js_function_call('qtype_chooser.init', array('chooseqtype'));
}

/**
 * Private function used by the preceding one.
 * @param $qtype the question type.
 * @param $localizedname the localized name of this question type.
 */
function print_qtype_to_add_option($qtype, $localizedname) {
    global $QTYPES;
    echo '<div class="qtypeoption">' . "\n";
    echo '<label for="qtype_' . $qtype . '">';
    echo '<input type="radio" name="qtype" id="qtype_' . $qtype . '" value="' . $qtype . '" />';
    echo '<span class="qtypename">';
    $fakequestion = new stdClass;
    $fakequestion->qtype = $qtype;
    print_question_icon($fakequestion);
    echo $localizedname . '</span><span class="qtypesummary">' . get_string($qtype . 'summary', 'qtype_' . $qtype);
    echo "</span></label>\n";
    echo "</div>\n";
}

/**
 * Print a button for creating a new question. This will open question/addquestion.php,
 * which in turn goes to question/question.php before getting back to $params['returnurl']
 * (by default the question bank screen).
 *
 * @param integer $categoryid The id of the category that the new question should be added to.
 * @param array $params Other paramters to add to the URL. You need either $params['cmid'] or
 *      $params['courseid'], and you should probably set $params['returnurl']
 * @param string $caption the text to display on the button.
 * @param string $tooltip a tooltip to add to the button (optional).
 * @param boolean $disabled if true, the button will be disabled.
 */
function create_new_question_button($categoryid, $params, $caption, $tooltip = '', $disabled = false) {
    global $CFG, $PAGE;
    static $choiceformprinted = false;
    $params['category'] = $categoryid;
    print_single_button($CFG->wwwroot . '/question/addquestion.php', $params,
            $caption,'get', '', false, $tooltip, $disabled);
    helpbutton('types', get_string('createnewquestion', 'question'), 'question');
    $PAGE->requires->yui_lib('dragdrop');
    $PAGE->requires->yui_lib('container');
    if (!$choiceformprinted) {
        echo '<div id="qtypechoicecontainer">';
        print_choose_qtype_to_add_form(array());
        echo "</div>\n";
        $choiceformprinted = true;
    }
}

?>
