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


use coding_exception;
use dml_exception;
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
class clean_adhoc_failed_task extends \core\task\scheduled_task {

    /** @var int Max FAIL Delay time in seconds */
    const MAX_FAILDELAY = 60;
    
    /** @var int Max running time for adhoc tasks (3 hours) */
    const MAX_RUNNING_TIME = 10800;

    // Use the logging trait to get some nice, juicy, logging.
    use \core\task\logging_trait;

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     * @throws coding_exception
     */
    public function get_name(): string {
        return get_string('clean_adhoc_failed_task', 'local_coursetransfer');
    }

    /**
     * Execute.
     *
     * @throws dml_exception
     */
    public function execute() {
        global $DB;
        $this->log_start("Clean Adhoc Failed Task - Starting...");
        
        // 1. Clean tasks with faildelay > MAX_FAILDELAY
        $tasksdb = $DB->get_records_select('task_adhoc',
                'component = ? AND faildelay > ?',
                ['local_coursetransfer', self::MAX_FAILDELAY]);
        
        $failedcount = 0;
        if (count($tasksdb) > 0) {
            foreach ($tasksdb as $taskdb) {
                try {
                    $DB->delete_records('task_adhoc', ['id' => $taskdb->id]);
                    $failedcount++;
                    $this->log("Removed failed adhoc task (ID: {$taskdb->id}, faildelay: {$taskdb->faildelay})");
                } catch (moodle_exception $e) {
                    $this->log("ERROR removing failed task {$taskdb->id}: " . $e->getMessage());
                }
            }
            $this->log("Cleaned {$failedcount} failed adhoc tasks with high faildelay");
        } else {
            $this->log("No failed adhoc tasks with high faildelay found");
        }
        
        // 2. Clean orphaned tasks that have been running for too long
        $currenttime = time();
        $maxstarttime = $currenttime - self::MAX_RUNNING_TIME;
        
        $orphanedtasks = $DB->get_records_select('task_adhoc',
                'component = ? AND timestarted > 0 AND timestarted < ?',
                ['local_coursetransfer', $maxstarttime]);
        
        $orphanedcount = 0;
        if (count($orphanedtasks) > 0) {
            foreach ($orphanedtasks as $taskdb) {
                $runningtime = $currenttime - $taskdb->timestarted;
                $hoursstuck = round($runningtime / 3600, 1);
                
                try {
                    // Get task details for logging
                    $classname = basename(str_replace('\\', '/', $taskdb->classname));
                    
                    $DB->delete_records('task_adhoc', ['id' => $taskdb->id]);
                    $orphanedcount++;
                    
                    $this->log("Removed orphaned adhoc task (ID: {$taskdb->id}, Class: {$classname}, " .
                              "Running for: {$hoursstuck} hours)");
                    
                } catch (moodle_exception $e) {
                    $this->log("ERROR removing orphaned task {$taskdb->id}: " . $e->getMessage());
                }
            }
            $this->log("Cleaned {$orphanedcount} orphaned adhoc tasks stuck in running state");
        } else {
            $this->log("No orphaned adhoc tasks found");
        }
        
        $totalcleaned = $failedcount + $orphanedcount;
        $this->log_finish("Clean Adhoc Failed Task - Finished. Total cleaned: {$totalcleaned}");
    }
}
