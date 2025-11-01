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
 * Logger for coursetransfer operations
 *
 * @package    local_coursetransfer
 * @copyright  2025 Proyecto UNIMOODLE
 * @author     UNIMOODLE Group (Coordinator) <direccion.area.estrategia.digital@uva.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfer;

defined('MOODLE_INTERNAL') || die();

/**
 * Class coursetransfer_logger
 *
 * Provides detailed logging for each step of the course transfer process
 *
 * @package local_coursetransfer
 */
class coursetransfer_logger {

    /** @var int Direction: Origin */
    const DIRECTION_ORIGIN = 0;

    /** @var int Direction: Target */
    const DIRECTION_TARGET = 1;

    /** @var string Status: Info */
    const STATUS_INFO = 'info';

    /** @var string Status: Success */
    const STATUS_SUCCESS = 'success';

    /** @var string Status: Warning */
    const STATUS_WARNING = 'warning';

    /** @var string Status: Error */
    const STATUS_ERROR = 'error';

    // Action constants
    const ACTION_REQUEST_CREATED = 'request_created';
    const ACTION_BACKUP_STARTED = 'backup_started';
    const ACTION_BACKUP_COMPLETED = 'backup_completed';
    const ACTION_BACKUP_FAILED = 'backup_failed';
    const ACTION_DOWNLOAD_STARTED = 'download_started';
    const ACTION_DOWNLOAD_PROGRESS = 'download_progress';
    const ACTION_DOWNLOAD_COMPLETED = 'download_completed';
    const ACTION_DOWNLOAD_FAILED = 'download_failed';
    const ACTION_RESTORE_STARTED = 'restore_started';
    const ACTION_RESTORE_COMPLETED = 'restore_completed';
    const ACTION_RESTORE_FAILED = 'restore_failed';
    const ACTION_CLEANUP_STARTED = 'cleanup_started';
    const ACTION_CLEANUP_COMPLETED = 'cleanup_completed';
    const ACTION_CLEANUP_FAILED = 'cleanup_failed';
    const ACTION_TASK_CREATED = 'task_created';
    const ACTION_TASK_STARTED = 'task_started';
    const ACTION_TASK_COMPLETED = 'task_completed';
    const ACTION_TASK_FAILED = 'task_failed';

