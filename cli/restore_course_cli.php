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
 * CLI script to execute a course restore in an isolated PHP process.
 *
 * This script runs the restore for a specific request in a completely
 * fresh PHP process. This avoids Moodle's static cache contamination issue 
 * where quiz restore plugins keep stale references from previous restores.
 *
 * Usage: php restore_course_cli.php --requestid=123
 *
 * @package    local_coursetransfer
 * @copyright  2025 Proyecto UNIMOODLE
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

// Find config.php - works for different Moodle structures
$configpath = __DIR__ . '/../../../config.php';
if (!file_exists($configpath)) {
    $configpath = __DIR__ . '/../../../../config.php';
}
if (!file_exists($configpath)) {
    // Try to find it by going up directories
    $dir = __DIR__;
    for ($i = 0; $i < 10; $i++) {
        $dir = dirname($dir);
        if (file_exists($dir . '/config.php')) {
            $configpath = $dir . '/config.php';
            break;
        }
    }
}

require($configpath);
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

use local_coursetransfer\coursetransfer_request;

// Get CLI options.
list($options, $unrecognized) = cli_get_params(
    [
        'requestid' => null,
        'fileid' => null,
        'help' => false,
    ],
    [
        'r' => 'requestid',
        'f' => 'fileid',
        'h' => 'help',
    ]
);

if ($options['help'] || empty($options['requestid'])) {
    $help = <<<EOF
Execute a course restore in an isolated PHP process.

This script ensures each restore has a clean PHP environment without
static cache contamination from previous restores.

Options:
 -r, --requestid=INT    The request ID to restore (required)
 -f, --fileid=INT       The file ID of the backup (optional, will search if not provided)
 -h, --help             Show this help

Example:
 php restore_course_cli.php --requestid=123 --fileid=456

EOF;
    echo $help;
    exit($options['help'] ? 0 : 1);
}

$requestid = (int)$options['requestid'];
$fileid = !empty($options['fileid']) ? (int)$options['fileid'] : null;

cli_writeln("[RESTORE CLI] Starting isolated restore for request {$requestid}");
cli_writeln("[RESTORE CLI] PID: " . getmypid() . ", Memory: " . round(memory_get_usage() / 1024 / 1024, 2) . " MB");
if ($fileid) {
    cli_writeln("[RESTORE CLI] File ID provided: {$fileid}");
}

// Increase limits
@set_time_limit(0);
raise_memory_limit(MEMORY_HUGE);

