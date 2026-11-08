<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The conditions[] payload of an Edit Redirect submission, reduced to what the
 * redirects repository accepts.
 *
 * Whitelists condition types and operators and coerces logic to AND/OR, so
 * anything the form could not have produced is dropped before it reaches the
 * database write. Pure request interpretation: it reads the array it is given
 * and returns a value, with no database access, logging or user-facing copy.
 *
 * Reports how many were dropped for being over the maximum, separately from
 * the ones skipped for being unrecognized. The two are not the same event: an
 * unknown condition type is garbage and dropping it silently is right, while a
 * VALID rule dropped for arriving 51st changes how the redirect behaves versus
 * what the admin configured, and the only signal they would otherwise get is
 * the rows being absent next time they happen to open the screen.
 */
final class ABJ_404_Solution_SubmittedRedirectConditions {

    /**
     * Cardinality cap on the conditions[] POST payload. Every accepted
     * condition becomes its own INSERT statement inside
     * RedirectConditionsRepository::saveRedirectConditions()'s replacement
     * transaction, so this bounds the SQL workload (and in-memory statement
     * array) a single edit request can generate. The admin UI's "+ Add
     * Condition" button has no client-side cap, but a redirect with dozens
     * of conditions is already an edge case -- 50 is comfortably above any
     * realistic legitimate use while still rejecting a forged or accidental
     * oversized request (e.g. 50,000 conditions).
     */
    const MAX_CONDITIONS = 50;

    /** @var array<int, array<string, mixed>> */
    private $conditions;

    /** @var int */
    private $droppedOverMaximum;

    /**
     * @param array<int, array<string, mixed>> $conditions Sanitized conditions, in submitted order.
     * @param int $droppedOverMaximum How many valid-looking conditions were cut for arriving past the cap.
     */
    private function __construct(array $conditions, int $droppedOverMaximum) {
        $this->conditions = $conditions;
        $this->droppedOverMaximum = $droppedOverMaximum;
    }

    /**
     * Sanitize the conditions[] POST payload into the shape redirectsRepo
     * accepts. Whitelists condition types and operators; coerces logic to
     * AND/OR.
     *
     * @param array<mixed, mixed> $rawConditions The raw conditions[] array from the request.
     * @return self
     */
    public static function fromRawConditions(array $rawConditions): self {
        // Enforce the domain maximum before sanitizing/inserting, rather than
        // rejecting the entire edit over an oversized payload: the rest of the
        // save is the admin's real work and is valid.
        $droppedOverMaximum = max(0, count($rawConditions) - self::MAX_CONDITIONS);
        if ($droppedOverMaximum > 0) {
            $rawConditions = array_slice($rawConditions, 0, self::MAX_CONDITIONS);
        }
        $sanitizedConditions = [];
        $allowedConditionTypes = [
            'login_status', 'user_role', 'referrer',
            'user_agent', 'ip_range', 'http_header',
        ];
        $allowedOperators = [
            'equals', 'not_equals', 'contains',
            'not_contains', 'regex', 'cidr',
        ];
        foreach ($rawConditions as $rawCond) {
            if (!is_array($rawCond)) {
                continue;
            }
            $condType = isset($rawCond['condition_type']) && is_string($rawCond['condition_type'])
                ? sanitize_text_field($rawCond['condition_type']) : '';
            if (!in_array($condType, $allowedConditionTypes, true)) {
                continue;
            }
            $condLogic = (isset($rawCond['logic']) && is_string($rawCond['logic']) && strtoupper($rawCond['logic']) === 'OR') ? 'OR' : 'AND';
            $condOperator = isset($rawCond['operator']) && is_string($rawCond['operator'])
                ? sanitize_text_field($rawCond['operator']) : 'equals';
            if (!in_array($condOperator, $allowedOperators, true)) {
                $condOperator = 'equals';
            }
            $condValue = isset($rawCond['value']) && is_string($rawCond['value'])
                ? sanitize_text_field(wp_unslash($rawCond['value'])) : '';
            $condSortOrder = isset($rawCond['sort_order']) && is_scalar($rawCond['sort_order'])
                ? absint($rawCond['sort_order']) : 0;

            $sanitizedConditions[] = [
                'logic'          => $condLogic,
                'condition_type' => $condType,
                'operator'       => $condOperator,
                'value'          => $condValue,
                'sort_order'     => $condSortOrder,
            ];
        }
        return new self($sanitizedConditions, $droppedOverMaximum);
    }

    /** @return array<int, array<string, mixed>> The conditions to save, in submitted order. */
    public function conditions(): array {
        return $this->conditions;
    }

    /** @return int How many valid conditions were not kept because they arrived past MAX_CONDITIONS. */
    public function droppedOverMaximum(): int {
        return $this->droppedOverMaximum;
    }
}
