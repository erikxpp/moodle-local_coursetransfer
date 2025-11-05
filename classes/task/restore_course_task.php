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
                        'file_size' => $file->get_filesize()
                    ]
                );
                
                $request->status = coursetransfer_request::STATUS_COMPLETED;
                coursetransfer_request::insert_or_update($request, $request->id);

                // Notify origin that restore completed successfully so it can safely cleanup backup .mbz file
                // This prevents race condition where backup is deleted before restore completes
                // IMPORTANT: This only deletes the .mbz file, NEVER the original course or category
                if (get_config('local_coursetransfer', 'auto_cleanup_origin_backup')) {
                    $this->notify_origin_restore_completed($request);
                }

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
                $this->log('Restore in Moodle is Failed!');
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
            
            // Try to get request object if not set
            if (!isset($request) && $requestid) {
                try {
                    $request = coursetransfer_request::get($requestid);
                } catch (\Exception $getException) {
                    $this->log('Could not retrieve request: ' . $getException->getMessage());
                }
            }
            
            // Log exception error
            if ($requestid) {
                coursetransfer_logger::error(
                    $requestid,
                    coursetransfer_logger::DIRECTION_TARGET,
                    coursetransfer_logger::ACTION_RESTORE_FAILED,
                    'Critical exception during restore: ' . $e->getMessage(),
                    $e->getCode() ?: '11000',
                    ['exception' => get_class($e), 'trace' => $e->getTraceAsString()]
                );
            }
            
            // Update request status if we have request object
            if (isset($request) && $request) {
                $request->status = coursetransfer_request::STATUS_ERROR;
                $request->error_code = $e->getCode() ?: '11000';
                $request->error_message = 'Restore failed: ' . $e->getMessage();
                
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
                $file->delete();
                $this->log("Deleted downloaded backup file: {$filename}");
            }
        } catch (\Exception $e) {
            // Don't fail the restoration if cleanup fails
            $this->log('Error cleaning up backup file: ' . $e->getMessage());
        }
    }

    /**
     * Notify origin site that restore completed successfully and backup can be safely deleted
     * This prevents race condition where backup is deleted before restore finishes
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
                    $this->log('Successfully notified origin to cleanup backup file after restore completion');
                    
                    coursetransfer_logger::info(
                        $request->id,
                        coursetransfer_logger::DIRECTION_TARGET,
                        'ORIGIN_CLEANUP_NOTIFIED',
                        'Notified origin server that restore completed and backup can be safely deleted',
                        ['request_id' => $request->id]
                    );
                } else {
                    $this->log('Failed to notify origin for cleanup: ' . json_encode($response->errors));
                    
                    coursetransfer_logger::warning(
                        $request->id,
                        coursetransfer_logger::DIRECTION_TARGET,
                        'ORIGIN_CLEANUP_NOTIFICATION_FAILED',
                        'Failed to notify origin for cleanup, but restore was successful',
                        null,
                        ['errors' => $response->errors]
                    );
                }
            }
        } catch (\Exception $e) {
            // Don't fail the restore process if notification fails
            // The backup will be cleaned up by the scheduled cleanup task after 24h anyway
            $this->log('Error notifying origin for cleanup: ' . $e->getMessage());
            
            coursetransfer_logger::warning(
                $request->id,
                coursetransfer_logger::DIRECTION_TARGET,
                'ORIGIN_CLEANUP_NOTIFICATION_ERROR',
                'Exception when notifying origin for cleanup: ' . $e->getMessage(),
                null,
                ['exception' => get_class($e)]
            );
        }
    }
}
