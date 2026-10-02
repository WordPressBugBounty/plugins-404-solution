/**
 * How long an admin AJAX request may run before jQuery aborts it and routes
 * the failure to the caller's own error handler.
 *
 * Generous on purpose. The cost of being too short is a legitimate slow
 * operation reported as a failure on a busy shared host; the cost of having no
 * deadline at all is a control that stays disabled until the page is reloaded,
 * because the handler that would re-enable it never runs. Thirty seconds is
 * past the point where an admin has concluded the click did nothing, and well
 * clear of any request this plugin issues on a healthy host.
 */
var ABJ404_ADMIN_AJAX_DEFAULT_TIMEOUT_MS = 30000;

/**
 * Enforced single call-through point for admin AJAX. scripts/lint/lint-raw-ajax.sh
 * requires call sites to use this instead of raw jQuery.ajax / $.ajax / $.post /
 * $.get, so any future cross-cutting concern (retry, telemetry, error handling)
 * has one seam to attach to instead of N call sites; add
 * "// ajax-direct-approved: <reason>" to opt a call site out.
 *
 * Supplies a default request deadline. A caller that needs a different budget
 * sets `timeout` itself and keeps it; the default only fills the gap, so a call
 * site can never be shipped with no deadline at all merely by forgetting one.
 *
 * @param {object} options  jQuery.ajax settings (url, data, type, etc.)
 * @returns {jqXHR}         The jQuery AJAX promise, for chaining .done/.fail.
 */
function abj404AdminAjax(options) {
    if (!options || typeof options !== 'object') {
        throw new Error('abj404AdminAjax: options object is required'); // allow-raw-error: internal caller-contract assertion, never reaches an end user
    }

    // Mutating the caller's object would surprise a caller that reuses one
    // settings object across retries, so the deadline goes on a copy.
    var settings = jQuery.extend({}, options);
    if (typeof settings.timeout !== 'number') {
        settings.timeout = ABJ404_ADMIN_AJAX_DEFAULT_TIMEOUT_MS;
    }

    return jQuery.ajax(settings); // ajax-direct-approved: wrapper implementation
}

/** Characters of the raw response body kept for the console entry. */
var ABJ404_ADMIN_AJAX_EXCERPT_LIMIT = 200;

/**
 * What the server said about a failed request, or '' when it said nothing
 * this ladder can read.
 *
 * WordPress error responses arrive in more than one shape: wp_send_json_error()
 * with a string, wp_send_json_error() with an array carrying a `message`, and
 * handlers that put `message` at the top level. Callers used to each carry
 * their own copy of this ladder, which is how four copies drifted into four
 * behaviours; it lives here now so there is one shape list to extend.
 *
 * May throw: a property read only raises if the response object is hostile
 * (a throwing getter), which is exactly the case the caller has to record
 * rather than swallow.
 *
 * @param {object} jqXHR
 * @returns {string}
 */
function abj404AdminAjaxServerMessage(jqXHR) {
    if (!jqXHR || !jqXHR.responseJSON) {
        return '';
    }
    if (typeof jqXHR.responseJSON.message === 'string' && jqXHR.responseJSON.message !== '') {
        return jqXHR.responseJSON.message;
    }
    if (!jqXHR.responseJSON.data) {
        return '';
    }
    if (typeof jqXHR.responseJSON.data === 'string') {
        return jqXHR.responseJSON.data;
    }
    if (typeof jqXHR.responseJSON.data.message === 'string' && jqXHR.responseJSON.data.message !== '') {
        return jqXHR.responseJSON.data.message;
    }
    return '';
}

/**
 * The shortest phrase that identifies an otherwise unexplained failure.
 *
 * Only reached when the server sent no message of its own, so it always names
 * something: a bare "An error occurred" cannot be reported, searched for or
 * acted on. jQuery reports a generic 'error' statusText for most transport
 * failures, so that word is dropped rather than shown: "HTTP 502 Bad Gateway"
 * is actionable, "HTTP 502 error" is noise wearing the same shape.
 *
 * A 2xx status that still landed here means the body was unusable (no message
 * on a success:false answer, or a body that did not parse as JSON), and the
 * phrase says which, because "HTTP 200" alone reads like a success.
 *
 * @param {{status: (number|null), statusText: string, textStatus: string, errorThrown: string}} context
 * @returns {string}
 */
