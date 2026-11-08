<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Raised when a manual or regex redirect would close a redirect cycle, or when
 * admission cannot establish that it would not.
 */
class ABJ_404_Solution_RedirectCycleException extends RuntimeException {

    /** @var array<int, string> */
    private $path;

    /**
     * @param array<int, string> $path
     * @param string $message Overrides the loop message; see unverifiable().
     */
    public function __construct(array $path, string $message = '') {
        $this->path = array_values($path);
        parent::__construct($message !== '' ? $message :
            sprintf(
                __('Redirect loop: %s. Choose a destination outside this loop.', '404-solution'),
                implode(' -> ', $this->path)
            )
        );
    }

    /**
     * Admission could not establish that the redirect terminates, because a
     * collaborator the loop check needs could not be resolved. The write is
     * refused the same way a loop is: an unchecked redirect may close one.
     *
     * @param array<int, string> $path The walk up to the point it stopped.
     * @param string $reason The underlying resolution failure.
     * @return self
     */
    public static function unverifiable(array $path, string $reason): self {
        return new self($path, sprintf(
            __('Redirect not saved: the redirect loop check could not run (%s).', '404-solution'),
            $reason
        ) . ' [ABJ404_LOOP_CHECK_UNAVAILABLE] ' . __(
            'Reload this page and try again. If the error continues, send this message to support.',
            '404-solution'
        ));
    }

    /** @return array<int, string> */
    public function getPath(): array {
        return $this->path;
    }
}

