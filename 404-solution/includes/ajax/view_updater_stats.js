/**
 * Stats dashboard background-refresh and per-tab auto-refresh throttling.
 *
 * Lives next to the table refresh code but talks to a different endpoint
 * (ajaxRefreshStatsDashboard). It also owns the localStorage cooldown
 * helpers shared with the redirects-table flow:
 *
 *   - getStatsAutoRefreshCacheKey/getStatsAutoRefreshCooldownMs/shouldRunStatsAutoRefreshNow/markStatsAutoRefreshCompleted
 *     throttle ready/unavailable refreshes to once per 30s per tab/subpage key;
 *     pending publication checks use a 2.5s cadence.
 *   - getAutoRefreshCacheKey/shouldRunAutoRefreshNow/markAutoRefreshCompleted
 *     throttle the redirects-table detect-only refresh to once per 30s
 *     per tab/sort/filter key.
 *
 * Plus setRefreshStatus/clearRefreshStatus, the small DOM helpers used to
 * populate the inline `.abj404-refresh-status` element with status text.
 *
 * Globals defined: getStatsRefreshConfigHost, getStatsAutoRefreshCacheKey, getStatsAutoRefreshCooldownMs,
 * shouldRunStatsAutoRefreshNow, markStatsAutoRefreshCompleted,
 * getStatsRefreshDelayMs, triggerStatsBackgroundRefreshIfEnabled, setRefreshStatus, clearRefreshStatus,
 * getAutoRefreshCacheKey, shouldRunAutoRefreshNow, markAutoRefreshCompleted.
 *
 * Depends on view_updater.js (getURLParameter) and
 * view_updater_refresh_pill.js (showRefreshAvailablePill).
 */

function getStatsRefreshConfigHost() {
    return jQuery('.abj404-stats-refresh-config').first();
}

function getStatsAutoRefreshCacheKey($config) {
    var page = getURLParameter('page') || '';
    var subpage = ($config && $config.attr) ? ($config.attr('data-stats-refresh-subpage') || getURLParameter('subpage') || 'abj404_stats') : (getURLParameter('subpage') || 'abj404_stats');
    return 'abj404:stats_auto_refresh:' + [page, subpage].join(':');
}

/**
 * Return the bounded remaining stats-refresh cooldown for this tab.
 *
 * A missing, malformed, or unreadable timestamp is fail-open: the caller may
 * refresh, but storage failures are visible in the browser console.
 * @param {jQuery} $config
 * @param {string} [state]
 * @return {number}
 */
function getStatsAutoRefreshCooldownMs($config, state) {
    var cooldownMs = state === 'pending' ? 2500 : 30000;
    try {
        if (!window.localStorage) {
            return 0;
        }
        var key = getStatsAutoRefreshCacheKey($config);
        var rawTimestamp = localStorage.getItem(key);
        if (rawTimestamp === null || rawTimestamp === '') {
            return 0;
        }
        var lastTimestamp = Number(rawTimestamp);
        if (!/^[0-9]+$/.test(rawTimestamp) || !Number.isSafeInteger(lastTimestamp) || lastTimestamp <= 0) {
            if (window.console && window.console.warn) {
                window.console.warn('404 Solution: getStatsAutoRefreshCooldownMs found malformed localStorage timestamp', rawTimestamp);
            }
            return 0;
        }
        var now = Date.now(); // allow-direct-time: cooldown compares a stamp stored by an earlier page load, so it must be the wall clock; tests drive it with fake timers
        if (lastTimestamp > now) {
            // A clock moved backwards or a stale page wrote a future value;
            // fail open once and let the next admission stamp current time.
            return 0;
        }
        return Math.min(cooldownMs, Math.max(0, cooldownMs - (now - lastTimestamp)));
    } catch (e) {
        if (window.console && window.console.warn) {
            window.console.warn('404 Solution: getStatsAutoRefreshCooldownMs failed to read localStorage', e);
        }
        return 0;
    }
}

