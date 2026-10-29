<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Service\Gsc;

use Derspe\SiteInsights\Domain\Repository\SnapshotRepository;
use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Service\ApiCache;
use Derspe\SiteInsights\Service\Matomo\MatomoClient;
use Derspe\SiteInsights\Service\Matomo\MatomoNormalizer;
use Derspe\SiteInsights\Service\PreviousRange;
use Derspe\SiteInsights\Settings\SettingsValidator;
use Derspe\SiteInsights\Settings\SiteSettings;
use Derspe\SiteInsights\Settings\Sources;

/**
 * Live Search Console reports: performance dashboard, drilldown, cannibalization,
 * search appearance, tracker, visibility and the figures of a single page.
 *
 * All dates are UTC and anchored at the latest day with data (Search Console has ~2 days latency).
 */
final readonly class GscDashboardService
{
    private const RANGE_DAYS = ['last7' => 7, 'last28' => 28, 'last90' => 90, 'last365' => 365];
    // Search Console keeps 16 months of data
    private const MAX_MONTHS = 16;
    public const PAGE_QUERIES = 20;
    private const CANNIBALIZATION_MIN_IMPRESSIONS = 50;
    // Maximum rows of one Search Analytics request
    private const ALL_ROWS = 25000;
    public const TRACKER_DAYS = 28;

    public function __construct(
        private SearchConsoleClient $client,
        private SiteSettings $settings,
        private ApiCache $cache,
        private SnapshotRepository $snapshots,
        private MatomoClient $matomo,
        private Sources $sources,
    ) {}

    public function latestDataDate(string $site): string
    {
        return $this->cache->remember([$site, 'latestDataDate'], function () use ($site): string {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $start = $now->modify('-12 days');
            $result = $this->client->searchAnalytics($site, [
                'startDate' => $start->format('Y-m-d'),
                'endDate' => $now->format('Y-m-d'),
                'dimensions' => ['date'],
                'type' => 'web',
                'rowLimit' => 20,
            ]);
            $dates = array_map(static fn(array $r): string => (string)$r['keys'][0], $result['rows'] ?? []);
            sort($dates);
            return $dates !== [] ? end($dates) : $start->format('Y-m-d');
        });
    }

    /**
     * @return array{startDate: string, endDate: string}
     */
    public static function dateRange(string $anchor, string $period, string $date): array
    {
        $base = new \DateTimeImmutable($anchor . ' 00:00:00', new \DateTimeZone('UTC'));
        if ($period === 'day') {
            $day = $date === 'yesterday' ? $base->modify('-1 day') : $base;
            return ['startDate' => $day->format('Y-m-d'), 'endDate' => $day->format('Y-m-d')];
        }
        $days = self::RANGE_DAYS[$date] ?? 28;
        return [
            'startDate' => $base->modify('-' . ($days - 1) . ' days')->format('Y-m-d'),
            'endDate' => $base->format('Y-m-d'),
        ];
    }

    /**
     * @return array{startDate: string, endDate: string}|null
     */
    public static function compareRange(string $anchor, string $period, string $date, string $compare): ?array
    {
        if (!in_array($compare, ['previous', 'year'], true)) {
            return null;
        }
        $range = self::dateRange($anchor, $period, $date);
        $utc = new \DateTimeZone('UTC');
        $start = new \DateTimeImmutable($range['startDate'], $utc);
        $end = new \DateTimeImmutable($range['endDate'], $utc);
        if ($compare === 'year') {
            return ['startDate' => $start->modify('-1 year')->format('Y-m-d'), 'endDate' => $end->modify('-1 year')->format('Y-m-d')];
        }
        $days = (int)$start->diff($end)->days + 1;
        $compareEnd = $start->modify('-1 day');
        return ['startDate' => $compareEnd->modify('-' . ($days - 1) . ' days')->format('Y-m-d'), 'endDate' => $compareEnd->format('Y-m-d')];
    }

    /**
     * @return list<array{filters: list<array<string, string>>}>|null
     */
    private static function dimensionFilter(string $country, string $device): ?array
    {
        $filters = [];
        if ($country !== '') {
            $filters[] = ['dimension' => 'country', 'operator' => 'equals', 'expression' => strtolower($country)];
        }
        if ($device !== '') {
            $filters[] = ['dimension' => 'device', 'operator' => 'equals', 'expression' => strtoupper($device)];
        }
        return $filters === [] ? null : [['filters' => $filters]];
    }

    private function displayTopN(string $site): int
    {
        return max(1, $this->settings->getInt($site, 'gsc_top_n') ?: 50);
    }

    /**
     * @param array{period?: string, date?: string, searchType?: string, country?: string, device?: string, compare?: string} $q
     * @return array<string, mixed>
     */
    public function dashboard(string $site, array $q): array
    {
        [$period, $date, $searchType, $country, $device] = $this->normalizeQuery($q);
        $compare = (string)($q['compare'] ?? '');
        $filter = self::dimensionFilter($country, $device);
        // Discover and Google News know no queries; the query dimension fails there
        $supportsQuery = !in_array($searchType, ['discover', 'googleNews'], true);
        $topN = $this->displayTopN($site);
        $anchor = $this->latestDataDate($site);

        return $this->cache->remember([$site, 'dashboard', 2, $period, $date, $searchType, $country, $device, $compare, $topN, $anchor], function () use ($site, $period, $date, $searchType, $filter, $supportsQuery, $topN, $anchor, $compare): array {
            $main = self::dateRange($anchor, $period, $date);
            $compareRange = self::compareRange($anchor, $period, $date, $compare);
            $body = static fn(array $range, array $dimensions, int $rowLimit): array => [
                'startDate' => $range['startDate'],
                'endDate' => $range['endDate'],
                'dimensions' => $dimensions,
                'type' => $searchType,
                'dimensionFilterGroups' => $filter,
                'rowLimit' => $rowLimit,
            ];
            $bodies = [
                'series' => $body($main, ['date'], 1000),
                'kpis' => $body($main, [], 1),
                // All rows, sorted here: Search Console orders ties in clicks alphabetically
                'pages' => $body($main, ['page'], self::ALL_ROWS),
            ];
            if ($supportsQuery) {
                $bodies['allQueries'] = $body($main, ['query'], self::ALL_ROWS);
            }
            if ($compareRange !== null) {
                $bodies['cSeries'] = $body($compareRange, ['date'], 1000);
                $bodies['cKpis'] = $body($compareRange, [], 1);
                $bodies['cPages'] = $body($compareRange, ['page'], $topN);
                if ($supportsQuery) {
                    $bodies['cQueries'] = $body($compareRange, ['query'], $topN);
                }
            }
            $r = $this->client->searchAnalyticsMany($site, $bodies);

            $series = GscNormalizer::dateSeries($r['series']['rows'] ?? []);
            $topQueries = GscNormalizer::topRowsByClicks($r['allQueries']['rows'] ?? [], 'query', $topN);
            $topPages = GscNormalizer::topRowsByClicks($r['pages']['rows'] ?? [], 'page', $topN);
            $kpisCompare = null;
            if ($compareRange !== null) {
                $kpisCompare = GscNormalizer::kpisFromRows($r['cKpis']['rows'] ?? []);
                $series = GscNormalizer::mergeCompareSeries($series, GscNormalizer::dateSeries($r['cSeries']['rows'] ?? []));
                $topQueries = GscNormalizer::addRowsDelta($topQueries, GscNormalizer::topRows($r['cQueries']['rows'] ?? [], 'query'), 'query', 'clicks');
                $topPages = GscNormalizer::addRowsDelta($topPages, GscNormalizer::topRows($r['cPages']['rows'] ?? [], 'page'), 'page', 'clicks');
            }

            // Matomo pageviews for exactly the same window (optional)
            if ($this->sources->matomo() && $this->matomo->isConfigured($site)) {
                try {
                    $params = $period === 'day'
                        ? ['period' => 'day', 'date' => $main['startDate']]
                        : ['period' => 'range', 'date' => $main['startDate'] . ',' . $main['endDate']];
                    $matomoPages = MatomoNormalizer::topPages($this->matomo->call($site, 'Actions.getPageUrls', $params + ['flat' => 1, 'filter_limit' => 1000]));
                    $topPages = GscNormalizer::joinMatomoVisits($topPages, $matomoPages);
                } catch (ApiException) {
                    // Join is optional; ignore Matomo failures here
                }
            }

            $brandRegex = GscNormalizer::brandRegex($this->settings->getList($site, 'gsc_brand_terms'));

            return [
                'kpis' => GscNormalizer::kpisFromRows($r['kpis']['rows'] ?? []),
                'kpisCompare' => $kpisCompare,
                'compare' => $compareRange !== null ? $compare : null,
                'range' => $main,
                'compareRange' => $compareRange,
                'series' => $series,
                'topQueries' => $topQueries,
                'topPages' => $topPages,
                'brandSplit' => GscNormalizer::splitBrand($r['allQueries']['rows'] ?? [], $brandRegex),
                'brandConfigured' => $brandRegex !== null,
                'dataDate' => $anchor,
            ];
        });
    }

    /**
     * Figures and top queries of one page ("url", "start" and "end" as YYYY-MM-DD, "searchType").
     *
     * @param array<string, mixed> $q
     * @return array<string, mixed>
     */
    public function page(string $site, array $q): array
    {
        $url = trim((string)($q['url'] ?? ''));
        if (!SettingsValidator::isPageUrlOrPath($url)) {
            throw new ApiException('Parameter "url" must be a URL or a path', 400);
        }
        $range = self::explicitRange((string)($q['start'] ?? ''), (string)($q['end'] ?? ''), new \DateTimeImmutable('today', new \DateTimeZone('UTC')));
        if ($range === null) {
            throw new ApiException(sprintf('Invalid date range: dates must lie within the last %d months', self::MAX_MONTHS), 400);
        }
        [, , $searchType] = $this->normalizeQuery($q);
        $supportsQuery = !in_array($searchType, ['discover', 'googleNews'], true);
        $regex = GscNormalizer::pageRegex($url);
        // "compare=1": figures of the period of the same length before, if Search Console still has it
        $compareRange = null;
        if ((string)($q['compare'] ?? '') === '1') {
            $previous = PreviousRange::of($range);
            $compareRange = self::explicitRange($previous['startDate'], $previous['endDate'], new \DateTimeImmutable('today', new \DateTimeZone('UTC')));
        }
        return $this->cache->remember([$site, 'page', 2, $regex, $range, $searchType, $compareRange], function () use ($site, $range, $searchType, $supportsQuery, $regex, $compareRange): array {
            $body = static fn(array $dimensions, int $rowLimit, ?array $r = null): array => ($r ?? $range) + [
                'dimensions' => $dimensions,
                'type' => $searchType,
                'dimensionFilterGroups' => [['filters' => [['dimension' => 'page', 'operator' => 'includingRegex', 'expression' => $regex]]]],
                'rowLimit' => $rowLimit,
            ];
            $bodies = ['kpis' => $body([], 1)];
            if ($supportsQuery) {
                $bodies['allQueries'] = $body(['query'], self::ALL_ROWS);
            }
            if ($compareRange !== null) {
                $bodies['compareKpis'] = $body([], 1, $compareRange);
            }
            $r = $this->client->searchAnalyticsMany($site, $bodies);
            $kpis = GscNormalizer::kpisFromRows($r['kpis']['rows'] ?? []);
            $queries = $r['allQueries']['rows'] ?? [];
            return $kpis + [
                'queries' => GscNormalizer::topRowsByClicks($queries, 'query', self::PAGE_QUERIES),
                'anonymized' => $supportsQuery ? GscNormalizer::anonymizedShare($kpis, $queries) : null,
                'supportsQuery' => $supportsQuery,
                'range' => $range,
            ] + ($compareRange !== null ? [
                'compare' => GscNormalizer::kpisFromRows($r['compareKpis']['rows'] ?? []),
                'compareRange' => $compareRange,
            ] : []);
        });
    }

    /**
     * Validates an explicit range "YYYY-MM-DD" – "YYYY-MM-DD" inside the data Search Console keeps.
     *
     * @return array{startDate: string, endDate: string}|null
     */
    public static function explicitRange(string $start, string $end, \DateTimeImmutable $today): ?array
    {
        $days = [];
        foreach ([$start, $end] as $value) {
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $today->getTimezone());
            if ($day === false || $day->format('Y-m-d') !== $value) {
                return null;
            }
            $days[] = $day;
        }
        // One day of tolerance: the backend may already be a day ahead of UTC
        if ($days[0] > $days[1] || $days[1] > $today->modify('+1 day') || $days[0] < $today->modify('-' . self::MAX_MONTHS . ' months')) {
            return null;
        }
        return ['startDate' => $start, 'endDate' => $end];
    }

    /**
     * @param array<string, string> $q
     * @return array{rows: list<array<string, mixed>>}
     */
    public function drilldown(string $site, array $q): array
    {
        [$period, $date, $searchType, $country, $device] = $this->normalizeQuery($q);
        $anchor = $this->latestDataDate($site);
        return $this->cache->remember([$site, 'drilldown', $period, $date, $searchType, $country, $device, $anchor], function () use ($site, $anchor, $period, $date, $searchType, $country, $device): array {
            $main = self::dateRange($anchor, $period, $date);
            $result = $this->client->searchAnalytics($site, [
                'startDate' => $main['startDate'],
                'endDate' => $main['endDate'],
                'dimensions' => ['query', 'page'],
                'type' => $searchType,
                'dimensionFilterGroups' => self::dimensionFilter($country, $device),
                'rowLimit' => 1000,
            ]);
            return ['rows' => array_map(static fn(array $r): array => [
                'query' => (string)$r['keys'][0],
                'page' => (string)$r['keys'][1],
                'clicks' => (int)($r['clicks'] ?? 0),
                'impressions' => (int)($r['impressions'] ?? 0),
                'ctr' => (float)($r['ctr'] ?? 0),
                'position' => (float)($r['position'] ?? 0),
            ], $result['rows'] ?? [])];
        });
    }

    /**
     * Queries for which two or more pages rank (each with a minimum of impressions).
     *
     * @param array<string, string> $q
     * @return array{items: list<array<string, mixed>>, minImpressions: int}
     */
    public function cannibalization(string $site, array $q): array
    {
        [$period, $date, $searchType] = $this->normalizeQuery($q);
        $topN = $this->displayTopN($site);
        $anchor = $this->latestDataDate($site);
        $items = $this->cache->remember([$site, 'cannibalization', $period, $date, $searchType, $topN, $anchor], function () use ($site, $anchor, $period, $date, $searchType, $topN): array {
            $main = self::dateRange($anchor, $period, $date);
            $result = $this->client->searchAnalytics($site, [
                'startDate' => $main['startDate'],
                'endDate' => $main['endDate'],
                'dimensions' => ['query', 'page'],
                'type' => $searchType,
                'rowLimit' => 25000,
            ]);
            $byQuery = [];
            foreach ($result['rows'] ?? [] as $row) {
                $byQuery[(string)$row['keys'][0]][] = [
                    'page' => (string)$row['keys'][1],
                    'clicks' => (int)($row['clicks'] ?? 0),
                    'impressions' => (int)($row['impressions'] ?? 0),
                    'position' => (float)($row['position'] ?? 0),
                ];
            }
            $items = [];
            foreach ($byQuery as $query => $pages) {
                $pages = array_values(array_filter($pages, static fn(array $p): bool => $p['impressions'] >= self::CANNIBALIZATION_MIN_IMPRESSIONS));
                if (count($pages) >= 2) {
                    usort($pages, static fn(array $a, array $b): int => $b['clicks'] <=> $a['clicks']);
                    $items[] = ['query' => (string)$query, 'pages' => $pages, 'totalClicks' => array_sum(array_column($pages, 'clicks'))];
                }
            }
            usort($items, static fn(array $a, array $b): int => $b['totalClicks'] <=> $a['totalClicks']);
            return array_slice($items, 0, $topN);
        });
        return ['items' => $items, 'minImpressions' => self::CANNIBALIZATION_MIN_IMPRESSIONS];
    }

    /**
     * Clicks/impressions per search appearance (rich result type); web search only.
     *
     * @param array<string, string> $q
     * @return array{items: list<array<string, mixed>>}
     */
    public function appearance(string $site, array $q): array
    {
        [$period, $date, $searchType, $country, $device] = $this->normalizeQuery($q);
        if ($searchType !== 'web') {
            return ['items' => []];
        }
        $anchor = $this->latestDataDate($site);
        $items = $this->cache->remember([$site, 'appearance', $period, $date, $country, $device, $anchor], function () use ($site, $anchor, $period, $date, $country, $device): array {
            $main = self::dateRange($anchor, $period, $date);
            $result = $this->client->searchAnalytics($site, [
                'startDate' => $main['startDate'],
                'endDate' => $main['endDate'],
                'dimensions' => ['searchAppearance'],
                'type' => 'web',
                'dimensionFilterGroups' => self::dimensionFilter($country, $device),
                'rowLimit' => 25,
            ]);
            return array_map(static fn(array $r): array => [
                'type' => (string)$r['keys'][0],
                'clicks' => (int)($r['clicks'] ?? 0),
                'impressions' => (int)($r['impressions'] ?? 0),
                'ctr' => (float)($r['ctr'] ?? 0),
                'position' => (float)($r['position'] ?? 0),
            ], $result['rows'] ?? []);
        });
        return ['items' => $items];
    }

    /**
     * @param array<string, string> $q
     * @return array{series: list<array<string, mixed>>}
     */
    public function visibility(string $site, array $q): array
    {
        $period = ($q['period'] ?? 'range') === 'day' ? 'day' : 'range';
        $date = (string)($q['date'] ?? 'last90');
        try {
            $range = self::dateRange($this->latestDataDate($site), $period, $date);
            return ['series' => $this->snapshots->visibilitySeries($site, $range['startDate'], $range['endDate'])];
        } catch (ApiException) {
            // Not connected: deliver the full stored series
            return ['series' => $this->snapshots->visibilitySeries($site)];
        }
    }

    /**
     * @param 'query'|'page' $dimension
     * @return array<string, mixed>
     */
    public function trackerList(string $site, string $dimension, string $order): array
    {
        $range = $this->trackerRange($site);
        if ($range === null) {
            return ['from' => null, 'to' => null, 'days' => self::TRACKER_DAYS, 'items' => []];
        }
        return $range + [
            'days' => self::TRACKER_DAYS,
            'items' => $this->snapshots->topForRange($site, $dimension, $range['from'], $range['to'], $this->displayTopN($site), $order),
        ];
    }

    /**
     * @param 'query'|'page' $dimension
     * @return array<string, mixed>
     */
    public function trackerSeries(string $site, string $dimension, string $key): array
    {
        if ($key === '') {
            throw new ApiException('Parameter "key" missing', 400);
        }
        $series = array_map(static fn(array $s): array => $s + [
            'visibility' => round($s['impressions'] * GscNormalizer::ctrWeight($s['position']), 2),
        ], $this->snapshots->series($site, $dimension, $key));
        $currentRank = null;
        $range = $this->trackerRange($site);
        if ($range !== null) {
            $top = $this->snapshots->topForRange($site, $dimension, $range['from'], $range['to'], $this->displayTopN($site));
            foreach ($top as $i => $row) {
                if ($row[$dimension] === $key) {
                    $currentRank = $i + 1;
                    break;
                }
            }
        }
        return ['key' => $key, 'series' => $series, 'currentRank' => $currentRank];
    }

    /**
     * @return array{from: string, to: string}|null
     */
    private function trackerRange(string $site): ?array
    {
        $to = $this->snapshots->latestQuerySnapshotDate($site);
        if ($to === null) {
            return null;
        }
        $from = (new \DateTimeImmutable($to, new \DateTimeZone('UTC')))->modify('-' . (self::TRACKER_DAYS - 1) . ' days');
        return ['from' => $from->format('Y-m-d'), 'to' => $to];
    }

    /**
     * ISO 3166-1 alpha-3 country code in lower case, otherwise '' (= all countries).
     * Strict, because every distinct value is a separate cache entry and API call.
     */
    public static function country(mixed $value): string
    {
        $value = strtolower(trim(is_scalar($value) ? (string)$value : ''));
        return preg_match('/^[a-z]{3}$/', $value) === 1 ? $value : '';
    }

    public static function device(mixed $value): string
    {
        $value = strtoupper(trim(is_scalar($value) ? (string)$value : ''));
        return in_array($value, ['DESKTOP', 'MOBILE', 'TABLET'], true) ? $value : '';
    }

    /**
     * @param array<string, mixed> $q
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
     */
    private function normalizeQuery(array $q): array
    {
        $period = ($q['period'] ?? 'range') === 'day' ? 'day' : 'range';
        $date = (string)($q['date'] ?? 'last28');
        if ($period === 'day' && !in_array($date, ['today', 'yesterday'], true)) {
            $date = 'today';
        }
        if ($period === 'range' && !isset(self::RANGE_DAYS[$date])) {
            $date = 'last28';
        }
        $searchType = (string)($q['searchType'] ?? 'web');
        if (!in_array($searchType, ['web', 'image', 'video', 'news', 'discover', 'googleNews'], true)) {
            $searchType = 'web';
        }
        return [$period, $date, $searchType, self::country($q['country'] ?? ''), self::device($q['device'] ?? '')];
    }
}
