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
 * Scheduled task to cleanup old backup files
 *
 * @package    local_coursetransfer
 * @copyright  2025 Proyecto UNIMOODLE
 * @author     UNIMOODLE Group (Coordinator) <direccion.area.estrategia.digital@uva.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfer\task;

use local_coursetransfer\coursetransfer_request;

/**
 * Cleanup old backup files task
 *
 * @package    local_coursetransfer
 * @copyright  2025 Proyecto UNIMOODLE
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_old_backup_files_task extends \core\task\scheduled_task {

    /**
     * Get task name
     *
     * @return string
     * @throws \coding_exception
     */
    public function get_name() {
        return get_string('cleanup_old_backup_files', 'local_coursetransfer');
    }

    /**
     * Execute the task
     *
     * @return void
     */
    public function execute() {
        global $DB;

        $retention_hours = get_config('local_coursetransfer', 'backup_retention_hours') ?: 24;
        $cutoff_time = time() - ($retention_hours * 3600);

        mtrace("Starting cleanup of old backup files (retention: {$retention_hours} hours)");

        $cleaned_origin = 0;
        $cleaned_target = 0;

        // Cleanup origin backups (in local_coursetransfer filearea)
        if (get_config('local_coursetransfer', 'auto_cleanup_origin_backup')) {
            $cleaned_origin = $this->cleanup_origin_backups($cutoff_time);
        }

        // Cleanup target backups (in backup/course filearea)
        if (get_config('local_coursetransfer', 'auto_cleanup_target_backup')) {
            $cleaned_target = $this->cleanup_target_backups($cutoff_time);
        }

        mtrace("Cleanup completed: {$cleaned_origin} origin backups, {$cleaned_target} target backups removed");

        // Cleanup old logs
        $cleaned_logs = $this->cleanup_old_logs();
        mtrace("Log cleanup completed: {$cleaned_logs} log entries removed");
    }

    /**
     * Cleanup origin backup files
     *
     * @param int $cutoff_time
     * @return int Number of files cleaned
     */
    private function cleanup_origin_backups($cutoff_time) {
        global $DB;
        
        $cleaned = 0;
        $fs = get_file_storage();

        // Get requests that are old enough and in failed/error state
        $sql = "SELECT DISTINCT r.id, r.origin_course_id
                FROM {local_coursetransfer_request} r
                WHERE r.type = :type
                  AND r.direction = :direction
                  AND r.status IN (:status_error, :status_incompleted)
                  AND r.timemodified < :cutoff";

        $params = [
            'type' => coursetransfer_request::TYPE_COURSE,
            'direction' => coursetransfer_request::DIRECTION_RESPONSE,
            'status_error' => coursetransfer_request::STATUS_ERROR,
            'status_incompleted' => coursetransfer_request::STATUS_INCOMPLETED,
            'cutoff' => $cutoff_time,
        ];

        $requests = $DB->get_records_sql($sql, $params);

        foreach ($requests as $request) {
            try {
                $context = \context_course::instance($request->origin_course_id);
                
                $file = $fs->get_file(
                    $context->id,
                    'local_coursetransfer',
                    'backup',
                    $request->id,
                    '/',
                    'backup.mbz'
                );

                if ($file && $file->get_timemodified() < $cutoff_time) {
                    $filesize = $file->get_filesize();
                    $file->delete();
                    $cleaned++;
                    mtrace("  Deleted origin backup for request {$request->id} (" . 
                           round($filesize / 1048576, 2) . " MB)");
                }
            } catch (\Exception $e) {
                mtrace("  Error cleaning origin backup for request {$request->id}: " . $e->getMessage());
            }
        }

        return $cleaned;
    }

    /**
     * Cleanup target backup files
     *
     * @param int $cutoff_time
     * @return int Number of files cleaned
     */
    private function cleanup_target_backups($cutoff_time) {
        global $DB;
        
        $cleaned = 0;
        $fs = get_file_storage();

        // Get target requests that are old enough and in failed/error state
        $sql = "SELECT DISTINCT r.id, r.target_course_id
                FROM {local_coursetransfer_request} r
                WHERE r.type = :type
                  AND r.direction = :direction
                  AND r.status IN (:status_error, :status_incompleted, :status_downloaded)
                  AND r.timemodified < :cutoff";

        $params = [
            'type' => coursetransfer_request::TYPE_COURSE,
            'direction' => coursetransfer_request::DIRECTION_REQUEST,
            'status_error' => coursetransfer_request::STATUS_ERROR,
            'status_incompleted' => coursetransfer_request::STATUS_INCOMPLETED,
            'status_downloaded' => coursetransfer_request::STATUS_DOWNLOADED,
            'cutoff' => $cutoff_time,
        ];

        $requests = $DB->get_records_sql($sql, $params);

        foreach ($requests as $request) {
            try {
                if (empty($request->target_course_id)) {
                    continue;
                }
                
                $context = \context_course::instance($request->target_course_id);
                
                // Find and delete backup files with pattern local_coursetransfer_*
                $files = $fs->get_area_files(
                    $context->id,
                    'backup',
                    'course',
                    0,
                    'filename',
                    false
                );

                foreach ($files as $file) {
                    if (strpos($file->get_filename(), 'local_coursetransfer_') === 0 && 
                        $file->get_timemodified() < $cutoff_time) {
                        $filesize = $file->get_filesize();
                        $filename = $file->get_filename();
                        $file->delete();
                        $cleaned++;
                        mtrace("  Deleted target backup {$filename} for request {$request->id} (" . 
                               round($filesize / 1048576, 2) . " MB)");
                    }
                }
            } catch (\Exception $e) {
                mtrace("  Error cleaning target backup for request {$request->id}: " . $e->getMessage());
            }
        }

        return $cleaned;
    }

    /**
     * Cleanup old log entries
     *
     * @return int Number of log entries cleaned
     */
    private function cleanup_old_logs() {
        global $DB;
        
        $retention_days = get_config('local_coursetransfer', 'log_retention_days') ?: 90;
        $cutoff_time = time() - ($retention_days * 86400); // 86400 seconds = 1 day

        mtrace("Starting cleanup of old log entries (retention: {$retention_days} days)");

        try {
            // Count logs to be deleted
            $count = $DB->count_records_select(
                'local_coursetransfer_log',
                'timecreated < :cutoff',
                ['cutoff' => $cutoff_time]
            );

            if ($count > 0) {
                // Delete old log entries
                $DB->delete_records_select(
                    'local_coursetransfer_log',
                    'timecreated < :cutoff',
                    ['cutoff' => $cutoff_time]
                );

                mtrace("  Deleted {$count} log entries older than " . 
                       userdate($cutoff_time, get_string('strftimedatetime', 'core_langconfig')));
            } else {
                mtrace("  No log entries to delete");
            }

            return $count;
        } catch (\Exception $e) {
            mtrace("  Error cleaning log entries: " . $e->getMessage());
            return 0;
        }
    }
}
