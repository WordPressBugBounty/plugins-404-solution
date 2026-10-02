<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The one place that knows how to hand a finished HTTP response back to the
 * web server before PHP keeps working.
 *
 * FPM and LiteSpeed both hold the response body in a SAPI-level buffer until
 * the PHP request ends, which includes every `shutdown` callback WordPress and
 * every active plugin registered. Post-response work therefore delays the page
 * the visitor is waiting on unless the response is detached first, and on a
 * buffering SAPI "delays" means the browser shows nothing at all for the
 * duration.
 *
 * The preference order is fastcgi, then litespeed, then neither, matching
 * Symfony HttpFoundation's Response::send() (symfony/symfony#42293).
 * fastcgi_finish_request() is FPM-only: php-src deliberately disabled the alias
 * under the litespeed SAPI (commit ccf051c3), so on a LiteSpeed/LSAPI host the
 * FPM-only guard is a silent no-op and litespeed_finish_request() is the
 * equivalent. Both flush every response buffer themselves, so nothing here
 * touches the output-buffer stack; tearing down buffers this code does not own
 * is what ABJ_404_Solution_OutputBufferDrain exists to avoid.
 *
 * Selection lives here rather than in each caller because a caller that gets
 * the order wrong fails silently on exactly the SAPI it was meant to protect.
 * ABJ_404_Solution_AjaxResponseEmitter reads the selection from here and then
 * performs the call itself, because its call is wrapped in checkpoint
 * journaling and a diagnostic A/B skip this adapter has no business knowing
 * about.
 */
class ABJ_404_Solution_ResponseDetach {

    /** @var string No SAPI function on this host can detach the response. */
    const FUNCTION_NONE = 'none';

    /**
     * Which SAPI function, if any, can detach the response on this host.
     *
     * @return string One of 'fastcgi_finish_request', 'litespeed_finish_request',
     *                or self::FUNCTION_NONE.
     */
    public static function availableFunction(): string {
        if (function_exists('fastcgi_finish_request')) {
            return 'fastcgi_finish_request';
        }
        if (function_exists('litespeed_finish_request')) {
            return 'litespeed_finish_request';
        }
        return self::FUNCTION_NONE;
    }

    /**
     * Deliver the response now, so whatever runs next cannot delay it.
     *
     * Returns which function ran rather than a bare bool, so a caller can
     * record "this host has neither" as positive evidence instead of a gap.
     *
     * @return string The selected function name, or self::FUNCTION_NONE when
     *                the SAPI offers neither (in which case output is streamed
     *                as it is produced and nothing was held back to release).
     */
    public static function detach(): string {
        $selected = self::availableFunction();
        if ($selected === 'fastcgi_finish_request') {
            fastcgi_finish_request();
            return $selected;
        }
        if ($selected === 'litespeed_finish_request') {
            litespeed_finish_request();
            return $selected;
        }
        return self::FUNCTION_NONE;
    }
}
