<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads and normalizes Linux procfs pressure evidence for HostPressureSampler.
 *
 * Procfs is optional and can disappear behind open_basedir, hidepid, or a
 * transient process exit. Every filesystem operation is scoped by the
 * existing warning-capture service so an unavailable reading retains the
 * operation and warning without exposing raw procfs contents.
 */
final class ABJ_404_Solution_HostProcfsPressureProbe {

    /** @var array<string, array<string, mixed>> */
    private static $sameUidProcessesByRequest = array();

    /** @return array<string, mixed> */
    public static function capture(string $procRoot, string $requestScope = ''): array {
        $procRoot = rtrim($procRoot, '/\\');
        return array(
            'proc_loadavg' => self::procLoadAverage($procRoot . '/loadavg'),
            'proc_self_status' => self::procSelfStatus($procRoot . '/self/status'),
            'proc_self_limits' => self::procSelfLimits($procRoot . '/self/limits'),
            'proc_self_cgroup' => self::procSelfCgroup($procRoot . '/self/cgroup'),
            'same_uid_processes' => self::sameUidProcesses($procRoot, $requestScope),
        );
    }

    /** Ends the cached observation for a logical request scope before reuse. */
    public static function resetCache(): void {
        self::$sameUidProcessesByRequest = array();
    }

    /** @return array<string, mixed> */
    private static function procLoadAverage(string $path): array {
        $read = self::readProcFile($path);
        if ($read['raw'] === null) {
            return self::unavailable('not_readable', array($path => 'not_readable'), $read['context']);
        }
        $parts = preg_split('/\s+/', trim($read['raw']));
        $processes = is_array($parts) && isset($parts[3]) ? explode('/', (string)$parts[3], 2) : array();
        if (!is_array($parts) || count($parts) < 5 || !is_numeric($parts[0])
                || !is_numeric($parts[1]) || !is_numeric($parts[2])
                || count($processes) !== 2 || !is_numeric($processes[0])
                || !is_numeric($processes[1]) || !is_numeric($parts[4])) {
            return self::unavailable('invalid_format', array($path => 'invalid_format'));
        }
        return array(
            'status' => 'available',
            'one_min' => (float)$parts[0],
            'five_min' => (float)$parts[1],
            'fifteen_min' => (float)$parts[2],
            'running_processes' => (int)$processes[0],
            'total_processes' => (int)$processes[1],
            'last_pid' => (int)$parts[4],
        );
    }

    /** @return array<string, mixed> */
    private static function procSelfStatus(string $path): array {
        $read = self::readProcFile($path);
        if ($read['raw'] === null) {
            return self::unavailable('not_readable', array($path => 'not_readable'), $read['context']);
        }
        $wanted = array_flip(array(
            'State', 'Threads', 'VmPeak', 'VmSize', 'VmRSS', 'VmSwap',
            'voluntary_ctxt_switches', 'nonvoluntary_ctxt_switches',
        ));
        $values = array();
        foreach (preg_split('/\R/', $read['raw']) ?: array() as $line) {
            $pair = explode(':', (string)$line, 2);
            $name = $pair[0] ?? '';
            if (isset($wanted[$name]) && isset($pair[1])) {
                $values[$name] = substr(trim($pair[1]), 0, 64);
            }
        }
        if ($values === array()) {
            return self::unavailable('no_supported_fields', array($path => 'no_supported_fields'));
        }
        return array('status' => 'available', 'values' => $values);
    }

    /** @return array<string, mixed> */
    private static function procSelfLimits(string $path): array {
        $read = self::readProcFile($path);
        if ($read['raw'] === null) {
            return self::unavailable('not_readable', array($path => 'not_readable'), $read['context']);
        }
        $names = array(
            'Max processes' => 'RLIMIT_NPROC',
            'Max address space' => 'RLIMIT_AS',
            'Max open files' => 'RLIMIT_NOFILE',
        );
        $limits = array_fill_keys(array_values($names), self::unavailable('not_reported', array(
            $path => 'limit_not_reported',
        )));
        $recognized = false;
        foreach (preg_split('/\R/', $read['raw']) ?: array() as $line) {
            foreach ($names as $label => $constant) {
                if (strpos((string)$line, $label) !== 0) {
                    continue;
                }
                $recognized = true;
                $parts = preg_split('/\s+/', trim(substr((string)$line, strlen($label))));
                if (!is_array($parts) || count($parts) !== 3
                        || preg_match('/^(?:unlimited|\d+)$/', $parts[0]) !== 1
                        || preg_match('/^(?:unlimited|\d+)$/', $parts[1]) !== 1
                        || preg_match('/^[a-z]+$/i', $parts[2]) !== 1) {
                    $limits[$constant] = self::unavailable('invalid_format', array(
                        $path . ':' . $label => 'invalid_format',
                    ));
                    continue;
                }
                $limits[$constant] = array(
                    'status' => 'available',
                    'soft_limit' => $parts[0],
                    'hard_limit' => $parts[1],
                    'unit' => $parts[2],
                );
            }
        }
        if (!$recognized) {
            $reason = trim($read['raw']) === '' ? 'empty_file' : 'no_supported_limits';
            return self::unavailable($reason, array($path => $reason));
        }
        return array('status' => 'available', 'limits' => $limits);
    }

