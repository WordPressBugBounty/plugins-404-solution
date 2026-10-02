<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Which requests in a journal failed, split by how sure the journal is.
 *
 * Condemned means a failure finding was recorded. Suspected means the request
 * never reached a terminal record, so it may be a hang. A request is one or
 * the other, never both: condemned needs a failure finding, suspected needs
 * its absence.
 *
 * Holding both sets in one value keeps them from being passed around as two
 * adjacent arrays of the same type, where a swap still runs and silently
 * reorders the tiers or miscounts the accounting. The union of the two is
 * derived once here for the same reason.
 */
final class ABJ_404_Solution_DiagnosticFailureClassification {

    /** @var array<string, bool> */
    private $condemned;

    /** @var array<string, bool> */
    private $suspected;

    /** @var array<string, bool> */
    private $failing;

    /**
     * @param array<string, bool> $condemned Request ids with a recorded failure, keyed by id.
     * @param array<string, bool> $suspected Request ids with no terminal record, keyed by id.
     */
    public function __construct(array $condemned, array $suspected) {
        $this->condemned = $condemned;
        // A condemned request is never also suspected, whatever the caller passed.
        $this->suspected = array_diff_key($suspected, $condemned);
        $this->failing = $this->condemned + $this->suspected;
    }

    /**
     * @param array<string, ABJ_404_Solution_DiagnosticRequestGroup> $groups
     */
    public static function fromGroups(array $groups): self {
        $condemned = array();
        $suspected = array();
        foreach ($groups as $id => $group) {
            if ($group->isCondemned()) {
                $condemned[$id] = true;
            } elseif ($group->isSuspectedHang()) {
                $suspected[$id] = true;
            }
        }
        return new self($condemned, $suspected);
    }

    public function isCondemned(string $requestId): bool {
        return isset($this->condemned[$requestId]);
    }

    public function isSuspected(string $requestId): bool {
        return isset($this->suspected[$requestId]);
    }

    /** @return array<string, bool> */
    public function condemnedIds(): array {
        return $this->condemned;
    }

    /** @return array<string, bool> Condemned first, then suspected. */
    public function failingIds(): array {
        return $this->failing;
    }
}