function shouldRunStatsAutoRefreshNow($config, state) {
    return getStatsAutoRefreshCooldownMs($config, state) === 0;
}

/**
 * Write the legacy stats-refresh timestamp used for both admission and
 * response settlement.
 * @param {jQuery} $config
 * @return {void}
 */
function markStatsAutoRefreshCompleted($config) {
    try {
        if (!window.localStorage) {
            return;
        }
        var key = getStatsAutoRefreshCacheKey($config);
        localStorage.setItem(key, String(Date.now()));
    } catch (e) {
        // Best-effort cooldown timestamp; storage failures must not block
        // the page. Log so a real bug here is diagnosable instead of
        // invisible.
        if (window.console && window.console.warn) {
            window.console.warn('404 Solution: markStatsAutoRefreshCompleted failed to write localStorage', e);
        }
    }
}

/**
 * Return the remaining freshness delay from the server-provided snapshot pair.
 * @param {jQuery} $config
 * @return {number}
 */
function getStatsRefreshDelayMs($config) {
    var snapshotAtRaw = $config.attr('data-stats-refresh-snapshot-at');
    var serverNowRaw = $config.attr('data-stats-refresh-server-now');
    if (typeof snapshotAtRaw !== 'string' || !/^[0-9]+$/.test(snapshotAtRaw)
        || typeof serverNowRaw !== 'string' || !/^[0-9]+$/.test(serverNowRaw)) {
        return 0;
    }
    var snapshotAt = Number(snapshotAtRaw);
    var serverNow = Number(serverNowRaw);
    if (!Number.isSafeInteger(snapshotAt) || snapshotAt <= 0 || !Number.isSafeInteger(serverNow) || serverNow <= 0) {
        return 0;
    }
    var ageMs = Math.max(0, (serverNow - snapshotAt) * 1000);
    return Math.min(30000, Math.max(0, 30000 - ageMs));
}

