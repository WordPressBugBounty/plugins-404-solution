/**
 * 404 Solution admin ready signal: the `abj404.adminReady` action.
 *
 * Every 404 Solution admin page fires, exactly once per page load,
 *
 *     wp.hooks.doAction('abj404.adminReady', { page: 'abj404_solution', subpage: 'abj404_redirects' })
 *
 * after the page's scripts have bound their handlers and the page's first
 * asynchronous render has settled (the redirects/captured/logs table load, the
 * health bar, the Google Search Console settings section, the engine profiles
 * list, the stats trend charts), whether that render succeeded or failed.
 * `subpage` is the screen being shown: one of the `abj404_*` tab slugs, or
 * `abj404_edit` for the edit-redirect screen.
 *
 * It is a public extension point. Another plugin, or a site's own admin
 * script, can hook it to decorate the plugin's screens without racing the
 * plugin's own rendering:
 *
 *     wp.hooks.addAction('abj404.adminReady', 'my-plugin/abj404', function (ctx) {
 *         if (ctx.subpage === 'abj404_redirects') { ... }
 *     });
 *
 * A script that loads after the action fired can check
 * `wp.hooks.didAction('abj404.adminReady')` and read the payload from
 * `window.ABJ404.adminReady.context()`. The end-to-end test driver
 * (test/utils/AdminPageDriver.js) waits on the same signal before it touches
 * a page.
 *
 * When "handlers are bound": the coordinator closes from a DOMContentLoaded
 * listener that defers through `setTimeout(0)` and then `jQuery(fn)`. The
 * plugin's page scripts bind from vanilla DOMContentLoaded listeners or from
 * jQuery ready handlers. Under jQuery 1.12 (WordPress 5.0 to 5.5) ready
 * handlers run synchronously inside jQuery's own DOMContentLoaded listener,
 * which was registered before this one because jquery loads first; under
 * jQuery 3 (WordPress 5.6+) each ready handler runs in its own timer queued
 * in registration order, all of them queued before this listener's timer.
 * Either way the close runs after every page handler has run, so no page
 * script needs to call anything to say "my handlers are bound".
 *
 * When "the first render settled": a page script that starts first-render
 * work from its ready code takes a hold and releases it on every terminal
 * outcome:
 *
 *     var releaseReady = (window.ABJ404 && window.ABJ404.adminReady)
 *         ? window.ABJ404.adminReady.hold('table') : function () {};
 *     ...  // in success, error and abort paths:
 *     releaseReady();
 *
 * The guarded accessor keeps each page script working where this coordinator
 * is absent (unit tests that load one script, a page that does not enqueue
 * it). A hold taken after the action fired is a no-op, so code shared between
 * first render and later refreshes can take holds unconditionally.
 *
 * Globals defined: window.ABJ404.adminReady.
 */
(function (window, document) {
    'use strict';

    var ACTION = 'abj404.adminReady';
    var ns = window.ABJ404 = window.ABJ404 || {};
    if (ns.adminReady) {
        return;
    }

    /** @type {Object<string, number>} name -> outstanding holds under that name */
    var held = {};
    var heldCount = 0;
    var closed = false;
    var fired = false;
    var payload = null;

    /**
     * @param {*} raw The localized abj404AdminReadyContext object.
     * @returns {{page: string, subpage: string}}
     */
    function normalizeContext(raw) {
        var page = (raw && typeof raw.page === 'string') ? raw.page : '';
        var subpage = (raw && typeof raw.subpage === 'string') ? raw.subpage : '';
        return { page: page, subpage: subpage };
    }

    function fireIfSettled() {
        if (fired || !closed || heldCount > 0) {
            return;
        }
        fired = true;
        payload = normalizeContext(window.abj404AdminReadyContext);
        var hooks = window.wp && window.wp.hooks;
        if (!hooks || typeof hooks.doAction !== 'function') {
            if (window.console && typeof window.console.warn === 'function') {
                window.console.warn('404 Solution: wp.hooks is not loaded, so ' + ACTION +
                    ' cannot fire (the wp-hooks script dependency did not load).');
            }
            return;
        }
        try {
            hooks.doAction(ACTION, { page: payload.page, subpage: payload.subpage });
        } catch (callbackError) {
            // A throwing third-party callback must not escape into the page
            // script whose release() happened to fire the action.
            if (window.console && typeof window.console.error === 'function') {
                window.console.error('404 Solution: an ' + ACTION + ' callback threw', callbackError);
            }
        }
    }

    /**
     * Keep the ready action from firing until the returned release runs.
     *
     * @param {string} name Short label for the held work, reported by outstanding().
     * @returns {function(): void} Idempotent release.
     */
    function hold(name) {
        if (fired) {
            return function () {};
        }
        var label = (typeof name === 'string' && name !== '') ? name : 'unnamed';
        held[label] = (held[label] || 0) + 1;
        heldCount++;
        var released = false;
        return function () {
            if (released) {
                return;
            }
            released = true;
            held[label]--;
            if (held[label] === 0) {
                delete held[label];
            }
            heldCount--;
            fireIfSettled();
        };
    }

    /** @returns {string[]} Names of the holds still outstanding. */
    function outstanding() {
        var names = [];
        Object.keys(held).forEach(function (label) {
            for (var i = 0; i < held[label]; i++) {
                names.push(label);
            }
        });
        return names;
    }

    /** Mark the page's handlers as bound; fires now if nothing is held. */
    function close() {
        closed = true;
        fireIfSettled();
    }

    ns.adminReady = {
        hold: hold,
        outstanding: outstanding,
        close: close,
        /** @returns {boolean} Whether the action has fired. */
        isReady: function () { return fired; },
        /** @returns {{page: string, subpage: string}|null} The fired payload, or null before it fires. */
        context: function () {
            return payload === null ? null : { page: payload.page, subpage: payload.subpage };
        }
    };

    function closeAfterPageHandlers() {
        window.setTimeout(function () {
            if (typeof window.jQuery === 'function') {
                window.jQuery(close);
                return;
            }
            close();
        }, 0);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', closeAfterPageHandlers);
    } else {
        closeAfterPageHandlers();
    }
})(window, document);
