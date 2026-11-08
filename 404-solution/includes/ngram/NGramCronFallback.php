<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admits and continues one overdue n-gram cron event from an authorized admin
 * page shutdown.
 *
 * This class owns only fallback admission, its versioned network-aware state,
 * and exact event consumption. SQL, progress mutation, rebuild batching,
 * authorization, and response rendering stay with their existing owners.
 *
 * @phpstan-type AdmissionState array{version:int,kind:string,created_at:int,updated_at:int,admitted_at:int,last_attempt_at:int,event_timestamp:int,event_args:array<int,mixed>}
 * @phpstan-type FallbackIntent array{kind:'event',event:array{timestamp:int,args:array<int,mixed>}}|array{kind:'retire'}
 */
class ABJ_404_Solution_NGramCronFallback {

    /** Durable network-aware admission record. */
    const STATE_OPTION = 'abj404_ngram_cron_fallback_state';

    /** Version of the persisted admission shape. */
    const STATE_VERSION = 1;

    /** Admission discriminator for the existing n-gram rebuild chain. */
    const STATE_KIND = 'ngram_rebuild';

    /** @var ABJ_404_Solution_NGramNetworkOptionStore */
    private ABJ_404_Solution_NGramNetworkOptionStore $optionStore;

    /** @var ABJ_404_Solution_NGramCacheRebuildScheduler */
    private ABJ_404_Solution_NGramCacheRebuildScheduler $scheduler;

    /** @var ABJ_404_Solution_Clock */
    private ABJ_404_Solution_Clock $clock;

    /** @var ABJ_404_Solution_Logging|null */
    private ?ABJ_404_Solution_Logging $logger;

    /** @var \Closure Existing bounded batch runner callback. */
    private \Closure $runBatch;

    /** @var \Closure Callback that acquires the existing rebuild lock. */
    private \Closure $runAfterResponseCallback;

    /** @var bool */
    private $armed = false;

    /** @var FallbackIntent|null */
    private $armedIntent = null;

    /** @var bool Set when a non-empty persisted value fails validation. */
    private $malformedState = false;

    /**
     * @param ABJ_404_Solution_NGramNetworkOptionStore $optionStore
     * @param ABJ_404_Solution_NGramCacheRebuildScheduler $scheduler
     * @param ABJ_404_Solution_Clock $clock
     * @param array{
     *     logger: ABJ_404_Solution_Logging|null,
     *     runBatch: callable():void,
     *     runAfterResponseCallback: callable():void
     * } $options
     */
    public function __construct(
        ABJ_404_Solution_NGramNetworkOptionStore $optionStore,
        ABJ_404_Solution_NGramCacheRebuildScheduler $scheduler,
        ABJ_404_Solution_Clock $clock,
        array $options
    ) {
        $this->optionStore = $optionStore;
        $this->scheduler = $scheduler;
        $this->clock = $clock;
        $this->logger = $options['logger'];
        $this->runBatch = \Closure::fromCallable($options['runBatch']);
        $this->runAfterResponseCallback = \Closure::fromCallable($options['runAfterResponseCallback']);
    }

    /**
     * Admit one event and register at most one WordPress shutdown callback.
     *
     * Initial admission requires the exact event to be due on a site that has
     * proved WP-Cron is stalled: this event, or any other stored event, more
     * than 900 seconds overdue now or earlier (CronDispatchStallEvidence). A
     * valid admission allows later due continuation events to run without
     * new evidence.
     *
     * @return bool Whether this request registered the fallback callback.
     */
    public function arm(): bool {
        if ($this->armed || !function_exists('add_action')) {
            return false;
        }

        $this->scheduler->refreshCronStoreReads();
        $event = $this->scheduler->queuedRebuildEvent();
        if ($event === false) {
            $state = $this->readState();
            if ($this->malformedState || $state === null) {
                return false;
            }
            return $this->registerShutdownCallback(array('kind' => 'retire'));
        }
        $state = $this->readState();
        if ($this->malformedState) {
            return false;
        }

        $now = $this->clock->now();
        if ($state === null) {
            return $this->isAdmissible($event, $now)
                ? $this->registerShutdownCallback(array('kind' => 'event', 'event' => $event))
                : false;
        }

        if ($this->eventMatchesState($state, $event)) {
            return $event['timestamp'] <= $now
                ? $this->registerShutdownCallback(array('kind' => 'event', 'event' => $event))
                : false;
        }

        // A stale admission must be retired under the shared rebuild lock.
        // Register only so runLocked() can perform that reconciliation.
        return $this->registerShutdownCallback(array('kind' => 'event', 'event' => $event));
    }

