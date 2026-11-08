<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * wpdb could not bind a statement the plugin built.
 *
 * `$wpdb->prepare()` returns an empty or non-string value only when the
 * template and its arguments disagree, which is the plugin's own fault and
 * never a host condition. It is therefore a LogicException, distinct from
 * ABJ_404_Solution_DatabaseMutationFailedException: a caller that treats a
 * refused write as "not stored" must not also swallow this, or a statement
 * that never reached the database is reported as an ordinary refusal.
 */
final class ABJ_404_Solution_StatementPreparationException extends LogicException {

    /** @var string */
    private $statementPurpose;

    /**
     * @param string $statementPurpose What the statement was for, e.g. "the serializable mutation".
     */
    public function __construct(string $statementPurpose) {
        parent::__construct('wpdb could not prepare ' . $statementPurpose . '.');
        $this->statementPurpose = $statementPurpose;
    }

    /** @return string What the statement was for. */
    public function getStatementPurpose(): string {
        return $this->statementPurpose;
    }

    /** @return string What a developer reading the log should check. */
    public function getHint(): string {
        return 'The placeholders in the SQL template do not match its arguments; this is a plugin defect, not a database refusal.';
    }
}
