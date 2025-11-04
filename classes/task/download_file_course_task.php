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

// Project implemented by the "Recovery, Transformation and Resilience Plan.
// Funded by the European Union - Next GenerationEU".
//
// Produced by the UNIMOODLE University Group: Universities of
// Valladolid, Complutense de Madrid, UPV/EHU, León, Salamanca,
// Illes Balears, Valencia, Rey Juan Carlos, La Laguna, Zaragoza, Málaga,
// Córdoba, Extremadura, Vigo, Las Palmas de Gran Canaria y Burgos.

/**
 * logs_course_response_table
 *
 * @package    local_coursetransfer
 * @copyright  2023 Proyecto UNIMOODLE
 * @author     UNIMOODLE Group (Coordinator) <direccion.area.estrategia.digital@uva.es>
 * @author     3IPUNT <contacte@tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfer\task;

use context_course;
use dml_exception;
use local_coursetransfer\coursetransfer;
use local_coursetransfer\coursetransfer_logger;
use local_coursetransfer\coursetransfer_request;
use local_coursetransfer\coursetransfer_restore;
use moodle_exception;

/**
 * logs_course_response_table
 *
 * @package    local_coursetransfer
 * @copyright  2023 Proyecto UNIMOODLE
 * @author     UNIMOODLE Group (Coordinator) <direccion.area.estrategia.digital@uva.es>
 * @author     3IPUNT <contacte@tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class download_file_course_task extends \core\task\adhoc_task {

    // Use the logging trait to get some nice, juicy, logging.
    use \core\task\logging_trait;

    /**
     * Maximum number of retry attempts before marking as failed
     */
    const MAX_RETRY_ATTEMPTS = 5;

    /**
     * Delay in seconds between retry attempts (exponential backoff)
     */
    const BASE_RETRY_DELAY = 60; // 1 minute base delay

    /**
     * Execute.
     *
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function execute() {
        global $CFG, $DB;
        
        $this->log_start("Download File Backup Course Remote and Restore Starting...");
        $fileurl = $this->get_custom_data()->fileurl;
        $requestid = $this->get_custom_data()->requestid;
        $request = coursetransfer_request::get($requestid);
        
        // Log download started
        coursetransfer_logger::log_task_started(
            $requestid,
            coursetransfer_logger::DIRECTION_TARGET,
            $this->get_id(),
            get_class($this),
            'Starting backup file download from origin'
        );
        
        coursetransfer_logger::info(
            $requestid,
            coursetransfer_logger::DIRECTION_TARGET,
            coursetransfer_logger::ACTION_DOWNLOAD_STARTED,
            'Initiating download from: ' . $fileurl,
            ['file_url' => $fileurl, 'adhoc_task_id' => $this->get_id()]
        );
        
        // Critical validation: Check if request exists and has valid target_course_id
        if (!$request) {
            throw new \Exception('Course transfer request not found with ID: ' . $requestid);
        }
        
        if (empty($request->target_course_id) || $request->target_course_id <= 0) {
            throw new \Exception('Invalid target_course_id in request: ' . ($request->target_course_id ?? 'NULL'));
        }
        
        // Verify that the target course actually exists in database
        if (!$DB->record_exists('course', ['id' => $request->target_course_id])) {
            throw new \Exception('Target course does not exist in database. Course ID: ' . $request->target_course_id);
        }
        
        $this->log("Validated request ID: {$requestid}, Target Course ID: {$request->target_course_id}");

        // Get retry attempt number (0 for first attempt)
        $retryattempt = isset($this->get_custom_data()->retry_attempt) ? 
            $this->get_custom_data()->retry_attempt : 0;

        if ($retryattempt > 0) {
            $this->log("Retry attempt #{$retryattempt} of " . self::MAX_RETRY_ATTEMPTS);
        }

        try {
            $fs = get_file_storage();
            
            // Step 1: Validate file size before download
            $filesize = $this->get_remote_file_size($fileurl);
            if ($filesize === false) {
                // File might not be ready yet - schedule retry instead of failing immediately
                if ($retryattempt < self::MAX_RETRY_ATTEMPTS) {
                    $this->schedule_retry($requestid, $fileurl, $retryattempt);
                    $this->log('File not ready yet. Retry scheduled.');
                    return; // Exit gracefully, retry will be executed later
                } else {
                    throw new \Exception('Failed to get remote file size after ' . 
                        self::MAX_RETRY_ATTEMPTS . ' attempts. File may not exist on origin.');
                }
            }
            
            $this->log("Remote file size: " . $this->format_bytes($filesize));
            
            // Step 2: Check if we have enough memory and configure strategy
            $memory_limit = $this->get_memory_limit_bytes();
            $use_streaming = ($filesize > ($memory_limit * 0.5)); // Use streaming if file > 50% of memory limit
            
            if ($use_streaming) {
                $this->log("Using streaming download for large file");
                $tempfile = $this->download_file_streaming($fileurl, $filesize);
            } else {
                $this->log("Using direct download for small file");
                $tempfile = $this->download_file_direct($fileurl);
            }
            
            if ($tempfile === false) {
                throw new \Exception('File download failed');
            }
            
            $this->log('Backup File Download Success!');

            // Step 3: Create Moodle file from temporary file
            // Double-check target_course_id before creating context
            if (empty($request->target_course_id) || $request->target_course_id <= 0) {
                throw new \Exception('Cannot create course context: invalid target_course_id = ' . ($request->target_course_id ?? 'NULL'));
            }
            
            $this->log("Creating context for course ID: {$request->target_course_id}");
            $context = context_course::instance($request->target_course_id);
            $filename = 'local_coursetransfer_' . $request->courseid . '_' . time() . '.mbz';

            $fileinfo = [
                'contextid' => $context->id,
                'component' => 'backup',
                'filearea' => 'course',
                'itemid' => 0,
                'filepath' => '/',
                'filename' => $filename,
            ];
            
            // Use create_file_from_pathname to avoid loading into memory
            $file = $fs->create_file_from_pathname($fileinfo, $tempfile);
            
            // Clean up temporary file
            unlink($tempfile);
            
            $this->log('Backup File Import to Moodle Success!');
            
            // Log successful download completion
            coursetransfer_logger::success(
                $requestid,
                coursetransfer_logger::DIRECTION_TARGET,
                coursetransfer_logger::ACTION_DOWNLOAD_COMPLETED,
                'Backup file downloaded and imported successfully',
                [
                    'file_size' => $filesize,
                    'filename' => $filename,
                    'context_id' => $context->id
                ]
            );
            
            $request->status = coursetransfer_request::STATUS_DOWNLOADED;
            coursetransfer_request::insert_or_update($request, $request->id);
            
            // Notify origin that backup was downloaded successfully so it can cleanup
            if (get_config('local_coursetransfer', 'auto_cleanup_origin_backup')) {
                $this->notify_origin_backup_downloaded($request);
            }
            
            coursetransfer_restore::create_task_restore_course($request, $file);
            
        } catch (\Throwable $e) {
            // Catch ALL types of errors including Exception, Error, Fatal errors, etc.
            $this->log('Error: ' . $e->getMessage());
            
            // Ensure we have request object
            if (!isset($request) || !$request) {
                if (isset($requestid)) {
                    try {
                        $request = coursetransfer_request::get($requestid);
                    } catch (\Exception $getException) {
                        $this->log('Could not retrieve request: ' . $getException->getMessage());
                    }
                }
            }
            
            // Log download failure
            if (isset($requestid)) {
                coursetransfer_logger::error(
                    $requestid,
                    coursetransfer_logger::DIRECTION_TARGET,
                    coursetransfer_logger::ACTION_DOWNLOAD_FAILED,
                    'Download failed: ' . $e->getMessage(),
                    $e->getCode() ?: '13000',
                    ['exception' => get_class($e), 'trace' => $e->getTraceAsString()]
                );
            }
            
            // Update request status if we have request object
            if (isset($request) && $request) {
                $request->status = coursetransfer_request::STATUS_ERROR;
                $request->error_code = $e->getCode() ?: '13000';
                $request->error_message = 'Download failed: ' . $e->getMessage();
                
                try {
                    coursetransfer_request::insert_or_update($request, $request->id);
                } catch (\Exception $updateException) {
                    $this->log('Failed to update request status: ' . $updateException->getMessage());
                }
            }
        }
        
        $this->log_finish("Download File Backup Course Remote and Restore Finishing...");
    }

    /**
     * Get remote file size using HEAD request with Moodle curl
     *
     * @param string $url
     * @return int|false File size in bytes or false on failure
     */
    private function get_remote_file_size($url) {
        global $CFG;
        require_once($CFG->libdir.'/filelib.php');
        
        $curl = new \curl();
        
        // Configure curl options
        $curl->setopt([
            'CURLOPT_NOBODY' => true,
            'CURLOPT_HEADER' => true,
            'CURLOPT_TIMEOUT' => get_config('local_coursetransfer', 'head_timeout') ?: 30,
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_FOLLOWLOCATION' => true,
            'CURLOPT_SSL_VERIFYPEER' => get_config('local_coursetransfer', 'ssl_verify') !== false,
            'CURLOPT_USERAGENT' => 'Moodle/' . $CFG->version . ' CourseTransfer/2.0',
        ]);
        
        $headers = $curl->head($url);
        $info = $curl->get_info();
        
        if ($info['http_code'] == 200 && isset($info['download_content_length']) && $info['download_content_length'] > 0) {
            return (int)$info['download_content_length'];
        }
        
        // Fallback: try to get content-length from headers array
        if (is_array($headers) && isset($headers['Content-Length'])) {
            return (int)$headers['Content-Length'];
        }
        
        $this->log('Failed to get file size: HTTP ' . $info['http_code']);
        return false;
    }

    /**
     * Download file using streaming with Moodle curl (for large files)
     *
     * @param string $url
     * @param int $filesize
     * @return string|false Path to temporary file or false on failure
     */
    private function download_file_streaming($url, $filesize) {
        global $CFG;
        require_once($CFG->libdir.'/filelib.php');
        
        // Create temporary file
        $tempdir = make_temp_directory('coursetransfer');
        $tempfile = $tempdir . '/download_' . $this->get_custom_data()->requestid . '_' . time() . '.mbz';
        
        $fp = fopen($tempfile, 'w+');
        if (!$fp) {
            $this->log('Failed to create temporary file: ' . $tempfile);
            return false;
        }
        
        $curl = new \curl();
        
        // Configure curl with enhanced settings for large files
        $download_timeout = $filesize > 1073741824 ? 7200 : 1800; // 2 hours for files >1GB, 30 min otherwise
        
        $curl->setopt([
            'CURLOPT_FILE' => $fp,
            'CURLOPT_TIMEOUT' => $download_timeout,
            'CURLOPT_CONNECTTIMEOUT' => get_config('local_coursetransfer', 'connect_timeout') ?: 300, // 5 minutes
            'CURLOPT_LOW_SPEED_LIMIT' => 1024, // Minimum 1KB/s
            'CURLOPT_LOW_SPEED_TIME' => 300,   // For 5 minutes (detect stalled downloads)
            'CURLOPT_FOLLOWLOCATION' => true,
            'CURLOPT_SSL_VERIFYPEER' => get_config('local_coursetransfer', 'ssl_verify') !== false,
            'CURLOPT_SSL_VERIFYHOST' => get_config('local_coursetransfer', 'ssl_verify') !== false ? 2 : 0,
            'CURLOPT_USERAGENT' => 'Moodle/' . $CFG->version . ' CourseTransfer/2.0 Enhanced',
            'CURLOPT_BUFFERSIZE' => 524288, // 512KB buffer for better performance
            'CURLOPT_TCP_KEEPALIVE' => 1,   // Keep connection alive for long transfers
            'CURLOPT_NOPROGRESS' => false,
        ]);
        
        $this->log("Configured for large file download: timeout={$download_timeout}s, size=" . $this->format_bytes($filesize));
        
        // Enhanced progress tracking variables
        $lastpercent = 0;
        $lastlogtime = time();
        $starttime = time();
        $last_downloaded = 0;
        
        // Set enhanced progress callback
        $curl->setopt([
            'CURLOPT_PROGRESSFUNCTION' => function($resource, $download_total, $downloaded, $upload_total, $uploaded) use (&$lastpercent, &$lastlogtime, &$starttime, &$last_downloaded) {
                if ($download_total > 0 && $downloaded > 0) {
                    $percent = round(($downloaded / $download_total) * 100);
                    $currenttime = time();
                    $elapsed_total = $currenttime - $starttime;
                    $elapsed_since_log = $currenttime - $lastlogtime;
                    
                    // Log every 5% or every 2 minutes for large files
                    if ($percent >= $lastpercent + 5 || $elapsed_since_log >= 120) {
                        // Calculate speeds with proper zero handling
                        $avg_speed = ($elapsed_total > 0 && $downloaded > 0) ? $downloaded / $elapsed_total : 0;
                        $current_speed = ($elapsed_since_log > 0 && $downloaded > $last_downloaded) ? 
                            ($downloaded - $last_downloaded) / $elapsed_since_log : 0;
                        
                        // Estimate remaining time with safety checks
                        $remaining_bytes = max(0, $download_total - $downloaded);
                        $eta = ($avg_speed > 0 && $remaining_bytes > 0) ? $remaining_bytes / $avg_speed : 0;
                        
                        // Format ETA safely
                        $eta_formatted = 'calculating...';
                        if ($eta > 0 && $eta < 86400) { // Less than 24 hours
                            $eta_formatted = gmdate('H:i:s', $eta);
                        } elseif ($eta >= 86400) {
                            $eta_formatted = 'more than 24h';
                        }
                        
                        $this->log(sprintf(
                            "Download: %d%% (%s/%s) - Speed: %s/s (avg: %s/s) - ETA: %s - Elapsed: %dm%ds",
                            $percent,
                            $this->format_bytes($downloaded),
                            $this->format_bytes($download_total),
                            $this->format_bytes(max(0, $current_speed)),
                            $this->format_bytes(max(0, $avg_speed)),
                            $eta_formatted,
                            floor($elapsed_total / 60),
                            $elapsed_total % 60
                        ));
                        
                        $lastpercent = $percent;
                        $lastlogtime = $currenttime;
                        $last_downloaded = $downloaded;
                    }
                }
                return 0; // Continue download
            }
        ]);
        
        // Perform download
        $result = $curl->download_one($url, null, ['file' => $fp]);
        $info = $curl->get_info();
        $errno = $curl->get_errno();
        
        fclose($fp);
        
        // Enhanced error checking with detailed diagnostics
        $total_time = time() - $starttime;
        $downloaded_size = file_exists($tempfile) ? filesize($tempfile) : 0;
        
        if (!$result || $info['http_code'] != 200) {
            if (file_exists($tempfile)) {
                unlink($tempfile);
            }
            
            $error_details = sprintf(
                "Download failed - HTTP: %d, cURL errno: %d, Total time: %ds, Downloaded: %s, Expected: %s",
                $info['http_code'] ?? 0,
                $errno,
                $total_time,
                $this->format_bytes($downloaded_size),
                $this->format_bytes($filesize)
            );
            
            $this->log($error_details);
            
            // Log additional curl info for debugging
            if (isset($info['total_time'])) {
                $this->log(sprintf(
                    "cURL diagnostics - Connect: %.2fs, Pretransfer: %.2fs, Total: %.2fs, Speed: %s/s",
                    $info['connect_time'] ?? 0,
                    $info['pretransfer_time'] ?? 0,
                    $info['total_time'] ?? 0,
                    $this->format_bytes($info['speed_download'] ?? 0)
                ));
            }
            
            return false;
        }
        
        // Verify file size if known
        $downloaded_size = filesize($tempfile);
        if ($filesize > 0 && $downloaded_size != $filesize) {
            // Allow small differences due to compression/headers (5% tolerance)
            $tolerance = $filesize * 0.05;
            if (abs($downloaded_size - $filesize) > $tolerance) {
                unlink($tempfile);
                $this->log("Size mismatch: expected {$filesize}, got {$downloaded_size} (tolerance: {$tolerance})");
                return false;
            } else {
                $this->log("Size difference within tolerance: expected {$filesize}, got {$downloaded_size}");
            }
        }
        
        $this->log("Download completed successfully: " . $this->format_bytes($downloaded_size));
        return $tempfile;
    }

    /**
     * Download file directly with Moodle curl (for small files)
     *
     * @param string $url
     * @return string|false Path to temporary file or false on failure
     */
    private function download_file_direct($url) {
        global $CFG;
        require_once($CFG->libdir.'/filelib.php');
        
        $curl = new \curl();
        
        // Configure curl for small files
        $curl->setopt([
            'CURLOPT_TIMEOUT' => get_config('local_coursetransfer', 'small_file_timeout') ?: 300, // 5 minutes default
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_FOLLOWLOCATION' => true,
            'CURLOPT_SSL_VERIFYPEER' => get_config('local_coursetransfer', 'ssl_verify') !== false,
            'CURLOPT_SSL_VERIFYHOST' => get_config('local_coursetransfer', 'ssl_verify') !== false ? 2 : 0,
            'CURLOPT_USERAGENT' => 'Moodle/' . $CFG->version . ' CourseTransfer/2.0',
        ]);
        
        // Download to string
        $filecontent = $curl->get($url);
        $info = $curl->get_info();
        $errno = $curl->get_errno();
        
        if (!$filecontent || $info['http_code'] != 200) {
            $error_msg = $errno ? "cURL error {$errno}" : "HTTP {$info['http_code']}";
            $this->log("Direct download failed: {$error_msg}");
            return false;
        }
        
        // Create temporary file
        $tempdir = make_temp_directory('coursetransfer');
        $tempfile = $tempdir . '/download_' . $this->get_custom_data()->requestid . '_' . time() . '.mbz';
        
        $result = file_put_contents($tempfile, $filecontent);
        
        if ($result === false) {
            $this->log('Failed to write temporary file: ' . $tempfile);
            return false;
        }
        
        // Clear memory
        unset($filecontent);
        
        $this->log("Direct download completed: " . $this->format_bytes($result));
        return $tempfile;
    }

    /**
     * Get memory limit in bytes
     *
     * @return int Memory limit in bytes
     */
    private function get_memory_limit_bytes() {
        $memory_limit = ini_get('memory_limit');
        
        if ($memory_limit == -1) {
            return PHP_INT_MAX;
        }
        
        $unit = strtolower(substr($memory_limit, -1));
        $value = (int)substr($memory_limit, 0, -1);
        
        switch ($unit) {
            case 'g':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $value *= 1024 * 1024;
                break;
            case 'k':
                $value *= 1024;
                break;
        }
        
        return $value;
    }

    /**
     * Format bytes to human readable format
     *
     * @param int $bytes
     * @return string
     */
    private function format_bytes($bytes) {
        // Handle zero or negative bytes to prevent log(0) error
        if ($bytes <= 0) {
            return '0 B';
        }
        
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);
        $power = max($power, 0); // Ensure power is not negative
        
        return round($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
    }

    /**
     * Notify origin Moodle that backup was downloaded successfully
     * so it can cleanup the backup file
     *
     * @param stdClass $request
     * @return void
     */
    private function notify_origin_backup_downloaded($request) {
        try {
            $site = coursetransfer::get_site_by_url($request->siteurl);
            if ($site) {
                $api_request = new \local_coursetransfer\api\request($site);
                $user = \core_user::get_user($request->userid);
                
                $response = $api_request->target_backup_course_downloaded($request->id, $user);
                
                if ($response->success) {
                    $this->log('Successfully notified origin to cleanup backup file');
                } else {
                    $this->log('Failed to notify origin for cleanup: ' . json_encode($response->errors));
                }
            }
        } catch (\Exception $e) {
            // Don't fail the download process if notification fails
            $this->log('Error notifying origin for cleanup: ' . $e->getMessage());
        }
    }

    /**
     * Schedule a retry of this download task
     *
     * @param int $requestid
     * @param string $fileurl
     * @param int $currentattempt
     * @return void
     */
    private function schedule_retry($requestid, $fileurl, $currentattempt) {
        $nextattempt = $currentattempt + 1;
        
        // Exponential backoff: 1min, 2min, 4min, 8min, 16min
        $delay = self::BASE_RETRY_DELAY * pow(2, $currentattempt);
        
        $retrytask = new download_file_course_task();
        $retrytask->set_blocking(false);
        $retrytask->set_custom_data([
            'requestid' => $requestid,
            'fileurl' => $fileurl,
            'retry_attempt' => $nextattempt,
        ]);
        
        // Schedule task to run after delay
        $retrytask->set_next_run_time(time() + $delay);
        
        \core\task\manager::queue_adhoc_task($retrytask);
        
        coursetransfer_logger::warning(
            $requestid,
            coursetransfer_logger::DIRECTION_TARGET,
            'DOWNLOAD_RETRY_SCHEDULED',
            "Download retry #{$nextattempt} scheduled in {$delay} seconds (exponential backoff)",
            null,
            ['attempt' => $nextattempt, 'delay' => $delay, 'max_attempts' => self::MAX_RETRY_ATTEMPTS]
        );
        
        $this->log("Retry #{$nextattempt} scheduled to run in {$delay} seconds (" . 
            gmdate('i\m s\s', $delay) . ")");
    }

}
