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
 * logs_course_response_table
 *
 * @package    local_coursetransfer
 * @copyright  2023 Proyecto UNIMOODLE
 * @author     UNIMOODLE Group (Coordinator) <direccion.area.estrategia.digital@uva.es>
 * @author     3IPUNT <contacte@tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfer\task;

use context_system;
use dml_exception;
use local_coursetransfer\coursetransfer;
use local_coursetransfer\coursetransfer_logger;
use local_coursetransfer\coursetransfer_notification;
use local_coursetransfer\coursetransfer_request;
use local_coursetransfer\coursetransfer_restore;
use moodle_exception;

/**
 * logs_course_response_table
 *
 * @package    local_coursetransfer
 * @copyright  2023 Proyecto UNIMOODLE
 * @author     UNIMOODLE Group (Coordinator) <direccion.area.estrategia.digital@uva.es>
 * @author     3IPUNT <contacte@tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_course_task extends \core\task\adhoc_task {

    // Use the logging trait to get some nice, juicy, logging.
    use \core\task\logging_trait;

    /**
     * Maximum number of retry attempts before marking as failed
     */
    const MAX_RETRY_ATTEMPTS = 3;

    /**
     * Base delay in seconds between retry attempts (exponential backoff)
     */
    const BASE_RETRY_DELAY = 300; // 5 minutes base delay

    /**
     * Lock timeout in seconds - wait up to 10 seconds for lock acquisition
     */
    const LOCK_TIMEOUT = 10;

    /**
     * Concurrency backoff delay in seconds
     */
    const CONCURRENCY_BACKOFF = 90; // 1.5 minutes backoff when lock is busy

    /**
     * Execute.
     *
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function execute() {
        global $DB;
        $lock = null;
        
        $this->log_start("Restore Backup Course Remote Starting...");

            // Optimize resources for heavy restore operation
            @set_time_limit(0); // Use standard PHP function instead of core class that might be missing
            if (function_exists('raise_memory_limit')) {
                raise_memory_limit(MEMORY_EXTRA);
            }
            gc_collect_cycles(); // clear any garbage before starting

            // PRE-CHECK: Verify if there are other restore tasks currently running
            // This check happens BEFORE trying to acquire the lock for efficiency
            $customdata = $this->get_custom_data();
            $requestid = $customdata->requestid ?? null;
            $mytaskid = $this->get_id();
            
            // Check for running restore tasks in the database
            $running_tasks = $this->check_running_restore_tasks($mytaskid);
            
            if ($running_tasks > 0) {
                // There are other restore tasks running, reschedule without trying lock
                $reschedule_count = isset($customdata->reschedule_count) ? $customdata->reschedule_count : 0;
                
                // Use smarter backoff: 60s for first few attempts, then increase
                $backoff = $reschedule_count < 3 ? 60 : min(self::CONCURRENCY_BACKOFF * ($reschedule_count - 1), 300);
                
                $this->log("Pre-check: {$running_tasks} restore task(s) currently running. Rescheduling in {$backoff}s (pre-check #{$reschedule_count})...");
                
                if ($requestid) {
                    coursetransfer_logger::info(
                        $requestid,
                        coursetransfer_logger::DIRECTION_TARGET,
                        'RESTORE_PRE_CHECK_WAIT',
                        "Waiting for {$running_tasks} running restore task(s) to complete",
                        [
                            'running_tasks' => $running_tasks,
                            'reschedule_count' => $reschedule_count,
                            'backoff_seconds' => $backoff,
                            'my_task_id' => $mytaskid
                        ]
                    );
                }
                
                // Increment reschedule counter and reschedule
                $customdata->reschedule_count = $reschedule_count + 1;
                $this->set_custom_data($customdata);
                $this->set_next_run_time(time() + $backoff);
                \core\task\manager::reschedule_or_queue_adhoc_task($this);
                
                return; // Exit early without trying to acquire lock
            }

            // STRICT CONCURRENCY CONTROL: Force sequential execution with lock
            // We use a global lock to ensure only ONE restore task runs at a time across ALL processes.
            // This prevents concurrent access to backup_ids_temp which causes restore_step_exception.
            $lockfactory = \core\lock\lock_config::get_lock_factory('local_coursetransfer');
            $lock = $lockfactory->get_lock('sequential_restore_execution', self::LOCK_TIMEOUT);

            if (!$lock) {
                // Another task acquired the lock between our pre-check and lock attempt.
                // Reschedule this task with exponential backoff.
                $reschedule_count = isset($customdata->reschedule_count) ? $customdata->reschedule_count : 0;
                
                // Increase backoff time with each reschedule (90s, 180s, 270s, max 300s)
                $backoff = min(self::CONCURRENCY_BACKOFF * ($reschedule_count + 1), 300);
                
                $this->log("Concurrency lock busy (attempt #{$reschedule_count}). Rescheduling in {$backoff}s...");
                
                // Log concurrency detection
                if ($requestid && $requestid !== 'unknown') {
                    coursetransfer_logger::warning(
                        $requestid,
                        coursetransfer_logger::DIRECTION_TARGET,
                        'CONCURRENCY_LOCK_BUSY',
                        "Restore task waiting for lock (reschedule #{$reschedule_count})",
                        null,
                        [
                            'reschedule_count' => $reschedule_count,
                            'backoff_seconds' => $backoff,
                            'next_run' => date('Y-m-d H:i:s', time() + $backoff)
                        ]
                    );
                }
                
                // Increment reschedule counter and reschedule
                $customdata->reschedule_count = $reschedule_count + 1;
                $this->set_custom_data($customdata);
                $this->set_next_run_time(time() + $backoff);
                \core\task\manager::reschedule_or_queue_adhoc_task($this);
                
                return; // Exit this execution, the rescheduled one will run later
            }

            // Lock acquired successfully - log for monitoring
            $this->log("✓ Sequential restore lock acquired. Starting restore process...");
            
            try {
                $fileid = $this->get_custom_data()->fileid;
                $requestid = $this->get_custom_data()->requestid;
                
                // Get retry attempt number (0 for first attempt)
                $retryattempt = isset($this->get_custom_data()->retry_attempt) ? 
                    $this->get_custom_data()->retry_attempt : 0;
                
                // CRITICAL: Free memory before restore to handle large backups
                // Using garbage collection instead of purge_all_caches() to avoid affecting other users
                gc_collect_cycles();
                $this->log("Memory optimized before restore (local GC only, no cache purge)");
                
                // Log lock acquisition for this request
                coursetransfer_logger::info(
                    $requestid,
                    coursetransfer_logger::DIRECTION_TARGET,
                    'RESTORE_LOCK_ACQUIRED',
                    'Sequential restore lock acquired - task is executing exclusively',
                    [
                        'adhoc_task_id' => $this->get_id(),
                        'retry_attempt' => $retryattempt
                    ]
                );

            if ($retryattempt > 0) {
                $this->log("Retry attempt #{$retryattempt} of " . self::MAX_RETRY_ATTEMPTS);
                coursetransfer_logger::warning(
                    $requestid,
                    coursetransfer_logger::DIRECTION_TARGET,
                    'RESTORE_RETRY_ATTEMPT',
                    "Restore retry attempt #{$retryattempt}",
                    null,
                    ['attempt' => $retryattempt, 'max_attempts' => self::MAX_RETRY_ATTEMPTS]
                );
            }
            
            $fs = get_file_storage();

            $request = coursetransfer_request::get($requestid);
        
        // Check if this request was already completed successfully
        if ($request && $request->status == coursetransfer_request::STATUS_COMPLETED) {
            $this->log_start('Request ' . $requestid . ' already completed successfully');
            $this->log_finish('Skipping duplicate task execution - request already finished');
            return;
        }
        
        $file = $fs->get_file_by_id($fileid);

        // Log restore started
        coursetransfer_logger::log_task_started(
            $requestid,
            coursetransfer_logger::DIRECTION_TARGET,
            $this->get_id(),
            get_class($this),
            'Starting course restore process'
        );
        
        coursetransfer_logger::info(
            $requestid,
            coursetransfer_logger::DIRECTION_TARGET,
            coursetransfer_logger::ACTION_RESTORE_STARTED,
            'Initiating restore for file ID: ' . $fileid,
            ['file_id' => $fileid, 'adhoc_task_id' => $this->get_id()]
        );

        if (!$file) {
            // Fix for 11100: Check if we have the URL to re-download the file
            $fileurl = isset($this->get_custom_data()->fileurl) ? $this->get_custom_data()->fileurl : null;
            
            if ($fileurl) {
                $this->log('File not found in storage. Attempting missing file recovery from URL: ' . $fileurl);
                try {
                    // Attempt to re-download "just-in-time"
                    $restored_file = $this->recover_missing_file($fileurl, $request->target_course_id, $request->origin_course_id);
                    if ($restored_file) {
                        $file = $restored_file;
                        $fileid = $file->get_id(); // Update fileid for logging
                        $this->log('File successfully recovered. New File ID: ' . $fileid);
                        
                        coursetransfer_logger::info(
                            $requestid,
                            coursetransfer_logger::DIRECTION_TARGET,
                            'FILE_RECOVERED',
                            'Missing backup file was automatically re-downloaded',
                            ['new_file_id' => $fileid]
                        );
                    }
                } catch (\Exception $recoverEx) {
                    $this->log('File recovery failed: ' . $recoverEx->getMessage());
                }
            }
        }

        if (!$file) {
            // Check again if request was completed by another task execution
            $request = coursetransfer_request::get($requestid);
            if ($request && $request->status == coursetransfer_request::STATUS_COMPLETED) {
                $this->log('File not found but request already completed - likely cleaned up after successful restore');
                $this->log_finish('Skipping duplicate task - file was cleaned up after successful previous execution');
                return;
            }
            
            $this->log('Restore in Moodle not working beacuse File not found! :' . $fileid);
            
            // Log restore failure
            coursetransfer_logger::error(
                $requestid,
                coursetransfer_logger::DIRECTION_TARGET,
                coursetransfer_logger::ACTION_RESTORE_FAILED,
                'Backup file not found in Moodle file system (Recovery failed or no URL)',
                '11100',
                ['file_id' => $fileid, 'had_url' => !empty($fileurl)]
            );
            
            $request->status = coursetransfer_request::STATUS_ERROR;
            $request->error_code = '11100';
            $request->error_message = 'Restore in Moodle not working beacuse File not found! :' . $fileid;
            coursetransfer_request::insert_or_update($request, $requestid);
        } else {
            // Pass retry_attempt to request for quiz corruption detection
            $request->retry_attempt = $retryattempt;
            
            // Execute restore in isolated CLI process to avoid static cache contamination
            // This fixes the "not_specified_restore_task" error in quiz restore
            $this->log('Executing restore via isolated CLI process...');
            $cli_result = $this->execute_restore_cli($requestid, $fileid);
            $success = $cli_result['success'];
            
            // IMPORTANT: If CLI reports failure, double-check the request status in DB
            // The CLI may have completed successfully but exited with error due to
            // webservice issues during cleanup notification (e.g., origin server errors)
            if (!$success) {
                $this->log('CLI reported failure, checking actual request status in database...');
                
                // Refresh request from database to get actual status
                $refreshed_request = coursetransfer_request::get($requestid);
                
                if ($refreshed_request && in_array($refreshed_request->status, [
                    coursetransfer_request::STATUS_COMPLETED,
                    coursetransfer_request::STATUS_COMPLETED_WITH_DIFFERENCES
                ])) {
                    // The restore actually succeeded! The CLI just had issues with post-restore operations
                    $this->log('Request status is ' . $refreshed_request->status . ' - restore actually succeeded');
                    $success = true;
                    $request = $refreshed_request; // Use refreshed request
                    
                    coursetransfer_logger::info(
                        $requestid,
                        coursetransfer_logger::DIRECTION_TARGET,
                        'CLI_EXIT_IGNORED',
                        'CLI exited with error but restore completed successfully (status=' . $refreshed_request->status . ')',
                        [
                            'cli_exit_code' => $cli_result['exit_code'] ?? -1,
                            'actual_status' => $refreshed_request->status,
                            'note' => 'Post-restore operations may have had issues but course was restored'
                        ]
                    );
                } else {
                    // Restore really did fail
                    $this->log('CLI restore failed: ' . ($cli_result['error'] ?? 'Unknown error'));
                    coursetransfer_logger::error(
                        $requestid,
                        coursetransfer_logger::DIRECTION_TARGET,
                        'CLI_RESTORE_FAILED',
                        'CLI restore process failed: ' . ($cli_result['error'] ?? 'Unknown'),
                        null,
                        ['exit_code' => $cli_result['exit_code'] ?? -1]
                    );
                }
            }
            
            if ($success) {
                $this->log('Restore in Moodle Success!');
                
                // Log successful restore completion
                coursetransfer_logger::success(
                    $requestid,
                    coursetransfer_logger::DIRECTION_TARGET,
                    coursetransfer_logger::ACTION_RESTORE_COMPLETED,
                    'Course restored successfully',
                    [
                        'target_course_id' => $request->target_course_id,
                        'file_id' => $fileid,
                        'file_size' => $file->get_filesize(),
                        'retry_attempts' => $retryattempt
                    ]
                );
                
                // Update request status to COMPLETED with specific DB error handling
                $request->status = coursetransfer_request::STATUS_COMPLETED;
                try {
                    coursetransfer_request::insert_or_update($request, $request->id);
                } catch (\dml_exception $dbException) {
                    // Database error - log specifically and re-throw
                    coursetransfer_logger::error(
                        $requestid,
                        coursetransfer_logger::DIRECTION_TARGET,
                        'DATABASE_UPDATE_FAILED',
                        'Failed to update request status to COMPLETED in database',
                        $dbException->getCode(),
                        [
                            'table' => 'local_coursetransfer_request',
                            'operation' => 'update_to_completed',
                            'request_id' => $request->id,
                            'db_error' => $dbException->getMessage()
                        ]
                    );
                    throw $dbException; // Re-throw to be caught by outer catch
                }

                // ALWAYS notify origin that restore completed (for logging and status tracking)
                // This is important even if auto_cleanup is disabled, to ensure proper logging in origin
                $this->notify_origin_restore_completed($request);

                // CRITICAL: Clean up temporary restore files after successful restore
                // Large backups (2GB+) can leave temp files that cause issues for subsequent restores
                // Reference: https://moodle.org/mod/forum/discuss.php?d=425306
                $this->cleanup_temp_restore_files($request->target_course_id);
                
                // Cleanup downloaded backup file if auto cleanup is enabled
                if (get_config('local_coursetransfer', 'auto_cleanup_target_backup')) {
                    $this->cleanup_downloaded_backup($file);
                }
                
                // Free memory after restore (local process only, doesn't affect other users)
                gc_collect_cycles();
                $this->log("Post-restore cleanup completed (temp files + local GC)");

                // Send completion notifications
                if (!is_null($request->request_category_id)) {
                    // Category restore completed
                    $reqcat = coursetransfer_request::update_status_request_cat($request->request_category_id);
                    $this->log('Update Status Category Request');
                    if ($reqcat->status === coursetransfer_request::STATUS_COMPLETED) {
                        coursetransfer_notification::send_restore_category_completed(
                                $request->userid, $request->origin_category_id);
                        $this->log('Category restore completed - .mbz files will be cleaned up by origin');
                    }
                } else {
                    // Individual course restore completed
                    coursetransfer_notification::send_restore_course_completed($request->userid, $request->target_course_id);
                    $this->log('Course restore completed - .mbz file will be cleaned up by origin');
                }
            } else {
                // Restore failed - check if we should retry
                $this->log('Restore in Moodle Failed!');
                
                // Check if we can retry
                if ($retryattempt < self::MAX_RETRY_ATTEMPTS) {
                    // Schedule retry instead of marking as permanent failure
                    $this->schedule_retry($requestid, $fileid, $retryattempt);
                    $this->log("Restore failed, retry #{" . ($retryattempt + 1) . "} scheduled");
                    
                    coursetransfer_logger::warning(
                        $requestid,
                        coursetransfer_logger::DIRECTION_TARGET,
                        'RESTORE_RETRY_SCHEDULED',
                        "Restore failed, scheduling retry attempt #" . ($retryattempt + 1),
                        null,
                        [
                            'current_attempt' => $retryattempt,
                            'next_attempt' => $retryattempt + 1,
                            'max_attempts' => self::MAX_RETRY_ATTEMPTS
                        ]
                    );
                    
                    // Don't mark as ERROR yet, keep in DOWNLOADED state for retry
                    return; // Exit gracefully, retry will be executed later
                } else {
                    // Max retries reached, mark as permanent failure
                    $this->log('Restore failed after ' . self::MAX_RETRY_ATTEMPTS . ' attempts');
                    
                    coursetransfer_logger::error(
                        $requestid,
                        coursetransfer_logger::DIRECTION_TARGET,
                        coursetransfer_logger::ACTION_RESTORE_FAILED,
                        'Restore failed after ' . self::MAX_RETRY_ATTEMPTS . ' retry attempts',
                        '11101',
                        ['retry_attempts' => $retryattempt, 'file_id' => $fileid]
                    );
                    
                    $request->status = coursetransfer_request::STATUS_ERROR;
                    $request->error_code = '11101';
                    $request->error_message = 'Restore failed after ' . self::MAX_RETRY_ATTEMPTS . ' attempts';
                    coursetransfer_request::insert_or_update($request, $request->id);
                }
            }
            $this->log_finish("Restore Backup Course Remote Finishing...");
        }
        
        } catch (\Throwable $e) {
            // Catch ALL types of errors including Exception, Error, Fatal errors, etc.
            $this->log('CRITICAL ERROR during restore: ' . $e->getMessage());
            
            // Ensure we have minimum required variables
            if (!isset($requestid)) {
                $requestid = isset($this->get_custom_data()->requestid) ? 
                    $this->get_custom_data()->requestid : null;
            }
            
            if (!isset($fileid)) {
                $fileid = isset($this->get_custom_data()->fileid) ? 
                    $this->get_custom_data()->fileid : null;
            }
            
            if (!isset($retryattempt)) {
                $retryattempt = isset($this->get_custom_data()->retry_attempt) ? 
                    $this->get_custom_data()->retry_attempt : 0;
            }
            
            // Try to get request object if not set
            if (!isset($request) && $requestid) {
                try {
                    $request = coursetransfer_request::get($requestid);
                } catch (\Exception $getException) {
                    $this->log('Could not retrieve request: ' . $getException->getMessage());
                }
            }
            
            // Check if we should retry on exception
            if ($retryattempt < self::MAX_RETRY_ATTEMPTS && $requestid && $fileid) {
                // Schedule retry for transient errors
                $this->log("Exception occurred, scheduling retry #{" . ($retryattempt + 1) . "}");
                
                coursetransfer_logger::warning(
                    $requestid,
                    coursetransfer_logger::DIRECTION_TARGET,
                    'RESTORE_EXCEPTION_RETRY_SCHEDULED',
                    "Exception during restore, scheduling retry: " . $e->getMessage(),
                    null,
                    [
                        'exception' => get_class($e),
                        'current_attempt' => $retryattempt,
                        'next_attempt' => $retryattempt + 1
                    ]
                );
                
                $this->schedule_retry($requestid, $fileid, $retryattempt);
                return; // Exit gracefully, retry will be executed
            }
            
            // Max retries reached or missing required data - mark as permanent failure
            if ($requestid) {
                coursetransfer_logger::error(
                    $requestid,
                    coursetransfer_logger::DIRECTION_TARGET,
                    coursetransfer_logger::ACTION_RESTORE_FAILED,
                    'Critical exception during restore after ' . $retryattempt . ' attempts: ' . $e->getMessage(),
                    $e->getCode() ?: '11000',
                    ['exception' => get_class($e), 'retry_attempts' => $retryattempt]
                );
            }
            
            // Update request status if we have request object
            if (isset($request) && $request) {
                $request->status = coursetransfer_request::STATUS_ERROR;
                $request->error_code = $e->getCode() ?: '11000';
                $request->error_message = 'Restore failed after ' . $retryattempt . ' attempts: ' . $e->getMessage();
                
                try {
                    coursetransfer_request::insert_or_update($request, $request->id);
                } catch (\Exception $updateException) {
                    $this->log('Failed to update request status: ' . $updateException->getMessage());
                }
            }
            
            $this->log_finish("Restore Backup Course Remote Finishing with ERROR...");
        } finally {
            // CRITICAL: Always release the lock, even if exception occurred
            if (isset($lock) && $lock) {
                try {
                    $lock->release();
                    $this->log("✓ Sequential restore lock released.");
                } catch (\Exception $lockReleaseException) {
                    // Log lock release failure but don't throw
                    $this->log("WARNING: Failed to release lock: " . $lockReleaseException->getMessage());
                    // Try to log to coursetransfer_logger if we have request ID
                    if (isset($requestid) && $requestid) {
                        coursetransfer_logger::warning(
                            $requestid,
                            coursetransfer_logger::DIRECTION_TARGET,
                            'LOCK_RELEASE_FAILED',
                            'Failed to release sequential restore lock',
                            null,
                            ['exception' => $lockReleaseException->getMessage()]
                        );
                    }
                }
            }
        }
    }
    /*
     * Cleanup downloaded backup file after successful restoration
     *
     * @param stored_file $file
     * @return void
     */
    private function cleanup_downloaded_backup($file) {
        try {
            if ($file && $file->get_filename() !== '.') {
                $filename = $file->get_filename();
                $filesize = $file->get_filesize();
                
                // Try to delete file with specific file exception handling
                try {
                    $file->delete();
                    $this->log("Deleted downloaded backup file: {$filename}");
                } catch (\file_exception $fileException) {
                    // File system error - log but don't fail the whole process
                    $requestid = $this->get_custom_data()->requestid ?? null;
                    if ($requestid) {
                        coursetransfer_logger::warning(
                            $requestid,
                            coursetransfer_logger::DIRECTION_TARGET,
                            'FILE_DELETE_FAILED',
                            'Failed to delete backup file (not critical): ' . $fileException->getMessage(),
                            null,
                            [
                                'filename' => $filename,
                                'file_id' => $file->get_id(),
                                'file_size' => $filesize
                            ]
                        );
                    }
                    $this->log("Warning: Could not delete file {$filename}: " . $fileException->getMessage());
                    return; // Exit method, don't log success
                }
                
                // Log the .mbz deletion in target (only if delete succeeded)
                $requestid = $this->get_custom_data()->requestid ?? null;
                if ($requestid) {
                    coursetransfer_logger::info(
                        $requestid,
                        coursetransfer_logger::DIRECTION_TARGET,
                        'TARGET_BACKUP_DELETED',
                        'Target downloaded backup file deleted after successful restore',
                        [
                            'filename' => $filename,
                            'file_size' => $filesize,
                            'file_size_mb' => round($filesize / 1048576, 2),
                            'request_id' => $requestid
                        ]
                    );
                }
            }
        } catch (\Exception $e) {
            // Don't fail the restoration if cleanup fails
            $this->log('Error cleaning up backup file: ' . $e->getMessage());
        }
    }

    /**
     * Notify origin site that restore completed successfully
     * This is called ALWAYS to ensure proper logging in origin, regardless of auto_cleanup setting.
     * The actual file deletion only happens if auto_cleanup_origin_backup is enabled in origin.
     *
     * @param stdClass $request
     * @return void
     */
    private function notify_origin_restore_completed($request) {
        try {
            $site = coursetransfer::get_site_by_url($request->siteurl);
            if ($site) {
                $api_request = new \local_coursetransfer\api\request($site);
                $user = \core_user::get_user($request->userid);
                
                $response = $api_request->target_backup_course_downloaded($request->id, $user);
                
                if ($response->success) {
                    if (isset($response->data->cleaned) && $response->data->cleaned) {
                        $this->log('Successfully notified origin - backup file was cleaned up');
                    } else {
                        $this->log('Successfully notified origin - backup file kept (auto_cleanup disabled)');
                    }
                    
                    coursetransfer_logger::info(
                        $request->id,
                        coursetransfer_logger::DIRECTION_TARGET,
                        'ORIGIN_RESTORE_COMPLETED_NOTIFIED',
                        'Notified origin server that restore completed successfully',
                        [
                            'request_id' => $request->id,
                            'backup_cleaned' => isset($response->data->cleaned) ? $response->data->cleaned : false
                        ]
                    );
                } else {
                    $this->log('Failed to notify origin: ' . json_encode($response->errors));
                    
                    coursetransfer_logger::warning(
                        $request->id,
                        coursetransfer_logger::DIRECTION_TARGET,
                        'ORIGIN_NOTIFICATION_FAILED',
                        'Failed to notify origin that restore completed (will be logged by scheduled cleanup)',
                        null,
                        ['errors' => $response->errors]
                    );
                }
            }
        } catch (\Exception $e) {
            // Don't fail the restore process if notification fails
            // The backup will be cleaned up by the scheduled cleanup task after 48h anyway
            $this->log('Error notifying origin: ' . $e->getMessage());
            
            coursetransfer_logger::warning(
                $request->id,
                coursetransfer_logger::DIRECTION_TARGET,
                'ORIGIN_NOTIFICATION_ERROR',
                'Exception when notifying origin: ' . $e->getMessage(),
                null,
                ['exception' => get_class($e)]
            );
        }
    }

    /**
     * Schedule a retry of this restore task
     * Uses exponential backoff: 5min, 10min, 20min for attempts 1, 2, 3
     *
     * @param int $requestid
     * @param int $fileid
     * @param int $currentattempt
     * @return void
     */
    /**
     * Attempt to download and recreate a missing backup file "just-in-time".
     *
     * @param string $fileurl
     * @param int $targetcourseid
     * @param int $origincourseid
     * @return \stored_file|null
     * @throws \Exception
     */
    private function recover_missing_file(string $fileurl, int $targetcourseid, int $origincourseid) {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        
        // 1. Download to temp file
        // We use a simpler download compared to download_task because we are in a recovery mode
        // and we assume the file exists (since we have the URL).
        
        $tempdir = make_temp_directory('coursetransfer_recovery');
        $tempfile = $tempdir . '/' . md5($fileurl . microtime()) . '.mbz';
        
        $fp = fopen($tempfile, 'w+');
        if (!$fp) {
            throw new \Exception('Could not create temp file for recovery');
        }
        
        $ch = curl_init($fileurl);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3600); // 1 hour timeout
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FAILONERROR, true);
        
        $exec = curl_exec($ch);
        $error = curl_error($ch);
        $curlinfo = curl_getinfo($ch); 
        curl_close($ch);
        fclose($fp);
        
        if (!$exec || $curlinfo['http_code'] != 200) {
            @unlink($tempfile);
            throw new \Exception('Download failed during recovery: ' . $error . ' HTTP: ' . $curlinfo['http_code']);
        }
        
        if (filesize($tempfile) < 100) {
             @unlink($tempfile);
             throw new \Exception('Recovered file is too small (invalid download)');
        }
        
        // 2. Create Moodle stored_file
        $fs = get_file_storage();
        $fileinfo = [
            'contextid' => \context_system::instance()->id,
            'component' => 'local_coursetransfer',
            'filearea' => 'backup',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'recovery_' . $origincourseid . '_' . time() . '.mbz',
            'timecreated' => time(),
            'timemodified' => time(),
            'userid' => get_admin()->id
        ];
        
        $file = $fs->create_file_from_pathname($fileinfo, $tempfile);
        @unlink($tempfile);
        
        return $file;
    }

    /**
     * Schedule a retry of this restore task
     * Uses exponential backoff: 5min, 10min, 20min for attempts 1, 2, 3
     *
     * @param int $requestid
     * @param int $fileid
     * @param int $currentattempt
     * @return void
     */
    private function schedule_retry($requestid, $fileid, $currentattempt) {
        $nextattempt = $currentattempt + 1;
        
        // Exponential backoff: 5min, 10min, 20min
        $delay = self::BASE_RETRY_DELAY * pow(2, $currentattempt);
        
        // Retrieve fileurl to pass to next retry
        $fileurl = isset($this->get_custom_data()->fileurl) ? $this->get_custom_data()->fileurl : null;
        
        $retrytask = new restore_course_task();
        $retrytask->set_blocking(false);
        $data = [
            'requestid' => $requestid,
            'fileid' => $fileid,
            'retry_attempt' => $nextattempt,
            // Reset reschedule counter for retry (fresh start for concurrency handling)
            'reschedule_count' => 0
        ];
        if ($fileurl) {
            $data['fileurl'] = $fileurl;
        }
        $retrytask->set_custom_data($data);
        
        // Schedule task to run after delay
        $retrytask->set_next_run_time(time() + $delay);
        
        \core\task\manager::queue_adhoc_task($retrytask);
        
        $this->log("Retry #{$nextattempt} scheduled to run in {$delay} seconds (" . 
            gmdate('i\m s\s', $delay) . ") - Will retry with fresh concurrency state");
    }

    /**
     * Check if there are other restore tasks currently running
     * 
     * This checks the adhoc_task table for other restore_course_task instances
     * that are currently in "running" state (faildelay is set and timestarted is recent)
     *
     * @param int $mytaskid The ID of current task to exclude from check
     * @return int Number of other running restore tasks
     */
    private function check_running_restore_tasks($mytaskid) {
        global $DB;
        
        try {
            // Query for running restore tasks
            // A task is "running" if:
            // 1. It's a restore_course_task (classname match)
            // 2. It has been started recently (timestarted within last 2 hours)
            // 3. It's not this task (different id)
            // 4. faildelay is NULL (not failed) or timestarted is very recent (within 30 minutes = actively running)
            
            $sql = "SELECT COUNT(*) 
                    FROM {task_adhoc} 
                    WHERE classname = :classname
                      AND id != :mytaskid
                      AND timestarted IS NOT NULL
                      AND timestarted > :recenttime
                      AND (faildelay IS NULL OR timestarted > :activetime)";
            
            $params = [
                'classname' => '\\local_coursetransfer\\task\\restore_course_task',
                'mytaskid' => $mytaskid,
                'recenttime' => time() - 7200,  // 2 hours ago
                'activetime' => time() - 1800   // 30 minutes ago (actively running)
            ];
            
            $count = $DB->count_records_sql($sql, $params);
            
            return (int)$count;
            
        } catch (\Exception $e) {
            // If check fails, log and return 0 (assume no tasks running to avoid blocking forever)
            $this->log("Warning: Could not check running tasks: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Clean up temporary restore files after successful restore
     * 
     * Large backups can leave temp files in moodledata/temp/backup/ that cause issues
     * for subsequent restores, especially with quiz/question bank data.
     * Reference: https://moodle.org/mod/forum/discuss.php?d=425306
     *
     * @param int $courseid The course ID that was restored
     * @return void
     */
    private function cleanup_temp_restore_files($courseid) {
        global $CFG;
        
        try {
            $tempdir = $CFG->tempdir . '/backup';
            
            if (!is_dir($tempdir)) {
                return;
            }
            
            // Find directories related to this restore (within last hour)
            $cutoff_time = time() - 3600; // 1 hour ago
            
            $cleaned = 0;
            $dirs = glob($tempdir . '/*', GLOB_ONLYDIR);
            
            foreach ($dirs as $dir) {
                // Only clean old temp directories (older than 1 hour or from completed restores)
                $mtime = filemtime($dir);
                if ($mtime < $cutoff_time) {
                    try {
                        remove_dir($dir);
                        $cleaned++;
                    } catch (\Exception $e) {
                        // Log but don't fail - cleanup is not critical
                        $this->log("Could not remove temp dir {$dir}: " . $e->getMessage());
                    }
                }
            }
            
            if ($cleaned > 0) {
                $this->log("Cleaned up {$cleaned} temporary restore directory(ies)");
            }
            
        } catch (\Exception $e) {
            // Don't fail the restore if cleanup fails
            $this->log("Warning: Could not clean temp files: " . $e->getMessage());
        }
    }

    /**
     * Execute restore in isolated CLI process.
     *
     * This prevents Moodle's static cache contamination that causes
     * "not_specified_restore_task" errors in quiz restore plugins.
     *
     * @param int $requestid Request ID
     * @return array ['success' => bool, 'error' => string|null, 'exit_code' => int]
     */
    private function execute_restore_cli($requestid, $fileid = null) {
        global $CFG;

        $php_binary = $this->get_php_binary();
        $cli_script = $CFG->dirroot . '/local/coursetransfer/cli/restore_course_cli.php';

        if (!file_exists($cli_script)) {
            $this->log("CLI script not found: {$cli_script}");
            // Fallback to direct restore
            $request = coursetransfer_request::get($requestid);
            $fs = get_file_storage();
            if ($fileid) {
                $file = $fs->get_file_by_id($fileid);
            } else {
                $context = context_system::instance();
                $files = $fs->get_area_files($context->id, 'local_coursetransfer', 'backup', $requestid, 'id DESC', false);
                $file = !empty($files) ? reset($files) : null;
            }
            if ($file) {
                $success = coursetransfer_restore::restore_course($request, $file);
                return ['success' => $success, 'error' => $success ? null : 'Direct restore failed', 'exit_code' => $success ? 0 : 1];
            }
            return ['success' => false, 'error' => 'CLI script not found and no backup file', 'exit_code' => 1];
        }

        // Build command - pass both requestid and fileid to CLI
        $cmd = sprintf(
            '%s %s --requestid=%d --fileid=%d 2>&1',
            escapeshellcmd($php_binary),
            escapeshellarg($cli_script),
            $requestid,
            $fileid ? (int)$fileid : 0
        );

        $this->log("Executing CLI: {$cmd}");

        // Execute with timeout (90 minutes for large courses)
        $timeout = 5400;
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes, $CFG->dirroot);

        if (!is_resource($process)) {
            return ['success' => false, 'error' => 'Failed to start CLI process', 'exit_code' => -1];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $start_time = time();

        while (true) {
            $status = proc_get_status($process);

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);

            if ($stdout) {
                $output .= $stdout;
                foreach (explode("\n", trim($stdout)) as $line) {
                    if (!empty(trim($line))) {
                        $this->log("[CLI] " . $line);
                    }
                }
            }

            if ($stderr) {
                $output .= $stderr;
                foreach (explode("\n", trim($stderr)) as $line) {
                    if (!empty(trim($line))) {
                        $this->log("[CLI ERR] " . $line);
                    }
                }
            }

            if (!$status['running']) {
                break;
            }

            if ((time() - $start_time) > $timeout) {
                $this->log("CLI process timeout after {$timeout}s");
                proc_terminate($process, 9);
                break;
            }

            usleep(100000);
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        if ($stdout) $output .= $stdout;
        if ($stderr) $output .= $stderr;

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit_code = proc_close($process);

        $this->log("CLI process exited with code: {$exit_code}");

        return [
            'success' => ($exit_code === 0),
            'error' => ($exit_code !== 0) ? "CLI exited with code {$exit_code}" : null,
            'exit_code' => $exit_code
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

