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
 * Scheduled task to process the restore queue one course at a time.
 *
 * This task runs every minute and processes ONE course from the queue
 * (status=DOWNLOADED), then terminates. This ensures:
 * - Sequential processing (no parallel restores)
 * - Fresh PHP memory for each restore
 * - No adhoc task accumulation
 *
 * @package    local_coursetransfer
 * @copyright  2025 IPG
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfer\task;

use local_coursetransfer\coursetransfer_request;
use local_coursetransfer\coursetransfer_logger;

/**
 * Process restore queue task - processes one course at a time from the download queue.
 */
class process_restore_queue_task extends \core\task\scheduled_task {

    /**
     * Maximum number of retry attempts before marking as failed
     */
    const MAX_RETRIES = 3;

    /**
     * Get task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_process_restore_queue', 'local_coursetransfer');
    }

    /**
     * Execute the task.
     *
     * Finds the oldest request in DOWNLOADED status and processes it via CLI.
     * Only processes ONE course per execution to ensure fresh PHP memory.
     */
    public function execute() {
        global $DB, $CFG;

        mtrace("Process Restore Queue Task - Starting...");

        // Find oldest request in DOWNLOADED status (ready to restore)
        $request = $DB->get_record_sql(
            "SELECT * FROM {local_coursetransfer_request}
             WHERE status = :status
             ORDER BY timecreated ASC
             LIMIT 1",
            ['status' => coursetransfer_request::STATUS_DOWNLOADED]
        );

        if (!$request) {
            mtrace("No courses pending restore in queue. Nothing to process.");
            return;
        }

        mtrace("Found request ID {$request->id} for course {$request->target_course_id} (origin: {$request->origin_course_id})");
        mtrace("Request created: " . userdate($request->timecreated));

        // Get current retry count
        $retry_count = isset($request->retry_count) ? (int)$request->retry_count : 0;
        if ($retry_count > 0) {
            mtrace("This is retry attempt #{$retry_count} of " . self::MAX_RETRIES);
        }

        // Get the backup file ID
        $fileid = $this->get_backup_file_id($request);
        if (!$fileid) {
            mtrace("ERROR: Backup file not found for request {$request->id}");
            $this->handle_failure($request, "Backup file not found in moodledata");
            return;
        }

        mtrace("Found backup file ID: {$fileid}");

        // Update status to RESTORE before starting
        $DB->set_field('local_coursetransfer_request', 'status', 
            coursetransfer_request::STATUS_RESTORE, ['id' => $request->id]);
        $DB->set_field('local_coursetransfer_request', 'timemodified', time(), ['id' => $request->id]);

        // Log that we're starting
        coursetransfer_logger::info(
            $request->id,
            coursetransfer_logger::DIRECTION_TARGET,
            'QUEUE_RESTORE_STARTED',
            'Starting restore from queue (attempt ' . ($retry_count + 1) . '/' . self::MAX_RETRIES . ')',
            [
                'target_course_id' => $request->target_course_id,
                'origin_course_id' => $request->origin_course_id,
                'file_id' => $fileid,
                'retry_count' => $retry_count
            ]
        );

        // Execute restore via CLI
        mtrace("Executing restore via CLI...");
        $result = $this->execute_restore_cli($request->id, $fileid);

        if ($result['success']) {
            mtrace("✓ Restore completed successfully for request {$request->id}");
            mtrace("  Target course ID: {$request->target_course_id}");
            
            // Status is already updated by CLI to COMPLETED
            coursetransfer_logger::success(
                $request->id,
                coursetransfer_logger::DIRECTION_TARGET,
                'QUEUE_RESTORE_COMPLETED',
                'Course restored successfully from queue',
                [
                    'target_course_id' => $request->target_course_id,
                    'execution_time' => $result['duration'] ?? 'unknown'
                ]
            );
        } else {
            mtrace("✗ Restore failed for request {$request->id}");
            mtrace("  Error: " . ($result['error'] ?? 'Unknown error'));
            $this->handle_failure($request, $result['error'] ?? 'CLI restore failed');
        }

        // Task ends here - only processes ONE course per execution
        // Next minute, cron will start a new instance for the next course
        mtrace("Process Restore Queue Task - Finished.");
    }

    /**
     * Handle a failed restore attempt.
     *
     * @param object $request The request object
     * @param string $error_message The error message
     */
    private function handle_failure($request, $error_message) {
        global $DB;

        $retry_count = isset($request->retry_count) ? (int)$request->retry_count : 0;
        $retry_count++;

        if ($retry_count < self::MAX_RETRIES) {
            // Still have retries left - put back in queue
            mtrace("Scheduling retry {$retry_count}/" . self::MAX_RETRIES . " (will retry next minute)");
            
            $DB->update_record('local_coursetransfer_request', (object)[
                'id' => $request->id,
                'status' => coursetransfer_request::STATUS_DOWNLOADED, // Back to queue
                'retry_count' => $retry_count,
                'timemodified' => time()
            ]);

            coursetransfer_logger::warning(
                $request->id,
                coursetransfer_logger::DIRECTION_TARGET,
                'QUEUE_RESTORE_RETRY',
                "Restore failed, will retry ({$retry_count}/" . self::MAX_RETRIES . "): " . $error_message,
                null,
                ['retry_count' => $retry_count, 'max_retries' => self::MAX_RETRIES]
            );
        } else {
            // Max retries reached - mark as error
            mtrace("Max retries reached ({$retry_count}). Marking as ERROR.");
            
            $DB->update_record('local_coursetransfer_request', (object)[
                'id' => $request->id,
                'status' => coursetransfer_request::STATUS_ERROR,
                'retry_count' => $retry_count,
                'error_code' => 11500,
                'error_message' => substr("Restore failed after " . self::MAX_RETRIES . " attempts: " . $error_message, 0, 500),
                'timemodified' => time()
            ]);

            coursetransfer_logger::error(
                $request->id,
                coursetransfer_logger::DIRECTION_TARGET,
                'QUEUE_RESTORE_FAILED',
                "Restore failed permanently after " . self::MAX_RETRIES . " attempts: " . $error_message,
                11500,
                ['retry_count' => $retry_count, 'final_error' => $error_message]
            );
        }
    }