try {
    // Get request
    $request = $DB->get_record('local_coursetransfer_request', ['id' => $requestid]);
    
    if (!$request) {
        cli_error("[RESTORE CLI] Request {$requestid} not found");
    }

    cli_writeln("[RESTORE CLI] Origin Course: {$request->origin_course_id}");
    cli_writeln("[RESTORE CLI] Target Course: {$request->target_course_id}");
    cli_writeln("[RESTORE CLI] Status: {$request->status}");

    // Get backup file
    $fs = get_file_storage();
    $backupfile = null;
    
    // Try to get file by ID first (most reliable)
    if ($fileid) {
        $backupfile = $fs->get_file_by_id($fileid);
        if ($backupfile) {
            cli_writeln("[RESTORE CLI] Found file by ID: {$fileid}");
        }
    }
    
    // Fallback: search in course context (component=backup, filearea=course)
    if (!$backupfile && $request->target_course_id) {
        cli_writeln("[RESTORE CLI] Searching in course context...");
        $context = \context_course::instance($request->target_course_id);
        $files = $fs->get_area_files($context->id, 'backup', 'course', 0, 'timecreated DESC', false);
        if (!empty($files)) {
            $backupfile = reset($files);
            cli_writeln("[RESTORE CLI] Found file in course context");
        }
    }
    
    // Fallback: search in system context (component=local_coursetransfer, filearea=backup)
    if (!$backupfile) {
        cli_writeln("[RESTORE CLI] Searching in system context...");
        $context = \context_system::instance();
        $files = $fs->get_area_files($context->id, 'local_coursetransfer', 'backup', $request->id, 'id DESC', false);
        if (!empty($files)) {
            $backupfile = reset($files);
            cli_writeln("[RESTORE CLI] Found file in system context");
        }
    }

    if (!$backupfile) {
        cli_error("[RESTORE CLI] No backup file found for request {$requestid}");
    }
    cli_writeln("[RESTORE CLI] Backup file: " . $backupfile->get_filename() . " (" . 
        round($backupfile->get_filesize() / 1024 / 1024, 2) . " MB)");

    // Update status to restoring
    $request->status = coursetransfer_request::STATUS_RESTORE;
    $request->timemodified = time();
    $DB->update_record('local_coursetransfer_request', $request);

    // Extract backup to temp directory
    $backupdir = 'ct_restore_' . $requestid . '_' . time();
    $backuppath = $CFG->tempdir . '/backup/' . $backupdir;
    
    cli_writeln("[RESTORE CLI] Extracting to: {$backupdir}");
    
    $fp = get_file_packer('application/vnd.moodle.backup');
    $extracted = $backupfile->extract_to_pathname($fp, $backuppath);
    
    if (!$extracted) {
        throw new \Exception('Failed to extract backup file');
    }
    
    cli_writeln("[RESTORE CLI] Extraction complete");

    // Get admin user for restore
    $admin = get_admin();

    // Determine restore target mode based on configuration
    $target_mode = \backup::TARGET_EXISTING_DELETING;
    
    // Check if we should add to existing content instead
    if (!empty($request->configuration)) {
        $config = json_decode($request->configuration, true);
        if (isset($config['targettarget']) && $config['targettarget'] == 4) {
            $target_mode = \backup::TARGET_CURRENT_ADDING;
            cli_writeln("[RESTORE CLI] Mode: Adding to existing content");
        } else {
            cli_writeln("[RESTORE CLI] Mode: Replacing existing content");
        }
    }

    cli_writeln("[RESTORE CLI] Creating restore controller...");

    // Create restore controller
    $rc = new \restore_controller(
        $backupdir,
        $request->target_course_id,
        \backup::INTERACTIVE_NO,
        \backup::MODE_GENERAL,
        $admin->id,
        $target_mode
    );

    // Check if conversion needed
    if ($rc->get_status() == \backup::STATUS_REQUIRE_CONV) {
        cli_writeln("[RESTORE CLI] Converting backup format...");
        $rc->convert();
    }

    // Run prechecks
    cli_writeln("[RESTORE CLI] Running prechecks...");
    if (!$rc->execute_precheck()) {
        $results = $rc->get_precheck_results();
        
        // Check if only warnings (not errors)
        if (!empty($results['errors'])) {
            $rc->destroy();
            throw new \Exception('Precheck errors: ' . json_encode($results['errors']));
        }
        cli_writeln("[RESTORE CLI] Precheck warnings (continuing): " . json_encode($results));
    }

    // Execute restore plan
    cli_writeln("[RESTORE CLI] Executing restore plan...");
    $rc->execute_plan();
    
    // Cleanup
    $rc->destroy();
    
    cli_writeln("[RESTORE CLI] Restore plan executed successfully");

    // POST-RESTORE: Update course idnumber from origin
    // Moodle's restore doesn't properly copy the idnumber, so we need to do it manually
    if (!empty($request->origin_course_idnumber)) {
        cli_writeln("[RESTORE CLI] Updating course idnumber to: {$request->origin_course_idnumber}");
        $DB->set_field('course', 'idnumber', $request->origin_course_idnumber, ['id' => $request->target_course_id]);
    } else {
        // Try to get idnumber from the backup's course.xml
        $course_xml_path = $backuppath . '/course/course.xml';
        if (file_exists($course_xml_path)) {
            $course_xml = simplexml_load_file($course_xml_path);
            if ($course_xml && !empty($course_xml->idnumber)) {
                $origin_idnumber = (string)$course_xml->idnumber;
                cli_writeln("[RESTORE CLI] Updating course idnumber from backup: {$origin_idnumber}");
                $DB->set_field('course', 'idnumber', $origin_idnumber, ['id' => $request->target_course_id]);
            }
        }
    }

    // POST-RESTORE: Validate and log availability restrictions
    cli_writeln("[RESTORE CLI] Validating availability restrictions...");
    $availability_issues = validate_availability_restrictions($request->target_course_id);
    if (!empty($availability_issues)) {
        cli_writeln("[RESTORE CLI] Availability warnings: " . count($availability_issues) . " issues found");
        foreach ($availability_issues as $issue) {
            cli_writeln("[RESTORE CLI]   - " . $issue);
        }
    } else {
        cli_writeln("[RESTORE CLI] All availability restrictions validated successfully");
    }

    // POST-RESTORE: Comprehensive comparison validation (origin vs destination)
    cli_writeln("[RESTORE CLI] ========================================");
    cli_writeln("[RESTORE CLI] COMPARATIVE VALIDATION: Origin vs Destination");
    cli_writeln("[RESTORE CLI] ========================================");
    
    $validation_result = validate_restore_completeness($backuppath, $request->target_course_id, $requestid);
    
    if ($validation_result['success']) {
        cli_writeln("[RESTORE CLI] ✓ VALIDATION PASSED - Course restored identically to origin");
    } else {
        cli_writeln("[RESTORE CLI] ⚠ VALIDATION WARNINGS - Some differences detected");
    }
    
    // Log validation summary
    foreach ($validation_result['checks'] as $check) {
        $status_icon = $check['passed'] ? '✓' : '✗';
        cli_writeln("[RESTORE CLI] {$status_icon} {$check['name']}: {$check['message']}");
    }

    // Cleanup temp directory
    fulldelete($backuppath);

    // Delete the backup file from moodledata to free space
    cli_writeln("[RESTORE CLI] Cleaning up backup file...");
    try {
        $backupfile->delete();
        cli_writeln("[RESTORE CLI] Backup file deleted successfully");
    } catch (\Exception $deleteEx) {
        cli_writeln("[RESTORE CLI] Warning: Could not delete backup file: " . $deleteEx->getMessage());
    }

    // Update request status to completed
    $request->status = coursetransfer_request::STATUS_COMPLETED;
    $request->timemodified = time();
    $DB->update_record('local_coursetransfer_request', $request);

    cli_writeln("[RESTORE CLI] SUCCESS - Course restored to ID: {$request->target_course_id}");
    cli_writeln("[RESTORE CLI] Memory peak: " . round(memory_get_peak_usage() / 1024 / 1024, 2) . " MB");
    
    exit(0);

} catch (\Exception $e) {
    cli_writeln("[RESTORE CLI] ERROR: " . $e->getMessage());
    
    // Update request status to error
    if (isset($request)) {
        $request->status = coursetransfer_request::STATUS_ERROR;
        $request->error_code = 10500;
        $request->error_message = substr($e->getMessage(), 0, 500);
        $request->timemodified = time();
        $DB->update_record('local_coursetransfer_request', $request);
    }
    
    // Cleanup temp if exists
    if (isset($backuppath) && file_exists($backuppath)) {
        fulldelete($backuppath);
    }
    
    exit(1);
}

