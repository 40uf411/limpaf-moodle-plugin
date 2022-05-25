<?php

defined('MOODLE_INTERNAL') || die();

function xmldb_assignsubmission_limpaf_install() {
    global $CFG;

    // Set the correct initial order for the plugins.
    require_once($CFG->dirroot . '/mod/assign/adminlib.php');
    $pluginmanager = new assign_plugin_manager('assignsubmission');

    $pluginmanager->move_plugin('limpaf', 'up');
    $pluginmanager->move_plugin('limpaf', 'up');

    return true;
}