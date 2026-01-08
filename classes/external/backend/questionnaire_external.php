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
 * External API for questionnaire survey data migration.
 *
 * This webservice allows the target Moodle to request complete survey data
 * (including questions, choices, and responses) from the origin Moodle
 * when questionnaires reference public surveys that weren't included in backup.
 *
 * @package    local_coursetransfer
 * @copyright  2025 Proyecto UNIMOODLE
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfer\external\backend;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use local_coursetransfer\coursetransfer;
use moodle_exception;
use stdClass;

/**
 * External API class for questionnaire data migration.
 */
class questionnaire_external extends external_api {

    /**
     * Get questionnaire survey data parameters.
     *
     * @return external_function_parameters
     */
    public static function get_questionnaire_survey_data_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'field' => new external_value(PARAM_TEXT, 'Authentication field'),
                'value' => new external_value(PARAM_TEXT, 'Authentication value'),
                'surveyid' => new external_value(PARAM_INT, 'Survey ID to retrieve'),
                'questionnaireid' => new external_value(PARAM_INT, 'Questionnaire ID for responses', VALUE_DEFAULT, 0),
                'includeresponses' => new external_value(PARAM_BOOL, 'Include user responses', VALUE_DEFAULT, true),
            ]
        );
    }

    /**
     * Get questionnaire survey data.
     *
     * Returns complete survey data including questions, choices, and optionally responses.
     * Responses include user email for mapping in destination.
     *
     * @param string $field Authentication field
     * @param string $value Authentication value
     * @param int $surveyid Survey ID to retrieve
     * @param int $questionnaireid Questionnaire ID for responses (0 for survey only)
     * @param bool $includeresponses Whether to include user responses
     * @return array
     */
    public static function get_questionnaire_survey_data(
        string $field,
        string $value,
        int $surveyid,
        int $questionnaireid = 0,
        bool $includeresponses = true
    ): array {
        global $DB;

        $params = self::validate_parameters(
            self::get_questionnaire_survey_data_parameters(),
            [
                'field' => $field,
                'value' => $value,
                'surveyid' => $surveyid,
                'questionnaireid' => $questionnaireid,
                'includeresponses' => $includeresponses,
            ]
        );

        $surveyid = $params['surveyid'];
        $questionnaireid = $params['questionnaireid'];
        $includeresponses = $params['includeresponses'];

        $errors = [];
        $data = new stdClass();
        $data->survey = null;
        $data->questions = [];
        $data->choices = [];
        $data->responses = [];

        try {
            // Authenticate
            $authres = coursetransfer::auth_user($field, $value);
            if (!$authres['success']) {
                return [
                    'success' => false,
                    'data' => $data,
                    'errors' => [$authres['error']],
                ];
            }

            // Check if questionnaire module is installed
            if (!$DB->get_manager()->table_exists('questionnaire_survey')) {
                return [
                    'success' => false,
                    'data' => $data,
                    'errors' => [['code' => '20001', 'msg' => 'mod_questionnaire not installed']],
                ];
            }

            // Get survey
            $survey = $DB->get_record('questionnaire_survey', ['id' => $surveyid]);
            if (!$survey) {
                return [
                    'success' => false,
                    'data' => $data,
                    'errors' => [['code' => '20002', 'msg' => "Survey {$surveyid} not found"]],
                ];
            }

            // Convert survey to array for JSON
            $data->survey = self::record_to_array($survey);

            // Get questions
            $questions = $DB->get_records('questionnaire_question', ['surveyid' => $surveyid], 'position ASC');
            foreach ($questions as $question) {
                $data->questions[] = self::record_to_array($question);
            }

            // Get choices for all questions
            $questionids = array_keys($questions);
            if (!empty($questionids)) {
                list($insql, $inparams) = $DB->get_in_or_equal($questionids);
                $choices = $DB->get_records_select(
                    'questionnaire_quest_choice',
                    "question_id {$insql}",
                    $inparams,
                    'question_id ASC, id ASC'
                );
                foreach ($choices as $choice) {
                    $data->choices[] = self::record_to_array($choice);
                }
            }

            // Get responses if requested and questionnaireid provided
            if ($includeresponses && $questionnaireid > 0) {
                $responses = $DB->get_records('questionnaire_response', ['questionnaireid' => $questionnaireid]);
                
                foreach ($responses as $response) {
                    $responsedata = self::record_to_array($response);
                    
                    // Add user email for mapping in destination
                    $user = $DB->get_record('user', ['id' => $response->userid], 'id, email, username');
                    if ($user) {
                        $responsedata['user_email'] = $user->email;
                        $responsedata['user_username'] = $user->username;
                    } else {
                        $responsedata['user_email'] = '';
                        $responsedata['user_username'] = '';
                    }

                    // Get response details from all response tables
                    $responsedata['response_bool'] = self::get_response_details(
                        'questionnaire_response_bool', $response->id
                    );
                    $responsedata['response_date'] = self::get_response_details(
                        'questionnaire_response_date', $response->id
                    );
                    $responsedata['response_multiple'] = self::get_response_details(
                        'questionnaire_resp_multiple', $response->id
                    );
                    $responsedata['response_other'] = self::get_response_details(
                        'questionnaire_response_other', $response->id
                    );
                    $responsedata['response_rank'] = self::get_response_details(
                        'questionnaire_response_rank', $response->id
                    );
                    $responsedata['response_single'] = self::get_response_details(
                        'questionnaire_resp_single', $response->id
                    );
                    $responsedata['response_text'] = self::get_response_details(
                        'questionnaire_response_text', $response->id
                    );

                    $data->responses[] = $responsedata;
                }
            }

            return [
                'success' => true,
                'data' => $data,
                'errors' => [],
            ];

        } catch (moodle_exception $e) {
            return [
                'success' => false,
                'data' => $data,
                'errors' => [['code' => '20099', 'msg' => $e->getMessage()]],
            ];
        }
    }

    /**
     * Get response details from a specific response table.
     *
     * @param string $table Table name
     * @param int $responseid Response ID
     * @return array
     */
    private static function get_response_details(string $table, int $responseid): array {
        global $DB;
        
        $details = [];
        $records = $DB->get_records($table, ['response_id' => $responseid]);
        foreach ($records as $record) {
            $details[] = self::record_to_array($record);
        }
        return $details;
    }

    /**
     * Convert a database record to array, handling all field types.
     *
     * @param stdClass $record Database record
     * @return array
     */
    private static function record_to_array(stdClass $record): array {
        $arr = [];
        foreach ($record as $key => $value) {
            $arr[$key] = $value;
        }
        return $arr;
    }

    /**
     * Get questionnaire survey data returns.
     *
     * @return external_single_structure
     */
    public static function get_questionnaire_survey_data_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'data' => new external_single_structure([
                'survey' => new external_value(PARAM_RAW, 'Survey data as JSON', VALUE_OPTIONAL),
                'questions' => new external_value(PARAM_RAW, 'Questions array as JSON', VALUE_OPTIONAL),
                'choices' => new external_value(PARAM_RAW, 'Choices array as JSON', VALUE_OPTIONAL),
                'responses' => new external_value(PARAM_RAW, 'Responses array as JSON', VALUE_OPTIONAL),
            ], 'Response data', VALUE_OPTIONAL),
            'errors' => new external_multiple_structure(
                new external_single_structure([
                    'code' => new external_value(PARAM_TEXT, 'Error code'),
                    'msg' => new external_value(PARAM_TEXT, 'Error message'),
                ], 'Error details'),
                'Errors list',
                VALUE_OPTIONAL
            ),
        ]);
    }
}