    /**
     * Get the backup file ID for a request.
     *
     * @param object $request The request object
     * @return int|null The file ID or null if not found
     */
    private function get_backup_file_id($request) {
        $fs = get_file_storage();

        // Try to get from course context first (where download_file_course_task stores it)
        if (!empty($request->target_course_id)) {
            try {
                $context = \context_course::instance($request->target_course_id);
                $files = $fs->get_area_files($context->id, 'backup', 'course', 0, 'timecreated DESC', false);
                
                if (!empty($files)) {
                    $file = reset($files);
                    mtrace("  Found backup in course context: " . $file->get_filename() . 
                           " (" . round($file->get_filesize() / 1024 / 1024, 2) . " MB)");
                    return $file->get_id();
                }
            } catch (\Exception $e) {
                mtrace("  Warning: Could not search course context: " . $e->getMessage());
            }
        }

        // Fallback: search in system context
        try {
            $context = \context_system::instance();
            $files = $fs->get_area_files($context->id, 'local_coursetransfer', 'backup', $request->id, 'id DESC', false);
            
            if (!empty($files)) {
                $file = reset($files);
                mtrace("  Found backup in system context: " . $file->get_filename());
                return $file->get_id();
            }
        } catch (\Exception $e) {
            mtrace("  Warning: Could not search system context: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Execute restore via CLI in isolated PHP process.
     *
     * @param int $requestid Request ID
     * @param int $fileid File ID
     * @return array ['success' => bool, 'error' => string|null, 'exit_code' => int, 'duration' => int]
     */
    private function execute_restore_cli($requestid, $fileid) {
        global $CFG;

        $start_time = time();

        // Get PHP binary
        $php_binary = $this->get_php_binary();
        $cli_script = $CFG->dirroot . '/local/coursetransfer/cli/restore_course_cli.php';

        if (!file_exists($cli_script)) {
            return [
                'success' => false,
                'error' => "CLI script not found: {$cli_script}",
                'exit_code' => -1,
                'duration' => 0
            ];
        }

        // Build command
        $cmd = sprintf(
            '%s %s --requestid=%d --fileid=%d 2>&1',
            escapeshellcmd($php_binary),
            escapeshellarg($cli_script),
            $requestid,
            $fileid
        );

        mtrace("  Command: {$cmd}");

        // Execute with timeout (90 minutes for large courses)
        $timeout = 5400;
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes, $CFG->dirroot);

        if (!is_resource($process)) {
            return [
                'success' => false,
                'error' => 'Failed to start CLI process',
                'exit_code' => -1,
                'duration' => time() - $start_time
            ];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';

        while (true) {
            $status = proc_get_status($process);

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);

            if ($stdout) {
                $output .= $stdout;
                foreach (explode("\n", trim($stdout)) as $line) {
                    if (!empty(trim($line))) {
                        mtrace("  [CLI] " . $line);
                    }
                }
            }

            if ($stderr) {
                $output .= $stderr;
                foreach (explode("\n", trim($stderr)) as $line) {
                    if (!empty(trim($line))) {
                        mtrace("  [CLI ERR] " . $line);
                    }
                }
            }

            if (!$status['running']) {
                break;
            }

            if ((time() - $start_time) > $timeout) {
                mtrace("  CLI process timeout after {$timeout}s");
                proc_terminate($process, 9);
                break;
            }

            usleep(100000); // 100ms
        }

        // Get any remaining output
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        if ($stdout) {
            $output .= $stdout;
            foreach (explode("\n", trim($stdout)) as $line) {
                if (!empty(trim($line))) {
                    mtrace("  [CLI] " . $line);
                }
            }
        }
        if ($stderr) {
            $output .= $stderr;
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit_code = proc_close($process);
        $duration = time() - $start_time;

        mtrace("  CLI exited with code: {$exit_code} (duration: {$duration}s)");

        // If CLI reports failure, double-check the request status in DB
        // The CLI may have completed successfully but exited with error due to
        // webservice issues during cleanup notification
        if ($exit_code !== 0) {
            global $DB;
            $refreshed_request = $DB->get_record('local_coursetransfer_request', ['id' => $requestid]);
            
            if ($refreshed_request && in_array($refreshed_request->status, [
                coursetransfer_request::STATUS_COMPLETED,
                coursetransfer_request::STATUS_COMPLETED_WITH_DIFFERENCES
            ])) {
                mtrace("  CLI exited with error but request status is COMPLETED - treating as success");
                return [
                    'success' => true,
                    'error' => null,
                    'exit_code' => $exit_code,
                    'duration' => $duration
                ];
            }
        }

        return [
            'success' => ($exit_code === 0),
            'error' => ($exit_code !== 0) ? "CLI exited with code {$exit_code}" : null,
            'exit_code' => $exit_code,
            'duration' => $duration
        ];
    }

    /**
     * Get PHP binary path.
     *
     * @return string
     */
    private function get_php_binary() {
        global $CFG;

        if (!empty($CFG->pathtophp) && is_executable($CFG->pathtophp)) {
            return $CFG->pathtophp;
        }

        $paths = [PHP_BINARY, '/usr/bin/php', '/usr/local/bin/php'];
        foreach ($paths as $path) {
            if (!empty($path) && is_executable($path)) {
                return $path;
            }
        }

        return 'php';
    }
}
