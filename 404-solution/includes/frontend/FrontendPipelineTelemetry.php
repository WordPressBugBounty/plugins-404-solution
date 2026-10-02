<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pipeline-execution diagnostic emission adapter. Owns three concerns:
 *   1. Benchmark response headers (`abj404_benchmark_emit_headers`) for paths
 *      that bypass WordPress's normal send_headers.
 *   2. Redirect-lookup timing samples
 *      (`abj404_benchmark_record_redirect_lookup`) recorded in microsecond
 *      precision so the benchmark wp_load can build a histogram.
 *   3. Verbose line at the top of process404: written in full when the logger
 *      is in debug mode, held as a lean lazy breadcrumb otherwise.
 *
 * All three call into global functions that may or may not be defined
 * (benchmark wp_load is optional, debug logging is opt-in). The adapter
 * absorbs those defined/!defined checks.
 */
class ABJ_404_Solution_FrontendPipelineTelemetry {

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /**
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_Functions $functions
     */
    function __construct($logger, $functions) {
        $this->logger = $logger;
        $this->f = $functions;
    }

    /**
     * Emit benchmark header immediately for paths that may not reach
     * WordPress send_headers (early redirect, exit, etc).
     * @return void
     */
    function emitBenchmarkHeadersIfEnabled(): void {
        if (function_exists('abj404_benchmark_emit_headers')) {
            abj404_benchmark_emit_headers();
        }
    }

    /**
     * @param float $startTime
     * @return void
     */
    function recordRedirectLookupTiming($startTime): void {
        if (!function_exists('abj404_benchmark_record_redirect_lookup')) {
            return;
        }
        $elapsedMs = (abj_clock()->nowFloat() - (float)$startTime) * 1000.0;
        abj404_benchmark_record_redirect_lookup($elapsedMs);
    }

    /**
     * Verbose line at the top of process404: the decision inputs and outcome for
     * a 404 ("why did this not redirect").
     *
     * Debug mode on: the full line, including the visitor's user agent and
     * (hashed) address. Debug mode off: a lean line WITHOUT visitor identity is
     * held as a lazy breadcrumb, so it costs nothing (parse_url, json_encode,
     * MD5 are only run if an error later flushes it) but an ERROR raised while
     * handling this 404 still carries the trace that explains what led to it.
     *
     * @param array<string, mixed> $options
     * @param string $requestedURL
     * @param array<string, mixed> $redirect
     * @return void
     */
    function logAReallyLongDebugMessage(array $options, string $requestedURL, array $redirect): void {
        if (!$this->logger->isDebug()) {
            $this->logger->debugMessageLazy(
                fn(): string => $this->buildLeanProcess404Line($options, $requestedURL, $redirect)
            );
            return;
        }

        $debugOptionsMsg = $this->optionsSnapshot($options);

        $remoteAddressRaw = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        $remoteAddress = esc_sql($remoteAddressRaw);
        if (!is_string($remoteAddress)) {
            $remoteAddress = '';
        }
        if (!array_key_exists('log_raw_ips', $options) || $options['log_raw_ips'] != '1') {
            $remoteAddress = $this->f->md5lastOctet($remoteAddress);
        }

        $httpUserAgent = '';
        if (array_key_exists('HTTP_USER_AGENT', $_SERVER) && is_string($_SERVER['HTTP_USER_AGENT'])) {
            $httpUserAgent = $_SERVER['HTTP_USER_AGENT'];
        }

        $requestUriStr = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $debugServerMsg = esc_html('HTTP_USER_AGENT: ' . $httpUserAgent . ', REMOTE_ADDR: ' .
                $remoteAddress . ', REQUEST_URI: ' . abj_service('sanitizer')->normalizeUrlString($requestUriStr));
        $isSingle = $this->callWpFunction('is_single', array(), false);
        $isPage = $this->callWpFunction('is_page', array(), false);
        $isFeed = $this->callWpFunction('is_feed', array(), false);
        $isTrackback = $this->callWpFunction('is_trackback', array(), false);
        $isPreview = $this->callWpFunction('is_preview', array(), false);
        $redirectJson = json_encode($redirect);
        $this->logger->debugMessage('Processing 404 for URL: ' . $requestedURL . ' | Redirect: ' .
                wp_kses_post(is_string($redirectJson) ? $redirectJson : '{}') . ' | is_single(): ' . $isSingle . ' | ' . 'is_page(): ' . $isPage .
                ' | is_feed(): ' . $isFeed . ' | is_trackback(): ' . $isTrackback . ' | is_preview(): ' .
                $isPreview . ' | options: ' . $debugOptionsMsg . ', ' . $debugServerMsg);
    }

    /**
     * The decision-relevant option values, as one escaped string.
     *
     * @param array<string, mixed> $options
     * @return string
     */
    private function optionsSnapshot(array $options): string {
        $optAutoRedirects = isset($options['auto_redirects']) && is_scalar($options['auto_redirects']) ? (string)$options['auto_redirects'] : '';
        $optAutoScore = isset($options['auto_score']) && is_scalar($options['auto_score']) ? (string)$options['auto_score'] : '';
        $optTemplatePriority = isset($options['template_redirect_priority']) && is_scalar($options['template_redirect_priority']) ? (string)$options['template_redirect_priority'] : '';
        $optAutoCats = isset($options['auto_cats']) && is_scalar($options['auto_cats']) ? (string)$options['auto_cats'] : '';
        $optAutoTags = isset($options['auto_tags']) && is_scalar($options['auto_tags']) ? (string)$options['auto_tags'] : '';
        $optDest404 = isset($options['dest404page']) && is_scalar($options['dest404page']) ? (string)$options['dest404page'] : '';
        return esc_html('auto_redirects: ' . $optAutoRedirects . ', auto_score: ' .
                $optAutoScore . ', template_redirect_priority: ' . $optTemplatePriority .
                ', auto_cats: ' . $optAutoCats . ', auto_tags: ' .
                $optAutoTags . ', dest404page: ' . $optDest404);
    }

    /**
     * The process404 line without visitor identity (no user agent, no address).
     *
     * @param array<string, mixed> $options
     * @param string $requestedURL
     * @param array<string, mixed> $redirect
     * @return string
     */
    private function buildLeanProcess404Line(array $options, string $requestedURL, array $redirect): string {
        $redirectJson = json_encode($redirect);
        return 'Processing 404 for URL: ' . $requestedURL . ' | Redirect: ' .
            wp_kses_post(is_string($redirectJson) ? $redirectJson : '{}') .
            ' | is_single(): ' . $this->conditionalTag('is_single') .
            ' | is_page(): ' . $this->conditionalTag('is_page') .
            ' | is_feed(): ' . $this->conditionalTag('is_feed') .
            ' | options: ' . $this->optionsSnapshot($options);
    }

    /**
     * A WordPress conditional tag as a fixed word ('true'/'false'), or 'false'
     * when WordPress has not defined it.
     *
     * @param string $name
     * @return string
     */
    private function conditionalTag(string $name): string {
        return $this->callWpFunction($name, array(), false) ? 'true' : 'false';
    }

    /**
     * Safe wrapper for WordPress template/global functions. Returns $default
     * when the function does not exist (used during unit testing without WP loaded).
     *
     * @param string $name
     * @param array<int, mixed> $args
     * @param mixed $default
     * @return mixed
     */
    private function callWpFunction(string $name, array $args = array(), $default = null) {
        if (!function_exists($name)) {
            return $default;
        }
        return call_user_func_array($name, $args);
    }
}
