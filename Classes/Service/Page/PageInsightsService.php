<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Service\Page;

use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Service\ApiCache;
use Derspe\SiteInsights\Service\Gsc\GscDashboardService;
use Derspe\SiteInsights\Service\Gsc\GscNormalizer;
use Derspe\SiteInsights\Service\Gsc\SearchConsoleClient;
use Derspe\SiteInsights\Service\Matomo\MatomoClient;
use Derspe\SiteInsights\Service\Matomo\MatomoNormalizer;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Key figures of a single page for the page module: Matomo pageviews and entries,
 * Search Console clicks, impressions and top queries of the last 28 days.
 *
 * Each source is loaded on its own; a failing or unconfigured source only returns its error.
 */
final readonly class PageInsightsService
{
    public const DAYS = 28;
    public const TOP_QUERIES = 10;
    private const MATOMO_LIFETIME = 3600;
    // Search Console only adds data once a day
    private const GSC_LIFETIME = 21600;

    public function __construct(
        private SiteFinder $siteFinder,
        private MatomoClient $matomo,
        private SearchConsoleClient $client,
        private GscDashboardService $gsc,
        private ApiCache $cache,
    ) {}

    /**
     * Absolute frontend URL of the page; the page must belong to the given site.
     */
    public function pageUrl(string $site, int $pageId, int $languageId): string
    {
        try {
            $pageSite = $this->siteFinder->getSiteByPageId($pageId);
        } catch (SiteNotFoundException) {
            throw new ApiException('Page not found', 404);
        }
        if ($pageSite->getIdentifier() !== $site) {
            throw new ApiException('Access to this site denied', 403);
        }
        try {
            return (string)$pageSite->getRouter()->generateUri($pageId, ['_language' => $languageId]);
        } catch (\Throwable) {
            throw new ApiException('No URL for this page', 404);
        }
    }

    /**
     * @return array{url: string, matomo: array<string, mixed>, gsc: array<string, mixed>}
     */
    public function insights(string $site, string $url): array
    {
        return [
            'url' => $url,
            'matomo' => self::guarded(fn(): array => $this->matomoMetrics($site, $url)),
            'gsc' => self::guarded(fn(): array => $this->gscMetrics($site, $url)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function matomoMetrics(string $site, string $url): array
    {
        if (!$this->matomo->isConfigured($site)) {
            throw new ApiException('Matomo is not configured', 503);
        }
        $end = new \DateTimeImmutable('today');
        $range = [
            'startDate' => $end->modify('-' . (self::DAYS - 1) . ' days')->format('Y-m-d'),
            'endDate' => $end->format('Y-m-d'),
        ];
        return $this->cache->remember([$site, 'page', 'matomo', $url, $range], function () use ($site, $url, $range): array {
            $data = $this->matomo->call($site, 'Actions.getPageUrl', [
                'pageUrl' => $url,
                'period' => 'range',
                'date' => $range['startDate'] . ',' . $range['endDate'],
            ]);
            return MatomoNormalizer::pageMetrics($data) + ['range' => $range];
        }, self::MATOMO_LIFETIME);
    }

    /**
     * @return array<string, mixed>
     */
    private function gscMetrics(string $site, string $url): array
    {
        $this->client->propertyUrl($site);
        $anchor = $this->gsc->latestDataDate($site);
        return $this->cache->remember([$site, 'page', 'gsc', $url, $anchor], function () use ($site, $url, $anchor): array {
            $range = GscDashboardService::dateRange($anchor, 'range', 'last' . self::DAYS);
            $body = static fn(array $dimensions, int $rowLimit): array => $range + [
                'dimensions' => $dimensions,
                'type' => 'web',
                'dimensionFilterGroups' => [['filters' => [
                    ['dimension' => 'page', 'operator' => 'includingRegex', 'expression' => GscNormalizer::pageRegex($url)],
                ]]],
                'rowLimit' => $rowLimit,
            ];
            $r = $this->client->searchAnalyticsMany($site, [
                'kpis' => $body([], 1),
                'queries' => $body(['query'], self::TOP_QUERIES),
            ]);
            return GscNormalizer::kpisFromRows($r['kpis']['rows'] ?? []) + [
                'queries' => GscNormalizer::topRows($r['queries']['rows'] ?? [], 'query'),
                'range' => $range,
                'dataDate' => $anchor,
            ];
        }, self::GSC_LIFETIME);
    }

    /**
     * @param callable(): array<string, mixed> $producer
     * @return array<string, mixed>
     */
    private static function guarded(callable $producer): array
    {
        try {
            return $producer();
        } catch (ApiException $e) {
            return ['error' => $e->getMessage(), 'status' => $e->status];
        }
    }
}
