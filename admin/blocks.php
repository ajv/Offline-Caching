<?PHP // $Id$

    // Allows the admin to configure blocks (hide/show, delete and configure)

    require_once('../config.php');
    require_once($CFG->libdir.'/adminlib.php');
    require_once($CFG->libdir.'/tablelib.php');

    admin_externalpage_setup('manageblocks');

    $confirm  = optional_param('confirm', 0, PARAM_BOOL);
    $hide     = optional_param('hide', 0, PARAM_INT);
    $show     = optional_param('show', 0, PARAM_INT);
    $delete   = optional_param('delete', 0, PARAM_INT);

/// Print headings

    $strmanageblocks = get_string('manageblocks');
    $strdelete = get_string('delete');
    $strversion = get_string('version');
    $strhide = get_string('hide');
    $strshow = get_string('show');
    $strsettings = get_string('settings');
    $strcourses = get_string('blockinstances', 'admin');
    $strname = get_string('name');
    $strshowblockcourse = get_string('showblockcourse');

/// If data submitted, then process and store.

    if (!empty($hide) && confirm_sesskey()) {
        if (!$block = $DB->get_record('block', array('id'=>$hide))) {
            print_error('blockdoesnotexist', 'error');
        }
        $DB->set_field('block', 'visible', '0', array('id'=>$block->id));      // Hide block
        admin_get_root(true, false);  // settings not required - only pages
    }

    if (!empty($show) && confirm_sesskey() ) {
        if (!$block = $DB->get_record('block', array('id'=>$show))) {
            print_error('blockdoesnotexist', 'error');
        }
        $DB->set_field('block', 'visible', '1', array('id'=>$block->id));      // Show block
        admin_get_root(true, false);  // settings not required - only pages
    }

    if (!empty($delete) && confirm_sesskey()) {
        admin_externalpage_print_header();
        print_heading($strmanageblocks);

        if (!$block = blocks_get_record($delete)) {
            print_error('blockdoesnotexist', 'error');
        }

        else {
            $blockobject = block_instance($block->name);
            $strblockname = $blockobject->get_title();
        }

        if (!$confirm) {
            notice_yesno(get_string('blockdeleteconfirm', '', $strblockname),
                         'blocks.php?delete='.$block->id.'&amp;confirm=1&amp;sesskey='.sesskey(),
                         'blocks.php');
            admin_externalpage_print_footer();
            exit;

        } else {
            // Inform block it's about to be deleted
            $blockobject = block_instance($block->name);
            if ($blockobject) {
                $blockobject->before_delete();  //only if we can create instance, block might have been already removed
            }

            // First delete instances and then block
            $instances = $DB->get_records('block_instances', array('blockname' => $block->name));
            if(!empty($instances)) {
                foreach($instances as $instance) {
                    blocks_delete_instance($instance);
                }
            }

            // Delete block
            if (!$DB->delete_records('block', array('id'=>$block->id))) {
                notify("Error occurred while deleting the $strblockname record from blocks table");
            }

            drop_plugin_tables($block->name, "$CFG->dirroot/blocks/$block->name/db/install.xml", false); // old obsoleted table names
            drop_plugin_tables('block_'.$block->name, "$CFG->dirroot/blocks/$block->name/db/install.xml", false);

            // Delete the capabilities that were defined by this block
            capabilities_cleanup('block/'.$block->name);

            // remove entent handlers and dequeue pending events
            events_uninstall('block/'.$block->name);

            $a->block = $strblockname;
            $a->directory = $CFG->dirroot.'/blocks/'.$block->name;
            notice(get_string('blockdeletefiles', '', $a), 'blocks.php');
        }
    }

    admin_externalpage_print_header();
    print_heading($strmanageblocks);

/// Main display starts here

/// Get and sort the existing blocks

    if (!$blocks = $DB->get_records('block')) {
        print_error('noblocks', 'error');  // Should never happen
    }

    $incompatible = array();

    foreach ($blocks as $block) {
        if (($blockobject = block_instance($block->name)) === false) {
            // Failed to load
            continue;
        }
        $blockbyname[$blockobject->get_title()] = $block->id;
        $blockobjects[$block->id] = $blockobject;
    }

    if(empty($blockbyname)) {
        print_error('failtoloadblocks', 'error');
    }

    ksort($blockbyname);

