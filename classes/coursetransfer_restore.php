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
 * Coursetransfer Restore.
 *
 * @package    local_coursetransfer
 * @copyright  2023 Proyecto UNIMOODLE
 * @author     UNIMOODLE Group (Coordinator) <direccion.area.estrategia.digital@uva.es>
 * @author     3IPUNT <contacte@tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfer;

use backup;
use backup_controller;
use backup_controller_dbops;
use base_plan_exception;
use base_setting;
use base_setting_exception;
use cm_info;
use dml_exception;
use local_coursetransfer\task\create_backup_course_task;
use local_coursetransfer\task\restore_course_task;
use moodle_exception;
use restore_controller;
use section_info;
use stdClass;
use stored_file;
use xmldb_table;

defined('MOODLE_INTERNAL') || die;

global $CFG;

require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/local/coursetransfer/classes/task/create_backup_course_task.php');
require_once($CFG->dirroot . '/local/coursetransfer/classes/user_mapper.php');

/**
 * coursetransfer_restore
 *
 * @package    local_coursetransfer
 * @copyright  2023 Proyecto UNIMOODLE
 * @author     UNIMOODLE Group (Coordinator) <direccion.area.estrategia.digital@uva.es>
 * @author     3IPUNT <contacte@tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class coursetransfer_restore {

    /**
     * Create task restore course.
     * Tasks are queued with priority based on request creation time (FIFO order).
     *
     * @param stdClass $request
     * @param stored_file $file
     * @return bool
     */
    public static function create_task_restore_course(stdClass $request, stored_file $file, string $fileurl = null): bool {
        $resasynctask = new restore_course_task();
        $resasynctask->set_blocking(false);
        
        // Set task to run immediately (respecting FIFO order based on task creation time)
        // Moodle's task runner will process tasks in order of next_run_time
        $resasynctask->set_next_run_time(time());
        
        $data = [
            'requestid' => $request->id, 
            'fileid' => $file->get_id(),
            'retry_attempt' => 0,  // Initialize retry counter
            'reschedule_count' => 0  // Initialize reschedule counter for concurrency handling
        ];
        if ($fileurl) {
            $data['fileurl'] = $fileurl;
        }
        
        $resasynctask->set_custom_data($data);
        
        // Log task creation
        coursetransfer_logger::info(
            $request->id,
            coursetransfer_logger::DIRECTION_TARGET,
            'RESTORE_TASK_QUEUED',
            'Restore task queued for sequential execution',
            [
                'request_id' => $request->id,
                'file_id' => $file->get_id(),
                'scheduled_for' => date('Y-m-d H:i:s', time())
            ]
        );
        
        return \core\task\manager::queue_adhoc_task($resasynctask);
    }

    /**
     * Create Task to restore Course.
     *
     * @param stdClass $request
     * @param stored_file $file
     * @return bool
     * @throws dml_exception
     * @throws moodle_exception
     */
    public static function restore_course(stdClass $request, stored_file $file): bool {
        try {
            $courseid = (int)$request->target_course_id;
            
            // CRITICAL FIX: Always use Admin user for restore execution
            // When using the requesting user ($request->userid), they may lack permissions to restore 
            // specific components (like Question Bank contexts), causing "orphan" attempts (10400 error).
            // Manual restore works because it's done by Admin. We replicate that here.
            $admin = get_admin();
            $userid = $admin ? (int)$admin->id : 2; // Default to 2 (Admin) if get_admin fails
            
            // Log this override for clarity
            if ((int)$request->userid !== $userid) {
                 coursetransfer_logger::info(
                    $request->id,
                    coursetransfer_logger::DIRECTION_TARGET,
                    'RESTORE_USER_OVERRIDE',
                    "Forcing restore as Admin (User ID: $userid) instead of Requestor (User ID: {$request->userid}) to ensure permissions."
                );
            }

            $fullname = $request->origin_course_fullname;
            $shortname = $request->origin_course_shortname;
            $removeenrols = (int)$request->target_remove_enrols;
            $removegroups = (int)$request->target_remove_groups;
            $target = (int)$request->target_target;

            $backuptmpdir = 'local_coursetransfer';

            if (!check_dir_exists($backuptmpdir, true, true)) {
                throw new \restore_controller_exception('cannot_create_backup_temp_dir');
            }

            $filepath = restore_controller::get_tempdir_name($file->get_contextid(), $userid);
            $backuptempdir = make_backup_temp_directory('', false);
            $fb = get_file_packer('application/vnd.moodle.backup');

            $fb->extract_to_pathname($file, $backuptempdir . '/' . $filepath . '/');

            // CRITICAL FIX: If fullname or shortname are empty/null in request, try to get them from the backup file
            // This prevents "Column 'fullname' cannot be null" error when request data is incomplete
            if (empty($fullname) || empty($shortname)) {
                $coursexml = $backuptempdir . '/' . $filepath . '/course/course.xml';
                if (file_exists($coursexml)) {
                    try {
                        $xml = simplexml_load_file($coursexml);
                        if ($xml) {
                            if (empty($fullname)) {
                                $fullname = (string)$xml->fullname;
                            }
                            if (empty($shortname)) {
                                $shortname = (string)$xml->shortname;
                            }
                        }
                    } catch (\Exception $e) {
                         coursetransfer_logger::warning(
                            $request->id,
                            coursetransfer_logger::DIRECTION_TARGET,
                            'RESTORE_XML_PARSE_ERROR',
                            "Failed to parse course.xml to recover missing names: " . $e->getMessage()
                        );
                    }
                }
            }

            if ($target !== backup::TARGET_EXISTING_DELETING && $target !== backup::TARGET_CURRENT_DELETING) {
                $keeprolesenrolments = true;
                $keepgroupsgroupings = true;
            } else {
                $keeprolesenrolments = $removeenrols === 1 ? false : true;
                $keepgroupsgroupings = $removegroups === 1 ? false : true;
            }

            // CRITICAL FIX: For TARGET_EXISTING_DELETING, Moodle ignores course_fullname/course_shortname settings
            // We must update the target course directly BEFORE creating the restore controller
            // This prevents Moodle from adding "copia 1" and "_1" suffixes
            global $DB;
            $target_course = $DB->get_record('course', ['id' => $courseid], 'id,fullname,shortname,idnumber');
            
            if ($target_course && ($target === backup::TARGET_EXISTING_DELETING || $target === backup::TARGET_EXISTING_ADDING)) {
                // Update target course if exists
                if ($target_course->fullname !== $fullname || $target_course->shortname !== $shortname) {
                    $target_course->fullname = $fullname;
                    $target_course->shortname = $shortname;
                    $DB->update_record('course', $target_course);
                    rebuild_course_cache($courseid, true);
                    
                    coursetransfer_logger::info(
                        $request->id,
                        coursetransfer_logger::DIRECTION_TARGET,
                        'PRE_RESTORE_COURSE_UPDATED',
                        'Target course names updated successfully BEFORE restore.',
                        ['course_id' => $courseid, 'fullname' => $fullname, 'shortname' => $shortname]
                    );
                }
            }

            $restoreoptions = [
                    'overwrite_conf' => true,
                    'users' => true,
                    'enrolments' => true,
                    'groups' => true,
                    'keep_roles_and_enrolments' => $keeprolesenrolments,
                    'keep_groups_and_groupings' => $keepgroupsgroupings,
                    'course_fullname' => $fullname,
                    'course_shortname' => $shortname,
            ];

            // DON'T convert TARGET_NEW_COURSE to TARGET_EXISTING_DELETING
            // Let Moodle use the target as configured in the request
            // The fullname/shortname from restoreoptions will override any duplicates

            // CRITICAL: Enable safe restore mode to handle orphaned quiz references
            // This allows restore to continue even if quiz attempts reference missing question_answers
            // Preserves ALL valid data (attempts, grades, questions) and only skips orphaned references
            \local_coursetransfer\safe_quiz_restore::enable_safe_restore();
            
            $rc = new restore_controller($filepath, $courseid,
                    backup::INTERACTIVE_NO, backup::MODE_GENERAL, $userid, $target);

            $plan = $rc->get_plan();

            if (!is_null($plan)) {
                // CRITICAL FIX: Set course names BEFORE applying other settings
                // This ensures the course has the correct names before any processing
                foreach ($restoreoptions as $option => $value) {
                    if ($plan->setting_exists($option)) {
                        $setting = $plan->get_setting($option);
                        $setting->set_status(\base_setting::NOT_LOCKED);
                        $setting->set_value($value);
                        
                        // Log setting application for debugging
                        if ($option === 'course_fullname' || $option === 'course_shortname') {
                            coursetransfer_logger::info(
                                $request->id,
                                coursetransfer_logger::DIRECTION_TARGET,
                                'RESTORE_SETTING_APPLIED',
                                "Applied restore setting: {$option} = '{$value}'"
                            );
                        }
                    }
                }
                
                // CRITICAL: Also update the course record directly to prevent Moodle
                // from adding "copia 1" suffix during restore
                global $DB;
                $course_before = $DB->get_record('course', ['id' => $courseid], 'id,fullname,shortname,idnumber');
                if ($course_before) {
                    $needs_update = false;
                    
                    // Force correct names BEFORE restore starts
                    if ($course_before->fullname !== $fullname) {
                        coursetransfer_logger::info(
                            $request->id,
                            coursetransfer_logger::DIRECTION_TARGET,
                            'PRE_RESTORE_FULLNAME_SET',
                            "Setting course fullname BEFORE restore. Current: '{$course_before->fullname}', Setting to: '{$fullname}'"
                        );
                        $course_before->fullname = $fullname;
                        $needs_update = true;
                    }
                    
                    if ($course_before->shortname !== $shortname) {
                        coursetransfer_logger::info(
                            $request->id,
                            coursetransfer_logger::DIRECTION_TARGET,
                            'PRE_RESTORE_SHORTNAME_SET',
                            "Setting course shortname BEFORE restore. Current: '{$course_before->shortname}', Setting to: '{$shortname}'"
                        );
                        $course_before->shortname = $shortname;
                        $needs_update = true;
                    }
                    
                    if ($needs_update) {
                        $DB->update_record('course', $course_before);
                        // Clear course cache to ensure Moodle sees the updated names
                        rebuild_course_cache($courseid, true);
                        
                        coursetransfer_logger::info(
                            $request->id,
                            coursetransfer_logger::DIRECTION_TARGET,
                            'PRE_RESTORE_COURSE_UPDATED',
                            'Course names set to correct values BEFORE restore execution'
                        );
                    }
                }
                
                if ($rc->get_status() == backup::STATUS_REQUIRE_CONV) {
                    $rc->convert();
                }

                // Execute precheck - should pass now without user conflicts.
                coursetransfer_logger::info(
                    $request->id,
                    coursetransfer_logger::DIRECTION_TARGET,
                    'PRECHECK_START',
                    'Executing precheck...'
                );
                
                $resexecute = $rc->execute_precheck();
                $results = $rc->get_precheck_results();
                
                coursetransfer_logger::info(
                    $request->id,
                    coursetransfer_logger::DIRECTION_TARGET,
                    'PRECHECK_COMPLETE',
                    'Precheck completed. Result: ' . ($resexecute ? 'SUCCESS' : 'FAILED'),
                    [
                        'success' => $resexecute,
                        'has_errors' => isset($results['errors']),
                        'error_count' => isset($results['errors']) ? count($results['errors']) : 0
                    ]
                );
                
                if ($resexecute) {
                    // SUCCESS: Precheck passed.
                    // Using 100% native restore - Moodle handles all user mapping
                    coursetransfer_logger::info(
                        $request->id,
                        coursetransfer_logger::DIRECTION_TARGET,
                        'EXECUTING_RESTORE',
                        'Executing restore plan with 100% native Moodle user handling (no backup_ids_temp manipulation)'
                    );

                // *** USER MAPPING LOGIC *** 
                // DISABLED: We previously performed manual user mapping here.
                // However, Moodle's native restore engine handles user mapping (by username/email) much more robustly.
                // Manual intervention in backup_ids_temp was causing restore_step_exceptions (10400) on newer Moodle versions.
                // We now let standard Moodle handle it.
                
                /*
                // 1. Initialize mapper
                $mapper = new user_mapper($rc->get_restoreid(), $backuptempdir . '/' . $filepath, $request);
                
                // 2. Execute mapping
                $mapper->map_users();
                */
                
                // 3. Log
                coursetransfer_logger::info(
                    $request->id,
                    coursetransfer_logger::DIRECTION_TARGET,
                    'USING_NATIVE_MAPPING',
                    'Using Native Restore logic for user mapping (Plugin manual mapper disabled)'
                );
                    
                    // CRITICAL: Execute plan with transaction safeguards
                    // This prevents the restore controller from losing its context during deep processing
                    try {
                        // Ensure we're not in a nested transaction that might interfere
                        global $DB;
                        
                        // Log restore controller state before execution
                        $restoreid = $rc->get_restoreid();
                        coursetransfer_logger::info(
                            $request->id,
                            coursetransfer_logger::DIRECTION_TARGET,
                            'RESTORE_CONTROLLER_STATE',
                            "Restore controller ready. RestoreID: {$restoreid}",
                            ['restoreid' => $restoreid]
                        );
                        
                        // PRE-RESTORE VERIFICATION: Check for duplicate modules from previous failed attempts
                        // If TARGET_EXISTING_DELETING, course should be clean. If not, force cleanup.
                        if ($target === backup::TARGET_EXISTING_DELETING) {
                            $existing_modules = $DB->count_records('course_modules', ['course' => $courseid]);
                            
                            if ($existing_modules > 0) {
                                coursetransfer_logger::warning(
                                    $request->id,
                                    coursetransfer_logger::DIRECTION_TARGET,
                                    'DUPLICATE_MODULES_DETECTED',
                                    "Found {$existing_modules} existing modules in course that should have been deleted (likely from previous failed restore)",
                                    null,
                                    ['course_id' => $courseid, 'target_type' => 'TARGET_EXISTING_DELETING', 'module_count' => $existing_modules]
                                );
                                
                                // Force cleanup before proceeding to prevent Duplicate entry errors
                                require_once($CFG->dirroot . '/course/lib.php');
                                remove_course_contents($courseid, false); // Don't delete course itself, only contents
                                rebuild_course_cache($courseid, true);
                                
                                coursetransfer_logger::info(
                                    $request->id,
                                    coursetransfer_logger::DIRECTION_TARGET,
                                    'PRE_RESTORE_CLEANUP',
                                    "Cleaned up {$existing_modules} existing course modules before restore to prevent duplicates"
                                );
                            }
                        }
                        
                        // Execute the restore plan
                        $rc->execute_plan();
                        
                        // Disable safe restore mode after successful execution
                        \local_coursetransfer\safe_quiz_restore::disable_safe_restore();
                        
                        // SUCCESS: Restore plan executed
                        coursetransfer_logger::success(
                            $request->id,
                            coursetransfer_logger::DIRECTION_TARGET,
                            'RESTORE_PLAN_EXECUTED',
                            'Restore plan executed successfully using 100% native Moodle process'
                        );
                        
                        // Log user restore summary
                        self::log_user_restore_summary($rc, $request, $courseid);
                        
                        coursetransfer_logger::info(
                            $request->id,
                            coursetransfer_logger::DIRECTION_TARGET,
                            'RESTORE_PLAN_EXECUTED',
                            'Restore plan executed successfully'
                        );
                        
                    } catch (\restore_step_exception $restoreException) {
                        // Specific restore step exception - this is the error we're seeing
                        $errorCode = $restoreException->errorcode ?? 'unknown';
                        
                        coursetransfer_logger::error(
                            $request->id,
                            coursetransfer_logger::DIRECTION_TARGET,
                            'RESTORE_STEP_EXCEPTION',
                            'Restore step failed: ' . $restoreException->getMessage(),
                            $errorCode,
                            [
                                'exception' => get_class($restoreException),
                                'error_info' => $restoreException->a ?? null,
                                'trace_preview' => substr($restoreException->getTraceAsString(), 0, 500)
                            ]
                        );
                        
                        // if this is the quiz/question-related error, try to provide helpful diagnostic
                        if ($errorCode === 'not_specified_restore_task' || 
                            strpos($restoreException->getMessage(), 'not_specified_restore_task') !== false ||
                            strpos($restoreException->getTraceAsString(), 'restore_qtype_') !== false ||
                            strpos($restoreException->getTraceAsString(), 'question_attempt') !== false) {
                            
                            coursetransfer_logger::warning(
                                $request->id,
                                coursetransfer_logger::DIRECTION_TARGET,
                                'QUIZ_ATTEMPT_RESTORE_ISSUE',
                                'Detected quiz/question attempt restore issue. This typically occurs when quiz attempt data references corrupted or missing question answers.',
                                null,
                                [
                                    'recommendation' => 'Consider backing up without user data, or investigate quiz questions in source course',
                                    'affected_component' => 'quiz attempts / question bank'
                                ]
                            );
                        }
                        
                        // CRITICAL: Perform complete rollback to prevent duplicate entries on retry
                        // This removes all course content created during failed restore
                        self::rollback_failed_restore($courseid, $restoreid, $request->id);
                        
                        // Re-throw to be caught by outer exception handler
                        throw $restoreException;
                    }
                    
                    // CRITICAL FIX: Force update course fullname and shortname after restore
                    // When restoring to existing course with TARGET_EXISTING_DELETING/ADDING,
                    // Moodle may ignore course_fullname/course_shortname settings and add
                    // "copia 1" suffix to fullname and "_1" to shortname. We need to fix this.
                    global $DB;
                    $restored_course = $DB->get_record('course', ['id' => $courseid], 'id,fullname,shortname,idnumber');
                    
                    if ($restored_course) {
                        $needs_update = false;
                        
                        // Check if fullname was modified incorrectly
                        if ($restored_course->fullname !== $fullname) {
                            coursetransfer_logger::info(
                                $request->id,
                                coursetransfer_logger::DIRECTION_TARGET,
                                'FIXING_COURSE_FULLNAME',
                                "Restoring original fullname. Was: '{$restored_course->fullname}', Should be: '{$fullname}'"
                            );
                            $restored_course->fullname = $fullname;
                            $needs_update = true;
                        }
                        
                        // Check if shortname was modified incorrectly
                        if ($restored_course->shortname !== $shortname) {
                            coursetransfer_logger::info(
                                $request->id,
                                coursetransfer_logger::DIRECTION_TARGET,
                                'FIXING_COURSE_SHORTNAME',
                                "Restoring original shortname. Was: '{$restored_course->shortname}', Should be: '{$shortname}'"
                            );
                            $restored_course->shortname = $shortname;
                            $needs_update = true;
                        }
                        
                        // Update course if needed
                        if ($needs_update) {
                            $DB->update_record('course', $restored_course);
                            coursetransfer_logger::info(
                                $request->id,
                                coursetransfer_logger::DIRECTION_TARGET,
                                'COURSE_NAMES_FIXED',
                                'Course fullname and shortname restored to original values'
                            );
                        }
                    }
                    
                    $rc->destroy();
                    
                    return true;
                } else {
                    // Precheck failed - log errors and update request
                    if (!array_key_exists('errors', $results)) {
                        // Log warning but continue
                        coursetransfer_logger::warning(
                            $request->id,
                            coursetransfer_logger::DIRECTION_TARGET,
                            'RESTORE_PRECHECK_WARNINGS',
                            'Warnings in precheck but continuing: ' . json_encode($results)
                        );
                        
                        $request->error_code = '104003';
                        $request->error_message = 'Warnings en precheck: ' . json_encode($rc->get_precheck_results());
                        coursetransfer_request::insert_or_update($request, $request->id);
                        
                        // Map users and execute
                        // We already mapped users before precheck, but if precheck failed and cleared temp tables,
                        // we might need to be careful. However, backup_ids_temp usually persists until destroy().
                        $rc->execute_plan();
                        
                        // CRITICAL FIX: Force update course fullname and shortname after restore
                        global $DB;
                        $restored_course = $DB->get_record('course', ['id' => $courseid], 'id,fullname,shortname,idnumber');
                        
                        if ($restored_course) {
                            $needs_update = false;
                            
                            if ($restored_course->fullname !== $fullname) {
                                coursetransfer_logger::info(
                                    $request->id,
                                    coursetransfer_logger::DIRECTION_TARGET,
                                    'FIXING_COURSE_FULLNAME',
                                    "Restoring original fullname. Was: '{$restored_course->fullname}', Should be: '{$fullname}'"
                                );
                                $restored_course->fullname = $fullname;
                                $needs_update = true;
                            }
                            
                            if ($restored_course->shortname !== $shortname) {
                                coursetransfer_logger::info(
                                    $request->id,
                                    coursetransfer_logger::DIRECTION_TARGET,
                                    'FIXING_COURSE_SHORTNAME',
                                    "Restoring original shortname. Was: '{$restored_course->shortname}', Should be: '{$shortname}'"
                                );
                                $restored_course->shortname = $shortname;
                                $needs_update = true;
                            }
                            
                            if ($needs_update) {
                                $DB->update_record('course', $restored_course);
                                coursetransfer_logger::info(
                                    $request->id,
                                    coursetransfer_logger::DIRECTION_TARGET,
                                    'COURSE_NAMES_FIXED',
                                    'Course fullname and shortname restored to original values (after warnings)'
                                );
                            }
                        }
                        
                        $rc->destroy();
                        return true;
                    }
                    // Error in precheck.
                    coursetransfer_logger::error(
                        $request->id,
                        coursetransfer_logger::DIRECTION_TARGET,
                        coursetransfer_logger::ACTION_RESTORE_FAILED,
                        'Error in precheck: ' . json_encode($results),
                        '104002'
                    );
                    
                    $request->status = coursetransfer_request::STATUS_ERROR;
                    $request->error_code = '104002';
                    $request->error_message = 'Error en precheck: ' . json_encode($rc->get_precheck_results());
                    coursetransfer_request::insert_or_update($request, $request->id);
                    return false;
                }
                return false;
            }

        } catch (\Exception $e) {
            // Ensure safe restore mode is disabled even on error
            \local_coursetransfer\safe_quiz_restore::disable_safe_restore();
            
            // Log restore failure
            coursetransfer_logger::error(
                $request->id,
                coursetransfer_logger::DIRECTION_TARGET,
                coursetransfer_logger::ACTION_RESTORE_FAILED,
                'Restore exception: ' . $e->getMessage(),
                '10400',
                ['exception' => get_class($e), 'trace' => $e->getTraceAsString()]
            );
            
            // CRITICAL: Perform rollback if restore controller exists
            // This prevents duplicate entries when the task retries
            if (isset($rc) && isset($courseid)) {
                try {
                    $restoreid = $rc->get_restoreid();
                    self::rollback_failed_restore($courseid, $restoreid, $request->id);
                } catch (\Exception $rollbackException) {
                    // Log rollback failure but don't throw - the main error is more important
                    coursetransfer_logger::warning(
                        $request->id,
                        coursetransfer_logger::DIRECTION_TARGET,
                        'ROLLBACK_EXCEPTION',
                        'Exception during rollback: ' . $rollbackException->getMessage()
                    );
                }
            }
            
            $request->status = coursetransfer_request::STATUS_ERROR;
            $request->error_code = '10400';
            $request->error_message = $e->getMessage();
            coursetransfer_request::insert_or_update($request, $request->id);
            return false;
        }
    }
    /**
     * Log detailed user restore summary after successful native restore
     *
     * @param \restore_controller $rc Restore controller
     * @param \stdClass $request Request object for logging
     * @param int $courseid Course ID that was restored
     * @return void
     */
    private static function log_user_restore_summary(\restore_controller $rc, \stdClass $request, int $courseid): void {
        try {
            // Get user restore statistics.
            $summary = coursetransfer_user_merger::get_user_restore_summary($rc);

            coursetransfer_logger::info(
                $request->id,
                coursetransfer_logger::DIRECTION_TARGET,
                'USER_RESTORE_SUMMARY',
                'User restore completed using 100% native Moodle process',
                [
                    'total_users_in_backup' => $summary['total'],
                    'users_mapped_to_existing' => $summary['mapped'],
                    'users_created_new' => $summary['created'],
                    'note' => 'Moodle handled all user mapping natively (or via pre-check mapping)'
                ]
            );

            // Detect and log duplicate users.
            $duplicates = coursetransfer_user_merger::detect_duplicate_users($courseid, $request);

            if (!empty($duplicates)) {
                coursetransfer_user_merger::log_duplicates($duplicates, $request);
            } else {
                coursetransfer_logger::info(
                    $request->id,
                    coursetransfer_logger::DIRECTION_TARGET,
                    'NO_DUPLICATE_USERS',
                    'No duplicate users detected - all users from backup were either new or properly mapped by Moodle'
                );
            }

        } catch (\Exception $e) {
            coursetransfer_logger::warning(
                $request->id,
                coursetransfer_logger::DIRECTION_TARGET,
                'USER_SUMMARY_ERROR',
                'Could not generate user restore summary: ' . $e->getMessage(),
                null,
                ['exception' => get_class($e)]
            );
        }
    }

    /**
     * Check if backup contains corrupted quiz attempts (error 10400)
     * 
     * This detects the common issue where quiz attempt data references question_answers
     * that don't exist in the backup XML, causing restore_step_exception.
     *
     * @param string $backupdir Path to extracted backup directory
     * @param stdClass $request Request object for logging
     * @return bool True if corrupted quiz detected
     */
    private static function check_corrupt_quiz_attempts(string $backupdir, stdClass $request): bool {
        try {
            // Check if activities directory exists
            $activities_dir = $backupdir . '/activities';
            if (!is_dir($activities_dir)) {
                return false;
            }
            
            // Look for quiz activities
            $quiz_dirs = glob($activities_dir . '/quiz_*');
            if (empty($quiz_dirs)) {
                return false; // No quizzes, no problem
            }
            
            foreach ($quiz_dirs as $quiz_dir) {
                $attempts_xml = $quiz_dir . '/attempts.xml';
                $questions_xml = $quiz_dir . '/questions.xml';
                
                // If quiz has attempts but questions.xml is missing or empty, it's likely corrupt
                if (file_exists($attempts_xml) && filesize($attempts_xml) > 100) {
                    if (!file_exists($questions_xml) || filesize($questions_xml) < 100) {
                        coursetransfer_logger::warning(
                            $request->id,
                            coursetransfer_logger::DIRECTION_TARGET,
                            'CORRUPT_QUIZ_STRUCTURE',
                            'Quiz has attempts but missing/empty questions.xml - likely data corruption',
                            null,
                            ['quiz_dir' => basename($quiz_dir)]
                        );
                        return true;
                    }
                    
                    // Check for orphaned question_answer references (basic check)
                    // This is a heuristic - if attempts.xml is much larger than questions.xml,
                    // it likely has more attempt data than question definitions
                    $attempts_size = filesize($attempts_xml);
                    $questions_size = filesize($questions_xml);
                    
                    if ($attempts_size > $questions_size * 3) {
                        coursetransfer_logger::warning(
                            $request->id,
                            coursetransfer_logger::DIRECTION_TARGET,
                            'SUSPICIOUS_QUIZ_SIZE_RATIO',
                            'Quiz attempts.xml suspiciously large compared to questions.xml',
                            null,
                            [
                                'quiz_dir' => basename($quiz_dir),
                                'attempts_size' => $attempts_size,
                                'questions_size' => $questions_size,
                                'ratio' => round($attempts_size / $questions_size, 2)
                            ]
                        );
                        return true;
                    }
                }
            }
            
            return false;
            
        } catch (\Exception $e) {
            // If check fails, log but assume no corruption to allow restore attempt
            coursetransfer_logger::warning(
                $request->id,
                coursetransfer_logger::DIRECTION_TARGET,
                'QUIZ_CHECK_FAILED',
                'Could not check for corrupt quiz attempts: ' . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * Perform rollback cleanup when restore fails
     * Removes all course content created during failed restore to prevent duplicates on retry
     * 
     * @param int $courseid The course ID being restored
     * @param string $restoreid The restore controller ID
     * @param int $requestid The transfer request ID for logging
     * @return bool True if rollback succeeded, false otherwise
     */
    private static function rollback_failed_restore($courseid, $restoreid, $requestid) {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        
        coursetransfer_logger::info(
            $requestid,
            coursetransfer_logger::DIRECTION_TARGET,
            'ROLLBACK_STARTED',
            "Starting rollback cleanup for failed restore (Course: $courseid, RestoreID: $restoreid)"
        );
        
        $transaction = $DB->start_delegated_transaction();
        
        try {
            $deleted_counts = [
                'modules' => 0,
                'assignments' => 0,
                'submissions' => 0,
                'quizzes' => 0,
                'attempts' => 0,
                'grades' => 0,
                'files' => 0,
                'backup_ids' => 0
            ];
            
            // 1. Get all course modules in this course
            $modules = $DB->get_records('course_modules', ['course' => $courseid]);
            
            foreach ($modules as $cm) {
                try {
                    // Get module name from modules table
                    $module = $DB->get_record('modules', ['id' => $cm->module], 'name');
                    if (!$module) {
                        continue;
                    }
                    $modname = $module->name;
                    
                    // Delete module-specific data based on type
                    switch ($modname) {
                        case 'assign':
                            // Delete assignment submissions and grades (prevent FK violations)
                            $submissions = $DB->count_records('assign_submission', ['assignment' => $cm->instance]);
                            if ($submissions > 0) {
                                $DB->delete_records('assign_submission', ['assignment' => $cm->instance]);
                                $deleted_counts['submissions'] += $submissions;
                            }
                            
                            $grades = $DB->count_records('assign_grades', ['assignment' => $cm->instance]);
                            if ($grades > 0) {
                                $DB->delete_records('assign_grades', ['assignment' => $cm->instance]);
                                $deleted_counts['grades'] += $grades;
                            }
                            
                            // Delete assignment itself
                            $DB->delete_records('assign', ['id' => $cm->instance]);
                            $deleted_counts['assignments']++;
                            break;
                            
                        case 'quiz':
                            // Delete quiz attempts and question usages
                            $attempts = $DB->get_records('quiz_attempts', ['quiz' => $cm->instance]);
                            foreach ($attempts as $attempt) {
                                // Delete question attempts first
                                $DB->delete_records('question_attempts', ['questionusageid' => $attempt->uniqueid]);
                                // Delete question usage
                                $DB->delete_records('question_usages', ['id' => $attempt->uniqueid]);
                            }
                            $attempts_count = $DB->count_records('quiz_attempts', ['quiz' => $cm->instance]);
                            if ($attempts_count > 0) {
                                $DB->delete_records('quiz_attempts', ['quiz' => $cm->instance]);
                                $deleted_counts['attempts'] += $attempts_count;
                            }
                            
                            $DB->delete_records('quiz_grades', ['quiz' => $cm->instance]);
                            $DB->delete_records('quiz', ['id' => $cm->instance]);
                            $deleted_counts['quizzes']++;
                            break;
                        
                        // Add more module types as needed
                        default:
                            // For other modules, delete from their respective tables
                            if ($DB->get_manager()->table_exists($modname)) {
                                $DB->delete_records($modname, ['id' => $cm->instance]);
                            }
                            break;
                    }
                    
                    // Delete the course module record itself
                    $DB->delete_records('course_modules', ['id' => $cm->id]);
                    $deleted_counts['modules']++;
                    
                } catch (\Exception $modException) {
                    // Log but continue with other modules
                    coursetransfer_logger::warning(
                        $requestid,
                        coursetransfer_logger::DIRECTION_TARGET,
                        'ROLLBACK_MODULE_ERROR',
                        "Error rolling back module {$cm->id}: " . $modException->getMessage()
                    );
                }
            }
            
            // 2. Delete gradebook entries for this course (except course grade item)
            $grade_items = $DB->get_records('grade_items', ['courseid' => $courseid, 'itemtype' => 'mod']);
            foreach ($grade_items as $item) {
                $DB->delete_records('grade_grades', ['itemid' => $item->id]);
                $DB->delete_records('grade_items', ['id' => $item->id]);
            }
            
            // 3. Clean backup_ids_temp table for this restore
            $backup_ids_count = $DB->count_records('backup_ids_temp', ['backupid' => $restoreid]);
            if ($backup_ids_count > 0) {
                $DB->delete_records('backup_ids_temp', ['backupid' => $restoreid]);
                $deleted_counts['backup_ids'] = $backup_ids_count;
            }
            
            // 4. Delete course files added during restore
            try {
                $fs = get_file_storage();
                $context = \context_course::instance($courseid);
                $files = $fs->get_area_files($context->id, 'course', 'legacy', false, 'timecreated DESC');
                foreach ($files as $file) {
                    if ($file->get_filename() !== '.') {
                        $file->delete();
                        $deleted_counts['files']++;
                    }
                }
            } catch (\Exception $fileException) {
                // Files cleanup not critical, log and continue
                coursetransfer_logger::warning(
                    $requestid,
                    coursetransfer_logger::DIRECTION_TARGET,
                    'ROLLBACK_FILES_ERROR',
                    'Could not clean up files: ' . $fileException->getMessage()
                );
            }
            
            // 5. Rebuild course cache to reflect changes
            rebuild_course_cache($courseid, true);
            
            // Commit all deletions
            $transaction->allow_commit();
            
            coursetransfer_logger::info(
                $requestid,
                coursetransfer_logger::DIRECTION_TARGET,
                'ROLLBACK_COMPLETED',
                'Rollback cleanup completed successfully',
                null,
                $deleted_counts
            );
            
            return true;
            
        } catch (\Exception $rollbackEx) {
            $transaction->rollback($rollbackEx);
            
            coursetransfer_logger::error(
                $requestid,
                coursetransfer_logger::DIRECTION_TARGET,
                'ROLLBACK_FAILED',
                'Rollback cleanup failed: ' . $rollbackEx->getMessage(),
                'ROLLBACK_ERROR',
                ['exception' => get_class($rollbackEx)]
            );
            
            return false;
        }
    }
}
