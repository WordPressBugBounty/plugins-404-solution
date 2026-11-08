<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves request URLs to active or existing redirect rows.
 */
class ABJ_404_Solution_RedirectLookupService {

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_RedirectLookupRepository */
    private $lookupRepository;

    /** @var ABJ_404_Solution_PluginLogicUrlNormalization|null */
    private $urlNormalization;

    /**
     * @param ABJ_404_Solution_Functions $functions
     * @param ABJ_404_Solution_RedirectLookupRepository $lookupRepository
     * @param ABJ_404_Solution_PluginLogicUrlNormalization|null $urlNormalization
     */
    public function __construct(
        $functions,
        ABJ_404_Solution_RedirectLookupRepository $lookupRepository,
        ?ABJ_404_Solution_PluginLogicUrlNormalization $urlNormalization = null
    ) {
        $this->f = $functions;
        $this->lookupRepository = $lookupRepository;
        $this->urlNormalization = $urlNormalization;
    }

    /**
     * @param string $url
     * @param bool $degradedMode
     * @return array<string, mixed>
     */
    public function getActiveRedirectForURL($url, $degradedMode = false): array {
        $exact = $this->findFirstCandidate($url, function($candidate) use ($degradedMode) {
            return $this->lookupRepository->getActiveRedirectForNormalizedUrl($candidate, $degradedMode);
        });
        if (!empty($exact['last_error']) || ABJ_404_Solution_ExactInteger::readOr($exact['id'] ?? null, 1, 0) !== 0 || strpos($url, '?') === false) {
            return $exact;
        }
        $routingKey = abj_service('query_string_helper')->routingKey($url);
        if ($routingKey !== $url) {
            $routing = $this->findFirstCandidate($routingKey, function($candidate) use ($degradedMode) {
                return $this->lookupRepository->getActiveRedirectForNormalizedUrl($candidate, $degradedMode, 'routing');
            });
            if (!empty($routing['last_error']) || ABJ_404_Solution_ExactInteger::readOr($routing['id'] ?? null, 1, 0) !== 0) { return $routing; }
        }
        $path = explode('?', $url, 2)[0];
        if ($path === $routingKey) { return array('id' => 0); }
        return $this->findFirstCandidate($path, function($candidate) use ($degradedMode) {
            return $this->lookupRepository->getActiveRedirectForNormalizedUrl($candidate, $degradedMode, 'pass');
        });
    }

    /** @param array<string, int> $context @return self */
    public function withLookupContext(array $context): self {
        return new self($this->f, $this->lookupRepository->withLookupContext($context), $this->urlNormalization);
    }

    /**
     * @param string $url
     * @return array<string, mixed>
     */
    public function getExistingRedirectForURL($url): array {
        return $this->findFirstCandidate($url, function($candidate) {
            return $this->lookupRepository->getExistingRedirectForNormalizedUrl($candidate);
        });
    }

    /**
     * @param string $url
     * @param callable $lookup
     * @return array<string, mixed>
     */
    private function findFirstCandidate($url, callable $lookup): array {
        $url = $this->f->sanitizeInvalidUTF8($url);

        if (function_exists('mb_check_encoding') && !mb_check_encoding($url, 'UTF-8')) {
            return array('id' => 0);
        }

        $candidates = $this->urlNormalization()->getNormalizedUrlCandidates($url);
        foreach ($candidates as $candidate) {
            $redirect = $lookup($candidate);
            if (!is_array($redirect)) {
                continue;
            }
            $assocRedirect = $this->assocRedirectRow($redirect);
            if (!empty($assocRedirect['last_error'])) {
                return $assocRedirect;
            }
            if (isset($assocRedirect['id']) && $assocRedirect['id'] !== 0) {
                return $assocRedirect;
            }
        }

        return array('id' => 0);
    }

    /**
     * @param array<mixed, mixed> $redirect
     * @return array<string, mixed>
     */
    private function assocRedirectRow(array $redirect): array {
        $assoc = array();
        foreach ($redirect as $key => $value) {
            if (is_string($key)) {
                $assoc[$key] = $value;
            }
        }
        return $assoc;
    }

    /**
     * @return ABJ_404_Solution_PluginLogicUrlNormalization
     */
    private function urlNormalization() {
        if ($this->urlNormalization !== null) {
            return $this->urlNormalization;
        }
        $pluginLogic = function_exists('abj_service_optional')
            ? abj_service_optional('plugin_logic') : null;
        if (is_object($pluginLogic) && method_exists($pluginLogic, 'urlNormalization')) {
            $normalization = $pluginLogic->urlNormalization();
            if ($normalization instanceof ABJ_404_Solution_PluginLogicUrlNormalization) {
                return $normalization;
            }
        }
        return new ABJ_404_Solution_PluginLogicUrlNormalization($this->f, '', 0);
    }
}
