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
 * CLI script to execute a single course restore in an isolated PHP process.
 *
 * This script runs the restore_course_task for a specific request in a completely
 * fresh PHP process. This avoids Moodle's static cache contamination issue where
 * quiz restore plugins keep stale references from previous restores.
 *
 * Usage: php execute_restore.php --requestid=123
 *
 * @package    local_coursetransfer
 * @copyright  2025 Proyecto UNIMOODLE
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

// Get CLI options.
list($options, $unrecognized) = cli_get_params(
    [
        'requestid' => null,
        'help' => false,
    ],
    [
        'r' => 'requestid',
        'h' => 'help',
    ]
);

if ($options['help'] || empty($options['requestid'])) {
    $help = <<<EOF
Execute a single course restore in an isolated PHP process.

This script ensures each restore has a clean PHP environment without
static cache contamination from previous restores.

Options:
 -r, --requestid=INT    The request ID to process (required)
 -h, --help             Show this help

Example:
 php execute_restore.php --requestid=123

EOF;
    echo $help;
    exit($options['help'] ? 0 : 1);
}

$requestid = (int)$options['requestid'];

cli_writeln("[CLI RESTORE] Starting isolated restore for request {$requestid}");
cli_writeln("[CLI RESTORE] Process ID: " . getmypid());
cli_writeln("[CLI RESTORE] PHP version: " . PHP_VERSION);
cli_writeln("[CLI RESTORE] Memory at start: " . round(memory_get_usage() / 1024 / 1024, 2) . " MB");

// Increase limits for restore operations
@set_time_limit(0);
raise_memory_limit(MEMORY_HUGE);

try {
    // Verify request exists
    $request = $DB->get_record('local_coursetransfer_request', ['id' => $requestid]);
    
    if (!$request) {
        cli_error("[CLI RESTORE] Request {$requestid} not found");
    }

    cli_writeln("[CLI RESTORE] Request found:");
    cli_writeln("[CLI RESTORE]   - Origin Course ID: {$request->origin_course_id}");
    cli_writeln("[CLI RESTORE]   - Target Course ID: {$request->target_course_id}");
    cli_writeln("[CLI RESTORE]   - Status: {$request->status}");

    // Check if backup file exists
    $fs = get_file_storage();
    $context = context_system::instance();
    
    $files = $fs->get_area_files(
        $context->id,
        'local_coursetransfer',
        'backup',
        $request->id,
        'id DESC',
        false
    );

    if (empty($files)) {
        cli_writeln("[CLI RESTORE] No backup file found yet, checking for pending download...");
        
        // Check if there's a download task pending
        $download_tasks = $DB->get_records_sql(
            "SELECT id FROM {task_adhoc} 
             WHERE classname LIKE '%download_file_course_task%' 
               AND customdata LIKE '%\"requestid\":{$requestid}%'
             LIMIT 1"
        );
        
        if (!empty($download_tasks)) {
            cli_writeln("[CLI RESTORE] Download task is pending. Waiting...");
            // Wait for download to complete (max 30 minutes)
            $max_wait = 1800;
            $waited = 0;
            $interval = 10;
            
            while ($waited < $max_wait) {
                sleep($interval);
                $waited += $interval;
                
                // Check if file now exists
                $files = $fs->get_area_files(
                    $context->id,
                    'local_coursetransfer',
                    'backup',
                    $request->id,
                    'id DESC',
                    false
                );
                
                if (!empty($files)) {
                    cli_writeln("[CLI RESTORE] Backup file now available after {$waited}s");
                    break;
                }
                
                if ($waited % 60 === 0) {
                    cli_writeln("[CLI RESTORE] Still waiting for backup... ({$waited}s)");
                }
            }
            
            if (empty($files)) {
                throw new \Exception("Timeout waiting for backup file after {$max_wait}s");
            }
        } else {
            throw new \Exception("No backup file and no download task found for request {$requestid}");
        }
    }

    $backup_file = reset($files);
    cli_writeln("[CLI RESTORE] Backup file found:");
    cli_writeln("[CLI RESTORE]   - File ID: " . $backup_file->get_id());
    cli_writeln("[CLI RESTORE]   - Size: " . round($backup_file->get_filesize() / 1024 / 1024, 2) . " MB");
    cli_writeln("[CLI RESTORE]   - Filename: " . $backup_file->get_filename());

    // Update request status
    $DB->set_field('local_coursetransfer_request', 'status', 
        \local_coursetransfer\coursetransfer_request::STATUS_IN_PROGRESS, 
        ['id' => $requestid]);

    cli_writeln("[CLI RESTORE] Starting restore process...");
    cli_writeln(str_repeat('-', 60));

    // Execute the restore using the existing coursetransfer_restore class
    $success = \local_coursetransfer\coursetransfer_restore::restore_course($request, $backup_file);

    cli_writeln(str_repeat('-', 60));

    // Check final status
    $final_request = $DB->get_record('local_coursetransfer_request', ['id' => $requestid]);

    if ($final_request->status == \local_coursetransfer\coursetransfer_request::STATUS_COMPLETED) {
        cli_writeln("[CLI RESTORE] SUCCESS - Restore completed!");
        cli_writeln("[CLI RESTORE] Final course ID: {$final_request->target_course_id}");
        cli_writeln("[CLI RESTORE] Memory peak: " . round(memory_get_peak_usage() / 1024 / 1024, 2) . " MB");
        exit(0);
    } else {
        $error = $final_request->error_message ?? 'Unknown error';
        cli_writeln("[CLI RESTORE] FAILED - Status: {$final_request->status}");
        cli_writeln("[CLI RESTORE] Error: {$error}");
        exit(1);
    }

} catch (\Exception $e) {
    cli_writeln("[CLI RESTORE] EXCEPTION: " . $e->getMessage());
    cli_writeln("[CLI RESTORE] File: " . $e->getFile() . ":" . $e->getLine());
    
    // Update request to error status
    if (isset($requestid)) {
        $DB->update_record('local_coursetransfer_request', (object)[
            'id' => $requestid,
            'status' => \local_coursetransfer\coursetransfer_request::STATUS_ERROR,
            'error_code' => '10500',
            'error_message' => substr($e->getMessage(), 0, 1000),
            'timemodified' => time()
        ]);
    }

    exit(1);
}
