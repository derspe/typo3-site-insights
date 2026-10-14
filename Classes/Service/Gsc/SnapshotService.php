<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Service\Gsc;

use Derspe\SiteInsights\Domain\Repository\InspectRepository;
use Derspe\SiteInsights\Domain\Repository\SnapshotRepository;
use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Settings\SiteSettings;

/**
 * Stores the daily top queries/pages of a site and keeps the visibility index up to date.
 */
final readonly class SnapshotService
{
    /** Search Console data is complete after roughly three days */
    public const LATENCY_DAYS = 3;
    public const MAX_BACKFILL_DAYS = 90;

    public function __construct(
        private SearchConsoleClient $client,
        private SnapshotRepository $snapshots,
        private InspectRepository $inspects,
        private SiteSettings $settings,
    ) {}

    public static function defaultSnapshotDate(?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $now->setTimezone(new \DateTimeZone('UTC'))->modify('-' . self::LATENCY_DAYS . ' days')->format('Y-m-d');
    }

    /**
     * Dates of the last $days snapshot days, newest first.
     *
     * @return list<string>
     */
    public static function backfillDates(int $days, ?\DateTimeImmutable $now = null): array
    {
        $days = max(1, min(self::MAX_BACKFILL_DAYS, $days));
        $first = new \DateTimeImmutable(self::defaultSnapshotDate($now), new \DateTimeZone('UTC'));
        $dates = [];
        for ($i = 0; $i < $days; $i++) {
            $dates[] = $first->modify('-' . $i . ' days')->format('Y-m-d');
        }
        return $dates;
    }

    public static function isComplete(int $rowCount, int $topN): bool
    {
        return $rowCount >= intdiv($topN, 2);
    }

    private function topN(string $site): int
    {
        return max(10, $this->settings->getInt($site, 'gsc_snapshot_top_n') ?: 100);
    }

    /**
     * @return array{skipped: bool, snapshotDate: string, queries?: int, pages?: int, reason?: string}
     */
    public function run(string $site, ?string $date = null, bool $force = false): array
    {
        if ($this->snapshots->hasActiveRun($site)) {
            throw new ApiException('Snapshot is already running', 409);
        }
        $topN = $this->topN($site);
        $date ??= self::defaultSnapshotDate();
        if (!$force && self::isComplete($this->snapshots->countQueryRows($site, $date), $topN)) {
            return ['skipped' => true, 'snapshotDate' => $date, 'reason' => 'already complete'];
        }

        $runId = $this->snapshots->startRun($site, $date);
        try {
            $body = static fn(string $dimension): array => [
                'startDate' => $date,
                'endDate' => $date,
                'dimensions' => [$dimension],
                'type' => 'web',
                'rowLimit' => $topN,
            ];
            $result = $this->client->searchAnalyticsMany($site, ['query' => $body('query'), 'page' => $body('page')]);
            $queries = $this->snapshots->writeDay($site, $date, 'query', self::rows($result['query']['rows'] ?? []));
            $pages = $this->snapshots->writeDay($site, $date, 'page', self::rows($result['page']['rows'] ?? []));
            $this->recordVisibility($site, $date);
            $this->snapshots->prune($site, 18);
            $this->inspects->pruneOlderThan($site, 30 * 86400);
            $this->snapshots->finishRun($runId, 'done', $queries + $pages);
            return ['skipped' => false, 'snapshotDate' => $date, 'queries' => $queries, 'pages' => $pages];
        } catch (\Throwable $e) {
            $this->snapshots->finishRun($runId, 'error', 0, $e->getMessage());
            throw $e;
        }
    }

    public function recordVisibility(string $site, string $date): ?float
    {
        $rows = $this->snapshots->queryRowsForDate($site, $date);
        if ($rows === []) {
            return null;
        }
        $score = GscNormalizer::visibilityScore($rows);
        $this->snapshots->upsertVisibility($site, $date, $score, count($rows));
        return $score;
    }

    /**
     * Recalculates the visibility index from all stored snapshots.
     */
    public function rebuildVisibility(string $site): int
    {
        $days = 0;
        foreach ($this->snapshots->allSnapshotDates($site) as $date) {
            if ($this->recordVisibility($site, $date) !== null) {
                $days++;
            }
        }
        return $days;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{key: string, clicks: int, impressions: int, ctr: float, position: float}>
     */
    private static function rows(array $rows): array
    {
        return array_map(static fn(array $r): array => [
            'key' => (string)($r['keys'][0] ?? ''),
            'clicks' => (int)($r['clicks'] ?? 0),
            'impressions' => (int)($r['impressions'] ?? 0),
            'ctr' => (float)($r['ctr'] ?? 0),
            'position' => (float)($r['position'] ?? 0),
        ], $rows);
    }
}
