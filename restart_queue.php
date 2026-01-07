<?php
/**
 * Restart queue processing for a failed category restore.
 * 
 * Usage: php restart_queue.php <requestid>
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params(
    ['requestid' => false, 'help' => false],
    ['h' => 'help', 'r' => 'requestid']
);

if ($options['help'] || !$options['requestid']) {
    echo "Restart queue processing for a failed category restore.\n\n";
    echo "Usage: php restart_queue.php --requestid=<id>\n";
    echo "Options:\n";
    echo "  -r, --requestid   Request ID to restart\n";
    echo "  -h, --help        Print this help\n";
    exit(0);
}

$requestid = (int)$options['requestid'];

// 1. Check if request exists
$request = $DB->get_record('local_coursetransfer_request', ['id' => $requestid]);
if (!$request) {
    echo "ERROR: Request {$requestid} not found\n";
    exit(1);
}

echo "Current request status: {$request->status}\n";

// 2. Check queue status
$queue_stats = $DB->get_records_sql(
    "SELECT status, COUNT(*) as count FROM {local_coursetransfer_queue}
     WHERE requestid = :requestid GROUP BY status",
    ['requestid' => $requestid]
);

echo "Queue status:\n";
foreach ($queue_stats as $stat) {
    echo "  - {$stat->status}: {$stat->count}\n";
}

// 3. Check if there's already an adhoc task
$existing_task = $DB->count_records('task_adhoc', [
    'classname' => '\\local_coursetransfer\\task\\queue_processor_task'
]);
echo "Existing queue processor tasks: {$existing_task}\n\n";

// 4. Update request status to IN_PROGRESS
echo "Updating request status to IN_PROGRESS (10)...\n";
$DB->set_field('local_coursetransfer_request', 'status', 10, ['id' => $requestid]);

// 5. Clear origin_category_requests
echo "Clearing origin_category_requests field...\n";
$DB->set_field('local_coursetransfer_request', 'origin_category_requests', null, ['id' => $requestid]);

// 6. Create new adhoc task
echo "Creating new queue processor task...\n";
$task = new \local_coursetransfer\task\queue_processor_task();
$task->set_custom_data(['requestid' => $requestid]);
\core\task\manager::queue_adhoc_task($task);

echo "\n✓ Queue restarted successfully!\n";
echo "The cron will process it automatically.\n";
echo "To process immediately, run: php admin/cli/adhoc_task.php --execute\n";
