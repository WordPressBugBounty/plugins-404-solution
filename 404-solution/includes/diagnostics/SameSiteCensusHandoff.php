<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../database/DatabaseMutationFailedException.php';
require_once __DIR__ . '/../database/DatabaseTransactionConnection.php';
require_once __DIR__ . '/../services/OptionCacheInvalidator.php';

/**
 * The durable handoff of one abandoned census row into the stranded-request
 * ledger.
 *
 * Split out of ABJ_404_Solution_SameSiteRequestRegistry, which keeps the row
 * store: the names, the writes, the reads and the retirement of in-flight
 * rows. A handoff is a different kind of work. It is one serializable
 * transaction on a pinned native session that renames the stale source row
 * into the pending namespace, appends its account to
 * ABJ_404_Solution_StrandedRequestLedger and deletes the pending row, so the
 * evidence is durable before the row that carried it is gone. Its only caller
 * is ABJ_404_Solution_SameSiteCensusReaper.
 *
 * The dependency is one-directional: this class reads the registry's row-name
 * prefixes, the registry knows nothing about it, and the reaper reaches it
 * through a class_exists capability gate so a mixed-generation deployment
 * during file replacement degrades to "no handoff" rather than a fatal.
 *
 * @phpstan-import-type NativeResult from ABJ_404_Solution_DatabaseTransactionConnection
 */
final class ABJ_404_Solution_SameSiteCensusHandoff {