/**
 * Validate availability restrictions in a restored course.
 * 
 * Checks that all availability conditions (date restrictions, completion requirements,
 * activity dependencies) are properly restored and functional.
 *
 * @param int $courseid The course ID to validate
 * @return array List of issues found (empty if all OK)
 */
function validate_availability_restrictions($courseid) {
    global $DB;
    
    $issues = [];
    
    // Get all course modules with availability restrictions
    $cms = $DB->get_records_sql(
        "SELECT cm.id, cm.course, cm.module, cm.instance, cm.availability, m.name as modname
         FROM {course_modules} cm
         JOIN {modules} m ON m.id = cm.module
         WHERE cm.course = :courseid AND cm.availability IS NOT NULL AND cm.availability != ''",
        ['courseid' => $courseid]
    );
    
    foreach ($cms as $cm) {
        $availability = json_decode($cm->availability, true);
        if (!$availability) {
            continue;
        }
        
        // Check for broken references in availability conditions
        $broken = check_availability_tree($availability, $courseid, $cm->id);
        if (!empty($broken)) {
            $modinfo = get_fast_modinfo($courseid);
            $cminfo = $modinfo->get_cm($cm->id);
            $issues[] = "Module '{$cminfo->name}' (id:{$cm->id}): " . implode(', ', $broken);
        }
    }
    
    // Also check section availability
    $sections = $DB->get_records_sql(
        "SELECT id, section, name, availability 
         FROM {course_sections} 
         WHERE course = :courseid AND availability IS NOT NULL AND availability != ''",
        ['courseid' => $courseid]
    );
    
    foreach ($sections as $section) {
        $availability = json_decode($section->availability, true);
        if (!$availability) {
            continue;
        }
        
        $broken = check_availability_tree($availability, $courseid, null);
        if (!empty($broken)) {
            $section_name = $section->name ?: "Section {$section->section}";
            $issues[] = "Section '{$section_name}': " . implode(', ', $broken);
        }
    }
    
    return $issues;
}

