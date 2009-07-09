<?php  //$Id$

/*
 * Please note that is file is always loaded last - it means that you can inject entries into other categories too.
 */

if ($hassiteconfig || has_capability('moodle/question:config', $systemcontext)) {

    require_once($CFG->libdir. '/portfoliolib.php');

    $ADMIN->add('modules', new admin_category('modsettings', get_string('activitymodules')));
    $ADMIN->add('modsettings', new admin_page_managemods());
    if ($modules = $DB->get_records('modules')) {
        $modulebyname = array();

        foreach ($modules as $module) {
            $strmodulename = get_string('modulename', $module->name);
            // Deal with modules which are lacking the language string
            if ($strmodulename == '[[modulename]]') {
                $textlib = textlib_get_instance();
                $strmodulename = $textlib->strtotitle($module->name);
            }
            $modulebyname[$strmodulename] = $module;
        }
        ksort($modulebyname);

        foreach ($modulebyname as $strmodulename=>$module) {
            $modulename = $module->name;
            if (file_exists($CFG->dirroot.'/mod/'.$modulename.'/settingstree.php')) {
                include($CFG->dirroot.'/mod/'.$modulename.'/settingstree.php');
            } else if (file_exists($CFG->dirroot.'/mod/'.$modulename.'/settings.php')) {
                // do not show disabled modules in tree, keep only settings link on manage page
                $settings = new admin_settingpage('modsetting'.$modulename, $strmodulename, 'moodle/site:config', !$module->visible);
                if ($ADMIN->fulltree) {
                    include($CFG->dirroot.'/mod/'.$modulename.'/settings.php');
                }
                $ADMIN->add('modsettings', $settings);
            }
        }
    }

    // hidden script for converting journals to online assignments (or something like that) linked from elsewhere
    $ADMIN->add('modsettings', new admin_externalpage('oacleanup', 'Online Assignment Cleanup', $CFG->wwwroot.'/'.$CFG->admin.'/oacleanup.php', 'moodle/site:config', true));

    $ADMIN->add('modules', new admin_category('blocksettings', get_string('blocks')));
    $ADMIN->add('blocksettings', new admin_page_manageblocks());
    if ($blocks = $DB->get_records('block')) {
        $blockbyname = array();

        foreach ($blocks as $block) {
            if(($blockobject = block_instance($block->name)) === false) {
                // Failed to load
                continue;
            }
            $blockbyname[$blockobject->get_title()] = $block;
        }
        ksort($blockbyname);

        foreach ($blockbyname as $strblockname=>$block) {
            $blockname = $block->name;
            if (file_exists($CFG->dirroot.'/blocks/'.$blockname.'/settings.php')) {
                $settings = new admin_settingpage('blocksetting'.$blockname, $strblockname, 'moodle/site:config', !$block->visible);
                if ($ADMIN->fulltree) {
                    include($CFG->dirroot.'/blocks/'.$blockname.'/settings.php');
                }
                $ADMIN->add('blocksettings', $settings);

            } else if (file_exists($CFG->dirroot.'/blocks/'.$blockname.'/config_global.html')) {
                $ADMIN->add('blocksettings', new admin_externalpage('blocksetting'.$blockname, $strblockname, "$CFG->wwwroot/$CFG->admin/block.php?block=$block->id", 'moodle/site:config', !$block->visible));
            }
        }
    }


/// Editor plugins
    $ADMIN->add('modules', new admin_category('editorsettings', get_string('editors', 'editor')));
    $temp = new admin_settingpage('manageeditors', get_string('editorsettings', 'editor'));
    $temp->add(new admin_setting_manageeditors());
    $ADMIN->add('editorsettings', $temp);


/// Filter plugins
    $ADMIN->add('modules', new admin_category('filtersettings', get_string('managefilters')));

    $ADMIN->add('filtersettings', new admin_page_managefilters());

    // "filtersettings" settingpage
    $temp = new admin_settingpage('commonfiltersettings', get_string('commonfiltersettings', 'admin'));
    if ($ADMIN->fulltree) {
        $cachetimes = array(
            604800 => get_string('numdays','',7),
            86400 => get_string('numdays','',1),
            43200 => get_string('numhours','',12),
            10800 => get_string('numhours','',3),
            7200 => get_string('numhours','',2),
            3600 => get_string('numhours','',1),
            2700 => get_string('numminutes','',45),
            1800 => get_string('numminutes','',30),
            900 => get_string('numminutes','',15),
            600 => get_string('numminutes','',10),
            540 => get_string('numminutes','',9),
            480 => get_string('numminutes','',8),
            420 => get_string('numminutes','',7),
            360 => get_string('numminutes','',6),
            300 => get_string('numminutes','',5),
            240 => get_string('numminutes','',4),
            180 => get_string('numminutes','',3),
            120 => get_string('numminutes','',2),
            60 => get_string('numminutes','',1),
            30 => get_string('numseconds','',30),
            0 => get_string('no')
        );
        $items = array();
        $items[] = new admin_setting_configselect('cachetext', get_string('cachetext', 'admin'), get_string('configcachetext', 'admin'), 60, $cachetimes);
        $items[] = new admin_setting_configselect('filteruploadedfiles', get_string('filteruploadedfiles', 'admin'), get_string('configfilteruploadedfiles', 'admin'), 0,
                array('0' => get_string('none'), '1' => get_string('allfiles'), '2' => get_string('htmlfilesonly')));
        $items[] = new admin_setting_configcheckbox('filtermatchoneperpage', get_string('filtermatchoneperpage', 'admin'), get_string('configfiltermatchoneperpage', 'admin'), 0);
        $items[] = new admin_setting_configcheckbox('filtermatchonepertext', get_string('filtermatchonepertext', 'admin'), get_string('configfiltermatchonepertext', 'admin'), 0);
        foreach ($items as $item) {
            $item->set_updatedcallback('reset_text_filters_cache');
            $temp->add($item);
        }
    }
    $ADMIN->add('filtersettings', $temp);

    $activefilters = filter_get_globally_enabled();
    $filternames = filter_get_all_installed();
    foreach ($filternames as $filterpath => $strfiltername) {
        if (file_exists("$CFG->dirroot/$filterpath/filtersettings.php")) {
            $settings = new admin_settingpage('filtersetting'.str_replace('/', '', $filterpath),
                    $strfiltername, 'moodle/site:config', !isset($activefilters[$filterpath]));
            if ($ADMIN->fulltree) {
                include("$CFG->dirroot/$filterpath/filtersettings.php");
            }
            $ADMIN->add('filtersettings', $settings);
        }
    }

    $catname = get_string('portfolios', 'portfolio');
    $manage = get_string('manageportfolios', 'portfolio');
    $url = "$CFG->wwwroot/$CFG->admin/portfolio.php";

    $ADMIN->add('modules', new admin_category('portfoliosettings', $catname, empty($CFG->enableportfolios)));

    // jump through hoops to do what we want
    $temp = new admin_settingpage('manageportfolios', get_string('manageportfolios', 'portfolio'));
    $temp->add(new admin_setting_heading('manageportfolios', get_string('activeportfolios', 'portfolio'), ''));
    $temp->add(new admin_setting_manageportfolio());
    $temp->add(new admin_setting_heading('manageportfolioscommon', get_string('commonsettings', 'admin'), get_string('commonsettingsdesc', 'portfolio')));
    $fileinfo = portfolio_filesize_info(); // make sure this is defined in one place since its used inside portfolio too to detect insane settings
    $fileoptions = $fileinfo['options'];
    $temp->add(new admin_setting_configselect(
        'portfolio_moderate_filesize_threshold',
        get_string('moderatefilesizethreshold', 'portfolio'),
        get_string('moderatefilesizethresholddesc', 'portfolio'),
        $fileinfo['moderate'], $fileoptions));
    $temp->add(new admin_setting_configselect(
        'portfolio_high_filesize_threshold',
        get_string('highfilesizethreshold', 'portfolio'),
        get_string('highfilesizethresholddesc', 'portfolio'),
        $fileinfo['high'], $fileoptions));

    $temp->add(new admin_setting_configtext(
        'portfolio_moderate_db_threshold',
        get_string('moderatedbsizethreshold', 'portfolio'),
        get_string('moderatedbsizethresholddesc', 'portfolio'),
        20, PARAM_INTEGER, 3));

    $temp->add(new admin_setting_configtext(
        'portfolio_high_db_threshold',
        get_string('highdbsizethreshold', 'portfolio'),
        get_string('highdbsizethresholddesc', 'portfolio'),
        50, PARAM_INTEGER, 3));

    $ADMIN->add('portfoliosettings', $temp);
    $ADMIN->add('portfoliosettings', new admin_externalpage('portfolionew', get_string('addnewportfolio', 'portfolio'), $url, 'moodle/site:config', true), '', $url);
    $ADMIN->add('portfoliosettings', new admin_externalpage('portfoliodelete', get_string('deleteportfolio', 'portfolio'), $url, 'moodle/site:config', true), '', $url);
    $ADMIN->add('portfoliosettings', new admin_externalpage('portfoliocontroller', get_string('manageportfolios', 'portfolio'), $url, 'moodle/site:config', true), '', $url);

    foreach (portfolio_instances(false, false) as $portfolio) {
        require_once($CFG->dirroot . '/portfolio/type/' . $portfolio->get('plugin') . '/lib.php');
        $classname = 'portfolio_plugin_' . $portfolio->get('plugin');
        $ADMIN->add(
            'portfoliosettings',
            new admin_externalpage(
                'portfoliosettings' . $portfolio->get('id'),
                $portfolio->get('name'),
                $url . '?edit=' . $portfolio->get('id'),
                'moodle/site:config',
                !$portfolio->get('visible')
            ),
            $portfolio->get('name'),
            $url . ' ?edit=' . $portfolio->get('id')
        );
    }

    // repository setting
    require_once("$CFG->dirroot/repository/lib.php");
    $catname =get_string('repositories', 'repository');
    $managerepo = get_string('manage', 'repository');
    $url = $CFG->wwwroot.'/'.$CFG->admin.'/repository.php';
    $ADMIN->add('modules', new admin_category('repositorysettings', $catname));
    $temp = new admin_settingpage('managerepositories', $managerepo);
    $temp->add(new admin_setting_heading('managerepositories', get_string('activerepository', 'repository'), ''));
    $temp->add(new admin_setting_managerepository());
    $temp->add(new admin_setting_heading('managerepositoriescommonheading', get_string('commonsettings', 'admin'), ''));
    $temp->add(new admin_setting_configtext('repositorycacheexpire', get_string('cacheexpire', 'repository'), get_string('configcacheexpire', 'repository'), 120));
    $ADMIN->add('repositorysettings', $temp);
    $ADMIN->add('repositorysettings', new admin_externalpage('repositorynew',
        get_string('addplugin', 'repository'), $url, 'moodle/site:config', true),
        '', $url);
    $ADMIN->add('repositorysettings', new admin_externalpage('repositorydelete',
        get_string('deleterepository', 'repository'), $url, 'moodle/site:config', true),
        '', $url);
    $ADMIN->add('repositorysettings', new admin_externalpage('repositorycontroller',
        get_string('managerepositories', 'repository'), $url, 'moodle/site:config', true),
        '', $url);
    $ADMIN->add('repositorysettings', new admin_externalpage('repositoryinstancenew',
        get_string('createrepository', 'repository'), $url, 'moodle/site:config', true),
        '', $url);
    $ADMIN->add('repositorysettings', new admin_externalpage('repositoryinstanceedit',
        get_string('editrepositoryinstance', 'repository'), $url, 'moodle/site:config', true),
        '', $url);
    foreach (repository::get_types()
        as $repositorytype)
    {
      //display setup page for plugins with: general options or multiple instances (e.g. has instance config)
      $typeoptionnames = repository::static_function($repositorytype->get_typename(), 'get_type_option_names');
      $instanceoptionnames = repository::static_function($repositorytype->get_typename(), 'get_instance_option_names');
      if (!empty($typeoptionnames) || !empty($instanceoptionnames)) {
            $ADMIN->add('repositorysettings',
                new admin_externalpage('repositorysettings'.$repositorytype->get_typename(),
                        $repositorytype->get_readablename(),
                        $url . '?edit=' . $repositorytype->get_typename()),
                        'moodle/site:config');
        }
    }

    // Question type settings.
    $ADMIN->add('modules', new admin_category('qtypesettings', get_string('questiontypes', 'admin')));
    $ADMIN->add('qtypesettings', new admin_page_manageqtypes());
    require_once($CFG->libdir . '/questionlib.php');
    global $QTYPES;
    foreach ($QTYPES as $qtype) {
        $settingsfile = $qtype->plugin_dir() . '/settings.php';
        if (file_exists($settingsfile)) {
            $settings = new admin_settingpage('qtypesetting' . $qtype->name(),
                    $qtype->local_name(), 'moodle/question:config');
            if ($ADMIN->fulltree) {
                include($settingsfile);
            }
            $ADMIN->add('qtypesettings', $settings);
        }
    }
}


/// Now add reports

foreach (get_plugin_list('report') as $plugin => $plugindir) {
    $settings_path = "$plugindir/settings.php";
    if (file_exists($settings_path)) {
        include($settings_path);
        continue;
    }

    $index_path = "$plugindir/index.php";
    if (!file_exists($index_path)) {
        continue;
    }
    // old style 3rd party plugin without settings.php
    $www_path = "$CFG->wwwroot/$CFG->admin/report/$plugin/index.php";
    $reportname = get_string($plugin, 'report_' . $plugin);
    $ADMIN->add('reports', new admin_externalpage('report'.$plugin, $reportname, $www_path, 'moodle/site:viewreports'));
}


/// Add all local plugins - must be always last!

foreach (get_plugin_list('local') as $plugin => $plugindir) {
    $settings_path = "$plugindir/settings.php";
    if (file_exists($settings_path)) {
        include($settings_path);
        continue;
    }
}