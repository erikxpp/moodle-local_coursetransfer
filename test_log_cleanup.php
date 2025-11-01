<?php
// This file is part of Moodle - http://moodle.org/
//
// Test script for log cleanup functionality

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir.'/clilib.php');

use local_coursetransfer\coursetransfer_logger;

// Get parameters
list($options, $unrecognized) = cli_get_params(
    array(
        'help' => false,
        'create' => false,
        'cleanup' => false,
        'days' => 100,
        'count' => 10
    ),
    array('h' => 'help')
);

if ($options['help']) {
    echo "Test script for log cleanup functionality\n\n";
    echo "Options:\n";
    echo "  --create          Create test log entries with old timestamps\n";
    echo "  --cleanup         Run cleanup task manually\n";
    echo "  --days=N          Number of days in the past for test logs (default: 100)\n";
    echo "  --count=N         Number of test logs to create (default: 10)\n";
    echo "  -h, --help        Print this help\n\n";
    echo "Examples:\n";
    echo "  php test_log_cleanup.php --create --days=100 --count=10\n";
    echo "  php test_log_cleanup.php --cleanup\n";
    exit(0);
}

if ($options['create']) {
    cli_heading('Creating test log entries');
    
    // Get a valid request_id from the database
    $request = $DB->get_record_sql("SELECT id FROM {local_coursetransfer_request} ORDER BY id DESC LIMIT 1");
    
    if (!$request) {
        echo "Error: No requests found in database. Cannot create test logs.\n";
        exit(1);
    }
    
    $days_ago = (int)$options['days'];
    $count = (int)$options['count'];
    $old_timestamp = time() - ($days_ago * 86400);
    
    echo "Creating {$count} test log entries for request {$request->id}\n";
    echo "Timestamp: " . userdate($old_timestamp, '%Y-%m-%d %H:%M:%S') . " ({$days_ago} days ago)\n\n";
    
    for ($i = 1; $i <= $count; $i++) {
        $log = new stdClass();
        $log->request_id = $request->id;
        $log->direction = 0; // Origin
        $log->action = 'test_action_' . $i;
        $log->status = 'info';
        $log->message = "Test log entry #{$i} created for cleanup testing";
        $log->error_code = null;
        $log->task_id = null;
        $log->task_classname = null;
        $log->extra_data = json_encode(['test' => true, 'entry' => $i]);
        $log->timecreated = $old_timestamp - ($i * 60); // Each log 1 minute apart
        
        $log_id = $DB->insert_record('local_coursetransfer_log', $log);
        echo "  ✓ Created test log #{$i} (ID: {$log_id})\n";
    }
    
    echo "\nTest logs created successfully!\n";
    echo "You can now view them at: {$CFG->wwwroot}/local/coursetransfer/logs_detail.php?requestid={$request->id}\n";
    
    // Show current log count
    $total_logs = $DB->count_records('local_coursetransfer_log');
    echo "\nTotal logs in database: {$total_logs}\n";
}

if ($options['cleanup']) {
    cli_heading('Running cleanup task');
    
    // Show stats before cleanup
    $retention_days = get_config('local_coursetransfer', 'log_retention_days') ?: 90;
    $cutoff_time = time() - ($retention_days * 86400);
    
    $total_logs = $DB->count_records('local_coursetransfer_log');
    $old_logs = $DB->count_records_select('local_coursetransfer_log', 'timecreated < ?', [$cutoff_time]);
    
    echo "Current configuration:\n";
    echo "  - Log retention: {$retention_days} days\n";
    echo "  - Cutoff date: " . userdate($cutoff_time, '%Y-%m-%d %H:%M:%S') . "\n\n";
    
    echo "Before cleanup:\n";
    echo "  - Total logs: {$total_logs}\n";
    echo "  - Old logs (to be deleted): {$old_logs}\n\n";
    
    // Execute the cleanup task
    $task = new \local_coursetransfer\task\cleanup_old_backup_files_task();
    
    ob_start();
    $task->execute();
    $output = ob_get_clean();
    
    echo $output;
    
    // Show stats after cleanup
    $total_logs_after = $DB->count_records('local_coursetransfer_log');
    $deleted = $total_logs - $total_logs_after;
    
    echo "\nAfter cleanup:\n";
    echo "  - Total logs: {$total_logs_after}\n";
    echo "  - Deleted: {$deleted}\n";
}

if (!$options['create'] && !$options['cleanup']) {
    echo "No action specified. Use --help for usage information.\n";
    exit(1);
}

exit(0);
