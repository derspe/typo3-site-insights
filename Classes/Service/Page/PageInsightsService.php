<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Service\Page;

use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Service\ApiCache;
use Derspe\SiteInsights\Service\PagePeriod;
use Derspe\SiteInsights\Service\PreviousRange;
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
 * Search Console clicks, impressions and average position of the last 7, 28 or 90 days, 6 months or a year,
 * optionally compared with the period before. Queries and transitions are loaded by the page
 * details modal.
 *
 * Each source is loaded on its own: an unconfigured source is reported as such, a failing one
 * only returns its error.
 */
final readonly class PageInsightsService
{
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
     * @param string $period one of PagePeriod::KEYS
     * @return array{url: string, period: string, compare: bool, matomo: array<string, mixed>, gsc: array<string, mixed>}
     */
    public function insights(string $site, string $url, string $period = PagePeriod::DEFAULT, bool $compare = true): array
    {
        $period = PagePeriod::normalize($period);
        return [
            'url' => $url,
            'period' => $period,
            'compare' => $compare,
            'matomo' => $this->matomoConfigured($site) ? self::guarded(fn(): array => $this->matomoMetrics($site, $url, $period, $compare)) : ['configured' => false],
            'gsc' => $this->gscConfigured($site) ? self::guarded(fn(): array => $this->gscMetrics($site, $url, $period, $compare)) : ['configured' => false],
        ];
    }

    /**
     * Search Console period ending with the latest day with data, and the period before if
     * Search Console still keeps it (16 months).
     *
     * @return array{array{startDate: string, endDate: string}, array{startDate: string, endDate: string}|null}
     */
    public static function gscRanges(string $anchor, string $period): array
    {
        $range = PagePeriod::ending($anchor, $period);
        $previous = PreviousRange::of($range);
        return [$range, GscDashboardService::explicitRange($previous['startDate'], $previous['endDate'], new \DateTimeImmutable('today', new \DateTimeZone('UTC')))];
    }

    /**
     * Matomo period and the period before; full days only, otherwise the incomplete current day
     * pulls the comparison down.
     *
     * @return array{array{startDate: string, endDate: string}, array{startDate: string, endDate: string}}
     */
    public static function matomoRanges(string $period = PagePeriod::DEFAULT): array
    {
        $range = PagePeriod::ending((new \DateTimeImmutable('yesterday'))->format('Y-m-d'), $period);
        return [$range, PreviousRange::of($range)];
    }

    /**
     * @return array<string, mixed>
     */
    private function matomoMetrics(string $site, string $url, string $period, bool $compare): array
    {
        [$range, $compareRange] = self::matomoRanges($period);
        return $this->cache->remember([$site, 'page', 'matomo', 3, $url, $range, $compare], function () use ($site, $url, $range, $compareRange, $compare): array {
            $call = static fn(array $r): array => ['Actions.getPageUrl', [
                'pageUrl' => $url,
                'period' => 'range',
                'date' => $r['startDate'] . ',' . $r['endDate'],
            ]];
            $results = $this->matomo->bulk($site, $compare ? [$call($range), $call($compareRange)] : [$call($range)]);
            return MatomoNormalizer::pageMetrics($results[0]) + ['range' => $range] + ($compare ? [
                'compare' => MatomoNormalizer::pageMetrics($results[1] ?? []),
                'compareRange' => $compareRange,
            ] : []);
        }, self::MATOMO_LIFETIME);
    }

    /**
     * @return array<string, mixed>
     */
    private function gscMetrics(string $site, string $url, string $period, bool $compare): array
    {
        $anchor = $this->gsc->latestDataDate($site);
        [$range, $compareRange] = self::gscRanges($anchor, $period);
        $compare = $compare && $compareRange !== null;
        return $this->cache->remember([$site, 'page', 'gsc', 8, $url, $range, $compare], function () use ($site, $url, $anchor, $range, $compareRange, $compare): array {
            $body = static fn(array $dimensions, int $rowLimit, ?array $r = null): array => ($r ?? $range) + [
                'dimensions' => $dimensions,
                'type' => 'web',
                'dimensionFilterGroups' => [['filters' => [
                    ['dimension' => 'page', 'operator' => 'includingRegex', 'expression' => GscNormalizer::pageRegex($url)],
                ]]],
                'rowLimit' => $rowLimit,
            ];
            $r = $this->client->searchAnalyticsMany($site, ['kpis' => $body([], 1)] + ($compare ? ['compareKpis' => $body([], 1, $compareRange)] : []));
            return GscNormalizer::kpisFromRows($r['kpis']['rows'] ?? []) + [
                'range' => $range,
                'dataDate' => $anchor,
            ] + ($compare ? [
                'compare' => GscNormalizer::kpisFromRows($r['compareKpis']['rows'] ?? []),
                'compareRange' => $compareRange,
            ] : []);
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
