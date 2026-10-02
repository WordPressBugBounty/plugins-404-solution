/**
 * Engine Profiles Admin UI
 *
 * Handles loading, saving, and deleting engine profiles via AJAX.
 * Depends on: jQuery, abj404EngineProfiles (localized object).
 */
(function ($) {
    'use strict';

    if (typeof abj404EngineProfiles === 'undefined') {
        return;
    }

    var nonce    = abj404EngineProfiles.nonce;
    var ajaxUrl  = abj404EngineProfiles.ajaxUrl;
    var editingId = 0;

    // Corrupt enabled_engines (DB tampering, interrupted migration) must not
    // throw and must not vanish silently: fall back to the safe "all engines"
    // empty-array reading (matches the PHP-side fail-open behavior in
    // EngineProfileResolver::parseEnabledEngines) but log it so it is
    // diagnosable instead of masquerading as a normal empty profile.
    function parseEnabledEngines(rawValue, profileId) {
        try {
            return JSON.parse(rawValue || '[]');
        } catch (e) {
            if (window.console && window.console.warn) {
                window.console.warn('404 Solution: enabled_engines is not valid JSON for profile id ' + profileId, rawValue);
            }
            return [];
        }
    }

    // Message for a failure the server did not explain: the framing sentence
    // plus the underlying code in parentheses, never the bare sentence.
    // Delegates to the shared seam (abj404-admin-ajax.js), which also records
    // the failure to the console; when that asset did not load, the same shape
    // is composed here so the message keeps its cause either way.
    // `failure` is one object, not adjacent strings, so two of them cannot be
    // swapped silently: {{fallback: string, source: string, textStatus: string,
    // errorThrown: string}}.
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

    // `failure` is {{source: string, textStatus: string, errorThrown: string}}.
    function recordFailure(jqXHR, failure) {
        if (typeof abj404AdminAjaxRecordFailure === 'function') {
            abj404AdminAjaxRecordFailure(jqXHR, failure);
        } else if (window.console && window.console.warn) {
            var source = (typeof failure.source === 'string') ? failure.source : '';
            var textStatus = (typeof failure.textStatus === 'string') ? failure.textStatus : '';
            window.console.warn('404 Solution: ' + source + ' failed', textStatus);
        }
    }

    // ── Load profiles ───────────────────────────────────────────────────────

    function loadProfiles() {
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            // A request with no deadline never reaches the error handler that
            // re-enables the control this call disabled, so the page stays stuck.
            timeout: 30000,
            data: {
                action: 'abj404_engine_profiles_list',
                nonce:  nonce
            },
            success: function (resp, textStatus, jqXHR) {
                // Validate shape before reading fields; a malformed
                // body (non-JSON, plugin-conflict mangled output) must
                // not throw and leave the table on its empty-row state.
                if (!resp || typeof resp !== 'object' || resp.success !== true || !resp.data) {
                    recordFailure(jqXHR, {
                        source: 'engine-profiles-list-malformed',
                        textStatus: textStatus,
                        errorThrown: ''
                    });
                    renderProfiles([]);
                    return;
                }
                renderProfiles(resp.data.profiles || []);
            },
            error: function (jqXHR, textStatus, errorThrown) {
                // Transport failure: render the empty state so the
                // page does not appear stuck loading, and record why, so
                // "no profiles exist" is distinguishable from "the list
                // request failed".
                recordFailure(jqXHR, {
                    source: 'engine-profiles-list',
                    textStatus: textStatus,
                    errorThrown: errorThrown
                });
                renderProfiles([]);
            }
        });
    }

    function renderProfiles(profiles) {
        var $tbody  = $('#abj404-engine-profiles-tbody');
        var $empty  = $('#abj404-engine-profiles-empty-row');

        // Remove all data rows (keep empty-row placeholder).
        $tbody.find('tr[data-profile-id]').remove();

        if (!profiles || profiles.length === 0) {
            $empty.show();
            return;
        }

        $empty.hide();

        profiles.forEach(function (p) {
            var engines = parseEnabledEngines(p.enabled_engines, p.id);
            var engineLabels = engines.map(function (cls) {
                return cls.replace(/^ABJ_404_Solution_/, '').replace(/Engine$/, '').replace(/MatchingEngine$/, '');
            }).join(', ');

            var $row = $('<tr>')
                .attr('data-profile-id', p.id)
                .append($('<td>').text(p.name))
                .append($('<td>').text(p.url_pattern))
                .append($('<td>').text(p.is_regex === '1' || p.is_regex === 1 ? '✓' : ''))
                .append($('<td>').text(engineLabels || '(all)'))
                .append($('<td>').text(p.priority))
                .append($('<td>').text(p.status === '1' || p.status === 1 ? '✓' : ''))
                .append($('<td>').append(
                    $('<button>').addClass('button button-small abj404-edit-profile-btn').text(abj404EngineProfiles.i18n.edit).attr('data-id', p.id),
                    ' ',
                    $('<button>').addClass('button button-small abj404-delete-profile-btn').text(abj404EngineProfiles.i18n.delete).attr('data-id', p.id)
                ));

            $tbody.append($row);
        });
    }

    // ── Add / edit form ─────────────────────────────────────────────────────

    $(document).on('click', '#abj404-add-engine-profile-btn', function () {
        openForm(null);
    });

    $(document).on('click', '.abj404-edit-profile-btn', function () {
        var id = $(this).data('id');
        // Load from current row data
        var $row = $('tr[data-profile-id="' + id + '"]');
        // Re-fetch profile list to get full data
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            // A request with no deadline never reaches the error handler that
            // re-enables the control this call disabled, so the page stays stuck.
            timeout: 30000,
            data: {
                action: 'abj404_engine_profiles_list',
                nonce:  nonce
            },
            success: function (resp) {
                if (!resp || typeof resp !== 'object' || resp.success !== true || !resp.data) {
                    return;
                }
                var profiles = resp.data.profiles || [];
                var profile = null;
                profiles.forEach(function (p) { if (parseInt(p.id, 10) === parseInt(id, 10)) { profile = p; } });
                if (profile) { openForm(profile); }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                // Log transport failure to console; the edit click is
                // recoverable (the user can retry from the still-visible
                // row) so we do not need a blocking notice here.
                recordFailure(jqXHR, {
                    source: 'engine-profile-reload',
                    textStatus: textStatus,
                    errorThrown: errorThrown
                });
            }
        });
    });

    function openForm(profile) {
        var $form = $('#abj404-engine-profile-form-wrap');
        var $title = $('#abj404-engine-profile-form-title');

        // Reset
        $('#abj404-profile-id').val(0);
        $('#abj404-profile-name').val('');
        $('#abj404-profile-pattern').val('');
        $('#abj404-profile-is-regex').prop('checked', false);
        $('#abj404-profile-priority').val(0);
        $('#abj404-profile-status').prop('checked', true);
        $('.abj404-engine-cb').prop('checked', false);
        $('#abj404-engine-profile-save-msg').hide().text('');

        if (profile) {
            $title.text(abj404EngineProfiles.i18n.editProfile);
            editingId = parseInt(profile.id, 10);
            $('#abj404-profile-id').val(editingId);
            $('#abj404-profile-name').val(profile.name);
            $('#abj404-profile-pattern').val(profile.url_pattern);
            $('#abj404-profile-is-regex').prop('checked', profile.is_regex === '1' || profile.is_regex === 1);
            $('#abj404-profile-priority').val(profile.priority);
            $('#abj404-profile-status').prop('checked', profile.status === '1' || profile.status === 1);

            var engines = parseEnabledEngines(profile.enabled_engines, profile.id);
            engines.forEach(function (cls) {
                $('.abj404-engine-cb[value="' + cls + '"]').prop('checked', true);
            });
        } else {
            $title.text(abj404EngineProfiles.i18n.addProfile);
            editingId = 0;
        }

        $form.show();
    }

    $(document).on('click', '#abj404-cancel-engine-profile-btn', function () {
        $('#abj404-engine-profile-form-wrap').hide();
        editingId = 0;
    });

    // ── Save ────────────────────────────────────────────────────────────────

    $(document).on('click', '#abj404-save-engine-profile-btn', function () {
        var name     = $.trim($('#abj404-profile-name').val());
        var pattern  = $.trim($('#abj404-profile-pattern').val());
        var isRegex  = $('#abj404-profile-is-regex').is(':checked') ? 1 : 0;
        var priority = parseInt($('#abj404-profile-priority').val(), 10) || 0;
        var status   = $('#abj404-profile-status').is(':checked') ? 1 : 0;
        var id       = parseInt($('#abj404-profile-id').val(), 10) || 0;

        var engines = [];
        $('.abj404-engine-cb:checked').each(function () {
            engines.push($(this).val());
        });

        var $msg = $('#abj404-engine-profile-save-msg');
        $msg.hide().text('');

        if (!name) {
            $msg.text(abj404EngineProfiles.i18n.nameRequired).css('color', 'red').show();
            return;
        }
        if (!pattern) {
            $msg.text(abj404EngineProfiles.i18n.patternRequired).css('color', 'red').show();
            return;
        }

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            // A request with no deadline never reaches the error handler that
            // re-enables the control this call disabled, so the page stays stuck.
            timeout: 30000,
            data: {
                action:          'abj404_engine_profiles_save',
                nonce:           nonce,
                id:              id,
                name:            name,
                url_pattern:     pattern,
                is_regex:        isRegex,
                enabled_engines: JSON.stringify(engines),
                priority:        priority,
                status:          status
            },
            success: function (resp, textStatus, jqXHR) {
                // Validate shape before reading fields. A malformed body
                // (HTML error page from a WAF, plugin-conflict mangled
                // output) previously threw on resp.success and left the
                // form with no feedback at all.
                if (!resp || typeof resp !== 'object' || resp.success !== true) {
                    var message;
                    if (resp && typeof resp === 'object' && resp.data && resp.data.message) {
                        message = resp.data.message;
                    } else {
                        message = describeFailure(jqXHR, {
                            fallback: abj404EngineProfiles.i18n.saveFailed,
                            source: 'engine-profile-save-malformed',
                            textStatus: textStatus,
                            errorThrown: ''
                        });
                    }
                    $msg.text(message).css('color', 'red').show();
                    return;
                }
                $msg.text(abj404EngineProfiles.i18n.saved).css('color', 'green').show();
                setTimeout(function () {
                    $('#abj404-engine-profile-form-wrap').hide();
                    editingId = 0;
                    loadProfiles();
                }, 800);
            },
            error: function (jqXHR, textStatus, errorThrown) {
                // Transport failure: surface the save-failed message, with
                // the server's own explanation (e.g. "DB error: ...") or the
                // underlying status when it gave none, so the form is
                // recoverable (admin can retry) and the failure reportable.
                $msg.text(describeFailure(jqXHR, {
                    fallback: abj404EngineProfiles.i18n.saveFailed,
                    source: 'engine-profile-save',
                    textStatus: textStatus,
                    errorThrown: errorThrown
                })).css('color', 'red').show();
            }
        });
    });

    // ── Delete ──────────────────────────────────────────────────────────────

    $(document).on('click', '.abj404-delete-profile-btn', function () {
        var id = parseInt($(this).data('id'), 10);
        if (!id) { return; }

        if (!window.confirm(abj404EngineProfiles.i18n.confirmDelete)) {
            return;
        }

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            // A request with no deadline never reaches the error handler that
            // re-enables the control this call disabled, so the page stays stuck.
            timeout: 30000,
            data: {
                action: 'abj404_engine_profiles_delete',
                nonce:  nonce,
                id:     id
            },
            success: function (resp, textStatus, jqXHR) {
                // Validate shape before reading resp.success: a malformed
                // body previously threw on null/non-object responses.
                if (resp && typeof resp === 'object' && resp.success === true) {
                    loadProfiles();
                    return;
                }
                if (resp && typeof resp === 'object' && resp.data && resp.data.message) {
                    window.alert(resp.data.message);
                    return;
                }
                window.alert(describeFailure(jqXHR, {
                    fallback: abj404EngineProfiles.i18n.saveFailed,
                    source: 'engine-profile-delete-malformed',
                    textStatus: textStatus,
                    errorThrown: ''
                }));
            },
            error: function (jqXHR, textStatus, errorThrown) {
                window.alert(describeFailure(jqXHR, {
                    fallback: abj404EngineProfiles.i18n.saveFailed,
                    source: 'engine-profile-delete',
                    textStatus: textStatus,
                    errorThrown: errorThrown
                }));
            }
        });
    });

    // ── Init ────────────────────────────────────────────────────────────────

    $(function () {
        if ($('#abj404-engine-profiles-section').length) {
            loadProfiles();
        }
    });

})(jQuery);
