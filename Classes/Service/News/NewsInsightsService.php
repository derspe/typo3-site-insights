<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Service\News;

use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Service\ApiCache;
use Derspe\SiteInsights\Service\PagePeriod;
use Derspe\SiteInsights\Service\Gsc\GscDashboardService;
use Derspe\SiteInsights\Service\Gsc\GscNormalizer;
use Derspe\SiteInsights\Service\Gsc\SearchConsoleClient;
use Derspe\SiteInsights\Service\Matomo\MatomoClient;
use Derspe\SiteInsights\Service\Page\PageInsightsService;
use Derspe\SiteInsights\Settings\SiteSettings;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Key figures of all news records of a folder for the page and list module.
 *
 * The figures of all records come from one Search Console query (dimension "page") and one
 * Matomo report per period, both limited to the common path of the detail views, and are
 * joined with the records by path. The top queries of a single record are loaded on demand.
 */
final readonly class NewsInsightsService
{
    // Maximum rows of one Search Analytics request
    private const GSC_ROWS = 25000;
    private const MATOMO_ROWS = 10000;
    private const MATOMO_LIFETIME = 3600;
    // Search Console only adds data once a day
    private const GSC_LIFETIME = 21600;

    public function __construct(
        private SiteFinder $siteFinder,
        private SiteSettings $settings,
        private NewsRepository $news,
        private NewsUrlBuilder $urls,
        private PageInsightsService $pageInsights,
        private SearchConsoleClient $client,
        private GscDashboardService $gsc,
        private MatomoClient $matomo,
        private ApiCache $cache,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(string $site, int $folder, int $language, string $returnUrl, string $period = PagePeriod::DEFAULT, bool $compare = true): array
    {
        $period = PagePeriod::normalize($period);
        $siteObject = $this->folderSite($site, $folder);
        $detailPid = $this->detailPid($site);
        if ($detailPid === 0) {
            return ['detailPage' => false];
        }
        ['records' => $records, 'truncated' => $truncated] = $this->news->findInFolder($folder, $language);
        $urls = [];
        foreach ($records as $i => $record) {
            $records[$i]['url'] = $this->urls->detailUrl($siteObject, $detailPid, $record['newsUid'], $language);
            $records[$i]['editUrl'] = $this->urls->editUrl($record['uid'], $returnUrl);
            if ($records[$i]['url'] !== null) {
                $urls[] = $records[$i]['url'];
            }
        }
        $prefix = self::urlPrefix($urls);
        $matomo = ['configured' => false];
        $gsc = ['configured' => false];
        if ($this->pageInsights->matomoConfigured($site)) {
            $matomo = $prefix === null ? ['pages' => [], 'compare' => []] : self::guarded(fn(): array => $this->matomoPages($site, $prefix, $period, $compare));
        }
        if ($this->pageInsights->gscConfigured($site)) {
            $gsc = $prefix === null ? ['pages' => [], 'compare' => []] : self::guarded(fn(): array => $this->gscPages($site, $prefix, $period, $compare));
        }

        // Search Console may no longer keep the period before (e.g. the year before a year)
        $gscCompare = $compare && isset($gsc['compareRange']);
        $totals = ['matomo' => self::emptyMatomo(), 'matomoCompare' => self::emptyMatomo(), 'gsc' => self::emptyGsc(), 'gscCompare' => self::emptyGsc()];
        foreach ($records as $i => $record) {
            $key = $record['url'] === null ? null : self::pathKey($record['url']);
            unset($records[$i]['newsUid']);
            if (isset($matomo['pages'])) {
                $records[$i]['matomo'] = ($matomo['pages'][$key] ?? self::emptyMatomo()) + ['compare' => $compare ? $matomo['compare'][$key] ?? self::emptyMatomo() : null];
                $totals['matomo'] = self::sum($totals['matomo'], $records[$i]['matomo']);
                $totals['matomoCompare'] = self::sum($totals['matomoCompare'], $records[$i]['matomo']['compare'] ?? []);
            }
            if (isset($gsc['pages'])) {
                $records[$i]['gsc'] = ($gsc['pages'][$key] ?? self::emptyGsc()) + ['compare' => $gscCompare ? $gsc['compare'][$key] ?? self::emptyGsc() : null];
                $totals['gsc'] = self::sum($totals['gsc'], $records[$i]['gsc']);
                $totals['gscCompare'] = self::sum($totals['gscCompare'], $records[$i]['gsc']['compare'] ?? []);
            }
        }
        unset($matomo['pages'], $matomo['compare'], $gsc['pages'], $gsc['compare']);
        return [
            'detailPage' => true,
            'period' => $period,
            'compare' => $compare,
            'records' => $records,
            'truncated' => $truncated,
            'matomo' => $matomo,
            'gsc' => $gsc,
            'totals' => [
                'matomo' => $totals['matomo'] + ['compare' => $compare ? $totals['matomoCompare'] : null],
                'gsc' => self::withRatios($totals['gsc']) + ['compare' => $gscCompare ? self::withRatios($totals['gscCompare']) : null],
            ],
        ];
    }

    /**
     * Longest common prefix of the URLs up to the last slash, at least scheme and host.
     * null without URLs or if they belong to different hosts.
     *
     * @param list<string> $urls
     */
    public static function urlPrefix(array $urls): ?string
    {
        if ($urls === []) {
            return null;
        }
        $prefix = array_shift($urls);
        foreach ($urls as $url) {
            $length = min(strlen($prefix), strlen($url));
            $i = 0;
            while ($i < $length && $prefix[$i] === $url[$i]) {
                $i++;
            }
            $prefix = substr($prefix, 0, $i);
        }
        if (preg_match('#^https?://[^/]+/#i', $prefix) !== 1) {
            return null;
        }
        return substr($prefix, 0, (int)strrpos($prefix, '/') + 1);
    }

    /**
     * Join key of a URL or a Matomo label: decoded path without query and trailing slash.
     */
    public static function pathKey(string $urlOrPath): string
    {
        $path = str_contains($urlOrPath, '://') ? (string)(parse_url($urlOrPath, PHP_URL_PATH) ?? '') : explode('?', $urlOrPath, 2)[0];
        return '/' . trim(rawurldecode($path), '/');
    }

    /**
     * Search Analytics rows (dimension "page") summed up per path.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, array{clicks: int, impressions: int, ctr: float, position: float}>
     */
    public static function gscByPath(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[self::pathKey((string)($row['keys'][0] ?? ''))][] = $row;
        }
        return array_map(GscNormalizer::kpisFromRows(...), $grouped);
    }

    /**
     * Rows of Actions.getPageUrls (flat) summed up per path.
     *
     * @return array<string, array{pageviews: int, entries: int}>
     */
    public static function matomoByPath(mixed $data): array
    {
        if (!is_array($data) || !array_is_list($data)) {
            return [];
        }
        $pages = [];
        foreach ($data as $row) {
            $key = self::pathKey((string)($row['url'] ?? $row['label'] ?? ''));
            $pages[$key] = self::sum($pages[$key] ?? self::emptyMatomo(), [
                'pageviews' => (int)($row['nb_hits'] ?? 0),
                'entries' => (int)($row['entry_nb_visits'] ?? 0),
            ]);
        }
        return $pages;
    }

    /**
     * @return array<string, mixed>
     */
    private function gscPages(string $site, string $prefix, string $period, bool $compare): array
    {
        $anchor = $this->gsc->latestDataDate($site);
        // By path only: the property may cover several hosts (www, development domains)
        $path = (string)(parse_url($prefix, PHP_URL_PATH) ?? '/');
        [$range, $compareRange] = PageInsightsService::gscRanges($anchor, $period);
        // Without the period before in Search Console, its figures stay empty
        $fetchCompare = $compare && $compareRange !== null;
        return $this->cache->remember([$site, 'news', 'gsc', 3, $path, $range, $fetchCompare], function () use ($site, $path, $anchor, $range, $compareRange, $fetchCompare): array {
            $body = static fn(array $r): array => $r + [
                'dimensions' => ['page'],
                'type' => 'web',
                'dimensionFilterGroups' => [['filters' => [
                    ['dimension' => 'page', 'operator' => 'includingRegex', 'expression' => '^https?://[^/]+' . preg_quote($path)],
                ]]],
                'rowLimit' => self::GSC_ROWS,
            ];
            $r = $this->client->searchAnalyticsMany($site, ['main' => $body($range)] + ($fetchCompare ? ['compare' => $body($compareRange)] : []));
            return [
                'pages' => self::gscByPath($r['main']['rows'] ?? []),
                'compare' => self::gscByPath($r['compare']['rows'] ?? []),
                'range' => $range,
                'dataDate' => $anchor,
            ] + ($fetchCompare ? ['compareRange' => $compareRange] : []);
        }, self::GSC_LIFETIME);
    }

    /**
     * @return array<string, mixed>
     */
    private function matomoPages(string $site, string $prefix, string $period, bool $compare): array
    {
        [$range, $compareRange] = PageInsightsService::matomoRanges($period);
        return $this->cache->remember([$site, 'news', 'matomo', 2, $prefix, $range, $compare], function () use ($site, $prefix, $range, $compareRange, $compare): array {
            $path = trim((string)(parse_url($prefix, PHP_URL_PATH) ?? ''), '/');
            // Flat labels are paths; Matomo puts the pattern between delimiters itself
            $filter = $path === '' ? [] : ['filter_pattern' => '^/?' . preg_quote($path)];
            $call = static fn(array $r): array => ['Actions.getPageUrls', [
                'period' => 'range',
                'date' => $r['startDate'] . ',' . $r['endDate'],
                'flat' => 1,
                'filter_limit' => self::MATOMO_ROWS,
            ] + $filter];
            $results = $this->matomo->bulk($site, $compare ? [$call($range), $call($compareRange)] : [$call($range)]);
            return [
                'pages' => self::matomoByPath($results[0]),
                'compare' => self::matomoByPath($results[1] ?? []),
                'range' => $range,
            ] + ($compare ? ['compareRange' => $compareRange] : []);
        }, self::MATOMO_LIFETIME);
    }

    private function detailPid(string $site): int
    {
        $pid = $this->settings->getInt($site, 'news_detail_pid');
        if ($pid <= 0) {
            return 0;
        }
        $this->assertDetailPage($site, $pid);
        return $pid;
    }

    /**
     * The detail page must be a page of the site.
     */
    public function assertDetailPage(string $site, int $pid): void
    {
        try {
            $detailSite = $this->siteFinder->getSiteByPageId($pid);
        } catch (SiteNotFoundException) {
            throw new ApiException('The news detail page does not exist', 400);
        }
        if ($detailSite->getIdentifier() !== $site) {
            throw new ApiException('The news detail page does not belong to this site', 400);
        }
    }

    private function folderSite(string $site, int $folder): Site
    {
        try {
            $folderSite = $this->siteFinder->getSiteByPageId($folder);
        } catch (SiteNotFoundException) {
            throw new ApiException('Page not found', 404);
        }
        if ($folderSite->getIdentifier() !== $site) {
            throw new ApiException('Access to this site denied', 403);
        }
        return $folderSite;
    }

    /**
     * @return array{pageviews: int, entries: int}
     */
    private static function emptyMatomo(): array
    {
        return ['pageviews' => 0, 'entries' => 0];
    }

    /**
     * @return array{clicks: int, impressions: int, ctr: float, position: float}
     */
    private static function emptyGsc(): array
    {
        return ['clicks' => 0, 'impressions' => 0, 'ctr' => 0.0, 'position' => 0.0];
    }

    /**
     * Adds the integer figures; for the position the impression weighted sum.
     *
     * @param array<string, mixed> $total
     * @param array<string, mixed> $add
     * @return array<string, mixed>
     */
    private static function sum(array $total, array $add): array
    {
        foreach (['pageviews', 'entries', 'clicks', 'impressions'] as $key) {
            if (array_key_exists($key, $total)) {
                $total[$key] += (int)($add[$key] ?? 0);
            }
        }
        if (array_key_exists('position', $total)) {
            $total['weightedPosition'] = ($total['weightedPosition'] ?? 0.0) + (float)($add['position'] ?? 0) * (int)($add['impressions'] ?? 0);
        }
        return $total;
    }

    /**
     * @param array<string, mixed> $total
     * @return array{clicks: int, impressions: int, ctr: float, position: float}
     */
    private static function withRatios(array $total): array
    {
        $impressions = (int)$total['impressions'];
        return [
            'clicks' => (int)$total['clicks'],
            'impressions' => $impressions,
            'ctr' => $impressions > 0 ? $total['clicks'] / $impressions : 0.0,
            'position' => $impressions > 0 ? (float)($total['weightedPosition'] ?? 0.0) / $impressions : 0.0,
        ];
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
