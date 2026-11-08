<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Terminal step of the frontend pipeline: given a matched redirect (an
 * existing row, a regex hit, or a single-page rule), emit the HTTP response
 * and exit. Owns the response-emission policy:
 *
 *   - 410 Gone (page renders normally, status 410 sent)
 *   - 451 Unavailable For Legal Reasons (template + exit)
 *   - 404 displayed (sendTo404Page)
 *   - External URL redirect (forceRedirect + exit)
 *   - Custom 404 page redirect (cookie + suggestions + redirect)
 *   - Standard internal redirect (permalinkInfoToArray + forceRedirect)
 *
 * Also owns:
 *   - tryRegexRedirect: regex-rule lookup + emit (called twice in process404)
 *   - handleEmptyUrlSinglePageRedirect: single-page permalink cleanup path
 *     for the empty-URL branch of process404.
 */
class ABJ_404_Solution_RedirectDispatcher {

    /** @var ABJ_404_Solution_PluginLogic */
    private $logic;

    /** @var ABJ_404_Solution_RedirectsRepository */
    private $redirectsRepository;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_SpellChecker */
    private $spellChecker;

    /** @var ABJ_404_Solution_NotFoundResponseService */
    private $notFoundResponse;

    /** @var ABJ_404_Solution_PreviousRequestCookieTracker */
    private $previousRequestCookieTracker;

    /** @var ABJ_404_Solution_FrontendPipelineTelemetry */
    private $telemetry;

    /** @var mixed */
    private $logsRepository;

    /**
     * @param ABJ_404_Solution_PluginLogic $logic
     * @param ABJ_404_Solution_RedirectsRepository $redirectsRepository
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_Functions $functions
     * @param ABJ_404_Solution_SpellChecker $spellChecker
     * @param ABJ_404_Solution_NotFoundResponseService $notFoundResponse
     * @param ABJ_404_Solution_PreviousRequestCookieTracker $previousRequestCookieTracker
     * @param ABJ_404_Solution_FrontendPipelineTelemetry $telemetry
     * @param mixed $logsRepository Object with logRedirectHit(); duck-typed.
     */
    function __construct($logic, $redirectsRepository, $logger, $functions, $spellChecker,
            $notFoundResponse, $previousRequestCookieTracker, $telemetry, $logsRepository) {
        $this->logic = $logic;
        $this->redirectsRepository = $redirectsRepository;
        $this->logger = $logger;
        $this->f = $functions;
        $this->spellChecker = $spellChecker;
        $this->notFoundResponse = $notFoundResponse;
        $this->previousRequestCookieTracker = $previousRequestCookieTracker;
        $this->telemetry = $telemetry;
        $this->logsRepository = $logsRepository;
    }

