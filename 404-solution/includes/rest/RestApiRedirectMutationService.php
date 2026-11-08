<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Executes REST redirect mutations through repository value objects.
 */
final class ABJ_404_Solution_RestApiRedirectMutationService {

    /** @var ABJ_404_Solution_RedirectsRepositoryInterface */
    private $redirectsRepo;

    /** @var ABJ_404_Solution_RestApiResponsePresenter */
    private $presenter;

    /**
     * @param ABJ_404_Solution_RedirectsRepositoryInterface $redirectsRepo
     */
    public function __construct($redirectsRepo, ABJ_404_Solution_RestApiResponsePresenter $presenter) {
        $this->redirectsRepo = $redirectsRepo;
        $this->presenter = $presenter;
    }

    /**
     * @param array{from: string, to: string, code: int, regex: bool, query_mode?: string|null} $input
     * @return \WP_REST_Response|\WP_Error
     */
    public function create(array $input) {
        $status = $input['regex'] ? (string)ABJ404_STATUS_REGEX : (string)ABJ404_STATUS_MANUAL;
        $resolved = $this->resolveDestinationType($input['to']);

        try {
            $insertedId = $this->redirectsRepo->setupRedirect(
                \ABJ_404_Solution_RedirectSpec::fromArray(array(
                    'fromURL' => $input['from'],
                    'status' => $status,
                    'type' => (string)$resolved['type'],
                    'finalDest' => $resolved['dest'],
                    'code' => (string)$input['code'],
                    'disabled' => 0,
                    'engine' => 'rest-api',
                    'query_mode' => $input['query_mode'] ?? null,
                ))
            );
        } catch (\ABJ_404_Solution_RedirectCycleException $e) {
            return $this->presenter->createFailed($e->getMessage());
        }

        if (!$insertedId) {
            $cycleError = method_exists($this->redirectsRepo, 'getLastWriteError')
                ? (string)$this->redirectsRepo->getLastWriteError() : '';
            if ($cycleError !== '') {
                return $this->presenter->createFailed($cycleError);
            }
            return $this->presenter->createFailed();
        }

        return $this->presenter->redirectCreated((int)$insertedId, $input['from'], $input['to'], $input['code'], $status);
    }

    /**
     * @param array{id: int, from: string, to: string, code: int, regex: bool, query_mode?: string|null} $input
     * @return \WP_REST_Response|\WP_Error
     */
    public function update(array $input) {
        $statusType = $input['regex'] ? (string)ABJ404_STATUS_REGEX : (string)ABJ404_STATUS_MANUAL;
        $resolved = $this->resolveDestinationType($input['to']);

        try {
            $error = $this->redirectsRepo->updateRedirect(ABJ_404_Solution_RedirectUpdate::fromArray(array_intersect_key($input, array('query_mode' => true)) + array(
                'id' => $input['id'],
                'type' => (int)$resolved['type'],
                'fromUrl' => (string)$input['from'],
                'destination' => (string)$resolved['dest'],
                'code' => (string)$input['code'],
                'statusType' => (string)$statusType,
            )));
        } catch (\ABJ_404_Solution_RedirectCycleException $e) {
            return $this->presenter->updateFailed($e->getMessage());
        }

        if ($error !== '') {
            return $this->presenter->updateFailed((string)$error);
        }

        return $this->presenter->redirectUpdated($input['id'], $input['from'], $input['to'], $input['code'], $statusType);
    }

    /**
     * @param array{id: int} $input
     * @return \WP_REST_Response|\WP_Error
     */
    public function trash(array $input) {
        $error = $this->redirectsRepo->moveRedirectsToTrash($input['id'], 1);

        if ($error !== '') {
            return $this->presenter->trashFailed((string)$error);
        }

        return $this->presenter->redirectTrashed($input['id']);
    }

    /**
     * @param array{id: int, to: string, code: int, query_mode?: string|null} $input
     * @return \WP_REST_Response|\WP_Error
     */
    public function promoteCaptured(array $input) {
        $resolved = $this->resolveDestinationType($input['to']);
        $from = '';
        try {
            $error = $this->redirectsRepo->updateRedirectFromStoredRow($input['id'], function (array $row) use (&$from, $input, $resolved) {
                $from = isset($row['url']) && is_string($row['url']) ? $row['url'] : '';
                if ($from === '') {
                    return 'bad_captured_record';
                }
                return ABJ_404_Solution_RedirectUpdate::fromArray(array_intersect_key($input, array('query_mode' => true)) + array(
                    'id' => $input['id'],
                    'type' => (int)$resolved['type'],
                    'fromUrl' => $from,
                    'destination' => (string)$resolved['dest'],
                    'code' => (string)$input['code'],
                    'statusType' => (string)ABJ404_STATUS_MANUAL,
                ));
            });
        } catch (\ABJ_404_Solution_RedirectCycleException $e) {
            return $this->presenter->updateFailed($e->getMessage());
        }

        if ($error === ABJ_404_Solution_RedirectsRepositoryInterface::ROW_NOT_FOUND) {
            return $this->presenter->capturedNotFound();
        }
        if ($error === 'bad_captured_record') {
            return $this->presenter->capturedBadRecord();
        }
        if ($error !== '') {
            return $this->presenter->updateFailed((string)$error);
        }

        return $this->presenter->capturedPromoted($input['id'], $from, $input['to'], $input['code']);
    }

    /**
     * @param string $to
     * @return array{type: int, dest: string}
     */
    private function resolveDestinationType($to): array {
        if ($this->looksLikeExternalUrl($to)) {
            return array('type' => (int)ABJ404_TYPE_EXTERNAL, 'dest' => $to);
        }

        $trimmed = trim($to, '/ ');
        if ($trimmed === '') {
            return array('type' => (int)ABJ404_TYPE_HOME, 'dest' => (string)ABJ404_TYPE_HOME);
        }

        if (function_exists('url_to_postid')) {
            $postId = url_to_postid(home_url($to));
            if ($postId > 0) {
                return array('type' => (int)ABJ404_TYPE_POST, 'dest' => (string)$postId);
            }
        }

        return array('type' => (int)ABJ404_TYPE_EXTERNAL, 'dest' => $to);
    }

    private function looksLikeExternalUrl(string $url): bool {
        return strncasecmp($url, 'http://', 7) === 0 || strncasecmp($url, 'https://', 8) === 0;
    }
}
