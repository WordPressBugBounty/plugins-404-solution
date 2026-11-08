<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Samples host-level pressure visible to an unprivileged PHP worker.
 *
 * Each probe returns either a named available result or a named unavailable
 * reason. Empty fields are forbidden because an absent counter must remain
 * distinguishable from a healthy zero when a request dies under transient
 * LiteSpeed or CloudLinux pressure.
 */
final class ABJ_404_Solution_HostPressureSampler {

    /**
     * Capture host pressure while paying the process-wide procfs walk once
     * for each request. Cheap counters remain fresh on every checkpoint.
     *
     * @param string $requestScope Stable identity shared by captures from one request.
     * @return array<string, mixed>
     */
    public static function capture(string $requestScope = ''): array {
        $procRoot = '/proc';
        if (function_exists('apply_filters')) {
            try {
                $filtered = apply_filters('abj404_host_pressure_proc_root', $procRoot);
                $procRoot = is_string($filtered) && $filtered !== '' ? $filtered : $procRoot;
            } catch (Throwable $e) {
                self::reportFailure('proc-root filter failed: ' . get_class($e) . ' code=' .
                    $e->getCode() . ' message=' . $e->getMessage());
            }
        }
        $procRoot = rtrim($procRoot, '/\\');
        $environment = self::processEnvironment();
        $procfs = ABJ_404_Solution_HostProcfsPressureProbe::capture($procRoot, $requestScope);

        return array(
            'sys_loadavg' => self::systemLoadAverage(),
            'proc_loadavg' => $procfs['proc_loadavg'],
            'proc_self_status' => $procfs['proc_self_status'],
            'runtime_identity' => self::runtimeIdentity(),
            'proc_self_limits' => $procfs['proc_self_limits'],
            'proc_self_cgroup' => $procfs['proc_self_cgroup'],
            'same_uid_processes' => $procfs['same_uid_processes'],
            'cloudlinux_lve_server_vars' => ABJ_404_Solution_HostServerCounterProbe::capture(
                '/^(?:LVE_|CLOUDLINUX_)/i',
                $environment
            ),
            'litespeed_server_vars' => ABJ_404_Solution_HostServerCounterProbe::capture(
                '/^(?:LSAPI_|LITESPEED_|LSWS_)/i',
                $environment
            ),
            'filesystem_quota_probes' => ABJ_404_Solution_HostFilesystemPressureProbe::capture(),
        );
    }

    /**
     * PHPUnit workers simulate multiple requests in one PHP process.
     */
    public static function resetForTests(): void {
        ABJ_404_Solution_HostProcfsPressureProbe::resetCache();
    }

    /**
     * Keep the first full host-pressure snapshot for each request, then omit
     * probes that are byte-identical to that request-local base. Changed and
     * unencodable probes stay inline, so any selected later record remains
     * self-describing without a previous-record reference chain.
     *
     * @param array<string, mixed> $record
     * @param array<string, array<string, string>> $snapshotByRequest
     * @return array<string, mixed>
     */
    public static function compactRepeatedHostPressureSnapshots(
        array $record,
        array &$snapshotByRequest
    ): array {
        $requestId = is_string($record['request_id'] ?? null) ? $record['request_id'] : '';
        $hostPressure = $record['host_pressure'] ?? null;
        if ($requestId === '' || !is_array($hostPressure)) {
            return $record;
        }
        $namedHostPressure = array();
        foreach ($hostPressure as $probeName => $probe) {
            if (!is_string($probeName)) {
                return $record;
            }
            $namedHostPressure[$probeName] = $probe;
        }
        $hostPressure = $namedHostPressure;

        if (!array_key_exists($requestId, $snapshotByRequest)) {
            $snapshotByRequest[$requestId] = self::hostPressureFingerprints($hostPressure);
            return $record;
        }

        $unchanged = array();
        foreach ($hostPressure as $probeName => $probe) {
            $encoded = json_encode($probe, JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded)) {
                continue;
            }
            $fingerprint = hash('sha256', $encoded);
            if (($snapshotByRequest[$requestId][$probeName] ?? '') !== $fingerprint) {
                continue;
            }
            unset($hostPressure[$probeName]);
            $unchanged[] = $probeName;
        }
        if ($unchanged === array()) {
            return $record;
        }