    /**
     * Atomically hand one stale row to the bounded ledger and remove it.
     *
     * Source rename, ledger append and pending delete all run on one pinned
     * native session. The source name and raw value are checked again under a
     * row lock, and the table engine is proved to be InnoDB before the first
     * mutation. A failed statement or commit is reported as unavailable so a
     * later bounded sweep can retry the unchanged source/pending row.
     *
     * @param array<string,mixed> $entry
     * @return 'recorded'|'already_durable'|'unavailable'
     */
    public static function run(array $entry): string {
        $optionName = isset($entry['option_name']) && is_string($entry['option_name'])
            ? $entry['option_name'] : '';
        $rawValue = isset($entry['raw_value']) && is_string($entry['raw_value'])
            ? $entry['raw_value'] : '';
        $isPending = strpos($optionName, ABJ_404_Solution_SameSiteRequestRegistry::PENDING_PREFIX) === 0;
        if ($rawValue === '' || ($isPending
                ? $optionName === ''
                : strpos($optionName, ABJ_404_Solution_SameSiteRequestRegistry::OPTION_PREFIX) !== 0)) {
            return 'unavailable';
        }
        $dbCore = self::dbCore();
        if ($dbCore === null || !class_exists('ABJ_404_Solution_StrandedRequestLedger')) {
            return 'unavailable';
        }
        global $wpdb;
        if (!is_object($wpdb) || !is_callable(array($wpdb, 'prepare'))) {
            return 'unavailable';
        }
        $table = $dbCore->doTableNameReplacements('{wp_options}');
        if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return 'unavailable';
        }
        $pendingName = $isPending
            ? $optionName
            : ABJ_404_Solution_SameSiteRequestRegistry::PENDING_PREFIX . hash('sha256', $optionName);
        try {
            if (!is_callable(array($dbCore, 'transactionExecutor'))
                    || !is_callable(array('ABJ_404_Solution_StrandedRequestLedger', 'recordHandoffInTransaction'))
                    || !is_callable(array('ABJ_404_Solution_StrandedRequestLedger', 'reportCommittedHandoff'))) {
                return 'unavailable';
            }
            $executor = $dbCore->transactionExecutor();
            return $executor->runSerializableTransaction(function ($connection) use (
                $entry, $optionName, $rawValue, $pendingName, $isPending, $table, $wpdb, $executor
            ): string {
                self::lockExactRow($connection, $wpdb, $table, $optionName, $rawValue);
                // Lock the exact source row before the engine proof. SHOW TABLE
                // STATUS does not hold the metadata lock needed to prevent a
                // concurrent ALTER from changing the engine between statements.
                self::requireInnoDb($connection, $wpdb, $table);
                if (!$isPending) {
                    $renamed = self::executePrepared($connection, $wpdb,
                        "UPDATE `" . $table . "` SET option_name = %s "
                            . "WHERE option_name = %s AND " . ABJ_404_Solution_StoredValueMatch::equals('option_name', '%s') . " "
                            . "AND " . ABJ_404_Solution_StoredValueMatch::equals('option_value', '%s'),
                        array($pendingName, $optionName, $optionName, $rawValue));
                    if ($renamed['rows_affected'] !== 1) {
                        throw self::mutationFailure('The stale census source row was not renamed.');
                    }
                }

                $status = ABJ_404_Solution_StrandedRequestLedger::recordHandoffInTransaction(
                    $connection, $entry, $pendingName, $table, $wpdb);
                if ($status === 'unavailable') {
                    throw self::mutationFailure('The stranded-request ledger was not updated.');
                }
                $deleted = self::executePrepared($connection, $wpdb,
                    "DELETE FROM `" . $table . "` WHERE option_name = %s "
                        . "AND " . ABJ_404_Solution_StoredValueMatch::equals('option_name', '%s') . " "
                        . "AND " . ABJ_404_Solution_StoredValueMatch::equals('option_value', '%s'),
                    array($pendingName, $pendingName, $rawValue));
                if ($deleted['rows_affected'] !== 1) {
                    throw self::mutationFailure('The pending census handoff was not deleted.');
                }
                if ($status === 'recorded') {
                    $executor->afterCommit(static function () use ($entry, $optionName, $pendingName): void {
                        self::invalidateCommittedOptions(array(
                            $optionName, $pendingName,
                            ABJ_404_Solution_StrandedRequestLedger::OPTION_NAME,
                        ));
                        ABJ_404_Solution_StrandedRequestLedger::reportCommittedHandoff($entry);
                    });
                } else {
                    $executor->afterCommit(static function () use ($optionName, $pendingName): void {
                        self::invalidateCommittedOptions(array($optionName, $pendingName));
                    });
                }
                return $status;
            });
        } catch (Throwable $e) {
            abj404_logPhpFallback('same-site-census-reaper',
                'durable census handoff failed (code ' . $e->getCode() . '): ' . $e->getMessage());
            return 'unavailable';
        }
    }

    /** A destructive handoff requires a complete, successful SQL envelope. */
    /** @param array<string,mixed> $result */
    private static function querySucceeded(array $result): bool {
        return array_key_exists('query_succeeded', $result)
            && $result['query_succeeded'] === true
            && array_key_exists('last_error', $result)
            && is_string($result['last_error'])
            && $result['last_error'] === ''
            && (!array_key_exists('timed_out', $result) || $result['timed_out'] === false);
    }

    /** @throws ABJ_404_Solution_DatabaseMutationFailedException */
    private static function requireInnoDb(
        ABJ_404_Solution_DatabaseTransactionConnection $connection,
        object $wpdb,
        string $table
    ): void {
        $status = self::executePrepared($connection, $wpdb,
            'SHOW TABLE STATUS WHERE Name = %s', array($table));
        $rows = $status['rows'];
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new ABJ_404_Solution_DatabaseMutationFailedException(
                ABJ_404_Solution_DatabaseMutationFailedException::SETUP_FAILED,
                'The census options table metadata could not be proved.');
        }
        $row = array_change_key_case($rows[0], CASE_LOWER);
        $engine = isset($row['engine']) && is_scalar($row['engine']) ? (string)$row['engine'] : '';
        if (strcasecmp($engine, 'InnoDB') !== 0) {
            throw new ABJ_404_Solution_DatabaseMutationFailedException(
                ABJ_404_Solution_DatabaseMutationFailedException::SETUP_FAILED,
                'The census options table is not InnoDB.');
        }
    }

    /** @throws ABJ_404_Solution_DatabaseMutationFailedException */
    private static function lockExactRow(
        ABJ_404_Solution_DatabaseTransactionConnection $connection,
        object $wpdb,
        string $table,
        string $name,
        string $raw
    ): void {
        $result = self::executePrepared($connection, $wpdb,
            "SELECT option_name, option_value FROM `" . $table . "` "
                . "WHERE option_name = %s AND " . ABJ_404_Solution_StoredValueMatch::equals('option_name', '%s') . " "
                . "AND " . ABJ_404_Solution_StoredValueMatch::equals('option_value', '%s') . " FOR UPDATE",
            array($name, $name, $raw));
        if (count($result['rows']) !== 1) {
            throw self::mutationFailure('The census source row changed after it was read.');
        }
        $row = is_array($result['rows'][0])
            ? array_change_key_case($result['rows'][0], CASE_LOWER) : null;
        if (!is_array($row) || !isset($row['option_name'], $row['option_value'])
                || (string)$row['option_name'] !== $name || (string)$row['option_value'] !== $raw) {
            throw self::mutationFailure('The census source row changed after it was read.');
        }
    }

    /**
     * @param object $wpdb WordPress-compatible database envelope.
     * @param array<int,mixed> $params
     * @return NativeResult
     */
    private static function executePrepared(
        ABJ_404_Solution_DatabaseTransactionConnection $connection,
        object $wpdb,
        string $sql,
        array $params
    ): array {
        $prepare = array($wpdb, 'prepare');
        if (!is_callable($prepare)) {
            throw self::mutationFailure('The census handoff SQL preparer is unavailable.');
        }
        $prepared = Closure::fromCallable($prepare)($sql, ...$params);
        if (!is_string($prepared) || $prepared === '') {
            throw self::mutationFailure('The census handoff SQL could not be prepared.');
        }
        $result = $connection->execute($prepared);
        if (!self::querySucceeded($result) || !array_key_exists('rows', $result)
                || !is_array($result['rows'])) {
            throw self::mutationFailure($result['last_error'] !== ''
                ? $result['last_error'] : 'The census handoff SQL was refused.');
        }
        return $result;
    }

    private static function mutationFailure(string $message): ABJ_404_Solution_DatabaseMutationFailedException {
        return new ABJ_404_Solution_DatabaseMutationFailedException(
            ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED, $message);
    }

    /** Invalidate direct native writes only after their transaction commits. */
    /** @param array<int,string> $optionNames */
    private static function invalidateCommittedOptions(array $optionNames): void {
        if (!is_callable(array('ABJ_404_Solution_OptionCacheInvalidator', 'site'))) {
            return;
        }
        foreach (array_unique($optionNames) as $optionName) {
            if (is_string($optionName) && $optionName !== '') {
                ABJ_404_Solution_OptionCacheInvalidator::site($optionName);
            }
        }
    }

    /**
     * Read straight from the container, as the registry does: the census runs
     * on every in-scope request, so instrumentation must not need the
     * presentation surface loaded in order to read a service.
     *
     * @return ABJ_404_Solution_DatabaseQueryInterface|null
     */
    private static function dbCore() {
        if (!class_exists('ABJ_404_Solution_ServiceContainer')) {
            return null;
        }
        $dbCore = ABJ_404_Solution_ServiceContainer::safeGet('db_core');
        return ($dbCore instanceof ABJ_404_Solution_DatabaseQueryInterface) ? $dbCore : null;
    }
}