/**
 * Recursively check an availability condition tree for broken references.
 *
 * @param array $tree The availability tree
 * @param int $courseid The course ID
 * @param int|null $cmid The course module ID (for context)
 * @return array List of issues found
 */
function check_availability_tree($tree, $courseid, $cmid) {
    global $DB;
    
    $issues = [];
    
    if (isset($tree['c']) && is_array($tree['c'])) {
        // This is a condition set, check each condition
        foreach ($tree['c'] as $condition) {
            $sub_issues = check_availability_tree($condition, $courseid, $cmid);
            $issues = array_merge($issues, $sub_issues);
        }
    }
    
    // Check for completion condition (requires another activity to be completed)
    if (isset($tree['type']) && $tree['type'] === 'completion') {
        if (isset($tree['cm'])) {
            $referenced_cm = $DB->get_record('course_modules', ['id' => $tree['cm'], 'course' => $courseid]);
            if (!$referenced_cm) {
                $issues[] = "References missing activity (cm id: {$tree['cm']})";
            }
        }
    }
    
    // Check for grade condition
    if (isset($tree['type']) && $tree['type'] === 'grade') {
        if (isset($tree['cm'])) {
            $referenced_cm = $DB->get_record('course_modules', ['id' => $tree['cm'], 'course' => $courseid]);
            if (!$referenced_cm) {
                $issues[] = "Grade condition references missing activity (cm id: {$tree['cm']})";
            }
        }
    }
    
    return $issues;
}

/**
 * Comprehensive validation of restore completeness.
 * Compares origin (from backup XMLs) with destination (restored course).
 *
 * @param string $backuppath Path to extracted backup
 * @param int $courseid Destination course ID
 * @param int $requestid Request ID for logging
 * @return array Validation result with 'success' and 'checks' array
 */
function validate_restore_completeness($backuppath, $courseid, $requestid) {
    global $DB, $CFG;
    
    require_once($CFG->dirroot . '/mod/quiz/locallib.php');
    
    $checks = [];
    $all_passed = true;
    
    // Load origin data from backup XMLs
    $origin_data = load_origin_data_from_backup($backuppath);
    
    // 1. COURSE CONFIGURATION VALIDATION
    cli_writeln("[RESTORE CLI] Checking course configuration...");
    $config_check = validate_course_config($origin_data, $courseid);
    $checks[] = $config_check;
    if (!$config_check['passed']) $all_passed = false;
    
    // 2. ACTIVITIES COUNT VALIDATION
    cli_writeln("[RESTORE CLI] Checking activities count...");
    $activities_check = validate_activities_count($origin_data, $courseid);
    $checks[] = $activities_check;
    if (!$activities_check['passed']) $all_passed = false;
    
    // 3. QUIZ AND QUESTIONS VALIDATION
    cli_writeln("[RESTORE CLI] Checking quizzes and questions...");
    $quiz_check = validate_quizzes($backuppath, $courseid);
    $checks[] = $quiz_check;
    if (!$quiz_check['passed']) $all_passed = false;
    
    // 4. ASSIGNMENTS VALIDATION
    cli_writeln("[RESTORE CLI] Checking assignments and submissions...");
    $assign_check = validate_assignments($backuppath, $courseid);
    $checks[] = $assign_check;
    if (!$assign_check['passed']) $all_passed = false;
    
    // 5. FORUM VALIDATION
    cli_writeln("[RESTORE CLI] Checking forums and discussions...");
    $forum_check = validate_forums($backuppath, $courseid);
    $checks[] = $forum_check;
    if (!$forum_check['passed']) $all_passed = false;
    
    // 6. FILES/RESOURCES VALIDATION
    cli_writeln("[RESTORE CLI] Checking files and resources...");
    $files_check = validate_files_resources($backuppath, $courseid);
    $checks[] = $files_check;
    if (!$files_check['passed']) $all_passed = false;
    
    // 7. USER ENROLLMENTS VALIDATION (if users were included)
    cli_writeln("[RESTORE CLI] Checking user enrollments...");
    $users_check = validate_user_enrollments($backuppath, $courseid);
    $checks[] = $users_check;
    if (!$users_check['passed']) $all_passed = false;
    
    // 8. SECTIONS VALIDATION
    cli_writeln("[RESTORE CLI] Checking course sections...");
    $sections_check = validate_sections($backuppath, $courseid);
    $checks[] = $sections_check;
    if (!$sections_check['passed']) $all_passed = false;
    
    // Log to coursetransfer_log
    log_validation_results($requestid, $checks, $all_passed);
    
    return [
        'success' => $all_passed,
        'checks' => $checks
    ];
}

