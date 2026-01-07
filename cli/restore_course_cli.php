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
    cli_writeln("[RESTORE CLI] Executing restore plan...");
    $rc->execute_plan();
    
    // Cleanup
    $rc->destroy();
    
    cli_writeln("[RESTORE CLI] Restore plan executed successfully");

    // Cleanup temp directory
    fulldelete($backuppath);

    // Delete the backup file from moodledata to free space
    cli_writeln("[RESTORE CLI] Cleaning up backup file...");
    try {
        $backupfile->delete();
        cli_writeln("[RESTORE CLI] Backup file deleted successfully");
    } catch (\Exception $deleteEx) {
        cli_writeln("[RESTORE CLI] Warning: Could not delete backup file: " . $deleteEx->getMessage());
    }

    // Update request status to completed
    $request->status = coursetransfer_request::STATUS_COMPLETED;
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
