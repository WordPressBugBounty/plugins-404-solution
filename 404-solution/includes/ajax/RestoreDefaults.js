/**
 * Restore Defaults Handler
 * Confirms with the admin, then calls the AJAX endpoint that overwrites
 * abj404_settings with PluginLogic::getDefaultOptions().
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        initRestoreDefaults();
    });

    /**
     * Message for a failure the server did not explain: the framing sentence
     * plus the underlying code in parentheses, never the bare sentence.
     * Delegates to the shared seam (abj404-admin-ajax.js), which also records
     * the failure to the console; when that asset did not load, the same shape
     * is composed here so the message keeps its cause either way.
     *
     * @param {object} jqXHR
     * @param {{fallback: string, source: string, textStatus: string, errorThrown: string}} failure
     *     One object, not adjacent strings, so two of them cannot be swapped
     *     silently: fallback is the framing sentence, source the call-site
     *     label for the console record, textStatus and errorThrown jQuery's own.
     * @returns {string}
     */
    function describeFailure(jqXHR, failure) {
        if (typeof abj404AdminAjaxErrorMessage === 'function') {
            return abj404AdminAjaxErrorMessage(jqXHR, failure);
        }
        var fallback = (typeof failure.fallback === 'string') ? failure.fallback : '';
        var textStatus = (typeof failure.textStatus === 'string') ? failure.textStatus : '';
        var errorThrown = (typeof failure.errorThrown === 'string') ? failure.errorThrown : '';
        var status = (jqXHR && typeof jqXHR.status === 'number') ? jqXHR.status : 0;
        var detail = 'no response from the server';
        if (status === 0 && errorThrown && errorThrown.toLowerCase() !== 'error') {
            detail = errorThrown;
        } else if (status >= 200 && status < 300) {
            detail = 'HTTP ' + status + (textStatus === 'parsererror'
                ? ', the response was not valid JSON' : ', the server sent no message');
        } else if (status > 0) {
            var statusText = (typeof jqXHR.statusText === 'string') ? jqXHR.statusText : '';
            detail = 'HTTP ' + status
                + ((statusText !== '' && statusText.toLowerCase() !== 'error') ? ' ' + statusText : '');
        }
        return fallback + ' (' + detail + ')';
    }

    function initRestoreDefaults() {
        var $button = $('#abj404-restore-defaults');
        if (!$button.length) {
            return;
        }
        var nonce = $button.data('nonce');
        var $modal = $('#abj404-restore-defaults-modal');
        var $confirm = $('#abj404-restore-defaults-confirm');
        var $cancel = $('#abj404-restore-defaults-cancel, #abj404-restore-defaults-cancel-2');

        $button.on('click', function(e) {
            e.preventDefault();
            $modal.addClass('active');
            $confirm.trigger('focus');
        });

        function closeModal() {
            $modal.removeClass('active');
        }

        $cancel.on('click', closeModal);
        $modal.on('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        $(document).on('keydown.abj404RestoreDefaults', function(e) {
            if (e.key === 'Escape' && $modal.hasClass('active')) {
                closeModal();
            }
        });

        $confirm.on('click', function(e) {
            e.preventDefault();
            $confirm.prop('disabled', true).addClass('loading');
            $cancel.prop('disabled', true);

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                // A request with no deadline never reaches the error handler that
                // re-enables the control this call disabled, so the page stays stuck.
                timeout: 30000,
                // Parsed as JSON so a non-JSON 200 reaches the error handler
                // below, where the shared seam keeps its status and body excerpt.
                dataType: 'json',
                data: {
                    action: 'abj404_restore_defaults',
                    nonce: nonce
                },
                success: function(response, textStatus, jqXHR) {
                    if (response && typeof response === 'object' && response.success === true) {
                        window.location.reload();
                        return;
                    }
                    var serverMsg = '';
                    if (response && typeof response === 'object' && response.data) {
                        if (typeof response.data === 'string') {
                            serverMsg = response.data;
                        } else if (response.data.message) {
                            serverMsg = response.data.message;
                        }
                    }
                    if (!serverMsg) {
                        serverMsg = describeFailure(jqXHR, {
                            fallback: 'Failed to restore defaults',
                            source: 'restore-defaults-malformed',
                            textStatus: textStatus,
                            errorThrown: ''
                        });
                    }
                    alert(serverMsg);
                    $confirm.prop('disabled', false).removeClass('loading');
                    $cancel.prop('disabled', false);
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    alert(describeFailure(jqXHR, {
                        fallback: 'An error occurred while restoring defaults',
                        source: 'restore-defaults',
                        textStatus: textStatus,
                        errorThrown: errorThrown
                    }));
                    $confirm.prop('disabled', false).removeClass('loading');
                    $cancel.prop('disabled', false);
                }
            });
        });
    }

})(jQuery);
