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
 * Detailed logs view for a course transfer request
 *
 * @package    local_coursetransfer
 * @copyright  2025 Proyecto UNIMOODLE
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_coursetransfer\coursetransfer;
use local_coursetransfer\coursetransfer_logger;
use local_coursetransfer\coursetransfer_request;

/**
 * Render logs timeline
 *
 * @param array $logs
 * @param string $direction
 * @return string HTML
 */
function render_logs_timeline($logs, $direction) {
    $html = html_writer::start_tag('div', ['class' => 'timeline']);
    
    foreach ($logs as $log) {
        $statusclass = 'timeline-item-' . $log->status;
        $icon = '';
        $iconclass = '';
        
        switch ($log->status) {
            case 'success':
                $icon = '✓';
                $iconclass = 'bg-success';
                break;
            case 'error':
                $icon = '✗';
                $iconclass = 'bg-danger';
                break;
            case 'warning':
                $icon = '⚠';
                $iconclass = 'bg-warning';
                break;
            default:
                $icon = 'ℹ';
                $iconclass = 'bg-info';
        }
        
        $html .= html_writer::start_tag('div', ['class' => 'timeline-item ' . $statusclass]);
        
        $html .= html_writer::start_tag('div', ['class' => 'timeline-marker ' . $iconclass]);
        $html .= html_writer::tag('span', $icon, ['class' => 'timeline-icon']);
        $html .= html_writer::end_tag('div');
        
        $html .= html_writer::start_tag('div', ['class' => 'timeline-content card']);
        $html .= html_writer::start_tag('div', ['class' => 'card-body']);
        
        $html .= html_writer::tag('h5', 
            format_log_action($log->action),
            ['class' => 'card-title']
        );
        
        $html .= html_writer::tag('p', 
            html_writer::tag('small', 
                '🕐 ' . userdate($log->timecreated, get_string('strftimedatetime', 'core_langconfig')),
                ['class' => 'text-muted']
            ),
            ['class' => 'card-subtitle mb-2']
        );
        
        if (!empty($log->message)) {
            $html .= html_writer::tag('p', $log->message, ['class' => 'card-text']);
        }
        
        if (!empty($log->error_code)) {
            $html .= html_writer::tag('div', 
                html_writer::tag('strong', 'Error Code: ') . $log->error_code,
                ['class' => 'alert alert-danger']
            );
        }
        
        if (!empty($log->task_id)) {
            // Display task ID and classname (link to adhoc tasks page)
            $taskinfo = html_writer::div(
                html_writer::tag('strong', '📋 Adhoc Task ID: ') . $log->task_id,
                'mt-2'
            );
            if (!empty($log->task_classname)) {
                $classparts = explode('\\', $log->task_classname);
                $shortname = end($classparts);
                $taskinfo .= html_writer::div(
                    html_writer::tag('small', 'Class: ' . html_writer::tag('code', $shortname)),
                    'text-muted'
                );
            }
            $html .= $taskinfo;
        }
        
        if (!empty($log->extra_data)) {
            $extradata = json_decode($log->extra_data, true);
            if ($extradata && is_array($extradata)) {
                $html .= html_writer::start_tag('div', ['class' => 'extra-data mt-2']);
                $html .= html_writer::tag('small', 
                    html_writer::tag('strong', 'ℹ️ Additional Information:'), 
                    ['class' => 'text-muted']
                );
                $html .= html_writer::start_tag('ul', ['class' => 'small mb-0 mt-1']);
                foreach ($extradata as $key => $value) {
                    if (is_numeric($value) && in_array($key, ['file_size', 'filesize'])) {
                        $value = display_size($value);
                    }
                    // Convert arrays/objects to JSON string for display.
                    if (is_array($value) || is_object($value)) {
                        $value = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    }
                    $html .= html_writer::tag('li', 
                        html_writer::tag('code', $key) . ': ' . htmlspecialchars((string)$value)
                    );
                }
                $html .= html_writer::end_tag('ul');
                $html .= html_writer::end_tag('div');
            }
        }
        
        $html .= html_writer::end_tag('div'); // card-body
        $html .= html_writer::end_tag('div'); // card
        $html .= html_writer::end_tag('div'); // timeline-item
    }
    
    $html .= html_writer::end_tag('div'); // timeline
    
    return $html;
}

/**
 * Format log action name
 *
 * @param string $action
 * @return string
 */
