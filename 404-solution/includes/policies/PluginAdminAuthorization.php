<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared service-resolution boundary for plugin-admin authorization.
 *
 * The concrete policy remains responsible for permission rules. This adapter
 * resolves the optional service, validates its public protocol, invokes it
 * for the current request, and fails closed when that boundary is absent or
 * throws.
 */
final class ABJ_404_Solution_PluginAdminAuthorization {

    /**
     * Lazily resolve and invoke the registered policy's public protocol.
     * Permission decisions remain inside the registered concrete policy.
     *
     * @return bool
     */
    public static function currentUserCanAccessPluginAdmin(): bool {
        try {
            $policy = function_exists('abj_service_optional') ? abj_service_optional('admin_access_policy') : null;
            if (is_object($policy) && method_exists($policy, 'isPluginAdmin')) {
                return (bool)$policy->isPluginAdmin();
            }

            self::warnStaticPolicyResolutionFailure(
                'plugin admin access policy resolution failed because admin_access_policy is unavailable.'
            );
            return false;
        } catch (\Throwable $e) {
            self::warnStaticPolicyResolutionFailure('plugin admin access policy resolution failed', $e);
            return false;
        }
    }

    private static function warnStaticPolicyResolutionFailure(string $message, ?\Throwable $throwable = null): void {
        if (function_exists('abj404_logRuntimeWarning')) {
            abj404_logRuntimeWarning($message, $throwable);
            return;
        }

        $line = $message;
        if ($throwable !== null) {
            $line .= ' (code ' . (string)$throwable->getCode() . ') at ' .
                $throwable->getFile() . ':' . (string)$throwable->getLine() .
                ': ' . $throwable->getMessage();
        }
        abj404_logPhpFallback('service-resolution-fallback', $line);
    }
}