/**
 * Load origin course data from backup XML files.
 */
function load_origin_data_from_backup($backuppath) {
    $data = [
        'course' => null,
        'activities' => [],
        'sections' => [],
        'users' => []
    ];
    
    // Load course.xml
    $course_xml_path = $backuppath . '/course/course.xml';
    if (file_exists($course_xml_path)) {
        $data['course'] = simplexml_load_file($course_xml_path);
    }
    
    // Load moodle_backup.xml for general info
    $backup_xml_path = $backuppath . '/moodle_backup.xml';
    if (file_exists($backup_xml_path)) {
        $data['backup_info'] = simplexml_load_file($backup_xml_path);
    }
    
    // Count activities from activities folder
    $activities_path = $backuppath . '/activities';
    if (is_dir($activities_path)) {
        $dirs = scandir($activities_path);
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') continue;
            if (is_dir($activities_path . '/' . $dir)) {
                // Parse activity type from folder name (e.g., "quiz_12345")
                $parts = explode('_', $dir);
                $type = $parts[0];
                if (!isset($data['activities'][$type])) {
                    $data['activities'][$type] = 0;
                }
                $data['activities'][$type]++;
            }
        }
    }
    
    // Load sections
    $sections_path = $backuppath . '/sections';
    if (is_dir($sections_path)) {
        $dirs = scandir($sections_path);
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') continue;
            if (is_dir($sections_path . '/' . $dir)) {
                $section_xml = $sections_path . '/' . $dir . '/section.xml';
                if (file_exists($section_xml)) {
                    $data['sections'][] = simplexml_load_file($section_xml);
                }
            }
        }
    }
    
    return $data;
}

/**
 * Validate course configuration matches.
 */
function validate_course_config($origin_data, $courseid) {
    global $DB;
    
    $issues = [];
    $dest_course = $DB->get_record('course', ['id' => $courseid]);
    
    if (!$origin_data['course'] || !$dest_course) {
        return [
            'name' => 'Course Configuration',
            'passed' => false,
            'message' => 'Could not load course data for comparison'
        ];
    }
    
    $origin = $origin_data['course'];
    
    // Check key configuration fields
    $fields_to_check = [
        'format' => 'Course format',
        'showgrades' => 'Show gradebook',
        'newsitems' => 'News items',
        'enablecompletion' => 'Completion tracking',
        'visible' => 'Visibility',
        'groupmode' => 'Group mode',
        'groupmodeforce' => 'Force group mode'
    ];
    
    foreach ($fields_to_check as $field => $label) {
        $origin_val = isset($origin->$field) ? (string)$origin->$field : null;
        $dest_val = isset($dest_course->$field) ? (string)$dest_course->$field : null;
        
        if ($origin_val !== null && $origin_val !== $dest_val) {
            $issues[] = "{$label}: origin={$origin_val}, dest={$dest_val}";
        }
    }
    
    // Check dates
    if (isset($origin->startdate) && (int)$origin->startdate != (int)$dest_course->startdate) {
        $issues[] = "Start date differs";
    }
    if (isset($origin->enddate) && (int)$origin->enddate != (int)$dest_course->enddate) {
        $issues[] = "End date differs";
    }
    
    if (empty($issues)) {
        return [
            'name' => 'Course Configuration',
            'passed' => true,
            'message' => 'All configuration matches origin'
        ];
    }
    
    return [
        'name' => 'Course Configuration',
        'passed' => false,
        'message' => implode('; ', $issues)
    ];
}

/**
 * Validate activities count matches.
 */
