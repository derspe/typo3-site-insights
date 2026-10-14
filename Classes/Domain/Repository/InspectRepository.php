<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Cache of URL Inspection results (index status, coverage, rich results).
 */
final readonly class InspectRepository
{
    public const TABLE = 'tx_derspesiteinsights_gsc_inspect';
    public const FRESH_SECONDS = 7 * 86400;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $site, string $url): ?array
    {
        $row = $this->connectionPool->getConnectionForTable(self::TABLE)
            ->select(['*'], self::TABLE, ['site' => $site, 'url_hash' => sha1($url)])
            ->fetchAssociative();
        return $row === false ? null : $row;
    }

    /**
     * @param list<string> $urls
     * @return array<string, array<string, mixed>> keyed by URL
     */
    public function findMany(string $site, array $urls): array
    {
        if ($urls === []) {
            return [];
        }
        $result = [];
        foreach (array_chunk($urls, 500) as $chunk) {
            $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $rows = $qb->select('*')
                ->from(self::TABLE)
                ->where(
                    $qb->expr()->eq('site', $qb->createNamedParameter($site)),
                    $qb->expr()->in('url_hash', $qb->createNamedParameter(array_map('sha1', $chunk), Connection::PARAM_STR_ARRAY))
                )
                ->executeQuery()
                ->fetchAllAssociative();
            foreach ($rows as $row) {
                $result[(string)$row['url']] = $row;
            }
        }
        return $result;
    }

    /**
     * @param array<string, mixed>|null $row
     */
    public static function isFresh(?array $row): bool
    {
        return $row !== null && (time() - (int)$row['inspected_at']) < self::FRESH_SECONDS;
    }

    /**
     * @param array<string, mixed> $raw URL Inspection API response
     * @return array<string, mixed>
     */
    public function save(string $site, string $url, array $raw): array
    {
        $index = $raw['inspectionResult']['indexStatusResult'] ?? [];
        $rich = $raw['inspectionResult']['richResultsResult'] ?? [];
        $richTypes = array_values(array_unique(array_filter(array_map(
            static fn(array $item): string => (string)($item['richResultType'] ?? ''),
            $rich['detectedItems'] ?? []
        ))));
        $referring = count($index['referringUrls'] ?? []);
        $row = [
            'site' => $site,
            'url_hash' => sha1($url),
            'url' => $url,
            'inspected_at' => time(),
            'index_status' => $index['verdict'] ?? null,
            'coverage_state' => $index['coverageState'] ?? null,
            'last_crawl' => $index['lastCrawlTime'] ?? null,
            'crawled_as' => $index['crawledAs'] ?? null,
            'page_fetch_state' => $index['pageFetchState'] ?? null,
            'robots_txt_state' => $index['robotsTxtState'] ?? null,
            'referring_urls_count' => $referring > 0 ? $referring : null,
            'rich_results_verdict' => $rich['verdict'] ?? null,
            'rich_results_types' => json_encode($richTypes),
            'raw_json' => json_encode($raw, JSON_UNESCAPED_SLASHES),
        ];
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->delete(self::TABLE, ['site' => $site, 'url_hash' => $row['url_hash']]);
        $connection->insert(self::TABLE, $row);
        return $row;
    }

    public function pruneOlderThan(string $site, int $seconds): void
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->delete(self::TABLE)
            ->where(
                $qb->expr()->eq('site', $qb->createNamedParameter($site)),
                $qb->expr()->lt('inspected_at', $qb->createNamedParameter(time() - $seconds, Connection::PARAM_INT))
            )
            ->executeStatement();
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>|null
     */
    public static function toDto(?array $row, bool $cacheHit): ?array
    {
        if ($row === null) {
            return null;
        }
        return [
            'url' => $row['url'],
            'inspectedAt' => (int)$row['inspected_at'],
            'indexStatus' => $row['index_status'],
            'coverageState' => $row['coverage_state'],
            'lastCrawl' => $row['last_crawl'],
            'crawledAs' => $row['crawled_as'],
            'pageFetchState' => $row['page_fetch_state'],
            'robotsTxtState' => $row['robots_txt_state'],
            'referringUrlsCount' => $row['referring_urls_count'] !== null ? (int)$row['referring_urls_count'] : null,
            'richVerdict' => $row['rich_results_verdict'],
            'richTypes' => json_decode((string)($row['rich_results_types'] ?: '[]'), true) ?: [],
            'cacheHit' => $cacheHit,
        ];
    }
}
