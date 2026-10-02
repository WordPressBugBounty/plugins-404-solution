jQuery(document).ready(function ($) {
    var $container = $('#abj404-gsc-deferred-content');
    if ($container.length === 0) {
        return;
    }
    if ($container.attr('data-deferred-load') !== '1') {
        return;
    }

    var action = $container.attr('data-ajax-action') || 'abj404_load_gsc_section';
    var nonce = $container.attr('data-ajax-nonce') || '';

    /**
     * Message for a section load the server did not explain: the framing
     * sentence plus the underlying code in parentheses, never the bare
     * sentence. Delegates to the shared seam (abj404-admin-ajax.js), which
     * also records the failure to the console; when that asset did not load,
     * the same shape is composed here so the message keeps its cause either way.
     *
     * @param {object} jqXHR
     * @param {{source: string, textStatus: string, errorThrown: string}} failure
     *     One object, not adjacent strings, so two of them cannot be swapped
     *     silently. The framing sentence (fallback) is owned here.
     * @returns {string}
     */
    function describeFailure(jqXHR, failure) {
        var fallback = 'Unable to load Google Search Console section.';
        var source = (typeof failure.source === 'string') ? failure.source : '';
        var textStatus = (typeof failure.textStatus === 'string') ? failure.textStatus : '';
        var errorThrown = (typeof failure.errorThrown === 'string') ? failure.errorThrown : '';
        if (typeof abj404AdminAjaxErrorMessage === 'function') {
            return abj404AdminAjaxErrorMessage(jqXHR, {
                fallback: fallback,
                source: source,
                textStatus: textStatus,
                errorThrown: errorThrown
            });
        }
        if (typeof console !== 'undefined' && console.warn) {
            console.warn('404 Solution: GSC section load failed (' + textStatus + ')');
        }
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

    $.ajax({
        url: window.ajaxurl || 'admin-ajax.php',
        type: 'POST',
        dataType: 'json',
        timeout: 15000,
        data: {
            action: action,
            nonce: nonce
        },
        success: function (response, textStatus, jqXHR) {
            if (response && response.success && response.data && typeof response.data.html === 'string') {
                $container.html(response.data.html);
                $container.attr('data-deferred-load', '0');
                return;
            }

            var message;
            if (response && response.data && response.data.message) {
                message = String(response.data.message);
            } else {
                // A 200 that is not the expected shape: name the status and
                // keep the response in the console.
                message = describeFailure(jqXHR, {
                    source: 'gsc-deferred-section-malformed',
                    textStatus: textStatus,
                    errorThrown: ''
                });
            }
            $container.html('<p class="abj404-form-help">' + $('<div>').text(message).html() + '</p>');
            $container.attr('data-deferred-load', '0');
        },
        error: function (jqXHR, textStatus, errorThrown) {
            // The server's forwarded exception message when it sent one, else
            // the underlying status.
            var message = describeFailure(jqXHR, {
                source: 'gsc-deferred-section',
                textStatus: textStatus,
                errorThrown: errorThrown
            });
            $container.html('<p class="abj404-form-help">' + $('<div>').text(message).html() + '</p>');
            $container.attr('data-deferred-load', '0');
        }
    });
});
