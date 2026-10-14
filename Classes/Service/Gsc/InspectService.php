<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Service\Gsc;

use Derspe\SiteInsights\Domain\Repository\InspectRepository;
use Derspe\SiteInsights\Service\ApiCache;

/**
 * URL Inspection with a seven day cache, and the index overview of all monitored URLs.
 */
final readonly class InspectService
{
    public function __construct(
        private SearchConsoleClient $client,
        private InspectRepository $inspects,
        private UrlCollector $urlCollector,
        private ApiCache $cache,
    ) {}

    /**
     * @return array{row: array<string, mixed>, cacheHit: bool}
     */
    public function inspect(string $site, string $url, bool $force = false): array
    {
        if (!$force) {
            $cached = $this->inspects->find($site, $url);
            if (InspectRepository::isFresh($cached)) {
                return ['row' => $cached, 'cacheHit' => true];
            }
        }
        return ['row' => $this->inspects->save($site, $url, $this->client->inspectUrl($site, $url)), 'cacheHit' => false];
    }

    /**
     * @return list<string>
     */
    public function monitoredUrls(string $site, bool $refresh = false): array
    {
        if ($refresh) {
            $urls = $this->urlCollector->collect($site);
            $this->cache->set(ApiCache::identifier([$site, 'monitoredUrls']), $urls, 600);
            return $urls;
        }
        return $this->cache->remember([$site, 'monitoredUrls'], fn(): array => $this->urlCollector->collect($site));
    }

    /**
     * @return array{total: int, inspected: int, items: list<array<string, mixed>>, sitemapUrl: string}
     */
    public function pages(string $site): array
    {
        $urls = $this->monitoredUrls($site);
        $cached = $this->inspects->findMany($site, $urls);
        $items = array_map(static function (string $url) use ($cached): array {
            $c = $cached[$url] ?? null;
            return [
                'url' => $url,
                'indexStatus' => $c['index_status'] ?? null,
                'coverageState' => $c['coverage_state'] ?? null,
                'lastCrawl' => $c['last_crawl'] ?? null,
                'richVerdict' => $c['rich_results_verdict'] ?? null,
                'richTypes' => $c !== null ? (json_decode((string)($c['rich_results_types'] ?: '[]'), true) ?: []) : [],
                'inspectedAt' => $c !== null ? (int)$c['inspected_at'] : null,
            ];
        }, $urls);
        return [
            'total' => count($items),
            'inspected' => count(array_filter($items, static fn(array $i): bool => $i['inspectedAt'] !== null)),
            'items' => $items,
            'sitemapUrl' => $this->urlCollector->sitemapUrl($site),
        ];
    }
}