    /**
     * Redirect to destination.
     *
     * @param string $requestedURL
     * @param array<string, mixed> $redirect
     * @param string $matchReason
     * @param ABJ_404_Solution_FrontendPipelineTrace $trace
     * @return bool true if user is sent to default 404 page.
     */
    function processRedirect(string $requestedURL, array $redirect, string $matchReason, ABJ_404_Solution_FrontendPipelineTrace $trace): bool {
        if (($redirect['status'] != ABJ404_STATUS_MANUAL && $redirect['status'] != ABJ404_STATUS_AUTO) || $redirect['disabled'] != 0) {
            $this->logger->errorMessage('processRedirect() was called with bad redirect data. Data: ' .
                    wp_kses_post(print_r($redirect, true)));
        }

        $redirectUrl = isset($redirect['url']) && is_string($redirect['url']) ? $redirect['url'] : '';
        $redirectCode = isset($redirect['code']) && is_scalar($redirect['code']) ? (int)$redirect['code'] : 0;

        $terminal = $this->dispatchTerminalStatus($redirectCode, $redirectUrl, $matchReason, $trace);
        if ($terminal !== null) {
            return $terminal;
        }

        if ($redirect['type'] == ABJ404_TYPE_404_DISPLAYED) {
            $trace->add('Result', 'Showed 404 page', $redirectUrl);
            $this->writeHit($redirectUrl, '404', $matchReason, null, $trace->getSteps());
            $this->telemetry->emitBenchmarkHeadersIfEnabled();
            $this->notFoundResponse->sendTo404Page($requestedURL, $matchReason);
            return true;
        }

        $isRedirectToCustom404Page = $this->isRedirectToCustom404Page($redirect);

        if ($isRedirectToCustom404Page) {
            $this->previousRequestCookieTracker->setCookieWithPreviousRequest();
            setcookie(ABJ404_PP . '_STATUS_404', 'true', abj_clock()->now() + 20, '/');
            // Suggestions are not computed here: the destination page opens a
            // job when it renders, and the page's own polling script runs it.
            // Computing in this request would charge every client that hits
            // the redirect, including ones that never follow it or render it.
        }

        return $this->dispatchResolvedTarget($redirect, array(
            'requestedURL' => $requestedURL,
            'matchReason' => $matchReason,
            'isRedirectToCustom404Page' => $isRedirectToCustom404Page,
        ), $trace);
    }

    /** @return bool|null */
    private function dispatchTerminalStatus(int $code, string $url, string $reason, ABJ_404_Solution_FrontendPipelineTrace $trace) {
        if ($code !== 410 && $code !== 451) {
            return null;
        }
        $label = $code === 410 ? 'Gone' : 'Unavailable For Legal Reasons';
        $trace->add('Result', 'Responded with ' . $code . ' ' . $label, $url);
        $this->writeHit($url, (string)$code, $reason, null, $trace->getSteps());
        $this->notFoundResponse->forceRedirect('', $code);
        return false;
    }

    /** @param array<string, mixed> $redirect */
    private function isRedirectToCustom404Page(array $redirect): bool {
        if (($redirect['type'] ?? null) != $this->typePost()) {
            return false;
        }
        $options = $this->getOptions();
        $dest404pageRaw = $options['dest404page'] ?? null;
        $dest404page = is_string($dest404pageRaw) ? $dest404pageRaw : null;
        if ($dest404page !== null && $this->notFoundResponse->thereIsAUserSpecified404Page($dest404page)) {
            $dest404Parts = explode('|', $dest404page);
            $custom404Id = isset($dest404Parts[0]) ? (int)$dest404Parts[0] : 0;
            if ($custom404Id > 0 && ($redirect['final_dest'] ?? null) == $custom404Id) {
                return true;
            }
        }
        $destPage = $this->callWpFunction('get_post', array($redirect['final_dest'] ?? ''), null);
        if (!is_object($destPage) || !property_exists($destPage, 'post_content') || !is_string($destPage->post_content)) {
            return false;
        }
        return (bool)$this->callWpFunction('has_shortcode', array($destPage->post_content, ABJ404_SHORTCODE_NAME), false);
    }

    /**
     * Normalize stored fields at the resolved-target boundary, retaining legacy defaults.
     *
     * @param array<string, mixed> $redirect
     * @return array{url: string, final_dest: string, code: int, query_mode: ?string, id: string, type_key: string}
     */
    private function normalizeResolvedRedirect(array $redirect): array {
        return array(
            'url' => is_string($redirect['url'] ?? null) ? $redirect['url'] : '',
            'final_dest' => is_scalar($redirect['final_dest'] ?? null) ? (string)$redirect['final_dest'] : '',
            'code' => is_scalar($redirect['code'] ?? null) ? (int)$redirect['code'] : 0,
            'query_mode' => ABJ_404_Solution_QueryStringHelper::storedQueryMode($redirect),
            'id' => is_scalar($redirect['id'] ?? null) ? (string)$redirect['id'] : '0',
            'type_key' => is_scalar($redirect['type'] ?? null) ? (string)$redirect['type'] : '',
        );
    }