    /** @return array<string, mixed> */
    private static function procSelfCgroup(string $path): array {
        $read = self::readProcFile($path);
        if ($read['raw'] === null) {
            return self::unavailable('not_readable', array($path => 'not_readable'), $read['context']);
        }
        $memberships = array();
        $invalidLines = 0;
        foreach (preg_split('/\R/', trim($read['raw'])) ?: array() as $line) {
            if ($line === '') {
                continue;
            }
            $parts = explode(':', (string)$line, 3);
            $controllers = count($parts) === 3 && $parts[1] !== '' ? explode(',', $parts[1]) : array();
            $controllersValid = array_filter($controllers, static function ($controller): bool {
                return preg_match('/^[a-z0-9_.=-]+$/i', (string)$controller) !== 1;
            }) === array();
            if (count($parts) !== 3 || preg_match('/^\d+$/', $parts[0]) !== 1
                    || !$controllersValid || strpos($parts[2], '/') !== 0) {
                $invalidLines++;
                continue;
            }
            $memberships[] = array(
                'hierarchy_id' => $parts[0],
                'controllers' => $controllers,
                'path' => self::readableScalar($parts[2], 256),
            );
        }
        if ($memberships === array()) {
            $reason = trim($read['raw']) === '' ? 'empty_file' : 'invalid_format';
            return self::unavailable($reason, array($path => $reason));
        }
        return array(
            'status' => 'available',
            'memberships' => $memberships,
            'invalid_lines' => $invalidLines,
        );
    }

    /** @return array<string, mixed> */
    private static function sameUidProcesses(string $procRoot, string $requestScope): array {
        $cacheKey = hash('sha256', $requestScope . "\0" . $procRoot);
        if (array_key_exists($cacheKey, self::$sameUidProcessesByRequest)) {
            return self::$sameUidProcessesByRequest[$cacheKey];
        }

        $readFailures = array();
        $readFailureCount = 0;
        $identity = self::selfProcessIdentity($procRoot);
        if ($identity['status'] !== 'available') {
            $result = self::unavailable($identity['reason'], $identity['paths'], $identity['context']);
            return $result;
        }
        $effectiveUid = $identity['effective_uid'];
        $selfPid = $identity['self_pid'];
        $entriesResult = ABJ_404_Solution_HostFilesystemCall::scandir($procRoot);
        $entries = $entriesResult['result'];
        if (!is_array($entries) || ($entriesResult['warning'] !== null && $entriesResult['warning'] !== '')) {
            $result = self::unavailable('proc_root_scan_failed', array(
                'scandir(' . $procRoot . ')' => 'failed',
            ), self::ioContext('scandir', $entriesResult['warning']));
            return $result;
        }
        $siblings = 0;
        $readableProcesses = 0;
        $unreadableProcesses = 0;
        foreach ($entries as $entry) {
            // A pid directory can vanish or be hidden between scandir and stat;
            // only a captured warning is retained as an I/O failure.
            if (preg_match('/^\d+$/', (string)$entry) !== 1) {
                continue;
            }
            $pidPath = $procRoot . '/' . $entry;
            $process = self::readProcessStatus($pidPath);
            if ($process['status'] === 'skip') {
                continue;
            }
            if ($process['status'] === 'failure') {
                $unreadableProcesses++;
                if ($process['record_failure']) {
                    self::appendReadFailure($readFailures, $readFailureCount, $process['path'],
                        $process['reason'], $process['context']);
                }
                continue;
            }
            $readableProcesses++;
            $uid = $process['uid'];
            if ($uid === $effectiveUid && $entry !== $selfPid) {
                $siblings++;
            }
        }
        if ($readableProcesses === 0) {
            $result = self::unavailable('no_readable_process_statuses', array(
                $procRoot . '/[pid]/status' => 'none_readable',
            ));
            $result = self::withReadFailureEvidence($result, $readFailures, $readFailureCount);
            self::$sameUidProcessesByRequest[$cacheKey] = $result;
            return $result;
        }
        $result = array(
            'status' => 'available',
            'effective_uid' => $effectiveUid,
            'sibling_count' => $siblings,
            'readable_processes' => $readableProcesses,
            'unreadable_processes' => $unreadableProcesses,
        );
        $result = self::withReadFailureEvidence($result, $readFailures, $readFailureCount);
        self::$sameUidProcessesByRequest[$cacheKey] = $result;
        return $result;
    }

