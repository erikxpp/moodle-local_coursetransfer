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
     * Download file using cURL instead of file_get_contents for better handling of large files.
     *
     * @param string $url The URL to download from
     * @return string The file content
     * @throws moodle_exception If download fails
     */
    private function download_file_with_curl(string $url): string {
        $curl = curl_init();
        
        // Get timeout from plugin settings or use default (300 seconds = 5 minutes)
        $timeout = (int)get_config('local_coursetransfer', 'download_timeout') ?: 300;
        
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'Moodle CourseTransfer Plugin/1.0',
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_ENCODING => '', // Accept all supported encodings
        ]);
        
        $result = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $contentLength = curl_getinfo($curl, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        $error = curl_error($curl);
        $errno = curl_errno($curl);
        
        curl_close($curl);
        
        // Log download details
        $this->log("Download attempt - HTTP Code: {$httpCode}, Content Length: {$contentLength} bytes, cURL Error: {$errno}");
        
        if ($result === false || $errno !== 0) {
            throw new \Exception("cURL error {$errno}: {$error}");
        }
        
        if ($httpCode !== 200) {
            throw new \Exception("HTTP error {$httpCode}");
        }
        
        if (empty($result)) {
            throw new \Exception('Downloaded file is empty');
        }
        
        $this->log("Download successful - File size: " . strlen($result) . " bytes");
        
        return $result;
    }

    /**
     * Execute.
     *
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function execute() {

        $this->log_start("Download File Backup Course Remote and Restore Starting...");
        $fileurle = $this->get_custom_data()->fileurl;
        $requestid = $this->get_custom_data()->requestid;
        $request = coursetransfer_request::get($requestid);

        try {
            $fs = get_file_storage();
            $filecontent = $this->download_file_with_curl($fileurle);

            if (!empty($filecontent)) {
                $this->log('Backup File Dowload Success!');

                $context = context_course::instance($request->target_course_id);
                $filename = 'local_coursetransfer_' . $request->origin_course_id . '_' . time() . '.mbz';

                $fileinfo = [
                        'contextid' => $context->id,
                        'component' => 'backup',
                        'filearea' => 'course',
                        'itemid' => 0,
                        'filepath' => '/',
                        'filename' => $filename,
                ];
                $file = $fs->create_file_from_string($fileinfo, $filecontent);
                $this->log('Backup File Dowload in Moodle Success!');
                $request->status = coursetransfer_request::STATUS_DOWNLOADED;
                coursetransfer_request::insert_or_update($request, $request->id);
                coursetransfer_restore::create_task_restore_course($request, $file);
            } else {
                $errorlast = error_get_last();
                $error = isset($errorlast['message']) ? $errorlast['message'] : 'HTTP request failed in file download';
                $this->log($error);
                $request->status = coursetransfer_request::STATUS_ERROR;
                $request->error_code = '13001';
                $request->error_message = $error;
                coursetransfer_request::insert_or_update($request, $request->id);
            }
        } catch (\Exception $e) {
            $this->log($e->getMessage());
            $request->status = coursetransfer_request::STATUS_ERROR;
            $request->error_code = '13000';
            $request->error_message = $e->getMessage();
            coursetransfer_request::insert_or_update($request, $request->id);
        }
        $this->log_finish("Download File Backup Course Remote and Restore Finishing...");
    }

}
