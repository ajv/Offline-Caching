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
 * @package   mod-url
 * @copyright 2009 Petr Skoda (http://skodak.org)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

function xmldb_url_install() {
    global $CFG;

    // Install logging support
    update_log_display_entry('url', 'view', 'url', 'name');
    update_log_display_entry('url', 'view all', 'url', 'name');
    update_log_display_entry('url', 'update', 'url', 'name');
    update_log_display_entry('url', 'add', 'url', 'name');

    // migrate settings if present
    if (!empty($CFG->resource_secretphrase)) {
        set_config('secretphrase', $CFG->resource_secretphrase, 'url');
    }
    unset_config('resource_secretphrase');

    // Upgrade from old resource module type if needed
    require_once("$CFG->dirroot/mod/url/db/upgradelib.php");
    url_20_migrate();
}

function xmldb_url_install_recovery() {
    global $CFG;

    // Upgrade from old resource module type if needed
    require_once("$CFG->dirroot/mod/url/db/upgradelib.php");
    url_20_migrate();
}