    /**
     * Admit the proc root and normalize the current process identity before
     * the sibling walk begins. This stage preserves the specific unavailable
     * reason and captured operation context used by the outer result schema.
     *
     * @return array{status: string, reason: string, paths: array<string, string>,
     *   context: array<string, mixed>, effective_uid: string|null,
     *   self_pid: string|null}
     */
    private static function selfProcessIdentity(string $procRoot): array {
        $directory = ABJ_404_Solution_HostFilesystemCall::isDir($procRoot);
        if (!$directory['result'] || ($directory['warning'] !== null && $directory['warning'] !== '')) {
            return array(
                'status' => 'unavailable', 'reason' => 'proc_root_not_readable',
                'paths' => array($procRoot => 'not_readable_directory'),
                'context' => self::ioContext('is_dir', $directory['warning']),
                'effective_uid' => null, 'self_pid' => null,
            );
        }
        $readable = ABJ_404_Solution_HostFilesystemCall::isReadable($procRoot);
        if (!$readable['result'] || ($readable['warning'] !== null && $readable['warning'] !== '')) {
            return array(
                'status' => 'unavailable', 'reason' => 'proc_root_not_readable',
                'paths' => array($procRoot => 'not_readable_directory'),
                'context' => self::ioContext('is_readable', $readable['warning']),
                'effective_uid' => null, 'self_pid' => null,
            );
        }
        $statusPath = $procRoot . '/self/status';
        $selfStatus = self::readProcFile($statusPath);
        if ($selfStatus['raw'] === null) {
            return array(
                'status' => 'unavailable', 'reason' => 'self_status_not_readable',
                'paths' => array($statusPath => 'not_readable'),
                'context' => $selfStatus['context'],
                'effective_uid' => null, 'self_pid' => null,
            );
        }
        $effectiveUid = self::effectiveUidFromStatus($selfStatus['raw']);
        if ($effectiveUid === null) {
            return array(
                'status' => 'unavailable', 'reason' => 'self_effective_uid_unavailable',
                'paths' => array($statusPath . ':Uid' => 'missing_or_invalid'),
                'context' => array(), 'effective_uid' => null, 'self_pid' => null,
            );
        }
        $selfPid = self::numericStatusField($selfStatus['raw'], 'Pid');
        if ($selfPid === null) {
            return array(
                'status' => 'unavailable', 'reason' => 'self_pid_unavailable',
                'paths' => array($statusPath . ':Pid' => 'missing_or_invalid'),
                'context' => array(), 'effective_uid' => null, 'self_pid' => null,
            );
        }
        return array(
            'status' => 'available', 'reason' => '', 'paths' => array(),
            'context' => array(), 'effective_uid' => $effectiveUid, 'self_pid' => $selfPid,
        );
    }

    /**
     * Read and normalize one numeric proc entry. The caller owns only the
     * process-walk counters; this stage owns the entry's directory/status I/O
     * and reports whether a failure is attributable to a captured warning.
     *
     * @return array{status: string, path: string, uid: string|null,
     *   reason: string, record_failure: bool, context: array<string, mixed>}
     */
    private static function readProcessStatus(string $pidPath): array {
        // A pid directory can vanish or be hidden between scandir and stat;
        // only a captured warning is retained as an I/O failure.
        $pidDirectory = ABJ_404_Solution_HostFilesystemCall::isDir($pidPath);
        $pidDirectoryWarning = $pidDirectory['warning'] !== null && $pidDirectory['warning'] !== '';
        if (!$pidDirectory['result'] && !$pidDirectoryWarning) {
            return array(
                'status' => 'skip', 'path' => $pidPath, 'uid' => null,
                'reason' => '', 'record_failure' => false, 'context' => array(),
            );
        }
        if ($pidDirectoryWarning) {
            return array(
                'status' => 'failure', 'path' => $pidPath, 'uid' => null,
                'reason' => 'directory_check_failed', 'record_failure' => true,
                'context' => self::ioContext('is_dir', $pidDirectory['warning']),
            );
        }
        $statusPath = $pidPath . '/status';
        $status = self::readProcFile($statusPath);
        if ($status['raw'] === null) {
            return array(
                'status' => 'failure', 'path' => $statusPath, 'uid' => null,
                'reason' => 'status_not_readable', 'record_failure' => true,
                'context' => $status['context'],
            );
        }
        $uid = self::effectiveUidFromStatus($status['raw']);
        if ($uid === null) {
            return array(
                'status' => 'failure', 'path' => $statusPath, 'uid' => null,
                'reason' => 'uid_invalid', 'record_failure' => false,
                'context' => array(),
            );
        }
        return array(
            'status' => 'available', 'path' => $statusPath, 'uid' => $uid,
            'reason' => '', 'record_failure' => false, 'context' => array(),
        );
    }