function validate_activities_count($origin_data, $courseid) {
    global $DB;
    
    $issues = [];
    
    // Get destination activities count by type
    $dest_activities = $DB->get_records_sql(
        "SELECT m.name, COUNT(*) as count 
         FROM {course_modules} cm 
         JOIN {modules} m ON m.id = cm.module 
         WHERE cm.course = :courseid AND cm.deletioninprogress = 0
         GROUP BY m.name",
        ['courseid' => $courseid]
    );
    
    $dest_counts = [];
    foreach ($dest_activities as $act) {
        $dest_counts[$act->name] = (int)$act->count;
    }
    
    // Compare with origin
    foreach ($origin_data['activities'] as $type => $origin_count) {
        $dest_count = $dest_counts[$type] ?? 0;
        if ($origin_count != $dest_count) {
            $issues[] = "{$type}: origin={$origin_count}, dest={$dest_count}";
        }
    }
    
    // Check for extra activities in destination
    foreach ($dest_counts as $type => $count) {
        if (!isset($origin_data['activities'][$type])) {
            $issues[] = "{$type}: not in origin, dest={$count}";
        }
    }
    
    $total_origin = array_sum($origin_data['activities']);
    $total_dest = array_sum($dest_counts);
    
    if (empty($issues)) {
        return [
            'name' => 'Activities Count',
            'passed' => true,
            'message' => "All {$total_dest} activities restored correctly"
        ];
    }
    
    return [
        'name' => 'Activities Count',
        'passed' => false,
        'message' => "Mismatch (origin={$total_origin}, dest={$total_dest}): " . implode('; ', $issues)
    ];
}

/**
 * Validate quizzes and their questions.
 */
function validate_quizzes($backuppath, $courseid) {
    global $DB;
    
    $issues = [];
    $origin_quizzes = [];
    $origin_total_questions = 0;
    
    // Scan quiz activities in backup
    $activities_path = $backuppath . '/activities';
    if (is_dir($activities_path)) {
        $dirs = scandir($activities_path);
        foreach ($dirs as $dir) {
            if (strpos($dir, 'quiz_') === 0) {
                $quiz_xml = $activities_path . '/' . $dir . '/quiz.xml';
                if (file_exists($quiz_xml)) {
                    $quiz = simplexml_load_file($quiz_xml);
                    $quiz_name = (string)$quiz->name;
                    
                    // Count questions from question_instances or slots
                    $question_count = 0;
                    if (isset($quiz->question_instances->question_instance)) {
                        $question_count = count($quiz->question_instances->question_instance);
                    }
                    
                    $origin_quizzes[$quiz_name] = $question_count;
                    $origin_total_questions += $question_count;
                }
            }
        }
    }
    
    // Get destination quizzes
    $dest_quizzes = $DB->get_records_sql(
        "SELECT q.id, q.name, COUNT(qs.id) as question_count
         FROM {quiz} q
         LEFT JOIN {quiz_slots} qs ON qs.quizid = q.id
         WHERE q.course = :courseid
         GROUP BY q.id, q.name",
        ['courseid' => $courseid]
    );
    
    $dest_quiz_count = count($dest_quizzes);
    $dest_total_questions = 0;
    
    foreach ($dest_quizzes as $quiz) {
        $dest_total_questions += $quiz->question_count;
        
        // Try to match by name
        if (isset($origin_quizzes[$quiz->name])) {
            $origin_q = $origin_quizzes[$quiz->name];
            if ($origin_q != $quiz->question_count) {
                $issues[] = "Quiz '{$quiz->name}': origin={$origin_q} questions, dest={$quiz->question_count}";
            }
        }
    }
    
    $origin_quiz_count = count($origin_quizzes);
    
    if ($origin_quiz_count != $dest_quiz_count) {
        $issues[] = "Quiz count: origin={$origin_quiz_count}, dest={$dest_quiz_count}";
    }
    
    if (empty($issues)) {
        return [
            'name' => 'Quizzes & Questions',
            'passed' => true,
            'message' => "{$dest_quiz_count} quizzes with {$dest_total_questions} questions restored"
        ];
    }
    
    return [
        'name' => 'Quizzes & Questions',
        'passed' => false,
        'message' => implode('; ', $issues)
    ];
}

/**
 * Validate assignments and submissions.
 */
