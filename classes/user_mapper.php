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

/**
 * User mapper for mapping backup users to existing users.
 *
 * @package    local_coursetransfer
 * @copyright  2023 Proyecto UNIMOODLE
 * @author     UNIMOODLE Group (Coordinator) <direccion.area.estrategia.digital@uva.es>
 * @author     3IPUNT <contacte@tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfer;

defined('MOODLE_INTERNAL') || die();

use restore_controller;
use moodle_exception;
use stdClass;

/**
 * User mapper class.
 */
class user_mapper {

    /** @var string Restore ID */
    protected $restoreid;

    /** @var string Temp directory for backup */
    protected $tempdir;

    /** @var stdClass Request object for logging */
    protected $request;

    /**
     * Constructor.
     *
     * @param string $restoreid The restore controller ID
     * @param string $tempdir The temporary directory where backup is extracted
     * @param stdClass $request The request object for logging
     */
    public function __construct(string $restoreid, string $tempdir, stdClass $request) {
        $this->restoreid = $restoreid;
        $this->tempdir = $tempdir;
        $this->request = $request;
    }

    /**
     * Map users from backup to existing users in DB.
     *
     * @return bool True if mapping was successful (or partially successful), False on critical failure
     */
    public function map_users(): bool {
        global $DB;

        coursetransfer_logger::info(
            $this->request->id,
            coursetransfer_logger::DIRECTION_TARGET,
            'USER_MAPPING_START',
            "Starting robust user mapping. RestoreID: {$this->restoreid}"
        );

        // 1. Read users.xml from the backup temp directory
        $usersfile = $this->tempdir . '/users.xml';
        if (!file_exists($usersfile)) {
            coursetransfer_logger::warning(
                $this->request->id,
                coursetransfer_logger::DIRECTION_TARGET,
                'USER_MAPPING_NO_FILE',
                'users.xml not found in backup. Skipping user mapping (no users to map).'
            );
            return true; // Not an error, just nothing to do
        }

        try {
            // Load XML with simplexml
            $xml = simplexml_load_file($usersfile);
            if (!$xml) {
                throw new moodle_exception('Failed to parse users.xml');
            }

            $mappedconfig = 0;
            $total_users = 0;

            foreach ($xml->user as $userxml) {
                $total_users++;
                $backup_userid = (int)$userxml->attributes()->id;
                $username = (string)$userxml->username;
                $email = (string)$userxml->email;

                // Skip if critical data missing
                if (empty($username)) {
                    continue;
                }

                // 2. Search for existing user in Moodle DB
                // We prefer matching by username (most unique in Moodle usually)
                // We ensure deleted=0 and mnethostid matches local (usually 1)
                $existing = $DB->get_record('user', [
                    'username' => $username, 
                    'deleted' => 0, 
                    'mnethostid' => $DB->get_field('config', 'value', ['name' => 'mnet_localhost_id'])
                ]);

                // Fallback to email if configured (optional, for now strictly username as requested for safety)
                /*
                if (!$existing && !empty($email)) {
                    $existing = $DB->get_record('user', ['email' => $email, 'deleted' => 0, ...]);
                }
                */

                if ($existing) {
                    // 3. Insert MAPPING into backup_ids_temp
                    // This tells the restore controller: "When you process user $backup_userid, use $existing->id"
                    
                    // Check if mapping already exists (unlikely in fresh restore, but good practice)
                    $mapping = new stdClass();
                    $mapping->backupid = $this->restoreid;
                    $mapping->itemname = 'user';
                    $mapping->itemid = $backup_userid;
                    $mapping->newitemid = $existing->id;
                    $mapping->parentitemid = 0;
                    $mapping->info = 'mapped_by_plugin';

                    // We use insert_record_raw for speed and to bypass some checks if needed, 
                    // but standard insert_record is safer. backup_ids_temp is a temp table.
                    // Important: The restore process expects records here.
                    
                    // We need to delete any existing mapping for this itemid first to be safe
                    // But first check if table exists to avoid dml_exception
                    $dbman = $DB->get_manager();
                    $tablename = 'backup_ids_temp';
                    $temptable = new \xmldb_table($tablename);
                    
                    if (!$dbman->table_exists($temptable)) {
                         // If we can't map, we just abort this specific user but log warning
                         coursetransfer_logger::warning(
                             $this->request->id,
                             coursetransfer_logger::DIRECTION_TARGET,
                             'USER_MAPPING_TABLE_MISSING',
                             'backup_ids_temp table not found. Aborting user mapping. Native restore will proceed.'
                         );
                         return false; 
                    }

                    $DB->delete_records('backup_ids_temp', [
                        'backupid' => $this->restoreid, 
                        'itemname' => 'user', 
                        'itemid' => $backup_userid
                    ]);

                    $DB->insert_record('backup_ids_temp', $mapping);
                    $mappedconfig++;
                }
            }

            coursetransfer_logger::info(
                $this->request->id,
                coursetransfer_logger::DIRECTION_TARGET,
                'USER_MAPPING_COMPLETE',
                "User mapping completed. Total users in backup: {$total_users}. Mapped to existing: {$mappedconfig}."
            );

            return true;

        } catch (\Exception $e) {
            coursetransfer_logger::error(
                $this->request->id,
                coursetransfer_logger::DIRECTION_TARGET,
                'USER_MAPPING_ERROR',
                'Error during user mapping: ' . $e->getMessage(),
                null,
                ['exception' => get_class($e)]
            );
            return false;
        }
    }
}