function abj404AdminAjaxFailureDetail(context) {
    if (context.status !== null && context.status >= 200 && context.status < 300) {
        return 'HTTP ' + context.status + (context.textStatus === 'parsererror'
            ? ', the response was not valid JSON'
            : ', the server sent no message');
    }
    if (context.status !== null && context.status > 0) {
        if (context.statusText !== '' && context.statusText.toLowerCase() !== 'error') {
            return 'HTTP ' + context.status + ' ' + context.statusText;
        }
        return 'HTTP ' + context.status;
    }
    if (context.errorThrown !== '' && context.errorThrown.toLowerCase() !== 'error') {
        return context.errorThrown;
    }
    return 'no response from the server';
}

/**
 * Read everything diagnosable out of a failed jqXHR without ever throwing.
 *
 * Shared by abj404AdminAjaxErrorMessage() (which also needs a message to show)
 * and abj404AdminAjaxRecordFailure() (for handlers with nothing to show, such
 * as an autocomplete lookup), so the two can never disagree about which fields
 * count as evidence.
 *
 * @param {object} jqXHR
 * @param {object} opts  Same options bag as abj404AdminAjaxErrorMessage.
 * @returns {{context: object, serverMessage: string}}
 */
function abj404AdminAjaxFailureContext(jqXHR, opts) {
    var context = {
        source: (typeof opts.source === 'string' && opts.source !== '') ? opts.source : 'admin-ajax',
        status: null,
        statusText: '',
        textStatus: (typeof opts.textStatus === 'string') ? opts.textStatus : '',
        errorThrown: (typeof opts.errorThrown === 'string') ? opts.errorThrown : '',
        responseExcerpt: '',
        shapeError: null,
        shown: ''
    };
    var serverMessage = '';

    // Every read of the response happens inside the guard: the object came
    // from the network by way of whatever else is on the page, so no property
    // on it is guaranteed safe to touch.
    try {
        if (jqXHR) {
            if (typeof jqXHR.status === 'number') {
                context.status = jqXHR.status;
            }
            if (typeof jqXHR.statusText === 'string') {
                context.statusText = jqXHR.statusText;
            }
            if (typeof jqXHR.responseText === 'string') {
                context.responseExcerpt =
                    jqXHR.responseText.substring(0, ABJ404_ADMIN_AJAX_EXCERPT_LIMIT);
            }
        }
        serverMessage = abj404AdminAjaxServerMessage(jqXHR);
    } catch (shapeError) {
        context.shapeError = (shapeError && shapeError.message)
            ? String(shapeError.message) : String(shapeError);
    }
    return { context: context, serverMessage: serverMessage };
}

/**
 * Record a failed admin AJAX request that has no message to show.
 *
 * For handlers whose failure is deliberately quiet on screen (an autocomplete
 * lookup that hands the widget an empty list, a list that renders its empty
 * state) but which still owe whoever diagnoses "the list is empty" the status
 * and body excerpt that explain it. Writes one console.warn (this is a
 * degraded lookup, not a broken page) and returns the context.
 *
 * @param {object} jqXHR
 * @param {object} options
 * @param {string} [options.source]      Call-site label.
 * @param {string} [options.textStatus]  jQuery's textStatus argument.
 * @param {string} [options.errorThrown] jQuery's errorThrown argument.
 * @returns {object}                     The recorded context.
 */
function abj404AdminAjaxRecordFailure(jqXHR, options) {
    var built = abj404AdminAjaxFailureContext(jqXHR, options || {});
    built.context.shown = built.serverMessage;
    if (window.console && typeof window.console.warn === 'function') {
        window.console.warn('404 Solution: admin AJAX request failed', built.context);
    }
    return built.context;
}

/**
 * Describe a failed admin AJAX request: return the best message to show, and
 * record the full failure context to the console on the way past.
 *
 * Both halves are deliberately one call. Every admin error handler needs the
 * message AND owes the underlying detail to whoever has to diagnose the
 * failure later, and leaving the second half to each call site is exactly how
 * two of them ended up catching the decode failure and commenting the empty
 * body "ignore and use generic msg": the response shape that broke was the
 * only evidence of why, and it was thrown away at the moment it was caught.
 *
 * When the server explains itself, that explanation is shown verbatim. When it
 * cannot -- an upstream gateway, WAF or proxy killing the request returns an
 * HTML error page, so there is no JSON to read at all -- the caller's fallback
 * carries the underlying code in parentheses, because an admin reporting
 * "it says error" cannot be helped and an admin reporting "it says HTTP 502 Bad
 * Gateway" can.
 *
 * @param {object} jqXHR                 The failed jQuery XHR.
 * @param {object} options
 * @param {string} options.fallback      Sentence to show when the server explained nothing.
 * @param {string} [options.source]      Call-site label, so a console entry names the workflow.
 * @param {string} [options.errorThrown] jQuery's errorThrown argument, when the handler has it.
 * @param {string} [options.textStatus]  jQuery's textStatus argument, when the handler has it.
 * @returns {string}                     The message to show the admin.
 */
