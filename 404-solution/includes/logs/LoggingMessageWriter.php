<?php


if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: covered by tests/LoggingTest.php through public ABJ_404_Solution_Logging message entry points.

/**
 * Formats and writes severity-tagged debug log messages.
 *
 * Owns the DEBUG/INFO/WARN/ERROR line shapes and the stored-debug-message
 * buffer used when debug mode is disabled. It deliberately receives its time,
 * debug-mode, and file-write dependencies as callables so the public
 * ABJ_404_Solution_Logging facade remains the only production entry point.
 */
class ABJ_404_Solution_LoggingMessageWriter {

    /** @var callable */
    private $timestampProvider;
    /** @var callable */
    private $debugModeProvider;
    /** @var callable */
    private $lineWriter;
    /**
     * Breadcrumbs held while debug mode is off, flushed by the next ERROR line.
     * An entry is either the finished line or a callable that builds it (see
     * debugMessageLazy()).
     *
     * @var array<int, string|callable(): string>
     */
    private $storedDebugMessages;
    /** @var callable|null */
    private $operationTracer;

    /**
     * Re-entry guard for the logging write path.
     *
     * Prevents unbounded recursion to OOM when resolving or writing the log
     * file itself logs: Logging::warn -> LoggingMessageWriter::warn ->
     * writeLine -> lineWriter -> Logging::writeLineToDebugFile ->
     * DebugLogFileStore::getDebugFilePath -> FileSystemService logWarning ->
     * logging->warn -> ... Static so re-entry through a second writer
     * instance is also caught. A nested line is not lost: it goes to the
     * PHP error log fallback with category 'logger-reentry'.
     *
     * @var bool
     */
    private static bool $writingLine = false;

    /**
     * @param callable $timestampProvider Returns the formatted current timestamp.
     * @param callable $debugModeProvider Returns true when debug mode is enabled.
     * @param callable $lineWriter Receives one fully-formatted log line.
     * @param array<int, string|callable(): string> $storedDebugMessages Shared debug buffer from the facade.
     * @param callable(string,array<string,mixed>,callable):mixed|null $operationTracer
     */
    public function __construct(
        callable $timestampProvider,
        callable $debugModeProvider,
        callable $lineWriter,
        array &$storedDebugMessages,
        $operationTracer = null
    ) {
        $this->timestampProvider = $timestampProvider;
        $this->debugModeProvider = $debugModeProvider;
        $this->lineWriter = $lineWriter;
        $this->storedDebugMessages =& $storedDebugMessages;
        $this->operationTracer = is_callable($operationTracer) ? $operationTracer : null;
    }

    /**
     * Write immediately when debug mode is enabled; otherwise buffer for the
     * next error log entry.
     *
     * @param string $message
     * @param \Throwable|null $e
     * @return void
     */
    public function debugMessage(string $message, $e = null): void {
        $this->traceOperation('message', function () use ($message, $e): void {
            $this->writeDebugMessage($message, $e);
        }, array('level' => 'debug'));
    }

    /** @param \Throwable|null $e */
    private function writeDebugMessage(string $message, $e): void {
        $stacktrace = "";
        if ($e != null) {
            $stacktrace = ", Stacktrace: " . $e->getTraceAsString();
        }

        $line = $this->timestampPrefix('DEBUG') . $message . $stacktrace;
        $debugEnabled = (bool)$this->traceOperation(
            'debug_state_resolution',
            fn() => call_user_func($this->debugModeProvider)
        );
        if ($debugEnabled) {
            $this->writeLine($line);
            return;
        }

        $this->traceOperation('buffer_append', function () use ($line): void {
            $this->storedDebugMessages[] = $line;
        });
    }

    /**
     * One WARN line for a catch block that degrades and carries on.
     *
     * debugMessage() reaches the log file only while debug_mode is on; with it
     * off (the default) the line waits in a per-request buffer that a later
     * errorMessage() in the SAME request may flush, and is otherwise lost. A
     * catch that swallows a failure and continues has no later error, so its
     * record has to be written unconditionally. It is a WARN, never an ERROR: a
     * degrade-and-continue catch is an infrastructure or environment condition
     * the plugin handled, and only ERROR lines reach the developer error-report
     * email.
     *
     * The line carries the caller's context sentence, then the exception class,
     * code, message and file:line, so no call site re-assembles those (and none
     * can leave one out). Logging::warnCaught() writes the result with warn().
     *
     * @param string $context What was attempted and what the code did instead.
     * @param \Throwable $e The caught failure.
     * @return string
     */
    public static function describeCaught(string $context, \Throwable $e): string {
        return $context . ' ' . get_class($e) . ' (code ' . (string)$e->getCode() . '): '
            . $e->getMessage() . ' [at ' . basename($e->getFile()) . ':' . (string)$e->getLine() . ']';
    }

