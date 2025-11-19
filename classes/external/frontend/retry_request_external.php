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
 * External service to retry/reprocess failed course transfer requests
 *
 * @package    local_coursetransfer
 * @copyright  2025 Proyecto UNIMOODLE
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfer\external\frontend;

use context_system;
use dml_exception;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use local_coursetransfer\coursetransfer;
use local_coursetransfer\coursetransfer_logger;
use local_coursetransfer\coursetransfer_request;
use local_coursetransfer\models\configuration_course;
use moodle_exception;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

/**
 * Class retry_request_external
 *
 * @package    local_coursetransfer
 */
class retry_request_external extends external_api {

    /**
     * Parameters for retry_failed_request
     *
     * @return external_function_parameters
     */
    public static function retry_failed_request_parameters(): external_function_parameters {
        return new external_function_parameters([
            'requestid' => new external_value(PARAM_INT, 'Original request ID to retry'),
        ]);
    }

    /**
     * Retry a failed course transfer request by creating a new request with same parameters
     *
     * @param int $requestid Original request ID
     * @return array
     * @throws dml_exception
     * @throws moodle_exception
     */
    public static function retry_failed_request(int $requestid): array {
        global $USER, $DB;

        $params = self::validate_parameters(
            self::retry_failed_request_parameters(),
            ['requestid' => $requestid]
        );

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/coursetransfer:origin_restore_course', $context);

        $errors = [];
        $success = false;
        $newrequestid = null;

        try {
            // 1. Get original request
            $originalrequest = coursetransfer_request::get($params['requestid']);
            
            if (!$originalrequest) {
                throw new moodle_exception('requestnotfound', 'local_coursetransfer');
            }

            // 2. Validate that request is in error state
            if ((int)$originalrequest->status !== coursetransfer_request::STATUS_ERROR) {
                throw new moodle_exception('onlyerrorrequestscanberetried', 'local_coursetransfer');
            }

            // 3. Validate that it's a course request (not category or remove)
            if ((int)$originalrequest->type !== coursetransfer_request::TYPE_COURSE) {
                throw new moodle_exception('onlycourserequestscanberetried', 'local_coursetransfer');
            }

            // 4. Log the retry attempt in the original request
            coursetransfer_logger::log(
                $params['requestid'],
                $originalrequest->direction,
                'RETRY_INITIATED',
                'info',
                "User {$USER->id} initiated retry of failed request",
                null,
                null,
                null,
                [
                    'original_request_id' => $params['requestid'],
                    'original_error_code' => $originalrequest->error_code,
                    'original_error_message' => $originalrequest->error_message,
                    'retry_user_id' => $USER->id,
                ]
            );

            // 5. Clean up orphaned files and failed adhoc tasks from original request
            self::cleanup_failed_request_resources($params['requestid']);

            // 6. Get site configuration
            $site = coursetransfer::get_site_by_url(
                $originalrequest->siteurl,
                $originalrequest->direction == coursetransfer_request::DIRECTION_REQUEST ? 'origin' : 'target'
            );

            // 7. Reconstruct configuration from original request
            $configuration = new configuration_course(
                (int)$originalrequest->target_target,
                (bool)$originalrequest->target_remove_enrols,
                (bool)$originalrequest->target_remove_groups,
                (bool)$originalrequest->origin_enrolusers,
                (bool)$originalrequest->origin_remove_course,
                0,
                (string)($originalrequest->target_notremove_activities ?? '')
            );

            // 8. Get sections/activities from original request
            $sections = [];
            if (!empty($originalrequest->origin_activities)) {
                $sections = json_decode($originalrequest->origin_activities, true) ?: [];
            }

            // 9. Create new restore request
            $result = coursetransfer::restore_course(
                $USER,
                $site,
                $originalrequest->target_course_id,
                $originalrequest->origin_course_id,
                $configuration,
                $sections
            );

            if ($result['success']) {
                $newrequestid = $result['data']['requestid'];
                $success = true;

                // 10. Log success in both requests
                coursetransfer_logger::log(
                    $params['requestid'],
                    $originalrequest->direction,
                    'RETRY_SUCCESS',
                    'success',
                    "New request created successfully: Request ID {$newrequestid}",
                    null,
                    null,
                    null,
                    [
                        'new_request_id' => $newrequestid,
                    ]
                );

                coursetransfer_logger::log(
                    $newrequestid,
                    $originalrequest->direction,
                    'CREATED_FROM_RETRY',
                    'info',
                    "This request was created as retry of failed request {$params['requestid']}",
                    null,
                    null,
                    null,
                    [
                        'original_request_id' => $params['requestid'],
                        'retry_user_id' => $USER->id,
                    ]
                );
            } else {
                // Convert error objects to proper array format
                foreach ($result['errors'] as $error) {
                    $errordata = [
                        'code' => is_object($error) && isset($error->code) ? $error->code : 'UNKNOWN_ERROR',
                        'msg' => is_object($error) && isset($error->msg) ? $error->msg : 
                                (is_string($error) ? $error : 'Unknown error occurred'),
                    ];
                    $errors[] = $errordata;
                }
                
                coursetransfer_logger::log(
                    $params['requestid'],
                    $originalrequest->direction,
                    'RETRY_FAILED',
                    'error',
                    "Failed to create retry request: " . json_encode($errors),
                    !empty($errors[0]['code']) ? $errors[0]['code'] : null,
                    null,
                    null,
                    ['errors' => $errors]
                );
            }

        } catch (moodle_exception $e) {
            $errors[] = [
                'code' => $e->getCode() ?: 'RETRY_ERROR',
                'msg' => $e->getMessage(),
            ];

            if (isset($originalrequest)) {
                coursetransfer_logger::log(
                    $params['requestid'],
                    $originalrequest->direction ?? coursetransfer_request::DIRECTION_REQUEST,
                    'RETRY_EXCEPTION',
                    'error',
                    $e->getMessage(),
                    $e->getCode() ?: 'RETRY_ERROR',
                    null,
                    null,
                    ['exception' => get_class($e)]
                );
            }
        }

        $response = [
            'success' => $success,
            'errors' => $errors,
        ];

        // Solo incluir 'data' si hay un nuevo request creado
        if ($success && $newrequestid) {
            $response['data'] = [
                'original_request_id' => $params['requestid'],
                'new_request_id' => $newrequestid,
                'redirect_url' => (new moodle_url('/local/coursetransfer/logs_detail.php', ['requestid' => $newrequestid]))->out(false),
            ];
        }

        return $response;
    }

