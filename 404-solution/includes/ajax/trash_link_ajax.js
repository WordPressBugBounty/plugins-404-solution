

jQuery(document).ready(function($) {
	bindTrashLinkListeners();
});

function bindTrashLinkListeners() {
    jQuery(".ajax-trash-link").click(function (e) {
        // preventDefault() means don't move to the top of the page. 
        e.preventDefault();
        
        var trashFilter = getURLParameter('filter');
        
        var row = jQuery(this).closest("tr");
        row.css("background-color", "grey");

        var theURL = jQuery(this).attr("data-url");
        jQuery.ajax({
            url: theURL, 
            type : 'GET',
            dataType: "json",
            // A request with no deadline never reaches the error handler that
            // re-enables the control this call disabled, so the page stays stuck.
            timeout: 30000,
            data: {
                filter: trashFilter
            },
            success: function (data, textStatus, jqXHR) {
                // Support both legacy payload ({ result, subsubsub, ... }) and WP-shaped AJAX payloads
                // ({ success: boolean, data: {...} }).
                var payload = data;
                if (data && typeof data === 'object' && data.success === false && data.data === undefined) {
                    // wp_send_json_error() with no argument answers {"success":false}. It carries
                    // no payload to show, but it is still a failure: reading it as a legacy
                    // payload below removed the row as though the trash had worked.
                    row.css("background-color", "yellow");
                    alert(abj404TrashLinkFailureMessage(jqXHR, {
                        fallback: "The redirect could not be moved to the trash.",
                        textStatus: textStatus,
                        errorThrown: ''
                    }));
                    return;
                }
                if (data && typeof data === 'object' && typeof data.success === 'boolean' && data.data !== undefined) {
                    if (data.success === false) {
                        row.css("background-color", "yellow");
                        alert("Error: " + JSON.stringify(data.data, null, 2));
                        return;
                    }
                    payload = data.data;
                }

                if (payload.result && payload.result.startsWith("fail")) {
                    row.css("background-color", "yellow");
                    alert("Error: " + JSON.stringify(payload, null, 2));

                } else {
                    row.hide(1000, function(){ row.remove(); });
                    // Update filter-row counts with fresh values from the server.
                    if (payload.tabCounts && Array.isArray(payload.tabCounts)) {
                        jQuery('.subsubsub a .count').each(function(i) {
                            if (i < payload.tabCounts.length) {
                                jQuery(this).text('(' + payload.tabCounts[i] + ')');
                            }
                        });
                    }
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                alert(abj404TrashLinkFailureMessage(jqXHR, {
                    fallback: "The redirect could not be moved to the trash.",
                    textStatus: textStatus,
                    errorThrown: errorThrown
                }));
                row.css("background-color", "yellow");
            }
        });
    });
}

/**
 * Message for a trash failure the server did not explain: the framing sentence
 * plus the underlying code in parentheses, never the bare sentence. Delegates
 * to the shared seam (abj404-admin-ajax.js), which also records the failure to
 * the console; when that asset did not load, the same shape is composed here
 * so the message keeps its cause either way.
 *
 * @param {object} jqXHR
 * @param {{fallback: string, textStatus: string, errorThrown: string}} failure
 *     One object, not adjacent strings, so two of them cannot be swapped
 *     silently: fallback is the framing sentence, textStatus and errorThrown
 *     jQuery's own. The console-record source ('trash-link') is owned here.
 * @returns {string}
 */
function abj404TrashLinkFailureMessage(jqXHR, failure) {
    var fallback = (typeof failure.fallback === 'string') ? failure.fallback : '';
    var textStatus = (typeof failure.textStatus === 'string') ? failure.textStatus : '';
    var errorThrown = (typeof failure.errorThrown === 'string') ? failure.errorThrown : '';
    if (typeof abj404AdminAjaxErrorMessage === 'function') {
        return abj404AdminAjaxErrorMessage(jqXHR, {
            fallback: fallback,
            source: 'trash-link',
            textStatus: textStatus,
            errorThrown: errorThrown
        });
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