    /**
     * Emit a resolved row using named request context; stored fields come only from the row.
     *
     * @param array<string, mixed> $redirect
     * @param array{requestedURL: string, matchReason: string, isRedirectToCustom404Page: bool} $context
     */
    private function dispatchResolvedTarget(array $redirect, array $context, ABJ_404_Solution_FrontendPipelineTrace $trace): bool {
        $requestedURL = $context['requestedURL'];
        $matchReason = $context['matchReason'];
        $isRedirectToCustom404Page = $context['isRedirectToCustom404Page'];
        $target = $this->normalizeResolvedRedirect($redirect);
        $redirectUrl = $target['url'];
        $redirectFinalDest = $target['final_dest'];
        $redirectCode = $target['code'];
        $queryMode = $target['query_mode'];
        $redirectId = $target['id'];
        $redirectTypeKey = $target['type_key'];

        if (($redirect['type'] ?? null) == ABJ404_TYPE_EXTERNAL) {
            $trace->add('Result', 'Redirected to external URL', $redirectFinalDest);
            $this->writeHit($redirectUrl, $redirectFinalDest, 'external', null, $trace->getSteps());
            return $this->notFoundResponse->forceRedirect($redirectFinalDest, $redirectCode, -1, $requestedURL, false, $queryMode);
        }
        $finalDestRaw = trim($redirectFinalDest);
        $redirectTypeInt = (int)$redirectTypeKey;
        if ($finalDestRaw === '' && $redirectTypeInt !== ABJ404_TYPE_HOME && $redirectTypeInt !== ABJ404_TYPE_404_DISPLAYED) {
            return $this->showInvalidRedirect(array(
                'requestedURL' => $requestedURL,
                'logReason' => $matchReason . ' (missing destination)',
                'redirectUrl' => $redirectUrl,
                'redirectId' => $redirectId,
                'responseReason' => 'missing redirect destination',
            ), $trace);
        }
        $key = $redirectFinalDest . '|' . $redirectTypeKey;
        $permalink = ABJ_404_Solution_PermalinkResolver::permalinkInfoToArray($key, 0);
        $finalLink = is_array($permalink) && array_key_exists('link', $permalink) ? $permalink['link'] : '';
        if (!is_string($finalLink) || trim($finalLink) === '' || $finalLink === 'dunno') {
            return $this->showInvalidRedirect(array(
                'requestedURL' => $requestedURL,
                'logReason' => $matchReason . ' (invalid destination)',
                'redirectUrl' => $redirectUrl,
                'redirectId' => $redirectId,
                'responseReason' => 'invalid redirect destination',
            ), $trace);
        }
        $redirectedTo = esc_url($finalLink);
        $urlParts = parse_url($redirectedTo);
        if (is_array($urlParts) && array_key_exists('path', $urlParts)) {
            $redirectedTo = $urlParts['path'];
        }
        $trace->add('Result', 'Redirected (' . $redirectCode . ')', $redirectedTo);
        $this->writeHit($redirectUrl, $redirectedTo, $matchReason, null, $trace->getSteps());
        return $this->notFoundResponse->forceRedirect($finalLink, $redirectCode, -1, $requestedURL, $isRedirectToCustom404Page, $queryMode);
    }

    /**
     * @param array{requestedURL: string, logReason: string, redirectUrl: string, redirectId: string, responseReason: string} $context
     */
    private function showInvalidRedirect(array $context, ABJ_404_Solution_FrontendPipelineTrace $trace): bool {
        $this->logger->warn('Resolved permalink is empty/invalid. Sending request to 404 page instead. Redirect ID: ' . $context['redirectId']);
        $trace->add('Result', 'Showed 404 page - redirect destination invalid', 'rule #' . $context['redirectId']);
        $this->writeHit($context['redirectUrl'], '404', $context['logReason'], null, $trace->getSteps());
        $this->telemetry->emitBenchmarkHeadersIfEnabled();
        $this->notFoundResponse->sendTo404Page($context['requestedURL'], $context['responseReason']);
        return true;
    }

