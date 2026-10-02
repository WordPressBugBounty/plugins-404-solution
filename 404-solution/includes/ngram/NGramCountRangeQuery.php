<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Immutable value object describing one database-side n-gram candidate read:
 * the ngram_count window, how many rows to return, which count to order
 * around, and an optional single entity type.
 *
 * Replaces the positional ($minNgramCount, $maxNgramCount, $limit,
 * $targetNgramCount, $type) parameters of
 * {@see ABJ_404_Solution_NGramCacheRepository::getCachedNGramsFiltered()}.
 * Four of those were interchangeable ints, so a call that swapped min and max
 * (or limit and target) ran without error and returned the wrong window.
 *
 * {@see self::fromArray()} is the only factory. Field semantics:
 *   - minCount:    inclusive lower bound on ngram_count (int, required).
 *   - maxCount:    inclusive upper bound on ngram_count (int, required).
 *   - limit:       maximum rows returned (int, default CACHE_LOAD_LIMIT).
 *   - targetCount: count to order results around (int|null, default null,
 *                  meaning the midpoint of the window).
 *   - type:        entity type restriction such as 'post' or 'category'
 *                  (string|null, default null, meaning all types).
 */
final class ABJ_404_Solution_NGramCountRangeQuery {

    /** @var int */
    private $minCount;
    /** @var int */
    private $maxCount;
    /** @var int */
    private $limit;
    /** @var int|null */
    private $targetCount;
    /** @var string|null */
    private $type;

    private function __construct(int $minCount, int $maxCount, int $limit, ?int $targetCount, ?string $type) {
        $this->minCount = $minCount;
        $this->maxCount = $maxCount;
        $this->limit = $limit;
        $this->targetCount = $targetCount;
        $this->type = $type;
    }

    /**
     * Build a range query from named fields (the only factory).
     *
     * Required keys: minCount, maxCount (int). Optional keys: limit (int),
     * targetCount (int|null), type (string|null). A missing required key or a
     * value of any other type throws InvalidArgumentException rather than
     * being coerced, so a transposed or garbage argument fails at the call.
     *
     * @param array<string, mixed> $fields
     * @return self
     */
    public static function fromArray(array $fields): self {
        $targetCount = array_key_exists('targetCount', $fields) && $fields['targetCount'] !== null
            ? self::intField($fields, 'targetCount')
            : null;
        $type = null;
        if (array_key_exists('type', $fields) && $fields['type'] !== null) {
            if (!is_string($fields['type'])) {
                throw new InvalidArgumentException('NGramCountRangeQuery field must be string or null: type');
            }
            $type = $fields['type'];
        }
        return new self(
            self::intField($fields, 'minCount'),
            self::intField($fields, 'maxCount'),
            array_key_exists('limit', $fields)
                ? self::intField($fields, 'limit')
                : ABJ_404_Solution_NGramCacheRepository::CACHE_LOAD_LIMIT,
            $targetCount,
            $type
        );
    }

    /** @param array<string, mixed> $fields */
    private static function intField(array $fields, string $name): int {
        if (!array_key_exists($name, $fields)) {
            throw new InvalidArgumentException('NGramCountRangeQuery is missing required field: ' . $name);
        }
        if (!is_int($fields[$name])) {
            throw new InvalidArgumentException('NGramCountRangeQuery field must be int: ' . $name);
        }
        return $fields[$name];
    }

    public function minCount(): int {
        return $this->minCount;
    }

    public function maxCount(): int {
        return $this->maxCount;
    }

    public function limit(): int {
        return $this->limit;
    }

    public function type(): ?string {
        return $this->type;
    }

    /**
     * The ngram_count the two-range read orders around: the target clamped
     * into [minCount, maxCount], or the integer midpoint when no target was
     * given.
     */
    public function orderTarget(): int {
        if ($this->targetCount !== null) {
            return max($this->minCount, min($this->maxCount, $this->targetCount));
        }
        return (int)(($this->minCount + $this->maxCount) / 2);
    }
}
