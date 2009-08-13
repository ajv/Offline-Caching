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
 * Post installation and migration code.
 *
 * This file replaces:
 *   - STATEMENTS section in db/install.xml
 *   - lib.php/modulename_install() post installation hook
 *   - partially defaults.php
 *
 * @package   mod-folder
 * @copyright 2009 Petr Skoda (http://skodak.org)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

function xmldb_folder_install() {
    global $CFG;

    // Install logging support
    update_log_display_entry('folder', 'view', 'folder', 'name');
    update_log_display_entry('folder', 'view all', 'folder', 'name');
    update_log_display_entry('folder', 'update', 'folder', 'name');
    update_log_display_entry('folder', 'add', 'folder', 'name');

    // Upgrade from old resource module type if needed
    require_once("$CFG->dirroot/mod/folder/db/upgradelib.php");
    folder_20_migrate();
}

function xmldb_folder_install_recovery() {
    global $CFG;

    require_once("$CFG->dirroot/mod/folder/db/upgradelib.php");
    folder_20_migrate();
}