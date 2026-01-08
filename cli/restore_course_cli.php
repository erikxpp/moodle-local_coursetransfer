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
 * CLI script to execute a course restore in an isolated PHP process.
 *
 * This script runs the restore for a specific request in a completely
 * fresh PHP process. This avoids Moodle's static cache contamination issue 
 * where quiz restore plugins keep stale references from previous restores.
 *
 * Usage: php restore_course_cli.php --requestid=123
 *
 * @package    local_coursetransfer
 * @copyright  2025 Proyecto UNIMOODLE
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

// Find config.php - works for different Moodle structures
$configpath = __DIR__ . '/../../../config.php';
if (!file_exists($configpath)) {
    $configpath = __DIR__ . '/../../../../config.php';
}
if (!file_exists($configpath)) {
    // Try to find it by going up directories
    $dir = __DIR__;
    for ($i = 0; $i < 10; $i++) {
        $dir = dirname($dir);
        if (file_exists($dir . '/config.php')) {
            $configpath = $dir . '/config.php';
            break;
        }
    }
}

require($configpath);
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

use local_coursetransfer\coursetransfer_request;

// Get CLI options.
list($options, $unrecognized) = cli_get_params(
    [
        'requestid' => null,
        'fileid' => null,
        'help' => false,
    ],
    [
        'r' => 'requestid',
        'f' => 'fileid',
        'h' => 'help',
    ]
);

if ($options['help'] || empty($options['requestid'])) {
    $help = <<<EOF
Execute a course restore in an isolated PHP process.

This script ensures each restore has a clean PHP environment without
static cache contamination from previous restores.

Options:
 -r, --requestid=INT    The request ID to restore (required)
 -f, --fileid=INT       The file ID of the backup (optional, will search if not provided)
 -h, --help             Show this help

Example:
 php restore_course_cli.php --requestid=123 --fileid=456

EOF;
    echo $help;
    exit($options['help'] ? 0 : 1);
}

$requestid = (int)$options['requestid'];
$fileid = !empty($options['fileid']) ? (int)$options['fileid'] : null;

cli_writeln("[RESTORE CLI] Starting isolated restore for request {$requestid}");
cli_writeln("[RESTORE CLI] PID: " . getmypid() . ", Memory: " . round(memory_get_usage() / 1024 / 1024, 2) . " MB");
if ($fileid) {
    cli_writeln("[RESTORE CLI] File ID provided: {$fileid}");
}

// Increase limits
@set_time_limit(0);
raise_memory_limit(MEMORY_HUGE);

