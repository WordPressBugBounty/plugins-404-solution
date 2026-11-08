<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A guarded database mutation or its committed completion failed.
 *
 * Raised by DatabaseTransactionExecutor::executeSerializableMutation() when
 * the statement itself is refused, when its transaction could not be set up,
 * when a caller-owned transaction makes a new SERIALIZABLE scope unsafe,
 * or when completion failed after a successful commit. The latter preserves
 * every completion cause and must never authorize replay of the write.
 * The message is the database's own text, never a summary of it, so a caller
 * can show it or pattern-match it.
 *
 * It is a distinct type, not a bare Exception, so that a caller whose job
 * does not depend on the write (the frontend 404 pipeline recording a captured
 * row before it redirects) can treat exactly this refusal as "not stored",
 * without also swallowing a programming error.
 */
final class ABJ_404_Solution_DatabaseMutationFailedException extends RuntimeException {

    /** The database refused the statement, or the transaction around it. */
    public const STATEMENT_REFUSED = 'DB_MUTATION_STATEMENT_REFUSED';

    /** The transaction boundary or bounded metadata-lock wait could not be established. */
    public const SETUP_FAILED = 'DB_MUTATION_SETUP_FAILED';

    /** A caller-owned transaction is active, so a new SERIALIZABLE scope was not attempted. */
    public const AMBIENT_TRANSACTION = 'DB_MUTATION_AMBIENT_TRANSACTION';

    /** The transaction committed, but one or more completion callbacks failed. */
    public const POST_COMMIT_FAILED = 'DB_MUTATION_POST_COMMIT_FAILED';

    /** @var string */
    private $failureCode;

    /** @var array<string,mixed> */
    private $context;

    /**
     * @param string $failureCode STATEMENT_REFUSED, SETUP_FAILED, AMBIENT_TRANSACTION or POST_COMMIT_FAILED.
     * @param string $databaseError The database's own error text.
     * @param \Throwable|null $cause The exception that carried the error, when one did.
     * @param array<string,mixed> $context Structured failure context.
     */
    public function __construct(string $failureCode, string $databaseError, ?\Throwable $cause = null, array $context = array()) {
        parent::__construct($databaseError, 0, $cause);
        $this->failureCode = $failureCode;
        $this->context = $context;
    }

    /** @return string One of the class constants. */
    public function getFailureCode(): string {
        return $this->failureCode;
    }

    /**
     * @return array<string,mixed>
     */
    public function getContext(): array {
        return $this->context;
    }

    /** @return string What a person reading the log or plugin screen should do about it. */
    public function getHint(): string {
        $hints = array(
            self::STATEMENT_REFUSED => 'The database refused the write. A read-only, full or over-quota database pauses plugin writes and the plugin screen says so; any other refusal is a schema or statement fault.',
            self::SETUP_FAILED => 'The database transaction boundary could not be established; the write was not attempted.',
            self::AMBIENT_TRANSACTION => 'A caller-owned transaction is active; its owner retains commit and rollback control, and this write was not attempted.',
            self::POST_COMMIT_FAILED => 'The transaction committed, but completion failed. Do not replay the write; inspect the completion failure and reconcile derived state.',
        );
        return $hints[$this->failureCode] ?? 'The database mutation failed; inspect the recorded failure context before retrying.';
    }
}