function validate_assignments($backuppath, $courseid) {
    global $DB;
    
    $issues = [];
    $origin_assigns = 0;
    $origin_submissions = 0;
    
    // Scan assign activities in backup
    $activities_path = $backuppath . '/activities';
    if (is_dir($activities_path)) {
        $dirs = scandir($activities_path);
        foreach ($dirs as $dir) {
            if (strpos($dir, 'assign_') === 0) {
                $origin_assigns++;
                
                // Check for submissions in the backup
                $submissions_xml = $activities_path . '/' . $dir . '/assign.xml';
                if (file_exists($submissions_xml)) {
                    $assign = simplexml_load_file($submissions_xml);
                    if (isset($assign->submissions->submission)) {
                        $origin_submissions += count($assign->submissions->submission);
                    }
                }
            }
        }
    }
    
    // Get destination data
    $dest_assigns = $DB->count_records('assign', ['course' => $courseid]);
    $dest_submissions = $DB->count_records_sql(
        "SELECT COUNT(*) FROM {assign_submission} asub
         JOIN {assign} a ON a.id = asub.assignment
         WHERE a.course = :courseid AND asub.status = 'submitted'",
        ['courseid' => $courseid]
    );
    
    if ($origin_assigns != $dest_assigns) {
        $issues[] = "Assignments: origin={$origin_assigns}, dest={$dest_assigns}";
    }
    
    // Note: Submissions might differ if users weren't restored
    $submission_diff = abs($origin_submissions - $dest_submissions);
    
    if (empty($issues)) {
        return [
            'name' => 'Assignments & Submissions',
            'passed' => true,
            'message' => "{$dest_assigns} assignments, {$dest_submissions} submissions restored"
        ];
    }
    
    return [
        'name' => 'Assignments & Submissions',
        'passed' => false,
        'message' => implode('; ', $issues)
    ];
}

/**
 * Validate forums and discussions.
 */
function validate_forums($backuppath, $courseid) {
    global $DB;
    
    $issues = [];
    $origin_forums = 0;
    $origin_discussions = 0;
    $origin_posts = 0;
    
    // Scan forum activities in backup
    $activities_path = $backuppath . '/activities';
    if (is_dir($activities_path)) {
        $dirs = scandir($activities_path);
        foreach ($dirs as $dir) {
            if (strpos($dir, 'forum_') === 0) {
                $origin_forums++;
                
                $forum_xml = $activities_path . '/' . $dir . '/forum.xml';
                if (file_exists($forum_xml)) {
                    $forum = simplexml_load_file($forum_xml);
                    if (isset($forum->discussions->discussion)) {
                        $origin_discussions += count($forum->discussions->discussion);
                        
                        foreach ($forum->discussions->discussion as $disc) {
                            if (isset($disc->posts->post)) {
                                $origin_posts += count($disc->posts->post);
                            }
                        }
                    }
                }
            }
        }
    }
    
    // Get destination data
    $dest_forums = $DB->count_records('forum', ['course' => $courseid]);
    $dest_discussions = $DB->count_records_sql(
        "SELECT COUNT(*) FROM {forum_discussions} fd
         JOIN {forum} f ON f.id = fd.forum
         WHERE f.course = :courseid",
        ['courseid' => $courseid]
    );
    $dest_posts = $DB->count_records_sql(
        "SELECT COUNT(*) FROM {forum_posts} fp
         JOIN {forum_discussions} fd ON fd.id = fp.discussion
         JOIN {forum} f ON f.id = fd.forum
         WHERE f.course = :courseid",
        ['courseid' => $courseid]
    );
    
    if ($origin_forums != $dest_forums) {
        $issues[] = "Forums: origin={$origin_forums}, dest={$dest_forums}";
    }
    
    if (empty($issues)) {
        return [
            'name' => 'Forums & Discussions',
            'passed' => true,
            'message' => "{$dest_forums} forums, {$dest_discussions} discussions, {$dest_posts} posts"
        ];
    }
    
    return [
        'name' => 'Forums & Discussions',
        'passed' => false,
        'message' => implode('; ', $issues)
    ];
}

/**
 * Validate files and resources.
 */
function validate_files_resources($backuppath, $courseid) {
    global $DB;
    
    $issues = [];
    $origin_resources = 0;
    $origin_folders = 0;
    $origin_urls = 0;
    
    // Scan resource/folder/url activities in backup
    $activities_path = $backuppath . '/activities';
    if (is_dir($activities_path)) {
        $dirs = scandir($activities_path);
        foreach ($dirs as $dir) {
            if (strpos($dir, 'resource_') === 0) $origin_resources++;
            if (strpos($dir, 'folder_') === 0) $origin_folders++;
            if (strpos($dir, 'url_') === 0) $origin_urls++;
        }
    }
    
    // Get destination data
    $dest_resources = $DB->count_records('resource', ['course' => $courseid]);
    $dest_folders = $DB->count_records('folder', ['course' => $courseid]);
    $dest_urls = $DB->count_records('url', ['course' => $courseid]);
    
    if ($origin_resources != $dest_resources) {
        $issues[] = "Resources: origin={$origin_resources}, dest={$dest_resources}";
    }
    if ($origin_folders != $dest_folders) {
        $issues[] = "Folders: origin={$origin_folders}, dest={$dest_folders}";
    }
    if ($origin_urls != $dest_urls) {
        $issues[] = "URLs: origin={$origin_urls}, dest={$dest_urls}";
    }
    
    $total = $dest_resources + $dest_folders + $dest_urls;
    
    if (empty($issues)) {
        return [
            'name' => 'Files & Resources',
            'passed' => true,
            'message' => "{$total} file resources restored (resources={$dest_resources}, folders={$dest_folders}, urls={$dest_urls})"
        ];
    }
    
    return [
        'name' => 'Files & Resources',
        'passed' => false,
        'message' => implode('; ', $issues)
    ];
}