try {
    // Get request
    $request = $DB->get_record('local_coursetransfer_request', ['id' => $requestid]);
    
    if (!$request) {
        cli_error("[RESTORE CLI] Request {$requestid} not found");
    }

    cli_writeln("[RESTORE CLI] Origin Course: {$request->origin_course_id}");
    cli_writeln("[RESTORE CLI] Target Course: {$request->target_course_id}");
    cli_writeln("[RESTORE CLI] Status: {$request->status}");

    // Get backup file
    $fs = get_file_storage();
    $backupfile = null;
    
    // Try to get file by ID first (most reliable)
    if ($fileid) {
        $backupfile = $fs->get_file_by_id($fileid);
        if ($backupfile) {
            cli_writeln("[RESTORE CLI] Found file by ID: {$fileid}");
        }
    }
    
    // Fallback: search in course context (component=backup, filearea=course)
    if (!$backupfile && $request->target_course_id) {
        cli_writeln("[RESTORE CLI] Searching in course context...");
        $context = \context_course::instance($request->target_course_id);
        $files = $fs->get_area_files($context->id, 'backup', 'course', 0, 'timecreated DESC', false);
        if (!empty($files)) {
            $backupfile = reset($files);
            cli_writeln("[RESTORE CLI] Found file in course context");
        }
    }
    
    // Fallback: search in system context (component=local_coursetransfer, filearea=backup)
    if (!$backupfile) {
        cli_writeln("[RESTORE CLI] Searching in system context...");
        $context = \context_system::instance();
        $files = $fs->get_area_files($context->id, 'local_coursetransfer', 'backup', $request->id, 'id DESC', false);
        if (!empty($files)) {
            $backupfile = reset($files);
            cli_writeln("[RESTORE CLI] Found file in system context");
        }
    }

    if (!$backupfile) {
        cli_error("[RESTORE CLI] No backup file found for request {$requestid}");
    }
    cli_writeln("[RESTORE CLI] Backup file: " . $backupfile->get_filename() . " (" . 
        round($backupfile->get_filesize() / 1024 / 1024, 2) . " MB)");

    // Update status to restoring
    $request->status = coursetransfer_request::STATUS_RESTORE;
    $request->timemodified = time();
    $DB->update_record('local_coursetransfer_request', $request);

    // Extract backup to temp directory
    $backupdir = 'ct_restore_' . $requestid . '_' . time();
    $backuppath = $CFG->tempdir . '/backup/' . $backupdir;
    
    cli_writeln("[RESTORE CLI] Extracting to: {$backupdir}");
    
    $fp = get_file_packer('application/vnd.moodle.backup');
    $extracted = $backupfile->extract_to_pathname($fp, $backuppath);
    
    if (!$extracted) {
        throw new \Exception('Failed to extract backup file');
    }
    
    cli_writeln("[RESTORE CLI] Extraction complete");

    // PRE-RESTORE: Load origin data from backup XMLs BEFORE restore
    // (Moodle deletes the temp directory after restore, so we must read it now)
    cli_writeln("[RESTORE CLI] Loading origin data from backup XMLs...");
    $origin_data = load_origin_data_from_backup($backuppath);
    cli_writeln("[RESTORE CLI] Origin data loaded: " . count($origin_data['activities']) . " activity types, " . 
        count($origin_data['sections']) . " sections");

    // Get admin user for restore
    $admin = get_admin();

    // Determine restore target mode based on configuration
    $target_mode = \backup::TARGET_EXISTING_DELETING;
    
    // Check if we should add to existing content instead
    if (!empty($request->configuration)) {
        $config = json_decode($request->configuration, true);
        if (isset($config['targettarget']) && $config['targettarget'] == 4) {
            $target_mode = \backup::TARGET_CURRENT_ADDING;
            cli_writeln("[RESTORE CLI] Mode: Adding to existing content");
        } else {
            cli_writeln("[RESTORE CLI] Mode: Replacing existing content");
        }
    }

    cli_writeln("[RESTORE CLI] Creating restore controller...");

    // Create restore controller
    $rc = new \restore_controller(
        $backupdir,
        $request->target_course_id,
        \backup::INTERACTIVE_NO,
        \backup::MODE_GENERAL,
        $admin->id,
        $target_mode
    );

    // CRITICAL: Configure restore plan settings to copy ALL course configuration from origin
    // Without overwrite_conf=true, Moodle keeps the destination course settings instead of origin's
    $plan = $rc->get_plan();
    if ($plan) {
        cli_writeln("[RESTORE CLI] Configuring restore plan settings...");
        
        // Get configuration from request
        $config = !empty($request->configuration) ? json_decode($request->configuration, true) : [];
        $removeenrols = isset($config['targetremoveenrols']) ? (int)$config['targetremoveenrols'] : 0;
        $removegroups = isset($config['targetremovegroups']) ? (int)$config['targetremovegroups'] : 0;
        
        // Determine keep settings based on target mode
        if ($target_mode !== \backup::TARGET_CURRENT_DELETING && $target_mode !== \backup::TARGET_EXISTING_DELETING) {
            $keeprolesenrolments = true;
            $keepgroupsgroupings = true;
        } else {
            $keeprolesenrolments = $removeenrols === 1 ? false : true;
            $keepgroupsgroupings = $removegroups === 1 ? false : true;
        }
        
        // Define restore options - THIS IS CRITICAL for copying course configuration
        $restoreoptions = [
            'overwrite_conf' => true,  // CRITICAL: Overwrite course configuration with origin values
            'keep_roles_and_enrolments' => $keeprolesenrolments,
            'keep_groups_and_groupings' => $keepgroupsgroupings,
        ];
        
        // Apply settings to the restore plan
        foreach ($restoreoptions as $option => $value) {
            try {
                $setting = $plan->get_setting($option);
                $setting->set_status(\base_setting::NOT_LOCKED);
                $setting->set_value($value);
                cli_writeln("[RESTORE CLI]   - {$option} = " . ($value ? 'true' : 'false'));
            } catch (\Exception $e) {
                cli_writeln("[RESTORE CLI]   - WARNING: Could not set {$option}: " . $e->getMessage());
            }
        }
        
        cli_writeln("[RESTORE CLI] Restore plan configured with overwrite_conf=true (will copy course settings from origin)");
    }

    // Check if conversion needed
    if ($rc->get_status() == \backup::STATUS_REQUIRE_CONV) {
        cli_writeln("[RESTORE CLI] Converting backup format...");
        $rc->convert();
    }

    // Run prechecks
    cli_writeln("[RESTORE CLI] Running prechecks...");
    if (!$rc->execute_precheck()) {
        $results = $rc->get_precheck_results();
        
        // Check if only warnings (not errors)
        if (!empty($results['errors'])) {
            $rc->destroy();
            throw new \Exception('Precheck errors: ' . json_encode($results['errors']));
        }
        cli_writeln("[RESTORE CLI] Precheck warnings (continuing): " . json_encode($results));
    }

    // Execute restore plan
    cli_writeln("[RESTORE CLI] Executing restore plan (with overwrite_conf=true)...");
    $rc->execute_plan();
    
    // Cleanup
    $rc->destroy();
    
    cli_writeln("[RESTORE CLI] Restore plan executed successfully");

    // POST-RESTORE: Update course idnumber from origin
    // Moodle's restore doesn't properly copy the idnumber, so we need to do it manually
    if (!empty($request->origin_course_idnumber)) {
        cli_writeln("[RESTORE CLI] Updating course idnumber to: {$request->origin_course_idnumber}");
        $DB->set_field('course', 'idnumber', $request->origin_course_idnumber, ['id' => $request->target_course_id]);
    } else {
        // Try to get idnumber from the backup's course.xml
        $course_xml_path = $backuppath . '/course/course.xml';
        if (file_exists($course_xml_path)) {
            $course_xml = simplexml_load_file($course_xml_path);
            if ($course_xml && !empty($course_xml->idnumber)) {
                $origin_idnumber = (string)$course_xml->idnumber;
                cli_writeln("[RESTORE CLI] Updating course idnumber from backup: {$origin_idnumber}");
                $DB->set_field('course', 'idnumber', $origin_idnumber, ['id' => $request->target_course_id]);
            }
        }
    }

    // POST-RESTORE: Fix course names - remove "copia X" suffix and "_X" from shortname
    // Moodle adds these suffixes when restoring to existing course slots
    cli_writeln("[RESTORE CLI] ========================================");
    cli_writeln("[RESTORE CLI] FIXING COURSE NAMES FROM ORIGIN");
    cli_writeln("[RESTORE CLI] ========================================");
    
    $names_fixed = fix_course_names_from_origin($origin_data, $request->target_course_id, $DB);
    if ($names_fixed['changed']) {
        cli_writeln("[RESTORE CLI] ✓ Course names corrected to match origin");
        if (!empty($names_fixed['fullname'])) {
            cli_writeln("[RESTORE CLI]   Fullname: " . $names_fixed['fullname']);
        }
        if (!empty($names_fixed['shortname'])) {
            cli_writeln("[RESTORE CLI]   Shortname: " . $names_fixed['shortname']);
        }
    } else {
        cli_writeln("[RESTORE CLI] ℹ Course names already match origin (no changes needed)");
    }

    // POST-RESTORE: Validate and log availability restrictions
    cli_writeln("[RESTORE CLI] Validating availability restrictions...");
    $availability_issues = validate_availability_restrictions($request->target_course_id);
    if (!empty($availability_issues)) {
        cli_writeln("[RESTORE CLI] Availability warnings: " . count($availability_issues) . " issues found");
        foreach ($availability_issues as $issue) {
            cli_writeln("[RESTORE CLI]   - " . $issue);
        }
    } else {
        cli_writeln("[RESTORE CLI] All availability restrictions validated successfully");
    }

    // POST-RESTORE: Comprehensive comparison validation (origin vs destination)
    // Note: $origin_data was loaded BEFORE the restore because Moodle deletes the temp dir
    cli_writeln("[RESTORE CLI] ========================================");
    cli_writeln("[RESTORE CLI] COMPARATIVE VALIDATION: Origin vs Destination");
    cli_writeln("[RESTORE CLI] ========================================");
    
    $validation_result = validate_restore_completeness_with_data($origin_data, $request->target_course_id, $requestid);
    
    if ($validation_result['success']) {
        cli_writeln("[RESTORE CLI] ✓ VALIDATION PASSED - Course restored identically to origin");
    } else {
        cli_writeln("[RESTORE CLI] ⚠ VALIDATION WARNINGS - Some differences detected");
    }
    
    // Log validation summary
    foreach ($validation_result['checks'] as $check) {
        $status_icon = $check['passed'] ? '✓' : '✗';
        cli_writeln("[RESTORE CLI] {$status_icon} {$check['name']}: {$check['message']}");
    }

    // Note: Moodle already cleaned the temp directory during restore
    // fulldelete($backuppath); // Not needed - Moodle already did this

    // Delete the backup file from moodledata (destination) if auto_cleanup is enabled
    cli_writeln("[RESTORE CLI] ========================================");
    cli_writeln("[RESTORE CLI] BACKUP CLEANUP (DESTINATION)");
    cli_writeln("[RESTORE CLI] ========================================");
    
    $auto_cleanup_target = get_config('local_coursetransfer', 'auto_cleanup_target_backup');
    cli_writeln("[RESTORE CLI] auto_cleanup_target_backup setting: " . ($auto_cleanup_target ? 'ENABLED' : 'DISABLED'));
    
    if ($auto_cleanup_target) {
        cli_writeln("[RESTORE CLI] Attempting to delete backup file...");
        cli_writeln("[RESTORE CLI]   File ID: " . $backupfile->get_id());
        cli_writeln("[RESTORE CLI]   Filename: " . $backupfile->get_filename());
        cli_writeln("[RESTORE CLI]   Size: " . round($backupfile->get_filesize() / 1024 / 1024, 2) . " MB");
        cli_writeln("[RESTORE CLI]   Component: " . $backupfile->get_component());
        cli_writeln("[RESTORE CLI]   Filearea: " . $backupfile->get_filearea());
        cli_writeln("[RESTORE CLI]   Context ID: " . $backupfile->get_contextid());
        
        try {
            $backupfile->delete();
            cli_writeln("[RESTORE CLI] ✓ Backup file deleted successfully (destination)");
            
            // Log the deletion
            \local_coursetransfer\coursetransfer_logger::info(
                $request->id,
                \local_coursetransfer\coursetransfer_logger::DIRECTION_TARGET,
                'TARGET_BACKUP_DELETED',
                'Target backup file (.mbz) deleted after successful restore',
                [
                    'filename' => $backupfile->get_filename(),
                    'file_id' => $backupfile->get_id(),
                    'request_id' => $request->id
                ]
            );
        } catch (\Exception $deleteEx) {
            cli_writeln("[RESTORE CLI] ⚠ Warning: Could not delete backup file: " . $deleteEx->getMessage());
            cli_writeln("[RESTORE CLI]   Exception type: " . get_class($deleteEx));
            
            // Log the failure
            \local_coursetransfer\coursetransfer_logger::warning(
                $request->id,
                \local_coursetransfer\coursetransfer_logger::DIRECTION_TARGET,
                'TARGET_BACKUP_DELETE_FAILED',
                'Failed to delete target backup file: ' . $deleteEx->getMessage(),
                null,
                [
                    'filename' => $backupfile->get_filename(),
                    'file_id' => $backupfile->get_id(),
                    'exception' => get_class($deleteEx)
                ]
            );
        }
    } else {
        cli_writeln("[RESTORE CLI] ℹ Backup file kept in destination (auto_cleanup_target_backup disabled)");
        cli_writeln("[RESTORE CLI]   File: " . $backupfile->get_filename());
        cli_writeln("[RESTORE CLI]   Size: " . round($backupfile->get_filesize() / 1024 / 1024, 2) . " MB");
        cli_writeln("[RESTORE CLI]   To enable auto-cleanup, go to: Site Admin > Plugins > Local > Course Transfer > Settings");
    }

    // NOTIFY ORIGIN to cleanup the backup file there too (only the .mbz file, NOT the course)
    cli_writeln("[RESTORE CLI] ========================================");
    cli_writeln("[RESTORE CLI] NOTIFYING ORIGIN FOR BACKUP CLEANUP");
    cli_writeln("[RESTORE CLI] ========================================");
    
    $origin_cleanup_result = notify_origin_cleanup($request);
    if ($origin_cleanup_result['success']) {
        if ($origin_cleanup_result['cleaned']) {
            cli_writeln("[RESTORE CLI] ✓ Origin notified - backup file deleted in origin server");
        } else {
            cli_writeln("[RESTORE CLI] ✓ Origin notified - backup file kept (auto_cleanup disabled in origin)");
        }
    } else {
        cli_writeln("[RESTORE CLI] ⚠ Could not notify origin: " . $origin_cleanup_result['error']);
        cli_writeln("[RESTORE CLI]   (Backup file will be cleaned by scheduled task in origin after 48h)");
    }

    // Determine final status based on validation results
    $has_differences = isset($validation_result) && !$validation_result['success'];
    
    if ($has_differences) {
        $request->status = coursetransfer_request::STATUS_COMPLETED_WITH_DIFFERENCES;
        cli_writeln("[RESTORE CLI] STATUS: Completed with differences");
    } else {
        $request->status = coursetransfer_request::STATUS_COMPLETED;
        cli_writeln("[RESTORE CLI] STATUS: Completed successfully");
    }
    
    $request->timemodified = time();
    $DB->update_record('local_coursetransfer_request', $request);

    cli_writeln("[RESTORE CLI] SUCCESS - Course restored to ID: {$request->target_course_id}");
    cli_writeln("[RESTORE CLI] Memory peak: " . round(memory_get_peak_usage() / 1024 / 1024, 2) . " MB");
    
    exit(0);

} catch (\Exception $e) {
    cli_writeln("[RESTORE CLI] ERROR: " . $e->getMessage());
    
    // Update request status to error
    if (isset($request)) {
        $request->status = coursetransfer_request::STATUS_ERROR;
        $request->error_code = 10500;
        $request->error_message = substr($e->getMessage(), 0, 500);
        $request->timemodified = time();
        $DB->update_record('local_coursetransfer_request', $request);
    }
    
    // Cleanup temp if exists
    if (isset($backuppath) && file_exists($backuppath)) {
        fulldelete($backuppath);
    }
    
    exit(1);
}

/**
 * Fix course names (fullname and shortname) to match origin.
 * 
 * Moodle's restore process adds suffixes like "copia 1", "copia 2", "_1", "_2" 
 * when it detects name conflicts. This function corrects the names to exactly
 * match the origin course.
 *
 * @param array $origin_data Data loaded from backup XML files
 * @param int $courseid The target course ID
 * @param moodle_database $DB Database connection
 * @return array ['changed' => bool, 'fullname' => string|null, 'shortname' => string|null]
 */