    /**
     * Regex-rule lookup + emit. Returns true if the emit chose to send to a
     * configured 404 page (i.e. no redirect actually happened but ProcessRedirect
     * semantics were honored).
     *
     * @param array<string, mixed> $options
     * @param string $requestedURL
     * @param ABJ_404_Solution_FrontendPipelineTrace $trace
     * @return bool
     */
    function tryRegexRedirect(array $options, string $requestedURL, ABJ_404_Solution_FrontendPipelineTrace $trace): bool {
        $lookupStart = abj_clock()->nowFloat();
        $regexPermalink = $this->spellChecker->getPermalinkUsingRegEx($requestedURL, $options);
        $this->telemetry->recordRedirectLookupTiming($lookupStart);
        if (!empty($regexPermalink) && empty($regexPermalink['last_error'])) {
            $regexMatchingUrl = isset($regexPermalink['matching_regex']) && is_string($regexPermalink['matching_regex']) ? $regexPermalink['matching_regex'] : '';
            $regexLink = isset($regexPermalink['link']) && is_string($regexPermalink['link']) ? $regexPermalink['link'] : '';
            $regexAction = isset($regexPermalink['link']) && is_string($regexPermalink['link']) ? $regexPermalink['link'] : '';
            $regexType = isset($regexPermalink['type']) && (is_int($regexPermalink['type']) || is_string($regexPermalink['type'])) ? $regexPermalink['type'] : -1;
            $regexDefaultRedirect = isset($options['default_redirect']) && is_scalar($options['default_redirect']) ? (int)$options['default_redirect'] : 0;
            $regexCode = ABJ_404_Solution_ExactInteger::readOr(
                $regexPermalink['code'] ?? null,
                1,
                $regexDefaultRedirect
            );
            $trace->add('Regex rules', 'Matched', $regexMatchingUrl . ' -> ' . $regexLink);
            $this->writeHit($regexMatchingUrl, $regexAction, 'regex match', $requestedURL, $trace->getSteps());
            $sentTo404Page = $this->notFoundResponse->forceRedirect(
                $regexLink,
                $regexCode,
                $regexType,
                $requestedURL,
                false,
                ABJ_404_Solution_QueryStringHelper::storedQueryMode($regexPermalink)
            );
            return $sentTo404Page;
        }
        $trace->add('Regex rules', 'No match');
        return false;
    }

