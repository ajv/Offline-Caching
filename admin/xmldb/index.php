<?php // $Id$

///////////////////////////////////////////////////////////////////////////
//                                                                       //
// NOTICE OF COPYRIGHT                                                   //
//                                                                       //
// Moodle - Modular Object-Oriented Dynamic Learning Environment         //
//          http://moodle.com                                            //
//                                                                       //
// Copyright (C) 1999 onwards Martin Dougiamas     http://dougiamas.com  //
//           (C) 2001-3001 Eloy Lafuente (stronk7) http://contiento.com  //
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

/// This is the main script for the complete XMLDB interface. From here
/// all the actions supported will be launched.

    require_once('../../config.php');
    require_once($CFG->libdir.'/adminlib.php');
    require_once($CFG->libdir.'/ddllib.php');
/// Add required XMLDB action classes
    require_once('actions/XMLDBAction.class.php');
    require_once('actions/XMLDBCheckAction.class.php');


    admin_externalpage_setup('xmldbeditor');

/// Add other used libraries
    require_once($CFG->libdir . '/xmlize.php');

/// Handle session data
    global $XMLDB;

/// State is stored in session - we have to serialise it because the classes are not loaded when creating session
    if (!isset($SESSION->xmldb)) {
        $XMLDB = new stdClass;
    } else {
        $XMLDB = unserialize($SESSION->xmldb);
    }

/// Some previous checks
    if (! $site = get_site()) {
        redirect("$CFG->wwwroot/$CFG->admin/index.php");
    }

    require_login();
    require_capability('moodle/site:config', get_context_instance(CONTEXT_SYSTEM));

/// Body of the script, based on action, we delegate the work
    $action = optional_param ('action', 'main_view', PARAM_ALPHAEXT);

/// Get the action path and invoke it
    $actionsroot = "$CFG->dirroot/$CFG->admin/xmldb/actions";
    $actionclass = $action . '.class.php';
    $actionpath = "$actionsroot/$action/$actionclass";

/// Load and invoke the proper action
    if (file_exists($actionpath) && is_readable($actionpath)) {
        require_once($actionpath);
        if ($xmldb_action = new $action) {
            //Invoke it
            $result = $xmldb_action->invoke();
            // store the result in session
            $SESSION->xmldb = serialize($XMLDB);

            if ($result) {
            /// Based on getDoesGenerate()
                switch ($xmldb_action->getDoesGenerate()) {
                    case ACTION_GENERATE_HTML:

                        $action = optional_param('action', '', PARAM_ALPHAEXT);
                        $postaction = optional_param('postaction', '', PARAM_ALPHAEXT);
                    /// If the js exists, load it
                        if ($action) {
                            $script = $CFG->admin . '/xmldb/actions/' . $action . '/' . $action . '.js';
                            $file = $CFG->dirroot . '/' . $script;
                            if (file_exists($file) && is_readable($file)) {
                                $PAGE->requires->js($script);
                            } else if ($postaction) {
                            /// Try to load the postaction javascript if exists
                                $script = $CFG->admin . '/xmldb/actions/' . $postaction . '/' . $postaction . '.js';
                                $file = $CFG->dirroot . '/' . $script;
                                if (file_exists($file) && is_readable($file)) {
                                    $PAGE->requires->js($script);
                                }
                            }
                        }

                    /// Go with standard admin header
                        admin_externalpage_print_header();
                        echo $OUTPUT->heading($xmldb_action->getTitle());
                        echo $xmldb_action->getOutput();
                        echo $OUTPUT->footer();
                        break;
                    case ACTION_GENERATE_XML:
                        header('Content-type: application/xhtml+xml');
                        echo $xmldb_action->getOutput();
                        break;
                }
            } else {
                //TODO: need more detailed error info
                print_error('xmldberror');
            }
        } else {
            $a = new stdclass;
            $a->action = $action;
            $a->actionclass = $actionclass;
            print_error('cannotinstantiateclass', 'xmldb', '', $a);
        }
    } else {
        print_error('invalidaction');
    }

    if ($xmldb_action->getDoesGenerate() != ACTION_GENERATE_XML) {
        if (debugging()) {
            ///print_object($XMLDB);
        }
    }

?>