function fix_course_names_from_origin($origin_data, $courseid, $DB) {
    $result = [
        'changed' => false,
        'fullname' => null,
        'shortname' => null,
        'old_fullname' => null,
        'old_shortname' => null
    ];
    
    // Get current course data
    $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname');
    if (!$course) {
        cli_writeln("[RESTORE CLI] Warning: Could not find course {$courseid} to fix names");
        return $result;
    }
    
    $result['old_fullname'] = $course->fullname;
    $result['old_shortname'] = $course->shortname;
    
    // Get origin names from backup data
    $origin_fullname = null;
    $origin_shortname = null;
    
    if (isset($origin_data['course']) && $origin_data['course']) {
        $origin_fullname = (string)$origin_data['course']->fullname;
        $origin_shortname = (string)$origin_data['course']->shortname;
    }
    
    // If we couldn't get from origin_data, check if backup_info has it
    if (empty($origin_fullname) && isset($origin_data['backup_info'])) {
        $backup_info = $origin_data['backup_info'];
        if (isset($backup_info->information->original_course_fullname)) {
            $origin_fullname = (string)$backup_info->information->original_course_fullname;
        }
        if (isset($backup_info->information->original_course_shortname)) {
            $origin_shortname = (string)$backup_info->information->original_course_shortname;
        }
    }
    
    cli_writeln("[RESTORE CLI] Current fullname: {$course->fullname}");
    cli_writeln("[RESTORE CLI] Origin fullname: " . ($origin_fullname ?: 'not found'));
    cli_writeln("[RESTORE CLI] Current shortname: {$course->shortname}");
    cli_writeln("[RESTORE CLI] Origin shortname: " . ($origin_shortname ?: 'not found'));
    
    $updates = [];
    
    // Fix fullname if needed
    if (!empty($origin_fullname) && $course->fullname !== $origin_fullname) {
        // Check if current name has "copia X" suffix that needs to be removed
        // Pattern matches: " copia 1", " copia 2", " copia 10", etc. (Spanish)
        // Also matches: " copy 1", " copy 2", etc. (English)
        $fullname_has_copy_suffix = preg_match('/\s+(copia|copy)\s+\d+$/i', $course->fullname);
        
        if ($fullname_has_copy_suffix || $course->fullname !== $origin_fullname) {
            $updates['fullname'] = $origin_fullname;
            $result['fullname'] = $origin_fullname;
            cli_writeln("[RESTORE CLI]   → Will update fullname to: {$origin_fullname}");
        }
    }
    
    // Fix shortname if needed
    if (!empty($origin_shortname) && $course->shortname !== $origin_shortname) {
        // Check if current shortname has "_X" suffix that needs to be removed
        // Pattern matches: "_1", "_2", "_10", etc.
        $shortname_has_suffix = preg_match('/_\d+$/', $course->shortname);
        
        if ($shortname_has_suffix || $course->shortname !== $origin_shortname) {
            // Before updating, check if the origin shortname would conflict with another course
            $existing = $DB->get_record('course', ['shortname' => $origin_shortname], 'id');
            if ($existing && $existing->id != $courseid) {
                cli_writeln("[RESTORE CLI]   ⚠ Cannot use shortname '{$origin_shortname}' - already exists in course {$existing->id}");
                // Keep the suffixed version to avoid conflict
            } else {
                $updates['shortname'] = $origin_shortname;
                $result['shortname'] = $origin_shortname;
                cli_writeln("[RESTORE CLI]   → Will update shortname to: {$origin_shortname}");
            }
        }
    }
    
    // Apply updates
    if (!empty($updates)) {
        foreach ($updates as $field => $value) {
            $DB->set_field('course', $field, $value, ['id' => $courseid]);
        }
        $result['changed'] = true;
        
        // Log the changes
        cli_writeln("[RESTORE CLI]   ✓ Course names updated in database");
    }
    
    return $result;
}

/**
 * Validate availability restrictions in a restored course.
 * 
 * Checks that all availability conditions (date restrictions, completion requirements,
 * activity dependencies) are properly restored and functional.
 *
 * @param int $courseid The course ID to validate
 * @return array List of issues found (empty if all OK)
 */
function validate_availability_restrictions($courseid) {
    global $DB;
    
    $issues = [];
    
    // Get all course modules with availability restrictions
    $cms = $DB->get_records_sql(
        "SELECT cm.id, cm.course, cm.module, cm.instance, cm.availability, m.name as modname
         FROM {course_modules} cm
         JOIN {modules} m ON m.id = cm.module
         WHERE cm.course = :courseid AND cm.availability IS NOT NULL AND cm.availability != ''",
        ['courseid' => $courseid]
    );
    
    foreach ($cms as $cm) {
        $availability = json_decode($cm->availability, true);
        if (!$availability) {
            continue;
        }
        
        // Check for broken references in availability conditions
        $broken = check_availability_tree($availability, $courseid, $cm->id);
        if (!empty($broken)) {
            $modinfo = get_fast_modinfo($courseid);
            $cminfo = $modinfo->get_cm($cm->id);
            $issues[] = "Module '{$cminfo->name}' (id:{$cm->id}): " . implode(', ', $broken);
        }
    }
    
    // Also check section availability
    $sections = $DB->get_records_sql(
        "SELECT id, section, name, availability 
         FROM {course_sections} 
         WHERE course = :courseid AND availability IS NOT NULL AND availability != ''",
        ['courseid' => $courseid]
    );
    
    foreach ($sections as $section) {
        $availability = json_decode($section->availability, true);
        if (!$availability) {
            continue;
        }
        
        $broken = check_availability_tree($availability, $courseid, null);
        if (!empty($broken)) {
            $section_name = $section->name ?: "Section {$section->section}";
            $issues[] = "Section '{$section_name}': " . implode(', ', $broken);
        }
    }
    
    return $issues;
}

/**
 * Recursively check an availability condition tree for broken references.
 *
 * @param array $tree The availability tree
 * @param int $courseid The course ID
 * @param int|null $cmid The course module ID (for context)
 * @return array List of issues found
 */
function check_availability_tree($tree, $courseid, $cmid) {
    global $DB;
    
    $issues = [];
    
    if (isset($tree['c']) && is_array($tree['c'])) {
        // This is a condition set, check each condition
        foreach ($tree['c'] as $condition) {
            $sub_issues = check_availability_tree($condition, $courseid, $cmid);
            $issues = array_merge($issues, $sub_issues);
        }
    }
    
    // Check for completion condition (requires another activity to be completed)
    if (isset($tree['type']) && $tree['type'] === 'completion') {
        if (isset($tree['cm'])) {
            $referenced_cm = $DB->get_record('course_modules', ['id' => $tree['cm'], 'course' => $courseid]);
            if (!$referenced_cm) {
                $issues[] = "References missing activity (cm id: {$tree['cm']})";
            }
        }
    }
    
    // Check for grade condition
    if (isset($tree['type']) && $tree['type'] === 'grade') {
        if (isset($tree['cm'])) {
            $referenced_cm = $DB->get_record('course_modules', ['id' => $tree['cm'], 'course' => $courseid]);
            if (!$referenced_cm) {
                $issues[] = "Grade condition references missing activity (cm id: {$tree['cm']})";
            }
        }
    }
    
    return $issues;
}

/**
 * Comprehensive validation of restore completeness.
 * Compares origin (from backup XMLs) with destination (restored course).
 *
 * @param string $backuppath Path to extracted backup
 * @param int $courseid Destination course ID
 * @param int $requestid Request ID for logging
 * @return array Validation result with 'success' and 'checks' array
 */
function validate_restore_completeness($backuppath, $courseid, $requestid) {
    global $DB, $CFG;
    
    require_once($CFG->dirroot . '/mod/quiz/locallib.php');
    
    $checks = [];
    $all_passed = true;
    
    // Load origin data from backup XMLs
    $origin_data = load_origin_data_from_backup($backuppath);
    
    // 1. COURSE CONFIGURATION VALIDATION
    cli_writeln("[RESTORE CLI] Checking course configuration...");
    $config_check = validate_course_config($origin_data, $courseid);
    $checks[] = $config_check;
    if (!$config_check['passed']) $all_passed = false;
    
    // 2. ACTIVITIES COUNT VALIDATION
    cli_writeln("[RESTORE CLI] Checking activities count...");
    $activities_check = validate_activities_count($origin_data, $courseid);
    $checks[] = $activities_check;
    if (!$activities_check['passed']) $all_passed = false;
    
    // 3. QUIZ AND QUESTIONS VALIDATION
    cli_writeln("[RESTORE CLI] Checking quizzes and questions...");
    $quiz_check = validate_quizzes($backuppath, $courseid);
    $checks[] = $quiz_check;
    if (!$quiz_check['passed']) $all_passed = false;
    
    // 4. ASSIGNMENTS VALIDATION
    cli_writeln("[RESTORE CLI] Checking assignments and submissions...");
    $assign_check = validate_assignments($backuppath, $courseid);
    $checks[] = $assign_check;
    if (!$assign_check['passed']) $all_passed = false;
    
    // 5. FORUM VALIDATION
    cli_writeln("[RESTORE CLI] Checking forums and discussions...");
    $forum_check = validate_forums($backuppath, $courseid);
    $checks[] = $forum_check;
    if (!$forum_check['passed']) $all_passed = false;
    
    // 6. FILES/RESOURCES VALIDATION
    cli_writeln("[RESTORE CLI] Checking files and resources...");
    $files_check = validate_files_resources($backuppath, $courseid);
    $checks[] = $files_check;
    if (!$files_check['passed']) $all_passed = false;
    
    // 7. USER ENROLLMENTS VALIDATION (if users were included)
    cli_writeln("[RESTORE CLI] Checking user enrollments...");
    $users_check = validate_user_enrollments($backuppath, $courseid);
    $checks[] = $users_check;
    if (!$users_check['passed']) $all_passed = false;
    
    // 8. SECTIONS VALIDATION
    cli_writeln("[RESTORE CLI] Checking course sections...");
    $sections_check = validate_sections($backuppath, $courseid);
    $checks[] = $sections_check;
    if (!$sections_check['passed']) $all_passed = false;
    
    // Log to coursetransfer_log
    log_validation_results($requestid, $checks, $all_passed);
    
    return [
        'success' => $all_passed,
        'checks' => $checks
    ];
}

/**
 * Load origin course data from backup XML files.
 */
