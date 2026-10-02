<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Puts the time-limit breakdown into WordPress core's recovery-mode email.
 *
 * The email becomes HTML with core's own text kept intact (escaped, links
 * clickable, paragraphs kept) and the per-component table placed right after
 * core's paragraph that names 404 Solution; a plain-text twin with the same
 * table rides along as the multipart alternative. When the site already
 * forces a Content-Type, the email stays as it is and the text table is
 * appended instead.
 *
 * Presentation only: it is handed the email and the breakdown and decides
 * nothing about whether the fatal is ours (ABJ_404_Solution_TimeLimitFatalReporter
 * does) or what is stored (ABJ_404_Solution_TimeLimitFatalRecorder does).
 * Core sends the mail; this class never sends any.
 *
 * @phpstan-import-type Record from ABJ_404_Solution_TimeLimitRecord
 */
final class ABJ_404_Solution_TimeLimitRecoveryEmail {

    /**
     * @var array{body_hash: string, alt_body: string}|null Plain-text alternative
     *   waiting for phpmailer_init, with the hash of the exact HTML message it
     *   belongs to. phpmailer_init fires for every mail the request sends, so
     *   the alternative is applied only to the mail whose body it matches.
     */
    private static $pendingAlternative = null;

    /**
     * The recovery email with the breakdown in it.
     *
     * @param array<mixed, mixed> $email core's array (to, subject, message, headers, attachments), as
     *   a filter received it: nothing about its keys is trusted.
     * @param Record $breakdown
     * @return array<mixed, mixed> the email, unchanged when its message is not a string.
     */
    public static function compose(array $email, array $breakdown): array {
        if (!isset($email['message']) || !is_string($email['message'])) {
            return $email;
        }
        $text = ABJ_404_Solution_TimeLimitReportRenderer::text($breakdown);
        $headers = self::headerLines($email['headers'] ?? '');
        if (self::hasContentType($headers)) {
            $email['message'] = self::insertAfterOurParagraph(self::paragraphs($email['message']),
                array('block' => $text, 'delimiter' => "\n\n"));
            return $email;
        }
        $paragraphs = self::paragraphs($email['message']);
        $paragraphTemplate = ABJ_404_Solution_TimeLimitReportRenderer::template('timeLimitParagraph.html');
        $htmlParagraphs = array();
        foreach ($paragraphs as $paragraph) {
            $htmlParagraphs[] = strtr($paragraphTemplate, array('{text}' => nl2br(make_clickable(esc_html($paragraph)))));
        }
        $htmlBlock = ABJ_404_Solution_TimeLimitReportRenderer::html($breakdown);
        $email['message'] = strtr(ABJ_404_Solution_TimeLimitReportRenderer::template('timeLimitEmailMessage.html'), array(
            '{body}' => self::insertAfterIndex($htmlParagraphs, self::ourParagraphIndex($paragraphs),
                array('block' => $htmlBlock, 'delimiter' => "\n")),
        ));
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $email['headers'] = $headers;
        self::$pendingAlternative = array(
            'body_hash' => hash('sha256', $email['message']),
            'alt_body' => self::insertAfterOurParagraph($paragraphs, array('block' => $text, 'delimiter' => "\n\n")),
        );
        self::lifecycle()->traceBoundary(ABJ_404_Solution_HookInstrumentationLifecycleTracer::PHASE_REGISTRATION,
            'phpmailer_init', static function (): void {
                add_action('phpmailer_init', array(self::class, 'applyPlainTextAlternative'));
            });
        return $email;
    }