    /**
     * Log an entry
     *
     * @param int $requestid Request ID
     * @param int $direction Direction (0=origin, 1=target)
     * @param string $action Action being performed
     * @param string $status Status (info, success, warning, error)
     * @param string|null $message Optional message
     * @param string|null $errorcode Optional error code
     * @param int|null $taskid Optional adhoc task ID
     * @param string|null $taskclassname Optional task classname
     * @param array|null $extradata Optional extra data
     * @return int|false The log entry ID or false on failure
     */
    public static function log(
        int $requestid,
        int $direction,
        string $action,
        string $status,
        ?string $message = null,
        ?string $errorcode = null,
        ?int $taskid = null,
        ?string $taskclassname = null,
        ?array $extradata = null
    ) {
        global $DB;

        $record = new \stdClass();
        $record->request_id = $requestid;
        $record->direction = $direction;
        $record->action = $action;
        $record->status = $status;
        $record->message = $message;
        $record->error_code = $errorcode;
        $record->task_id = $taskid;
        $record->task_classname = $taskclassname;
        $record->extra_data = $extradata ? json_encode($extradata) : null;
        $record->timecreated = time();

        try {
            return $DB->insert_record('local_coursetransfer_log', $record);
        } catch (\Exception $e) {
            // Log to PHP error log if database insert fails
            error_log('coursetransfer_logger: Failed to insert log entry: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Log info message
     *
     * @param int $requestid
     * @param int $direction
     * @param string $action
     * @param string|null $message
     * @param array|null $extradata
     * @return int|false
     */
    public static function info(int $requestid, int $direction, string $action, ?string $message = null, ?array $extradata = null) {
        return self::log($requestid, $direction, $action, self::STATUS_INFO, $message, null, null, null, $extradata);
    }

    /**
     * Log success message
     *
     * @param int $requestid
     * @param int $direction
     * @param string $action
     * @param string|null $message
     * @param array|null $extradata
     * @return int|false
     */
    public static function success(int $requestid, int $direction, string $action, ?string $message = null, ?array $extradata = null) {
        return self::log($requestid, $direction, $action, self::STATUS_SUCCESS, $message, null, null, null, $extradata);
    }

    /**
     * Log warning message
     *
     * @param int $requestid
     * @param int $direction
     * @param string $action
     * @param string|null $message
     * @param string|null $errorcode
     * @param array|null $extradata
     * @return int|false
     */
    public static function warning(int $requestid, int $direction, string $action, ?string $message = null, ?string $errorcode = null, ?array $extradata = null) {
        return self::log($requestid, $direction, $action, self::STATUS_WARNING, $message, $errorcode, null, null, $extradata);
    }

    /**
     * Log error message
     *
     * @param int $requestid
     * @param int $direction
     * @param string $action
     * @param string|null $message
     * @param string|null $errorcode
     * @param array|null $extradata
     * @return int|false
     */
    public static function error(int $requestid, int $direction, string $action, ?string $message = null, ?string $errorcode = null, ?array $extradata = null) {
        return self::log($requestid, $direction, $action, self::STATUS_ERROR, $message, $errorcode, null, null, $extradata);
    }

    /**
     * Log task creation
     *
     * @param int $requestid
     * @param int $direction
     * @param int $taskid
     * @param string $taskclassname
     * @param string|null $message
     * @return int|false
     */
    public static function log_task_created(int $requestid, int $direction, int $taskid, string $taskclassname, ?string $message = null) {
        return self::log($requestid, $direction, self::ACTION_TASK_CREATED, self::STATUS_INFO, $message, null, $taskid, $taskclassname);
    }

    /**
     * Log task start
     *
     * @param int $requestid
     * @param int $direction
     * @param int $taskid
     * @param string $taskclassname
     * @param string|null $message
     * @return int|false
     */
    public static function log_task_started(int $requestid, int $direction, int $taskid, string $taskclassname, ?string $message = null) {
        return self::log($requestid, $direction, self::ACTION_TASK_STARTED, self::STATUS_INFO, $message, null, $taskid, $taskclassname);
    }

    /**
     * Log task completion
     *
     * @param int $requestid
     * @param int $direction
     * @param int $taskid
     * @param string $taskclassname
     * @param string|null $message
     * @param array|null $extradata
     * @return int|false
     */
    public static function log_task_completed(int $requestid, int $direction, int $taskid, string $taskclassname, ?string $message = null, ?array $extradata = null) {
        return self::log($requestid, $direction, self::ACTION_TASK_COMPLETED, self::STATUS_SUCCESS, $message, null, $taskid, $taskclassname, $extradata);
    }

    /**
     * Log task failure
     *
     * @param int $requestid
     * @param int $direction
     * @param int $taskid
     * @param string $taskclassname
     * @param string|null $message
     * @param string|null $errorcode
     * @return int|false
     */
    public static function log_task_failed(int $requestid, int $direction, int $taskid, string $taskclassname, ?string $message = null, ?string $errorcode = null) {
        return self::log($requestid, $direction, self::ACTION_TASK_FAILED, self::STATUS_ERROR, $message, $errorcode, $taskid, $taskclassname);
    }

    /**
     * Get all logs for a request
     *
     * @param int $requestid
     * @return array Array of log entries
     */
    public static function get_logs(int $requestid): array {
        global $DB;
        return $DB->get_records('local_coursetransfer_log', ['request_id' => $requestid], 'timecreated ASC');
    }

    /**
     * Get logs for a request grouped by direction
     *
     * @param int $requestid
     * @return array Array with keys 'origin' and 'target' containing their respective logs
     */
    public static function get_logs_by_direction(int $requestid): array {
        $logs = self::get_logs($requestid);
        $result = [
            'origin' => [],
            'target' => [],
        ];

        foreach ($logs as $log) {
            if ($log->direction == self::DIRECTION_ORIGIN) {
                $result['origin'][] = $log;
            } else {
                $result['target'][] = $log;
            }
        }

        return $result;
    }

    /**
     * Check if there are any error logs for a request
     *
     * @param int $requestid
     * @return bool
     */
    public static function has_errors(int $requestid): bool {
        global $DB;
        return $DB->record_exists('local_coursetransfer_log', [
            'request_id' => $requestid,
            'status' => self::STATUS_ERROR
        ]);
    }

    /**
     * Get the latest log entry for a request
     *
     * @param int $requestid
     * @return \stdClass|false
     */
    public static function get_latest_log(int $requestid) {
        global $DB;
        $logs = $DB->get_records('local_coursetransfer_log', ['request_id' => $requestid], 'timecreated DESC', '*', 0, 1);
        return reset($logs);
    }

    /**
     * Delete all logs for a request
     *
     * @param int $requestid
     * @return bool
     */
    public static function delete_logs(int $requestid): bool {
        global $DB;
        return $DB->delete_records('local_coursetransfer_log', ['request_id' => $requestid]);
    }
}