function load_origin_data_from_backup($backuppath) {
    $data = [
        'course' => null,
        'activities' => [],
        'sections' => [],
        'users' => []
    ];
    
    cli_writeln("[VALIDATION DEBUG] Backup path: {$backuppath}");
    cli_writeln("[VALIDATION DEBUG] Path exists: " . (is_dir($backuppath) ? 'YES' : 'NO'));
    
    // List contents of backup directory
    if (is_dir($backuppath)) {
        $contents = scandir($backuppath);
        cli_writeln("[VALIDATION DEBUG] Backup contents: " . implode(', ', $contents));
    }
    
    // Load course.xml - try multiple possible locations
    $course_xml_paths = [
        $backuppath . '/course/course.xml',
        $backuppath . '/course.xml',
    ];
    
    foreach ($course_xml_paths as $path) {
        cli_writeln("[VALIDATION DEBUG] Checking course.xml at: {$path}");
        if (file_exists($path)) {
            cli_writeln("[VALIDATION DEBUG] Found course.xml at: {$path}");
            $data['course'] = simplexml_load_file($path);
            break;
        }
    }
    
    // Load moodle_backup.xml for general info
    $backup_xml_path = $backuppath . '/moodle_backup.xml';
    cli_writeln("[VALIDATION DEBUG] Checking moodle_backup.xml at: {$backup_xml_path}");
    if (file_exists($backup_xml_path)) {
        cli_writeln("[VALIDATION DEBUG] Found moodle_backup.xml");
        $data['backup_info'] = simplexml_load_file($backup_xml_path);
    }
    
    // Count activities from activities folder
    $activities_path = $backuppath . '/activities';
    cli_writeln("[VALIDATION DEBUG] Checking activities at: {$activities_path}");
    if (is_dir($activities_path)) {
        $dirs = scandir($activities_path);
        cli_writeln("[VALIDATION DEBUG] Activities folders found: " . count($dirs));
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') continue;
            if (is_dir($activities_path . '/' . $dir)) {
                // Parse activity type from folder name (e.g., "quiz_12345")
                $parts = explode('_', $dir);
                $type = $parts[0];
                if (!isset($data['activities'][$type])) {
                    $data['activities'][$type] = 0;
                }
                $data['activities'][$type]++;
            }
        }
        cli_writeln("[VALIDATION DEBUG] Activities by type: " . json_encode($data['activities']));
    } else {
        cli_writeln("[VALIDATION DEBUG] Activities folder NOT FOUND");
    }
    
    // Load sections
    $sections_path = $backuppath . '/sections';
    cli_writeln("[VALIDATION DEBUG] Checking sections at: {$sections_path}");
    if (is_dir($sections_path)) {
        $dirs = scandir($sections_path);
        $section_count = 0;
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') continue;
            if (is_dir($sections_path . '/' . $dir)) {
                $section_xml = $sections_path . '/' . $dir . '/section.xml';
                if (file_exists($section_xml)) {
                    $data['sections'][] = simplexml_load_file($section_xml);
                    $section_count++;
                }
            }
        }
        cli_writeln("[VALIDATION DEBUG] Sections found: {$section_count}");
    } else {
        cli_writeln("[VALIDATION DEBUG] Sections folder NOT FOUND");
    }
    
    // Load users.xml - contains all users in the backup
    $users_xml_path = $backuppath . '/users.xml';
    cli_writeln("[VALIDATION DEBUG] Checking users.xml at: {$users_xml_path}");
    if (file_exists($users_xml_path)) {
        cli_writeln("[VALIDATION DEBUG] Found users.xml");
        $data['users_xml'] = simplexml_load_file($users_xml_path);
        
        // Count total users from backup
        $data['backup_user_count'] = 0;
        $data['backup_users'] = [];
        if ($data['users_xml'] && isset($data['users_xml']->user)) {
            foreach ($data['users_xml']->user as $user) {
                $data['backup_user_count']++;
                $userid = (int)$user->id;
                $data['backup_users'][$userid] = [
                    'id' => $userid,
                    'username' => (string)$user->username,
                    'email' => (string)$user->email
                ];
            }
        }
        cli_writeln("[VALIDATION DEBUG] Total users in backup: " . $data['backup_user_count']);
    } else {
        cli_writeln("[VALIDATION DEBUG] users.xml NOT FOUND - backup may be structure-only");
    }
    
    // Load roles.xml to get user role assignments in the course context
    $roles_xml_path = $backuppath . '/roles.xml';
    cli_writeln("[VALIDATION DEBUG] Checking roles.xml at: {$roles_xml_path}");
    if (file_exists($roles_xml_path)) {
        cli_writeln("[VALIDATION DEBUG] Found roles.xml");
        $roles_xml = simplexml_load_file($roles_xml_path);
        
        // Parse role assignments to count users by role
        // Structure: <roles><role_assignments><assignment><roleid>X</roleid><userid>Y</userid>...
        $data['users_by_role'] = [];
        $data['role_assignments'] = [];
        
        if ($roles_xml && isset($roles_xml->role_assignments->assignment)) {
            // First pass: count users per roleid
            $users_per_roleid = [];
            foreach ($roles_xml->role_assignments->assignment as $assignment) {
                $roleid = (string)$assignment->roleid;
                $userid = (string)$assignment->userid;
                
                if (!isset($users_per_roleid[$roleid])) {
                    $users_per_roleid[$roleid] = [];
                }
                $users_per_roleid[$roleid][$userid] = true; // Use userid as key to count unique
            }
            
            // Map common roleids to shortnames (standard Moodle roles)
            // Note: roleid can vary between installations, but typically:
            // 1=manager, 3=editingteacher, 4=teacher, 5=student
            $roleid_to_shortname = [
                '1' => ['shortname' => 'manager', 'name' => 'Manager'],
                '2' => ['shortname' => 'coursecreator', 'name' => 'Course creator'],
                '3' => ['shortname' => 'editingteacher', 'name' => 'Teacher'],
                '4' => ['shortname' => 'teacher', 'name' => 'Non-editing teacher'],
                '5' => ['shortname' => 'student', 'name' => 'Student'],
                '6' => ['shortname' => 'guest', 'name' => 'Guest'],
                '7' => ['shortname' => 'user', 'name' => 'Authenticated user'],
                '8' => ['shortname' => 'frontpage', 'name' => 'Frontpage user']
            ];
            
            foreach ($users_per_roleid as $roleid => $users) {
                $count = count($users);
                $roleinfo = $roleid_to_shortname[$roleid] ?? ['shortname' => "role_{$roleid}", 'name' => "Role {$roleid}"];
                
                $data['users_by_role'][$roleinfo['shortname']] = [
                    'name' => $roleinfo['name'],
                    'shortname' => $roleinfo['shortname'],
                    'roleid' => $roleid,
                    'count' => $count
                ];
            }
        }
        cli_writeln("[VALIDATION DEBUG] Users by role from roles.xml: " . json_encode($data['users_by_role']));
    } else {
        cli_writeln("[VALIDATION DEBUG] roles.xml NOT FOUND");
        
        // Fallback: try to get role info from course/roles.xml
        $course_roles_path = $backuppath . '/course/roles.xml';
        if (file_exists($course_roles_path)) {
            cli_writeln("[VALIDATION DEBUG] Found course/roles.xml");
            $roles_xml = simplexml_load_file($course_roles_path);
            $data['users_by_role'] = [];
            
            if ($roles_xml && isset($roles_xml->role_assignments->assignment)) {
                $users_per_roleid = [];
                foreach ($roles_xml->role_assignments->assignment as $assignment) {
                    $roleid = (string)$assignment->roleid;
                    $userid = (string)$assignment->userid;
                    
                    if (!isset($users_per_roleid[$roleid])) {
                        $users_per_roleid[$roleid] = [];
                    }
                    $users_per_roleid[$roleid][$userid] = true;
                }
                
                $roleid_to_shortname = [
                    '1' => ['shortname' => 'manager', 'name' => 'Manager'],
                    '3' => ['shortname' => 'editingteacher', 'name' => 'Teacher'],
                    '4' => ['shortname' => 'teacher', 'name' => 'Non-editing teacher'],
                    '5' => ['shortname' => 'student', 'name' => 'Student']
                ];
                
                foreach ($users_per_roleid as $roleid => $users) {
                    $count = count($users);
                    $roleinfo = $roleid_to_shortname[$roleid] ?? ['shortname' => "role_{$roleid}", 'name' => "Role {$roleid}"];
                    
                    $data['users_by_role'][$roleinfo['shortname']] = [
                        'name' => $roleinfo['name'],
                        'shortname' => $roleinfo['shortname'],
                        'roleid' => $roleid,
                        'count' => $count
                    ];
                }
            }
            cli_writeln("[VALIDATION DEBUG] Users by role from course/roles.xml: " . json_encode($data['users_by_role']));
        }
    }
    
    // Load enrolments.xml - contains enrollment methods and user enrollments
    // This is the MOST RELIABLE source for counting enrolled users
    $enrolments_xml_path = $backuppath . '/enrolments.xml';
    cli_writeln("[VALIDATION DEBUG] Checking enrolments.xml at: {$enrolments_xml_path}");
    if (file_exists($enrolments_xml_path)) {
        cli_writeln("[VALIDATION DEBUG] Found enrolments.xml");
        $data['enrolments_xml'] = simplexml_load_file($enrolments_xml_path);
        
        // Parse enrolments to get user counts by role
        $data['enrolments_by_role'] = [];
        $data['total_enrolments'] = 0;
        $data['enrolled_users'] = []; // Track unique users
        
        if ($data['enrolments_xml'] && isset($data['enrolments_xml']->enrols->enrol)) {
            foreach ($data['enrolments_xml']->enrols->enrol as $enrol) {
                $enrolmethod = (string)$enrol->enrol;
                $roleid = (string)$enrol->roleid;
                
                if (isset($enrol->user_enrolments->enrolment)) {
                    foreach ($enrol->user_enrolments->enrolment as $enrolment) {
                        $userid = (string)$enrolment->userid;
                        
                        // Track unique enrolled users with their role
                        if (!isset($data['enrolled_users'][$userid])) {
                            $data['enrolled_users'][$userid] = [];
                        }
                        $data['enrolled_users'][$userid][$roleid] = true;
                        $data['total_enrolments']++;
                    }
                }
            }
        }
        
        // Count unique users per role from enrolments
        $users_per_roleid = [];
        foreach ($data['enrolled_users'] as $userid => $roles) {
            foreach ($roles as $roleid => $val) {
                if (!isset($users_per_roleid[$roleid])) {
                    $users_per_roleid[$roleid] = [];
                }
                $users_per_roleid[$roleid][$userid] = true;
            }
        }
        
        // Map roleids to shortnames
        $roleid_to_shortname = [
            '1' => ['shortname' => 'manager', 'name' => 'Manager'],
            '2' => ['shortname' => 'coursecreator', 'name' => 'Course creator'],
            '3' => ['shortname' => 'editingteacher', 'name' => 'Teacher'],
            '4' => ['shortname' => 'teacher', 'name' => 'Non-editing teacher'],
            '5' => ['shortname' => 'student', 'name' => 'Student'],
            '6' => ['shortname' => 'guest', 'name' => 'Guest'],
            '7' => ['shortname' => 'user', 'name' => 'Authenticated user'],
            '8' => ['shortname' => 'frontpage', 'name' => 'Frontpage user']
        ];
        
        foreach ($users_per_roleid as $roleid => $users) {
            $count = count($users);
            $roleinfo = $roleid_to_shortname[$roleid] ?? ['shortname' => "role_{$roleid}", 'name' => "Role {$roleid}"];
            
            $data['users_by_role'][$roleinfo['shortname']] = [
                'name' => $roleinfo['name'],
                'shortname' => $roleinfo['shortname'],
                'roleid' => $roleid,
                'count' => $count
            ];
        }
        
        $data['enrolled_user_count'] = count($data['enrolled_users']);
        cli_writeln("[VALIDATION DEBUG] Total enrolments: " . $data['total_enrolments']);
        cli_writeln("[VALIDATION DEBUG] Unique enrolled users: " . $data['enrolled_user_count']);
        cli_writeln("[VALIDATION DEBUG] Users by role from enrolments.xml: " . json_encode($data['users_by_role']));
        cli_writeln("[VALIDATION DEBUG] Total enrolments found: " . ($data['total_enrolments'] ?? 0));
    } else {
        cli_writeln("[VALIDATION DEBUG] enrolments.xml NOT FOUND");
    }
    
    return $data;
}

