<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Service\Page;

use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Service\ApiCache;
use Derspe\SiteInsights\Service\Gsc\GoogleServiceAccount;
use Derspe\SiteInsights\Service\Gsc\GscDashboardService;
use Derspe\SiteInsights\Service\Gsc\GscNormalizer;
use Derspe\SiteInsights\Service\Gsc\SearchConsoleClient;
use Derspe\SiteInsights\Service\Matomo\MatomoClient;
use Derspe\SiteInsights\Service\Matomo\MatomoNormalizer;
use Derspe\SiteInsights\Settings\SiteSettings;
use Derspe\SiteInsights\Settings\Sources;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Key figures of a single page for the page module: Matomo pageviews and entries,
 * Search Console clicks, impressions, top queries and the share of anonymized queries
 * of the last 28 days, each compared with the 28 days before.
 *
 * Each source is loaded on its own: an unconfigured source is reported as such, a failing one
 * only returns its error.
 */
final readonly class PageInsightsService
{
    public const DAYS = 28;
    public const TOP_QUERIES = 10;
    // Maximum rows of one Search Analytics request
    private const ALL_QUERIES = 25000;
    private const MATOMO_LIFETIME = 3600;
    // Search Console only adds data once a day
    private const GSC_LIFETIME = 21600;

    public function __construct(
        private SiteFinder $siteFinder,
        private MatomoClient $matomo,
        private SearchConsoleClient $client,
        private GscDashboardService $gsc,
        private ApiCache $cache,
        private GoogleServiceAccount $serviceAccount,
        private SiteSettings $settings,
        private Sources $sources,
    ) {}

    /**
     * Enabled in the extension configuration and set up for the site.
     */
    public function matomoConfigured(string $site): bool
    {
        return $this->sources->matomo() && $this->matomo->isConfigured($site);
    }

    /**
     * Enabled in the extension configuration and set up for the site.
     */
    public function gscConfigured(string $site): bool
    {
        return $this->sources->gsc() && $this->settings->isSet($site, 'gsc_site_url') && $this->serviceAccount->isConfigured($site);
    }

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
            'matomo' => $this->matomoConfigured($site) ? self::guarded(fn(): array => $this->matomoMetrics($site, $url)) : ['configured' => false],
            'gsc' => $this->gscConfigured($site) ? self::guarded(fn(): array => $this->gscMetrics($site, $url)) : ['configured' => false],
        ];
    }

    /**
     * Matomo period and the period before; full days only, otherwise the incomplete current day
     * pulls the comparison down.
     *
     * @return array{array{startDate: string, endDate: string}, array{startDate: string, endDate: string}}
     */
    public static function matomoRanges(): array
    {
        $end = new \DateTimeImmutable('yesterday');
        $start = $end->modify('-' . (self::DAYS - 1) . ' days');
        return [
            ['startDate' => $start->format('Y-m-d'), 'endDate' => $end->format('Y-m-d')],
            [
                'startDate' => $start->modify('-' . self::DAYS . ' days')->format('Y-m-d'),
                'endDate' => $start->modify('-1 day')->format('Y-m-d'),
            ],
        ];
    }

    /**
     * Search Console figures and top queries of one URL.
     *
     * @return array<string, mixed>
     */
    public function gscPage(string $site, string $url): array
    {
        return $this->gscMetrics($site, $url);
    }

    /**
     * @return array<string, mixed>
     */
    private function matomoMetrics(string $site, string $url): array
    {
        [$range, $compareRange] = self::matomoRanges();
        return $this->cache->remember([$site, 'page', 'matomo', 2, $url, $range], function () use ($site, $url, $range, $compareRange): array {
            $call = static fn(array $r): array => ['Actions.getPageUrl', [
                'pageUrl' => $url,
                'period' => 'range',
                'date' => $r['startDate'] . ',' . $r['endDate'],
            ]];
            [$main, $compare] = $this->matomo->bulk($site, [$call($range), $call($compareRange)]);
            return MatomoNormalizer::pageMetrics($main) + [
                'compare' => MatomoNormalizer::pageMetrics($compare),
                'range' => $range,
                'compareRange' => $compareRange,
            ];
        }, self::MATOMO_LIFETIME);
    }

    /**
     * @return array<string, mixed>
     */
    private function gscMetrics(string $site, string $url): array
    {
        $anchor = $this->gsc->latestDataDate($site);
        return $this->cache->remember([$site, 'page', 'gsc', 5, $url, $anchor], function () use ($site, $url, $anchor): array {
            $range = GscDashboardService::dateRange($anchor, 'range', 'last' . self::DAYS);
            $compareRange = GscDashboardService::compareRange($anchor, 'range', 'last' . self::DAYS, 'previous');
            $body = static fn(array $dimensions, int $rowLimit, ?array $r = null): array => ($r ?? $range) + [
                'dimensions' => $dimensions,
                'type' => 'web',
                'dimensionFilterGroups' => [['filters' => [
                    ['dimension' => 'page', 'operator' => 'includingRegex', 'expression' => GscNormalizer::pageRegex($url)],
                ]]],
                'rowLimit' => $rowLimit,
            ];
            $r = $this->client->searchAnalyticsMany($site, [
                'kpis' => $body([], 1),
                'allQueries' => $body(['query'], self::ALL_QUERIES),
                'compareKpis' => $body([], 1, $compareRange),
            ]);
            $kpis = GscNormalizer::kpisFromRows($r['kpis']['rows'] ?? []);
            return $kpis + [
                'queries' => GscNormalizer::topRowsByClicks($r['allQueries']['rows'] ?? [], 'query', self::TOP_QUERIES),
                'anonymized' => GscNormalizer::anonymizedShare($kpis, $r['allQueries']['rows'] ?? []),
                'compare' => GscNormalizer::kpisFromRows($r['compareKpis']['rows'] ?? []),
                'range' => $range,
                'compareRange' => $compareRange,
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