function triggerStatsBackgroundRefreshIfEnabled() {
    var $config = getStatsRefreshConfigHost();
    if ($config.length === 0) {
        return;
    }
    if ($config.attr('data-stats-refresh-enabled') !== '1') {
        return;
    }
    var renderedState = $config.attr('data-stats-refresh-state') || 'ready';
    // A cooldown from a prior ready page must not strand an unpublished page.
    if (renderedState === 'ready' && !shouldRunStatsAutoRefreshNow($config, renderedState)) {
        return;
    }
    if (window.abj404StatsRefreshTriggered) {
        return;
    }
    window.abj404StatsRefreshTriggered = true;

    var nonce = $config.attr('data-stats-refresh-nonce') || '';
    if (nonce === '') {
        return;
    }

    window.abj404StatsBackgroundRefreshState = {
        enabled: true,
        startedAt: Date.now(),
        finishedAt: null,
        difference: null,
        durationMs: null,
        lastStatusCode: null,
        hasUpdateAvailable: false,
        lastError: null,
        state: renderedState,
        work: {kind: 'settled'}
    };
    var setStatsWork = function(kind, dueAt) {
        if (!window.abj404StatsBackgroundRefreshState) {
            return;
        }
        window.abj404StatsBackgroundRefreshState.work = kind === 'scheduled'
            ? {kind: kind, dueAt: dueAt}
            : {kind: kind};
    };

    var action = $config.attr('data-stats-refresh-action') || 'ajaxRefreshStatsDashboard';
    var refreshAvailableText = $config.attr('data-stats-refresh-available-text') || 'Refresh available';
    var currentHash = $config.attr('data-stats-refresh-current-hash') || '';

    var runRefresh = function() {
        var refreshState = window.abj404StatsBackgroundRefreshState.state || renderedState;
        var remainingCooldownMs = getStatsAutoRefreshCooldownMs($config, refreshState);
        if (remainingCooldownMs > 0) {
            setStatsWork('scheduled', Date.now() + remainingCooldownMs);
            setTimeout(runRefresh, remainingCooldownMs);
            return;
        }
        // Stamp admission, not response completion, so pending/unavailable
        // responses cannot admit a burst of duplicate requests.
        markStatsAutoRefreshCompleted($config);
        setStatsWork('requesting');
        var startMs = Date.now(); // allow-direct-time: AJAX wall-clock duration baseline; browser admin script, no client-side clock adapter exists in this plugin
        var statsAjaxRunner = (typeof abj404AjaxWithNonceRetry === 'function')
            ? abj404AjaxWithNonceRetry : jQuery.ajax;
        statsAjaxRunner({
            url: window.ajaxurl || 'admin-ajax.php',
            type: 'POST',
            dataType: 'json',
            data: {
                action: action,
                page: getURLParameter('page') || '',
                subpage: getURLParameter('subpage') || 'abj404_stats',
                nonce: nonce,
                currentHash: currentHash
            },
            timeout: 25000,
            success: function(result) {
                var payload = result;
                if (payload && payload.data && typeof payload.data === 'object' && payload.success === false) {
                    payload = payload.data;
                }
                var state = payload && payload.state || 'ready';
                var pending = state === 'pending';
                var notReady = state !== 'ready';
                var hasUpdate = !!(payload && payload.hasUpdate && !notReady);
                if (state === 'unavailable' && payload.error && typeof payload.error.message === 'string') {
                    setRefreshStatus($config, payload.error.message + ' (' + String(payload.error.code) + ')');
                } else if (!notReady) {
                    clearRefreshStatus($config);
                }
                if (hasUpdate) {
                    showRefreshAvailablePill(refreshAvailableText, 5000);
                }
                if (payload && payload.hash && !notReady) {
                    $config.attr('data-stats-refresh-current-hash', payload.hash);
                }
                if (window.abj404StatsBackgroundRefreshState) {
                    var duration = Date.now() - startMs;
                    window.abj404StatsBackgroundRefreshState.finishedAt = Date.now();
                    window.abj404StatsBackgroundRefreshState.durationMs = duration;
                    window.abj404StatsBackgroundRefreshState.difference = duration;
                    window.abj404StatsBackgroundRefreshState.lastStatusCode = 200;
                    window.abj404StatsBackgroundRefreshState.hasUpdateAvailable = hasUpdate;
                    window.abj404StatsBackgroundRefreshState.state = state;
                    window.abj404StatsBackgroundRefreshState.generation = payload && payload.generation || '';
                }
                if (notReady) {
                    // Detect publication through the same cheap endpoint without changing the rendered DOM.
                    var retryDelayMs = pending ? 2500 : 30000;
                    setStatsWork('scheduled', Date.now() + retryDelayMs);
                    setTimeout(runRefresh, retryDelayMs);
                    return;
                }
                markStatsAutoRefreshCompleted($config);
                setStatsWork('settled');
            },
            error: function(xhr, textStatus, errorThrown) {
                // Keep failure details available to live-page diagnostics.
                // Publication consumers read only the work state to avoid
                // replacing a document while its refresh is still active.
                if (typeof abj404AdminAjaxRecordFailure === 'function') {
                    abj404AdminAjaxRecordFailure(xhr, {
                        source: 'stats-background-refresh',
                        textStatus: textStatus,
                        errorThrown: errorThrown
                    });
                } else if (window.console && window.console.warn) {
                    window.console.warn('404 Solution: stats-background-refresh failed', {
                        status: xhr && typeof xhr.status === 'number' ? xhr.status : null,
                        textStatus: textStatus,
                        errorThrown: errorThrown
                    });
                }
                if (window.abj404StatsBackgroundRefreshState) {
                    var duration = Date.now() - startMs;
                    window.abj404StatsBackgroundRefreshState.finishedAt = Date.now();
                    window.abj404StatsBackgroundRefreshState.durationMs = duration;
                    window.abj404StatsBackgroundRefreshState.difference = duration;
                    window.abj404StatsBackgroundRefreshState.lastStatusCode = xhr ? xhr.status : null;
                    window.abj404StatsBackgroundRefreshState.lastError = textStatus || errorThrown || 'ajax-error';
                    window.abj404StatsBackgroundRefreshState.hasUpdateAvailable = false;
                }
                markStatsAutoRefreshCompleted($config);
                setStatsWork('settled');
            }
        });
    };

    var scheduleRefresh = function() {
        if (typeof window.requestIdleCallback === 'function') {
            // The idle callback may run immediately or at its native timeout;
            // treat it as due now so navigation observes the earliest safe edge.
            setStatsWork('scheduled', Date.now());
            window.requestIdleCallback(runRefresh, {timeout: 2000});
        } else {
            setStatsWork('scheduled', Date.now() + 900);
            setTimeout(runRefresh, 900);
        }
    };
    var refreshDelayMs = Math.max(getStatsRefreshDelayMs($config), getStatsAutoRefreshCooldownMs($config, renderedState));
    if (refreshDelayMs > 0) {
        setStatsWork('scheduled', Date.now() + refreshDelayMs);
        setTimeout(scheduleRefresh, refreshDelayMs);
    } else {
        scheduleRefresh();
    }
}

