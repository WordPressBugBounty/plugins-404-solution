/**
 * Simple/Advanced Mode Toggle Handler
 * Handles switching between settings modes via AJAX.
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        initModeToggle();
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

    /**
     * Initialize the mode toggle buttons.
     */
    function initModeToggle() {
        var $toggleContainer = $('.abj404-mode-toggle');
        if (!$toggleContainer.length) {
            return;
        }

        var $buttons = $toggleContainer.find('.abj404-mode-btn');
        var nonce = $toggleContainer.data('nonce');

        // Handle "Switch to Advanced Mode" link in the hint
        $(document).on('click', '.abj404-switch-to-advanced', function(e) {
            e.preventDefault();
            // Trigger click on the Advanced Mode button
            var $advancedBtn = $toggleContainer.find('.abj404-mode-btn[data-mode="advanced"]');
            if ($advancedBtn.length && !$advancedBtn.hasClass('active')) {
                $advancedBtn.trigger('click');
            }
        });

        $buttons.on('click', function(e) {
            e.preventDefault();

            var $btn = $(this);
            var mode = $btn.data('mode');

            // Don't do anything if already active
            if ($btn.hasClass('active')) {
                return;
            }

            // Disable buttons during request
            $buttons.prop('disabled', true);
            $btn.addClass('loading');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                // A request with no deadline never reaches the error handler that
                // re-enables the control this call disabled, so the page stays stuck.
                timeout: 30000,
                // Parsed as JSON so a non-JSON 200 (a fatal's plain text, a
                // gateway page) reaches the error handler below, where the
                // shared seam keeps its status and body excerpt, instead of
                // arriving here as a string whose text is discarded.
                dataType: 'json',
                data: {
                    action: 'abj404_toggle_settings_mode',
                    mode: mode,
                    nonce: nonce
                },
                success: function(response, textStatus, jqXHR) {
                    // Validate shape before reading fields: an upstream
                    // gateway / WAF / plugin conflict can return HTML or
                    // a non-object body even when the request asked for
                    // JSON. Treat any malformed body as a failed save.
                    if (response && typeof response === 'object' && response.success === true) {
                        // Reload the page to show the new mode
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
                            fallback: 'Failed to update settings mode',
                            source: 'settings-mode-toggle-malformed',
                            textStatus: textStatus,
                            errorThrown: ''
                        });
                    }
                    alert(serverMsg);
                    $buttons.prop('disabled', false);
                    $btn.removeClass('loading');
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    alert(describeFailure(jqXHR, {
                        fallback: 'An error occurred while updating settings mode',
                        source: 'settings-mode-toggle',
                        textStatus: textStatus,
                        errorThrown: errorThrown
                    }));
                    $buttons.prop('disabled', false);
                    $btn.removeClass('loading');
                }
            });
        });

        // Keyboard accessibility
        $buttons.on('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                $(this).trigger('click');
            }
        });
    }

})(jQuery);
