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
 * Retry failed course transfer request
 *
 * @module      local_coursetransfer/retry_request
 * @copyright   2025 Proyecto UNIMOODLE
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/ajax', 'core/notification', 'core/str'], function($, Ajax, Notification, Str) {

    const SERVICES = {
        RETRY_REQUEST: 'local_coursetransfer_retry_failed_request'
    };

    /**
     * Initialize the retry request functionality
     */
    function init() {
        // Use delegation to handle dynamically loaded buttons
        $(document).on('click', '.retry-request-btn', function(e) {
            e.preventDefault();
            
            const button = $(this);
            const requestId = parseInt(button.data('request-id'));
            
            if (!requestId) {
                return;
            }
            
            // Get confirmation strings
            Str.get_strings([
                {key: 'retry_request_confirm_title', component: 'local_coursetransfer'},
                {key: 'retry_request_confirm_message', component: 'local_coursetransfer'},
                {key: 'retry', component: 'local_coursetransfer'},
                {key: 'cancel', component: 'core'}
            ]).done(function(strings) {
                // Show confirmation dialog
                Notification.confirm(
                    strings[0], // Title
                    strings[1], // Message
                    strings[2], // Yes button
                    strings[3], // No button
                    function() {
                        // User confirmed - execute retry
                        executeRetry(button, requestId);
                    }
                );
            }).fail(Notification.exception);
        });
    }

    /**
     * Execute the retry request
     *
     * @param {jQuery} button The button element
     * @param {int} requestId The request ID to retry
     */
    function executeRetry(button, requestId) {
        // Disable button and show loading state
        button.prop('disabled', true);
        const originalText = button.html();
        
        Str.get_string('processing', 'local_coursetransfer')
            .done(function(processingMsg) {
                button.html('<i class="fa fa-spinner fa-spin"></i> ' + processingMsg);
            })
            .fail(function() {
                button.html('<i class="fa fa-spinner fa-spin"></i> Procesando...');
            });
        
        const request = {
            methodname: SERVICES.RETRY_REQUEST,
            args: {
                requestid: requestId
            }
        };

        Ajax.call([request])[0]
            .done(function(response) {
                if (response.success && response.data.redirect_url) {
                    // Show success message
                    Str.get_string('retry_request_success', 'local_coursetransfer')
                        .done(function(successMsg) {
                            Notification.addNotification({
                                message: successMsg,
                                type: 'success'
                            });
                            
                            // Redirect to new request logs after short delay
                            setTimeout(function() {
                                window.location.href = response.data.redirect_url;
                            }, 1500);
                        })
                        .fail(function() {
                            // Fallback - just redirect
                            window.location.href = response.data.redirect_url;
                        });
                } else {
                    // Show errors
                    Str.get_strings([
                        {key: 'retry_request_failed', component: 'local_coursetransfer'},
                        {key: 'error', component: 'core'},
                        {key: 'ok', component: 'core'}
                    ]).done(function(errorStrings) {
                        let errorMessage = errorStrings[0];
                        
                        if (response.errors && response.errors.length > 0) {
                            errorMessage += '<br><br>';
                            response.errors.forEach(function(error) {
                                errorMessage += '• [' + error.code + '] ' + error.msg + '<br>';
                            });
                        }
                        
                        Notification.alert(
                            errorStrings[1],
                            errorMessage,
                            errorStrings[2]
                        );
                        
                        // Re-enable button
                        button.prop('disabled', false);
                        button.html(originalText);
                    }).fail(function() {
                        Notification.alert('Error', 'Error al reprocesar la solicitud', 'OK');
                        button.prop('disabled', false);
                        button.html(originalText);
                    });
                }
            })
            .fail(function(error) {
                // Show error notification
                Notification.exception(error);
                
                // Re-enable button
                button.prop('disabled', false);
                button.html(originalText);
            });
    }

    return {
        init: init
    };
});
