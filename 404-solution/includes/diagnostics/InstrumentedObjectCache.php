<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: exercised through the real AJAX table render entry point in tests/AjaxRowProgressAttributionTest.php
/**
 * Transparent WordPress object-cache adapter used during a diagnostic scope.
 *
 * Unknown Object Cache Pro methods and public properties pass through without
 * tracing. Standard key operations are bracketed with the tracer's hard
 * record budget. The original cache remains available for capability and
 * metrics inspection without the adapter making magic methods look real.
 *
 */
final class ABJ_404_Solution_InstrumentedObjectCache {
    /** @var object */
    private $target;
    /** @var ABJ_404_Solution_CacheOperationTraceSink */
    private $tracer;
    /** @var bool Cache work used by the observer itself must remain uninstrumented. */
    private $tracing = false;

    public function __construct(
        object $target,
        ABJ_404_Solution_CacheOperationTraceSink $tracer
    ) {
        $this->target = $target;
        $this->tracer = $tracer;
    }

    /** The decorated cache, for non-operation capability inspection. */
    public function originalCache(): object {
        return $this->target;
    }

    /**
     * @param int|string $key
     * @param bool|null $found
     * @return mixed
     */
    public function get($key, string $group = 'default', bool $force = false, &$found = null) {
        return $this->traceCacheOperation('get', $key, $group, function () use ($key, $group, $force, &$found) {
            return $this->callTarget('get', array($key, $group, $force, &$found));
        });
    }

    /**
     * @param array<int|string, int|string> $keys
     * @return mixed
     */
    public function get_multiple(array $keys, string $group = 'default', bool $force = false) {
        return $this->traceCacheOperation('get_multiple', $keys, $group, function () use ($keys, $group, $force) {
            return $this->callTarget('get_multiple', array($keys, $group, $force));
        });
    }

    /**
     * @param array<int|string, mixed> $data
     * @return mixed
     */
    public function add_multiple(array $data, string $group = 'default', int $expire = 0) {
        return $this->traceCacheOperation('add_multiple', $data, $group, function () use ($data, $group, $expire) {
            return $this->callTarget('add_multiple', array($data, $group, $expire));
        });
    }

    /**
     * @param array<int|string, mixed> $data
     * @return mixed
     */
    public function set_multiple(array $data, string $group = 'default', int $expire = 0) {
        return $this->traceCacheOperation('set_multiple', $data, $group, function () use ($data, $group, $expire) {
            return $this->callTarget('set_multiple', array($data, $group, $expire));
        });
    }

    /**
     * @param array<int|string, int|string> $keys
     * @return mixed
     */
    public function delete_multiple(array $keys, string $group = 'default') {
        return $this->traceCacheOperation('delete_multiple', $keys, $group, function () use ($keys, $group) {
            return $this->callTarget('delete_multiple', array($keys, $group));
        });
    }

    /**
     * @param int|string $key
     * @param mixed $value
     * @return mixed
     */
    public function set($key, $value, string $group = 'default', int $expire = 0) {
        return $this->traceCacheOperation('set', $key, $group, function () use ($key, $value, $group, $expire) {
            return $this->callTarget('set', array($key, $value, $group, $expire));
        });
    }

    /**
     * @param int|string $key
     * @param mixed $value
     * @return mixed
     */
    public function add($key, $value, string $group = 'default', int $expire = 0) {
        return $this->traceCacheOperation('add', $key, $group, function () use ($key, $value, $group, $expire) {
            return $this->callTarget('add', array($key, $value, $group, $expire));
        });
    }

    /**
     * @param int|string $key
     * @param mixed $value
     * @return mixed
     */
    public function replace($key, $value, string $group = 'default', int $expire = 0) {
        return $this->traceCacheOperation('replace', $key, $group, function () use ($key, $value, $group, $expire) {
            return $this->callTarget('replace', array($key, $value, $group, $expire));
        });
    }

    /**
     * @param int|string $key
     * @return mixed
     */
    public function delete($key, string $group = 'default', bool $deprecated = false) {
        return $this->traceCacheOperation('delete', $key, $group, function () use ($key, $group, $deprecated) {
            return $this->callTarget('delete', array($key, $group, $deprecated));
        });
    }

    /**
     * @param int|string $key
     * @return mixed
     */
    public function incr($key, int $offset = 1, string $group = 'default') {
        return $this->traceCacheOperation('incr', $key, $group, function () use ($key, $offset, $group) {
            return $this->callTarget('incr', array($key, $offset, $group));
        });
    }

    /**
     * @param int|string $key
     * @return mixed
     */
    public function decr($key, int $offset = 1, string $group = 'default') {
        return $this->traceCacheOperation('decr', $key, $group, function () use ($key, $offset, $group) {
            return $this->callTarget('decr', array($key, $offset, $group));
        });
    }

    /**
     * @param array<int, mixed> $arguments
     * @return mixed
     */
    public function __call(string $name, array $arguments) {
        return $this->callTarget($name, $arguments);
    }

    /** @return mixed */
    public function __get(string $name) {
        return $this->target->$name;
    }

    /** @param mixed $value */
    public function __set(string $name, $value): void {
        $this->target->$name = $value;
    }

    public function __isset(string $name): bool {
        return isset($this->target->$name);
    }

    /**
     * Forward observer-owned cache work without tracing it again. A failed
     * checkpoint can log a warning whose timestamp reads WordPress options;
     * tracing that cache read would try the same failed checkpoint before the
     * logger reaches its own line-write recursion guard.
     *
     * @template T
     * @param mixed $key
     * @param mixed $group
     * @param callable():T $work
     * @return T
     */
    private function traceCacheOperation(string $operation, $key, $group, callable $work) {
        if ($this->tracing || ABJ_404_Solution_AjaxCheckpointLogger::isRecording()) {
            return $work();
        }
        $this->tracing = true;
        try {
            return $this->tracer->traceCache($operation, $key, $group, $work);
        } finally {
            $this->tracing = false;
        }
    }

    /**
     * @param array<int, mixed> $arguments
     * @return mixed
     */
    private function callTarget(string $method, array $arguments) {
        $callback = array($this->target, $method);
        if (!is_callable($callback)) {
            abj404_logPhpFallback(
                'diagnostic-object-cache',
                'cache method unavailable: ' . get_class($this->target) . '::' . $method
            );
            return false;
        }
        return call_user_func_array($callback, $arguments);
    }
}