function format_log_action($action) {
    $string_key = 'log_action_' . $action;
    if (get_string_manager()->string_exists($string_key, 'local_coursetransfer')) {
        return get_string($string_key, 'local_coursetransfer');
    }
    // Fallback: format action name
    return ucwords(str_replace('_', ' ', $action));
}

$requestid = required_param('requestid', PARAM_INT);

require_login();
require_capability('local/coursetransfer:origin_restore_course', context_system::instance());

$PAGE->set_url(new moodle_url('/local/coursetransfer/logs_detail.php', ['requestid' => $requestid]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('request_logs_detail', 'local_coursetransfer'));
$PAGE->set_heading(get_string('request_logs_detail', 'local_coursetransfer'));
$PAGE->set_pagelayout('admin');

// Get request details
$request = coursetransfer_request::get($requestid);
if (!$request) {
    throw new moodle_exception('requestnotfound', 'local_coursetransfer');
}

// Get logs
$logs = coursetransfer_logger::get_logs($requestid);

// Get adhoc tasks related to this request
global $DB;
$adhoctasks = $DB->get_records_sql("
    SELECT t.* 
    FROM {task_adhoc} t
    WHERE t.component = 'local_coursetransfer'
      AND (t.customdata LIKE :requestid1 OR t.customdata LIKE :requestid2)
    ORDER BY t.id DESC
", [
    'requestid1' => '%"requestid":' . $requestid . '%',
    'requestid2' => '%"requestoriginid":' . $requestid . '%'
]);

// Check for stuck tasks (in progress but no active adhoc tasks)
$stuck = false;
$stuck_message = '';
if (in_array($request->status, [
    coursetransfer_request::STATUS_IN_PROGRESS,
    coursetransfer_request::STATUS_BACKUP,
    coursetransfer_request::STATUS_DOWNLOADED
])) {
    // Check if there are any pending/running tasks
    $hasPendingTasks = false;
    foreach ($adhoctasks as $task) {
        if (empty($task->timestarted) || (!empty($task->timestarted) && empty($task->faildelay))) {
            $hasPendingTasks = true;
            break;
        }
    }
    
    if (!$hasPendingTasks) {
        $stuck = true;
        $stuck_message = get_string('request_stuck', 'local_coursetransfer');
    }
}

echo $OUTPUT->header();

// Add inline CSS for timeline
echo '<style>
.timeline {
    position: relative;
    padding-left: 50px;
    margin-top: 20px;
}

.timeline::before {
    content: "";
    position: absolute;
    left: 20px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #dee2e6;
}

.timeline-item {
    position: relative;
    margin-bottom: 30px;
}

.timeline-marker {
    position: absolute;
    left: -40px;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: bold;
    font-size: 1.2em;
    box-shadow: 0 0 0 4px #fff;
    z-index: 1;
}

.timeline-content {
    margin-left: 20px;
}

.extra-data {
    background-color: #f8f9fa;
    padding: 10px;
    border-radius: 4px;
    border-left: 3px solid #007bff;
}

.request-summary .table td:first-child {
    width: 30%;
    background-color: #f8f9fa;
}

.adhoc-tasks-table code {
    background-color: #e9ecef;
    padding: 2px 6px;
    border-radius: 3px;
}
</style>';

// Display request summary
echo html_writer::start_tag('div', ['class' => 'request-summary card mb-4']);
echo html_writer::start_tag('div', ['class' => 'card-body']);
echo html_writer::tag('h3', get_string('request_details', 'local_coursetransfer'), ['class' => 'card-title']);

$statusclass = 'badge-secondary';
switch ($request->status) {
    case coursetransfer_request::STATUS_COMPLETED:
        $statusclass = 'badge-success';
        break;
    case coursetransfer_request::STATUS_ERROR:
        $statusclass = 'badge-danger';
        break;
    case coursetransfer_request::STATUS_IN_PROGRESS:
    case coursetransfer_request::STATUS_BACKUP:
    case coursetransfer_request::STATUS_DOWNLOADED:
        $statusclass = 'badge-info';
        break;
}

// Get status string
$statuskey = isset(coursetransfer::STATUS[$request->status]) 
    ? coursetransfer::STATUS[$request->status]['shortname'] 
    : 'error';
$statusstring = get_string('status_' . $statuskey, 'local_coursetransfer');

