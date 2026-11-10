<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decides when a submitted destination is "an external URL" and whether a
 * typed external URL may be stored.
 *
 * The Add/Edit Redirect forms and the custom 404 page setting share the same
 * destination picker, which posts "4|4" (ABJ404_TYPE_EXTERNAL twice) in its id
 * field when the admin chose to type a URL. Both forms must recognize that
 * choice and validate the typed URL the same way: a URL that cannot be stored
 * is refused with a message, never saved as an empty destination that sends
 * every visitor nowhere.
 */
class ABJ_404_Solution_ExternalDestinationPolicy {

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @param ABJ_404_Solution_Functions $f */
    public function __construct($f) {
        $this->f = $f;
    }

    /**
     * Whether the destination picker's id field selects "external URL".
     *
     * @param mixed $destinationFieldId The posted id field, or a stored dest404page value.
     */
    public static function isExternalChoice($destinationFieldId): bool {
        return is_scalar($destinationFieldId)
            && (string)$destinationFieldId === ABJ404_TYPE_EXTERNAL . '|' . ABJ404_TYPE_EXTERNAL;
    }

    /**
     * Validate an absolute external destination for storage. The URL is stored
     * and sent as typed, so it is normalized (invalid UTF-8 and control bytes
     * removed, never percent-decoded) and sanitized as a URL (esc_url_raw, http
     * and https only), never as text. Normalization happens here so every form
     * that stores an external destination gets the same rule.
     *
     * @param string $destination The URL as the admin typed it.
     * @return array{dest: string, message: string} message is '' when the URL may be stored.
     */
    public function validate(string $destination): array {
        $typed = trim(abj_service('sanitizer')->normalizeUrlString($destination, array('decode' => false)));
        if ($typed === '') {
            return array(
                'dest' => '',
                'message' => __('Error: You selected external URL but did not enter a URL.', '404-solution') . "<BR/>",
            );
        }
        // esc_url_raw() returns '' for a URL it refuses outright, which is a URL
        // with a scheme other than http or https (ftp:, mailto:, javascript:).
        $destination = esc_url_raw($typed, array('http', 'https'));
        if ($destination === '') {
            return array('dest' => '', 'message' => self::schemeMessage());
        }
        if ($this->f->strlen($destination) < 8) {
            return array(
                'dest' => $destination,
                'message' => __('Error: External URL is too short.', '404-solution') . "<BR/>",
            );
        }
        if ($this->f->strpos($destination, "://") === false) {
            return array(
                'dest' => $destination,
                'message' => __("Error: External URL doesn't contain ://", '404-solution') . "<BR/>",
            );
        }

        $parsedUrl = parse_url($destination);
        if (!is_array($parsedUrl) || !isset($parsedUrl['scheme'])
                || !in_array(strtolower($parsedUrl['scheme']), array('http', 'https'), true)) {
            return array('dest' => $destination, 'message' => self::schemeMessage());
        }

        // A callback that returns false, a non-string, or a blank string (a
        // forgotten "return" yields null) has not given a URL to store.
        $validatedUrl = apply_filters('abj404_validate_external_redirect', $destination);
        if (!is_string($validatedUrl) || trim($validatedUrl) === '') {
            return array(
                'dest' => $destination,
                'message' => __('Error: Another plugin or your theme rejected this external URL.', '404-solution') . "<BR/>",
            );
        }

        return array('dest' => $validatedUrl, 'message' => '');
    }

    private static function schemeMessage(): string {
        return __('Error: External URL must use http:// or https:// protocol only.', '404-solution') . "<BR/>";
    }
}
