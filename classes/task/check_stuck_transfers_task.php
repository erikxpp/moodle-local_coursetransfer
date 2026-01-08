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
 * Check stuck transfers task
 *
 * @package    local_coursetransfer
 * @copyright  2025 Proyecto UNIMOODLE
 * @author     UNIMOODLE Group (Coordinator) <direccion.area.estrategia.digital@uva.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfer\task;

use core\task\scheduled_task;
use local_coursetransfer\coursetransfer_logger;
use local_coursetransfer\coursetransfer_request;

/**
 * Check stuck transfers task
 *
 * This task checks for transfers that are stuck in progress without active adhoc tasks
 * and marks them as ERROR after a timeout period.
 *
 * @package    local_coursetransfer
 * @copyright  2025 Proyecto UNIMOODLE
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_stuck_transfers_task extends scheduled_task {

    /**
     * Timeout in seconds (2 hours by default)
     * After this time without updates, a transfer is considered stuck
     * Reduced from 4h to detect stuck transfers faster
     */
    const STUCK_TIMEOUT = 7200;

    /**
     * Maximum time a task can run before being considered stuck (4 hours)
     * This catches tasks that are actually running but hung/frozen
     * Increased to 4 hours to allow large category migrations with multiple courses
     */
    const TASK_RUNNING_TIMEOUT = 14400; // 4 hours

    /**
     * Get task name
     *
     * @return string
     */
    public function get_name() {
        return get_string('check_stuck_transfers_task', 'local_coursetransfer');
    }

    /**
     * Execute task
     */
    public function execute() {
        global $DB;

        mtrace('Starting stuck transfers check...');

        // STEP 1: Clean up stuck adhoc tasks first (tasks running for too long)
        $this->cleanup_stuck_adhoc_tasks();

        // STEP 2: Find transfers that are stuck without active tasks
        $timeoutthreshold = time() - self::STUCK_TIMEOUT;

        // Find requests that are:
        // 1. In progress (status between NOT_STARTED and COMPLETED, excluding ERROR)
        // 2. Haven't been modified in STUCK_TIMEOUT seconds
        // 3. Are individual courses OR categories
        // 
        // NOTE: We deliberately EXCLUDE STATUS_DOWNLOADED (70) and STATUS_RESTORE (80) because:
        // - STATUS_DOWNLOADED: Courses are waiting in queue for process_restore_queue_task
        // - STATUS_RESTORE: A restore is currently being processed
        // These states are handled by the sequential restore queue and should not be marked as stuck.
        $sql = "SELECT r.*
                FROM {local_coursetransfer_request} r
                WHERE (r.type = :type_course OR r.type = :type_category)
                  AND r.status > :notstartedstatus
                  AND r.status != :errorstatus
                  AND r.status != :completedstatus
                  AND r.status != :downloadedstatus
                  AND r.status != :restorestatus
                  AND r.timemodified < :timeout";

        $params = [
            'type_course' => coursetransfer_request::TYPE_COURSE,
            'type_category' => coursetransfer_request::TYPE_CATEGORY,
            'notstartedstatus' => coursetransfer_request::STATUS_NOT_STARTED,
            'errorstatus' => coursetransfer_request::STATUS_ERROR,
            'completedstatus' => coursetransfer_request::STATUS_COMPLETED,
            'downloadedstatus' => coursetransfer_request::STATUS_DOWNLOADED,
            'restorestatus' => coursetransfer_request::STATUS_RESTORE,
            'timeout' => $timeoutthreshold,
        ];

        $stuckrequests = $DB->get_records_sql($sql, $params);

        if (empty($stuckrequests)) {
            mtrace('No stuck transfers found.');
            return;
        }

        mtrace('Found ' . count($stuckrequests) . ' potentially stuck transfers. Checking adhoc tasks...');

        $markedasstuck = 0;

        foreach ($stuckrequests as $request) {
            // Check if there are active adhoc tasks for this request
            $hasactivetask = $this->has_active_adhoc_task($request);

            if (!$hasactivetask) {
                // Mark as ERROR
                $this->mark_request_as_stuck_no_tasks($request);
                $markedasstuck++;
                mtrace("  - Request ID {$request->id}: Marked as ERROR (no active tasks, stuck for " . 
                       round((time() - $request->timemodified) / 3600, 1) . " hours)");
            } else {
                mtrace("  - Request ID {$request->id}: Has active tasks, skipping.");
            }
        }

        mtrace("Stuck transfers check completed. Marked {$markedasstuck} transfers as ERROR.");
    }

    /**
     * Clean up adhoc tasks that have been running for too long
     * 
     * This proactively detects and removes tasks that are stuck in "running" state
     * for longer than TASK_RUNNING_TIMEOUT seconds.
     */
    protected function cleanup_stuck_adhoc_tasks() {
        global $DB;

        mtrace('Checking for stuck adhoc tasks...');

        $timeoutthreshold = time() - self::TASK_RUNNING_TIMEOUT;

        $classnames = [
            // Tareas de transferencia (pueden ejecutarse por horas en cursos grandes)
            '\\local_coursetransfer\\task\\create_backup_course_task',      // Origen: Crear backup
            '\\local_coursetransfer\\task\\download_file_course_task',      // Destino: Descargar backup
            '\\local_coursetransfer\\task\\restore_course_task',            // Destino: Restaurar curso
            
            // Tareas de limpieza (pueden atascarse si hay muchos archivos)
            '\\local_coursetransfer\\task\\remove_course_task',             // Eliminar curso
            '\\local_coursetransfer\\task\\remove_category_task',           // Eliminar categoría
            '\\local_coursetransfer\\task\\cleanup_course_bin_task',        // Limpiar papelera curso
            '\\local_coursetransfer\\task\\cleanup_category_bin_task',      // Limpiar papelera categoría
        ];

        $totalcleaned = 0;

        foreach ($classnames as $classname) {
            // Find tasks that have been running for too long
            // timestarted > 0 means the task is currently running
            // timestarted < threshold means it's been running for too long
            $sql = "SELECT *
                    FROM {task_adhoc}
                    WHERE classname = :classname
                      AND timestarted > 0
                      AND timestarted < :threshold";

            $stucktasks = $DB->get_records_sql($sql, [
                'classname' => $classname,
                'threshold' => $timeoutthreshold,
            ]);

            if (!empty($stucktasks)) {
                mtrace('  Found ' . count($stucktasks) . ' stuck tasks for ' . 
                       basename(str_replace('\\', '/', $classname)));

                foreach ($stucktasks as $task) {
                    $runningtime = time() - $task->timestarted;
                    $customdata = @json_decode($task->customdata);

                    try {
                        // Get request ID for logging
                        $requestid = null;
                        if ($customdata) {
                            if (isset($customdata->requestoriginid)) {
                                $requestid = $customdata->requestoriginid;
                            } else if (isset($customdata->requestid)) {
                                $requestid = $customdata->requestid;
                            }
                        }

                        // Delete the stuck task
                        $DB->delete_records('task_adhoc', ['id' => $task->id]);
                        $totalcleaned++;

                        mtrace("    → Deleted stuck adhoc task (ID: {$task->id}, " .
                               "Request: " . ($requestid ?? 'unknown') . ", " .
                               "Running for: " . round($runningtime / 3600, 1) . " hours)");

                        // Log the cleanup
                        if ($requestid) {
                            // Try to get the request to determine direction
                            $request = $DB->get_record('local_coursetransfer_request', ['id' => $requestid]);
                            if ($request) {
                                coursetransfer_logger::warning(
                                    $requestid,
                                    $request->direction == coursetransfer_request::DIRECTION_REQUEST ?
                                        coursetransfer_logger::DIRECTION_ORIGIN :
                                        coursetransfer_logger::DIRECTION_TARGET,
                                    'STUCK_ADHOC_TASK_CLEANED',
                                    'Stuck adhoc task removed after running for ' .
                                        round($runningtime / 3600, 1) . ' hours',
                                    '13001',
                                    [
                                        'task_id' => $task->id,
                                        'classname' => $classname,
                                        'hours_running' => round($runningtime / 3600, 1),
                                        'pid' => $task->pid ?? null,
                                    ]
                                );

                                // Mark the request as ERROR
                                if ($request->status != coursetransfer_request::STATUS_ERROR &&
                                    $request->status != coursetransfer_request::STATUS_COMPLETED) {
                                    $this->mark_request_as_stuck($request, $runningtime);
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        mtrace("    → ERROR deleting adhoc task {$task->id}: " . $e->getMessage());
                    }
                }
            }
        }

        if ($totalcleaned > 0) {
            mtrace("Total stuck adhoc tasks cleaned: {$totalcleaned}");
        } else {
            mtrace('No stuck adhoc tasks found.');
        }
    }

    /**
     * Check if request has active adhoc tasks
     *
     * @param \stdClass $request
     * @return bool
     */
    protected function has_active_adhoc_task($request): bool {
        global $DB;

        // Check for backup tasks (on origin)
        if ($request->direction == coursetransfer_request::DIRECTION_REQUEST) {
            // Check for backup controller in progress
            $sql = "SELECT COUNT(*)
                    FROM {backup_controllers} bc
                    WHERE bc.itemid = :courseid
                      AND bc.purpose = :purpose
                      AND bc.status < :completedstatus";
            
            $backupcount = $DB->count_records_sql($sql, [
                'courseid' => $request->origin_course_id,
                'purpose' => 10, // backup::BACKUP_PURPOSE_COURSE
                'completedstatus' => 1000, // backup::STATUS_FINISHED_OK
            ]);

            if ($backupcount > 0) {
                return true;
            }

            // Check for create_backup_course_task in adhoc tasks
            $adhoctasks = $DB->get_records('task_adhoc', [
                'classname' => '\\local_coursetransfer\\task\\create_backup_course_task',
            ]);

            foreach ($adhoctasks as $task) {
                $customdata = @json_decode($task->customdata);
                if ($customdata && isset($customdata->requestoriginid) && 
                    $customdata->requestoriginid == $request->id) {
                    return true;
                }
            }
        }

        // Check for download tasks (on target)
        if ($request->direction == coursetransfer_request::DIRECTION_RESPONSE) {
            $adhoctasks = $DB->get_records('task_adhoc', [
                'classname' => '\\local_coursetransfer\\task\\download_file_course_task',
            ]);

            foreach ($adhoctasks as $task) {
                $customdata = @json_decode($task->customdata);
                if ($customdata && isset($customdata->requestid) && 
                    $customdata->requestid == $request->id) {
                    return true;
                }
            }

            // Check for restore tasks
            $adhoctasks = $DB->get_records('task_adhoc', [
                'classname' => '\\local_coursetransfer\\task\\restore_course_task',
            ]);

            foreach ($adhoctasks as $task) {
                $customdata = @json_decode($task->customdata);
                if ($customdata && isset($customdata->requestid) && 
                    $customdata->requestid == $request->id) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Mark request as stuck (ERROR status) - no active tasks variant
     *
     * @param \stdClass $request
     */
    protected function mark_request_as_stuck_no_tasks($request) {
        global $DB;

        // Log the stuck transfer
        coursetransfer_logger::error(
            $request->id,
            $request->direction == coursetransfer_request::DIRECTION_REQUEST ? 
                coursetransfer_logger::DIRECTION_ORIGIN : 
                coursetransfer_logger::DIRECTION_TARGET,
            'TRANSFER_STUCK',
            'Transfer marked as ERROR: stuck for ' . round((time() - $request->timemodified) / 3600, 1) . 
                ' hours without active tasks',
            '13000',
            [
                'last_status' => $request->status,
                'last_modified' => $request->timemodified,
                'hours_stuck' => round((time() - $request->timemodified) / 3600, 1),
            ]
        );

        // Update request status
        $request->status = coursetransfer_request::STATUS_ERROR;
        $request->error_code = '13000';
        $request->error_message = '⚠️ Esta transferencia parece estar atascada. ' .
                                  'No hay tareas activas pero el estado no se ha completado.';
        $request->timemodified = time();

        $DB->update_record('local_coursetransfer_request', $request);
        
        // Clean up orphaned adhoc tasks for this request
        $this->cleanup_orphaned_adhoc_tasks($request);
    }

    /**
     * Mark request as stuck due to long-running task
     *
     * @param \stdClass $request
     * @param int $runningtime Time the task has been running in seconds
     */
    protected function mark_request_as_stuck($request, $runningtime) {
        global $DB;

        $hoursstuck = round($runningtime / 3600, 1);

        // Log the stuck transfer
        coursetransfer_logger::error(
            $request->id,
            $request->direction == coursetransfer_request::DIRECTION_REQUEST ?
                coursetransfer_logger::DIRECTION_ORIGIN :
                coursetransfer_logger::DIRECTION_TARGET,
            'TRANSFER_STUCK_TASK',
            "Transfer marked as ERROR: task has been running for {$hoursstuck} hours",
            '13001',
            [
                'last_status' => $request->status,
                'last_modified' => $request->timemodified,
                'hours_running' => $hoursstuck,
            ]
        );

        // Update request status
        $request->status = coursetransfer_request::STATUS_ERROR;
        $request->error_code = '13001';
        $request->error_message = "⚠️ Esta transferencia ha sido cancelada automáticamente. " .
                                  "La tarea de procesamiento estuvo ejecutándose por {$hoursstuck} horas, " .
                                  "lo cual indica un problema. Por favor, intente nuevamente.";
        $request->timemodified = time();

        $DB->update_record('local_coursetransfer_request', $request);
    }
    
    /**
     * Clean up orphaned adhoc tasks for a request
     * 
     * This removes adhoc tasks that are stuck in "running" state but don't have
     * an actual process running, which can happen when PHP crashes or times out.
     *
     * @param \stdClass $request
     */
    protected function cleanup_orphaned_adhoc_tasks($request) {
        global $DB;
        
        $classnames = [
            '\\local_coursetransfer\\task\\create_backup_course_task',
            '\\local_coursetransfer\\task\\download_file_course_task',
            '\\local_coursetransfer\\task\\restore_course_task',
            '\\local_coursetransfer\\task\\remove_course_task',
            '\\local_coursetransfer\\task\\remove_category_task',
            '\\local_coursetransfer\\task\\cleanup_course_bin_task',
            '\\local_coursetransfer\\task\\cleanup_category_bin_task',
        ];
        
        $cleaned = 0;
        
        foreach ($classnames as $classname) {
            // Get all adhoc tasks for this class
            $adhoctasks = $DB->get_records('task_adhoc', ['classname' => $classname]);
            
            foreach ($adhoctasks as $task) {
                $customdata = @json_decode($task->customdata);
                if (!$customdata) {
                    continue;
                }
                
                // Check if this task belongs to our stuck request
                $belongstorequest = false;
                
                if ($classname === '\\local_coursetransfer\\task\\create_backup_course_task') {
                    if (isset($customdata->requestoriginid) && $customdata->requestoriginid == $request->id) {
                        $belongstorequest = true;
                    }
                } else {
                    if (isset($customdata->requestid) && $customdata->requestid == $request->id) {
                        $belongstorequest = true;
                    }
                }
                
                if ($belongstorequest) {
                    // Check if task has been running for too long (> 3 hours)
                    $runningtime = time() - $task->timestarted;
                    if ($task->timestarted > 0 && $runningtime > 10800) { // 3 hours
                        try {
                            $DB->delete_records('task_adhoc', ['id' => $task->id]);
                            $cleaned++;
                            
                            mtrace("    → Deleted orphaned adhoc task (ID: {$task->id}, Class: " . 
                                   basename(str_replace('\\', '/', $classname)) . 
                                   ", Running for: " . round($runningtime / 3600, 1) . " hours)");
                            
                            coursetransfer_logger::warning(
                                $request->id,
                                $request->direction == coursetransfer_request::DIRECTION_REQUEST ? 
                                    coursetransfer_logger::DIRECTION_ORIGIN : 
                                    coursetransfer_logger::DIRECTION_TARGET,
                                'ADHOC_TASK_CLEANED',
                                'Orphaned adhoc task removed after being stuck for ' . 
                                    round($runningtime / 3600, 1) . ' hours',
                                null,
                                [
                                    'task_id' => $task->id,
                                    'classname' => $classname,
                                    'hours_running' => round($runningtime / 3600, 1),
                                ]
                            );
                        } catch (\Exception $e) {
                            mtrace("    → ERROR deleting adhoc task {$task->id}: " . $e->getMessage());
                        }
                    }
                }
            }
        }
        
        if ($cleaned > 0) {
            mtrace("    → Cleaned up {$cleaned} orphaned adhoc task(s) for request {$request->id}");
        }
    }
}
