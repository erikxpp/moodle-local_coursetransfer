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

// Project implemented by the "Recovery, Transformation and Resilience Plan.
// Funded by the European Union - Next GenerationEU".
//
// Produced by the UNIMOODLE University Group: Universities of
// Valladolid, Complutense de Madrid, UPV/EHU, León, Salamanca,
// Illes Balears, Valencia, Rey Juan Carlos, La Laguna, Zaragoza, Málaga,
// Córdoba, Extremadura, Vigo, Las Palmas de Gran Canaria y Burgos.

/**
 * Upgrade.
 *
 * @package    local_coursetransfer
 * @copyright  2023 Proyecto UNIMOODLE
 * @author     UNIMOODLE Group (Coordinator) <direccion.area.estrategia.digital@uva.es>
 * @author     3IPUNT <contacte@tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 *
 * XMLDB xmldb_local_coursetransfer_upgrade.

 * @param int $oldversion Old Version.
 * @return bool
 * @throws ddl_exception
 * @throws ddl_table_missing_exception
 */
function xmldb_local_coursetransfer_upgrade($oldversion): bool {
    global $CFG, $DB;

    require_once($CFG->libdir.'/db/upgradelib.php'); // Core Upgrade-related functions.

    $dbman = $DB->get_manager(); // Loads ddl manager and xmldb classes.

    if ($oldversion < 2024040500) {

        $destinytable = new xmldb_table('local_coursetransfer_destiny');

        if ($dbman->table_exists($destinytable)) {
            // Rename table local_coursetransfer_destiny to local_coursetransfer_target.
            $dbman->rename_table($destinytable, 'local_coursetransfer_target');
            debugging('Table local_coursetransfer_destiny change name');
        } else {
            debugging('Table local_coursetransfer_destiny not exists');
        }

        $requesttable = new xmldb_table('local_coursetransfer_request');

        if ($dbman->table_exists($requesttable)) {
            if ($dbman->field_exists($requesttable, 'destiny_request_id')) {
                $field = new xmldb_field('destiny_request_id', XMLDB_TYPE_INTEGER,
                        '10', null, false, null, null);
                $dbman->rename_field($requesttable, $field, 'target_request_id');
                debugging('Field destiny_request_id change name');
            } else {
                debugging('Field destiny_request_id not exists');
            }
            if ($dbman->field_exists($requesttable, 'destiny_course_id')) {
                $field = new xmldb_field('destiny_course_id', XMLDB_TYPE_INTEGER,
                        '10', null, false, null, null);
                $dbman->rename_field($requesttable, $field, 'target_course_id');
                debugging('Field destiny_course_id change name');
            } else {
                debugging('Field destiny_course_id not exists');
            }
            if ($dbman->field_exists($requesttable, 'destiny_category_id')) {
                $field = new xmldb_field('destiny_category_id', XMLDB_TYPE_INTEGER,
                        '10', null, false, null, null);
                $dbman->rename_field($requesttable, $field, 'target_category_id');
                debugging('Field destiny_category_id change name');
            } else {
                debugging('Field destiny_category_id not exists');
            }
            if ($dbman->field_exists($requesttable, 'destiny_remove_enrols')) {
                $field = new xmldb_field('destiny_remove_enrols', XMLDB_TYPE_INTEGER,
                        '1', null, false, null, '0');
                $dbman->rename_field($requesttable, $field, 'target_remove_enrols');
                debugging('Field destiny_remove_enrols change name');
            } else {
                debugging('Field destiny_remove_enrols not exists');
            }
            if ($dbman->field_exists($requesttable, 'destiny_remove_groups')) {
                $field = new xmldb_field('destiny_remove_groups', XMLDB_TYPE_INTEGER,
                        '1', null, false, null, '0');
                $dbman->rename_field($requesttable, $field, 'target_remove_groups');
                debugging('Field destiny_remove_groups change name');
            } else {
                debugging('Field destiny_remove_groups not exists');
            }
            if ($dbman->field_exists($requesttable, 'destiny_target')) {
                $field = new xmldb_field('destiny_target', XMLDB_TYPE_INTEGER,
                        '1', null, false, null, '3');
                $dbman->rename_field($requesttable, $field, 'target_target');
                debugging('Field destiny_target change name');
            } else {
                debugging('Field destiny_target not exists');
            }
        } else {
            debugging('Table local_coursetransfer_request not exists');
        }

    }

    if ($oldversion < 2024110200) {

        // Define table local_coursetransfer_log to be created.
        $table = new xmldb_table('local_coursetransfer_log');

        // Adding fields to table local_coursetransfer_log.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('request_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('direction', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, null);
        $table->add_field('action', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('message', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('error_code', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('task_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('task_classname', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('extra_data', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Adding keys to table local_coursetransfer_log.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('request_id', XMLDB_KEY_FOREIGN, ['request_id'], 'local_coursetransfer_request', ['id']);

        // Adding indexes to table local_coursetransfer_log.
        $table->add_index('action_idx', XMLDB_INDEX_NOTUNIQUE, ['action']);
        $table->add_index('status_idx', XMLDB_INDEX_NOTUNIQUE, ['status']);

        // Conditionally launch create table for local_coursetransfer_log.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Coursetransfer savepoint reached.
        upgrade_plugin_savepoint(true, 2024110200, 'local', 'coursetransfer');
    }

    if ($oldversion < 2024122600) {
        // Add missing capabilities to local_coursetransfer_ws role.
        require_once($CFG->dirroot . '/local/coursetransfer/classes/factory/role.php');
        
        $role = $DB->get_record('role', ['shortname' => 'local_coursetransfer_ws']);
        
        if ($role) {
            $roleid = $role->id;
            $context = context_system::instance();
            
            // List of new capabilities to add
            $newcapabilities = [
                // Course viewing and management
                'moodle/course:viewhiddenactivities',
                'moodle/course:viewparticipants',
                'moodle/course:update',
                'moodle/course:changefullname',
                'moodle/course:changeshortname',
                'moodle/course:changeidnumber',
                'moodle/course:changecategory',
                'moodle/course:manageactivities',
                
                // Category management
                'moodle/category:viewhiddencategories',
                
                // File and content access
                'moodle/site:accessallgroups',
                'moodle/site:viewfullnames',
                
                // Question bank (critical for quiz backup/restore)
                'moodle/question:viewall',
                'moodle/question:viewmine',
                'moodle/question:add',
                'moodle/question:editall',
                'moodle/question:editmine',
                'moodle/question:moveall',
                'moodle/question:movemine',
                'moodle/question:usemine',
                'moodle/question:useall',
                
                // Plugin-specific
                'local/coursetransfer:origin_restore_course_users',
                'local/coursetransfer:target_restore_merge',
                'local/coursetransfer:target_restore_content_remove',
                'local/coursetransfer:target_restore_groups_remove',
                'local/coursetransfer:target_restore_enrol_remove',
            ];
            
            foreach ($newcapabilities as $capability) {
                // Check if capability already exists for this role
                $exists = $DB->record_exists('role_capabilities', [
                    'roleid' => $roleid,
                    'capability' => $capability,
                    'contextid' => $context->id
                ]);
                
                if (!$exists) {
                    // Assign the capability
                    \local_coursetransfer\factory\role::add_capability($roleid, $capability);
                    mtrace("  Added capability: {$capability}");
                }
            }
            
            mtrace("Updated coursetransfer role with " . count($newcapabilities) . " new capabilities");
        } else {
            mtrace("Warning: Role local_coursetransfer_ws not found. Capabilities not updated.");
        }
        
        // Coursetransfer savepoint reached.
        upgrade_plugin_savepoint(true, 2024122600, 'local', 'coursetransfer');
    }

    // Add restore queue table for sequential processing.
    if ($oldversion < 2025010701) {
        
        // Define table local_coursetransfer_queue to be created.
        $table = new xmldb_table('local_coursetransfer_queue');

        // Adding fields to table.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('requestid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('origin_course_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('origin_course_name', XMLDB_TYPE_CHAR, '254', null, null, null, null);
        $table->add_field('priority', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending');
        $table->add_field('attempts', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('max_attempts', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '3');
        $table->add_field('processing_started', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('processing_completed', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('error_message', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        // Adding keys to table.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('requestid', XMLDB_KEY_FOREIGN, ['requestid'], 'local_coursetransfer_request', ['id']);

        // Adding indexes to table.
        // Note: status index removed - CHAR fields can cause "text column comparison" errors in strict MySQL
        $table->add_index('priority_created', XMLDB_INDEX_NOTUNIQUE, ['priority', 'timecreated']);

        // Conditionally launch create table.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
            mtrace("Created table local_coursetransfer_queue for sequential restore processing");
        }

        // Coursetransfer savepoint reached.
        upgrade_plugin_savepoint(true, 2025010701, 'local', 'coursetransfer');
    }

    // Add configuration field to request table for queue processing.
    if ($oldversion < 2025010702) {
        
        $table = new xmldb_table('local_coursetransfer_request');
        $field = new xmldb_field('configuration', XMLDB_TYPE_TEXT, null, null, null, null, null, 'error_message');
        
        // Conditionally launch add field configuration.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
            mtrace("Added configuration field to local_coursetransfer_request table");
        }
        
        // Coursetransfer savepoint reached.
        upgrade_plugin_savepoint(true, 2025010702, 'local', 'coursetransfer');
    }

    // Remove problematic status index from queue table (MySQL strict mode issue).
    if ($oldversion < 2025010707) {
        
        $table = new xmldb_table('local_coursetransfer_queue');
        $index = new xmldb_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        
        // Conditionally drop the index if it exists.
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
            mtrace("Dropped problematic status index from local_coursetransfer_queue table");
        }
        
        // Coursetransfer savepoint reached.
        upgrade_plugin_savepoint(true, 2025010707, 'local', 'coursetransfer');
    }

    // Add origin_request_id field to track the request ID on the origin server.
    // This is needed to correctly identify which backup file to delete when cleanup is triggered.
    if ($oldversion < 2025010720) {
        
        $table = new xmldb_table('local_coursetransfer_request');
        $field = new xmldb_field('origin_request_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'origin_backup_url');
        
        // Conditionally launch add field origin_request_id.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
            mtrace("Added origin_request_id field to local_coursetransfer_request table");
        }
        
        // Coursetransfer savepoint reached.
        upgrade_plugin_savepoint(true, 2025010720, 'local', 'coursetransfer');
    }

    return true;
}
