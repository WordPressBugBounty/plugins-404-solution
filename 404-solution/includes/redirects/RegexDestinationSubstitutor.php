<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fills a regex redirect's `$N` destination template with text captured from
 * the visitor's requested URL, and refuses any result whose ORIGIN the
 * visitor chose.
 *
 * Invariant: text captured from the visitor's URL can never choose the origin
 * (scheme, userinfo, host, port) of the redirect. The admin's template picks
 * the origin; a capture may only fill in the path, query and fragment.
 *
 * The visitor controls every captured character, so three shapes have to be
 * closed off:
 *
 *  1. A capture appended to an external host extends it or re-parents it.
 *     Template `https://shop.example.com$1` with source `^/shop(.*)`: the
 *     request `/shop.evil.com` gives host `shop.example.com.evil.com`, and the
 *     request `/shop@evil.com` gives host `evil.com` (the template host
 *     becomes userinfo).
 *  2. A capture after a lone `/` forms a network-path reference. Template
 *     `/$1` with source `^/old(.*)`: the request `/old/evil.com` gives
 *     `//evil.com`, which every browser reads as host evil.com.
 *  3. Characters that vanish or change meaning between this class and the
 *     browser. Browsers strip tab, CR and LF inside a URL and read `\` as `/`;
 *     WordPress `wp_sanitize_redirect()` deletes every character outside
 *     `[a-z0-9-~+_.?#=&;,/:%!*\[\]()@]` and strips `%0a`/`%0d`. So `/` + tab +
 *     `/evil.com` is `//evil.com` after sanitizing, `/\evil.com` is `//evil.com`
 *     to a browser, and `https://shop.example.com\@evil.com` turns into
 *     `https://shop.example.com@evil.com` once WordPress deleted the backslash.
 *
 * Because the plugin whitelists whatever host the final string parses to
 * before calling `wp_safe_redirect()`, a visitor who picks the host also gets
 * it whitelisted. So the origin is checked on three views of the result, all
 * of which must agree with the template: the raw string (what the plugin
 * parses), the browser view (what a browser follows) and the WordPress view
 * (what `wp_safe_redirect()` actually sends).
 */
class ABJ_404_Solution_RegexDestinationSubstitutor {

    /** A `$N` replacement token in a destination template. */
    private const TOKEN_PATTERN = '/\\$([1-9][0-9]*)/';

    /** The leading run that collapses to one `/` in a relative result. */
    private const LEADING_SLASH_RUN_PATTERN = '/^[\/\\\\\x00-\x20\x7F]+/';

    /** Valid UTF-8 runs, which `wp_sanitize_redirect()` percent-encodes instead of deleting. */
    private const UTF8_RUN_PATTERN = '/(?:[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|[\xEE-\xEF][\x80-\xBF]{2}|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}){1,40}/';

    /** Everything `wp_sanitize_redirect()` keeps; all other bytes are deleted. */
    private const WP_DISALLOWED_PATTERN = '|[^a-z0-9-~+_.?#=&;,/:%!*\[\]()@]|i';

    /**
     * Replace `$1`..`$99` in a destination template with the captured groups,
     * then return the result only if the captured text did not change the
     * redirect's origin.
     *
     * A relative template (`/...`, not `//...`) has its leading run of `/`, `\`
     * and control characters collapsed to a single `/`, so `/$1` with the
     * capture `/evil.com` gives the local path `/evil.com`. Any other template
     * keeps its result as substituted, but the origin of the result (scheme,
     * userinfo, host, port) must equal the origin of the template with its
     * tokens removed, in every view described on the class. A template that
     * holds no `$N` token is returned unchanged: nothing captured is in it.
     *
     * Limitations: origins are compared strictly, so `:443` on an `https`
     * template is rejected even though it names the same origin, and a
     * template whose host contains a token (`https://$1.example.com/`) is
     * rejected for every non-empty capture, because a visitor-chosen host is
     * exactly what this class forbids.
     *
     * @param string $template The admin's destination, e.g. `/new/$1` or `https://shop.example.com$1`.
     * @param array<int|string, string> $captures Capture groups from the source pattern match, indexed by group number.
     *                                            A group that is absent substitutes the empty string.
     * @return string|null The safe destination, or null when the captured text would change the origin.
     */
    public function substitute(string $template, array $captures): ?string {
        if (preg_match(self::TOKEN_PATTERN, $template) !== 1) {
            return $template;
        }

        $substituted = $this->replaceTokens($template, $captures);
        if ($this->isRelativeTemplate($template)) {
            return $this->guardRelative($substituted);
        }
        return $this->guardAbsolute($template, $substituted);
    }

    /**
     * Whether every `$N` in this template sits after its origin, so ordinary
     * path, query or fragment text can fill it. False means a token sits inside
     * the scheme, userinfo, host or port: substitute() then refuses ordinary
     * captures, and a rule with this destination does not redirect where the
     * admin meant. The save-time validator rejects such a template, and
     * SpellURLMatcher's log line for a saved one names the fix.
     *
     * Decided from the template's literal text, not by probing: the text before
     * each token (tokens removed) must already carry the whole origin, and must
     * either continue into a path, query or fragment or end exactly where the
     * authority ends (`https://shop.example.com$1`, `https://example.com:8443$1`).
     * A dangling `:`, `@` or partial host before the token fails the check.
     *
     * Limitation: a token right after a complete-looking host is accepted,
     * because only the capture decides whether it starts a path (`/cart`) or
     * extends the host (`.evil.com`); `https://example.co$1/` passes here, and
     * substitute() still refuses every capture that would extend the host.
     *
     * @param string $template The admin's destination template.
     * @return bool True when the template holds no token, is site-relative, or keeps every token after its origin.
     */
    public function keepsTokensOutsideTheOrigin(string $template): bool {
        if (preg_match_all(self::TOKEN_PATTERN, $template, $tokens, PREG_OFFSET_CAPTURE) < 1) {
            return true;
        }
        if ($this->isRelativeTemplate($template)) {
            return true;
        }
        $origin = $this->originOf($this->withoutTokens($template));
        if ($origin === null || $origin['host'] === null) {
            return false;
        }
        foreach ($tokens[0] as $token) {
            if (!$this->closesOrigin($this->withoutTokens(substr($template, 0, $token[1])), $origin)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether the literal text before a token already ends the template's origin.
     *
     * @param string $prefix The template text before the token, tokens removed.
     * @param array{scheme: ?string, user: ?string, pass: ?string, host: ?string, port: ?int} $origin The template's origin.
     */
    private function closesOrigin(string $prefix, array $origin): bool {
        $parts = parse_url($prefix);
        if (!is_array($parts) || $this->originOf($prefix) !== $origin) {
            return false;
        }
        $last = substr($prefix, -1);
        if (isset($parts['path']) && $parts['path'] !== '' || isset($parts['query']) || isset($parts['fragment'])
                || $last === '?' || $last === '#') {
            return true;
        }
        return strtolower($prefix) === strtolower($this->authorityOf($parts));
    }

    /**
     * The scheme and authority rebuilt from parse_url() parts, e.g. `https://u@example.com:8443`.
     *
     * @param array<string, int|string> $parts
     */
    private function authorityOf(array $parts): string {
        $userinfo = isset($parts['user'])
            ? $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@'
            : '';
        return (isset($parts['scheme']) ? $parts['scheme'] . ':' : '') . '//' . $userinfo
            . (isset($parts['host']) ? $parts['host'] : '')
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private function withoutTokens(string $template): string {
        return (string)preg_replace(self::TOKEN_PATTERN, '', $template);
    }

    /**
     * @param array<int|string, string> $captures
     */
    private function replaceTokens(string $template, array $captures): string {
        $substituted = preg_replace_callback(
            self::TOKEN_PATTERN,
            static function(array $tokenMatch) use ($captures): string {
                $groupNumber = (int)$tokenMatch[1];
                return array_key_exists($groupNumber, $captures)
                    ? (string)$captures[$groupNumber]
                    : '';
            },
            $template
        );
        return is_string($substituted) ? $substituted : $template;
    }

    /** A site-relative template: starts with `/`, and the second character is not `/` or `\`. */
    private function isRelativeTemplate(string $template): bool {
        if ($template === '' || $template[0] !== '/') {
            return false;
        }
        return !isset($template[1]) || ($template[1] !== '/' && $template[1] !== '\\');
    }

    /**
     * Collapse the leading run to one `/`, then require every view of the
     * result to be a plain local path.
     */
    private function guardRelative(string $substituted): ?string {
        $normalized = preg_replace(self::LEADING_SLASH_RUN_PATTERN, '/', $substituted);
        if (!is_string($normalized)) {
            return null;
        }
        foreach ($this->views($normalized) as $view) {
            if (!$this->isLocalPath($view)) {
                return null;
            }
        }
        return $normalized;
    }

    /** One `/`, then something that is not `/`, and no scheme, host, user or pass. */
    private function isLocalPath(string $view): bool {
        if (preg_match('#^/(?!/)#', $view) !== 1) {
            return false;
        }
        $parts = parse_url($view);
        return is_array($parts)
            && !isset($parts['scheme'])
            && !isset($parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }

    /**
     * The result must have the template's origin in every view. The template
     * is viewed with its tokens removed, so only the literal part of the
     * admin's template defines the expected origin.
     */
    private function guardAbsolute(string $template, string $substituted): ?string {
        $templateViews = $this->views($this->withoutTokens($template));
        $resultViews = $this->views($substituted);
        foreach ($resultViews as $name => $resultView) {
            $expected = $this->originOf($templateViews[$name]);
            $actual = $this->originOf($resultView);
            if ($expected === null || $actual === null || $expected !== $actual) {
                return null;
            }
        }
        return $substituted;
    }

    /**
     * @return array{scheme: ?string, user: ?string, pass: ?string, host: ?string, port: ?int}|null
     *         Null when parse_url() cannot read the string.
     */
    private function originOf(string $url): ?array {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }
        return array(
            'scheme' => isset($parts['scheme']) ? strtolower($parts['scheme']) : null,
            'user' => isset($parts['user']) ? $parts['user'] : null,
            'pass' => isset($parts['pass']) ? $parts['pass'] : null,
            'host' => isset($parts['host']) ? strtolower($parts['host']) : null,
            'port' => isset($parts['port']) ? $parts['port'] : null,
        );
    }

    /**
     * The three strings an attacker targets, in a fixed order.
     *
     * @return array{raw: string, browser: string, wordpress: string}
     */
    private function views(string $url): array {
        return array(
            'raw' => $url,
            'browser' => $this->browserView($url),
            'wordpress' => $this->wordPressRedirectView($url),
        );
    }

    /** What a browser follows: control characters and spaces dropped, `\` read as `/`. */
    private function browserView(string $url): string {
        $stripped = (string)preg_replace('/[\x00-\x20\x7F]/', '', $url);
        return str_replace('\\', '/', $stripped);
    }

    /**
     * What `wp_safe_redirect()` sends: the steps of `wp_sanitize_redirect()`
     * (spaces to `%20`, valid UTF-8 percent-encoded, every other character
     * outside the allowed set deleted, `%0a`/`%0d` stripped until none is
     * left), emulated here so the check does not depend on WordPress loading.
     * A failed preg call yields an empty view, which no check accepts.
     */
    private function wordPressRedirectView(string $url): string {
        $view = str_replace(' ', '%20', $url);
        $encoded = preg_replace_callback(
            self::UTF8_RUN_PATTERN,
            static function(array $run): string {
                return urlencode($run[0]);
            },
            $view
        );
        $view = (string)preg_replace(self::WP_DISALLOWED_PATTERN, '', is_string($encoded) ? $encoded : $view);

        do {
            $before = $view;
            $view = str_replace(array('%0d', '%0a', '%0D', '%0A'), '', $view);
        } while ($view !== $before);

        return $view;
    }
}
