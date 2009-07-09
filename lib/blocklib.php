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
 * Block Class and Functions
 *
 * This file defines the {@link block_manager} class, 
 *
 * @package   moodlecore
 * @copyright 1999 onwards Martin Dougiamas  http://dougiamas.com
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Block Defines
 */
define('BLOCK_MOVE_LEFT',   0x01);
define('BLOCK_MOVE_RIGHT',  0x02);
define('BLOCK_MOVE_UP',     0x04);
define('BLOCK_MOVE_DOWN',   0x08);
define('BLOCK_CONFIGURE',   0x10);

define('BLOCK_POS_LEFT',  'side-pre');
define('BLOCK_POS_RIGHT', 'side-post');

define('BLOCKS_PINNED_TRUE',0);
define('BLOCKS_PINNED_FALSE',1);
define('BLOCKS_PINNED_BOTH',2);

/**
 * Exception thrown when someone tried to do something with a block that does
 * not exist on a page.
 *
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since     Moodle 2.0
 */
class block_not_on_page_exception extends moodle_exception {
    /**
     * Contructor
     * @param int $instanceid the block instance id of the block that was looked for.
     * @param object $page the current page.
     */
    public function __construct($instanceid, $page) {
        $a = new stdClass;
        $a->instanceid = $instanceid;
        $a->url = $page->url;
        parent::__construct('blockdoesnotexistonpage', '', $page->url, $a);
    }
}

/**
 * This class keeps track of the block that should appear on a moodle_page.
 *
 * The page to work with as passed to the constructor.
 *
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since     Moodle 2.0
 */
class block_manager {

/// Field declarations =========================================================

    /** @var moodle_page the moodle_page we aremanaging blocks for. */
    protected $page;

    /** @var array region name => 1.*/
    protected $regions = array();

    /** @var string the region where new blocks are added.*/
    protected $defaultregion = null;

    /** @var array will be $DB->get_records('blocks') */
    protected $allblocks = null;

    /**
     * @var array blocks that this user can add to this page. Will be a subset
     * of $allblocks. Access this via the {@link get_addable_blocks()} method
     * to ensure it is lazy-loaded.
     */
    protected $addableblocks = null;

    /**
     * Will be an array region-name => array(db rows loaded in load_blocks);
     * @var array
     */
    protected $birecordsbyregion = null;

    /**
     * array region-name => array(block objects); populated as necessary by
     * the ensure_instances_exist method.
     * @var array
     */
    protected $blockinstances = array();

    /**
     * array region-name => array(block_contents objects) what acutally needs to
     * be displayed in each region.
     * @var array
     */
    protected $visibleblockcontent = array();

    /**
     * array region-name => array(block_contents objects) extra block-like things
     * to be displayed in each region, before the real blocks.
     * @var array
     */
    protected $extracontent = array();

/// Constructor ================================================================

    /**
     * Constructor.
     * @param object $page the moodle_page object object we are managing the blocks for,
     * or a reasonable faxilimily. (See the comment at the top of this classe
     * and {@link http://en.wikipedia.org/wiki/Duck_typing})
     */
    public function __construct($page) {
        $this->page = $page;
    }

/// Getter methods =============================================================

    /**
     * Get an array of all region names on this page where a block may appear
     *
     * @return array the internal names of the regions on this page where block may appear.
     */
    public function get_regions() {
        $this->page->initialise_theme_and_output();
        return array_keys($this->regions);
    }

    /**
     * Get the region name of the region blocks are added to by default
     *
     * @return string the internal names of the region where new blocks are added
     * by default, and where any blocks from an unrecognised region are shown.
     * (Imagine that blocks were added with one theme selected, then you switched
     * to a theme with different block positions.)
     */
    public function get_default_region() {
        $this->page->initialise_theme_and_output();
        return $this->defaultregion;
    }

    /**
     * The list of block types that may be added to this page.
     *
     * @return array block id => record from block table.
     */
    public function get_addable_blocks() {
        $this->check_is_loaded();

        if (!is_null($this->addableblocks)) {
            return $this->addableblocks;
        }

        // Lazy load.
        $this->addableblocks = array();

        $allblocks = blocks_get_record();
        if (empty($allblocks)) {
            return $this->addableblocks;
        }

        $pageformat = $this->page->pagetype;
        foreach($allblocks as $block) {
            if ($block->visible &&
                    (block_method_result($block->name, 'instance_allow_multiple') || !$this->is_block_present($block->id)) &&
                    blocks_name_allowed_in_format($block->name, $pageformat)) {
                $this->addableblocks[$block->id] = $block;
            }
        }

        return $this->addableblocks;
    }

    /**
     * Find out if a block is present ? just a guess
     * @todo Write this function and document
     */
    public function is_block_present($blocktypeid) {
        // TODO
    }