$table = new html_table();
$table->attributes['class'] = 'table table-bordered request-details-table';
$table->data = [
    [
        html_writer::tag('strong', get_string('request_id', 'local_coursetransfer')), 
        $request->id
    ],
    [
        html_writer::tag('strong', get_string('direction', 'local_coursetransfer')), 
        $request->direction == 0 ? get_string('request', 'local_coursetransfer') : get_string('response', 'local_coursetransfer')
    ],
    [
        html_writer::tag('strong', get_string('status', 'local_coursetransfer')), 
        html_writer::tag('span', $statusstring, ['class' => 'badge ' . $statusclass])
    ],
    [
        html_writer::tag('strong', get_string('course', 'local_coursetransfer')), 
        $request->origin_course_fullname . ' (' . $request->origin_course_shortname . ')'
    ],
    [
        html_writer::tag('strong', get_string('origin_course_id', 'local_coursetransfer')), 
        $request->origin_course_id
    ],
    [
        html_writer::tag('strong', get_string('target_course_id', 'local_coursetransfer')), 
        $request->target_course_id ?? '-'
    ],
    [
        html_writer::tag('strong', get_string('site_url', 'local_coursetransfer')), 
        html_writer::link($request->siteurl, $request->siteurl, ['target' => '_blank'])
    ],
    [
        html_writer::tag('strong', get_string('created', 'local_coursetransfer')), 
        userdate($request->timecreated, get_string('strftimedatetime', 'core_langconfig'))
    ],
    [
        html_writer::tag('strong', get_string('modified', 'local_coursetransfer')), 
        userdate($request->timemodified, get_string('strftimedatetime', 'core_langconfig'))
    ],
];

if (!empty($request->error_code) || !empty($request->error_message)) {
    $table->data[] = [
        html_writer::tag('strong', get_string('error', 'local_coursetransfer'), ['class' => 'text-danger']),
        html_writer::tag('span', 
            ($request->error_code ? "[$request->error_code] " : '') . $request->error_message,
            ['class' => 'text-danger']
        )
    ];
}

if ($stuck) {
    $table->data[] = [
        html_writer::tag('strong', get_string('warning', 'local_coursetransfer'), ['class' => 'text-warning']),
        html_writer::tag('span', '⚠️ ' . $stuck_message, ['class' => 'text-warning font-weight-bold'])
    ];
}

echo html_writer::table($table);

// Display retry button if request failed and is a course request
if ($request->status === coursetransfer_request::STATUS_ERROR && 
    $request->type === coursetransfer_request::TYPE_COURSE) {
    
    echo html_writer::div(
        html_writer::tag('button',
            '<i class="fa fa-refresh"></i> ' . get_string('retry_request', 'local_coursetransfer'),
            [
                'class' => 'btn btn-warning btn-lg mt-3',
                'data-action' => 'retry-request',
                'data-requestid' => $requestid,
                'title' => get_string('retry_request_help', 'local_coursetransfer')
            ]
        ) .
        html_writer::tag('p',
            get_string('retry_request_description', 'local_coursetransfer'),
            ['class' => 'small text-muted mt-2']
        ),
        'text-center'
    );
    
    // Initialize retry JavaScript
    $PAGE->requires->js_call_amd('local_coursetransfer/retry_request', 'init', [$requestid]);
}

echo html_writer::end_tag('div'); // card-body
echo html_writer::end_tag('div'); // card

