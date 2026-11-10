<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Conditional storage mutations used by legacy generated-page recovery. */
final class ABJ_404_Solution_LegacyPageRecoveryMutation {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_SiteResourceLock */
    private $lock;

    /** @var string */
    private $postsTable;

    /** @var string */
    private $postmetaTable;

    /** @var string */
    private $optionsTable;

    /** @var string */
    private $settingsRaw;

    /** @var array<int, string> */
    private const SNAPSHOT_COLUMNS = array(
        'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title',
        'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_password',
        'post_name', 'to_ping', 'pinged', 'post_modified', 'post_modified_gmt',
        'post_content_filtered', 'post_parent', 'guid', 'menu_order', 'post_type',
        'post_mime_type', 'comment_count',
    );

    public const APPLIED = 'applied';
    public const CHANGED = 'changed';
    public const FAILED = 'failed';

    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore, ABJ_404_Solution_SiteResourceLock $lock,
        string $settingsRaw) {
        $this->dbCore = $dbCore;
        $this->lock = $lock;
        $this->postsTable = $this->captureTable('{wp_posts}', 'the posts table');
        $this->postmetaTable = $this->captureTable('{wp_postmeta}', 'the postmeta table');
        $this->optionsTable = $this->captureTable('{wp_options}', 'the options table');
        $this->settingsRaw = $settingsRaw;
    }

    /** @return array{meta_id:int,meta_value:string}|null */
    public function readMetadata(WP_Post $post, string $key): ?array {
        $query = 'SELECT meta_id, meta_value FROM ' . $this->postmetaTable
            . ' WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1';
        $result = $this->dbCore->queryAndGetResults($query, array(
            'query_params' => array((int)$post->ID, $key),
        ));
        $failure = $this->readQueryFailure($result, 'reading metadata for page ' . (int)$post->ID);
        if ($failure !== '') {
            throw $this->refusal($failure);
        }
        $rows = $result['rows'] ?? null;
        if (!is_array($rows)) {
            throw $this->refusal('reading metadata for page ' . (int)$post->ID . ' returned an invalid row set');
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw $this->refusal('reading metadata for page ' . (int)$post->ID . ' returned an invalid row');
            }
        }
        $row = $rows[0] ?? null;
        if ($row === null) {
            return null;
        }
        if (!array_key_exists('meta_id', $row) || !array_key_exists('meta_value', $row)
            || !is_scalar($row['meta_id']) || !is_scalar($row['meta_value'])) {
            throw $this->refusal('reading metadata for page ' . (int)$post->ID . ' returned an invalid row');
        }
        return array('meta_id' => $this->intValue($row['meta_id']), 'meta_value' => (string)$row['meta_value']);
    }

    /**
     * @param array{snapshot:WP_Post,metaKey:string,storedValue:string,metaId:int,
     *   oldValue:string,systemTagKey:string} $context
     * @return array{kind:string,meta_id?:int}
     */
    public function updateMetadata(array $context): array {
        $before = $context['snapshot'];
        $key = $context['metaKey'];
        $stored = $context['storedValue'];
        $metaId = $context['metaId'];
        $oldValue = $context['oldValue'];
        $systemTagKey = $context['systemTagKey'];
        $params = array($systemTagKey, $stored, $metaId, $oldValue, $key);
        $predicate = $this->snapshotPredicate($before, 'p', $params);
        $settingsPredicate = $this->settingsPredicate($params);
        $query = 'UPDATE ' . $this->postmetaTable . ' AS m JOIN ' . $this->postsTable . ' AS p ON p.ID = m.post_id'
            . ' LEFT JOIN ' . $this->postmetaTable . ' AS tag ON tag.post_id = p.ID AND tag.meta_key = %s'
            . ' SET m.meta_value = %s WHERE m.meta_id = %d AND '
            . ABJ_404_Solution_StoredValueMatch::equals('m.meta_value', '%s')
            . ' AND ' . ABJ_404_Solution_StoredValueMatch::equals('m.meta_key', '%s')
            . ' AND ' . $predicate
            . ' AND tag.meta_id IS NULL AND ' . $settingsPredicate
            . ' AND ' . $this->lock->ownershipPredicate();
        $result = $this->dbCore->queryAndGetResults($query, array('query_params' => $params));
        $operation = 'updating metadata for page ' . (int)$before->ID;
        $failure = $this->mutationQueryFailure($result, $operation);
        if ($failure !== '') {
            $this->invalidateUncertainMutation($before, true);
            return $this->failed($failure);
        }
        $affected = $this->affectedRows($result);
        if ($affected === null) {
            $this->invalidateUncertainMutation($before, true);
            return $this->failed($operation . ' returned malformed affected-row metadata');
        }
        if ($affected !== 1) {
            return $this->zeroRowsOutcome($operation);
        }
        return array('kind' => self::APPLIED, 'meta_id' => $metaId);
    }

    /**
     * @param array{snapshot:WP_Post,metaKey:string,storedValue:string,systemTagKey:string} $context
     * @return array{kind:string,meta_id?:int}
     */
    public function insertMetadata(array $context): array {
        $before = $context['snapshot'];
        $key = $context['metaKey'];
        $stored = $context['storedValue'];
        $systemTagKey = $context['systemTagKey'];
        $params = array($key, $stored);
        $predicate = $this->snapshotPredicate($before, 'p', $params);
        $params[] = $key;
        $params[] = $systemTagKey;
        $settingsPredicate = $this->settingsPredicate($params);
        $query = 'INSERT INTO ' . $this->postmetaTable . ' (post_id, meta_key, meta_value)'
            . ' SELECT p.ID, %s, %s FROM ' . $this->postsTable . ' AS p WHERE ' . $predicate
            . ' AND NOT EXISTS (SELECT 1 FROM ' . $this->postmetaTable . ' AS target'
            . ' WHERE target.post_id = p.ID AND target.meta_key = %s)'
            . ' AND NOT EXISTS (SELECT 1 FROM ' . $this->postmetaTable . ' AS tag'
            . ' WHERE tag.post_id = p.ID AND tag.meta_key = %s)'
            . ' AND ' . $settingsPredicate
            . ' AND ' . $this->lock->ownershipPredicate();
        try {
            $result = $this->dbCore->transactionExecutor()->executeSerializableMutation(array(
                'sql' => $query,
                'params' => $params,
                'description' => 'atomically recovering legacy page metadata',
            ));
        } catch (ABJ_404_Solution_DatabaseMutationFailedException $failure) {
            return $this->failed('inserting metadata for page ' . (int)$before->ID . ': ' . $failure->getMessage());
        }
        if ($this->intValue($result['rows_affected'] ?? 0) !== 1) {
            return $this->zeroRowsOutcome('metadata insert for page ' . (int)$before->ID);
        }
        return array('kind' => self::APPLIED, 'meta_id' => $this->intValue($result['insert_id'] ?? 0));
    }

    /**
     * @param array{snapshot:WP_Post,systemTagKey:string} $context
     * @return array{kind:string}
     */
    public function draftIfUnchanged(array $context): array {
        $before = $context['snapshot'];
        $systemTagKey = $context['systemTagKey'];
        $params = array();
        $where = array($this->snapshotPredicate($before, '', $params));
        $where[] = 'NOT EXISTS (SELECT 1 FROM ' . $this->postmetaTable . ' WHERE post_id = %d AND meta_key = %s)';
        $params[] = (int)$before->ID;
        $params[] = $systemTagKey;
        $settingsPredicate = $this->settingsPredicate($params);
        $where[] = $settingsPredicate;
        $where[] = $this->lock->ownershipPredicate();
        $query = "UPDATE " . $this->postsTable . " SET post_status = 'draft' WHERE " . implode(' AND ', $where);
        $result = $this->dbCore->queryAndGetResults($query, array('query_params' => $params));
        $operation = 'status update for page ' . (int)$before->ID;
        $failure = $this->mutationQueryFailure($result, 'drafting page ' . (int)$before->ID);
        if ($failure !== '') {
            $this->invalidateUncertainMutation($before, false);
            return $this->failed($failure);
        }
        $affected = $this->affectedRows($result);
        if ($affected === null) {
            $this->invalidateUncertainMutation($before, false);
            return $this->failed($operation . ' returned malformed affected-row metadata');
        }
        if ($affected !== 1) {
            return $this->zeroRowsOutcome($operation);
        }
        return array('kind' => self::APPLIED);
    }

    /** @param array<int,mixed> $params */
    private function snapshotPredicate(WP_Post $before, string $alias, array &$params): string {
        $prefix = $alias === '' ? '' : rtrim($alias, '.') . '.';
        $where = array($prefix . 'ID = %d');
        $params[] = (int)$before->ID;
        foreach (self::SNAPSHOT_COLUMNS as $column) {
            if (!isset($before->$column) || !is_scalar($before->$column)) {
                throw $this->refusal('incomplete post snapshot for page ' . (int)$before->ID . ', column ' . $column);
            }
            $where[] = ABJ_404_Solution_StoredValueMatch::equals($prefix . '`' . $column . '`', '%s');
            $params[] = (string)$before->$column;
        }
        return implode(' AND ', $where);
    }

    /** @return ABJ_404_Solution_DatabaseMutationFailedException */
    private function refusal(string $message): ABJ_404_Solution_DatabaseMutationFailedException {
        return new ABJ_404_Solution_DatabaseMutationFailedException(
            ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED, $message
        );
    }

    /** @return array{kind:string} */
    private function failed(string $message): array {
        abj404_logPhpFallback('system-page-recovery', $message);
        return array('kind' => self::FAILED);
    }

    /** @return array{kind:string} */
    private function zeroRowsOutcome(string $operation): array {
        if (!$this->lock->isOwned()) {
            return $this->failed($operation . ' refused because initialization lock ownership was lost');
        }
        if ($this->settingsRaw === '' || $this->settingsGuardStillMatches() !== true) {
            return $this->failed($operation . ' refused because the settings destination changed or could not be verified');
        }
        return array('kind' => self::CHANGED);
    }

    /** @param array<int,mixed> $params */
    private function settingsPredicate(array &$params): string {
        if ($this->settingsRaw === '') {
            return '1 = 0';
        }
        $params[] = 'abj404_settings';
        $params[] = $this->settingsRaw;
        return 'EXISTS (SELECT 1 FROM ' . $this->optionsTable
            . ' AS settings WHERE settings.option_name = %s AND '
            . ABJ_404_Solution_StoredValueMatch::equals('settings.option_value', '%s') . ')';
    }

    /** @return bool|null */
    private function settingsGuardStillMatches(): ?bool {
        $result = $this->dbCore->queryAndGetResults(
            'SELECT option_value FROM ' . $this->optionsTable
            . ' WHERE option_name = %s LIMIT 1',
            array('query_params' => array('abj404_settings'))
        );
        if ($this->readQueryFailure($result, 'checking the settings destination after a refused mutation') !== '') {
            return null;
        }
        $rows = $result['rows'] ?? null;
        if (!is_array($rows) || count($rows) !== 1 || !is_array($rows[0])
            || !array_key_exists('option_value', $rows[0])
            || !is_scalar($rows[0]['option_value'])) {
            return null;
        }
        return (string)$rows[0]['option_value'] === $this->settingsRaw;
    }

    /** @param mixed $result @return string Empty means the mutation was confirmed successful. */
    private function mutationQueryFailure($result, string $operation): string {
        if (!is_array($result)) {
            return $operation . ' returned an invalid result envelope';
        }
        $error = $result['last_error'] ?? '';
        if (!is_string($error)) {
            return $operation . ' returned malformed error metadata';
        }
        if ($error !== '') {
            return $operation . ': ' . $error;
        }
        if (array_key_exists('timed_out', $result) && !is_bool($result['timed_out'])) {
            return $operation . ' returned malformed timeout metadata';
        }
        if (($result['timed_out'] ?? false) === true) {
            return $operation . ' timed out';
        }
        if (!array_key_exists('query_succeeded', $result) || !is_bool($result['query_succeeded'])) {
            return $operation . ' returned unknown query status';
        }
        if ($result['query_succeeded'] !== true) {
            return $operation . ' failed without a database error';
        }
        return '';
    }

    /** @param mixed $result @return int|null */
    private function affectedRows($result): ?int {
        if (!is_array($result) || !array_key_exists('rows_affected', $result)) {
            return null;
        }
        return ABJ_404_Solution_ExactInteger::read($result['rows_affected'], 0);
    }

    /** @param array<string,mixed> $result */
    private function readQueryFailure(array $result, string $operation): string {
        $failure = $this->mutationQueryFailure($result, $operation);
        if ($failure !== '') {
            return $failure;
        }
        if (!array_key_exists('rows', $result) || !is_array($result['rows'])) {
            return $operation . ' returned an invalid row set';
        }
        return '';
    }

    private function invalidateUncertainMutation(WP_Post $before, bool $metadata): void {
        $postId = (int)$before->ID;
        if ($metadata && function_exists('wp_cache_delete')) {
            wp_cache_delete($postId, 'post_meta');
        }
        if (function_exists('clean_post_cache')) {
            clean_post_cache($postId);
        }
    }

    private function captureTable(string $token, string $purpose): string {
        $table = $this->dbCore->doTableNameReplacements($token);
        if ($table === '' || strpos($table, '{') !== false || strpos($table, '}') !== false) {
            throw new ABJ_404_Solution_StatementPreparationException($purpose);
        }
        return $table;
    }

    /** @param mixed $value */
    private function intValue($value): int {
        return ABJ_404_Solution_ExactInteger::readOr($value, 0, 0);
    }
}