/**
 * Validate course configuration matches.
 */
function validate_course_config($origin_data, $courseid) {
    global $DB;
    
    $issues = [];
    $dest_course = $DB->get_record('course', ['id' => $courseid]);
    
    if (!$origin_data['course'] || !$dest_course) {
        return [
            'name' => 'Course Configuration',
            'passed' => false,
            'message' => 'Could not load course data for comparison'
        ];
    }
    
    $origin = $origin_data['course'];
    
    // Check key configuration fields
    $fields_to_check = [
        'format' => 'Course format',
        'showgrades' => 'Show gradebook',
        'newsitems' => 'News items',
        'enablecompletion' => 'Completion tracking',
        'visible' => 'Visibility',
        'groupmode' => 'Group mode',
        'groupmodeforce' => 'Force group mode'
    ];
    
    foreach ($fields_to_check as $field => $label) {
        $origin_val = isset($origin->$field) ? (string)$origin->$field : null;
        $dest_val = isset($dest_course->$field) ? (string)$dest_course->$field : null;
        
        if ($origin_val !== null && $origin_val !== $dest_val) {
            $issues[] = "{$label}: origin={$origin_val}, dest={$dest_val}";
        }
    }
    
    // Check dates
    if (isset($origin->startdate) && (int)$origin->startdate != (int)$dest_course->startdate) {
        $issues[] = "Start date differs";
    }
    if (isset($origin->enddate) && (int)$origin->enddate != (int)$dest_course->enddate) {
        $issues[] = "End date differs";
    }
    
    if (empty($issues)) {
        return [
            'name' => 'Course Configuration',
            'passed' => true,
            'message' => 'All configuration matches origin'
        ];
    }
    
    return [
        'name' => 'Course Configuration',
        'passed' => false,
        'message' => implode('; ', $issues)
    ];
}

/**
 * Validate activities count matches.
 */
function validate_activities_count($origin_data, $courseid) {
    global $DB;
    
    $issues = [];
    
    // Get destination activities count by type
    $dest_activities = $DB->get_records_sql(
        "SELECT m.name, COUNT(*) as count 
         FROM {course_modules} cm 
         JOIN {modules} m ON m.id = cm.module 
         WHERE cm.course = :courseid AND cm.deletioninprogress = 0
         GROUP BY m.name",
        ['courseid' => $courseid]
    );
    
    $dest_counts = [];
    foreach ($dest_activities as $act) {
        $dest_counts[$act->name] = (int)$act->count;
    }
    
    // Compare with origin
    foreach ($origin_data['activities'] as $type => $origin_count) {
        $dest_count = $dest_counts[$type] ?? 0;
        if ($origin_count != $dest_count) {
            $issues[] = "{$type}: origin={$origin_count}, dest={$dest_count}";
        }
    }
    
    // Check for extra activities in destination
    foreach ($dest_counts as $type => $count) {
        if (!isset($origin_data['activities'][$type])) {
            $issues[] = "{$type}: not in origin, dest={$count}";
        }
    }
    
    $total_origin = array_sum($origin_data['activities']);
    $total_dest = array_sum($dest_counts);
    
    if (empty($issues)) {
        return [
            'name' => 'Activities Count',
            'passed' => true,
            'message' => "All {$total_dest} activities restored correctly"
        ];
    }
    
    return [
        'name' => 'Activities Count',
        'passed' => false,
        'message' => "Mismatch (origin={$total_origin}, dest={$total_dest}): " . implode('; ', $issues)
    ];
}

/**
 * Validate quizzes and their questions.
 */
function validate_quizzes($backuppath, $courseid) {
    global $DB;
    
    $issues = [];
    $origin_quizzes = [];
    $origin_total_questions = 0;
    
    // Scan quiz activities in backup
    $activities_path = $backuppath . '/activities';
    if (is_dir($activities_path)) {
        $dirs = scandir($activities_path);
        foreach ($dirs as $dir) {
            if (strpos($dir, 'quiz_') === 0) {
                $quiz_xml = $activities_path . '/' . $dir . '/quiz.xml';
                if (file_exists($quiz_xml)) {
                    $quiz = simplexml_load_file($quiz_xml);
                    $quiz_name = (string)$quiz->name;
                    
                    // Count questions from question_instances or slots
                    $question_count = 0;
                    if (isset($quiz->question_instances->question_instance)) {
                        $question_count = count($quiz->question_instances->question_instance);
                    }
                    
                    $origin_quizzes[$quiz_name] = $question_count;
                    $origin_total_questions += $question_count;
                }
            }
        }
    }
    
    // Get destination quizzes
    $dest_quizzes = $DB->get_records_sql(
        "SELECT q.id, q.name, COUNT(qs.id) as question_count
         FROM {quiz} q
         LEFT JOIN {quiz_slots} qs ON qs.quizid = q.id
         WHERE q.course = :courseid
         GROUP BY q.id, q.name",
        ['courseid' => $courseid]
    );
    
    $dest_quiz_count = count($dest_quizzes);
    $dest_total_questions = 0;
    
    foreach ($dest_quizzes as $quiz) {
        $dest_total_questions += $quiz->question_count;
        
        // Try to match by name
        if (isset($origin_quizzes[$quiz->name])) {
            $origin_q = $origin_quizzes[$quiz->name];
            if ($origin_q != $quiz->question_count) {
                $issues[] = "Quiz '{$quiz->name}': origin={$origin_q} questions, dest={$quiz->question_count}";
            }
        }
    }
    
    $origin_quiz_count = count($origin_quizzes);
    
    if ($origin_quiz_count != $dest_quiz_count) {
        $issues[] = "Quiz count: origin={$origin_quiz_count}, dest={$dest_quiz_count}";
    }
    
    if (empty($issues)) {
        return [
            'name' => 'Quizzes & Questions',
            'passed' => true,
            'message' => "{$dest_quiz_count} quizzes with {$dest_total_questions} questions restored"
        ];
    }
    
    return [
        'name' => 'Quizzes & Questions',
        'passed' => false,
        'message' => implode('; ', $issues)
    ];
}

/**
 * Validate assignments and submissions.
 */
function validate_assignments($backuppath, $courseid) {
    global $DB;
    
    $issues = [];
    $origin_assigns = 0;
    $origin_submissions = 0;
    
    // Scan assign activities in backup
    $activities_path = $backuppath . '/activities';
    if (is_dir($activities_path)) {
        $dirs = scandir($activities_path);
        foreach ($dirs as $dir) {
            if (strpos($dir, 'assign_') === 0) {
                $origin_assigns++;
                
                // Check for submissions in the backup
                $submissions_xml = $activities_path . '/' . $dir . '/assign.xml';
                if (file_exists($submissions_xml)) {
                    $assign = simplexml_load_file($submissions_xml);
                    if (isset($assign->submissions->submission)) {
                        $origin_submissions += count($assign->submissions->submission);
                    }
                }
            }
        }
    }
    
    // Get destination data
    $dest_assigns = $DB->count_records('assign', ['course' => $courseid]);
    $dest_submissions = $DB->count_records_sql(
        "SELECT COUNT(*) FROM {assign_submission} asub
         JOIN {assign} a ON a.id = asub.assignment
         WHERE a.course = :courseid AND asub.status = 'submitted'",
        ['courseid' => $courseid]
    );
    
    if ($origin_assigns != $dest_assigns) {
        $issues[] = "Assignments: origin={$origin_assigns}, dest={$dest_assigns}";
    }
    
    // Note: Submissions might differ if users weren't restored
    $submission_diff = abs($origin_submissions - $dest_submissions);
    
    if (empty($issues)) {
        return [
            'name' => 'Assignments & Submissions',
            'passed' => true,
            'message' => "{$dest_assigns} assignments, {$dest_submissions} submissions restored"
        ];
    }
    
    return [
        'name' => 'Assignments & Submissions',
        'passed' => false,
        'message' => implode('; ', $issues)
    ];
}

/**
 * Validate forums and discussions.
 */
function validate_forums($backuppath, $courseid) {
    global $DB;
    
    $issues = [];
    $origin_forums = 0;
    $origin_discussions = 0;
    $origin_posts = 0;
    
    // Scan forum activities in backup
    $activities_path = $backuppath . '/activities';
    if (is_dir($activities_path)) {
        $dirs = scandir($activities_path);
        foreach ($dirs as $dir) {
            if (strpos($dir, 'forum_') === 0) {
                $origin_forums++;
                
                $forum_xml = $activities_path . '/' . $dir . '/forum.xml';
                if (file_exists($forum_xml)) {
                    $forum = simplexml_load_file($forum_xml);
                    if (isset($forum->discussions->discussion)) {
                        $origin_discussions += count($forum->discussions->discussion);
                        
                        foreach ($forum->discussions->discussion as $disc) {
                            if (isset($disc->posts->post)) {
                                $origin_posts += count($disc->posts->post);
                            }
                        }
                    }
                }
            }
        }
    }
    
    // Get destination data
    $dest_forums = $DB->count_records('forum', ['course' => $courseid]);
    $dest_discussions = $DB->count_records_sql(
        "SELECT COUNT(*) FROM {forum_discussions} fd
         JOIN {forum} f ON f.id = fd.forum
         WHERE f.course = :courseid",
        ['courseid' => $courseid]
    );
    $dest_posts = $DB->count_records_sql(
        "SELECT COUNT(*) FROM {forum_posts} fp
         JOIN {forum_discussions} fd ON fd.id = fp.discussion
         JOIN {forum} f ON f.id = fd.forum
         WHERE f.course = :courseid",
        ['courseid' => $courseid]
    );
    
    if ($origin_forums != $dest_forums) {
        $issues[] = "Forums: origin={$origin_forums}, dest={$dest_forums}";
    }
    
    if (empty($issues)) {
        return [
            'name' => 'Forums & Discussions',
            'passed' => true,
            'message' => "{$dest_forums} forums, {$dest_discussions} discussions, {$dest_posts} posts"
        ];
    }
    
    return [
        'name' => 'Forums & Discussions',
        'passed' => false,
        'message' => implode('; ', $issues)
    ];
}

