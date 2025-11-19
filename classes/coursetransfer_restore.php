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
     *
     * @param stdClass $request
     * @param stored_file $file
     * @return bool
     */
    public static function create_task_restore_course(stdClass $request, stored_file $file): bool {
        $resasynctask = new restore_course_task();
        $resasynctask->set_blocking(false);
        $resasynctask->set_custom_data(
                ['requestid' => $request->id, 'fileid' => $file->get_id()]
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
            $userid = (int)$request->userid;
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
                $course_updated = false;
                
                // Update fullname if different
                if ($target_course->fullname !== $fullname) {
                    coursetransfer_logger::info(
                        $request->id,
                        coursetransfer_logger::DIRECTION_TARGET,
                        'PRE_RESTORE_FULLNAME_UPDATE',
                        "Updating target course fullname BEFORE restore controller. Was: '{$target_course->fullname}', Setting: '{$fullname}'",
                        ['course_id' => $courseid, 'old_fullname' => $target_course->fullname, 'new_fullname' => $fullname]
                    );
                    $target_course->fullname = $fullname;
                    $course_updated = true;
                }
                
                // Update shortname if different
                if ($target_course->shortname !== $shortname) {
                    coursetransfer_logger::info(
                        $request->id,
                        coursetransfer_logger::DIRECTION_TARGET,
                        'PRE_RESTORE_SHORTNAME_UPDATE',
                        "Updating target course shortname BEFORE restore controller. Was: '{$target_course->shortname}', Setting: '{$shortname}'",
                        ['course_id' => $courseid, 'old_shortname' => $target_course->shortname, 'new_shortname' => $shortname]
                    );
                    $target_course->shortname = $shortname;
                    $course_updated = true;
                }
                
                // Apply updates if needed
                if ($course_updated) {
                    $DB->update_record('course', $target_course);
                    rebuild_course_cache($courseid, true);
                    
                    coursetransfer_logger::info(
                        $request->id,
                        coursetransfer_logger::DIRECTION_TARGET,
                        'PRE_RESTORE_COURSE_UPDATED',
                        'Target course names updated successfully BEFORE restore. This prevents Moodle from adding duplicate suffixes.',
                        ['course_id' => $courseid, 'fullname' => $fullname, 'shortname' => $shortname]
                    );
                }
            }

            $restoreoptions = [
                    'overwrite_conf' => true,
                    'keep_roles_and_enrolments' => $keeprolesenrolments,
                    'keep_groups_and_groupings' => $keepgroupsgroupings,
                    'course_fullname' => $fullname,
                    'course_shortname' => $shortname,
            ];

            // CRITICAL FIX: Read course format from backup and apply it to destination course
            // BEFORE creating restore controller. This ensures format-specific configurations
            // (like colors, custom settings) are properly restored from the backup.
            try {
                $moodlefile = $backuptempdir . '/' . $filepath . '/moodle_backup.xml';
                if (file_exists($moodlefile)) {
                    $xml = simplexml_load_file($moodlefile);
                    if ($xml && isset($xml->information->original_course_format)) {
                        $backup_format = (string)$xml->information->original_course_format;
                        
                        // Get current course format
                        $current_course = $DB->get_record('course', ['id' => $courseid], 'id,format');
                        
                        if ($current_course && $current_course->format !== $backup_format) {
                            coursetransfer_logger::info(
                                $request->id,
                                coursetransfer_logger::DIRECTION_TARGET,
                                'CHANGING_COURSE_FORMAT',
                                "Changing destination course format to match backup. Current: '{$current_course->format}', Backup format: '{$backup_format}'",
                                ['course_id' => $courseid, 'old_format' => $current_course->format, 'new_format' => $backup_format]
                            );
                            
                            // Change course format to match the backup
                            $current_course->format = $backup_format;
                            $DB->update_record('course', $current_course);
                            
                            // Clear course cache to ensure format change is recognized
                            rebuild_course_cache($courseid, true);
                            
                            coursetransfer_logger::info(
                                $request->id,
                                coursetransfer_logger::DIRECTION_TARGET,
                                'COURSE_FORMAT_CHANGED',
                                "Course format changed successfully. Now restore will use correct format plugin.",
                                ['course_id' => $courseid, 'format' => $backup_format]
                            );
                        } else if ($current_course) {
                            coursetransfer_logger::info(
                                $request->id,
                                coursetransfer_logger::DIRECTION_TARGET,
                                'COURSE_FORMAT_MATCH',
                                "Course format already matches backup format: '{$backup_format}'",
                                ['course_id' => $courseid, 'format' => $backup_format]
                            );
                        }
                    }
                }
            } catch (\Exception $e) {
                coursetransfer_logger::info(
                    $request->id,
                    coursetransfer_logger::DIRECTION_TARGET,
                    'FORMAT_CHECK_ERROR',
                    "Could not read course format from backup: " . $e->getMessage()
                );
            }

            // DON'T convert TARGET_NEW_COURSE to TARGET_EXISTING_DELETING
            // Let Moodle use the target as configured in the request
            // The fullname/shortname from restoreoptions will override any duplicates

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
                
                // SOLUTION: Use Moodle's native mechanism for duplicate admin users
                // Combined with manual mapping for non-admin users.
                // This is the same approach Moodle uses in native course import/restore.
                
                // Save original setting to restore later
                $original_duplicate_admin_setting = get_config('backup', 'import_general_duplicate_admin_allowed');
                
                // Temporarily enable Moodle's duplicate admin resolution
                // This allows Moodle to automatically rename conflicting 'admin' users
                // by appending the site identifier: admin -> admin_abc123def
                set_config('import_general_duplicate_admin_allowed', true, 'backup');
                
                coursetransfer_logger::info(
                    $request->id,
                    coursetransfer_logger::DIRECTION_TARGET,
                    'DUPLICATE_ADMIN_ENABLED',
                    'Enabled Moodle native duplicate admin resolution (will rename admin to admin_siteid if conflict)',
                    ['original_setting' => $original_duplicate_admin_setting]
                );

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
                    // At this point, Moodle has already processed admin users:
                    // - If admin conflicts, it was renamed to admin_siteid (because we enabled the setting)
                    // - Other users are marked for creation (newitemid = 0)
                    
                    // Now map NON-ADMIN users to existing destination users
                    // This prevents duplicate creation for professors, students, etc.
                    coursetransfer_logger::info(
                        $request->id,
                        coursetransfer_logger::DIRECTION_TARGET,
                        'POPULATING_USER_MAPPINGS',
                        'Mapping non-admin users from backup to existing destination users...'
                    );
                    
                    self::populate_user_mappings_in_temp_table($rc, $request);
                    
                    // Execute restore with mapped users
                    coursetransfer_logger::info(
                        $request->id,
                        coursetransfer_logger::DIRECTION_TARGET,
                        'EXECUTING_RESTORE',
                        'Executing restore plan with user mappings in place...'
                    );
                    
                    $rc->execute_plan();
                    
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
                    
                    // Restore original Moodle setting
                    set_config('import_general_duplicate_admin_allowed', $original_duplicate_admin_setting, 'backup');
                    
                    coursetransfer_logger::info(
                        $request->id,
                        coursetransfer_logger::DIRECTION_TARGET,
                        'DUPLICATE_ADMIN_RESTORED',
                        'Restored original duplicate admin setting',
                        ['restored_to' => $original_duplicate_admin_setting]
                    );
                    
                    return true;
                } else {
                    // Restore original setting even if precheck fails
                    set_config('import_general_duplicate_admin_allowed', $original_duplicate_admin_setting, 'backup');
                    
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
                        self::populate_user_mappings_in_temp_table($rc, $request);
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
            } else {
                // Log invalid backup file error
                coursetransfer_logger::error(
                    $request->id,
                    coursetransfer_logger::DIRECTION_TARGET,
                    coursetransfer_logger::ACTION_RESTORE_FAILED,
                    'MBZ file is invalid. Plan is NULL: ' . $file->get_filepath(),
                    '104001'
                );
                
                $request->status = coursetransfer_request::STATUS_ERROR;
                $request->error_code = '104001';
                $request->error_message = 'MBZ file is invalid. Plan is NULL: ' . $file->get_filepath();
                coursetransfer_request::insert_or_update($request, $request->id);
                return false;
            }

        } catch (\Exception $e) {
            // Log restore failure
            coursetransfer_logger::error(
                $request->id,
                coursetransfer_logger::DIRECTION_TARGET,
                coursetransfer_logger::ACTION_RESTORE_FAILED,
                'Restore exception: ' . $e->getMessage(),
                '10400',
                ['exception' => get_class($e), 'trace' => $e->getTraceAsString()]
            );
            
            $request->status = coursetransfer_request::STATUS_ERROR;
            $request->error_code = '10400';
            $request->error_message = $e->getMessage();
            coursetransfer_request::insert_or_update($request, $request->id);
            return false;
        }
    }

    /**
     * Check if precheck errors contain user conflict messages.
     *
     * @param array $errors Array of error messages
     * @return bool True if user conflicts detected
     */
    private static function has_user_conflict_errors(array $errors): bool {
        foreach ($errors as $error) {
            if (is_string($error)) {
                // Check for user conflict patterns in Spanish and English.
                if (stripos($error, 'restaurar al usuario') !== false ||
                    stripos($error, 'restore') !== false && stripos($error, 'user') !== false && 
                    stripos($error, 'conflict') !== false) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Setup user mappings BEFORE restore to prevent duplicate user errors.
     * This reads users from the backup XML and creates mappings to existing users.
     *
     * @param restore_controller $rc Restore controller
     * @param stdClass $request Request object for logging
     * @return bool True if mappings were created
     */
    private static function setup_user_mappings_before_restore(restore_controller $rc, stdClass $request): bool {
        global $DB;

        try {
            $restoreid = $rc->get_restoreid();
            $tempdir = $rc->get_tempdir();
            
            // Get field to match users.
            $matchfield = get_config('local_coursetransfer', 'origin_field_search_user');
            if (empty($matchfield) || !in_array($matchfield, ['username', 'email', 'idnumber'])) {
                $matchfield = 'username';
            }

            coursetransfer_logger::info(
                $request->id,
                coursetransfer_logger::DIRECTION_TARGET,
                'READING_BACKUP_USERS',
                "Reading users from backup file. Match field: {$matchfield}, Temp dir: {$tempdir}"
            );

            // Debug: List files in temp directory to understand backup structure
            if (is_dir($tempdir)) {
                $files = scandir($tempdir);
                coursetransfer_logger::info(
                    $request->id,
                    coursetransfer_logger::DIRECTION_TARGET,
                    'BACKUP_STRUCTURE_DEBUG',
                    'Files in temp directory: ' . implode(', ', array_slice($files, 0, 20))
                );
            }

            // Path to users.xml - try multiple possible locations
            // In Moodle backups, users.xml is typically in the root of the extracted backup
            $possiblepaths = [
                $tempdir . '/users.xml',
                dirname($tempdir) . '/users.xml',
            ];
            
            // Also try to find it recursively
            if (is_dir($tempdir)) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($tempdir, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST
                );
                foreach ($iterator as $file) {
                    if ($file->isFile() && $file->getFilename() === 'users.xml') {
                        $possiblepaths[] = $file->getPathname();
                        break; // Found it
                    }
                }
            }
            
            $usersfile = null;
            foreach ($possiblepaths as $path) {
                if (file_exists($path)) {
                    $usersfile = $path;
                    coursetransfer_logger::info(
                        $request->id,
                        coursetransfer_logger::DIRECTION_TARGET,
                        'USERS_FILE_FOUND',
                        "Found users.xml at: {$path}"
                    );
                    break;
                }
            }
            
            if (!$usersfile) {
                coursetransfer_logger::warning(
                    $request->id,
                    coursetransfer_logger::DIRECTION_TARGET,
                    'NO_USERS_IN_BACKUP',
                    'No users.xml found in backup at any expected location. Tried: ' . implode(', ', $possiblepaths)
                );
                return false;
            }

            // Parse users XML.
            $xml = simplexml_load_file($usersfile);
            if (!$xml) {
                coursetransfer_logger::error(
                    $request->id,
                    coursetransfer_logger::DIRECTION_TARGET,
                    'XML_PARSE_ERROR',
                    'Failed to parse users.xml from backup'
                );
                return false;
            }

            $mappedcount = 0;
            $newcount = 0;
            $removedcount = 0;

            // We'll modify the XML to remove users that already exist.
            // This prevents Moodle from trying to create them.
            $modified = false;

            // Process each user in the backup.
            $userstoremove = [];
            foreach ($xml->user as $usernode) {
                $backupuserid = (int)$usernode->id;
                $username = (string)$usernode->username;
                $email = (string)$usernode->email;
                
                // Get the match value based on configured field.
                $matchvalue = '';
                if ($matchfield === 'username') {
                    $matchvalue = $username;
                } else if ($matchfield === 'email') {
                    $matchvalue = $email;
                } else if ($matchfield === 'idnumber') {
                    $matchvalue = (string)$usernode->idnumber;
                }

                if (empty($matchvalue)) {
                    continue;
                }

                // Check if user exists in destination.
                $existinguser = $DB->get_record('user', [
                    $matchfield => $matchvalue,
                    'deleted' => 0
                ], 'id, username, firstname, lastname');

                if ($existinguser) {
                    // CRITICAL: Remove this user from the backup XML
                    // so Moodle doesn't try to create it.
                    $userstoremove[] = $usernode;
                    $mappedcount++;
                    
                    coursetransfer_logger::info(
                        $request->id,
                        coursetransfer_logger::DIRECTION_TARGET,
                        'USER_REMOVED_FROM_BACKUP',
                        "Removing user '{$matchvalue}' from backup XML (already exists with ID: {$existinguser->id})"
                    );
                } else {
                    $newcount++;
                    
                    coursetransfer_logger::info(
                        $request->id,
                        coursetransfer_logger::DIRECTION_TARGET,
                        'USER_WILL_BE_CREATED',
                        "User '{$matchvalue}' not found - will be created from backup"
                    );
                }
            }

            // Remove existing users from XML.
            foreach ($userstoremove as $node) {
                $dom = dom_import_simplexml($node);
                $dom->parentNode->removeChild($dom);
                $removedcount++;
                $modified = true;
            }

            // Save modified XML back to file.
            if ($modified) {
                $result = $xml->asXML($usersfile);
                if ($result) {
                    coursetransfer_logger::info(
                        $request->id,
                        coursetransfer_logger::DIRECTION_TARGET,
                        'BACKUP_XML_MODIFIED',
                        "Modified users.xml - removed {$removedcount} existing users to prevent duplicates"
                    );
                } else {
                    coursetransfer_logger::error(
                        $request->id,
                        coursetransfer_logger::DIRECTION_TARGET,
                        'XML_SAVE_ERROR',
                        'Failed to save modified users.xml'
                    );
                    return false;
                }
            }

            coursetransfer_logger::info(
                $request->id,
                coursetransfer_logger::DIRECTION_TARGET,
                'USER_MAPPING_SUMMARY',
                "User processing complete: {$removedcount} existing users removed from backup, {$newcount} new users will be created",
                [
                    'removed' => $removedcount,
                    'new' => $newcount,
                    'match_field' => $matchfield
                ]
            );

            return true;

        } catch (\Exception $e) {
            coursetransfer_logger::error(
                $request->id,
                coursetransfer_logger::DIRECTION_TARGET,
                'USER_MAPPING_SETUP_ERROR',
                'Error setting up user mappings: ' . $e->getMessage() . "\nTrace: " . $e->getTraceAsString()
            );
            return false;
        }
    }

    /**
     * Populate backup_ids_temp table with user mappings AFTER precheck.
     * This is the CRITICAL method that prevents duplicate user creation.
     *
     * @param restore_controller $rc Restore controller
     * @param stdClass $request Request object for logging
     * @return bool True if mappings were created
     */
    private static function populate_user_mappings_in_temp_table(restore_controller $rc, stdClass $request): bool {
        global $DB;

        try {
            $restoreid = $rc->get_restoreid();
            
            // Get field to match users.
            $matchfield = get_config('local_coursetransfer', 'origin_field_search_user');
            if (empty($matchfield) || !in_array($matchfield, ['username', 'email', 'idnumber'])) {
                $matchfield = 'username';
            }

            coursetransfer_logger::info(
                $request->id,
                coursetransfer_logger::DIRECTION_TARGET,
                'MAPPING_USERS_IN_TEMP_TABLE',
                "Populating backup_ids_temp with user mappings. RestoreID: {$restoreid}, Match field: {$matchfield}"
            );

            // Verify backup_ids_temp exists (it should after precheck)
            $dbman = $DB->get_manager();
            $temptable = new xmldb_table('backup_ids_temp');
            
            if (!$dbman->table_exists($temptable)) {
                coursetransfer_logger::error(
                    $request->id,
                    coursetransfer_logger::DIRECTION_TARGET,
                    'TEMP_TABLE_MISSING',
                    'backup_ids_temp table does not exist - precheck may have failed'
                );
                return false;
            }

            // Get all user records from backup_ids_temp that precheck created
            $backupusers = $DB->get_records('backup_ids_temp', [
                'backupid' => $restoreid,
                'itemname' => 'user'
            ]);

            if (empty($backupusers)) {
                coursetransfer_logger::info(
                    $request->id,
                    coursetransfer_logger::DIRECTION_TARGET,
                    'NO_USERS_IN_TEMP_TABLE',
                    'No users found in backup_ids_temp - backup may not include user data'
                );
                return false;
            }

            $mappedcount = 0;
            $skippedcount = 0;
            $alreadymappedcount = 0;
            $tobecreatedcount = 0;

            foreach ($backupusers as $backupuser) {
                // Decode user info using Moodle's method
                // Moodle stores user info as: base64(gzcompress(serialize($data)))
                $userinfo = backup_controller_dbops::decode_backup_temp_info($backupuser->info);
                
                // DEBUG: Log what we got
                if (empty($userinfo)) {
                    coursetransfer_logger::warning(
                        $request->id,
                        coursetransfer_logger::DIRECTION_TARGET,
                        'USER_INFO_EMPTY',
                        "Skipping user (backup ID: {$backupuser->itemid}) - could not decode user info"
                    );
                    $skippedcount++;
                    continue;
                }
                
                // Get username for logging
                $username = '';
                if (is_object($userinfo)) {
                    $username = $userinfo->username ?? 'unknown';
                } else if (is_array($userinfo)) {
                    $username = $userinfo['username'] ?? 'unknown';
                }
                
                // DEBUG: Log user info structure for first few users
                if ($mappedcount + $skippedcount + $alreadymappedcount < 3) {
                    coursetransfer_logger::info(
                        $request->id,
                        coursetransfer_logger::DIRECTION_TARGET,
                        'DEBUG_USER_INFO',
                        "User info structure for '{$username}'",
                        [
                            'itemid' => $backupuser->itemid,
                            'newitemid' => $backupuser->newitemid,
                            'info_type' => gettype($userinfo),
                            'is_object' => is_object($userinfo),
                            'is_array' => is_array($userinfo),
                            'has_username' => isset($userinfo->username) || (is_array($userinfo) && isset($userinfo['username']))
                        ]
                    );
                }
                
                // Check if already mapped by Moodle (e.g., admin renamed to admin_siteid)
                if (!empty($backupuser->newitemid)) {
                    $alreadymappedcount++;
                    coursetransfer_logger::info(
                        $request->id,
                        coursetransfer_logger::DIRECTION_TARGET,
                        'USER_ALREADY_MAPPED',
                        "User '{$username}' already mapped by Moodle (backup ID: {$backupuser->itemid} → destination ID: {$backupuser->newitemid})"
                    );
                    continue;
                }

                // Extract match value
                $matchvalue = null;
                if (is_object($userinfo)) {
                    $matchvalue = $userinfo->$matchfield ?? null;
                } else if (is_array($userinfo)) {
                    $matchvalue = $userinfo[$matchfield] ?? null;
                }

                if (empty($matchvalue)) {
                    coursetransfer_logger::warning(
                        $request->id,
                        coursetransfer_logger::DIRECTION_TARGET,
                        'USER_MATCHVALUE_EMPTY',
                        "Skipping user '{$username}' (backup ID: {$backupuser->itemid}) - matchvalue empty for field: {$matchfield}"
                    );
                    $skippedcount++;
                    continue;
                }

                // Search for existing user in destination
                $existinguser = $DB->get_record('user', [
                    $matchfield => $matchvalue,
                    'deleted' => 0
                ], 'id, username, firstname, lastname');

                if ($existinguser) {
                    // CRITICAL: Update the mapping to point to existing user
                    // This prevents Moodle from trying to create the user
                    $backupuser->newitemid = $existinguser->id;
                    $DB->update_record('backup_ids_temp', $backupuser);
                    $mappedcount++;
                    
                    coursetransfer_logger::info(
                        $request->id,
                        coursetransfer_logger::DIRECTION_TARGET,
                        'USER_MAPPED_TO_EXISTING',
                        "Mapped user '{$username}' (match: {$matchfield}={$matchvalue}) to existing user '{$existinguser->username}' (backup ID: {$backupuser->itemid} → destination ID: {$existinguser->id})"
                    );
                } else {
                    // User doesn't exist in destination, will be created
                    $tobecreatedcount++;
                    coursetransfer_logger::info(
                        $request->id,
                        coursetransfer_logger::DIRECTION_TARGET,
                        'USER_WILL_BE_CREATED',
                        "User '{$username}' (match: {$matchfield}={$matchvalue}) not found in destination, will be created (backup ID: {$backupuser->itemid})"
                    );
                }
            }

            coursetransfer_logger::info(
                $request->id,
                coursetransfer_logger::DIRECTION_TARGET,
                'USER_MAPPING_COMPLETE',
                "User mapping complete: {$alreadymappedcount} pre-mapped by Moodle, {$mappedcount} mapped to existing, {$tobecreatedcount} will be created, {$skippedcount} skipped",
                [
                    'already_mapped_by_moodle' => $alreadymappedcount,
                    'mapped_to_existing' => $mappedcount,
                    'to_be_created' => $tobecreatedcount,
                    'skipped' => $skippedcount,
                    'total' => count($backupusers)
                ]
            );

            return $mappedcount > 0 || $alreadymappedcount > 0;

        } catch (\Exception $e) {
            coursetransfer_logger::error(
                $request->id,
                coursetransfer_logger::DIRECTION_TARGET,
                'USER_MAPPING_ERROR',
                'Error populating user mappings: ' . $e->getMessage() . "\nTrace: " . $e->getTraceAsString()
            );
            return false;
        }
    }

    /**
     * Map backup users to existing destination users by username.
     * This prevents user conflicts during restore and ensures all user data
     * (grades, forum posts, assignments, etc.) is correctly associated.
     *
     * @param restore_controller $rc Restore controller
     * @param stdClass $request Request object for logging
     * @return bool True if mapping was successful
     */
    private static function map_backup_users_to_existing(restore_controller $rc, stdClass $request): bool {
        global $DB;

        try {
            // Get restore ID (used as backupid in temp tables).
            $restoreid = $rc->get_restoreid();
            
            // Get field to match users (from plugin settings, default: username).
            $matchfield = get_config('local_coursetransfer', 'origin_field_search_user');
            if (empty($matchfield) || !in_array($matchfield, ['username', 'email', 'idnumber'])) {
                $matchfield = 'username';
            }

            coursetransfer_logger::info(
                $request->id,
                coursetransfer_logger::DIRECTION_TARGET,
                'USER_MAPPING_START',
                "Starting user mapping. RestoreID: {$restoreid}, Match field: {$matchfield}"
            );

            // NOTE: The backup_ids_temp table is created and managed by Moodle during restore.
            // If precheck failed and destroyed the controller, the table might be gone.
            // We need to check if it exists first.
            $dbman = $DB->get_manager();
            $temptable = new xmldb_table('backup_ids_temp');
            
            if (!$dbman->table_exists($temptable)) {
                coursetransfer_logger::warning(
                    $request->id,
                    coursetransfer_logger::DIRECTION_TARGET,
                    'USER_MAPPING_SKIPPED',
                    'Temporary table backup_ids_temp does not exist. The table is created by Moodle during restore process. Skipping user mapping - this may cause precheck to fail again.',
                    null,
                    ['restore_id' => $restoreid, 'info' => 'Table will be created on next precheck attempt']
                );
                return false;
            }

            // Get all users from backup temporary table.
            $backupusers = $DB->get_records('backup_ids_temp', [
                'backupid' => $restoreid,
                'itemname' => 'user'
            ]);

            if (empty($backupusers)) {
                coursetransfer_logger::warning(
                    $request->id,
                    coursetransfer_logger::DIRECTION_TARGET,
                    'USER_MAPPING_NO_USERS',
                    'No users found in backup_ids_temp table to map. This may indicate backup has no user data.'
                );
                return false;
            }

            $mappedcount = 0;
            $skippedcount = 0;
            $detailedlog = [];

            foreach ($backupusers as $backupuser) {
                // Skip if already mapped.
                if (!empty($backupuser->newitemid)) {
                    $detailedlog[] = "User ID {$backupuser->itemid} already mapped to {$backupuser->newitemid}";
                    continue;
                }

                // Decode user info from backup.
                $userinfo = unserialize(base64_decode($backupuser->info));
                if (empty($userinfo)) {
                    $skippedcount++;
                    $detailedlog[] = "Failed to decode user info for backup item ID {$backupuser->id}";
                    continue;
                }

                // Extract match field value.
                $matchvalue = null;
                if (is_object($userinfo)) {
                    $matchvalue = $userinfo->$matchfield ?? null;
                } else if (is_array($userinfo)) {
                    $matchvalue = $userinfo[$matchfield] ?? null;
                }

                if (empty($matchvalue)) {
                    $skippedcount++;
                    $detailedlog[] = "No {$matchfield} found for backup user ID {$backupuser->itemid}";
                    continue;
                }

                // Search for existing user in destination by match field.
                $existinguser = $DB->get_record('user', [
                    $matchfield => $matchvalue,
                    'deleted' => 0
                ], 'id, username, email, firstname, lastname');

                if ($existinguser) {
                    // CRITICAL: Map backup user to existing destination user.
                    // This ensures all user-related data (grades, submissions, etc.) 
                    // will be associated with the correct existing user.
                    $backupuser->newitemid = $existinguser->id;
                    $DB->update_record('backup_ids_temp', $backupuser);
                    $mappedcount++;
                    
                    $detailedlog[] = sprintf(
                        "✓ MAPPED: Backup user '%s' (origin ID: %d) → Destination user '%s %s' (ID: %d)",
                        $matchvalue,
                        $backupuser->itemid,
                        $existinguser->firstname,
                        $existinguser->lastname,
                        $existinguser->id
                    );
                    
                    coursetransfer_logger::info(
                        $request->id,
                        coursetransfer_logger::DIRECTION_TARGET,
                        'USER_MAPPED',
                        "Mapped backup user '{$matchvalue}' (origin ID: {$backupuser->itemid}) to destination user ID {$existinguser->id}",
                        [
                            'origin_id' => $backupuser->itemid,
                            'destination_id' => $existinguser->id,
                            'match_field' => $matchfield,
                            'match_value' => $matchvalue
                        ]
                    );
                } else {
                    // User doesn't exist in destination.
                    // Let Moodle handle it (will try to create or skip based on restore settings).
                    $skippedcount++;
                    
                    $detailedlog[] = "⚠ NOT FOUND: User '{$matchvalue}' from backup not in destination";
                    
                    coursetransfer_logger::warning(
                        $request->id,
                        coursetransfer_logger::DIRECTION_TARGET,
                        'USER_NOT_FOUND',
                        "User '{$matchvalue}' from backup not found in destination (will be created if backup includes user data)",
                        null,
                        [
                            'origin_id' => $backupuser->itemid,
                            'match_field' => $matchfield,
                            'match_value' => $matchvalue
                        ]
                    );
                }
            }

            // Log comprehensive summary.
            $summary = implode("\n", $detailedlog);
            coursetransfer_logger::info(
                $request->id,
                coursetransfer_logger::DIRECTION_TARGET,
                'USER_MAPPING_COMPLETE',
                "User mapping complete: {$mappedcount} mapped, {$skippedcount} skipped/to be created from " . 
                count($backupusers) . " total users. Match field: {$matchfield}\n\nDetailed mapping:\n{$summary}",
                [
                    'total_users' => count($backupusers),
                    'mapped' => $mappedcount,
                    'skipped' => $skippedcount,
                    'match_field' => $matchfield,
                    'restore_id' => $restoreid
                ]
            );
            
            return $mappedcount > 0;

        } catch (\Exception $e) {
            // Log error but don't fail the restore - let it continue with default behavior.
            coursetransfer_logger::error(
                $request->id,
                coursetransfer_logger::DIRECTION_TARGET,
                'USER_MAPPING_ERROR',
                'Critical error mapping users: ' . $e->getMessage() . "\nTrace: " . $e->getTraceAsString(),
                null,
                null,
                null,
                ['exception_class' => get_class($e)]
            );
            return false;
        }
    }

}