    /**
     * Detach the delivered response, then perform the already-admitted work.
     * Failures become concrete warnings and cannot corrupt the response tail.
     *
     * @return void
     */
    public function runAfterResponse(): void {
        try {
            ABJ_404_Solution_ResponseDetach::detach();
            call_user_func($this->runAfterResponseCallback);
        } catch (Throwable $e) {
            $this->warn(
                'Post-response n-gram cache rebuild failed: '
                . get_class($e) . ': ' . $e->getMessage()
            );
        }
    }

    /**
     * Recheck admission under the caller's existing ngram_rebuild lock,
     * consume only the exact due event, and invoke the canonical batch runner.
     *
     * @return void
     */
    public function runLocked(): void {
        $state = $this->readState();
        if ($this->malformedState) {
            return;
        }
        if ($this->retriesExhausted()) {
            if ($state !== null) {
                $this->retireState();
            }
            return;
        }

        $this->scheduler->refreshCronStoreReads();
        $event = $this->scheduler->queuedRebuildEvent();
        $now = $this->clock->now();
        $eventWasRemoved = $event === false
            && is_array($this->armedIntent)
            && $this->armedIntent['kind'] === 'event'
            && $state !== null;
        if (!$this->intentMatchesEvent($event) && !$eventWasRemoved) {
            return;
        }
        if ($state === null) {
            if ($event === false || !$this->isAdmissible($event, $now)) {
                return;
            }
            $state = $this->newAdmissionState($event, $now);
            // Causal proof is durable before a callback can run any batch.
            if (!$this->writeState($state)) {
                return;
            }
        } elseif ($event === false) {
            // The callback was scheduled for a persisted admission, but the
            // real cron chain has already disappeared. Retire only the stale
            // fallback state and preserve rebuild progress.
            $this->retireState();
            return;
        } elseif (!$this->eventMatchesState($state, $event)) {
            // Cron may have advanced the chain between requests. Retire the
            // old identity and admit a replacement only with stall evidence
            // for the currently selected event.
            $this->retireState();
            if (!$this->isAdmissible($event, $now)) {
                return;
            }
            $state = $this->newAdmissionState($event, $now);
            if (!$this->writeState($state)) {
                return;
            }
        } elseif ($event['timestamp'] > $now) {
            // A valid admission never authorizes consuming a future event.
            return;
        }

        $consumed = $this->scheduler->consumeExactEvent(array(
            'timestamp' => $event['timestamp'],
            'hook' => ABJ_404_Solution_NGramCacheRebuildScheduler::REBUILD_CRON_HOOK,
            'args' => $event['args'],
        ));
        if (!$consumed) {
            return;
        }

        call_user_func($this->runBatch);

        $this->scheduler->refreshCronStoreReads();
        $nextEvent = $this->scheduler->queuedRebuildEvent();
        if ($nextEvent === false) {
            // The canonical runner did not leave a continuation. A later
            // normal scheduler call must establish fresh causal evidence.
            $this->retireState();
            return;
        }

        $state['updated_at'] = $this->clock->now();
        $state['last_attempt_at'] = $state['updated_at'];
        $state['event_timestamp'] = $nextEvent['timestamp'];
        $state['event_args'] = $nextEvent['args'];
        $this->writeState($state);
    }

    /**
     * @param array{timestamp:int,args:array<int,mixed>} $event
     * @param int $now
     * @return AdmissionState
     */
    private function newAdmissionState(array $event, int $now): array {
        return array(
            'version' => self::STATE_VERSION,
            'kind' => self::STATE_KIND,
            'created_at' => $now,
            'updated_at' => $now,
            'admitted_at' => $now,
            'last_attempt_at' => 0,
            'event_timestamp' => $event['timestamp'],
            'event_args' => $event['args'],
        );
    }

    /** @return AdmissionState|null */
    private function readState(): ?array {
        $this->malformedState = false;
        $raw = $this->optionStore->getArrayOption(self::STATE_OPTION, null);
        if ($raw === null || $raw === false) {
            return null;
        }
        if (!is_array($raw)) {
            $this->invalidState('state is ' . gettype($raw) . ', expected an array');
            return null;
        }
        $required = array('version', 'kind', 'created_at', 'updated_at', 'admitted_at', 'last_attempt_at', 'event_timestamp', 'event_args');
        foreach ($required as $key) {
            if (!array_key_exists($key, $raw)) {
                $this->invalidState('state is missing required field ' . $key);
                return null;
            }
        }
        if (!is_int($raw['version']) || $raw['version'] !== self::STATE_VERSION) {
            $this->invalidState('state version is not ' . self::STATE_VERSION);
            return null;
        }
        if ($raw['kind'] !== self::STATE_KIND) {
            $this->invalidState('state kind is not ' . self::STATE_KIND);
            return null;
        }
        foreach (array('created_at', 'updated_at', 'admitted_at', 'event_timestamp') as $key) {
            if (!is_int($raw[$key]) || $raw[$key] <= 0) {
                $this->invalidState('state field ' . $key . ' is not a positive timestamp');
                return null;
            }
        }
        if (!is_int($raw['last_attempt_at']) || $raw['last_attempt_at'] < 0) {
            $this->invalidState('state field last_attempt_at is not a non-negative timestamp');
            return null;
        }
        if (!is_array($raw['event_args'])) {
            $this->invalidState('state field event_args is not an array');
            return null;
        }
        return array(
            'version' => self::STATE_VERSION,
            'kind' => self::STATE_KIND,
            'created_at' => (int)$raw['created_at'],
            'updated_at' => (int)$raw['updated_at'],
            'admitted_at' => (int)$raw['admitted_at'],
            'last_attempt_at' => (int)$raw['last_attempt_at'],
            'event_timestamp' => (int)$raw['event_timestamp'],
            'event_args' => array_values($raw['event_args']),
        );
    }