    /**
     * @param list<array{path: string, reason: string, context: array<string, mixed>}> $failures
     * @param array<string, mixed> $context
     */
    private static function appendReadFailure(
        array &$failures,
        int &$count,
        string $path,
        string $reason,
        array $context
    ): void {
        $count++;
        if (count($failures) < 4) {
            $failures[] = array('path' => $path, 'reason' => $reason, 'context' => $context);
        }
    }

    /**
     * @param array<string, mixed> $result
     * @param list<array{path: string, reason: string, context: array<string, mixed>}> $failures
     * @return array<string, mixed>
     */
    private static function withReadFailureEvidence(array $result, array $failures, int $count): array {
        if ($count === 0) {
            return $result;
        }
        $result['read_failure_count'] = $count;
        $result['read_failures'] = $failures;
        $result['read_failures_truncated'] = $count > count($failures);
        return $result;
    }

    /** @return array{raw:?string,context:array<string,mixed>} */
    private static function readProcFile(string $path): array {
        $readable = ABJ_404_Solution_HostFilesystemCall::isReadable($path);
        if (!$readable['result'] || ($readable['warning'] !== null && $readable['warning'] !== '')) {
            return array('raw' => null, 'context' => self::ioContext('is_readable', $readable['warning']));
        }
        $read = ABJ_404_Solution_HostFilesystemCall::fileGetContents($path);
        if ($read['warning'] !== null && $read['warning'] !== '') {
            return array('raw' => null, 'context' => self::ioContext('file_get_contents', $read['warning']));
        }
        if (!is_string($read['result'])) {
            return array('raw' => null, 'context' => self::ioContext('file_get_contents', null));
        }
        return array('raw' => $read['result'], 'context' => array());
    }

    /** @return array<string, mixed> */
    private static function ioContext(string $operation, ?string $warning): array {
        $warning = is_string($warning) && $warning !== '' ? $warning : null;
        $length = $warning === null ? 0 : strlen($warning);
        return array(
            'io_operation' => $operation,
            'io_warning' => $warning === null ? null : substr($warning, 0, 1024),
            'io_warning_truncated' => $length > 1024,
            'io_warning_length' => $length,
        );
    }

    private static function effectiveUidFromStatus(string $raw): ?string {
        foreach (preg_split('/\R/', $raw) ?: array() as $line) {
            if (strpos((string)$line, 'Uid:') !== 0) {
                continue;
            }
            $uids = preg_split('/\s+/', trim(substr((string)$line, 4)));
            return is_array($uids) && isset($uids[1]) && preg_match('/^\d+$/', $uids[1]) === 1
                ? $uids[1]
                : null;
        }
        return null;
    }

    private static function numericStatusField(string $raw, string $field): ?string {
        foreach (preg_split('/\R/', $raw) ?: array() as $line) {
            if (strpos((string)$line, $field . ':') !== 0) {
                continue;
            }
            $value = trim(substr((string)$line, strlen($field) + 1));
            return preg_match('/^\d+$/', $value) === 1 ? $value : null;
        }
        return null;
    }

    /** @param mixed $value */
    private static function readableScalar($value, int $maxLength): ?string {
        if (!is_scalar($value)) {
            return null;
        }
        $string = is_bool($value) ? ($value ? '1' : '0') : (string)$value;
        $ascii = preg_replace('/[^\x20-\x7E]/', '?', $string);
        if (!is_string($ascii)) {
            return null;
        }
        $truncated = substr($ascii, 0, $maxLength);
        return is_string($truncated) ? $truncated : null;
    }

    /**
     * @param array<string, string> $attemptedPaths
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function unavailable(
        string $reason,
        array $attemptedPaths,
        array $context = array()
    ): array {
        $reading = array(
            'status' => 'unavailable',
            'reason' => $reason,
            'attempted_paths' => $attemptedPaths,
        );
        if ($context !== array()) {
            $reading['context'] = $context;
        }
        return $reading;
    }
}