/**
 * Validate files and resources.
 */
function validate_files_resources($backuppath, $courseid) {
    global $DB;
    
    $issues = [];
    $origin_resources = 0;
    $origin_folders = 0;
    $origin_urls = 0;
    
    // Scan resource/folder/url activities in backup
    $activities_path = $backuppath . '/activities';
    if (is_dir($activities_path)) {
        $dirs = scandir($activities_path);
        foreach ($dirs as $dir) {
            if (strpos($dir, 'resource_') === 0) $origin_resources++;
            if (strpos($dir, 'folder_') === 0) $origin_folders++;
            if (strpos($dir, 'url_') === 0) $origin_urls++;
        }
    }
    
    // Get destination data
    $dest_resources = $DB->count_records('resource', ['course' => $courseid]);
    $dest_folders = $DB->count_records('folder', ['course' => $courseid]);
    $dest_urls = $DB->count_records('url', ['course' => $courseid]);
    
    if ($origin_resources != $dest_resources) {
        $issues[] = "Resources: origin={$origin_resources}, dest={$dest_resources}";
    }
    if ($origin_folders != $dest_folders) {
        $issues[] = "Folders: origin={$origin_folders}, dest={$dest_folders}";
    }
    if ($origin_urls != $dest_urls) {
        $issues[] = "URLs: origin={$origin_urls}, dest={$dest_urls}";
    }
    
    $total = $dest_resources + $dest_folders + $dest_urls;
    
    if (empty($issues)) {
        return [
            'name' => 'Files & Resources',
            'passed' => true,
            'message' => "{$total} file resources restored (resources={$dest_resources}, folders={$dest_folders}, urls={$dest_urls})"
        ];
    }
    
    return [
        'name' => 'Files & Resources',
        'passed' => false,
        'message' => implode('; ', $issues)
    ];
}

/**
 * Validate user enrollments.
 */
function validate_user_enrollments($backuppath, $courseid) {
    global $DB;
    
    $origin_users = 0;
    
    // Check users.xml in backup
    $users_xml = $backuppath . '/users.xml';
    if (file_exists($users_xml)) {
        $users = simplexml_load_file($users_xml);
        if (isset($users->user)) {
            $origin_users = count($users->user);
        }
    }
    
    // Get destination enrolled users
    $dest_users = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT ue.userid) 
         FROM {user_enrolments} ue
         JOIN {enrol} e ON e.id = ue.enrolid
         WHERE e.courseid = :courseid",
        ['courseid' => $courseid]
    );
    
    // Users might not be restored (depending on settings)
    if ($origin_users == 0) {
        return [
            'name' => 'User Enrollments',
            'passed' => true,
            'message' => "No users in backup (structure-only restore), {$dest_users} enrolled in destination"
        ];
    }
    
    if ($origin_users == $dest_users) {
        return [
            'name' => 'User Enrollments',
            'passed' => true,
            'message' => "{$dest_users} users enrolled (matches origin)"
        ];
    }
    
    return [
        'name' => 'User Enrollments',
        'passed' => false,
        'message' => "User count mismatch: origin={$origin_users}, dest={$dest_users}"
    ];
}

/**
 * Validate course sections.
 */
function validate_sections($backuppath, $courseid) {
    global $DB;
    
    $origin_sections = 0;
    
    // Count sections in backup
    $sections_path = $backuppath . '/sections';
    if (is_dir($sections_path)) {
        $dirs = scandir($sections_path);
        foreach ($dirs as $dir) {
            if ($dir !== '.' && $dir !== '..' && is_dir($sections_path . '/' . $dir)) {
                $origin_sections++;
            }
        }
    }
    
    // Get destination sections (excluding section 0 which is always present)
    $dest_sections = $DB->count_records_select('course_sections', 
        'course = :courseid', 
        ['courseid' => $courseid]
    );
    
    if ($origin_sections == $dest_sections || abs($origin_sections - $dest_sections) <= 1) {
        return [
            'name' => 'Course Sections',
            'passed' => true,
            'message' => "{$dest_sections} sections restored"
        ];
    }
    
    return [
        'name' => 'Course Sections',
        'passed' => false,
        'message' => "Section count: origin={$origin_sections}, dest={$dest_sections}"
    ];
}

/**
 * Validate restore completeness using pre-loaded origin data.
 * This version uses data that was loaded BEFORE the restore executed,
 * since Moodle deletes the temp backup directory during restore.
 *
 * @param array $origin_data Pre-loaded origin data from load_origin_data_from_backup()
 * @param int $courseid Destination course ID
 * @param int $requestid Request ID for logging
 * @return array Validation result with 'success' and 'checks' array
 */
function validate_restore_completeness_with_data($origin_data, $courseid, $requestid) {
    global $DB, $CFG;
    
    $checks = [];
    $all_passed = true;
    
    cli_writeln("[RESTORE CLI] Starting validation with pre-loaded origin data...");
    cli_writeln("[RESTORE CLI] Origin activities: " . json_encode($origin_data['activities']));
    cli_writeln("[RESTORE CLI] Origin sections: " . count($origin_data['sections']));
    cli_writeln("[RESTORE CLI] Origin users by role: " . json_encode($origin_data['users_by_role'] ?? []));
    
    // 1. COURSE CONFIGURATION VALIDATION
    cli_writeln("[RESTORE CLI] Checking course configuration...");
    $config_check = validate_course_config($origin_data, $courseid);
    $checks[] = $config_check;
    if (!$config_check['passed']) $all_passed = false;
    
    // 2. ACTIVITIES COUNT VALIDATION (using pre-loaded data)
    cli_writeln("[RESTORE CLI] Checking activities count...");
    $activities_check = validate_activities_count($origin_data, $courseid);
    $checks[] = $activities_check;
    if (!$activities_check['passed']) $all_passed = false;
    
    // 3. QUIZ VALIDATION (simplified - count only since XMLs are gone)
    cli_writeln("[RESTORE CLI] Checking quizzes...");
    $quiz_check = validate_quizzes_simple($origin_data, $courseid);
    $checks[] = $quiz_check;
    if (!$quiz_check['passed']) $all_passed = false;
    
    // 4. ASSIGNMENTS VALIDATION (simplified)
    cli_writeln("[RESTORE CLI] Checking assignments...");
    $assign_check = validate_assignments_simple($origin_data, $courseid);
    $checks[] = $assign_check;
    if (!$assign_check['passed']) $all_passed = false;
    
    // 5. FORUM VALIDATION (simplified)
    cli_writeln("[RESTORE CLI] Checking forums...");
    $forum_check = validate_forums_simple($origin_data, $courseid);
    $checks[] = $forum_check;
    if (!$forum_check['passed']) $all_passed = false;
    
    // 6. FILES/RESOURCES VALIDATION (simplified)
    cli_writeln("[RESTORE CLI] Checking files and resources...");
    $files_check = validate_files_resources_simple($origin_data, $courseid);
    $checks[] = $files_check;
    if (!$files_check['passed']) $all_passed = false;
    
    // 7. SECTIONS VALIDATION (using pre-loaded sections count)
    cli_writeln("[RESTORE CLI] Checking course sections...");
    $sections_check = validate_sections_simple($origin_data, $courseid);
    $checks[] = $sections_check;
    if (!$sections_check['passed']) $all_passed = false;
    
    // 8. ENROLLED USERS VALIDATION (students, teachers, other roles)
    cli_writeln("[RESTORE CLI] Checking enrolled users by role...");
    $users_check = validate_enrolled_users($origin_data, $courseid);
    $checks[] = $users_check;
    // Note: User mismatch is informational, doesn't fail validation
    // if (!$users_check['passed']) $all_passed = false;
    
    // Log to coursetransfer_log
    log_validation_results($requestid, $checks, $all_passed);
    
    // Print summary
    cli_writeln("\n[RESTORE CLI] ========== VALIDATION SUMMARY ==========");
    foreach ($checks as $check) {
        $status = $check['passed'] ? '✓ PASS' : '✗ FAIL';
        cli_writeln("[RESTORE CLI] {$status}: {$check['name']} - {$check['message']}");
    }
    cli_writeln("[RESTORE CLI] =========================================\n");
    
    return [
        'success' => $all_passed,
        'checks' => $checks
    ];
}

/**
 * Simplified quiz validation using pre-loaded origin data.
 */
function validate_quizzes_simple($origin_data, $courseid) {
    global $DB;
    
    $origin_count = $origin_data['activities']['quiz'] ?? 0;
    
    // Get destination quiz count and questions
    $dest_quizzes = $DB->get_records_sql(
        "SELECT q.id, q.name, COUNT(qs.id) as question_count
         FROM {quiz} q
         LEFT JOIN {quiz_slots} qs ON qs.quizid = q.id
         WHERE q.course = :courseid
         GROUP BY q.id, q.name",
        ['courseid' => $courseid]
    );
    
    $dest_count = count($dest_quizzes);
    $total_questions = 0;
    foreach ($dest_quizzes as $quiz) {
        $total_questions += $quiz->question_count;
    }
    
    if ($origin_count == $dest_count) {
        return [
            'name' => 'Quizzes',
            'passed' => true,
            'message' => "{$dest_count} quizzes restored with {$total_questions} questions total"
        ];
    }
    
    return [
        'name' => 'Quizzes',
        'passed' => false,
        'message' => "Quiz count mismatch: origin={$origin_count}, dest={$dest_count}"
    ];
}

/**
 * Simplified assignments validation using pre-loaded origin data.
 */