// Display adhoc tasks
if (!empty($adhoctasks)) {
    echo html_writer::start_tag('div', ['class' => 'adhoc-tasks card mb-4']);
    echo html_writer::start_tag('div', ['class' => 'card-body']);
    echo html_writer::tag('h3', get_string('adhoc_tasks', 'local_coursetransfer'), ['class' => 'card-title']);
    
    $tasktable = new html_table();
    $tasktable->attributes['class'] = 'table table-striped adhoc-tasks-table';
    $tasktable->head = [
        get_string('task_id', 'local_coursetransfer'),
        get_string('task_name', 'local_coursetransfer'),
        get_string('status', 'local_coursetransfer'),
        get_string('created', 'local_coursetransfer'),
        get_string('started', 'local_coursetransfer'),
        get_string('next_run', 'local_coursetransfer'),
        get_string('fail_delay', 'local_coursetransfer'),
        get_string('actions', 'local_coursetransfer'),
    ];
    
    foreach ($adhoctasks as $task) {
        $taskstatus = 'Pending';
        $statusclass = 'badge badge-warning';
        
        if (!empty($task->timestarted)) {
            if (!empty($task->faildelay)) {
                $taskstatus = 'Failed (' . $task->attemptsavailable . ' attempts left)';
                $statusclass = 'badge badge-danger';
            } else {
                $taskstatus = 'Running';
                $statusclass = 'badge badge-info';
            }
        }
        
        $classparts = explode('\\', $task->classname);
        $shortname = end($classparts);
        
        // Link to view the adhoc task details in scheduled tasks page
        $tasklink = html_writer::link(
            new moodle_url('/admin/tool/task/adhoctasks.php'),
            '� ' . get_string('view_task', 'local_coursetransfer'),
            ['target' => '_blank', 'class' => 'btn btn-sm btn-secondary', 'title' => 'View adhoc tasks']
        );
        
        $tasktable->data[] = [
            $task->id,
            html_writer::tag('code', $shortname, ['style' => 'font-size: 0.85em;']),
            html_writer::tag('span', $taskstatus, ['class' => $statusclass]),
            userdate($task->timecreated),
            !empty($task->timestarted) ? userdate($task->timestarted) : '-',
            userdate($task->nextruntime),
            !empty($task->faildelay) ? format_time($task->faildelay) : '-',
            $tasklink
        ];
    }
    
    echo html_writer::table($tasktable);
    echo html_writer::end_tag('div'); // card-body
    echo html_writer::end_tag('div'); // card
}

// Display logs timeline
echo html_writer::start_tag('div', ['class' => 'logs-timeline-section card']);
echo html_writer::start_tag('div', ['class' => 'card-body']);
echo html_writer::tag('h3', get_string('logs_timeline', 'local_coursetransfer'), ['class' => 'card-title']);

if (empty($logs)) {
    echo html_writer::tag('p', get_string('no_logs_found', 'local_coursetransfer'), ['class' => 'alert alert-info']);
} else {
    // Group logs by direction
    $logsByDirection = coursetransfer_logger::get_logs_by_direction($requestid);
    
    // Display tabs for origin/target
    echo html_writer::start_tag('ul', ['class' => 'nav nav-tabs mb-3', 'role' => 'tablist']);
    
    if (!empty($logsByDirection['origin'])) {
        echo html_writer::start_tag('li', ['class' => 'nav-item']);
        echo html_writer::link('#origin-logs', 
            '📤 ' . get_string('origin_logs', 'local_coursetransfer') . ' (' . count($logsByDirection['origin']) . ')', 
            [
                'class' => 'nav-link active',
                'data-toggle' => 'tab',
                'role' => 'tab'
            ]
        );
        echo html_writer::end_tag('li');
    }
    
    if (!empty($logsByDirection['target'])) {
        $activeClass = empty($logsByDirection['origin']) ? 'active' : '';
        echo html_writer::start_tag('li', ['class' => 'nav-item']);
        echo html_writer::link('#target-logs', 
            '📥 ' . get_string('target_logs', 'local_coursetransfer') . ' (' . count($logsByDirection['target']) . ')', 
            [
                'class' => 'nav-link ' . $activeClass,
                'data-toggle' => 'tab',
                'role' => 'tab'
            ]
        );
        echo html_writer::end_tag('li');
    }
    
    echo html_writer::end_tag('ul');
    
    // Tab content
    echo html_writer::start_tag('div', ['class' => 'tab-content']);
    
    // Origin logs
    if (!empty($logsByDirection['origin'])) {
        echo html_writer::start_tag('div', [
            'class' => 'tab-pane fade show active',
            'id' => 'origin-logs',
            'role' => 'tabpanel'
        ]);
        echo render_logs_timeline($logsByDirection['origin'], 'origin');
        echo html_writer::end_tag('div');
    }
    
    // Target logs
    if (!empty($logsByDirection['target'])) {
        $activeClass = empty($logsByDirection['origin']) ? 'show active' : '';
        echo html_writer::start_tag('div', [
            'class' => 'tab-pane fade ' . $activeClass,
            'id' => 'target-logs',
            'role' => 'tabpanel'
        ]);
        echo render_logs_timeline($logsByDirection['target'], 'target');
        echo html_writer::end_tag('div');
    }
    
    echo html_writer::end_tag('div');
}

echo html_writer::end_tag('div'); // card-body
echo html_writer::end_tag('div'); // card

// Back button
echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/coursetransfer/logs.php'),
        '← ' . get_string('back_to_logs', 'local_coursetransfer'),
        ['class' => 'btn btn-secondary']
    ),
    'mt-3'
);

echo $OUTPUT->footer();