/// Print the table of all blocks

    $table = new flexible_table('admin-blocks-compatible');

    $table->define_columns(array('name', 'instances', 'version', 'hideshow', 'delete', 'settings'));
    $table->define_headers(array($strname, $strcourses, $strversion, $strhide.'/'.$strshow, $strdelete, $strsettings));
    $table->define_baseurl($CFG->wwwroot.'/'.$CFG->admin.'/blocks.php');
    $table->set_attribute('id', 'blocks');
    $table->set_attribute('class', 'generaltable generalbox boxaligncenter boxwidthwide');
    $table->setup();

    foreach ($blockbyname as $blockname => $blockid) {

        $blockobject = $blockobjects[$blockid];
        $block       = $blocks[$blockid];

        $delete = '<a href="blocks.php?delete='.$blockid.'&amp;sesskey='.sesskey().'">'.$strdelete.'</a>';

        $settings = ''; // By default, no configuration
        if ($blockobject->has_config()) {
            if (file_exists($CFG->dirroot.'/blocks/'.$block->name.'/settings.php')) {
                $settings = '<a href="'.$CFG->wwwroot.'/'.$CFG->admin.'/settings.php?section=blocksetting'.$block->name.'">'.$strsettings.'</a>';
            } else {
                $settings = '<a href="block.php?block='.$blockid.'">'.$strsettings.'</a>';
            }
        }

        // MDL-11167, blocks can be placed on mymoodle, or the blogs page
        // and it should not show up on course search page

        $totalcount = $DB->count_records('block_instances', array('blockname'=>$blockname));
        $count = $DB->count_records('block_instances', array('blockname'=>$blockname, 'pagetypepattern'=>'course-view-*'));

        if ($count>0) {
            $blocklist = "<a href=\"{$CFG->wwwroot}/course/search.php?blocklist=$blockid&amp;sesskey=".sesskey()."\" ";
            $blocklist .= "title=\"$strshowblockcourse\" >$totalcount</a>";
        }
        else {
            $blocklist = "$totalcount";
        }
        $class = ''; // Nothing fancy, by default

        if ($blocks[$blockid]->visible) {
            $visible = '<a href="blocks.php?hide='.$blockid.'&amp;sesskey='.sesskey().'" title="'.$strhide.'">'.
                       '<img src="'.$OUTPUT->old_icon_url('i/hide') . '" class="icon" alt="'.$strhide.'" /></a>';
        } else {
            $visible = '<a href="blocks.php?show='.$blockid.'&amp;sesskey='.sesskey().'" title="'.$strshow.'">'.
                       '<img src="'.$OUTPUT->old_icon_url('i/show') . '" class="icon" alt="'.$strshow.'" /></a>';
            $class = ' class="dimmed_text"'; // Leading space required!
        }

        $table->add_data(array(
            '<span'.$class.'>'.$blockobject->get_title().'</span>',
            $blocklist,
            '<span'.$class.'>'.$blockobject->get_version().'</span>',
            $visible,
            $delete,
            $settings
        ));
    }

    $table->print_html();

    if(!empty($incompatible)) {
        print_heading(get_string('incompatibleblocks', 'admin'));

        $table = new flexible_table('admin-blocks-incompatible');

        $table->define_columns(array('block', 'delete'));
        $table->define_headers(array($strname, $strdelete));
        $table->define_baseurl($CFG->wwwroot.'/'.$CFG->admin.'/blocks.php');

        $table->set_attribute('id', 'incompatible');
        $table->set_attribute('class', 'generaltable generalbox boxaligncenter boxwidthwide');

        $table->setup();

        foreach ($incompatible as $block) {
            $table->add_data(array(
                $block->name,
                '<a href="blocks.php?delete='.$block->id.'&amp;sesskey='.sesskey().'">'.$strdelete.'</a>',
            ));
        }
        $table->print_html();
    }

    admin_externalpage_print_footer();

?>
