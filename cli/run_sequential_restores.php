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
 * CLI script to run restore tasks ONE BY ONE sequentially.
 * 
 * This script bypasses Moodle's cron concurrency settings by directly executing
 * restore_course_cli.php for each pending task, ensuring strict sequential processing.
 * 
 * Use this instead of the regular cron when you need to guarantee that only ONE
 * restore runs at a time, leaving system resources available for other tasks.
 *
 * Usage: 
 *   php run_sequential_restores.php                    # Process all pending restores
 *   php run_sequential_restores.php --limit=5         # Process only 5 tasks
 *   php run_sequential_restores.php --category=2439   # Process only tasks for category
 *   php run_sequential_restores.php --dry-run         # Show what would be processed
 *   php run_sequential_restores.php --wait-running    # Wait for running tasks to finish
 *
 * @package    local_coursetransfer
 * @copyright  2025 IPG Online
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

// Find config.php
$configpath = __DIR__ . '/../../../config.php';
if (!file_exists($configpath)) {
    $configpath = __DIR__ . '/../../../../config.php';
}
if (!file_exists($configpath)) {
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

// CLI options
list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
        'limit' => 0,
        'category' => null,
        'dry-run' => false,
        'verbose' => false,
        'pause' => 10,
        'wait-running' => false,
        'timeout' => 3600,
    ],
    [
        'h' => 'help',
        'l' => 'limit',
        'c' => 'category',
        'd' => 'dry-run',
        'v' => 'verbose',
        'p' => 'pause',
        'w' => 'wait-running',
        't' => 'timeout',
    ]
);

if ($options['help']) {
    $help = <<<EOF
Run coursetransfer restore tasks ONE BY ONE sequentially.

This script bypasses Moodle's task_adhoc_concurrency_limit and executes
restore tasks strictly one at a time using restore_course_cli.php.

Options:
 -h, --help           Show this help
 -l, --limit=N        Process only N tasks (0 = unlimited)
 -c, --category=ID    Process only tasks for origin category ID
 -d, --dry-run        Show what would be processed without executing
 -v, --verbose        Show detailed progress
 -p, --pause=N        Seconds to pause between tasks (default: 10)
 -w, --wait-running   Wait for currently running tasks to finish before starting
 -t, --timeout=N      Max seconds to wait for running tasks (default: 3600)

Examples:
 php run_sequential_restores.php                     # Process all pending
 php run_sequential_restores.php --limit=10          # Process max 10 tasks
 php run_sequential_restores.php --category=2439     # Only category 2439
 php run_sequential_restores.php --dry-run           # Preview only
 php run_sequential_restores.php --wait-running      # Wait for running to finish

EOF;
    echo $help;
    exit(0);
}

$limit = (int)$options['limit'];
$category = $options['category'] ? (int)$options['category'] : null;
$dryrun = $options['dry-run'];
$verbose = $options['verbose'];
$pause = max(5, (int)$options['pause']);
$waitrunning = $options['wait-running'];
$timeout = (int)$options['timeout'];

cli_writeln("");
cli_writeln("╔═══════════════════════════════════════════════════════════╗");
cli_writeln("║    COURSETRANSFER SEQUENTIAL RESTORE RUNNER               ║");
cli_writeln("╚═══════════════════════════════════════════════════════════╝");
cli_writeln("");
cli_writeln("Mode:     " . ($dryrun ? "DRY RUN (no changes)" : "LIVE EXECUTION"));
cli_writeln("Limit:    " . ($limit > 0 ? $limit . " tasks" : "Unlimited"));
if ($category) {
    cli_writeln("Category: " . $category);
}
cli_writeln("Pause:    {$pause} seconds between tasks");
cli_writeln("Time:     " . date('Y-m-d H:i:s'));
cli_writeln("");

// Step 1: Check for running tasks
cli_writeln("┌─────────────────────────────────────────────────────────────┐");
cli_writeln("│ [1/4] Checking for running restore tasks...                 │");
cli_writeln("└─────────────────────────────────────────────────────────────┘");

$running_sql = "SELECT id, customdata, timestarted,
                       TIMESTAMPDIFF(MINUTE, FROM_UNIXTIME(timestarted), NOW()) as minutes_running
                FROM {task_adhoc} 
                WHERE classname = :classname
                  AND timestarted IS NOT NULL
                ORDER BY timestarted";

$running_tasks = $DB->get_records_sql($running_sql, [
    'classname' => '\\local_coursetransfer\\task\\restore_course_task'
]);

