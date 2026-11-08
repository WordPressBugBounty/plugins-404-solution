<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Completes independent work after a redirect mutation has committed.
 *
 * Admission and the database mutation stay in their owning service. This
 * domain boundary only guarantees that named post-commit steps are validated,
 * attempted in order, and reported without hiding an actual failure.
 */
final class ABJ_404_Solution_RedirectMutationCompletion {
    /**
     * @param array<string, callable(): void> $steps
     * @return void
     * @throws InvalidArgumentException when a step name or callback is invalid.
     * @throws Throwable when a post-commit step fails.
     */
    public static function complete(array $steps): void {
        self::validate($steps);
        $failures = array();
        foreach ($steps as $name => $step) {
            $failure = self::attempt($name, $step);
            if ($failure !== null) {
                $failures[] = $failure;
            }
        }
        if (count($failures) === 1) {
            throw $failures[0]['throwable'];
        }
        if (count($failures) > 1) {
            throw new ABJ_404_Solution_ViewQueryFailureException(
                self::aggregateMessage($failures[0]),
                array(
                    'code' => 'REDIRECT_MUTATION_COMPLETION_FAILED',
                    'hint' => 'One or more post-commit cleanup steps failed; inspect post_commit_failures.',
                    'post_commit_failures' => array_map(static function (array $failure): array {
                        return array(
                            'step' => $failure['step'],
                            'code' => $failure['code'],
                            'class' => $failure['class'],
                            'message' => $failure['message'],
                            'diagnostics' => self::throwableDiagnostics($failure['throwable']),
                        );
                    }, $failures),
                ),
                $failures[0]['throwable']
            );
        }
    }

    /**
     * Run one post-commit step so a failure cannot stop the steps after it.
     * The failure is returned with its code, class, message and the original
     * throwable, never dropped: complete() rethrows it (or chains it into the
     * aggregate exception) once every step has had its turn.
     *
     * @param callable(): void $step
     * @return array{step: string, code: int, class: string, message: string, throwable: Throwable}|null
     *         Null when the step succeeded.
     */
    private static function attempt(string $name, callable $step): ?array {
        try {
            $step();
            return null;
        } catch (Throwable $error) {
            return array(
                'step' => $name,
                'code' => $error->getCode(),
                'class' => get_class($error),
                'message' => $error->getMessage(),
                'throwable' => $error,
            );
        }
    }

    /**
     * Keep the first failure visible at boundaries that only render getMessage().
     * The step and code identify the cleanup context without discarding the
     * original message that explains the underlying failure.
     *
     * @param array{step: string, code: int, class: string, message: string, throwable: Throwable} $failure
     */
    private static function aggregateMessage(array $failure): string {
        return sprintf(
            'Independent redirect mutation completion failed: step "%s" reported %s (code %d): %s. Inspect post_commit_failures for every named cleanup step.',
            $failure['step'],
            $failure['class'],
            $failure['code'],
            $failure['message']
        );
    }

    /**
     * Capture structured diagnostics for each named error, including bounded
     * previous chains. Depth four keeps malformed nested errors from growing
     * the response without silently losing whether further causes existed.
     *
     * @return array<string, mixed>
     */
    private static function throwableDiagnostics(Throwable $error, int $depth = 0): array {
        $diagnostics = array(
            'class' => get_class($error),
            'code' => $error->getCode(),
            'message' => $error->getMessage(),
        );
        if ($error instanceof ABJ_404_Solution_ViewQueryFailureException) {
            $diagnostics['diagnostics'] = $error->getDiagnostics();
        }
        $previous = $error->getPrevious();
        if ($previous instanceof Throwable) {
            $diagnostics['previous'] = $depth >= 4
                ? array('truncated' => true)
                : self::throwableDiagnostics($previous, $depth + 1);
        }
        return $diagnostics;
    }

    /** @param array<string, callable(): void> $steps */
    private static function validate(array $steps): void {
        foreach ($steps as $name => $step) {
            if (!is_string($name) || $name === '') {
                throw new InvalidArgumentException('Redirect mutation completion step names must be non-empty strings.');
            }
            if (!is_callable($step)) {
                throw new InvalidArgumentException('Redirect mutation completion step is not callable: ' . $name);
            }
        }
    }
}
