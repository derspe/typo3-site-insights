<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Service\Job;

use Derspe\SiteInsights\Domain\Repository\InspectRepository;
use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Service\Gsc\InspectService;
use Derspe\SiteInsights\Service\Gsc\SnapshotService;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

/**
 * Long running work (index scan, snapshot backfill) as database jobs, processed in
 * time-boxed steps. Steps are driven by the polling backend UI and by the scheduler
 * command, so no long running PHP process is needed (works on shared hosting).
 */
final readonly class JobService
{
    public const TABLE = 'tx_derspesiteinsights_job';
    public const TYPE_INDEX_SCAN = 'index_scan';
    public const TYPE_BACKFILL = 'backfill';

    /** URL Inspection API: 2000/day and 600/minute per property */
    private const INSPECT_DAILY_CAP = 1800;
    private const INSPECT_INTERVAL = 1.1;
    private const BACKFILL_INTERVAL = 0.5;

    public function __construct(
        private ConnectionPool $connectionPool,
        private LockFactory $lockFactory,
        private InspectService $inspectService,
        private InspectRepository $inspects,
        private SnapshotService $snapshotService,
    ) {}

    /**
     * Creates a job, or returns the active one of the same type.
     *
     * @param array<string, mixed> $options
     */
    public function create(string $site, string $type, array $options = []): int
    {
        $this->expireAbandoned();
        $active = $this->findActive($site, $type);
        if ($active !== null) {
            return (int)$active['uid'];
        }
        $state = match ($type) {
            self::TYPE_INDEX_SCAN => ['force' => (bool)($options['force'] ?? false), 'urls' => null, 'cursor' => 0],
            self::TYPE_BACKFILL => ['dates' => SnapshotService::backfillDates((int)($options['days'] ?? 90)), 'cursor' => 0, 'fetched' => 0],
            default => throw new ApiException('Unknown job type', 400),
        };
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'site' => $site,
            'type' => $type,
            'status' => 'pending',
            'state' => json_encode($state, JSON_UNESCAPED_SLASHES),
            'progress' => json_encode(['key' => 'job.pending']),
            'total' => $type === self::TYPE_BACKFILL ? count($state['dates']) : 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        return (int)$connection->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActive(string $site, string $type): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $qb->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('site', $qb->createNamedParameter($site)),
                $qb->expr()->eq('type', $qb->createNamedParameter($type)),
                $qb->expr()->in('status', $qb->createNamedParameter(['pending', 'running'], Connection::PARAM_STR_ARRAY))
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $site, int $uid): ?array
    {
        $row = $this->connectionPool->getConnectionForTable(self::TABLE)
            ->select(['*'], self::TABLE, ['uid' => $uid, 'site' => $site])
            ->fetchAssociative();
        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAllActive(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->in('status', $qb->createNamedParameter(['pending', 'running'], Connection::PARAM_STR_ARRAY)))
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Processes the job for at most $budgetSeconds. Returns null if another process holds the job.
     *
     * @return array<string, mixed>|null
     */
    public function step(string $site, int $uid, float $budgetSeconds): ?array
    {
        $locker = $this->lockFactory->createLocker(
            'derspe_site_insights_job_' . $uid,
            LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK
        );
        try {
            if (!$locker->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK)) {
                return null;
            }
        } catch (LockAcquireWouldBlockException) {
            return null;
        }
        try {
            $job = $this->find($site, $uid);
            if ($job === null || !in_array($job['status'], ['pending', 'running'], true)) {
                return $job;
            }
            $deadline = microtime(true) + $budgetSeconds;
            $job['status'] = 'running';
            $state = json_decode((string)$job['state'], true) ?: [];
            try {
                $job = match ($job['type']) {
                    self::TYPE_INDEX_SCAN => $this->stepIndexScan($job, $state, $deadline),
                    self::TYPE_BACKFILL => $this->stepBackfill($job, $state, $deadline),
                    default => ['status' => 'error', 'error' => 'Unknown job type'] + $job,
                };
            } catch (\Throwable $e) {
                $job['status'] = 'error';
                $job['error'] = $e->getMessage();
            }
            $this->save($job);
            return $job;
        } finally {
            $locker->release();
        }
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function stepIndexScan(array $job, array $state, float $deadline): array
    {
        $site = (string)$job['site'];
        if (!is_array($state['urls'] ?? null)) {
            $state['urls'] = $this->inspectService->monitoredUrls($site, true);
            $state['cursor'] = 0;
            $job['total'] = count($state['urls']);
        }
        $urls = $state['urls'];
        $total = count($urls);
        $cursor = (int)$state['cursor'];
        $force = (bool)($state['force'] ?? false);
        $cached = $force ? [] : $this->inspects->findMany($site, array_slice($urls, $cursor));
        $lastCall = 0.0;

        while ($cursor < $total && microtime(true) < $deadline) {
            $url = $urls[$cursor];
            if (!$force && InspectRepository::isFresh($cached[$url] ?? null)) {
                $job['skipped']++;
                $cursor++;
                continue;
            }
            if ((int)$job['inspected'] >= self::INSPECT_DAILY_CAP) {
                return $this->finish($job, $state, $cursor, ['key' => 'job.scan.cap', 'args' => ['cap' => self::INSPECT_DAILY_CAP]]);
            }
            $wait = self::INSPECT_INTERVAL - (microtime(true) - $lastCall);
            if ($wait > 0 && $lastCall > 0) {
                if (microtime(true) + $wait > $deadline) {
                    break;
                }
                usleep((int)($wait * 1_000_000));
            }
            $job['progress'] = ['key' => 'job.scan.inspecting', 'args' => ['n' => $cursor + 1, 'total' => $total, 'url' => $url]];
            $lastCall = microtime(true);
            try {
                $this->inspectService->inspect($site, $url, true);
                $job['inspected']++;
            } catch (ApiException $e) {
                if ($e->status === 429) {
                    return $this->finish($job, $state, $cursor, ['key' => 'job.scan.quota']);
                }
                if ($e->status === 503) {
                    throw $e;
                }
                $job['errors']++;
            }
            $cursor++;
        }

        if ($cursor >= $total) {
            return $this->finish($job, $state, $cursor, ['key' => 'job.scan.done', 'args' => [
                'inspected' => (int)$job['inspected'],
                'skipped' => (int)$job['skipped'],
                'errors' => (int)$job['errors'],
            ]]);
        }
        $state['cursor'] = $cursor;
        $job['done'] = $cursor;
        $job['state'] = $state;
        if (!is_array($job['progress'] ?? null) || ($job['progress']['key'] ?? '') !== 'job.scan.inspecting') {
            $job['progress'] = ['key' => 'job.scan.inspecting', 'args' => ['n' => $cursor, 'total' => $total, 'url' => $urls[$cursor] ?? '']];
        }
        return $job;
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function stepBackfill(array $job, array $state, float $deadline): array
    {
        $site = (string)$job['site'];
        $dates = $state['dates'] ?? [];
        $cursor = (int)($state['cursor'] ?? 0);
        $fetched = (int)($state['fetched'] ?? 0);
        while ($cursor < count($dates) && microtime(true) < $deadline) {
            $date = $dates[$cursor];
            $job['progress'] = ['key' => 'job.backfill.running', 'args' => ['date' => $date, 'n' => $cursor + 1, 'total' => count($dates)]];
            try {
                $result = $this->snapshotService->run($site, $date);
                if ($result['skipped']) {
                    $job['skipped']++;
                } else {
                    $fetched++;
                    usleep((int)(self::BACKFILL_INTERVAL * 1_000_000));
                }
            } catch (ApiException $e) {
                if ($e->status === 429 || $e->status === 503) {
                    $state['fetched'] = $fetched;
                    $job['inspected'] = $fetched;
                    $job['error'] = $e->getMessage();
                    return $this->finish($job, $state, $cursor, ['key' => 'job.backfill.aborted'], 'error');
                }
                if ($e->status === 409) {
                    break;
                }
                $job['errors']++;
            }
            $cursor++;
        }
        $state['fetched'] = $fetched;
        $job['inspected'] = $fetched;
        if ($cursor >= count($dates)) {
            return $this->finish($job, $state, $cursor, ['key' => 'job.backfill.done', 'args' => [
                'fetched' => $fetched,
                'skipped' => (int)$job['skipped'],
                'errors' => (int)$job['errors'],
            ]]);
        }
        $state['cursor'] = $cursor;
        $job['done'] = $cursor;
        $job['state'] = $state;
        return $job;
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $state
     * @param array<string, mixed> $progress
     * @return array<string, mixed>
     */
    private function finish(array $job, array $state, int $cursor, array $progress, string $status = 'done'): array
    {
        $state['cursor'] = $cursor;
        // The URL list is not needed anymore once the job is finished
        unset($state['urls']);
        $job['state'] = $state;
        $job['done'] = $status === 'done' ? (int)$job['total'] : $cursor;
        $job['status'] = $status;
        $job['progress'] = $progress;
        return $job;
    }

    /**
     * @param array<string, mixed> $job
     */
    private function save(array $job): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(self::TABLE, [
            'status' => $job['status'],
            'state' => is_array($job['state']) ? json_encode($job['state'], JSON_UNESCAPED_SLASHES) : (string)$job['state'],
            'progress' => is_array($job['progress']) ? json_encode($job['progress'], JSON_UNESCAPED_SLASHES) : (string)$job['progress'],
            'total' => (int)$job['total'],
            'done' => (int)$job['done'],
            'inspected' => (int)$job['inspected'],
            'skipped' => (int)$job['skipped'],
            'errors' => (int)$job['errors'],
            'error' => (string)($job['error'] ?? ''),
            'updated_at' => time(),
        ], ['uid' => (int)$job['uid']]);
    }

    /**
     * Jobs nobody advanced for a day are given up.
     */
    private function expireAbandoned(): void
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->update(self::TABLE)
            ->set('status', 'error')
            ->set('error', 'abandoned')
            ->where(
                $qb->expr()->in('status', $qb->createNamedParameter(['pending', 'running'], Connection::PARAM_STR_ARRAY)),
                $qb->expr()->lt('updated_at', $qb->createNamedParameter(time() - 86400, Connection::PARAM_INT))
            )
            ->executeStatement();
        // Keep the table small
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->delete(self::TABLE)
            ->where($qb->expr()->lt('updated_at', $qb->createNamedParameter(time() - 30 * 86400, Connection::PARAM_INT)))
            ->executeStatement();
    }

    /**
     * @param array<string, mixed>|null $job
     * @return array<string, mixed>|null
     */
    public static function toDto(?array $job): ?array
    {
        if ($job === null) {
            return null;
        }
        $progress = is_array($job['progress'] ?? null) ? $job['progress'] : (json_decode((string)($job['progress'] ?? ''), true) ?: null);
        return [
            'id' => (int)$job['uid'],
            'type' => (string)$job['type'],
            'status' => (string)$job['status'],
            'total' => (int)$job['total'],
            'done' => (int)$job['done'],
            'inspected' => (int)$job['inspected'],
            'skipped' => (int)$job['skipped'],
            'errors' => (int)$job['errors'],
            'progress' => $progress,
            'error' => (string)($job['error'] ?? '') ?: null,
        ];
    }
}