if (!empty($running_tasks)) {
    cli_writeln("");
    cli_writeln("⚠️  " . count($running_tasks) . " restore task(s) currently running:");
    cli_writeln("");
    
    foreach ($running_tasks as $task) {
        $data = json_decode($task->customdata);
        $requestid = $data->requestid ?? 'unknown';
        cli_writeln("   • Task #{$task->id}: Request {$requestid}, running {$task->minutes_running} min");
    }
    cli_writeln("");
    
    if ($waitrunning && !$dryrun) {
        cli_writeln("--wait-running enabled. Waiting for tasks to complete...");
        cli_writeln("");
        
        $waited = 0;
        $check_interval = 30;
        
        while ($waited < $timeout) {
            $still_running = $DB->count_records_sql(
                "SELECT COUNT(*) FROM {task_adhoc} 
                 WHERE classname = :classname AND timestarted IS NOT NULL",
                ['classname' => '\\local_coursetransfer\\task\\restore_course_task']
            );
            
            if ($still_running == 0) {
                cli_writeln("✅ All running tasks completed. Continuing...");
                cli_writeln("");
                break;
            }
            
            cli_writeln("   Still running: {$still_running} task(s). Waiting... ({$waited}s/{$timeout}s)");
            sleep($check_interval);
            $waited += $check_interval;
        }
        
        if ($waited >= $timeout) {
            cli_writeln("❌ Timeout waiting for running tasks. Exiting.");
            exit(1);
        }
    } else if (!$dryrun) {
        cli_writeln("⚠️  Running tasks detected. They may cause concurrency issues.");
        cli_writeln("   Use --wait-running to wait for them to finish first.");
        cli_writeln("   Continuing in 5 seconds... (Ctrl+C to cancel)");
        sleep(5);
    }
}

cli_writeln("   No running tasks (or cleared). ✓");
cli_writeln("");

// Step 2: Get pending tasks
cli_writeln("┌─────────────────────────────────────────────────────────────┐");
cli_writeln("│ [2/4] Finding pending restore tasks...                      │");
cli_writeln("└─────────────────────────────────────────────────────────────┘");

// Build query to find pending tasks
$pending_sql = "SELECT ta.id as task_id, 
                       ta.customdata,
                       ta.nextruntime,
                       ta.faildelay
                FROM {task_adhoc} ta
                WHERE ta.classname = :classname
                  AND ta.timestarted IS NULL
                ORDER BY ta.nextruntime ASC";

$params = ['classname' => '\\local_coursetransfer\\task\\restore_course_task'];

if ($limit > 0) {
    $all_pending = $DB->get_records_sql($pending_sql, $params, 0, $limit * 2); // Get extra for filtering
} else {
    $all_pending = $DB->get_records_sql($pending_sql, $params);
}

// Filter by category if specified
$pending_tasks = [];
foreach ($all_pending as $task) {
    $data = json_decode($task->customdata);
    $requestid = $data->requestid ?? null;
    
    if (!$requestid) {
        continue;
    }
    
    // Get request details
    $request = $DB->get_record('local_coursetransfer_request', ['id' => $requestid]);
    if (!$request) {
        continue;
    }
    
    // Filter by category
    if ($category && $request->origin_category_id != $category) {
        continue;
    }
    
    $task->request = $request;
    $task->data = $data;
    $pending_tasks[] = $task;
    
    if ($limit > 0 && count($pending_tasks) >= $limit) {
        break;
    }
}

$total_pending = count($pending_tasks);

if ($total_pending === 0) {
    cli_writeln("");
    cli_writeln("✅ No pending restore tasks found" . ($category ? " for category {$category}" : "") . ".");
    cli_writeln("");
    exit(0);
}

cli_writeln("   Found {$total_pending} pending task(s)");
cli_writeln("");

// Step 3: Show queue
cli_writeln("┌─────────────────────────────────────────────────────────────┐");
cli_writeln("│ [3/4] Task queue preview                                    │");
cli_writeln("└─────────────────────────────────────────────────────────────┘");
cli_writeln("");
cli_writeln(sprintf("  %-7s %-7s %-8s %-42s", "Task", "Req#", "Cat", "Course Name"));
cli_writeln("  " . str_repeat("─", 68));

foreach ($pending_tasks as $task) {
    $course_name = $task->request->origin_course_fullname ?? 
                   $task->request->origin_course_shortname ?? 
                   "Course #{$task->request->origin_course_id}";
    $course_name = mb_substr($course_name, 0, 40);
    
    cli_writeln(sprintf("  %-7d %-7d %-8d %-42s", 
        $task->task_id,
        $task->data->requestid,
        $task->request->origin_category_id ?? 0,
        $course_name
    ));
}
cli_writeln("  " . str_repeat("─", 68));
cli_writeln("");

if ($dryrun) {
    cli_writeln("🔍 DRY RUN MODE - No tasks will be executed.");
    cli_writeln("   Remove --dry-run to process these tasks.");
    cli_writeln("");
    exit(0);
}

// Step 4: Process tasks
cli_writeln("┌─────────────────────────────────────────────────────────────┐");
cli_writeln("│ [4/4] Processing tasks ONE BY ONE...                        │");
cli_writeln("└─────────────────────────────────────────────────────────────┘");
cli_writeln("");

$processed = 0;
$successful = 0;
$failed = 0;
$start_time = time();
$cli_script = __DIR__ . '/restore_course_cli.php';

if (!file_exists($cli_script)) {
    cli_writeln("❌ ERROR: restore_course_cli.php not found at:");
    cli_writeln("   {$cli_script}");
    exit(1);
}

