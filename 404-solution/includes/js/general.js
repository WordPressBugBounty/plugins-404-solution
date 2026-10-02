var abj404_whichButtonClicked = null;

// Bound on the document when this script runs, not in a jQuery ready callback.
// The script loads in the head; the browser paints the options form and accepts
// a click on Save before ready fires on a heavy admin page. A handler attached
// only at ready leaves that window open to a native POST to action="#", which
// no server handler reads, so the admin's changes were silently discarded.
document.addEventListener('submit', function(e) {
	if (e.target && e.target.id === 'admin-options-page') {
		submitOptions(e);
	}
});

document.addEventListener('click', function(e) {
	var target = e.target;
	if (target && typeof target.closest === 'function' && target.closest('#deleteDebugFile')) {
		abj404_whichButtonClicked = 'deleteDebugFile';
		submitOptions(e);
	}
});

function striphtml(html) {
    // A regex strip (not a real HTML parser) so this can never load a
    // resource or run an event-handler attribute (e.g. <img onerror=...>)
    // that an HTML parser boundary would otherwise create, even on an
    // element never attached to the document. The only caller uses this
    // purely to make a JSON.stringify()'d payload readable in a console.log.
    if (typeof html !== 'string') {
        return '';
    }
    return html.replace(/<[^>]*>/g, '');
}

function submitOptions(e) {
    e.preventDefault();

    // Show loading overlay
    showSaveOverlay();

	// gather form data.
	var form = document.getElementById("admin-options-page");
	var formElements = form.elements;
	var formData = {};
	for (var i = 0; i < formElements.length; i++) {
		var field = formElements[i];
		var currentValue = field.value;
		if (field.type == 'checkbox') {
			currentValue = field.checked ? 1 : 0;
		}

		if (!(field.name in formData)) {
			formData[field.name] = currentValue;
		} else {
			if (!Array.isArray(formData[field.name])) {
				formData[field.name] = new Array(formData[field.name]);
			}
			formData[field.name].push(currentValue);
		}
	}

    // if we should just delete the log file.
    if (abj404_whichButtonClicked == 'deleteDebugFile') {
    	// set the action to 'updateOptions' and set deleteDebugFile to true
    	formData['action'] = 'updateOptions';
    	formData['deleteDebugFile'] = true;
    } else {
    	formData['deleteDebugFile'] = false;
    }

	// fix checkboxes.
    var formDataAsJson = JSON.stringify(formData);
    var encodedData = encodeURI(formDataAsJson);

    // save / send the data via an ajax request.
    var saveOptionsURL = form.getAttribute('data-url')

    jQuery.ajax({
        url: saveOptionsURL,
        type: 'POST',
        // Was a bare setTimeout that showed the error but left the request
        // running, so a late success could still submit the redirect form
        // under an error overlay. jQuery's own deadline aborts the request and
        // routes it to the error handler below, which is the one place the
        // overlay is cleared.
        timeout: 30000,
        data: {
            'encodedData': encodedData
        },
        dataType :'json',
        success: function (data, textStatus, jqXHR) {
            // Support both legacy payloads ({ newURL, message, error }) and WP-shaped responses
            // ({ success: true|false, data: { ... } }).
            var payload = data;
            if (data && typeof data === 'object' && typeof data.success === 'boolean' && data.data !== undefined) {
                if (data.success === false) {
                    var serverMsg = '';
                    if (typeof data.data === 'string') {
                        serverMsg = data.data;
                    } else if (data.data && data.data.message) {
                        serverMsg = data.data.message;
                    }
                    showSaveError(serverMsg || abj404OptionsSaveFailureMessage(jqXHR, {
                        textStatus: textStatus,
                        errorThrown: ''
                    }));
                    return;
                }
                payload = data.data;
            }

            // Safety: if we don't have the redirect URL, treat as failure.
            if (!payload || payload['newURL'] === undefined) {
                showSaveError(abj404OptionsSaveFailureMessage(jqXHR, {
                    textStatus: textStatus,
                    errorThrown: ''
                }));
                return;
            }

            var message = striphtml(JSON.stringify(payload, null, 2));
            console.log("saved options: " + message);

            // redirect and post a message (overlay will disappear on page reload)
            // Use DOM methods to avoid XSS from unescaped payload values in HTML attributes.
            var formEl = document.createElement('form');
            formEl.method = 'post';
            formEl.action = payload['newURL'];
            formEl.style.display = 'none';
            var inputEl = document.createElement('input');
            inputEl.type = 'text';
            inputEl.name = 'display-this-message';
            inputEl.value = payload['message'];
            formEl.appendChild(inputEl);
            document.body.appendChild(formEl);
            formEl.submit();
        },
        error: function (request, textStatus, errorThrown) {
            // A deadline is the one failure the admin can act on directly, so
            // it keeps its own wording instead of the generic save message.
            if (textStatus === 'timeout') {
                showSaveError('Request timed out. Please check your connection and try again.');
                return;
            }
            var errMsg = abj404OptionsSaveFailureMessage(request, {
                textStatus: textStatus,
                errorThrown: errorThrown
            });

            showSaveError(errMsg);
        }
    });

    // don't submit the form.
    return false;
}

