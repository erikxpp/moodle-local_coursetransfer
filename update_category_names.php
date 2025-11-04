<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Update origin_category_name for existing records
 *
 * This script fills the origin_category_name field for existing transfer requests
 * that don't have this information yet.
 *
 * Usage: php update_category_names.php
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

echo "=== Update origin_category_name for existing records ===\n\n";

// Get all records where origin_category_name is NULL or empty
$sql = "SELECT id, origin_category_id, origin_category_name
        FROM {local_coursetransfer_request}
        WHERE origin_category_id IS NOT NULL 
          AND (origin_category_name IS NULL OR origin_category_name = '')";

$records = $DB->get_records_sql($sql);

echo "Found " . count($records) . " records without category name.\n\n";

if (count($records) == 0) {
    echo "✅ All records already have category name information.\n";
    echo "Nothing to update.\n";
    exit(0);
}

$updated = 0;
$notfound = 0;
$errors = 0;

foreach ($records as $record) {
    try {
        // Try to get the category from the database
        $category = $DB->get_record('course_categories', ['id' => $record->origin_category_id], 'id, name');
        
        if ($category) {
            // Update the record with the category name
            $DB->set_field('local_coursetransfer_request', 'origin_category_name', 
                          $category->name, ['id' => $record->id]);
            $updated++;
            echo "✓ Record #{$record->id}: Updated with category name '{$category->name}'\n";
        } else {
            $notfound++;
            echo "⚠ Record #{$record->id}: Category {$record->origin_category_id} not found in database\n";
        }
    } catch (Exception $e) {
        $errors++;
        echo "✗ Record #{$record->id}: Error - " . $e->getMessage() . "\n";
    }
}

echo "\n=== Summary ===\n";
echo "Total records processed: " . count($records) . "\n";
echo "✅ Successfully updated: $updated\n";
echo "⚠️  Category not found: $notfound\n";
echo "❌ Errors: $errors\n";

if ($updated > 0) {
    echo "\n✅ Done! Please refresh the logs page and purge caches.\n";
    echo "   Admin → Development → Purge all caches\n";
} else {
    echo "\n⚠️  No records were updated.\n";
}

exit(0);
