<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Makes client-supplied text safe to carry in a persisted diagnostic record,
 * and encodes such a record so it is never lost without a trace.
 *
 * Diagnostic records are small JSON documents assembled from strings a
 * browser (or a bot) chose: a table part name, a session id, a request path.
 * They are bounded with a truncation, and a BYTE truncation can land inside a
 * multibyte character. json_encode() rejects invalid UTF-8 outright, so the
 * one malformed string used to make the whole record encode to '' or false and
 * vanish, with nothing recording that it had been lost.
 *
 * Two guarantees, one at each end of the same path:
 * - clip() at the boundary: the stored value is a valid, control-character-free
 *   UTF-8 string of at most N characters. Every truncation of client text bound
 *   for a record goes through it instead of substr().
 * - encode() at the consumer: a value that still carries invalid bytes is
 *   encoded with substitution (U+FFFD) instead of failing, and a record that
 *   cannot be encoded at all is reported on the durable WARN channel with the
 *   JSON error, then reported to the caller as ''. Never throws: these run in
 *   shutdown and fatal handlers.
 *
 * The string primitives are the repo's own MbStringAdapter (mb_* when the
 * extension is loaded, a byte-safe fallback otherwise), from the container.
 */
final class ABJ_404_Solution_Utf8SafeRecord {

    /**
     * Truncate client text to at most $maxChars characters without splitting a
     * character, with invalid UTF-8 sequences and control bytes removed.
     *
     * Sanitized before the cut (so invalid bytes cannot skew the character
     * count) and again after it (the byte-based fallback adapter can still cut
     * a multibyte character in half; the second pass drops the fragment).
     *
     * @param string $text
     * @param int $maxChars Maximum characters kept.
     * @return string
     */
    public static function clip(string $text, int $maxChars): string {
        $adapter = abj_service('mb_string_adapter');
        $clean = $adapter->sanitizeInvalidUTF8($text);
        return $adapter->sanitizeInvalidUTF8($adapter->substr($clean, 0, max(0, $maxChars)));
    }

    /**
     * Encode a record as JSON. Invalid UTF-8 becomes U+FFFD rather than
     * failing the encode. When the record still cannot be encoded, the failure
     * is logged as a durable WARN (record name plus the JSON error) and ''
     * is returned, so the caller drops the record knowing it was reported.
     *
     * @param mixed $value The record.
     * @param string $context What this record is, for the warning ("phase timeline").
     * @param int $flags Extra json_encode flags (for example JSON_UNESCAPED_SLASHES).
     * @return string The JSON, or '' when the record could not be encoded.
     */
    public static function encode($value, string $context, int $flags = 0): string {
        try {
            return json_encode($value, $flags | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            self::reportEncodeFailure($context, $e);
            return '';
        }
    }

    /**
     * @param string $context
     * @param JsonException $failure
     * @return void
     */
    private static function reportEncodeFailure(string $context, JsonException $failure): void {
        $message = $context . ' could not be JSON-encoded and was dropped (json error '
            . $failure->getCode() . ': ' . $failure->getMessage() . ')';
        try {
            $logger = function_exists('abj_service') ? abj_service('logging') : null;
            if (is_object($logger) && method_exists($logger, 'warnCaught')) {
                $logger->warnCaught($message, $failure);
                return;
            }
        } catch (Throwable $e) {
            $message .= '; the logger failed too (' . get_class($e) . ' ' . $e->getCode() . '): ' . $e->getMessage();
        }
        if (function_exists('abj404_logPhpFallback')) {
            abj404_logPhpFallback('diagnostic-record-encode', $message);
        }
    }
}