function setRefreshStatus($config, message) {
    if (!$config || $config.length === 0) {
        return;
    }
    var $status = $config.find('.abj404-refresh-status').first();
    if ($status.length === 0) {
        $status = jQuery('<span class="abj404-refresh-status" aria-live="polite"></span>');
        $config.append($status);
    }
    if ($status.length > 0) {
        $status.text(message || '');
    }
}

function clearRefreshStatus($config) {
    setRefreshStatus($config, '');
}

function getAutoRefreshCacheKey($config) {
    var page = getURLParameter('page') || '';
    var subpage = ($config && $config.attr) ? ($config.attr('data-pagination-ajax-subpage') || '') : '';
    var filter = ($config && $config.attr) ? ($config.attr('data-pagination-current-filter') || '') : '';
    if (filter === '') {
        filter = getURLParameter('filter') || '0';
    }
    var orderby = ($config && $config.attr) ? ($config.attr('data-pagination-current-orderby') || '') : '';
    if (orderby === '') {
        orderby = getURLParameter('orderby') || '';
    }
    var order = ($config && $config.attr) ? ($config.attr('data-pagination-current-order') || '') : '';
    if (order === '') {
        order = getURLParameter('order') || '';
    }
    return 'abj404:auto_refresh:' + [page, subpage, filter, orderby, order].join(':');
}

function shouldRunAutoRefreshNow($config) {
    try {
        if (!window.localStorage) {
            return true;
        }
        var key = getAutoRefreshCacheKey($config);
        var lastTs = parseInt(localStorage.getItem(key) || '0', 10);
        var cooldownMs = 30000; // 30s throttle per tab/sort/filter key
        return !(lastTs > 0 && (Date.now() - lastTs) < cooldownMs); // allow-direct-time: cooldown compares a stamp stored by an earlier page load, so it must be the wall clock; tests drive it with fake timers
    } catch (e) {
        // An unreadable cooldown degrades open: refreshing more often than
        // every 30s costs one request, a throttle stuck shut would hide new
        // data. Log so a real bug here is diagnosable instead of invisible.
        if (window.console && window.console.warn) {
            window.console.warn('404 Solution: shouldRunAutoRefreshNow failed to read localStorage', e);
        }
        return true;
    }
}

function markAutoRefreshCompleted($config) {
    try {
        if (!window.localStorage) {
            return;
        }
        var key = getAutoRefreshCacheKey($config);
        localStorage.setItem(key, String(Date.now()));
    } catch (e) {
        // Best-effort cooldown timestamp; storage failures must not block
        // the page. Log so a real bug here is diagnosable instead of
        // invisible.
        if (window.console && window.console.warn) {
            window.console.warn('404 Solution: markAutoRefreshCompleted failed to write localStorage', e);
        }
    }
}