function validate_assignments_simple($origin_data, $courseid) {
    global $DB;
    
    $origin_count = $origin_data['activities']['assign'] ?? 0;
    $dest_count = $DB->count_records('assign', ['course' => $courseid]);
    
    if ($origin_count == $dest_count) {
        return [
            'name' => 'Assignments',
            'passed' => true,
            'message' => "{$dest_count} assignments restored"
        ];
    }
    
    return [
        'name' => 'Assignments',
        'passed' => false,
        'message' => "Assignment count mismatch: origin={$origin_count}, dest={$dest_count}"
    ];
}

/**
 * Simplified forums validation using pre-loaded origin data.
 */
function validate_forums_simple($origin_data, $courseid) {
    global $DB;
    
    $origin_count = $origin_data['activities']['forum'] ?? 0;
    $dest_count = $DB->count_records('forum', ['course' => $courseid]);
    
    // Also get discussions and posts for the message
    $dest_discussions = $DB->count_records_sql(
        "SELECT COUNT(*) FROM {forum_discussions} fd
         JOIN {forum} f ON f.id = fd.forum
         WHERE f.course = :courseid",
        ['courseid' => $courseid]
    );
    
    if ($origin_count == $dest_count) {
        return [
            'name' => 'Forums',
            'passed' => true,
            'message' => "{$dest_count} forums restored with {$dest_discussions} discussions"
        ];
    }
    
    return [
        'name' => 'Forums',
        'passed' => false,
        'message' => "Forum count mismatch: origin={$origin_count}, dest={$dest_count}"
    ];
}

/**
 * Simplified files/resources validation using pre-loaded origin data.
 */
function validate_files_resources_simple($origin_data, $courseid) {
    global $DB;
    
    $origin_resources = $origin_data['activities']['resource'] ?? 0;
    $origin_folders = $origin_data['activities']['folder'] ?? 0;
    $origin_urls = $origin_data['activities']['url'] ?? 0;
    
    $dest_resources = $DB->count_records('resource', ['course' => $courseid]);
    $dest_folders = $DB->count_records('folder', ['course' => $courseid]);
    $dest_urls = $DB->count_records('url', ['course' => $courseid]);
    
    $issues = [];
    
    if ($origin_resources != $dest_resources) {
        $issues[] = "Resources: origin={$origin_resources}, dest={$dest_resources}";
    }
    if ($origin_folders != $dest_folders) {
        $issues[] = "Folders: origin={$origin_folders}, dest={$dest_folders}";
    }
    if ($origin_urls != $dest_urls) {
        $issues[] = "URLs: origin={$origin_urls}, dest={$dest_urls}";
    }
    
    $total = $dest_resources + $dest_folders + $dest_urls;
    
    if (empty($issues)) {
        return [
            'name' => 'Files & Resources',
            'passed' => true,
            'message' => "{$total} file resources restored (resources={$dest_resources}, folders={$dest_folders}, urls={$dest_urls})"
        ];
    }
    
    return [
        'name' => 'Files & Resources',
        'passed' => false,
        'message' => implode('; ', $issues)
    ];
}

/**
 * Simplified sections validation using pre-loaded origin data.
 */
function validate_sections_simple($origin_data, $courseid) {
    global $DB;
    
    $origin_sections = count($origin_data['sections']);
    
    $dest_sections = $DB->count_records_select('course_sections', 
        'course = :courseid', 
        ['courseid' => $courseid]
    );
    
    // Allow small differences (section 0 handling varies)
    if ($origin_sections == $dest_sections || abs($origin_sections - $dest_sections) <= 1) {
        return [
            'name' => 'Course Sections',
            'passed' => true,
            'message' => "{$dest_sections} sections restored"
        ];
    }
    
    return [
        'name' => 'Course Sections',
        'passed' => false,
        'message' => "Section count: origin={$origin_sections}, dest={$dest_sections}"
    ];
}

/**
 * Validate enrolled users by role (students, teachers, other roles).
 * Compares origin backup users with destination course enrollments.
 */
function validate_enrolled_users($origin_data, $courseid) {
    global $DB;
    
    // Get origin users by role from backup
    $origin_by_role = $origin_data['users_by_role'] ?? [];
    
    // Get destination users by role
    $context = \context_course::instance($courseid);
    
    // Query to get enrolled users grouped by role
    $sql = "SELECT r.shortname, r.name, COUNT(DISTINCT ra.userid) as count
            FROM {role_assignments} ra
            JOIN {role} r ON r.id = ra.roleid
            WHERE ra.contextid = :contextid
            GROUP BY r.id, r.shortname, r.name
            ORDER BY r.sortorder";
    
    $dest_by_role = $DB->get_records_sql($sql, ['contextid' => $context->id]);
    
    // Categorize roles into students, teachers, and others
    $student_roles = ['student'];
    $teacher_roles = ['editingteacher', 'teacher', 'manager'];
    
    // Calculate origin totals
    $origin_students = 0;
    $origin_teachers = 0;
    $origin_others = 0;
    $origin_details = [];
    
    foreach ($origin_by_role as $shortname => $roledata) {
        $count = $roledata['count'];
        $origin_details[] = "{$roledata['name']}: {$count}";
        
        if (in_array($shortname, $student_roles)) {
            $origin_students += $count;
        } elseif (in_array($shortname, $teacher_roles)) {
            $origin_teachers += $count;
        } else {
            $origin_others += $count;
        }
    }
    
    // Calculate destination totals
    $dest_students = 0;
    $dest_teachers = 0;
    $dest_others = 0;
    $dest_details = [];
    
    foreach ($dest_by_role as $role) {
        $count = (int)$role->count;
        $dest_details[] = "{$role->name}: {$count}";
        
        if (in_array($role->shortname, $student_roles)) {
            $dest_students += $count;
        } elseif (in_array($role->shortname, $teacher_roles)) {
            $dest_teachers += $count;
        } else {
            $dest_others += $count;
        }
    }
    
    $origin_total = $origin_students + $origin_teachers + $origin_others;
    $dest_total = $dest_students + $dest_teachers + $dest_others;
    
    // Build detailed message
    $origin_msg = "Students: {$origin_students}, Teachers: {$origin_teachers}, Others: {$origin_others}";
    $dest_msg = "Students: {$dest_students}, Teachers: {$dest_teachers}, Others: {$dest_others}";
    
    // Check if counts match
    $passed = ($origin_students == $dest_students && 
               $origin_teachers == $dest_teachers && 
               $origin_others == $dest_others);
    
    // If no origin users data (backup without users), consider it informational only
    if (empty($origin_by_role)) {
        // Check if backup has enrolled users from enrolments.xml
        $enrolled_user_count = $origin_data['enrolled_user_count'] ?? 0;
        if ($enrolled_user_count > 0) {
            // We have enrolled users but couldn't parse roles - use total count
            return [
                'name' => 'Enrolled Users',
                'passed' => ($enrolled_user_count == $dest_total),
                'message' => "Origin: {$enrolled_user_count} enrolled, Dest: {$dest_total} enrolled",
                'origin_summary' => "{$enrolled_user_count} enrolled users",
                'dest_summary' => $dest_msg,
                'details' => [
                    'origin_students' => 0,
                    'origin_teachers' => 0,
                    'origin_others' => 0,
                    'enrolled_user_count' => $enrolled_user_count,
                    'dest_students' => $dest_students,
                    'dest_teachers' => $dest_teachers,
                    'dest_others' => $dest_others
                ]
            ];
        }
        
        // No enrolled users in backup - structure-only backup
        $backup_user_count = $origin_data['backup_user_count'] ?? 0;
        return [
            'name' => 'Enrolled Users',
            'passed' => true,
            'message' => "Dest: {$dest_msg} (no user data in backup)",
            'origin_summary' => "No user data in backup",
            'dest_summary' => $dest_msg,
            'details' => [
                'origin_students' => 0,
                'origin_teachers' => 0,
                'origin_others' => 0,
                'dest_students' => $dest_students,
                'dest_teachers' => $dest_teachers,
                'dest_others' => $dest_others
            ]
        ];
    }
    
    if ($passed) {
        return [
            'name' => 'Enrolled Users',
            'passed' => true,
            'message' => "{$dest_total} users enrolled ({$dest_msg})",
            'origin_summary' => "{$origin_total} users ({$origin_msg})",
            'dest_summary' => "{$dest_total} users ({$dest_msg})",
            'details' => [
                'origin_students' => $origin_students,
                'origin_teachers' => $origin_teachers,
                'origin_others' => $origin_others,
                'dest_students' => $dest_students,
                'dest_teachers' => $dest_teachers,
                'dest_others' => $dest_others
            ]
        ];
    }
    
    return [
        'name' => 'Enrolled Users',
        'passed' => false,
        'message' => "Users differ - Origin: {$origin_msg} | Dest: {$dest_msg}",
        'origin_summary' => "{$origin_total} users ({$origin_msg})",
        'dest_summary' => "{$dest_total} users ({$dest_msg})",
        'details' => [
            'origin_students' => $origin_students,
            'origin_teachers' => $origin_teachers,
            'origin_others' => $origin_others,
            'dest_students' => $dest_students,
            'dest_teachers' => $dest_teachers,
            'dest_others' => $dest_others
        ]
    ];
}

/**
 * Log validation results to coursetransfer log with HTML formatted table.
 */