        if ($hostPressure === array()) {
            unset($record['host_pressure']);
        } else {
            $record['host_pressure'] = $hostPressure;
        }
        $record['host_pressure_unchanged'] = $unchanged;
        return $record;
    }

    /**
     * @param array<string, mixed> $hostPressure
     * @return array<string, string>
     */
    private static function hostPressureFingerprints(array $hostPressure): array {
        $fingerprints = array();
        foreach ($hostPressure as $probeName => $probe) {
            $encoded = json_encode($probe, JSON_UNESCAPED_SLASHES);
            if (is_string($encoded)) {
                $fingerprints[$probeName] = hash('sha256', $encoded);
            }
        }
        return $fingerprints;
    }

    /** @return array<string, mixed> */
    private static function systemLoadAverage(): array {
        if (!ABJ_404_Solution_PhpRuntimeCapabilityAdapter::isFunctionAvailable('sys_getloadavg')) {
            return self::unavailable('function_unavailable', array(
                'function_exists(sys_getloadavg)' => 'false',
            ));
        }
        $load = ABJ_404_Solution_PhpRuntimeCapabilityAdapter::systemLoadAverage();
        if (!is_array($load) || count($load) < 3) {
            return self::unavailable('invalid_result', array(
                'sys_getloadavg()' => 'invalid_result',
            ));
        }
        return array(
            'status' => 'available',
            'one_min' => (float)$load[0],
            'five_min' => (float)$load[1],
            'fifteen_min' => (float)$load[2],
        );
    }

    /** @return array<string, mixed> */
    private static function runtimeIdentity(): array {
        $sapi = php_sapi_name();
        $serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? null;
        $serverSoftwareProbe = self::unavailable('not_present', array(
            '$_SERVER[SERVER_SOFTWARE]' => 'not_present',
        ), array('php_sapi_name' => php_sapi_name()));
        if (array_key_exists('SERVER_SOFTWARE', $_SERVER)) {
            $readable = self::readableScalar($serverSoftware, 128);
            $serverSoftwareProbe = $readable === null
                ? self::unavailable('not_readable', array(
                    '$_SERVER[SERVER_SOFTWARE]' => 'not_readable',
                ), array('php_sapi_name' => php_sapi_name()))
                : array('status' => 'available', 'value' => self::serverSoftwareClass($readable));
        }
        return array(
            'php_sapi_name' => array('status' => 'available', 'value' => $sapi),
            'server_software' => $serverSoftwareProbe,
        );
    }

    private static function serverSoftwareClass(string $raw): string {
        $hostMarker = stripos($raw, ' Server at ');
        $withoutHost = $hostMarker === false ? $raw : substr($raw, 0, $hostMarker);
        return substr(trim($withoutHost), 0, 100);
    }

    /** @return array<string, mixed> */
    private static function processEnvironment(): array {
        $environment = getenv();
        $probe = array('status' => 'available', 'values' => $environment);
        if (!function_exists('apply_filters')) {
            return $probe;
        }
        try {
            $filtered = apply_filters(
                'abj404_host_pressure_environment',
                $probe['values']
            );
        } catch (Throwable $e) {
            self::reportFailure('environment filter failed: ' . get_class($e) . ' code=' .
                $e->getCode() . ' message=' . $e->getMessage());
            return self::unavailable('environment_filter_failed', array(
                'getenv()' => 'available',
                'apply_filters(abj404_host_pressure_environment)' => 'exception:' . get_class($e),
            ));
        }
        return is_array($filtered)
            ? array('status' => 'available', 'values' => $filtered)
            : self::unavailable('environment_filter_invalid', array(
                'getenv()' => 'available',
                'apply_filters(abj404_host_pressure_environment)' => 'not_array',
            ));
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
     * @param array<string, scalar|null> $context
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

    private static function reportFailure(string $message): void {
        if (function_exists('abj404_logPhpFallback')) {
            abj404_logPhpFallback('host-pressure', $message);
        }
    }
}