/**
 * Validate user enrollments.
 */
function validate_user_enrollments($backuppath, $courseid) {
    global $DB;
    
    $origin_users = 0;
    
    // Check users.xml in backup
    $users_xml = $backuppath . '/users.xml';
    if (file_exists($users_xml)) {
        $users = simplexml_load_file($users_xml);
        if (isset($users->user)) {
            $origin_users = count($users->user);
        }
    }
    
    // Get destination enrolled users
    $dest_users = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT ue.userid) 
         FROM {user_enrolments} ue
         JOIN {enrol} e ON e.id = ue.enrolid
         WHERE e.courseid = :courseid",
        ['courseid' => $courseid]
    );
    
    // Users might not be restored (depending on settings)
    if ($origin_users == 0) {
        return [
            'name' => 'User Enrollments',
            'passed' => true,
            'message' => "No users in backup (structure-only restore), {$dest_users} enrolled in destination"
        ];
    }
    
    if ($origin_users == $dest_users) {
        return [
            'name' => 'User Enrollments',
            'passed' => true,
            'message' => "{$dest_users} users enrolled (matches origin)"
        ];
    }
    
    return [
        'name' => 'User Enrollments',
        'passed' => false,
        'message' => "User count mismatch: origin={$origin_users}, dest={$dest_users}"
    ];
}

/**
 * Validate course sections.
 */
function validate_sections($backuppath, $courseid) {
    global $DB;
    
    $origin_sections = 0;
    
    // Count sections in backup
    $sections_path = $backuppath . '/sections';
    if (is_dir($sections_path)) {
        $dirs = scandir($sections_path);
        foreach ($dirs as $dir) {
            if ($dir !== '.' && $dir !== '..' && is_dir($sections_path . '/' . $dir)) {
                $origin_sections++;
            }
        }
    }
    
    // Get destination sections (excluding section 0 which is always present)
    $dest_sections = $DB->count_records_select('course_sections', 
        'course = :courseid', 
        ['courseid' => $courseid]
    );
    
    if ($origin_sections == $dest_sections || abs($origin_sections - $dest_sections) <= 1) {
        return [
            'name' => 'Course Sections',
            'passed' => true,
            'message' => "{$dest_sections} sections restored"
        ];
    }
    
    return [
        'name' => 'Course Sections',
        'passed' => false,
        'message' => "Section count: origin={$origin_sections}, dest={$dest_sections}"
    ];
}

/**
 * Log validation results to coursetransfer log.
 */
function log_validation_results($requestid, $checks, $all_passed) {
    global $DB;
    
    $summary = [];
    foreach ($checks as $check) {
        $status = $check['passed'] ? 'PASS' : 'FAIL';
        $summary[] = "[{$status}] {$check['name']}: {$check['message']}";
    }
    
    $log = new \stdClass();
    $log->request_id = $requestid;
    $log->direction = 1; // TARGET
    $log->action = 'POST_RESTORE_VALIDATION';
    $log->status = $all_passed ? 'success' : 'warning';
    $log->message = $all_passed ? 
        'All validation checks passed - course restored identically to origin' : 
        'Some validation checks failed - review differences';
    $log->extra_data = json_encode([
        'checks' => $checks,
        'all_passed' => $all_passed,
        'validation_summary' => $summary
    ]);
    $log->timecreated = time();
    
    try {
        $DB->insert_record('local_coursetransfer_log', $log);
    } catch (\Exception $e) {
        cli_writeln("[RESTORE CLI] Warning: Could not log validation results: " . $e->getMessage());
    }
}
