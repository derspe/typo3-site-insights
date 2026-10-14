<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Daily Search Console snapshots (top queries / top pages), runs and visibility index.
 */
final readonly class SnapshotRepository
{
    public const QUERY_TABLE = 'tx_derspesiteinsights_gsc_query';
    public const PAGE_TABLE = 'tx_derspesiteinsights_gsc_page';
    public const RUN_TABLE = 'tx_derspesiteinsights_gsc_run';
    public const VISIBILITY_TABLE = 'tx_derspesiteinsights_gsc_visibility';

    private const ORDER_COLUMNS = ['clicks', 'impressions', 'ctr', 'position'];

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * Replaces all rows of one day.
     *
     * @param 'query'|'page' $dimension
     * @param list<array{key: string, clicks: int, impressions: int, ctr: float, position: float}> $rows
     */
    public function writeDay(string $site, string $date, string $dimension, array $rows): int
    {
        $table = $dimension === 'query' ? self::QUERY_TABLE : self::PAGE_TABLE;
        $connection = $this->connectionPool->getConnectionForTable($table);
        $connection->delete($table, ['site' => $site, 'snapshot_date' => $date]);
        $unique = [];
        foreach ($rows as $row) {
            $unique[sha1($row['key'])] = $row;
        }
        $insert = [];
        foreach ($unique as $hash => $row) {
            $insert[] = [$site, $date, $hash, $row['key'], $row['clicks'], $row['impressions'], $row['ctr'], $row['position']];
        }
        if ($insert !== []) {
            $connection->bulkInsert(
                $table,
                $insert,
                ['site', 'snapshot_date', $dimension . '_hash', $dimension, 'clicks', 'impressions', 'ctr', 'position']
            );
        }
        return count($insert);
    }

    public function countQueryRows(string $site, string $date): int
    {
        return (int)$this->connectionPool->getConnectionForTable(self::QUERY_TABLE)
            ->count('*', self::QUERY_TABLE, ['site' => $site, 'snapshot_date' => $date]);
    }

    public function latestQuerySnapshotDate(string $site): ?string
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::QUERY_TABLE);
        $date = $qb->selectLiteral('MAX(snapshot_date) AS d')
            ->from(self::QUERY_TABLE)
            ->where($qb->expr()->eq('site', $qb->createNamedParameter($site)))
            ->executeQuery()
            ->fetchOne();
        return is_string($date) && $date !== '' ? $date : null;
    }

    /**
     * Aggregate over a date range: clicks/impressions summed, CTR recalculated, position impression-weighted.
     *
     * @param 'query'|'page' $dimension
     * @return list<array<string, mixed>>
     */
    public function topForRange(string $site, string $dimension, string $from, string $to, int $limit, string $orderBy = 'clicks'): array
    {
        $table = $dimension === 'query' ? self::QUERY_TABLE : self::PAGE_TABLE;
        $orderBy = in_array($orderBy, self::ORDER_COLUMNS, true) ? $orderBy : 'clicks';
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $rows = $qb
            ->selectLiteral(
                'MIN(' . $dimension . ') AS ' . $qb->quoteIdentifier($dimension),
                'SUM(clicks) AS clicks',
                'SUM(impressions) AS impressions',
                'COALESCE(SUM(clicks) * 1.0 / NULLIF(SUM(impressions), 0), 0) AS ctr',
                'COALESCE(SUM(position * impressions) / NULLIF(SUM(impressions), 0), 0) AS ' . $qb->quoteIdentifier('position')
            )
            ->from($table)
            ->where(
                $qb->expr()->eq('site', $qb->createNamedParameter($site)),
                $qb->expr()->gte('snapshot_date', $qb->createNamedParameter($from)),
                $qb->expr()->lte('snapshot_date', $qb->createNamedParameter($to))
            )
            ->groupBy($dimension . '_hash')
            ->orderBy($orderBy, $orderBy === 'position' ? 'ASC' : 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();
        return array_map(static fn(array $r): array => [
            $dimension => (string)$r[$dimension],
            'clicks' => (int)$r['clicks'],
            'impressions' => (int)$r['impressions'],
            'ctr' => (float)$r['ctr'],
            'position' => (float)$r['position'],
        ], $rows);
    }

    /**
     * @param 'query'|'page' $dimension
     * @return list<array{date: string, clicks: int, impressions: int, ctr: float, position: float}>
     */
    public function series(string $site, string $dimension, string $key): array
    {
        $table = $dimension === 'query' ? self::QUERY_TABLE : self::PAGE_TABLE;
        $rows = $this->connectionPool->getConnectionForTable($table)
            ->select(
                ['snapshot_date', 'clicks', 'impressions', 'ctr', 'position'],
                $table,
                ['site' => $site, $dimension . '_hash' => sha1($key)],
                [],
                ['snapshot_date' => 'ASC']
            )
            ->fetchAllAssociative();
        return array_map(static fn(array $r): array => [
            'date' => (string)$r['snapshot_date'],
            'clicks' => (int)$r['clicks'],
            'impressions' => (int)$r['impressions'],
            'ctr' => (float)$r['ctr'],
            'position' => (float)$r['position'],
        ], $rows);
    }

    /**
     * @return list<array{impressions: int, position: float}>
     */
    public function queryRowsForDate(string $site, string $date): array
    {
        return $this->connectionPool->getConnectionForTable(self::QUERY_TABLE)
            ->select(['impressions', 'position'], self::QUERY_TABLE, ['site' => $site, 'snapshot_date' => $date])
            ->fetchAllAssociative();
    }

    /**
     * @return list<string>
     */
    public function allSnapshotDates(string $site): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::QUERY_TABLE);
        return array_map('strval', $qb->select('snapshot_date')
            ->from(self::QUERY_TABLE)
            ->where($qb->expr()->eq('site', $qb->createNamedParameter($site)))
            ->groupBy('snapshot_date')
            ->orderBy('snapshot_date', 'ASC')
            ->executeQuery()
            ->fetchFirstColumn());
    }

    public function upsertVisibility(string $site, string $date, float $score, int $queryCount): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::VISIBILITY_TABLE);
        $connection->delete(self::VISIBILITY_TABLE, ['site' => $site, 'snapshot_date' => $date]);
        $connection->insert(self::VISIBILITY_TABLE, [
            'site' => $site,
            'snapshot_date' => $date,
            'score' => $score,
            'query_count' => $queryCount,
            'computed_at' => time(),
        ]);
    }

    /**
     * @return list<array{date: string, score: float, queryCount: int}>
     */
    public function visibilitySeries(string $site, ?string $from = null, ?string $to = null): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::VISIBILITY_TABLE);
        $qb->select('snapshot_date', 'score', 'query_count')
            ->from(self::VISIBILITY_TABLE)
            ->where($qb->expr()->eq('site', $qb->createNamedParameter($site)))
            ->orderBy('snapshot_date', 'ASC');
        if ($from !== null) {
            $qb->andWhere($qb->expr()->gte('snapshot_date', $qb->createNamedParameter($from)));
        }
        if ($to !== null) {
            $qb->andWhere($qb->expr()->lte('snapshot_date', $qb->createNamedParameter($to)));
        }
        return array_map(static fn(array $r): array => [
            'date' => (string)$r['snapshot_date'],
            'score' => (float)$r['score'],
            'queryCount' => (int)$r['query_count'],
        ], $qb->executeQuery()->fetchAllAssociative());
    }

    public function prune(string $site, int $monthsToKeep = 18): void
    {
        $limit = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->modify('-' . $monthsToKeep . ' months')->format('Y-m-d');
        foreach ([self::QUERY_TABLE, self::PAGE_TABLE] as $table) {
            $qb = $this->connectionPool->getQueryBuilderForTable($table);
            $qb->delete($table)
                ->where(
                    $qb->expr()->eq('site', $qb->createNamedParameter($site)),
                    $qb->expr()->lt('snapshot_date', $qb->createNamedParameter($limit))
                )
                ->executeStatement();
        }
    }

    public function startRun(string $site, string $date): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::RUN_TABLE);
        $connection->insert(self::RUN_TABLE, [
            'site' => $site,
            'snapshot_date' => $date,
            'started_at' => time(),
            'status' => 'running',
        ]);
        return (int)$connection->lastInsertId();
    }

    public function finishRun(int $uid, string $status, int $rowsWritten = 0, string $error = ''): void
    {
        $this->connectionPool->getConnectionForTable(self::RUN_TABLE)->update(
            self::RUN_TABLE,
            ['finished_at' => time(), 'status' => $status, 'rows_written' => $rowsWritten, 'error' => $error],
            ['uid' => $uid]
        );
    }

    /**
     * Runs younger than one hour block a new run; older ones are orphans and marked as error.
     */
    public function hasActiveRun(string $site): bool
    {
        $connection = $this->connectionPool->getConnectionForTable(self::RUN_TABLE);
        $qb = $connection->createQueryBuilder();
        $qb->update(self::RUN_TABLE)
            ->set('status', 'error')
            ->set('error', 'timeout')
            ->set('finished_at', time(), true, Connection::PARAM_INT)
            ->where(
                $qb->expr()->eq('site', $qb->createNamedParameter($site)),
                $qb->expr()->eq('status', $qb->createNamedParameter('running')),
                $qb->expr()->lt('started_at', $qb->createNamedParameter(time() - 3600, Connection::PARAM_INT))
            )
            ->executeStatement();
        return $connection->count('*', self::RUN_TABLE, ['site' => $site, 'status' => 'running']) > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestRun(string $site): ?array
    {
        $row = $this->connectionPool->getConnectionForTable(self::RUN_TABLE)
            ->select(['*'], self::RUN_TABLE, ['site' => $site], [], ['uid' => 'DESC'], 1)
            ->fetchAssociative();
        return $row === false ? null : $row;
    }
}
