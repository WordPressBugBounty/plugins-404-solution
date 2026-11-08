/**
 * Public table-flight admission policy for the pagination transport.
 *
 * A table owner may return a versioned pending envelope while another request
 * renders the shared result. This module owns that finite owner/follower
 * exchange: admission validation, token propagation, the bounded follow-up
 * schedule, and the one deadline shared by every follower. HTTP telemetry and
 * ordinary transport retries remain in view_updater_pagination_transport.js.
 *
 * Globals defined: abj404TableFlight.
 *
 * allow-no-test-found: covered by tests-js/view_updater_pagination_transport.test.js
 */
(function (global, abj404Module) {
    if (global.abj404ClientBuildRegistry) {
        global.abj404ClientBuildRegistry.register('table_flight', abj404Module);
    }
    abj404Module(global);
}(typeof window !== 'undefined' ? window : this, /* abj404-client-module:start */ function (global) {
    'use strict';

    var MAX_REQUESTS = 7;
    var FIRST_FOLLOW_UP_MS = 2000;
    var LATER_FOLLOW_UP_MS = 4000;

    /**
     * @param {string} code
     * @param {string} message
     * @returns {object}
     */
    function flightError(code, message) {
        var body = { success: false, data: { code: code, message: message } };
        return {
            status: 503,
            responseJSON: body,
            responseText: JSON.stringify(body)
        };
    }

    /**
     * Parse the versioned public admission envelope.
     *
     * @param {*} result
     * @returns {{state: string, token?: string, retryAfterMs?: number, error?: object}}
     */
    function admission(result) {
        if (!result || typeof result !== 'object' ||
                !Object.prototype.hasOwnProperty.call(result, 'tableFlight')) {
            return { state: 'absent' };
        }
        var flight = result.tableFlight;
        if (!flight || typeof flight !== 'object' || flight.state !== 'pending') {
            return {
                state: 'invalid',
                error: flightError(
                    'TABLE_FLIGHT_INVALID',
                    'Invalid table flight pending response (TABLE_FLIGHT_INVALID).'
                )
            };
        }
        var token = typeof flight.token === 'string' ? flight.token : '';
        if (flight.version !== 1 || !/^[0-9a-f]{64}$/.test(token) ||
                flight.retryAfterMs !== FIRST_FOLLOW_UP_MS) {
            return {
                state: 'invalid',
                error: flightError(
                    'TABLE_FLIGHT_INVALID',
                    'Invalid table flight pending response (TABLE_FLIGHT_INVALID).'
                )
            };
        }
        return { state: 'pending', token: token, retryAfterMs: FIRST_FOLLOW_UP_MS };
    }

    /**
     * Create the finite state for one table part.
     *
     * @param {object} options
     * @param {number} options.timeoutMs
     * @param {function(): number} options.now
     * @param {function(function(), number): number} options.setTimeout
     * @param {function(number): void} options.clearTimeout
     * @param {function(): boolean} options.shouldAbort
     * @param {function(): void} options.onAbort
     * @param {function(): void} options.onDeadline
     * @param {function(object): void} options.onFollowUp
     * @returns {object}
     */
    function create(options) {
        var state = 'ready';
        var token = '';
        var followUpCount = 0;
        var sentCount = 0;
        var deadlineAt = options.now() + options.timeoutMs;
        var deadlineTimer = null;
        var followUpTimer = null;
        var retryTimer = null;

        var clearTimer = function(timerName) {
            if (timerName !== null) {
                options.clearTimeout(timerName);
            }
        };
        var clearTimers = function() {
            clearTimer(deadlineTimer);
            clearTimer(followUpTimer);
            clearTimer(retryTimer);
            deadlineTimer = null;
            followUpTimer = null;
            retryTimer = null;
        };
        var settle = function() {
            if (state === 'settled') {
                return;
            }
            state = 'settled';
            clearTimers();
        };
        var abort = function() {
            if (state === 'settled') {
                return;
            }
            settle();
            options.onAbort();
        };
        var deadline = function() {
            if (state === 'settled') {
                return;
            }
            if (options.shouldAbort()) {
                abort();
                return;
            }
            settle();
            options.onDeadline();
        };
        var guardScheduledWork = function() {
            if (state === 'settled') {
                return false;
            }
            if (options.shouldAbort()) {
                abort();
                return false;
            }
            return true;
        };
        var armDeadline = function() {
            if (deadlineTimer !== null || state === 'settled') {
                return;
            }
            var remaining = deadlineAt - options.now(); // allow-direct-time: shared table-flight deadline
            if (remaining <= 0) {
                deadline();
                return;
            }
            deadlineTimer = options.setTimeout(function() {
                deadlineTimer = null;
                deadline();
            }, remaining);
        };
        var scheduleFollowUp = function() {
            if (sentCount >= MAX_REQUESTS) {
                deadline();
                return;
            }
            var delay = followUpCount === 1 ? FIRST_FOLLOW_UP_MS : LATER_FOLLOW_UP_MS;
            followUpTimer = options.setTimeout(function() {
                followUpTimer = null;
                if (guardScheduledWork()) {
                    options.onFollowUp({ token: token, followUpCount: followUpCount });
                }
            }, delay);
        };

        return {
            /** @returns {string} ready, waiting, or settled */
            state: function() { return state; },

            /**
             * Reserve one actual HTTP attempt and return its transport values.
             *
             * @returns {{timeoutMs: number, flightToken: string, followUpCount: number}|null}
             */
            beginAttempt: function() {
                if (state === 'settled') {
                    return null;
                }
                if (options.shouldAbort()) {
                    abort();
                    return null;
                }
                if (sentCount >= MAX_REQUESTS) {
                    deadline();
                    return null;
                }
                var timeoutMs = deadlineAt - options.now(); // allow-direct-time: every attempt spends the original part deadline
                if (timeoutMs <= 0) {
                    deadline();
                    return null;
                }
                sentCount++;
                return { timeoutMs: timeoutMs, flightToken: token, followUpCount: followUpCount };
            },

            /**
             * Admit each wire send, including retries inside the nonce wrapper.
             * The logical attempt already reserved its first wire; a repeated
             * beforeSend consumes another slot and cannot reset the deadline.
             *
             * @param {boolean} repeated
             * @returns {number|null} Remaining timeout, or null to cancel before sending.
             */
            admitWire: function(repeated) {
                if (!guardScheduledWork()) {
                    return null;
                }
                if (repeated && sentCount >= MAX_REQUESTS) {
                    deadline();
                    return null;
                }
                var timeoutMs = deadlineAt - options.now(); // allow-direct-time: all wire sends share one deadline, including the initial owner
                if (timeoutMs <= 0) {
                    deadline();
                    return null;
                }
                armDeadline();
                if (repeated) {
                    sentCount++;
                }
                return timeoutMs;
            },

            /**
             * Apply one HTTP success envelope and decide whether it is usable.
             *
             * @param {*} result
             * @returns {{state: string, error?: object}}
             */
            acceptResponse: function(result) {
                if (state === 'settled') {
                    return { state: 'settled' };
                }
                var parsed = admission(result);
                if (parsed.state === 'invalid') {
                    settle();
                    return parsed;
                }
                if (parsed.state !== 'pending') {
                    settle();
                    return { state: 'ready' };
                }
                state = 'waiting';
                token = parsed.token;
                followUpCount++;
                armDeadline();
                if (state === 'settled') {
                    return { state: 'deadline' };
                }
                scheduleFollowUp();
                return { state: 'pending' };
            },

            /** @returns {boolean} whether the current exchange awaits a table */
            isWaiting: function() { return state === 'waiting'; },

            /**
             * Keep a pending transport retry under the same part deadline.
             *
             * @param {number} delayMs
             * @param {function(): void} callback
             * @returns {void}
             */
            scheduleTransportRetry: function(delayMs, callback) {
                if (state !== 'waiting') {
                    return;
                }
                armDeadline();
                retryTimer = options.setTimeout(function() {
                    retryTimer = null;
                    if (guardScheduledWork()) {
                        callback();
                    }
                }, delayMs);
            },

            /** @returns {void} Mark a usable response or terminal transport error. */
            settle: settle,

            /** @returns {object} Structured protocol error factory for the caller. */
            error: flightError
        };
    }

    global.abj404TableFlight = {
        create: create,
        error: flightError
    };
} /* abj404-client-module:end */));
