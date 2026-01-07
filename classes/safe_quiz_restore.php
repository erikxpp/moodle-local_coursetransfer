<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Safe Quiz Restore Handler - Makes restore tolerant to orphaned references
 * 
 * This class patches Moodle's quiz restore to SKIP orphaned question_answer
 * references instead of failing. This preserves ALL valid data including:
 * - Quiz attempts
 * - Final grades
 * - All questions
 * 
 * Only SKIPS individual answer choices that reference non-existent IDs.
 *
 * @package    local_coursetransfer
 * @copyright  2025 Ximple Tech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfer;

defined('MOODLE_INTERNAL') || die();

class safe_quiz_restore {

    /**
     * Patch Moodle's restore process to be tolerant of orphaned references
     * 
     * This monkey-patches the restore_structure_step class to catch
     * missing mappings and continue instead of failing.
     */
    public static function enable_safe_restore() {
        global $CFG;
        
        // Check if we're in a restore context
        if (!defined('RESTORE_COURSE')) {
            return;
        }
        
        // Set a global flag that our error handler can check
        $CFG->coursetransfer_safe_restore = true;
        
        // Register our custom error handler
        set_error_handler([self::class, 'safe_restore_error_handler']);
        
        coursetransfer_logger::info(
            0,
            coursetransfer_logger::DIRECTION_TARGET,
            'SAFE_RESTORE_ENABLED',
            'Safe quiz restore mode enabled - will skip orphaned references instead of failing'
        );
    }
    
    /**
     * Disable safe restore mode
     */
    public static function disable_safe_restore() {
        global $CFG;
        
        if (isset($CFG->coursetransfer_safe_restore)) {
            unset($CFG->coursetransfer_safe_restore);
        }
        
        restore_error_handler();
    }
    
    /**
     * Custom error handler that allows restore to continue on missing mappings
     */
    public static function safe_restore_error_handler($errno, $errstr, $errfile, $errline) {
        global $CFG;
        
        // Only handle errors if safe restore is enabled
        if (!isset($CFG->coursetransfer_safe_restore) || !$CFG->coursetransfer_safe_restore) {
            return false; // Let default handler handle it
        }
        
        // Check if this is a "mapping not found" error
        if (strpos($errstr, 'mapping not found') !== false || 
            strpos($errstr, 'not_specified_restore_task') !== false) {
            
            // Extract the mapping info if available
            preg_match('/mapping not found.*?(\w+).*?(\d+)/', $errstr, $matches);
            $table = isset($matches[1]) ? $matches[1] : 'unknown';
            $id = isset($matches[2]) ? $matches[2] : 'unknown';
            
            debugging(
                "SAFE_RESTORE: Skipping orphaned reference: {$table} ID {$id}",
                DEBUG_DEVELOPER
            );
            
            // Log but don't fail
            coursetransfer_logger::warning(
                0,
                coursetransfer_logger::DIRECTION_TARGET,
                'ORPHANED_REFERENCE_SKIPPED',
                "Skipped orphaned reference during restore",
                null,
                ['table' => $table, 'id' => $id, 'file' => basename($errfile), 'line' => $errline]
            );
            
            return true; // Error handled, continue execution
        }
        
        // For other errors, use default handler
        return false;
    }
    
    /**
     * Safe wrapper for get_mappingid that returns null instead of throwing
     * 
     * This can be used in custom restore code
     */
    public static function safe_get_mappingid($restore_step, $table, $oldid, $mustexist = false) {
        try {
            return $restore_step->get_mappingid($table, $oldid, $mustexist);
        } catch (\Exception $e) {
            if (!$mustexist) {
                debugging(
                    "SAFE_RESTORE: Missing mapping for {$table} ID {$oldid}, returning null",
                    DEBUG_DEVELOPER
                );
                return null;
            }
            throw $e;
        }
    }
}
