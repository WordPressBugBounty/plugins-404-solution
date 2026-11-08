<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keeps a bounded sample of import diagnostics and the count hidden by bounds.
 *
 * Import sources can contain any number of rejected rows. This value object
 * keeps the first 100 diagnostics, bounded to 2048 bytes each, while retaining
 * an exact count for the omitted rows so callers can report the full result.
 */
class ABJ_404_Solution_ImportIssueSummary {

    const MAX_DETAILS = 100;
    const MAX_DETAIL_BYTES = 2048;

    /** @var array<int, string> */
    private $details = array();

    /** @var int */
    private $omitted = 0;

    /**
     * @param array<int, mixed> $details Previously retained diagnostics.
     * @param int $omitted Previously omitted diagnostic count.
     */
    public function __construct(array $details = array(), int $omitted = 0) {
        $this->omitted = max(0, $omitted);
        foreach ($details as $detail) {
            if (is_scalar($detail)) {
                $this->add((string)$detail);
            }
        }
    }

    /**
     * Add one diagnostic while retaining bounded memory.
     *
     * @param string $detail
     * @return void
     */
    public function add(string $detail): void {
        if (count($this->details) >= self::MAX_DETAILS) {
            $this->omitted++;
            return;
        }
        $this->details[] = $this->boundDetail($detail);
    }

    /** @return array<int, string> */
    public function details(): array {
        return $this->details;
    }

    /** @return int */
    public function omittedCount(): int {
        return $this->omitted;
    }

    /**
     * Return the visible sample plus one translated omission summary when
     * diagnostics are hidden by either the retained-sample or display limit.
     *
     * @param int $limit Maximum retained diagnostics to return.
     * @return array<int, string>
     */
    public function messages(int $limit = self::MAX_DETAILS): array {
        $limit = max(0, min(self::MAX_DETAILS, $limit));
        $messages = array_slice($this->details, 0, $limit);
        $hidden = $this->omitted + max(0, count($this->details) - $limit);
        if ($hidden > 0) {
            $messages[] = sprintf(
                __('Additional import issues omitted: %d.', '404-solution'),
                $hidden
            );
        }
        return $messages;
    }

    private function boundDetail(string $detail): string {
        if (strlen($detail) <= self::MAX_DETAIL_BYTES) {
            return $detail;
        }

        $marker = '...';
        $available = self::MAX_DETAIL_BYTES - strlen($marker);
        $headRaw = substr($detail, 0, intdiv($available, 2));
        $tailRaw = substr($detail, -($available - strlen($headRaw)));
        if (preg_match('//u', $detail) === 1) {
            // A valid UTF-8 string can have at most three partial bytes at a
            // byte cut. Keep this bounded rather than scanning arbitrary input.
            $head = $this->validUtf8Prefix($headRaw);
            $tail = $this->validUtf8Suffix($tailRaw);
        } else {
            // Preserve diagnostic bytes and the cause text for malformed input;
            // these strings are logged as raw diagnostics elsewhere as well.
            $head = $headRaw;
            $tail = $tailRaw;
        }
        return $head . $marker . $tail;
    }

    private function validUtf8Prefix(string $value): string {
        for ($i = 0; $i < 3 && $value !== '' && preg_match('//u', $value) !== 1; $i++) {
            $value = substr($value, 0, -1);
        }
        return $value;
    }

    private function validUtf8Suffix(string $value): string {
        for ($i = 0; $i < 3 && $value !== '' && preg_match('//u', $value) !== 1; $i++) {
            $value = substr($value, 1);
        }
        return $value;
    }
}
