<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: real persisted-state, malformed-ID and storage-failure coverage in tests/MaintenanceContinuationIntegrationTest.php.
/**
 * Durable, cache-independent storage for one maintenance pass.
 * JSON is a versioned workflow record, not a WordPress serialized object.
 * Conditional writes cannot replace another pass or an unreadable record.
 */
final class ABJ_404_Solution_MaintenancePassRepository {
    public const OPTION = 'abj404_maintenance_pass';
    private string $lastFailure = '';

    /** The original storage error or conditional-write outcome from the last attempt. */
    public function lastFailureDetail(): string { return $this->lastFailure; }

    /** @return array<array-key,mixed>|null Null only when the row is absent. */
    public function read(): ?array {
        $this->lastFailure = '';
        $stored = ABJ_404_Solution_OptionRowCompareAndSwap::readResult(self::OPTION, $this->failureObserver());
        if ($stored['status'] === 'failed') {
            $this->lastFailure = $this->storageError('read failed');
            throw new ABJ_404_Solution_DatabaseMutationFailedException(
                ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED,
                'Maintenance pass could not be read: ' . $this->lastFailure
            );
        }
        if ($stored['status'] === 'absent') { return null; }
        $pass = json_decode($stored['value'], true);
        if (!is_array($pass)) {
            throw new UnexpectedValueException('Maintenance pass JSON is unreadable: ' . json_last_error_msg());
        }
        return $pass;
    }

    /**
     * Save only into the absent row or the pass identity the caller owns.
     * @param array<string,mixed> $pass
     */
    public function save(array $pass, ?string $expectedId): bool {
        $this->lastFailure = '';
        $encoded = json_encode($pass, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new UnexpectedValueException('Maintenance pass JSON could not be encoded: ' . json_last_error_msg());
        }
        $outcome = ABJ_404_Solution_OptionRowCompareAndSwap::update([
            'optionName' => self::OPTION,
            'onFailure' => $this->failureObserver(),
            'compute' => static function (?string $stored) use ($expectedId, $encoded): ?string {
                if ($stored === null) { return $expectedId === null ? $encoded : null; }
                $current = json_decode($stored, true);
                return is_array($current) && $expectedId !== null && ($current['id'] ?? null) === $expectedId
                    ? $encoded : null;
            },
        ]);
        $saved = $outcome === ABJ_404_Solution_OptionRowCompareAndSwap::RESULT_SWAPPED
            || ($outcome === ABJ_404_Solution_OptionRowCompareAndSwap::RESULT_UNCHANGED
                && ABJ_404_Solution_OptionRowCompareAndSwap::read(self::OPTION) === $encoded);
        if (!$saved) { $this->lastFailure = $this->storageError('conditional write ' . $outcome); }
        return $saved;
    }

    private function storageError(string $outcome): string {
        return $this->lastFailure !== '' ? $this->lastFailure : $outcome . ' for option ' . self::OPTION;
    }

    /** Capture the actual boundary result while it still exists. @return callable(string):void */
    private function failureObserver(): callable {
        return function (string $error): void {
            if ($this->lastFailure === '') { $this->lastFailure = $error; }
        };
    }
}
