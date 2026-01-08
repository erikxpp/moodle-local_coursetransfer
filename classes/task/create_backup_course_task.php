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

use async_helper;
use local_coursetransfer\api\request;
use local_coursetransfer\coursetransfer;
use local_coursetransfer\coursetransfer_logger;
use local_coursetransfer\coursetransfer_request;
use local_coursetransfer\coursetransfer_sites;
use moodle_exception;
use stdClass;

/**
 * logs_course_response_table
 *
 * @package    local_coursetransfer
 * @copyright  2023 Proyecto UNIMOODLE
 * @author     UNIMOODLE Group (Coordinator) <direccion.area.estrategia.digital@uva.es>
 * @author     3IPUNT <contacte@tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_backup_course_task extends \core\task\asynchronous_backup_task {

    // Use the logging trait to get some nice, juicy, logging.
    use \core\task\logging_trait;

    /** @var stdClass Site (host & token) */
    public stdClass $site;

    /** Maximum attempts to create backup file (handles transient failures) */
    const MAX_FILE_CREATION_ATTEMPTS = 3;
    
    /** Retry delays in seconds: [0, 10, 30] = immediate, 10s, 30s */
    const RETRY_DELAYS = [0, 10, 30];

    /**
     * Execute the task.
     *
     */
    public function execute() {
        global $DB;

        $started = time();

        try {

            $this->log_start("Course Transfer Backup Starting...");

            $istest = $this->get_custom_data()->istest;
            $backupid = $this->get_custom_data()->backupid;
            $requestid = $this->get_custom_data()->requestid;
            $siteid = $this->get_custom_data()->targetsite;
            $requestoriginid = $this->get_custom_data()->requestoriginid;

            // Log backup started
            coursetransfer_logger::info(
                $requestoriginid,
                coursetransfer_logger::DIRECTION_ORIGIN,
                coursetransfer_logger::ACTION_BACKUP_STARTED,
                'Starting backup for backup ID: ' . $backupid,
                ['backup_id' => $backupid, 'adhoc_task_id' => $this->get_id()]
            );

            if (!$backupid) {
                throw new moodle_exception('BACKUP ID NOT FOUND');
            }
            if (!$requestoriginid) {
                throw new moodle_exception('REQUEST ORIGIN ID NOT FOUND');
            }

            $bc = \backup_controller::load_controller($backupid);

            $backuprecord = $DB->get_record(
                    'backup_controllers', ['backupid' => $backupid], 'id, controller', MUST_EXIST);
            mtrace('Processing asynchronous backup for backup: ' . $backupid);

            // Get the backup controller by backup id. If controller is invalid, this task can never complete.
            if ($backuprecord->controller === '') {
                mtrace('Bad backup controller status, invalid controller, ending backup execution.');
                
                // Mark request as error since controller is invalid
                $requestorigin = coursetransfer_request::get($requestoriginid);
                if ($requestorigin) {
                    $requestorigin->status = coursetransfer_request::STATUS_ERROR;
                    $requestorigin->error_code = 13002;
                    $requestorigin->error_message = 'Invalid backup controller - backup cannot proceed';
                    coursetransfer_request::insert_or_update($requestorigin, $requestorigin->id);
                    
                    coursetransfer_logger::error(
                        $requestoriginid,
                        coursetransfer_logger::DIRECTION_ORIGIN,
                        coursetransfer_logger::ACTION_BACKUP_FAILED,
                        $requestorigin->error_message,
                        $requestorigin->error_code
                    );
                    
                    // Notify target
                    if (!$istest) {
                        $site = coursetransfer_sites::get('target', $siteid);
                        $request = new request($site);
                        $userid = $requestorigin->userid;
                        $user = \core_user::get_user($userid);
                        $request->target_backup_course_error($user, $requestid, $requestorigin->error_message, []);
                    }
                }
                return;
            }

            $bc->set_progress(new \core\progress\db_updater($backuprecord->id, 'backup_controllers', 'progress'));

            // Do some preflight checks on the backup.
            $status = $bc->get_status();
            $execution = $bc->get_execution();

            // Check that the backup is in the correct status and
            // that is set for asynchronous execution.
            if ($status == \backup::STATUS_AWAITING && $execution == \backup::EXECUTION_DELAYED) {
                // Execute the backup - wrap in try-catch to handle execution errors
                try {
                    $bc->execute_plan();
                    
                    // Send message to user if enabled.
                    $messageenabled = (bool)get_config('backup', 'backup_async_message_users');
                    if ($messageenabled && $bc->get_status() == \backup::STATUS_FINISHED_OK) {
                        $asynchelper = new async_helper('backup', $backupid);
                        $asynchelper->send_message();
                    }
                } catch (\Exception $executeException) {
                    // Log execution failure
                    mtrace('Backup execution failed with exception: ' . $executeException->getMessage());
                    $bc->set_status(\backup::STATUS_FINISHED_ERR);
                    
                    coursetransfer_logger::error(
                        $requestoriginid,
                        coursetransfer_logger::DIRECTION_ORIGIN,
                        coursetransfer_logger::ACTION_BACKUP_FAILED,
                        'Backup execution threw exception: ' . $executeException->getMessage(),
                        $executeException->getCode(),
                        [
                            'exception' => get_class($executeException),
                            'file' => $executeException->getFile(),
                            'line' => $executeException->getLine()
                        ]
                    );
                }

            } else {
                // If status isn't 700, it means the process has failed.
                // Retrying isn't going to fix it, so marked operation as failed.
                $bc->set_status(\backup::STATUS_FINISHED_ERR);
                mtrace('Bad backup controller status, is: ' . $status . ' should be 700, marking job as failed.');
            }

            $result = $bc->get_results();
            $userid = $bc->get_userid();
            $user = \core_user::get_user($userid);
            $site = coursetransfer_sites::get('target', $siteid);
            $request = new request($site);

            $requestorigin = coursetransfer_request::get($requestoriginid);
            if ($bc->get_status() === \backup::STATUS_FINISHED_OK) {
                mtrace('Course Transfer Backup - Creating File ... ');
                
                // Implement retry strategy for file creation (handles transient failures like file flush delays)
                $resfileurl = null;
                $backupfile = $result['backup_destination'];
                
                for ($attempt = 0; $attempt < self::MAX_FILE_CREATION_ATTEMPTS; $attempt++) {
                    // Apply delay before retry attempts (not on first attempt)
                    if ($attempt > 0) {
                        $delay = self::RETRY_DELAYS[$attempt];
                        mtrace("Course Transfer Backup - Retry attempt {$attempt}/" . 
                            self::MAX_FILE_CREATION_ATTEMPTS . " after {$delay}s delay...");
                        sleep($delay);
                        
                        // Verify temporary backup file still exists before retrying
                        if (!$backupfile || !$backupfile->get_filesize()) {
                            mtrace('Temporary backup file no longer accessible, cannot retry');
                            coursetransfer_logger::warning(
                                $requestoriginid,
                                coursetransfer_logger::DIRECTION_ORIGIN,
                                'FILE_RETRY_ABORTED',
                                'Temporary backup file disappeared, aborting retry',
                                null,
                                ['attempt' => $attempt, 'max_attempts' => self::MAX_FILE_CREATION_ATTEMPTS]
                            );
                            break;
                        }
                    }
                    
                    // Attempt to create backup file URL
                    $resfileurl = coursetransfer::create_backupfile_url(
                        $bc->get_courseid(), $backupfile, $requestorigin->id);
                    
                    if ($resfileurl->success) {
                        if ($attempt > 0) {
                            mtrace("Course Transfer Backup - File creation succeeded on attempt " . ($attempt + 1));
                            coursetransfer_logger::info(
                                $requestoriginid,
                                coursetransfer_logger::DIRECTION_ORIGIN,
                                'FILE_CREATION_RETRY_SUCCESS',
                                "File creation succeeded after {$attempt} retry attempts",
                                ['attempt' => $attempt + 1, 'total_attempts' => self::MAX_FILE_CREATION_ATTEMPTS]
                            );
                        }
                        break; // Success - exit retry loop
                    }
                    
                    // Log failed attempt
                    mtrace("Course Transfer Backup - Attempt " . ($attempt + 1) . " failed: " . $resfileurl->error);
                    coursetransfer_logger::warning(
                        $requestoriginid,
                        coursetransfer_logger::DIRECTION_ORIGIN,
                        'FILE_CREATION_ATTEMPT_FAILED',
                        "File creation attempt failed: {$resfileurl->error}",
                        null,
                        [
                            'attempt' => $attempt + 1,
                            'max_attempts' => self::MAX_FILE_CREATION_ATTEMPTS,
                            'error' => $resfileurl->error
                        ]
                    );
                }
                
                // Check final result after all retry attempts
                if ($resfileurl->success) {
                    mtrace('Course Transfer Backup - Creating File OK');
                    
                    // Wait a moment to ensure file is fully written and accessible
                    sleep(2);
                    
                    // Verify file is actually accessible before notifying target
                    $fileurl = $resfileurl->fileurl;
                    $fileaccessible = self::verify_file_accessible($fileurl);
                    
                    if (!$fileaccessible) {
                        mtrace('Course Transfer Backup - File created but not accessible yet, waiting...');
                        sleep(3);
                        $fileaccessible = self::verify_file_accessible($fileurl);
                    }
                    
                    if (!$fileaccessible) {
                        throw new moodle_exception('Backup file created but not accessible via webservice');
                    }
                    
                    // Log successful backup completion
                    coursetransfer_logger::success(
                        $requestoriginid,
                        coursetransfer_logger::DIRECTION_ORIGIN,
                        coursetransfer_logger::ACTION_BACKUP_COMPLETED,
                        'Backup file created successfully',
                        [
                            'file_url' => $resfileurl->fileurl,
                            'file_size' => $resfileurl->filesize,
                            'backup_id' => $backupid,
                            'duration_seconds' => time() - $started
                        ]
                    );
                    
                    if ($requestorigin) {
                        $requestorigin->fileurl = $resfileurl->fileurl;
                        $requestorigin->origin_backup_url = $resfileurl->fileurl;
                        $requestorigin->origin_backup_size = $resfileurl->filesize;
                        
                        // Update request with specific DB error handling
                        try {
                            coursetransfer_request::insert_or_update($requestorigin, $requestorigin->id);
                        } catch (\dml_exception $dbException) {
                            // Database error during request update
                            coursetransfer_logger::error(
                                $requestoriginid,
                                coursetransfer_logger::DIRECTION_ORIGIN,
                                'DATABASE_UPDATE_FAILED',
                                'Failed to update request after successful backup creation',
                                $dbException->getCode(),
                                [
                                    'table' => 'local_coursetransfer_request',
                                    'operation' => 'update_backup_info',
                                    'request_id' => $requestorigin->id,
                                    'db_error' => $dbException->getMessage()
                                ]
                            );
                            throw $dbException; // Re-throw to outer catch
                        }
                    }
                    if (!$istest) {
                        // Pass origin_request_id so target can store it for correct cleanup identification
                        $res = $request->target_backup_course_completed(
                                $resfileurl->fileurl, $requestid, $resfileurl->filesize, $user, $requestoriginid);
                    }
                    $requestorigin->status = coursetransfer_request::STATUS_COMPLETED;
                } else {
                    // CRITICAL FIX: Mark request as ERROR when file creation fails
                    mtrace('Course Transfer Backup - Creating File ERROR');
                    
                    $requestorigin->status = coursetransfer_request::STATUS_ERROR;
                    $requestorigin->error_code = 10201;
                    $requestorigin->error_message = 'Failed to create backup file: ' . $resfileurl->error;
                    
                    // Log backup file creation error
                    coursetransfer_logger::error(
                        $requestoriginid,
                        coursetransfer_logger::DIRECTION_ORIGIN,
                        coursetransfer_logger::ACTION_BACKUP_FAILED,
                        $requestorigin->error_message,
                        $requestorigin->error_code,
                        ['raw_error' => $resfileurl->error]
                    );
                    
                    // Notify target about the error
                    if (!$istest) {
                        $res = $request->target_backup_course_error(
                                $user, $requestid, $resfileurl->error, [], $resfileurl->filesize);
                    }
                }
            } else {
                // Backup failed - mark as error
                $requestorigin->status = coursetransfer_request::STATUS_ERROR;
                $requestorigin->error_code = 13001;
                $requestorigin->error_message = 'Backup execution failed with status: ' . $bc->get_status();
                
                // Log backup execution error
                coursetransfer_logger::error(
                    $requestoriginid,
                    coursetransfer_logger::DIRECTION_ORIGIN,
                    coursetransfer_logger::ACTION_BACKUP_FAILED,
                    $requestorigin->error_message,
                    $requestorigin->error_code,
                    ['result' => $result, 'status' => $bc->get_status()]
                );
                
                // Notify target about the error
                if (!$istest) {
                    $res = $request->target_backup_course_error($user, $requestid, $requestorigin->error_message, $result);
                    
                    // Check if notification failed
                    if (!$res->success) {
                        mtrace('Failed to notify target about backup error: ' . $res->errors[0]->msg);
                    }
                }
            }
            
            // Final status check and save
            if (!$istest && isset($res) && !$res->success) {
                $requestorigin->status = coursetransfer_request::STATUS_ERROR;
                if (isset($res->errors[0])) {
                    $requestorigin->error_code = $res->errors[0]->code;
                    $requestorigin->error_message = $res->errors[0]->msg;
                }
                mtrace('Course Transfer Backup ERROR: ' . $requestorigin->error_message);
                $this->log(json_encode($res));
            }
            
            coursetransfer_request::insert_or_update($requestorigin, $requestorigin->id);
            $bc->destroy();
        } catch (\Throwable $e) {
            // Catch ALL types of errors including moodle_exception, Exception, Error, Fatal errors, etc.
            mtrace('Course Transfer Backup ERROR: ' . $e->getMessage());
            $this->log($e->getMessage());
            
            // Ensure we have minimum required variables
            if (!isset($requestoriginid)) {
                $requestoriginid = isset($this->get_custom_data()->requestoriginid) ? 
                    $this->get_custom_data()->requestoriginid : null;
            }
            if (!isset($requestid)) {
                $requestid = isset($this->get_custom_data()->requestid) ? 
                    $this->get_custom_data()->requestid : null;
            }
            if (!isset($istest)) {
                $istest = isset($this->get_custom_data()->istest) ? 
                    $this->get_custom_data()->istest : true;
            }
            
            // Try to get or create request object
            if (!isset($requestorigin) && $requestoriginid) {
                try {
                    $requestorigin = coursetransfer_request::get($requestoriginid);
                } catch (\Exception $getException) {
                    mtrace('Could not retrieve request: ' . $getException->getMessage());
                }
            }
            
            // Log exception error if we have request
            if (isset($requestoriginid) && $requestoriginid) {
                coursetransfer_logger::error(
                    $requestoriginid,
                    coursetransfer_logger::DIRECTION_ORIGIN,
                    coursetransfer_logger::ACTION_BACKUP_FAILED,
                    'Exception during backup: ' . $e->getMessage(),
                    $e->getCode() ?: 13003,
                    ['exception' => get_class($e), 'trace' => $e->getTraceAsString()]
                );
            }
            
            // Update local status to ERROR if we have request object
            if (isset($requestorigin) && $requestorigin) {
                $requestorigin->status = coursetransfer_request::STATUS_ERROR;
                $requestorigin->error_code = $e->getCode() ?: 13003;
                $requestorigin->error_message = 'Backup failed: ' . $e->getMessage();
                
                try {
                    coursetransfer_request::insert_or_update($requestorigin, $requestorigin->id);
                } catch (\Exception $updateException) {
                    mtrace('Failed to update request status: ' . $updateException->getMessage());
                }
                
                // Notify target about the error
                if (!$istest && $requestid) {
                    try {
                        if (!isset($siteid)) {
                            $siteid = $this->get_custom_data()->targetsite ?? null;
                        }
                        if ($siteid) {
                            $site = coursetransfer_sites::get('target', $siteid);
                            $request = new request($site);
                            $userid = $requestorigin->userid ?? 2; // Fallback to admin
                            $user = \core_user::get_user($userid);
                            $request->target_backup_course_error(
                                $user, $requestid, $requestorigin->error_message, [], 0
                            );
                        }
                    } catch (\Exception $notifyException) {
                        mtrace('Failed to notify target about backup error: ' . $notifyException->getMessage());
                    }
                }
            }
            
            // Clean up backup controller if exists
            if (isset($bc) && $bc) {
                try {
                    $bc->destroy();
                } catch (\Exception $destroyException) {
                    mtrace('Failed to destroy backup controller: ' . $destroyException->getMessage());
                }
            }
        }
        $this->log_finish("Course Transfer Backup Finishing...");

        $duration = time() - $started;
        mtrace('Backup completed in: ' . $duration . ' seconds');
    }

    /**
     * Verify that backup file is accessible via webservice
     *
     * @param string $fileurl
     * @return bool
     */
    protected static function verify_file_accessible(string $fileurl): bool {
        try {
            // Extract file path components from URL
            $fs = get_file_storage();
            
            // Simple check: verify URL is well-formed and has required parameters
            if (strpos($fileurl, '/webservice/pluginfile.php/') === false) {
                return false;
            }
            
            // Parse URL to get file components
            $urlparts = parse_url($fileurl);
            if (!$urlparts || !isset($urlparts['path'])) {
                return false;
            }
            
            // Extract path components
            $pathparts = explode('/', trim($urlparts['path'], '/'));
            
            // Expected format: webservice/pluginfile.php/{contextid}/{component}/{filearea}/{itemid}/{filename}
            if (count($pathparts) < 7) {
                return false;
            }
            
            $contextid = $pathparts[2];
            $component = $pathparts[3];
            $filearea = $pathparts[4];
            $itemid = $pathparts[5];
            $filename = $pathparts[6];
            
            // Check if file exists in Moodle file storage
            $file = $fs->get_file($contextid, $component, $filearea, $itemid, '/', $filename);
            
            return ($file && !$file->is_directory() && $file->get_filesize() > 0);
            
        } catch (\Exception $e) {
            mtrace('File accessibility check failed: ' . $e->getMessage());
            return false;
        }
    }

}
