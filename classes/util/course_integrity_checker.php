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

namespace local_coursetransfer\util;

defined('MOODLE_INTERNAL') || die;

/**
 * Class course_integrity_checker
 *
 * Checks course data integrity before operations like backup.
 *
 * @package    local_coursetransfer
 * @copyright  2025 Proyecto UNIMOODLE
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_integrity_checker {

    /**
     * Check if a course has integrity issues that would cause backup/restore failure.
     *
     * @param int $courseid The course ID to check
     * @return array Result array ['valid' => bool, 'errors' => array]
     */
    public static function check_course_integrity(int $courseid): array {
        $result = [
            'valid' => true,
            'errors' => []
        ];

        // Check 1: Quiz Attempt Integrity
        // Detects attempts referencing non-existent question answers
        $quiz_errors = self::check_quiz_attempt_integrity($courseid);
        if (!empty($quiz_errors)) {
            $result['valid'] = false;
            $result['errors'] = array_merge($result['errors'], $quiz_errors);
        }

        return $result;
    }

    /**
     * Check for orphaned question attempt data.
     * Use raw SQL to detect the specific condition causing error 10400/restore_step_exception.
     *
     * @param int $courseid
     * @return array List of error strings
     */
    private static function check_quiz_attempt_integrity(int $courseid): array {
        global $DB;
        $errors = [];

        // Check if quiz module is installed
        if (!$DB->get_manager()->table_exists('quiz')) {
            return [];
        }

        // Logic:
        // 1. Get all quizzes in the course
        // 2. Join with attempts -> question_usages -> question_attempts -> question_attempt_steps -> question_attempt_step_data
        // 3. Filter steps where name implies a reference to an answer id (e.g. 'answer')
        // 4. Left join with question_answers to see if the value exists
        // 5. If question_answers.id is NULL, it's a corruption
        
        // Note: This is an expensive query, but necessary for safety.
        // We limit to 'answer' and 'response%' which typically hold the answer ID for multichoice.
        
        $sql = "
            SELECT count(qasd.id) as corrupted_count
            FROM {course_modules} cm
            JOIN {modules} m ON m.id = cm.module
            JOIN {quiz} q ON q.course = cm.course AND q.id = cm.instance
            JOIN {quiz_attempts} qa ON qa.quiz = q.id
            JOIN {question_usages} qu ON qu.id = qa.uniqueid
            JOIN {question_attempts} qatt ON qatt.questionusageid = qu.id
            JOIN {question_attempt_steps} qas ON qas.questionattemptid = qatt.id
            JOIN {question_attempt_step_data} qasd ON qasd.attemptstepid = qas.id
            -- The corruption happens when 'value' is an ID that doesn't exist in question_answers
            -- Typically for multichoice, the 'answer' field holds the answer ID
            LEFT JOIN {question_answers} ans ON ans.id = " . $DB->sql_cast_to_char('qasd.value') . "
            WHERE cm.course = :courseid
              AND m.name = 'quiz'
              -- Only check multichoice type questions or generally 'answer' fields that are numeric
              AND (qasd.name = 'answer' OR qasd.name LIKE 'response_%') 
              -- Basic check that value looks like an ID
              AND " . $DB->sql_regex('qasd.value', '^[0-9]+$') . "
              AND ans.id IS NULL
        ";

        try {
            // Because the JOIN is complex and depends on question type specifics,
            // we use a simplified heuristic: if we find usage of IDs in step_data that act like answers
            // but don't exist, we validly flag it.
            // However, different qtypes store data differently. 
            // The specific error reported was 'restore_qtype_multichoice_plugin'.
            // For multichoice, 'answer' (single) or 'response_x' (multiple) fields hold records from mdl_question_answers.
            
            // Let's refine the query to target the specific table structure associated with the error.
            // The error is get_mapping('question_answer', ID).
            
            $sql_multichoice = "
                SELECT count(DISTINCT qasd.id)
                FROM {course_modules} cm
                JOIN {modules} m ON m.id = cm.module
                JOIN {quiz} q ON q.course = cm.course AND q.id = cm.instance
                JOIN {quiz_attempts} qa ON qa.quiz = q.id
                JOIN {question_usages} qu ON qu.id = qa.uniqueid
                JOIN {question_attempts} qatt ON qatt.questionusageid = qu.id
                JOIN {question} que ON que.id = qatt.questionid
                JOIN {question_attempt_steps} qas ON qas.questionattemptid = qatt.id
                JOIN {question_attempt_step_data} qasd ON qasd.attemptstepid = qas.id
                LEFT JOIN {question_answers} ans ON ans.id = " . $DB->sql_cast_to_char('qasd.value') . "
                WHERE cm.course = :courseid
                  AND m.name = 'quiz'
                  AND que.qtype = 'multichoice'
                  AND (qasd.name = 'answer' OR qasd.name LIKE 'response_%')
                  AND " . $DB->sql_regex('qasd.value', '^[0-9]+$') . "
                  AND ans.id IS NULL
            ";
            
            $count = $DB->count_records_sql($sql_multichoice, ['courseid' => $courseid]);
            
            if ($count > 0) {
                $errors[] = "FATAL: Found {$count} corrupted quiz attempt answer(s) (orphaned references). This will cause restore failure.";
            }

        } catch (\Exception $e) {
            // If the check fails (e.g. DB structure diff), we log but don't block unless we're sure
            // In strict mode, we might want to return an error. Here we'll just return the exception as error.
            $errors[] = "Integrity check failed to execute: " . $e->getMessage();
        }

        return $errors;
    }
}