/**
 * Message for a settings save the server did not explain: the framing sentence
 * plus the underlying code in parentheses, never the bare sentence. Delegates
 * to the shared seam (abj404-admin-ajax.js), which also records the failure to
 * the console and never throws; when that asset did not load, the same shape is
 * composed here so the overlay keeps its cause and still clears instead of
 * throwing out of the handler.
 *
 * @param {object} jqXHR
 * @param {{textStatus: string, errorThrown: string}} failure
 *     One object, not adjacent strings, so the two cannot be swapped silently:
 *     jQuery's own textStatus and errorThrown. The framing sentence (fallback)
 *     and the console-record source ('options-save') are owned here.
 * @returns {string}
 */
function abj404OptionsSaveFailureMessage(jqXHR, failure) {
    var fallback = "Error saving settings. Please try again.";
    var textStatus = (typeof failure.textStatus === 'string') ? failure.textStatus : '';
    var errorThrown = (typeof failure.errorThrown === 'string') ? failure.errorThrown : '';
    if (typeof abj404AdminAjaxErrorMessage === 'function') {
        return abj404AdminAjaxErrorMessage(jqXHR, {
            fallback: fallback,
            source: 'options-save',
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

function showSaveOverlay() {
    var overlay = document.getElementById('abj404-save-overlay');
    if (overlay) {
        overlay.style.display = 'flex';
        overlay.classList.remove('error');

        // Update message to default
        var message = overlay.querySelector('.abj404-save-message');
        if (message) {
            message.textContent = abj404General.savingSettings;
        }

        // Announce to screen readers
        abj404AnnounceToScreenReader(abj404General.savingSettings);
    }
}

/**
 * Announce a message to screen readers using a live region
 * @param {string} message The message to announce
 * @param {string} priority 'polite' or 'assertive' (default: 'polite')
 */
function abj404AnnounceToScreenReader(message, priority) {
    priority = priority || 'polite';

    // Create or get the live region
    var liveRegion = document.getElementById('abj404-live-region');
    if (!liveRegion) {
        liveRegion = document.createElement('div');
        liveRegion.id = 'abj404-live-region';
        liveRegion.className = 'abj404-live-region';
        liveRegion.setAttribute('aria-live', priority);
        liveRegion.setAttribute('aria-atomic', 'true');
        liveRegion.setAttribute('role', 'status');
        document.body.appendChild(liveRegion);
    }

    // Update priority if needed
    liveRegion.setAttribute('aria-live', priority);

    // Clear and set the message (clearing first ensures re-announcement)
    liveRegion.textContent = '';
    setTimeout(function() {
        liveRegion.textContent = message;
    }, 100);
}

function showSaveError(errorMessage) {
    var overlay = document.getElementById('abj404-save-overlay');
    if (overlay) {
        overlay.classList.add('error');

        var message = overlay.querySelector('.abj404-save-message');
        if (message) {
            message.textContent = errorMessage;
        }

        // Announce error to screen readers with assertive priority
        abj404AnnounceToScreenReader(errorMessage, 'assertive');

        // Hide overlay after 5 seconds
        setTimeout(function() {
            overlay.style.display = 'none';
        }, 5000);
    }
}