    /**
     * Clean up resources from failed request (orphaned files, stuck tasks)
     *
     * @param int $requestid
     * @return void
     */
    private static function cleanup_failed_request_resources(int $requestid): void {
        global $DB;

        try {
            // 1. Find and remove orphaned backup files
            $fs = get_file_storage();
            $request = coursetransfer_request::get($requestid);
            
            if ($request && $request->target_course_id) {
                $context = \context_course::instance($request->target_course_id, IGNORE_MISSING);
                if ($context) {
                    $files = $fs->get_area_files(
                        $context->id,
                        'local_coursetransfer',
                        'backup',
                        $requestid
                    );
                    
                    foreach ($files as $file) {
                        if (!$file->is_directory()) {
                            $file->delete();
                            coursetransfer_logger::log(
                                $requestid,
                                $request->direction,
                                'CLEANUP_FILE_DELETED',
                                'info',
                                "Deleted orphaned backup file: {$file->get_filename()}",
                                null,
                                null,
                                null,
                                ['file_id' => $file->get_id()]
                            );
                        }
                    }
                }
            }

            // 2. Find and delete related failed adhoc tasks
            $adhoctasks = $DB->get_records_sql("
                SELECT t.* 
                FROM {task_adhoc} t
                WHERE t.component = 'local_coursetransfer'
                  AND (t.customdata LIKE :requestid1 OR t.customdata LIKE :requestid2)
                  AND t.faildelay > 0
            ", [
                'requestid1' => '%"requestid":' . $requestid . '%',
                'requestid2' => '%"requestoriginid":' . $requestid . '%'
            ]);

            foreach ($adhoctasks as $task) {
                $DB->delete_records('task_adhoc', ['id' => $task->id]);
                
                if ($request) {
                    coursetransfer_logger::log(
                        $requestid,
                        $request->direction,
                        'CLEANUP_TASK_DELETED',
                        'info',
                        "Deleted failed adhoc task: {$task->classname}",
                        null,
                        $task->id,
                        $task->classname,
                        ['task_id' => $task->id]
                    );
                }
            }

        } catch (\Exception $e) {
            // Log but don't fail the retry if cleanup fails
            debugging('Cleanup failed during retry: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Return structure for retry_failed_request
     *
     * @return external_single_structure
     */
    public static function retry_failed_request_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Operation success'),
            'errors' => new external_multiple_structure(
                new external_single_structure([
                    'code' => new external_value(PARAM_TEXT, 'Error code'),
                    'msg' => new external_value(PARAM_TEXT, 'Error message'),
                ])
            ),
            'data' => new external_single_structure([
                'original_request_id' => new external_value(PARAM_INT, 'Original request ID'),
                'new_request_id' => new external_value(PARAM_INT, 'New request ID created'),
                'redirect_url' => new external_value(PARAM_RAW, 'URL to redirect to new request logs'),
            ], 'Response data', VALUE_OPTIONAL),
        ]);
    }
}
