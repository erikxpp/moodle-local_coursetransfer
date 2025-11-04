<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Update origin_course_shortname for existing records
 *
 * This script fills the origin_course_shortname field for existing transfer requests
 * that don't have this information yet.
 *
 * Usage: php update_shortnames.php
 *
 * @package    local_coursetransfer
 * @copyright  2025 Proyecto UNIMOODLE
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

// Ensure errors are well explained
$CFG->debug = (E_ALL | E_STRICT);
$CFG->debugdisplay = 1;

echo "=== Update origin_course_shortname for existing records ===\n\n";

// Get all records where origin_course_shortname is NULL or empty
$sql = "SELECT id, origin_course_id, origin_course_shortname
        FROM {local_coursetransfer_request}
        WHERE origin_course_id IS NOT NULL 
          AND (origin_course_shortname IS NULL OR origin_course_shortname = '')";

$records = $DB->get_records_sql($sql);

echo "Found " . count($records) . " records without shortname.\n\n";

if (count($records) == 0) {
    echo "✅ All records already have shortname information.\n";
    echo "Nothing to update.\n";
    exit(0);
}

$updated = 0;
$notfound = 0;
$errors = 0;

foreach ($records as $record) {
    try {
        // Try to get the course from the database
        $course = $DB->get_record('course', ['id' => $record->origin_course_id], 'id, shortname');
        
        if ($course) {
            // Update the record with the shortname
            $DB->set_field('local_coursetransfer_request', 'origin_course_shortname', 
                          $course->shortname, ['id' => $record->id]);
            $updated++;
            echo "✓ Record #{$record->id}: Updated with shortname '{$course->shortname}'\n";
        } else {
            $notfound++;
            echo "⚠ Record #{$record->id}: Course {$record->origin_course_id} not found in database\n";
        }
    } catch (Exception $e) {
        $errors++;
        echo "✗ Record #{$record->id}: Error - " . $e->getMessage() . "\n";
    }
}

echo "\n=== Summary ===\n";
echo "Total records processed: " . count($records) . "\n";
echo "✅ Successfully updated: $updated\n";
echo "⚠️  Course not found: $notfound\n";
echo "❌ Errors: $errors\n";

if ($updated > 0) {
    echo "\n✅ Done! Please refresh the logs page and purge caches.\n";
    echo "   Admin → Development → Purge all caches\n";
} else {
    echo "\n⚠️  No records were updated.\n";
}

exit(0);