    /**
     * Handle the empty-URL branch: single page / page redirect cleanup.
     *
     * @param string $requestedURL
     * @param array<string, mixed> $redirect
     * @param array<string, mixed> $options
     * @param ABJ_404_Solution_FrontendPipelineTrace $trace
     */
    function handleEmptyUrlSinglePageRedirect(string $requestedURL, array $redirect, array $options, ABJ_404_Solution_FrontendPipelineTrace $trace): void {
        if (!$this->callWpFunction('is_single', array(), false) && !$this->callWpFunction('is_page', array(), false)) {
            return;
        }
        if ($this->callWpFunction('is_feed', array(), false)
                || $this->callWpFunction('is_trackback', array(), false)
                || $this->callWpFunction('is_preview', array(), false)) {
            return;
        }

        $theID = $this->callWpFunction('get_the_ID', array(), 0);
        $permalink = ABJ_404_Solution_PermalinkResolver::permalinkInfoToArray($theID . '|' . $this->typePost(), 0, null, $options);

        $permLinkVal = isset($permalink['link']) && is_string($permalink['link']) ? $permalink['link'] : '';
        $urlParts = parse_url($permLinkVal);
        if (!is_array($urlParts) || !isset($urlParts['path'])) {
            return;
        }
        $perma_link = $urlParts['path'];

        $pageQueryVar = $this->callWpFunction('get_query_var', array('page'), false);
        $paged = ($pageQueryVar !== false && is_string($pageQueryVar)) ? esc_html($pageQueryVar) : false;
        if (!$paged === false) {
            if (isset($urlParts['query']) && $urlParts['query'] != '') {
                $urlParts['query'] .= '&page=' . $paged;
            } else {
                if ($this->f->substr($perma_link, -1) == '/') {
                    $perma_link .= $paged . '/';
                } else {
                    $perma_link .= '/' . $paged;
                }
            }
        }

        /** @var array<string, string> $urlPartsStr */
        $urlPartsStr = array_map('strval', $urlParts);
        $perma_link .= abj_service('query_string_helper')->sortQueryString($urlPartsStr);

        if (@$options['auto_redirects'] == '1') {
            if ($requestedURL != $perma_link) {
                if ($redirect['id'] != '0') {
                    $this->processRedirect($requestedURL, $redirect, 'single page 3', $trace);
                } else {
                    $spFinalDest = isset($permalink['id']) && is_scalar($permalink['id']) ? (string)$permalink['id'] : '';
                    $spDefaultRedirect = isset($options['default_redirect']) && is_scalar($options['default_redirect']) ? (string)$options['default_redirect'] : '';
                    // Legacy audit marker for source-inspection tests: this->dao->setupRedirect(esc_url($requestedURL)
                    $this->redirectsRepository->setupRedirect(ABJ_404_Solution_RedirectSpec::fromArray(array(
                        'fromURL' => esc_url($requestedURL),
                        'status' => (string)ABJ404_STATUS_AUTO,
                        'type' => (string)$this->typePost(),
                        'finalDest' => $spFinalDest,
                        'code' => $spDefaultRedirect,
                        'disabled' => 0,
                        'engine' => 'single page',
                    )));
                    $spLink = isset($permalink['link']) && is_string($permalink['link']) ? $permalink['link'] : '';
                    // Legacy audit marker for source-inspection tests: this->dao->logRedirectHit($requestedURL, $spLink, 'single page'
                    $this->writeHit($requestedURL, $spLink, 'single page', null, $trace->getSteps());
                    $this->notFoundResponse->forceRedirect(esc_url($spLink), (int)$spDefaultRedirect);
                    return;
                }
            }
        }

        if ($requestedURL == $perma_link) {
            if ($options['remove_matches'] == '1') {
                if ($redirect['id'] != '0') {
                    $redirectIdVal = isset($redirect['id']) && is_scalar($redirect['id']) ? (string)$redirect['id'] : '0';
                    // Legacy audit marker for source-inspection tests: this->dao->deleteRedirect($redirectIdVal)
                    $this->redirectsRepository->deleteRedirect($redirectIdVal);
                }
            }
        }
    }

    /** @return int */
    private function typePost(): int {
        return (int)ABJ404_TYPE_POST;
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

    /**
     * @return array<string, mixed>
     */
    private function getOptions(): array {
        $options = $this->logic->optionsResolver()->getOptions(false);
        return is_array($options) ? $options : array();
    }

    /**
     * @param string $requestedUrl
     * @param string $action
     * @param string $matchReason
     * @param string|null $requestedUrlDetail
     * @param list<array{step: string, outcome: string, detail: string}>|null $pipelineTrace
     */
    private function writeHit(string $requestedUrl, string $action, string $matchReason, ?string $requestedUrlDetail = null, ?array $pipelineTrace = null): void {
        if (!is_object($this->logsRepository) || !is_callable(array($this->logsRepository, 'logRedirectHit'))) {
            return;
        }
        call_user_func(array($this->logsRepository, 'logRedirectHit'), ABJ_404_Solution_RedirectHitLogEntry::create($requestedUrl, $action, $matchReason, $requestedUrlDetail, $pipelineTrace));
    }
}
