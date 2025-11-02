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
     * Timeout in seconds (4 hours by default)
     * After this time without updates, a transfer is considered stuck
     */
    const STUCK_TIMEOUT = 14400;

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

        // Get current time minus timeout
        $timeoutthreshold = time() - self::STUCK_TIMEOUT;

        // Find requests that are:
        // 1. In progress (status between NOT_STARTED and COMPLETED, excluding ERROR)
        // 2. Haven't been modified in STUCK_TIMEOUT seconds
        // 3. Are individual courses (type = TYPE_COURSE)
        $sql = "SELECT r.*
                FROM {local_coursetransfer_request} r
                WHERE r.type = :type
                  AND r.status > :notstartedstatus
                  AND r.status != :errorstatus
                  AND r.status != :completedstatus
                  AND r.timemodified < :timeout";

        $params = [
            'type' => coursetransfer_request::TYPE_COURSE,
            'notstartedstatus' => coursetransfer_request::STATUS_NOT_STARTED,
            'errorstatus' => coursetransfer_request::STATUS_ERROR,
            'completedstatus' => coursetransfer_request::STATUS_COMPLETED,
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
                $this->mark_as_stuck($request);
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
     * Mark request as stuck (ERROR status)
     *
     * @param \stdClass $request
     */
    protected function mark_as_stuck($request) {
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
    }
}
