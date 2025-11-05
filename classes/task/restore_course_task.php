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
     * Execute.
     *
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function execute() {
        
        try {
            $this->log_start("Restore Backup Course Remote Starting...");

            $fileid = $this->get_custom_data()->fileid;
            $requestid = $this->get_custom_data()->requestid;
            
            // Get retry attempt number (0 for first attempt)
            $retryattempt = isset($this->get_custom_data()->retry_attempt) ? 
                $this->get_custom_data()->retry_attempt : 0;

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
                'Backup file not found in Moodle file system',
                '11100',
                ['file_id' => $fileid]
            );
            
            $request->status = coursetransfer_request::STATUS_ERROR;
            $request->error_code = '11100';
            $request->error_message = 'Restore in Moodle not working beacuse File not found! :' . $fileid;
            coursetransfer_request::insert_or_update($request, $requestid);
        } else {
            $success = coursetransfer_restore::restore_course($request, $file);
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

                // Cleanup downloaded backup file if auto cleanup is enabled
                if (get_config('local_coursetransfer', 'auto_cleanup_target_backup')) {
                    $this->cleanup_downloaded_backup($file);
                }

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
        }
    }    /**
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
    private function schedule_retry($requestid, $fileid, $currentattempt) {
        $nextattempt = $currentattempt + 1;
        
        // Exponential backoff: 5min, 10min, 20min
        $delay = self::BASE_RETRY_DELAY * pow(2, $currentattempt);
        
        $retrytask = new restore_course_task();
        $retrytask->set_blocking(false);
        $retrytask->set_custom_data([
            'requestid' => $requestid,
            'fileid' => $fileid,
            'retry_attempt' => $nextattempt,
        ]);
        
        // Schedule task to run after delay
        $retrytask->set_next_run_time(time() + $delay);
        
        \core\task\manager::queue_adhoc_task($retrytask);
        
        $this->log("Retry #{$nextattempt} scheduled to run in {$delay} seconds (" . 
            gmdate('i\m s\s', $delay) . ")");
    }
}