    /**
     * phpmailer_init callback: give the HTML recovery email its plain-text
     * alternative, once, and only to that email.
     *
     * wp_mail sets Subject and Body on the PHPMailer before this action, so
     * the mail is recognized by the hash of the exact HTML body compose()
     * produced (the subject cannot serve: core formats it after the filter
     * ran). Any other mail initializing first is left alone and the pending
     * alternative stays for its own message; once applied, the hook removes
     * itself, so nothing later in the request can match it.
     *
     * @param object $phpmailer
     * @return void
     */
    public static function applyPlainTextAlternative($phpmailer): void {
        if (self::$pendingAlternative === null || !is_object($phpmailer) || !property_exists($phpmailer, 'AltBody')
                || !property_exists($phpmailer, 'Body') || !is_string($phpmailer->Body)
                || !hash_equals(self::$pendingAlternative['body_hash'], hash('sha256', $phpmailer->Body))) {
            return;
        }
        $phpmailer->AltBody = self::$pendingAlternative['alt_body'];
        self::$pendingAlternative = null;
        self::lifecycle()->traceBoundary(ABJ_404_Solution_HookInstrumentationLifecycleTracer::PHASE_REMOVAL,
            'phpmailer_init', static function (): void {
                remove_action('phpmailer_init', array(self::class, 'applyPlainTextAlternative'));
            });
    }

    /**
     * Forget the pending plain-text alternative.
     *
     * @return void
     */
    public static function resetForTests(): void {
        self::$pendingAlternative = null;
    }

    /**
     * The boundary this class's hook registrations run in. Inert on purpose:
     * no request id exists when it is used, so the empty id makes the tracer
     * skip every write. The bracket stays because the diagnostics-wide guard
     * (DecisiveRecordManifestTest) requires every hook mutation in this
     * directory to run inside traceBoundary().
     *
     * @return ABJ_404_Solution_HookInstrumentationLifecycleTracer
     */
    private static function lifecycle(): ABJ_404_Solution_HookInstrumentationLifecycleTracer {
        return new ABJ_404_Solution_HookInstrumentationLifecycleTracer('', 'time_limit_recovery_email');
    }

    /**
     * Core's message split into blank-line-separated paragraphs.
     *
     * @param string $message
     * @return array<int, string>
     */
    private static function paragraphs(string $message): array {
        $parts = preg_split("/\r?\n\s*\r?\n/", $message);
        return is_array($parts) ? $parts : array($message);
    }

    /**
     * Index of core's paragraph naming this plugin (the cause line), or the
     * last paragraph when none does.
     *
     * @param array<int, string> $paragraphs
     * @return int
     */
    private static function ourParagraphIndex(array $paragraphs): int {
        foreach ($paragraphs as $i => $paragraph) {
            if (strpos($paragraph, '404 Solution') !== false) {
                return $i;
            }
        }
        return count($paragraphs) - 1;
    }

    /**
     * @param array<int, string> $paragraphs
     * @param array{block: string, delimiter: string} $insertion see insertAfterIndex().
     * @return string
     */
    private static function insertAfterOurParagraph(array $paragraphs, array $insertion): string {
        return self::insertAfterIndex($paragraphs, self::ourParagraphIndex($paragraphs), $insertion);
    }

    /**
     * The block and the delimiter that joins the result are both strings, so
     * they arrive keyed: swapping them is a wrong key at the call site, not a
     * type-valid slip that silently builds a malformed email.
     *
     * @param array<int, string> $items
     * @param int $index the block goes after this item.
     * @param array{block: string, delimiter: string} $insertion block: what to
     *   insert; delimiter: what joins the items in the returned string.
     * @return string
     */
    private static function insertAfterIndex(array $items, int $index, array $insertion): string {
        array_splice($items, $index + 1, 0, array($insertion['block']));
        return implode($insertion['delimiter'], $items);
    }

    /**
     * Headers as a list of lines, whatever form core or a filter left them in.
     *
     * @param mixed $headers
     * @return array<int, string>
     */
    private static function headerLines($headers): array {
        if (is_array($headers)) {
            $lines = array();
            foreach ($headers as $header) {
                if (is_string($header) && $header !== '') {
                    $lines[] = $header;
                }
            }
            return $lines;
        }
        if (!is_string($headers) || trim($headers) === '') {
            return array();
        }
        $lines = preg_split("/\r?\n/", trim($headers));
        return is_array($lines) ? array_values(array_filter($lines, static function (string $line): bool {
            return $line !== '';
        })) : array();
    }

    /**
     * @param array<int, string> $headers
     * @return bool
     */
    private static function hasContentType(array $headers): bool {
        foreach ($headers as $line) {
            if (stripos(ltrim($line), 'content-type:') === 0) {
                return true;
            }
        }
        return false;
    }
}
