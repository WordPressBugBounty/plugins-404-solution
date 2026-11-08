<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Delivers post-change redirects without taking ownership of another plugin's
 * transaction. Durable intents share the post writer's commit or rollback;
 * delivery subsequently owns its SERIALIZABLE transaction and graph lock.
 */
final class ABJ_404_Solution_PostChangeRedirectDelivery {
    /** @var ABJ_404_Solution_RedirectIntentRepository */
    private $intents;
    /** @var ABJ_404_Solution_RedirectsRepository */
    private $redirects;
    /** @var ABJ_404_Solution_DatabaseTransactionExecutor */
    private $transactions;
    /** @var ABJ_404_Solution_PluginLogicOptionsResolver */
    private $options;
    /** @var ABJ_404_Solution_RequestContext */
    private $requestContext;
    /** @var ABJ_404_Solution_Clock */
    private $clock;
    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param array{intents:ABJ_404_Solution_RedirectIntentRepository,
     * redirects:ABJ_404_Solution_RedirectsRepository,
     * transactions:ABJ_404_Solution_DatabaseTransactionExecutor,
     * options:ABJ_404_Solution_PluginLogicOptionsResolver,
     * requestContext:ABJ_404_Solution_RequestContext,
     * clock:ABJ_404_Solution_Clock,logger:ABJ_404_Solution_Logging} $dependencies
     */
    public function __construct(array $dependencies) {
        $this->intents = $dependencies['intents'];
        $this->redirects = $dependencies['redirects'];
        $this->transactions = $dependencies['transactions'];
        $this->options = $dependencies['options'];
        $this->requestContext = $dependencies['requestContext'];
        $this->clock = $dependencies['clock'];
        $this->logger = $dependencies['logger'];
    }

    /** Register ordinary-request delivery; WP-Cron and loopbacks are unnecessary. */
    public function register(): void {
        add_action('init', array($this, 'prepareSchema'), PHP_INT_MAX, 0);
        add_action('shutdown', array($this, 'runAfterResponse'), PHP_INT_MAX, 0);
    }

    /**
     * Prepare the normal schema migration before post-editing callbacks run.
     * An early caller-owned transaction defers DDL to a later safe request.
     */
    public function prepareSchema(): void {
        try {
            $options = $this->options->getOptions(true);
            if (ABJ_404_Solution_SettingsVersionStamp::isStale($options)
                    && !$this->transactions->hasActiveTransaction()) {
                $this->options->getOptions();
            }
        } catch (Throwable $failure) {
            $this->logger->warn('Post-change redirect schema preparation failed: '
                . get_class($failure) . ': ' . $failure->getMessage());
        }
    }

    /**
     * @return 'stored'|'queued'|'refused' A queue receipt is not a redirect ID.
     */
    public function deliver(ABJ_404_Solution_RedirectSpec $spec): string {
        if ($this->transactions->hasActiveTransaction()) {
            $this->intents->enqueue($spec);
            return 'queued';
        }
        return $this->redirects->setupRedirect($spec) > 0 ? 'stored' : 'refused';
    }

    /**
     * Deliver at most ten intents within the existing five-second continuation
     * admission window. No new item starts after it; failed work stays durable.
     */
    public function runAfterResponse(): void {
        try {
            if ($this->requestContext->ignore_doprocess
                    || $this->transactions->hasActiveTransaction()) {
                return;
            }
            $keys = $this->intents->pendingKeys(10);
            if ($keys === array()) { return; }
            ABJ_404_Solution_ResponseDetach::detach();
            $deadline = $this->clock->nowFloat()
                + ABJ_404_Solution_QueuedCronEventAdminContinuation::AFTER_RESPONSE_BUDGET_SECONDS;
            foreach ($keys as $key) {
                if ($this->clock->nowFloat() >= $deadline) { break; }
                $this->deliverIntent($key);
            }
        } catch (Throwable $failure) {
            $this->logger->warn('Deferred post-change redirect delivery failed: '
                . get_class($failure) . ': ' . $failure->getMessage());
        }
    }

    /**
     * Hold the graph lock through commit, not merely through the nested insert.
     * This also makes a process death between the insert and acknowledgment
     * harmless: either both changes commit, or both roll back.
     */
    private function deliverIntent(string $key): void {
        $error = $this->redirects->runWithGraphOwnership(function (callable $isOwned) use ($key): void {
            $this->transactions->runSerializableTransaction(function (ABJ_404_Solution_DatabaseTransactionConnection $connection) use ($key, $isOwned): void {
                $this->redirects->requireTransactionalStorage($connection);
                $intent = $this->intents->readForUpdate($key);
                if ($intent === null) { return; }
                $id = $this->redirects->setupRedirect($intent['spec']);
                $error = $this->redirects->getLastWriteError();
                if (!$isOwned() || !$connection->hasActiveTransaction()) {
                    throw new ABJ_404_Solution_DatabaseMutationFailedException(
                        ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED,
                        'Redirect graph or transaction ownership was lost before intent acknowledgment; the intent was retained.');
                }
                if ($id <= 0 && $error !== ''
                        && $this->redirects->getLastWriteRefusalReason() !== 'source-owned') {
                    throw new ABJ_404_Solution_DatabaseMutationFailedException(
                        ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED,
                        $error);
                }
                // A live source owner wins; an automatic destination that no
                // longer exists is obsolete. Neither may overwrite a rule.
                // A genuine storage refusal above keeps the intent for retry.
                $this->intents->delete($key);
            });
        });
        if ($error !== '') {
            throw new ABJ_404_Solution_DatabaseMutationFailedException(
                ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED, $error);
        }
    }
}