    /**
     * Find out if a block type is known by the system
     *
     * @param string $blockname the name of ta type of block.
     * @param boolean $includeinvisible if false (default) only check 'visible' blocks, that is, blocks enabled by the admin.
     * @return boolean true if this block in installed.
     */
    public function is_known_block_type($blockname, $includeinvisible = false) {
        $blocks = $this->get_installed_blocks();
        foreach ($blocks as $block) {
            if ($block->name == $blockname && ($includeinvisible || $block->visible)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Find out if a region exists on a page
     *
     * @param string $region a region name
     * @return boolean true if this retion exists on this page.
     */
    public function is_known_region($region) {
        return array_key_exists($region, $this->regions);
    }

    /**
     * Get an array of all blocks within a given region
     *
     * @param string $region a block region that exists on this page.
     * @return array of block instances.
     */
    public function get_blocks_for_region($region) {
        $this->check_is_loaded();
        $this->ensure_instances_exist($region);
        return $this->blockinstances[$region];
    }

    /**
     * Returns an array of block content objects that exist in a region
     *
     * @param string $region a block region that exists on this page.
     * @return array of block block_contents objects for all the blocks in a region.
     */
    public function get_content_for_region($region, $output) {
        $this->check_is_loaded();
        $this->ensure_content_created($region, $output);
        return $this->visibleblockcontent[$region];
    }

    /**
     * Determine whether a region contains anything. (Either any real blocks, or
     * the add new block UI.)
     * @param string $region a block region that exists on this page.
     * @return boolean Whether there is anything in this region.
     */
    public function region_has_content($region) {
        if (!$this->is_known_region($region)) {
            return false;
        }
        $this->check_is_loaded();
        $this->ensure_instances_exist($region);
        if ($this->page->user_is_editing() && $this->page->user_can_edit_blocks()) {
            // If editing is on, we need all the block regions visible, for the
            // move blocks UI.
            return true;
        }
        return !empty($this->blockinstances[$region]) || !empty($this->extracontent[$region]);
    }

    /**
     * Get an array of all of the installed blocks.
     *
     * @return array contents of the block table.
     */
    public function get_installed_blocks() {
        global $DB;
        if (is_null($this->allblocks)) {
            $this->allblocks = $DB->get_records('block');
        }
        return $this->allblocks;
    }

/// Setter methods =============================================================

    /**
     * Add a region to a page
     *
     * @param string $region add a named region where blocks may appear on the
     * current page. This is an internal name, like 'side-pre', not a string to
     * display in the UI.
     */
    public function add_region($region) {
        $this->check_not_yet_loaded();
        $this->regions[$region] = 1;
    }

    /**
     * Add an array of regions
     * @see add_region()
     *
     * @param array $regions this utility method calls add_region for each array element.
     */
    public function add_regions($regions) {
        foreach ($regions as $region) {
            $this->add_region($region);
        }
    }

    /**
     * Set the default region for new blocks on the page
     *
     * @param string $defaultregion the internal names of the region where new
     * blocks should be added by default, and where any blocks from an
     * unrecognised region are shown.
     */
    public function set_default_region($defaultregion) {
        $this->check_not_yet_loaded();
        $this->check_region_is_known($defaultregion);
        $this->defaultregion = $defaultregion;
    }

    /**
     * Add something that looks like a block, but which isn't an actual block_instance,
     * to this page.
     *
     * @param block_contents $bc the content of the block like thing.
     * @param string $region a block region that exists on this page.
     */
    public function add_pretend_block($bc, $region) {
        $this->page->initialise_theme_and_output();
        $this->check_region_is_known($region);
        if (array_key_exists($region, $this->visibleblockcontent)) {
            throw new coding_exception('block_manager has already prepared the blocks in region ' .
                    $region . 'for output. It is too late to add a pretend block.');
        }
        $this->extracontent[$region][] = $bc;
    }

/// Actions ====================================================================

    /**
     * This method actually loads the blocks for our page from the database.
     *
     * @param bool|null $includeinvisible
     */
    public function load_blocks($includeinvisible = NULL) {
        global $DB, $CFG;
        if (!is_null($this->birecordsbyregion)) {
            // Already done.
            return;
        }

        if ($CFG->version < 2009050619) {
            // Upgrade/install not complete. Don't try too show any blocks.
            $this->birecordsbyregion = array();
            return;
        }

        // Ensure we have been initialised.
        if (!isset($this->defaultregion)) {
            $this->page->initialise_theme_and_output();
            // If there are still no block regions, then there are no blocks on this page.
            if (empty($this->regions)) {
                $this->birecordsbyregion = array();
                return;
            }
        }

        if (is_null($includeinvisible)) {
            $includeinvisible = $this->page->user_is_editing();
        }
        if ($includeinvisible) {
            $visiblecheck = 'AND (bp.visible = 1 OR bp.visible IS NULL)';
        } else {
            $visiblecheck = '';
        }

        $context = $this->page->context;
        $contexttest = 'bi.contextid = :contextid2';
        $parentcontextparams = array();
        $parentcontextids = get_parent_contexts($context);
        if ($parentcontextids) {
            list($parentcontexttest, $parentcontextparams) =
                    $DB->get_in_or_equal($parentcontextids, SQL_PARAMS_NAMED, 'parentcontext0000');
            $contexttest = "($contexttest OR (bi.showinsubcontexts = 1 AND bi.contextid $parentcontexttest))";
        }

        $pagetypepatterns = $this->matching_page_type_patterns($this->page->pagetype);
        list($pagetypepatterntest, $pagetypepatternparams) =
                $DB->get_in_or_equal($pagetypepatterns, SQL_PARAMS_NAMED, 'pagetypepatterntest0000');

        $params = array(
            'subpage1' => $this->page->subpage,
            'subpage2' => $this->page->subpage,
            'contextid1' => $context->id,
            'contextid2' => $context->id,
            'pagetype' => $this->page->pagetype,
        );
        $sql = "SELECT
                    bi.id,
                    bp.id AS blockpositionid,
                    bi.blockname,
                    bi.contextid,
                    bi.showinsubcontexts,
                    bi.pagetypepattern,
                    bi.subpagepattern,
                    COALESCE(bp.visible, 1) AS visible,
                    COALESCE(bp.region, bi.defaultregion) AS region,
                    COALESCE(bp.weight, bi.defaultweight) AS weight,
                    bi.configdata

                FROM {block_instances} bi
                JOIN {block} b ON bi.blockname = b.name
                LEFT JOIN {block_positions} bp ON bp.blockinstanceid = bi.id
                                                  AND bp.contextid = :contextid1
                                                  AND bp.pagetype = :pagetype
                                                  AND bp.subpage = :subpage1

                WHERE
                $contexttest
                AND bi.pagetypepattern $pagetypepatterntest
                AND (bi.subpagepattern IS NULL OR bi.subpagepattern = :subpage2)
                $visiblecheck
                AND b.visible = 1

                ORDER BY
                    COALESCE(bp.region, bi.defaultregion),
                    COALESCE(bp.weight, bi.defaultweight),
                    bi.id";
        $blockinstances = $DB->get_recordset_sql($sql, $params + $parentcontextparams + $pagetypepatternparams);

        $this->birecordsbyregion = $this->prepare_per_region_arrays();
        $unknown = array();
        foreach ($blockinstances as $bi) {
            if ($this->is_known_region($bi->region)) {
                $this->birecordsbyregion[$bi->region][] = $bi;
            } else {
                $unknown[] = $bi;
            }
        }

        // Pages don't necessarily have a defaultregion. The  one time this can
        // happen is when there are no theme block regions, but the script itself
        // has a block region in the main content area.
        if (!empty($this->defaultregion)) {
            $this->birecordsbyregion[$this->defaultregion] =
                    array_merge($this->birecordsbyregion[$this->defaultregion], $unknown);
        }
    }

    /**
     * Add a block to the current page, or related pages. The block is added to
     * context $this->page->contextid. If $pagetypepattern $subpagepattern
     *
     * @param string $blockname The type of block to add.
     * @param string $region the block region on this page to add the block to.
     * @param integer $weight determines the order where this block appears in the region.
     * @param boolean $showinsubcontexts whether this block appears in subcontexts, or just the current context.
     * @param string|null $pagetypepattern which page types this block should appear on. Defaults to just the current page type.
     * @param string|null $subpagepattern which subpage this block should appear on. NULL = any (the default), otherwise only the specified subpage.
     */
    public function add_block($blockname, $region, $weight, $showinsubcontexts, $pagetypepattern = NULL, $subpagepattern = NULL) {
        global $DB;
        $this->check_known_block_type($blockname);
        $this->check_region_is_known($region);

        if (empty($pagetypepattern)) {
            $pagetypepattern = $this->page->pagetype;
        }

        $blockinstance = new stdClass;
        $blockinstance->blockname = $blockname;
        $blockinstance->contextid = $this->page->context->id;
        $blockinstance->showinsubcontexts = !empty($showinsubcontexts);
        $blockinstance->pagetypepattern = $pagetypepattern;
        $blockinstance->subpagepattern = $subpagepattern;
        $blockinstance->defaultregion = $region;
        $blockinstance->defaultweight = $weight;
        $blockinstance->configdata = '';
        $blockinstance->id = $DB->insert_record('block_instances', $blockinstance);

        if ($this->page->context->contextlevel == CONTEXT_COURSE) {
            get_context_instance(CONTEXT_BLOCK, $blockinstance->id);
        }

        // If the new instance was created, allow it to do additional setup
        if($block = block_instance($blockname, $blockinstance)) {
            $block->instance_create();
        }
    }

    /**
     * Convenience method, calls add_block repeatedly for all the blocks in $blocks.
     *
     * @param array $blocks array with arrray keys the region names, and values an array of block names.
     * @param string $pagetypepattern optional. Passed to @see add_block()
     * @param string $subpagepattern optional. Passed to @see add_block()
     */
    public function add_blocks($blocks, $pagetypepattern = NULL, $subpagepattern = NULL) {
        $this->add_regions(array_keys($blocks));
        foreach ($blocks as $region => $regionblocks) {
            $weight = 0;
            foreach ($regionblocks as $blockname) {
                $this->add_block($blockname, $region, $weight, false, $pagetypepattern, $subpagepattern);
                $weight += 1;
            }
        }
    }

    /**
     * Find a given block by its instance id 
     *
     * @param integer $instanceid
     * @return object
     */
    public function find_instance($instanceid) {
        foreach ($this->regions as $region => $notused) {
            $this->ensure_instances_exist($region);
            foreach($this->blockinstances[$region] as $instance) {
                if ($instance->instance->id == $instanceid) {
                    return $instance;
                }
            }
        }
        throw new block_not_on_page_exception($instanceid, $this->page);
    }

/// Inner workings =============================================================

    /**
     * Given a specific page type, return all the page type patterns that might
     * match it.
     *
     * @param string $pagetype for example 'course-view-weeks' or 'mod-quiz-view'.
     * @return array an array of all the page type patterns that might match this page type.
     */
    protected function matching_page_type_patterns($pagetype) {
        $patterns = array($pagetype, '*');
        $bits = explode('-', $pagetype);
        if (count($bits) == 3 && $bits[0] == 'mod') {
            if ($bits[2] == 'view') {
                $patterns[] = 'mod-*-view';
            } else if ($bits[2] == 'index') {
                $patterns[] = 'mod-*-index';
            }
        }
        while (count($bits) > 0) {
            $patterns[] = implode('-', $bits) . '-*';
            array_pop($bits);
        }
        return $patterns;
    }

    /**
     * Check whether the page blocks have been loaded yet
     *
     * @return void Throws coding exception if already loaded
     */
    protected function check_not_yet_loaded() {
        if (!is_null($this->birecordsbyregion)) {
            throw new coding_exception('block_manager has already loaded the blocks, to it is too late to change things that might affect which blocks are visible.');
        }
    }

    /**
     * Check whether the page blocks have been loaded yet
     *
     * Nearly identical to the above function {@link check_not_yet_loaded()} except different message
     *
     * @return void Throws coding exception if already loaded
     */
    protected function check_is_loaded() {
        if (is_null($this->birecordsbyregion)) {
            throw new coding_exception('block_manager has not yet loaded the blocks, to it is too soon to request the information you asked for.');
        }
    }

    /**
     * Check if a block type is known and usable
     *
     * @param string $blockname The block type name to search for
     * @param bool $includeinvisible Include disabled block types in the intial pass
     * @return void Coding Exception thrown if unknown or not enabled
     */
    protected function check_known_block_type($blockname, $includeinvisible = false) {
        if (!$this->is_known_block_type($blockname, $includeinvisible)) {
            if ($this->is_known_block_type($blockname, true)) {
                throw new coding_exception('Unknown block type ' . $blockname);
            } else {
                throw new coding_exception('Block type ' . $blockname . ' has been disabled by the administrator.');
            }
        }
    }

    /**
     * Check if a region is known by its name
     *
     * @param string $region
     * @return void Coding Exception thrown if the region is not known
     */
    protected function check_region_is_known($region) {
        if (!$this->is_known_region($region)) {
            throw new coding_exception('Trying to reference an unknown block region ' . $region);
        }
    }

    /**
     * Returns an array of region names as keys and nested arrays for values
     *
     * @return array an array where the array keys are the region names, and the array
     * values are empty arrays.
     */
    protected function prepare_per_region_arrays() {
        $result = array();
        foreach ($this->regions as $region => $notused) {
            $result[$region] = array();
        }
        return $result;
    }

    /**
     * Create a set of new block instance from a record array
     *
     * @param array $birecords An array of block instance records
     * @return array An array of instantiated block_instance objects
     */
    protected function create_block_instances($birecords) {
        $results = array();
        foreach ($birecords as $record) {
            $results[] = block_instance($record->blockname, $record, $this->page);
        }
        return $results;
    }

    /**
     * Return an array of content vars from a set of block instances
     *
     * @param array $instances An array of block instances
     * @return array An array of content vars
     */
    protected function create_block_contents($instances, $output) {
        $results = array();
        foreach ($instances as $instance) {
            $content = $instance->get_content_for_output($output);
            if (!empty($content)) {
                $results[] = $content;
            }
        }
        return $results;
    }

    /**
     * Return a {@link block_contents} representing the add a new block UI, if
     * this user is allowed to see it.
     *
     * @return block_contents an appropriate block_contents, or null if the user
     * cannot add any blocks here.
     */
    function add_block_ui($output) {
        global $CFG;
        if (!$this->page->user_is_editing() || !$this->page->user_can_edit_blocks()) {
            return null;
        }

        $bc = new block_contents();
        $bc->title = get_string('addblock');
        $bc->add_class('block_adminblock');

        $missingblocks = array_keys($this->get_addable_blocks());
        if (empty($missingblocks)) {
            $bc->title = get_string('noblockstoaddhere');
            return $bc;
        }

        $menu = array();
        foreach ($missingblocks as $blockid) {
            $block = blocks_get_record($blockid);
            $blockobject = block_instance($block->name);
            if ($blockobject !== false && $blockobject->user_can_addto($page)) {
                $menu[$block->id] = $blockobject->get_title();
            }
        }
        asort($menu, SORT_LOCALE_STRING);

        // TODO convert to $OUTPUT.
        $returnurlparam = '&amp;returnurl=' . urlencode($this->page->url->out_returnurl());
        $actionurl = $CFG->wwwroot . '/blocks/add.php?sesskey=' . sesskey() . $returnurlparam . '&amp;blocktype=';
        $bc->content = popup_form($actionurl, $menu, 'add_block', '', get_string('adddots'), '', '', true);
        return $bc;
    }

    /**
     * Ensure block instances exist for a given region
     * 
     * @param string $region Check for bi's with the instance with this name
     */
    protected function ensure_instances_exist($region) {
        $this->check_region_is_known($region);
        if (!array_key_exists($region, $this->blockinstances)) {
            $this->blockinstances[$region] =
                    $this->create_block_instances($this->birecordsbyregion[$region]);
        }
    }

    /**
     * Ensure that there is some content within the given region
     *
     * @param string $region The name of the region to check
     */
    protected function ensure_content_created($region, $output) {
        $this->ensure_instances_exist($region);
        if (!array_key_exists($region, $this->visibleblockcontent)) {
            $contents = array();
            if (array_key_exists($region, $this->extracontent)) {
                $contents = $this->extracontent[$region];
            }
            $contents = array_merge($contents, $this->create_block_contents($this->blockinstances[$region], $output));
            if ($region == $this->defaultregion) {
                $addblockui = $this->add_block_ui($output);
                if ($addblockui) {
                    $contents[] = $addblockui;
                }
            }
            $this->visibleblockcontent[$region] = $contents;
        }
    }
}

/// Helper functions for working with block classes ============================

/**
 * Call a class method (one that does not requrie a block instance) on a block class.
 *
 * @param string $blockname the name of the block.
 * @param string $method the method name.
 * @param array $param parameters to pass to the method.
 * @return mixed whatever the method returns.
 */
function block_method_result($blockname, $method, $param = NULL) {
    if(!block_load_class($blockname)) {
        return NULL;
    }
    return call_user_func(array('block_'.$blockname, $method), $param);
}

/**
 * Creates a new object of the specified block class.
 *
 * @param string $blockname the name of the block.
 * @param $instance block_instances DB table row (optional).
 * @param moodle_page $page the page this block is appearing on.
 * @return block_base the requested block instance.
 */
function block_instance($blockname, $instance = NULL, $page = NULL) {
    if(!block_load_class($blockname)) {
        return false;
    }
    $classname = 'block_'.$blockname;
    $retval = new $classname;
    if($instance !== NULL) {
        if (is_null($page)) {
            global $PAGE;
            $page = $PAGE;
        }
        $retval->_load_instance($instance, $page);
    }
    return $retval;
}

/**
 * Load the block class for a particular type of block.
 *
 * @param string $blockname the name of the block.
 * @return boolean success or failure.
 */
function block_load_class($blockname) {
    global $CFG;

    if(empty($blockname)) {
        return false;
    }

    $classname = 'block_'.$blockname;

    if(class_exists($classname)) {
        return true;
    }

    require_once($CFG->dirroot.'/blocks/moodleblock.class.php');
    @include_once($CFG->dirroot.'/blocks/'.$blockname.'/block_'.$blockname.'.php'); // do not throw errors if block code not present

    return class_exists($classname);
}

/// Functions that have been deprecated by block_manager =======================

/**
 * @deprecated since Moodle 2.0 - use $page->blocks->get
 *
 * This function returns an array with the IDs of any blocks that you can add to your page.
 * Parameters are passed by reference for speed; they are not modified at all.
 *
 * @param $page the page object.
 * @param $blockmanager Not used.
 * @return array of block type ids.
 */
function blocks_get_missing(&$page, &$blockmanager) {
    return array_keys($page->blocks->get_addable_blocks());
}

/**
 * Actually delete from the database any blocks that are currently on this page,
 * but which should not be there according to blocks_name_allowed_in_format.
 *
 * @todo Write/Fix this function. Currently returns immediatly
 * @param $course
 */
function blocks_remove_inappropriate($course) {
    // TODO
    return;
    $blockmanager = blocks_get_by_page($page);

    if(empty($blockmanager)) {
        return;
    }

    if(($pageformat = $page->pagetype) == NULL) {
        return;
    }

    foreach($blockmanager as $region) {
        foreach($region as $instance) {
            $block = blocks_get_record($instance->blockid);
            if(!blocks_name_allowed_in_format($block->name, $pageformat)) {
               blocks_delete_instance($instance);
            }
        }
    }
}

/**
 * Check that a given name is in a permittable format
 *
 * @param string $name
 * @param string $pageformat
 * @return bool
 */
function blocks_name_allowed_in_format($name, $pageformat) {
    $accept = NULL;
    $maxdepth = -1;
    $formats = block_method_result($name, 'applicable_formats');
    if (!$formats) {
        $formats = array();
    }
    foreach ($formats as $format => $allowed) {
        $formatregex = '/^'.str_replace('*', '[^-]*', $format).'.*$/';
        $depth = substr_count($format, '-');
        if (preg_match($formatregex, $pageformat) && $depth > $maxdepth) {
            $maxdepth = $depth;
            $accept = $allowed;
        }
    }
    if ($accept === NULL) {
        $accept = !empty($formats['all']);
    }
    return $accept;
}

/**
 * Delete a block, and associated data.
 *
 * @param object $instance a row from the block_instances table
 * @param bool $nolongerused legacy parameter. Not used, but kept for bacwards compatibility.
 * @param bool $skipblockstables for internal use only. Makes @see blocks_delete_all_for_context() more efficient.
 */
function blocks_delete_instance($instance, $nolongerused = false, $skipblockstables = false) {
    global $DB;

    if ($block = block_instance($instance->blockname, $instance)) {
        $block->instance_delete();
    }
    delete_context(CONTEXT_BLOCK, $instance->id);

    if (!$skipblockstables) {
        $DB->delete_records('block_positions', array('blockinstanceid' => $instance->id));
        $DB->delete_records('block_instances', array('id' => $instance->id));
    }
}

/**
 * Delete all the blocks that belong to a particular context.
 *
 * @param int $contextid the context id.
 */
function blocks_delete_all_for_context($contextid) {
    global $DB;
    $instances = $DB->get_recordset('block_instances', array('contextid' => $contextid));
    foreach ($instances as $instance) {
        blocks_delete_instance($instance, true);
    }
    $instances->close();
    $DB->delete_records('block_instances', array('contextid' => $contextid));
    $DB->delete_records('block_positions', array('contextid' => $contextid));
}

/**
 * @deprecated since 2.0
 * Delete all the blocks from a particular page.
 *
 * @param string $pagetype the page type.
 * @param integer $pageid the page id.
 * @return bool success or failure.
 */
function blocks_delete_all_on_page($pagetype, $pageid) {
    global $DB;

    debugging('Call to deprecated function blocks_delete_all_on_page. ' .
            'This function cannot work any more. Doing nothing. ' .
            'Please update your code to use a block_manager method $PAGE->blocks->....', DEBUG_DEVELOPER);
    return false;
}

/**
 * Dispite what this function is called, it seems to be mostly used to populate
 * the default blocks when a new course (or whatever) is created.
 *
 * @deprecated since 2.0
 *
 * @param object $page the page to add default blocks to.
 * @return boolean success or failure.
 */
function blocks_repopulate_page($page) {
    global $CFG;

    debugging('Call to deprecated function blocks_repopulate_page. ' .
            'Use a more specific method like blocks_add_default_course_blocks, ' .
            'or just call $PAGE->blocks->add_blocks()', DEBUG_DEVELOPER);

    /// If the site override has been defined, it is the only valid one.
    if (!empty($CFG->defaultblocks_override)) {
        $blocknames = $CFG->defaultblocks_override;
    } else {
        $blocknames = $page->blocks_get_default();
    }

    $blocks = blocks_parse_default_blocks_list($blocknames);
    $page->blocks->add_blocks($blocks);

    return true;
}

/**
 * Get the block record for a particular blockid - that is, a particul type os block.
 *
 * @param $int blockid block type id. If null, an array of all block types is returned.
 * @param bool $notusedanymore No longer used.
 * @return array|object row from block table, or all rows.
 */
function blocks_get_record($blockid = NULL, $notusedanymore = false) {
    global $PAGE;
    $blocks = $PAGE->blocks->get_installed_blocks();
    if ($blockid === NULL) {
        return $blocks;
    } else if (isset($blocks[$blockid])) {
        return $blocks[$blockid];
    } else {
        return false;
    }
}

/**
 * Find a given block by its blockid within a provide array
 *
 * @param int $blockid
 * @param array $blocksarray
 * @return bool|object Instance if found else false
 */
function blocks_find_block($blockid, $blocksarray) {
    if (empty($blocksarray)) {
        return false;
    }
    foreach($blocksarray as $blockgroup) {
        if (empty($blockgroup)) {
            continue;
        }
        foreach($blockgroup as $instance) {
            if($instance->blockid == $blockid) {
                return $instance;
            }
        }
    }
    return false;
}

/**
 * TODO Document this function, description
 *
 * @param object $page The page object
 * @param object $blockmanager The block manager object
 * @param string $blockaction One of [config, add, delete]
 * @param int|object $instanceorid The instance id or a block_instance object
 * @param bool $pinned
 * @param bool $redirect To redirect or not to that is the question but you should stick with true
 */
function blocks_execute_action($page, &$blockmanager, $blockaction, $instanceorid, $pinned=false, $redirect=true) {
    global $CFG, $USER, $DB;

    if (!in_array($blockaction, array('config', 'add', 'delete'))) {
        throw new moodle_exception('Sorry, blocks editing is currently broken. Will be fixed. See MDL-19010.');
    }

    if (is_int($instanceorid)) {
        $blockid = $instanceorid;
    } else if (is_object($instanceorid)) {
        $instance = $instanceorid;
    }

    switch($blockaction) {
        case 'config':
            // First of all check to see if the block wants to be edited
            if(!$instance->user_can_edit()) {
                break;
            }

            // Now get the title and AFTER that load up the instance
            $blocktitle = $instance->get_title();

            // Define the data we're going to silently include in the instance config form here,
            // so we can strip them from the submitted data BEFORE serializing it.
            $hiddendata = array(
                'sesskey' => sesskey(),
                'instanceid' => $instance->instance->id,
                'blockaction' => 'config'
            );

            // To this data, add anything the page itself needs to display
            $hiddendata = $page->url->params($hiddendata);

            if ($data = data_submitted()) {
                $remove = array_keys($hiddendata);
                foreach($remove as $item) {
                    unset($data->$item);
                }
                $instance->instance_config_save($data);
                redirect($page->url->out());

            } else {
                // We need to show the config screen, so we highjack the display logic and then die
                $strheading = get_string('blockconfiga', 'moodle', $blocktitle);
                $nav = build_navigation($strheading, $page->cm);
                print_header($strheading, $strheading, $nav);

                echo '<div class="block-config" id="'.$instance->name().'">';   /// Make CSS easier

                print_heading($strheading);
                echo '<form method="post" name="block-config" action="'. $page->url->out(false) .'">';
                echo '<p>';
                echo $page->url->hidden_params_out(array(), 0, $hiddendata);
                echo '</p>';
                $instance->instance_config_print();
                echo '</form>';

                echo '</div>';
                global $PAGE;
                $PAGE->set_docs_path('blocks/' . $instance->name());
                print_footer();
                die; // Do not go on with the other page-related stuff
            }
        break;
        case 'toggle':
            if(empty($instance))  {
                print_error('invalidblockinstance', '', '', $blockaction);
            }
            $instance->visible = ($instance->visible) ? 0 : 1;
            if (!empty($pinned)) {
                $DB->update_record('block_pinned_old', $instance);
            } else {
                $DB->update_record('block_instance_old', $instance);
            }
        break;
        case 'delete':
            if(empty($instance))  {
                print_error('invalidblockinstance', '', '', $blockaction);
            }
            blocks_delete_instance($instance->instance, $pinned);
        break;
        case 'moveup':
            if(empty($instance))  {
                print_error('invalidblockinstance', '', '', $blockaction);
            }

            if($instance->weight == 0) {
                // The block is the first one, so a move "up" probably means it changes position
                // Where is the instance going to be moved?
                $newpos = $page->blocks_move_position($instance, BLOCK_MOVE_UP);
                $newweight = (empty($blockmanager[$newpos]) ? 0 : max(array_keys($blockmanager[$newpos])) + 1);

                blocks_execute_repositioning($instance, $newpos, $newweight, $pinned);
            }
            else {
                // The block is just moving upwards in the same position.
                // This configuration will make sure that even if somehow the weights
                // become not continuous, block move operations will eventually bring
                // the situation back to normal without printing any warnings.
                if(!empty($blockmanager[$instance->position][$instance->weight - 1])) {
                    $other = $blockmanager[$instance->position][$instance->weight - 1];
                }
                if(!empty($other)) {
                    ++$other->weight;
                    if (!empty($pinned)) {
                        $DB->update_record('block_pinned_old', $other);
                    } else {
                        $DB->update_record('block_instance_old', $other);
                    }
                }
                --$instance->weight;
                if (!empty($pinned)) {
                    $DB->update_record('block_pinned_old', $instance);
                } else {
                    $DB->update_record('block_instance_old', $instance);
                }
            }
        break;
        case 'movedown':
            if(empty($instance))  {
                print_error('invalidblockinstance', '', '', $blockaction);
            }

            if($instance->weight == max(array_keys($blockmanager[$instance->position]))) {
                // The block is the last one, so a move "down" probably means it changes position
                // Where is the instance going to be moved?
                $newpos = $page->blocks_move_position($instance, BLOCK_MOVE_DOWN);
                $newweight = (empty($blockmanager[$newpos]) ? 0 : max(array_keys($blockmanager[$newpos])) + 1);

                blocks_execute_repositioning($instance, $newpos, $newweight, $pinned);
            }
            else {
                // The block is just moving downwards in the same position.
                // This configuration will make sure that even if somehow the weights
                // become not continuous, block move operations will eventually bring
                // the situation back to normal without printing any warnings.
                if(!empty($blockmanager[$instance->position][$instance->weight + 1])) {
                    $other = $blockmanager[$instance->position][$instance->weight + 1];
                }
                if(!empty($other)) {
                    --$other->weight;
                    if (!empty($pinned)) {
                        $DB->update_record('block_pinned_old', $other);
                    } else {
                        $DB->update_record('block_instance_old', $other);
                    }
                }
                ++$instance->weight;
                if (!empty($pinned)) {
                    $DB->update_record('block_pinned_old', $instance);
                } else {
                    $DB->update_record('block_instance_old', $instance);
                }
            }
        break;
        case 'moveleft':
            if(empty($instance))  {
                print_error('invalidblockinstance', '', '', $blockaction);
            }

            // Where is the instance going to be moved?
            $newpos = $page->blocks_move_position($instance, BLOCK_MOVE_LEFT);
            $newweight = (empty($blockmanager[$newpos]) ? 0 : max(array_keys($blockmanager[$newpos])) + 1);

            blocks_execute_repositioning($instance, $newpos, $newweight, $pinned);
        break;
        case 'moveright':
            if(empty($instance))  {
                print_error('invalidblockinstance', '', '', $blockaction);
            }

            // Where is the instance going to be moved?
            $newpos    = $page->blocks_move_position($instance, BLOCK_MOVE_RIGHT);
            $newweight = (empty($blockmanager[$newpos]) ? 0 : max(array_keys($blockmanager[$newpos])) + 1);

            blocks_execute_repositioning($instance, $newpos, $newweight, $pinned);
        break;
        case 'add':
            // Add a new instance of this block, if allowed
            $block = blocks_get_record($blockid);

            if (empty($block) || !$block->visible) {
                // Only allow adding if the block exists and is enabled
                break;
            }

            if (!$block->multiple && blocks_find_block($blockid, $blockmanager) !== false) {
                // If no multiples are allowed and we already have one, return now
                break;
            }

            if (!block_method_result($block->name, 'user_can_addto', $page)) {
                // If the block doesn't want to be added...
                break;
            }

            $region = $page->blocks->get_default_region();
            $weight = $DB->get_field_sql("SELECT MAX(defaultweight) FROM {block_instances} 
                    WHERE contextid = ? AND defaultregion = ?", array($page->context->id, $region));
            $pagetypepattern = $page->pagetype;
            if (strpos($pagetypepattern, 'course-view') === 0) {
                $pagetypepattern = 'course-view-*';
            }
            $page->blocks->add_block($block->name, $region, $weight, false, $pagetypepattern);
        break;
    }

    if ($redirect) {
        // In order to prevent accidental duplicate actions, redirect to a page with a clean url
        redirect($page->url->out());
    }
}

/**
 * TODO deprecate
 *
 * You can use this to get the blocks to respond to URL actions without much hassle
 *
 * @param object $PAGE
 * @param object $blockmanager
 * @param bool $pinned
 */
function blocks_execute_url_action(&$PAGE, &$blockmanager,$pinned=false) {
    $blockaction = optional_param('blockaction', '', PARAM_ALPHA);

    if (empty($blockaction) || !$PAGE->user_allowed_editing() || !confirm_sesskey()) {
        return;
    }

    $instanceid  = optional_param('instanceid', 0, PARAM_INT);
    $blockid     = optional_param('blockid',    0, PARAM_INT);

    if (!empty($blockid)) {
        blocks_execute_action($PAGE, $blockmanager, strtolower($blockaction), $blockid, $pinned);
    } else if (!empty($instanceid)) {
        $instance = $blockmanager->find_instance($instanceid);
        blocks_execute_action($PAGE, $blockmanager, strtolower($blockaction), $instance, $pinned);
    }
}

/**
 * TODO deprecate
 * This shouldn't be used externally at all, it's here for use by blocks_execute_action()
 * in order to reduce code repetition.
 *
 * @todo Remove exception when MDL-19010 is fixed
 *
 * global object
 * @param $instance
 * @param $newpos
 * @param string|int $newweight
 * @param bool $pinned
 */
function blocks_execute_repositioning(&$instance, $newpos, $newweight, $pinned=false) {
    global $DB;

    throw new moodle_exception('Sorry, blocks editing is currently broken. Will be fixed. See MDL-19010.');

    // If it's staying where it is, don't do anything, unless overridden
    if ($newpos == $instance->position) {
        return;
    }

    // Close the weight gap we 'll leave behind
    if (!empty($pinned)) {
        $sql = "UPDATE {block_instance_old}
                   SET weight = weight - 1
                 WHERE pagetype = ? AND position = ? AND weight > ?";
        $params = array($instance->pagetype, $instance->position, $instance->weight);

    } else {
        $sql = "UPDATE {block_instance_old}
                   SET weight = weight - 1
                 WHERE pagetype = ? AND pageid = ?
                       AND position = ? AND weight > ?";
        $params = array($instance->pagetype, $instance->pageid, $instance->position, $instance->weight);
    }
    $DB->execute($sql, $params);

    $instance->position = $newpos;
    $instance->weight   = $newweight;

    if (!empty($pinned)) {
        $DB->update_record('block_pinned_old', $instance);
    } else {
        $DB->update_record('block_instance_old', $instance);
    }
}


/**
 * TODO deprecate
 * Moves a block to the new position (column) and weight (sort order).
 *
 * @param object $instance The block instance to be moved.
 * @param string $destpos BLOCK_POS_LEFT or BLOCK_POS_RIGHT. The destination column.
 * @param string $destweight The destination sort order. If NULL, we add to the end
 *                    of the destination column.
 * @param bool $pinned Are we moving pinned blocks? We can only move pinned blocks
 *                to a new position withing the pinned list. Likewise, we
 *                can only moved non-pinned blocks to a new position within
 *                the non-pinned list.
 * @return boolean success or failure
 */
function blocks_move_block($page, &$instance, $destpos, $destweight=NULL, $pinned=false) {
    global $CFG, $DB;

    throw new moodle_exception('Sorry, blocks editing is currently broken. Will be fixed. See MDL-19010.');

    if ($pinned) {
        $blocklist = array(); //blocks_get_pinned($page);
    } else {
        $blocklist = array(); //blocks_get_by_page($page);
    }

    if ($blocklist[$instance->position][$instance->weight]->id != $instance->id) {
        // The source block instance is not where we think it is.
        return false;
    }

    // First we close the gap that will be left behind when we take out the
    // block from it's current column.
    if ($pinned) {
        $closegapsql = "UPDATE {block_instance_old}
                           SET weight = weight - 1
                         WHERE weight > ? AND position = ? AND pagetype = ?";
        $params = array($instance->weight, $instance->position, $instance->pagetype);
    } else {
        $closegapsql = "UPDATE {block_instance_old}
                           SET weight = weight - 1
                         WHERE weight > ? AND position = ?
                               AND pagetype = ? AND pageid = ?";
        $params = array($instance->weight, $instance->position, $instance->pagetype, $instance->pageid);
    }
    if (!$DB->execute($closegapsql, $params)) {
        return false;
    }

    // Now let's make space for the block being moved.
    if ($pinned) {
        $opengapsql = "UPDATE {block_instance_old}
                           SET weight = weight + 1
                         WHERE weight >= ? AND position = ? AND pagetype = ?";
        $params = array($destweight, $destpos, $instance->pagetype);
    } else {
        $opengapsql = "UPDATE {block_instance_old}
                          SET weight = weight + 1
                        WHERE weight >= ? AND position = ?
                              AND pagetype = ? AND pageid = ?";
        $params = array($destweight, $destpos, $instance->pagetype, $instance->pageid);
    }
    if (!$DB->execute($opengapsql, $params)) {
        return false;
    }

    // Move the block.
    $instance->position = $destpos;
    $instance->weight   = $destweight;

    if ($pinned) {
        $table = 'block_pinned_old';
    } else {
        $table = 'block_instance_old';
    }
    return $DB->update_record($table, $instance);
}

// Functions for programatically adding default blocks to pages ================

/**
 * Parse a list of default blocks. See config-dist for a description of the format.
 *
 * @param string $blocksstr
 * @return array
 */
function blocks_parse_default_blocks_list($blocksstr) {
    $blocks = array();
    $bits = explode(':', $blocksstr);
    if (!empty($bits)) {
        $blocks[BLOCK_POS_LEFT] = explode(',', array_shift($bits));
    }
    if (!empty($bits)) {
        $blocks[BLOCK_POS_RIGHT] = explode(',', array_shift($bits));
    }
    return $blocks;
}

/**
 * @return array the blocks that should be added to the site course by default.
 */
function blocks_get_default_site_course_blocks() {
    global $CFG;

    if (!empty($CFG->defaultblocks_site)) {
        return blocks_parse_default_blocks_list($CFG->defaultblocks_site);
    } else {
        return array(
            BLOCK_POS_LEFT => array('site_main_menu', 'admin_tree'),
            BLOCK_POS_RIGHT => array('course_summary', 'calendar_month')
        );
    }
}

/**
 * Add the default blocks to a course.
 *
 * @param object $course a course object.
 */
function blocks_add_default_course_blocks($course) {
    global $CFG;

    if (!empty($CFG->defaultblocks_override)) {
        $blocknames = blocks_parse_default_blocks_list($CFG->defaultblocks_override);

    } else if ($course->id == SITEID) {
        $blocknames = blocks_get_default_site_course_blocks();

    } else {
        $defaultblocks = 'defaultblocks_' . $course->format;
        if (!empty($CFG->$defaultblocks)) {
            $blocknames = blocks_parse_default_blocks_list($CFG->$defaultblocks);

        } else {
            $formatconfig = $CFG->dirroot.'/course/format/'.$course->format.'/config.php';
            if (is_readable($formatconfig)) {
                require($formatconfig);
            }
            if (!empty($format['defaultblocks'])) {
                $blocknames = blocks_parse_default_blocks_list($format['defaultblocks']);

            } else if (!empty($CFG->defaultblocks)){
                $blocknames = blocks_parse_default_blocks_list($CFG->defaultblocks);

            } else {
                $blocknames = array(
                    BLOCK_POS_LEFT => array('participants', 'activity_modules', 'search_forums', 'admin', 'course_list'),
                    BLOCK_POS_RIGHT => array('news_items', 'calendar_upcoming', 'recent_activity')
                );
            }
        }
    }

    if ($course->id == SITEID) {
        $pagetypepattern = 'site-index';
    } else {
        $pagetypepattern = 'course-view-*';
    }

    $page = new moodle_page();
    $page->set_course($course);
    $page->blocks->add_blocks($blocknames, $pagetypepattern);
}

/**
 * Add the default system-context blocks. E.g. the admin tree.
 */
function blocks_add_default_system_blocks() {
    $page = new moodle_page();
    $page->set_context(get_context_instance(CONTEXT_SYSTEM));
    $page->blocks->add_blocks(array(BLOCK_POS_LEFT => array('admin_tree', 'admin_bookmarks')), 'admin-*');
}