    /** @param string $reason @return void */
    private function invalidState(string $reason): void {
        $this->malformedState = true;
        $this->warn('N-gram cron fallback state is malformed: ' . $reason . '; refusing fallback work.');
    }

    /** @param array<string,mixed> $state @return bool */
    private function writeState(array $state): bool {
        $written = $this->optionStore->updateOption(self::STATE_OPTION, $state);
        $stored = $this->readState();
        if ($this->malformedState || $stored === null || !$this->sameState($stored, $state)) {
            $this->warn(
                'N-gram cron fallback admission state did not round-trip after storage '
                . '(write result ' . var_export($written, true) . ').'
            );
            return false;
        }
        return true;
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @return bool
     */
    private function sameState(array $left, array $right): bool {
        foreach (array('version', 'kind', 'created_at', 'updated_at', 'admitted_at', 'last_attempt_at', 'event_timestamp') as $key) {
            if (($left[$key] ?? null) !== ($right[$key] ?? null)) {
                return false;
            }
        }
        return serialize($left['event_args'] ?? array()) === serialize($right['event_args'] ?? array());
    }

    /**
     * @param AdmissionState $state
     * @param array{timestamp:int,args:array<int,mixed>} $event
     * @return bool
     */
    private function eventMatchesState(array $state, array $event): bool {
        return (int)$state['event_timestamp'] === $event['timestamp']
            && serialize($state['event_args']) === serialize($event['args']);
    }

    /**
     * A due event on a site with proved cron stall. CronDispatchStallEvidence
     * alone judges the stall (an overdue n-gram event proves it there too), so
     * no second age check here can disagree with it.
     *
     * @param array{timestamp:int,args:array<int,mixed>} $event @param int $now @return bool
     */
    private function isAdmissible(array $event, int $now): bool {
        if ($event['timestamp'] > $now) {
            return false;
        }
        return $this->scheduler->cronDispatchStallProven($event['timestamp']);
    }

    /**
     * Register the one shutdown callback for this component/request.
     *
     * @param FallbackIntent $intent
     */
    private function registerShutdownCallback(array $intent): bool {
        if ($this->armed) {
            return false;
        }
        $this->armed = true;
        $this->armedIntent = $intent;
        add_action('shutdown', function (): void {
            $this->runAfterResponse();
        });
        return true;
    }

    /**
     * Ensure the event selected during eligibility is still the event being
     * considered under the rebuild lock. A retire intent permits only the
     * original no-event observation to retire stale admission state.
     *
     * @param array{timestamp:int,args:array<int,mixed>}|false $event
     * @return bool
     */
    private function intentMatchesEvent($event): bool {
        if (!is_array($this->armedIntent)) {
            return false;
        }
        if ($this->armedIntent['kind'] === 'retire') {
            return $event === false;
        }
        if (!is_array($event)) {
            return false;
        }
        $expected = $this->armedIntent['event'];
        return $expected['timestamp'] === $event['timestamp']
            && serialize($expected['args']) === serialize($event['args']);
    }

    /** @return bool */
    private function retriesExhausted(): bool {
        $raw = $this->optionStore->getOption(
            ABJ_404_Solution_NGramRebuildProgressState::OPTION_CONSECUTIVE_FAILURES,
            0
        );
        $failures = ABJ_404_Solution_ExactInteger::read($raw, 0);
        return $failures !== null
            && $failures >= ABJ_404_Solution_NGramRebuildProgressState::MAX_CONSECUTIVE_FAILURES;
    }

    /** @return void */
    private function retireState(): void {
        $written = $this->optionStore->updateOption(self::STATE_OPTION, null);
        $remaining = $this->optionStore->getArrayOption(self::STATE_OPTION, null);
        if ($remaining !== null && $remaining !== false) {
            $this->warn(
                'N-gram cron fallback state could not be retired (write result '
                . var_export($written, true) . ').'
            );
        }
    }

    /** @param string $message @return void */
    private function warn(string $message): void {
        if ($this->logger !== null) {
            $this->logger->warn($message);
        }
    }
}
