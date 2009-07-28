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

/**#@+
 * @deprecated since Moodle 2.0. No longer used.
 */
define('BLOCK_MOVE_LEFT',   0x01);
define('BLOCK_MOVE_RIGHT',  0x02);
define('BLOCK_MOVE_UP',     0x04);
define('BLOCK_MOVE_DOWN',   0x08);
define('BLOCK_CONFIGURE',   0x10);
/**#@-*/

/**#@+
 * Default names for the block regions in the standard theme.
 */
define('BLOCK_POS_LEFT',  'side-pre');
define('BLOCK_POS_RIGHT', 'side-post');
/**#@-*/

/**#@+
 * @deprecated since Moodle 2.0. No longer used.
 */
define('BLOCKS_PINNED_TRUE',0);
define('BLOCKS_PINNED_FALSE',1);
define('BLOCKS_PINNED_BOTH',2);
/**#@-*/

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
        $a->url = $page->url->out();
        parent::__construct('blockdoesnotexistonpage', '', $page->url->out(), $a);
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
     * of $allblocks, but with array keys block->name. Access this via the
     * {@link get_addable_blocks()} method to ensure it is lazy-loaded.
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
     * @return array block name => record from block table.
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
                    blocks_name_allowed_in_format($block->name, $pageformat) &&
                    block_method_result($block->name, 'user_can_addto', $this->page)) {
                $this->addableblocks[$block->name] = $block;
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
     *
     * (You may wonder why the $output parameter is required. Unfortunately,
     * becuase of the way that blocks work, the only reliable way to find out
     * if a block will be visible is to get the content for output, and to
     * get the content, you need a renderer. Fortunately, this is not a
     * performance problem, becuase we cache the output that is generated, and
     * in almost every case where we call region_has_content, we are about to
     * output the blocks anyway, so we are not doing wasted effort.)
     *
     * @param string $region a block region that exists on this page.
     * @param object $output a moodle_core_renderer. normally the global $OUTPUT.
     * @return boolean Whether there is anything in this region.
     */
    public function region_has_content($region, $output) {
        if (!$this->is_known_region($region)) {
            return false;
        }
        $this->check_is_loaded();
        $this->ensure_content_created($region, $output);
        if ($this->page->user_is_editing() && $this->page->user_can_edit_blocks()) {
            // If editing is on, we need all the block regions visible, for the
            // move blocks UI.
            return true;
        }
        return !empty($this->visibleblockcontent[$region]) || !empty($this->extracontent[$region]);
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
     * @param boolean|null $includeinvisible
     *      null (default) - load hidden blocks if $this->page->user_is_editing();
     *      true - load hidden blocks.
     *      false - don't load hidden blocks.
     */
    public function load_blocks($includeinvisible = null) {
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

        // The code here needs to be consistent with the code in block_load_for_page.
        if (is_null($includeinvisible)) {
            $includeinvisible = $this->page->user_is_editing();
        }
        if ($includeinvisible) {
            $visiblecheck = '';
        } else {
            $visiblecheck = 'AND (bp.visible = 1 OR bp.visible IS NULL)';
        }

        $context = $this->page->context;
        $contexttest = 'bi.parentcontextid = :contextid2';
        $parentcontextparams = array();
        $parentcontextids = get_parent_contexts($context);
        if ($parentcontextids) {
            list($parentcontexttest, $parentcontextparams) =
                    $DB->get_in_or_equal($parentcontextids, SQL_PARAMS_NAMED, 'parentcontext0000');
            $contexttest = "($contexttest OR (bi.showinsubcontexts = 1 AND bi.parentcontextid $parentcontexttest))";
        }

        $pagetypepatterns = matching_page_type_patterns($this->page->pagetype);
        list($pagetypepatterntest, $pagetypepatternparams) =
                $DB->get_in_or_equal($pagetypepatterns, SQL_PARAMS_NAMED, 'pagetypepatterntest0000');

        $params = array(
            'subpage1' => $this->page->subpage,
            'subpage2' => $this->page->subpage,
            'contextid1' => $context->id,
            'contextid2' => $context->id,
            'pagetype' => $this->page->pagetype,
            'contextblock' => CONTEXT_BLOCK,
        );
        $sql = "SELECT
                    bi.id,
                    bp.id AS blockpositionid,
                    bi.blockname,
                    bi.parentcontextid,
                    bi.showinsubcontexts,
                    bi.pagetypepattern,
                    bi.subpagepattern,
                    bi.defaultregion,
                    bi.defaultweight,
                    COALESCE(bp.visible, 1) AS visible,
                    COALESCE(bp.region, bi.defaultregion) AS region,
                    COALESCE(bp.weight, bi.defaultweight) AS weight,
                    bi.configdata,
                    ctx.id AS ctxid,
                    ctx.path AS ctxpath,
                    ctx.depth AS ctxdepth,
                    ctx.contextlevel AS ctxlevel

                FROM {block_instances} bi
                JOIN {block} b ON bi.blockname = b.name
                LEFT JOIN {block_positions} bp ON bp.blockinstanceid = bi.id
                                                  AND bp.contextid = :contextid1
                                                  AND bp.pagetype = :pagetype
                                                  AND bp.subpage = :subpage1
                JOIN {context} ctx ON ctx.contextlevel = :contextblock
                                      AND ctx.instanceid = bi.id

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
            $bi = make_context_subobj($bi);
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
        $blockinstance->parentcontextid = $this->page->context->id;
        $blockinstance->showinsubcontexts = !empty($showinsubcontexts);
        $blockinstance->pagetypepattern = $pagetypepattern;
        $blockinstance->subpagepattern = $subpagepattern;
        $blockinstance->defaultregion = $region;
        $blockinstance->defaultweight = $weight;
        $blockinstance->configdata = '';
        $blockinstance->id = $DB->insert_record('block_instances', $blockinstance);

        // Ensure the block context is created.
        get_context_instance(CONTEXT_BLOCK, $blockinstance->id);

        // If the new instance was created, allow it to do additional setup
        if ($block = block_instance($blockname, $blockinstance)) {
            $block->instance_create();
        }
    }

    public function add_block_at_end_of_default_region($blockname) {
        $defaulregion = $this->get_default_region();

        $lastcurrentblock = end($this->birecordsbyregion[$defaulregion]);
        if ($lastcurrentblock) {
            $weight = $lastcurrentblock->weight + 1;
        } else {
            $weight = 0;
        }

        if ($this->page->subpage) {
            $subpage = $this->page->subpage;
        } else {
            $subpage = null;
        }

        // Special case. Course view page type include the course format, but we
        // want to add the block non-format-specifically.
        $pagetypepattern = $this->page->pagetype;
        if (strpos($pagetypepattern, 'course-view') === 0) {
            $pagetypepattern = 'course-view-*';
        }

        $this->add_block($blockname, $defaulregion, $weight, false, $pagetypepattern, $subpage);
    }

    /**
     * Convenience method, calls add_block repeatedly for all the blocks in $blocks.
     *
     * @param array $blocks array with array keys the region names, and values an array of block names.
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
     * Create all the bock instances for all the blocks that were loaded by
     * load_blocks. This is used, for example, to ensure that all blocks get a
     * chance to initialise themselves via the {@link block_base::specialize()}
     * method, before any output is done.
     */
    public function create_all_block_instances() {
        foreach ($this->get_regions() as $region) {
            $this->ensure_instances_exist($region);
        }
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
                $addblockui = block_add_block_ui($this->page, $output);
                if ($addblockui) {
                    $contents[] = $addblockui;
                }
            }
            $this->visibleblockcontent[$region] = $contents;
        }
    }