function abj404AdminAjaxErrorMessage(jqXHR, options) {
    var opts = options || {};
    var built = abj404AdminAjaxFailureContext(jqXHR, opts);
    var context = built.context;
    var fallback = (typeof opts.fallback === 'string' && opts.fallback !== '')
        ? opts.fallback : 'The request failed.';

    if (built.serverMessage !== '') {
        context.shown = built.serverMessage;
    } else {
        var detail = abj404AdminAjaxFailureDetail(context);
        context.shown = fallback + ' (' + detail + ')';
    }

    if (window.console && typeof window.console.error === 'function') {
        window.console.error('404 Solution: admin AJAX request failed', context);
    }

    return context.shown;
}

/**
 * Record a failed fetch()-based admin request.
 *
 * The jQuery-free counterpart of abj404AdminAjaxRecordFailure(): the trend
 * chart and the migration preview use fetch(), where a failure arrives as a
 * Response (status, gateway page), a rejection (network TypeError, AbortError,
 * SyntaxError from a non-JSON body) or a well-formed JSON refusal. Every one of
 * them used to reduce to a fixed error string. One console.warn keeps the
 * evidence; nothing is added to the page.
 *
 * @param {string} source                 Call-site label.
 * @param {object} [details]
 * @param {number} [details.status]       HTTP status, when a response arrived.
 * @param {string} [details.statusText]
 * @param {string} [details.responseText] Body; only the first excerpt is kept.
 * @param {*}      [details.error]        The rejection / parse error.
 * @param {string} [details.serverMessage] Message from a JSON refusal.
 * @returns {object}                      The recorded context.
 */
function abj404AdminRecordFetchFailure(source, details) {
    var d = details || {};
    var context = {
        source: (typeof source === 'string' && source !== '') ? source : 'admin-fetch',
        status: (typeof d.status === 'number') ? d.status : null,
        statusText: (typeof d.statusText === 'string') ? d.statusText : '',
        responseExcerpt: (typeof d.responseText === 'string')
            ? d.responseText.substring(0, ABJ404_ADMIN_AJAX_EXCERPT_LIMIT) : '',
        serverMessage: (typeof d.serverMessage === 'string') ? d.serverMessage : '',
        errorName: '',
        errorMessage: ''
    };
    if (d.error) {
        try {
            context.errorName = (typeof d.error.name === 'string') ? d.error.name : '';
            context.errorMessage = (typeof d.error.message === 'string') ? d.error.message : String(d.error);
        } catch (readError) {
            context.errorMessage = 'unreadable error object';
        }
    }
    if (window.console && typeof window.console.warn === 'function') {
        window.console.warn('404 Solution: admin fetch request failed', context);
    }
    return context;
}

/**
 * Parse a fetch() Response as JSON, keeping the body when it is not JSON.
 *
 * `response.json()` rejects with a bare SyntaxError and has already consumed
 * the body, so a WAF block page or a PHP fatal's output was unrecoverable at the
 * point of failure. The Response is cloned first so the raw text can be read
 * on the failure path only; a healthy response costs one extra clone and no
 * console output. The original rejection is preserved for the caller.
 *
 * @param {Response} response
 * @param {string} source       Call-site label for the console entry.
 * @returns {Promise<*>}        Parsed JSON; rejects with the original error.
 */
function abj404AdminFetchJson(response, source) {
    var copy = null;
    var cloneFailure = '';
    try {
        copy = (response && typeof response.clone === 'function') ? response.clone() : null;
    } catch (cloneError) {
        // Parsing still proceeds on the original; only the failure record
        // loses its body, and it says why instead of showing an empty excerpt.
        copy = null;
        cloneFailure = '[response body unreadable: clone failed: '
            + ((cloneError && cloneError.message) ? String(cloneError.message) : String(cloneError)) + ']';
    }
    return response.json().catch(function (parseError) {
        var bodyPromise = (copy && typeof copy.text === 'function')
            ? copy.text() : Promise.resolve(cloneFailure);
        return bodyPromise.catch(function (bodyError) {
            // The body itself could not be read; say so in the record rather
            // than leaving an empty excerpt that looks like an empty response.
            return '[response body unreadable: '
                + ((bodyError && bodyError.message) ? String(bodyError.message) : String(bodyError)) + ']';
        }).then(function (bodyText) {
            abj404AdminRecordFetchFailure(source, {
                status: response.status,
                statusText: response.statusText,
                responseText: bodyText,
                error: parseError
            });
            throw parseError;
        });
    });
}