foreach ($pending_tasks as $task) {
    $processed++;
    $requestid = $task->data->requestid;
    $fileid = $task->data->fileid ?? null;
    $course_name = $task->request->origin_course_fullname ?? "Course #{$task->request->origin_course_id}";
    
    cli_writeln("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
    cli_writeln("Processing [{$processed}/{$total_pending}]");
    cli_writeln("  Request:  #{$requestid}");
    cli_writeln("  Task:     #{$task->task_id}");
    cli_writeln("  Course:   {$course_name}");
    cli_writeln("  File ID:  " . ($fileid ?? 'N/A'));
    cli_writeln("  Started:  " . date('Y-m-d H:i:s'));
    cli_writeln("");
    
    // Build command
    $cmd = PHP_BINARY . " " . escapeshellarg($cli_script) . " --requestid=" . escapeshellarg($requestid);
    if ($fileid) {
        $cmd .= " --fileid=" . escapeshellarg($fileid);
    }
    
    if ($verbose) {
        cli_writeln("  Command: {$cmd}");
        cli_writeln("");
    }
    
    // Execute
    $output = [];
    $return_code = 0;
    $start_task = time();
    
    cli_writeln("  Executing restore...");
    
    // Use proc_open for real-time output
    $descriptorspec = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ];
    
    $process = proc_open($cmd, $descriptorspec, $pipes);
    
    if (is_resource($process)) {
        fclose($pipes[0]);
        
        // Read output in real-time
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        
        $stdout = '';
        $stderr = '';
        
        while (true) {
            $status = proc_get_status($process);
            
            $out = fread($pipes[1], 8192);
            $err = fread($pipes[2], 8192);
            
            if ($out) {
                $stdout .= $out;
                if ($verbose) {
                    foreach (explode("\n", trim($out)) as $line) {
                        if ($line) cli_writeln("    " . $line);
                    }
                }
            }
            if ($err) {
                $stderr .= $err;
            }
            
            if (!$status['running']) {
                // Read any remaining output
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                break;
            }
            
            usleep(100000); // 100ms
        }
        
        fclose($pipes[1]);
        fclose($pipes[2]);
        $return_code = proc_close($process);
        $output = explode("\n", $stdout);
    } else {
        $return_code = 1;
        $output = ["Failed to start process"];
    }
    
    $duration = time() - $start_task;
    $minutes = floor($duration / 60);
    $seconds = $duration % 60;
    
    cli_writeln("");
    
    // Check result
    if ($return_code === 0) {
        cli_writeln("  ✅ SUCCESS (duration: {$minutes}m {$seconds}s)");
        $successful++;
        
        // Remove task from queue
        try {
            $DB->delete_records('task_adhoc', ['id' => $task->task_id]);
            cli_writeln("  Task #{$task->task_id} removed from adhoc queue.");
        } catch (\Exception $e) {
            cli_writeln("  Note: Could not remove task: " . $e->getMessage());
        }
    } else {
        cli_writeln("  ❌ FAILED (exit code: {$return_code}, duration: {$minutes}m {$seconds}s)");
        $failed++;
        
        // Show last lines of output
        if (!$verbose && !empty($output)) {
            cli_writeln("  Last output:");
            $last_lines = array_slice(array_filter($output), -5);
            foreach ($last_lines as $line) {
                cli_writeln("    " . $line);
            }
        }
        
        // Mark task for retry
        try {
            $task_record = $DB->get_record('task_adhoc', ['id' => $task->task_id]);
            if ($task_record) {
                $task_record->faildelay = ($task_record->faildelay ?: 60) * 2;
                $task_record->nextruntime = time() + $task_record->faildelay;
                $task_record->timestarted = null;
                $DB->update_record('task_adhoc', $task_record);
                cli_writeln("  Task will retry in " . ($task_record->faildelay / 60) . " minutes.");
            }
        } catch (\Exception $e) {
            // Ignore
        }
    }
    
    cli_writeln("");
    
    // Pause before next task
    if ($processed < $total_pending) {
        cli_writeln("  Pausing {$pause} seconds before next task...");
        sleep($pause);
        cli_writeln("");
    }
}

// Summary
$total_duration = time() - $start_time;
$total_minutes = floor($total_duration / 60);
$total_seconds = $total_duration % 60;

cli_writeln("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
cli_writeln("");
cli_writeln("╔═══════════════════════════════════════════════════════════╗");
cli_writeln("║                    PROCESSING COMPLETE                    ║");
cli_writeln("╚═══════════════════════════════════════════════════════════╝");
cli_writeln("");
cli_writeln("  Total processed:  {$processed}");
cli_writeln("  Successful:       {$successful}");
cli_writeln("  Failed:           {$failed}");
cli_writeln("  Total time:       {$total_minutes}m {$total_seconds}s");
cli_writeln("  Finished at:      " . date('Y-m-d H:i:s'));
cli_writeln("");

if ($failed > 0) {
    cli_writeln("⚠️  {$failed} task(s) failed. Check output above for details.");
    cli_writeln("   Failed tasks will be retried by regular cron or run this script again.");
}

cli_writeln("");
exit($failed > 0 ? 1 : 0);
