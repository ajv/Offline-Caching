<?php // $Id$
/**
* Code fragment to define the version of feedback
* This fragment is called by moodle_needs_upgrading() and /admin/index.php
*
* @version $Id$
* @author Andreas Grabs
* @license http://www.gnu.org/copyleft/gpl.html GNU Public License
* @package feedback
*/

   
    $module->version = 2009052701; // The current module version (Date: YYYYMMDDXX)
    $module->requires = 2009041700;  // Requires this Moodle version
    $feedback_version_intern = 1; //this version is used for restore older backups
    $module->cron = 0; // Period for cron to check this module (secs)

?>
