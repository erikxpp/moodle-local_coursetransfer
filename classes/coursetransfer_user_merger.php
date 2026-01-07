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
 * User merger utility for detecting and logging duplicate users after restore
 *
 * @package    local_coursetransfer
 * @copyright  2023 Proyecto UNIMOODLE
 * @author     UNIMOODLE Group (Coordinator) <direccion.area.estrategia.digital@uva.es>
 * @author     3IPUNT <contacte@tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfer;

defined('MOODLE_INTERNAL') || die();

/**
 * Helper class for detecting and logging duplicate users created during restore
 *
 * When using 100% native Moodle restore, if users already exist in the destination,
 * Moodle creates duplicates with suffixes (_1, _2, etc.). This class detects those
 * duplicates and logs them for administrator review.
 */
class coursetransfer_user_merger {

    /**
     * Detect duplicate users created during restore
     *
     * @param int $courseid Course that was just restored
     * @param \stdClass $request Transfer request for logging context
     * @return array Array of duplicate user info: ['original' => user, 'duplicate' => user, 'base_username' => string]
     */
    public static function detect_duplicate_users(int $courseid, \stdClass $request): array {
        global $DB;

        $duplicates = [];

        try {
            // Find users enrolled in this course with username suffixes (_1, _2, _3, etc.)
            $sql = "SELECT u.id, u.username, u.firstname, u.lastname, u.email
                    FROM {user} u
                    JOIN {user_enrolments} ue ON ue.userid = u.id
                    JOIN {enrol} e ON e.id = ue.enrolid
                    WHERE e.courseid = :courseid
                    AND u.deleted = 0
                    AND u.suspended = 0
                    AND u.mnethostid = 1
                    AND (u.username LIKE '%\\_1' OR
                         u.username LIKE '%\\_2' OR
                         u.username LIKE '%\\_3' OR
                         u.username LIKE '%\\_4' OR
                         u.username LIKE '%\\_5')";

            $potentialduplicates = $DB->get_records_sql($sql, ['courseid' => $courseid]);

            foreach ($potentialduplicates as $dup) {
                // Extract base username (without suffix)
                if (preg_match('/^(.+)_(\d+)$/', $dup->username, $matches)) {
                    $baseusername = $matches[1];
                    $suffix = $matches[2];

                    // Search for original user (without suffix)
                    $original = $DB->get_record('user', [
                        'username' => $baseusername,
                        'deleted' => 0,
                        'mnethostid' => 1
                    ]);

                    if ($original) {
                        $duplicates[] = [
                            'original' => $original,
                            'duplicate' => $dup,
                            'base_username' => $baseusername,
                            'suffix' => $suffix
                        ];
                    }
                }
            }

        } catch (\Exception $e) {
            coursetransfer_logger::error(
                $request->id,
                coursetransfer_logger::DIRECTION_TARGET,
                'DUPLICATE_DETECTION_ERROR',
                'Error detecting duplicate users: ' . $e->getMessage(),
                null,
                ['exception' => get_class($e)]
            );
        }

        return $duplicates;
    }

    /**
     * Log duplicate users for administrator review
     *
     * @param array $duplicates Array of duplicate user info from detect_duplicate_users()
     * @param \stdClass $request Transfer request for logging
     * @return void
     */
    public static function log_duplicates(array $duplicates, \stdClass $request): void {
        if (empty($duplicates)) {
            return;
        }

        $count = count($duplicates);

        // Log summary
        coursetransfer_logger::warning(
            $request->id,
            coursetransfer_logger::DIRECTION_TARGET,
            'DUPLICATE_USERS_DETECTED',
            "Detected {$count} duplicate user(s) created during restore. These users already existed in destination and were duplicated with suffixes.",
            null,
            ['duplicate_count' => $count]
        );

        // Log details for each duplicate
        foreach ($duplicates as $index => $dupinfo) {
            $original = $dupinfo['original'];
            $duplicate = $dupinfo['duplicate'];
            $baseusername = $dupinfo['base_username'];
            $suffix = $dupinfo['suffix'];

            $indexplus = $index + 1;
            coursetransfer_logger::info(
                $request->id,
                coursetransfer_logger::DIRECTION_TARGET,
                'DUPLICATE_USER_DETAIL',
                "Duplicate user {$indexplus}/{$count}: '{$duplicate->username}' (ID: {$duplicate->id}) is duplicate of '{$original->username}' (ID: {$original->id})",
                [
                    'original_id' => $original->id,
                    'original_username' => $original->username,
                    'original_email' => $original->email,
                    'duplicate_id' => $duplicate->id,
                    'duplicate_username' => $duplicate->username,
                    'duplicate_email' => $duplicate->email,
                    'base_username' => $baseusername,
                    'suffix' => $suffix
                ]
            );
        }

        // Log recommendation
        coursetransfer_logger::info(
            $request->id,
            coursetransfer_logger::DIRECTION_TARGET,
            'DUPLICATE_RESOLUTION_INFO',
            "Administrator action required: Review duplicate users and merge manually if needed. Duplicate users were created because they already existed in destination with same username.",
            [
                'recommendation' => 'Use Moodle user merging tool or merge manually',
                'affected_users' => array_map(function($d) {
                    return [
                        'duplicate' => $d['duplicate']->username,
                        'original' => $d['original']->username
                    ];
                }, $duplicates)
            ]
        );
    }

    /**
     * Get summary of users restored from backup
     *
     * @param \restore_controller $rc Restore controller
     * @return array Summary with counts: total, mapped, created
     */
    public static function get_user_restore_summary(\restore_controller $rc): array {
        global $DB;

        try {
            $restoreid = $rc->get_restoreid();

            // Count users in backup_ids_temp
            $totalusers = $DB->count_records('backup_ids_temp', [
                'backupid' => $restoreid,
                'itemname' => 'user'
            ]);

            // Count users that were mapped to existing (itemid != newitemid)
            $mappedusers = $DB->count_records_select('backup_ids_temp',
                "backupid = :restoreid AND itemname = 'user' AND newitemid != 0 AND newitemid != itemid",
                ['restoreid' => $restoreid]
            );

            // Count users that were created (newitemid = 0 initially, but may have been assigned later)
            $createdusers = $totalusers - $mappedusers;

            return [
                'total' => $totalusers,
                'mapped' => $mappedusers,
                'created' => $createdusers
            ];

        } catch (\Exception $e) {
            return [
                'total' => 0,
                'mapped' => 0,
                'created' => 0,
                'error' => $e->getMessage()
            ];
        }
    }
}