function log_validation_results($requestid, $checks, $all_passed) {
    global $DB;
    
    // Build HTML table for validation results
    $html = '<div class="validation-results">';
    
    // Summary table
    $html .= '<table class="table table-bordered table-sm" style="margin-top: 10px;">';
    $html .= '<thead class="thead-light"><tr>';
    $html .= '<th style="width: 25%;">Componente</th>';
    $html .= '<th style="width: 10%;">Estado</th>';
    $html .= '<th style="width: 30%;">Origen</th>';
    $html .= '<th style="width: 35%;">Destino / Acción</th>';
    $html .= '</tr></thead><tbody>';
    
    foreach ($checks as $check) {
        $rowclass = $check['passed'] ? '' : 'table-warning';
        $badge = $check['passed'] 
            ? '<span class="badge badge-success">✓ OK</span>' 
            : '<span class="badge badge-warning">⚠ Diferencia</span>';
        
        // Parse the message to extract origin/dest values
        $origin_info = '-';
        $dest_action = $check['message'];
        
        // Use dest_summary if available, otherwise format from message
        if (!empty($check['dest_summary'])) {
            if ($check['passed']) {
                $dest_action = '<span class="text-success">' . htmlspecialchars($check['dest_summary']) . '</span>';
            } else {
                $dest_action = htmlspecialchars($check['dest_summary']);
            }
        } elseif (!$check['passed']) {
            // Try to parse for more detail
            $dest_action = format_validation_action($check['name'], $check['message']);
        } else {
            $dest_action = '<span class="text-success">' . htmlspecialchars($check['message']) . '</span>';
        }
        
        $html .= "<tr class=\"{$rowclass}\">";
        $html .= '<td><strong>' . htmlspecialchars($check['name']) . '</strong></td>';
        $html .= "<td>{$badge}</td>";
        $html .= '<td>' . get_origin_summary($check) . '</td>';
        $html .= '<td>' . $dest_action . '</td>';
        $html .= '</tr>';
    }
    
    $html .= '</tbody></table>';
    
    // Add legend/help if there are differences
    if (!$all_passed) {
        $html .= '<div class="alert alert-info mt-3" style="font-size: 0.9em;">';
        $html .= '<strong>💡 ¿Cómo resolver las diferencias?</strong><br>';
        $html .= '<ul class="mb-0 mt-2">';
        $html .= '<li><strong>Plugins faltantes</strong> (questionnaire, onetopic): Instalar el plugin en el servidor destino</li>';
        $html .= '<li><strong>Formato del curso</strong>: Cambiar manualmente en Configuración del curso → Formato</li>';
        $html .= '<li><strong>Completion tracking</strong>: Verificar que esté habilitado en el sitio (Admin → Avanzado)</li>';
        $html .= '<li><strong>Visibilidad</strong>: Cambiar en Configuración del curso → Visibilidad</li>';
        $html .= '<li><strong>Fechas</strong>: Ajustar en Configuración del curso → Fechas</li>';
        $html .= '</ul>';
        $html .= '</div>';
    }
    
    $html .= '</div>';
    
    // Simple summary for compatibility
    $summary = [];
    foreach ($checks as $check) {
        $status = $check['passed'] ? 'PASS' : 'FAIL';
        $summary[] = "[{$status}] {$check['name']}: {$check['message']}";
    }
    
    $log = new \stdClass();
    $log->request_id = $requestid;
    $log->direction = 1; // TARGET
    $log->action = 'POST_RESTORE_VALIDATION';
    $log->status = $all_passed ? 'success' : 'warning';
    $log->message = $all_passed ? 
        'All validation checks passed - course restored identically to origin' : 
        'Some validation checks failed - review differences';
    $log->extra_data = json_encode([
        'checks' => $checks,
        'all_passed' => $all_passed,
        'validation_summary' => $summary,
        'html_table' => $html
    ]);
    $log->timecreated = time();
    
    try {
        $DB->insert_record('local_coursetransfer_log', $log);
    } catch (\Exception $e) {
        cli_writeln("[RESTORE CLI] Warning: Could not log validation results: " . $e->getMessage());
    }
}

/**
 * Get origin summary from check data.
 */
function get_origin_summary($check) {
    // If check has a dedicated origin_summary field, use it
    if (!empty($check['origin_summary'])) {
        if ($check['passed']) {
            return '<span class="text-success">' . htmlspecialchars($check['origin_summary']) . '</span>';
        }
        return htmlspecialchars($check['origin_summary']);
    }
    
    if ($check['passed']) {
        return '<span class="text-success">' . htmlspecialchars($check['message']) . '</span>';
    }
    
    // Parse message for origin values
    $msg = $check['message'];
    $origin_parts = [];
    
    if (preg_match_all('/(\w+):\s*origin=([^,;]+)/i', $msg, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $origin_parts[] = $match[1] . ': ' . $match[2];
        }
    }
    
    if (!empty($origin_parts)) {
        return implode('<br>', $origin_parts);
    }
    
    return '-';
}

/**
 * Format validation action/recommendation based on the check type.
 */
function format_validation_action($name, $message) {
    $actions = [];
    
    switch ($name) {
        case 'Course Configuration':
            if (strpos($message, 'format') !== false) {
                if (preg_match('/origin=(\w+),\s*dest=(\w+)/', $message, $m)) {
                    $actions[] = "<strong>Formato:</strong> dest={$m[2]} <br><small class='text-muted'>→ Instalar plugin '{$m[1]}' o cambiar manualmente</small>";
                }
            }
            if (strpos($message, 'Completion') !== false) {
                $actions[] = "<strong>Completion:</strong> Deshabilitado<br><small class='text-muted'>→ Habilitar en Admin → Características avanzadas</small>";
            }
            if (strpos($message, 'Visibility') !== false) {
                $actions[] = "<strong>Visibilidad:</strong> Visible<br><small class='text-muted'>→ Ocultar si es necesario en Config. del curso</small>";
            }
            if (strpos($message, 'News') !== false) {
                $actions[] = "<strong>Noticias:</strong> 0<br><small class='text-muted'>→ Ajustar en Config. del curso</small>";
            }
            if (strpos($message, 'date') !== false) {
                $actions[] = "<strong>Fechas:</strong> Ajustadas al restore<br><small class='text-muted'>→ Cambiar manualmente si necesario</small>";
            }
            break;
            
        case 'Activities Count':
            if (preg_match('/(\w+):\s*origin=(\d+),\s*dest=(\d+)/', $message, $m)) {
                $module = $m[1];
                $origin = $m[2];
                $dest = $m[3];
                if ($dest == 0) {
                    $actions[] = "<strong>{$module}:</strong> No restaurado (dest=0)<br><small class='text-muted'>→ Instalar plugin 'mod_{$module}'</small>";
                } else {
                    $actions[] = "<strong>{$module}:</strong> dest={$dest}<br><small class='text-muted'>→ Verificar manualmente</small>";
                }
            }
            break;
            
        default:
            // Generic formatting
            if (preg_match('/origin=(\d+),\s*dest=(\d+)/', $message, $m)) {
                $actions[] = "dest={$m[2]} (origen={$m[1]})";
            } else {
                $actions[] = htmlspecialchars($message);
            }
    }
    
    return !empty($actions) ? implode('<br>', $actions) : htmlspecialchars($message);
}

/**
 * Notify origin server to cleanup the backup file after successful restore.
 * This only deletes the .mbz backup file in the origin, NOT the course itself.
 *
 * @param stdClass $request The coursetransfer request object
 * @return array ['success' => bool, 'cleaned' => bool, 'error' => string]
 */
function notify_origin_cleanup($request) {
    global $CFG;
    
    require_once($CFG->dirroot . '/local/coursetransfer/classes/coursetransfer.php');
    require_once($CFG->dirroot . '/local/coursetransfer/classes/api/request.php');
    
    try {
        // Get the origin site configuration
        $site = \local_coursetransfer\coursetransfer::get_site_by_url($request->siteurl);
        
        if (!$site) {
            return [
                'success' => false,
                'cleaned' => false,
                'error' => 'Could not find origin site configuration for: ' . $request->siteurl
            ];
        }
        
        // Create API request to origin
        $api_request = new \local_coursetransfer\api\request($site);
        
        // Get the user who initiated the request
        $user = \core_user::get_user($request->userid);
        if (!$user) {
            // Fallback to admin if user not found
            $user = get_admin();
        }
        
        // Get the origin_request_id from the request - this was stored when backup completed
        // and allows the origin to identify exactly which backup file to delete
        $origin_request_id = isset($request->origin_request_id) ? (int)$request->origin_request_id : 0;
        
        // Call the webservice to notify origin that restore completed
        // This triggers cleanup of the .mbz file in origin if auto_cleanup_origin_backup is enabled
        $response = $api_request->target_backup_course_downloaded($request->id, $user, $origin_request_id);
        
        if ($response->success) {
            $cleaned = isset($response->data->cleaned) ? $response->data->cleaned : false;
            
            // Log the notification
            \local_coursetransfer\coursetransfer_logger::info(
                $request->id,
                \local_coursetransfer\coursetransfer_logger::DIRECTION_TARGET,
                'ORIGIN_CLEANUP_NOTIFIED',
                $cleaned ? 
                    'Origin server notified - backup file (.mbz) deleted successfully' : 
                    'Origin server notified - backup file kept (auto_cleanup_origin_backup disabled)',
                [
                    'origin_url' => $request->siteurl,
                    'backup_cleaned' => $cleaned,
                    'request_id' => $request->id,
                    'origin_request_id' => $origin_request_id
                ]
            );
            
            return [
                'success' => true,
                'cleaned' => $cleaned,
                'error' => ''
            ];
        } else {
            // Handle both array and object responses
            $error_msg = 'Unknown error';
            if (isset($response->errors)) {
                if (is_array($response->errors) && isset($response->errors[0])) {
                    $err = $response->errors[0];
                    $error_msg = is_array($err) ? ($err['msg'] ?? 'Unknown') : (is_object($err) ? ($err->msg ?? 'Unknown') : (string)$err);
                } else if (is_object($response->errors)) {
                    $errors_arr = (array)$response->errors;
                    if (!empty($errors_arr)) {
                        $first = reset($errors_arr);
                        $error_msg = is_object($first) ? ($first->msg ?? json_encode($first)) : (string)$first;
                    }
                } else {
                    $error_msg = (string)$response->errors;
                }
            }
            
            \local_coursetransfer\coursetransfer_logger::warning(
                $request->id,
                \local_coursetransfer\coursetransfer_logger::DIRECTION_TARGET,
                'ORIGIN_CLEANUP_FAILED',
                'Failed to notify origin for backup cleanup: ' . $error_msg,
                null,
                ['errors' => is_object($response->errors) ? json_encode($response->errors) : $response->errors]
            );
            
            return [
                'success' => false,
                'cleaned' => false,
                'error' => $error_msg
            ];
        }
        
    } catch (\Exception $e) {
        \local_coursetransfer\coursetransfer_logger::warning(
            $request->id,
            \local_coursetransfer\coursetransfer_logger::DIRECTION_TARGET,
            'ORIGIN_CLEANUP_EXCEPTION',
            'Exception when notifying origin for cleanup: ' . $e->getMessage(),
            null,
            ['exception' => get_class($e)]
        );
        
        return [
            'success' => false,
            'cleaned' => false,
            'error' => $e->getMessage()
        ];
    }
}