/// Process actions from the URL ===============================================

    /**
     * Process any block actions that were specified in the URL.
     *
     * This can only be done given a valid $page object.
     *
     * @param moodle_page $page the page to add blocks to.
     * @return boolean true if anything was done. False if not.
     */
    public function process_url_actions() {
        return $this->process_url_add() || $this->process_url_delete() ||
            $this->process_url_show_hide() || $this->process_url_edit();
    }

    /**
     * Handle adding a block.
     * @return boolean true if anything was done. False if not.
     */
    public function process_url_add() {
        $blocktype = optional_param('bui_addblock', null, PARAM_SAFEDIR);
        if (!$blocktype) {
            return false;
        }

        confirm_sesskey();

        if (!$page->user_is_editing() && !$this->page->user_can_edit_blocks()) {
            throw new moodle_exception('nopermissions', '', $this->page->url->out(), get_string('addblock'));
        }

        if (!array_key_exists($blocktype, $this->get_addable_blocks())) {
            throw new moodle_exception('cannotaddthisblocktype', '', $this->page->url->out(), $blocktype);
        }

        $this->add_block_at_end_of_default_region($blocktype);

        // If the page URL was a guses, it will contain the bui_... param, so we must make sure it is not there.
        $this->page->ensure_param_not_in_url('bui_addblock');

        return true;
    }

    /**
     * Handle deleting a block.
     * @return boolean true if anything was done. False if not.
     */
    public function process_url_delete() {
        $blockid = optional_param('bui_deleteid', null, PARAM_INTEGER);
        if (!$blockid) {
            return false;
        }

        confirm_sesskey();

        $block = $this->page->blocks->find_instance($blockid);

        if (!$block->user_can_edit() || !$this->page->user_can_edit_blocks() || !$block->user_can_addto($page)) {
            throw new moodle_exception('nopermissions', '', $this->page->url->out(), get_string('deleteablock'));
        }

        blocks_delete_instance($block->instance);

        // If the page URL was a guses, it will contain the bui_... param, so we must make sure it is not there.
        $this->page->ensure_param_not_in_url('bui_deleteid');

        return true;
    }

    /**
     * Handle showing or hiding a block.
     * @return boolean true if anything was done. False if not.
     */
    public function process_url_show_hide() {
        if ($blockid = optional_param('bui_hideid', null, PARAM_INTEGER)) {
            $newvisibility = 0;
        } else if ($blockid = optional_param('bui_showid', null, PARAM_INTEGER)) {
            $newvisibility = 1;
        } else {
            return false;
        }

        confirm_sesskey();

        $block = $this->page->blocks->find_instance($blockid);

        if (!$block->user_can_edit() || !$this->page->user_can_edit_blocks()) {
            throw new moodle_exception('nopermissions', '', $this->page->url->out(), get_string('hideshowblocks'));
        }

        blocks_set_visibility($block->instance, $this->page, $newvisibility);

        // If the page URL was a guses, it will contain the bui_... param, so we must make sure it is not there.
        $this->page->ensure_param_not_in_url('bui_hideid');
        $this->page->ensure_param_not_in_url('bui_showid');

        return true;
    }

    /**
     * Handle showing/processing the submission from the block editing form.
     * @return boolean true if the form was submitted and the new config saved. Does not
     *      return if the editing form was displayed. False otherwise.
     */
    public function process_url_edit() {
        global $CFG, $DB, $PAGE;

        $blockid = optional_param('bui_editid', null, PARAM_INTEGER);
        if (!$blockid) {
            return false;
        }

        confirm_sesskey();
        require_once($CFG->dirroot . '/blocks/edit_form.php');

        $block = $this->find_instance($blockid);

        if (!$block->user_can_edit() || !$this->page->user_can_edit_blocks()) {
            throw new moodle_exception('nopermissions', '', $this->page->url->out(), get_string('editblock'));
        }

        $editpage = new moodle_page();
        $editpage->set_generaltype('form');
        $editpage->set_course($this->page->course);
        $editpage->set_context($this->page->context);
        $editurlbase = str_replace($CFG->wwwroot . '/', '', $this->page->url->out(true));
        $editurlparams = $this->page->url->params();
        $editurlparams['bui_editid'] = $blockid;
        $editpage->set_url($editurlbase, $editurlparams);
        $editpage->_block_actions_done = true;
        // At this point we are either going to redirect, or display the form, so
        // overwrite global $PAGE ready for this. (Formslib refers to it.)
        $PAGE = $editpage;

        $formfile = $CFG->dirroot . '/blocks/' . $block->name() . '/edit_form.php';
        if (is_readable($formfile)) {
            require_once($formfile);
            $classname = 'block_' . $block->name() . '_edit_form';
        } else {
            $classname = 'block_edit_form';
        }

        $mform = new $classname($editpage->url, $block, $this->page);
        $mform->set_data($block->instance);

        if ($mform->is_cancelled()) {
            redirect($this->page->url);

        } else if ($data = $mform->get_data()) {
            $bi = new stdClass;
            $bi->id = $block->instance->id;
            $bi->showinsubcontexts = $data->bui_showinsubcontexts;
            $bi->pagetypepattern = $data->bui_pagetypepattern;
            if (empty($data->bui_subpagepattern) || $data->bui_subpagepattern == '%@NULL@%') {
                $bi->subpagepattern = null;
            } else {
                $bi->subpagepattern = $data->bui_subpagepattern;
            }
            $bi->defaultregion = $data->bui_defaultregion;
            $bi->defaultweight = $data->bui_defaultweight;
            $DB->update_record('block_instances', $bi);

            $config = clone($block->config);
            foreach ($data as $configfield => $value) {
                if (strpos($configfield, 'config_') !== 0) {
                    continue;
                }
                $field = substr($configfield, 7);
                $config->$field = $value;
            }
            $block->instance_config_save($config);

            $bp = new stdClass;
            $bp->visible = $data->bui_visible;
            $bp->region = $data->bui_region;
            $bp->weight = $data->bui_weight;
            $needbprecord = !$data->bui_visible || $data->bui_region != $data->bui_defaultregion ||
                    $data->bui_weight != $data->bui_defaultweight;

            if ($block->instance->blockpositionid && !$needbprecord) {
                $DB->delete_records('block_positions', array('id' => $block->instance->blockpositionid));

            } else if ($block->instance->blockpositionid && $needbprecord) {
                $bp->id = $block->instance->blockpositionid;
                $DB->update_record('block_positions', $bp);

            } else if ($needbprecord) {
                $bp->blockinstanceid = $block->instance->id;
                $bp->contextid = $this->page->contextid;
                $bp->pagetype = $this->page->pagetype;
                if ($this->page->subpage) {
                    $bp->subpage = $this->page->subpage;
                } else {
                    $bp->subpage = null;
                }
                $DB->insert_record('block_positions', $bp);
            }

            redirect($this->page->url);

        } else {
            $strheading = get_string('editinga', $block->name());
            if (strpos($strheading, '[[') === 0) {
                $strheading = get_string('blockconfiga', 'moodle', $block->get_title());
            }

            $editpage->set_title($strheading);
            $editpage->set_heading($strheading);

            $output = $editpage->theme->get_renderer('core', $editpage);
            echo $output->header();
            echo $output->heading($strheading, 2);
            $mform->display();
            echo $output->footer();
            exit;
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
 * Load a block instance, with position information about where that block appears
 * on a given page.
 *
 * @param integer$blockid the block_instance.id.
 * @param moodle_page $page the page the block is appearing on.
 * @return block_base the requested block.
 */
function block_load_for_page($blockid, $page) {
    global $DB;

    // The code here needs to be consistent with the code in block_manager::load_blocks.
    $params = array(
        'blockinstanceid' => $blockid,
        'subpage' => $page->subpage,
        'contextid' => $page->context->id,
        'pagetype' => $page->pagetype,
        'contextblock' => CONTEXT_BLOCK,
    );
    $sql = "SELECT
                bi.id,
                bp.id AS blockpositionid,
                bi.blockname,
                bi.parentcontextid,
                bi.showinsubcontexts,
                bi.pagetypepattern,
                bi.subpagepattern,
                bi.defaultregion,
                bi.defaultweight,
                COALESCE(bp.visible, 1) AS visible,
                COALESCE(bp.region, bi.defaultregion) AS region,
                COALESCE(bp.weight, bi.defaultweight) AS weight,
                bi.configdata,
                ctx.id AS ctxid,
                ctx.path AS ctxpath,
                ctx.depth AS ctxdepth,
                ctx.contextlevel AS ctxlevel

            FROM {block_instances} bi
            JOIN {block} b ON bi.blockname = b.name
            LEFT JOIN {block_positions} bp ON bp.blockinstanceid = bi.id
                                              AND bp.contextid = :contextid
                                              AND bp.pagetype = :pagetype
                                              AND bp.subpage = :subpage
            JOIN {context} ctx ON ctx.contextlevel = :contextblock
                                  AND ctx.instanceid = bi.id

            WHERE
            bi.id = :blockinstanceid
            AND b.visible = 1

            ORDER BY
                COALESCE(bp.region, bi.defaultregion),
                COALESCE(bp.weight, bi.defaultweight),
                bi.id";
    $bi = $DB->get_record_sql($sql, $params, MUST_EXIST);
    $bi = make_context_subobj($bi);
    return block_instance($bi->blockname, $bi, $page);
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

/**
 * Given a specific page type, return all the page type patterns that might
 * match it.
 *
 * @param string $pagetype for example 'course-view-weeks' or 'mod-quiz-view'.
 * @return array an array of all the page type patterns that might match this page type.
 */
function matching_page_type_patterns($pagetype) {
    $patterns = array($pagetype);
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
     $patterns[] = '*';
    return $patterns;
}

/// Functions update the blocks if required by the request parameters ==========

/**
 * Return a {@link block_contents} representing the add a new block UI, if
 * this user is allowed to see it.
 *
 * @return block_contents an appropriate block_contents, or null if the user
 * cannot add any blocks here.
 */
function block_add_block_ui($page, $output) {
    global $CFG;
    if (!$page->user_is_editing() || !$page->user_can_edit_blocks()) {
        return null;
    }

    $bc = new block_contents();
    $bc->title = get_string('addblock');
    $bc->add_class('block_adminblock');

    $missingblocks = $page->blocks->get_addable_blocks();
    if (empty($missingblocks)) {
        $bc->content = get_string('noblockstoaddhere');
        return $bc;
    }

    $menu = array();
    foreach ($missingblocks as $block) {
        $blockobject = block_instance($block->name);
        if ($blockobject !== false && $blockobject->user_can_addto($page)) {
            $menu[$block->name] = $blockobject->get_title();
        }
    }
    asort($menu, SORT_LOCALE_STRING);

    // TODO convert to $OUTPUT.
    $actionurl = $page->url->out_action() . '&amp;bui_addblock=';
    $bc->content = popup_form($actionurl, $menu, 'add_block', '', get_string('adddots'), '', '', true);
    return $bc;
}

/**
 * Get the appropriate list of editing icons for a block. This is used
 * to set {@link block_contents::$controls} in {@link block_base::get_contents_for_output()}.
 *
 * @param $output The core_renderer to use when generating the output. (Need to get icon paths.)
 * @return an array in the format for {@link block_contents::$controls}
 * @since Moodle 2.0.
 */
function block_edit_controls($block, $page) {
    global $CFG;

    $controls = array();
    $actionurl = $page->url->out_action();

    // Assign roles icon.
    if (has_capability('moodle/role:assign', $block->context)) {
        $controls[] = array('url' => $CFG->wwwroot . '/' . $CFG->admin .
                '/roles/assign.php?contextid=' . $block->context->id . '&amp;returnurl=' . urlencode($page->url->out_returnurl()),
                'icon' => 'i/roles', 'caption' => get_string('assignroles', 'role'));
    }

    if ($block->user_can_edit() && $page->user_can_edit_blocks()) {
        // Show/hide icon.
        if ($block->instance->visible) {
            $controls[] = array('url' => $actionurl . '&amp;bui_hideid=' . $block->instance->id,
                    'icon' => 't/hide', 'caption' => get_string('hide'));
        } else {
            $controls[] = array('url' => $actionurl . '&amp;bui_showid=' . $block->instance->id,
                    'icon' => 't/show', 'caption' => get_string('show'));
        }

        // Edit config icon - always show - needed for positioning UI.
        $controls[] = array('url' => $actionurl . '&amp;bui_editid=' . $block->instance->id,
                'icon' => 't/edit', 'caption' => get_string('configuration'));

        // Delete icon.
        if ($block->user_can_addto($page)) {
            $controls[] = array('url' => $actionurl . '&amp;bui_deleteid=' . $block->instance->id,
                'icon' => 't/delete', 'caption' => get_string('delete'));
        }

        // Move icon.
        $controls[] = array('url' => $page->url->out(false, array('moveblockid' => $block->instance->id)),
                'icon' => 't/move', 'caption' => get_string('move'));
    }

    return $controls;
}

// Functions that have been deprecated by block_manager =======================

/**
 * @deprecated since Moodle 2.0 - use $page->blocks->get_addable_blocks();
 *
 * This function returns an array with the IDs of any blocks that you can add to your page.
 * Parameters are passed by reference for speed; they are not modified at all.
 *
 * @param $page the page object.
 * @param $blockmanager Not used.
 * @return array of block type ids.
 */
function blocks_get_missing(&$page, &$blockmanager) {
    debugging('blocks_get_missing is deprecated. Please use $page->blocks->get_addable_blocks() instead.', DEBUG_DEVELOPER);
    $blocks = $page->blocks->get_addable_blocks();
    $ids = array();
    foreach ($blocks as $block) {
        $ids[] = $block->id;
    }
    return $ids;
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
               blocks_delete_instance($instance->instance);
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
    $instances = $DB->get_recordset('block_instances', array('parentcontextid' => $contextid));
    foreach ($instances as $instance) {
        blocks_delete_instance($instance, true);
    }
    $instances->close();
    $DB->delete_records('block_instances', array('parentcontextid' => $contextid));
    $DB->delete_records('block_positions', array('contextid' => $contextid));
}

/**
 * Set a block to be visible or hidden on a particular page.
 *
 * @param object $instance a row from the block_instances, preferably LEFT JOINed with the
 *      block_positions table as return by block_manager.
 * @param moodle_page $page the back to set the visibility with respect to.
 * @param integer $newvisibility 1 for visible, 0 for hidden.
 */
function blocks_set_visibility($instance, $page, $newvisibility) {
    global $DB;
    if (!empty($instance->blockpositionid)) {
        // Already have local information on this page.
        $DB->set_field('block_positions', 'visible', $newvisibility, array('id' => $instance->blockpositionid));
        return;
    }

    // Create a new block_positions record.
    $bp = new stdClass;
    $bp->blockinstanceid = $instance->id;
    $bp->contextid = $page->context->id;
    $bp->pagetype = $page->pagetype;
    if ($page->subpage) {
        $bp->subpage = $page->subpage;
    }
    $bp->visible = $newvisibility;
    $bp->region = $instance->defaultregion;
    $bp->weight = $instance->defaultweight;
    $DB->insert_record('block_positions', $bp);
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