    /**
     * A debug breadcrumb whose text is expensive to build.
     *
     * Debug mode on: built and written now, exactly like debugMessage(). Debug
     * mode off: the BUILDER is held instead of the text and only run if a later
     * errorMessage() in the same request flushes the buffer, so a request that
     * never fails pays nothing for the line. Without this, a breadcrumb that was
     * too expensive to always build had to be skipped outright when debug was
     * off, and the ERROR that followed carried no trace of it.
     *
     * A builder that throws is recorded as such rather than breaking the flush.
     *
     * @param callable(): string $build Returns the message text.
     * @return void
     */
    public function debugMessageLazy(callable $build): void {
        $this->traceOperation('message', function () use ($build): void {
            $debugEnabled = (bool)$this->traceOperation(
                'debug_state_resolution',
                fn() => call_user_func($this->debugModeProvider)
            );
            if ($debugEnabled) {
                $this->writeLine($this->timestampPrefix('DEBUG') . $this->resolveBufferedLine($build));
                return;
            }
            $prefix = $this->timestampPrefix('DEBUG');
            $this->traceOperation('buffer_append', function () use ($build, $prefix): void {
                $this->storedDebugMessages[] = static function () use ($build, $prefix): string {
                    return $prefix . (string)call_user_func($build);
                };
            });
        }, array('level' => 'debug'));
    }

    /**
     * Turn one buffered entry into its line, tolerating a builder that throws.
     *
     * @param string|callable(): string $entry
     * @return string
     */
    private function resolveBufferedLine($entry): string {
        if (is_string($entry)) {
            return $entry;
        }
        try {
            return (string)call_user_func($entry);
        } catch (\Throwable $t) {
            return $this->timestampPrefix('DEBUG') . 'breadcrumb builder failed: '
                . get_class($t) . ': ' . $t->getMessage();
        }
    }

    /**
     * @param string $message
     * @return void
     */
    public function infoMessage(string $message): void {
        $this->traceOperation('message', function () use ($message): void {
            $this->writeLine($this->timestampPrefix('INFO') . $message);
        }, array('level' => 'info'));
    }

    /**
     * @param string $message
     * @return void
     */
    public function warn(string $message): void {
        $this->traceOperation('message', function () use ($message): void {
            $this->writeLine($this->timestampPrefix('WARN') . $message);
        }, array('level' => 'warn'));
    }

    /**
     * Flush any debug messages buffered while debug mode was disabled, then
     * write the ERROR line with request/plugin context.
     *
     * @param string $message
     * @param \Exception|null $e
     * @return void
     */
    public function errorMessage(string $message, $e = null): void {
        $this->traceOperation('message', function () use ($message, $e): void {
            $this->writeErrorMessage($message, $e);
        }, array('level' => 'error'));
    }

    /** @param \Exception|null $e */
    private function writeErrorMessage(string $message, $e): void {
        if ($e == null) {
            $e = new Exception;
        }
        $stacktrace = $e->getTraceAsString();

        $savedDebugMessages = implode("\n", array_map(
            fn($entry): string => $this->resolveBufferedLine($entry),
            $this->storedDebugMessages
        ));
        $this->storedDebugMessages = array();

        $referrer = '';
        if (array_key_exists('HTTP_REFERER', $_SERVER) && !empty($_SERVER['HTTP_REFERER'])) {
            $referrer = $_SERVER['HTTP_REFERER'];
        }
        $requestedURL = '';
        if (array_key_exists('REQUEST_URI', $_SERVER) && !empty($_SERVER['REQUEST_URI'])) {
            $requestedURL = $_SERVER['REQUEST_URI'];
        }

        $this->writeLine($this->timestampPrefix('ERROR') . $message . ", PHP version: " . PHP_VERSION .
            ", WP ver: " . get_bloginfo('version') . ", Plugin ver: " . ABJ404_VERSION .
            ", Referrer: " . $referrer . ", Requested URL: " . $requestedURL .
            ", \nStored debug messages: \n" . $savedDebugMessages . ", \nTrace: " . $stacktrace);
    }

    /**
     * @param string $level
     * @return string
     */
    private function timestampPrefix(string $level): string {
        $timestamp = $this->traceOperation(
            'timestamp_resolution',
            fn() => call_user_func($this->timestampProvider)
        );
        return (string)$timestamp . ' (' . $level . '): ';
    }

    /**
     * @param string $line
     * @return void
     */
    private function writeLine(string $line): void {
        if (self::$writingLine) {
            if (function_exists('abj404_logPhpFallback')) {
                abj404_logPhpFallback('logger-reentry', $line);
            }
            return;
        }
        self::$writingLine = true;
        try {
            $this->traceOperation('line_writer_dispatch', function () use ($line): void {
                call_user_func($this->lineWriter, $line);
            });
        } finally {
            self::$writingLine = false;
        }
    }

    /**
     * @template T
     * @param callable():T $work
     * @param array<string,mixed> $fields
     * @return T
     */
    private function traceOperation(string $operation, callable $work, array $fields = array()) {
        return is_callable($this->operationTracer)
            ? call_user_func($this->operationTracer, $operation, $fields, $work)
            : $work();
    }
}
