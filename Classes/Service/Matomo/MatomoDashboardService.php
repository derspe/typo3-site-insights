<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Service\Matomo;

use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Service\ApiCache;
use Derspe\SiteInsights\Service\PreviousRange;
use Derspe\SiteInsights\Settings\SettingsValidator;
use Derspe\SiteInsights\Settings\SiteSettings;

/**
 * Matomo endpoints of the backend module: segments, goals, the dashboard and page transitions.
 */
final readonly class MatomoDashboardService
{
    private const RANGE_DAYS = ['last7' => 7, 'last30' => 30, 'last90' => 90, 'last365' => 365];
    // Custom ranges reach back one year at most: longer ranges (plus comparison) make Matomo archiving slow
    public const CUSTOM_MAX_MONTHS = 12;

    public function __construct(
        private MatomoClient $client,
        private SiteSettings $settings,
        private ApiCache $cache,
        private TransitionsService $transitions,
        private PageMatomoService $pages,
    ) {}

    /**
     * @return list<array{id: int|string, name: string, definition: string}>
     */
    public function segments(string $site): array
    {
        try {
            $data = $this->cache->remember([$site, 'matomo', 'segments'], fn(): mixed => $this->client->call($site, 'SegmentEditor.getAll'));
        } catch (ApiException $e) {
            if ($e->status === 503) {
                throw $e;
            }
            return [];
        }
        return array_values(array_map(static fn(array $s): array => [
            'id' => $s['idsegment'] ?? '',
            'name' => (string)($s['name'] ?? ''),
            'definition' => (string)($s['definition'] ?? ''),
        ], is_array($data) && array_is_list($data) ? $data : []));
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public function goals(string $site): array
    {
        try {
            $data = $this->client->call($site, 'Goals.getGoals');
        } catch (ApiException $e) {
            if ($e->status === 503) {
                throw $e;
            }
            return [];
        }
        return array_values(array_map(static fn(array $g): array => [
            'id' => (string)($g['idgoal'] ?? ''),
            'name' => (string)($g['name'] ?? ''),
        ], is_array($data) ? array_values(array_filter($data, 'is_array')) : []));
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(string $site, string $period, string $date, string $segment, string $compare): array
    {
        $period = $period === 'day' ? 'day' : 'range';
        $date = $period === 'day'
            ? (in_array($date, ['today', 'yesterday'], true) ? $date : 'today')
            : (isset(self::RANGE_DAYS[$date]) ? $date : $this->customDate($date));
        $compare = in_array($compare, ['previous', 'year'], true) ? $compare : '';
        // Only segments defined in Matomo: free-form segments could trigger expensive archiving
        if ($segment !== '' && !in_array($segment, array_column($this->segments($site), 'definition'), true)) {
            throw new ApiException('Unknown segment', 400);
        }
        $goalIds = $this->goalIds($site);
        return $this->cache->remember(
            [$site, 'matomo', 'dashboard', $period, $date, $segment, $compare, $goalIds, date('Y-m-d')],
            fn(): array => $this->buildDashboard($site, $period, $date, $segment, $compare, $goalIds)
        );
    }

    /**
     * Transitions of one page of the dashboard ("start" and "end" as YYYY-MM-DD).
     *
     * @param array<string, mixed> $q
     * @return array<string, mixed>
     */
    public function transitions(string $site, array $q): array
    {
        [$url, $range, $segment, $compareRange] = $this->pageQuery($site, $q);
        return $this->transitions->forUrl($site, $url, $range['startDate'], $range['endDate'], $segment, $compareRange);
    }

    /**
     * Pageviews of one page per day (per week for more than 90 days), parameters as for transitions.
     *
     * @param array<string, mixed> $q
     * @return array<string, mixed>
     */
    public function pageSeries(string $site, array $q): array
    {
        [$url, $range, $segment, $compareRange] = $this->pageQuery($site, $q);
        return $this->transitions->series($site, $url, $range, $segment, $compareRange);
    }

    /**
     * Device types, browsers and operating systems of one page, parameters as for transitions.
     *
     * @param array<string, mixed> $q
     * @return array<string, mixed>
     */
    public function pageDevices(string $site, array $q): array
    {
        [$url, $range, $segment, $compareRange] = $this->pageQuery($site, $q);
        return $this->pages->devices($site, $url, $range, $segment, $compareRange);
    }

    /**
     * Events of one page by category, parameters as for transitions.
     *
     * @param array<string, mixed> $q
     * @return array<string, mixed>
     */
    public function pageEvents(string $site, array $q): array
    {
        [$url, $range, $segment, $compareRange] = $this->pageQuery($site, $q);
        return $this->pages->events($site, $url, $range, $segment, $compareRange);
    }

    /**
     * Event names of one category on one page; "category" is required, a compare range is ignored.
     *
     * @param array<string, mixed> $q
     * @return array<string, mixed>
     */
    public function pageEventNames(string $site, array $q): array
    {
        [$url, $range, $segment] = $this->pageQuery($site, $q);
        $category = $q['category'] ?? null;
        if (!is_string($category) || $category === '' || mb_strlen($category) > 255) {
            throw new ApiException('Parameter "category" must be a non-empty string of at most 255 characters', 400);
        }
        return $this->pages->eventNames($site, $url, $range, $segment, $category);
    }

    /**
     * Goal conversions of one page, parameters as for transitions.
     *
     * @param array<string, mixed> $q
     * @return array<string, mixed>
     */
    public function pageGoals(string $site, array $q): array
    {
        [$url, $range, $segment, $compareRange] = $this->pageQuery($site, $q);
        return $this->pages->goals($site, $url, $range, $segment, $compareRange);
    }

    /**
     * Validated URL, range, segment and compare range ("compare=1": the period of the same length before).
     *
     * @param array<string, mixed> $q
     * @return array{string, array{startDate: string, endDate: string}, string, array{startDate: string, endDate: string}|null}
     */
    private function pageQuery(string $site, array $q): array
    {
        $url = trim((string)($q['url'] ?? ''));
        if (!SettingsValidator::isPageUrlOrPath($url)) {
            throw new ApiException('Parameter "url" must be a URL or a path', 400);
        }
        // Search Console ranges may start a few days before the window of custom ranges
        $start = (string)($q['start'] ?? '');
        $earliest = $this->today()->modify('-' . self::CUSTOM_MAX_MONTHS . ' months')->format('Y-m-d');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) === 1 && $start < $earliest && $start >= (new \DateTimeImmutable($earliest))->modify('-14 days')->format('Y-m-d')) {
            $start = $earliest;
        }
        $bounds = self::customRange($start . ',' . (string)($q['end'] ?? ''), $this->today());
        if ($bounds === null) {
            throw new ApiException(sprintf('Invalid date range: dates must lie within the last %d months', self::CUSTOM_MAX_MONTHS), 400);
        }
        $segment = (string)($q['segment'] ?? '');
        if ($segment !== '' && !in_array($segment, array_column($this->segments($site), 'definition'), true)) {
            throw new ApiException('Unknown segment', 400);
        }
        $range = ['startDate' => $bounds[0]->format('Y-m-d'), 'endDate' => $bounds[1]->format('Y-m-d')];
        return [$url, $range, $segment, (string)($q['compare'] ?? '') === '1' ? PreviousRange::of($range) : null];
    }

    /**
     * @param list<string> $goalIds
     * @return array<string, mixed>
     */
    private function buildDashboard(string $site, string $period, string $date, string $segment, string $compare, array $goalIds): array
    {
        $segmentParam = $segment !== '' ? ['segment' => $segment] : [];
        $explicitDate = $this->explicitDate($period, $date);
        $compareRange = $compare !== '' ? $this->compareRange($period, $date, $compare) : null;

        $calls = [
            ['VisitsSummary.get', ['period' => $period, 'date' => $explicitDate] + $segmentParam],
            ['Referrers.getAll', ['period' => $period, 'date' => $explicitDate] + $segmentParam],
            ['Actions.getPageUrls', ['period' => $period, 'date' => $explicitDate, 'filter_limit' => 100, 'flat' => 1] + $segmentParam],
            $period === 'day'
                ? ['VisitTime.getVisitInformationPerLocalTime', ['period' => 'day', 'date' => $this->dayDate($date)] + $segmentParam]
                : ['VisitsSummary.get', ['period' => 'day', 'date' => $explicitDate, 'filter_limit' => -1] + $segmentParam],
        ];
        if ($compareRange !== null) {
            [$cPeriod, $cDate] = $compareRange;
            $calls[] = ['VisitsSummary.get', ['period' => $cPeriod, 'date' => $cDate] + $segmentParam];
            $calls[] = ['Referrers.getAll', ['period' => $cPeriod, 'date' => $cDate] + $segmentParam];
            $calls[] = ['Actions.getPageUrls', ['period' => $cPeriod, 'date' => $cDate, 'filter_limit' => 100, 'flat' => 1] + $segmentParam];
            $calls[] = $period === 'day'
                ? ['VisitTime.getVisitInformationPerLocalTime', ['period' => 'day', 'date' => $cDate] + $segmentParam]
                : ['VisitsSummary.get', ['period' => 'day', 'date' => $cDate, 'filter_limit' => -1] + $segmentParam];
        }
        $goalOffset = count($calls);
        if ($goalIds !== []) {
            $calls[] = ['Goals.getGoals', []];
            foreach ($goalIds as $id) {
                $calls[] = ['Goals.get', ['period' => $period, 'date' => $explicitDate, 'idGoal' => $id] + $segmentParam];
                $calls[] = ['Goals.get', ['period' => 'day', 'date' => $explicitDate, 'idGoal' => $id, 'filter_limit' => -1] + $segmentParam];
                if ($compareRange !== null) {
                    $calls[] = ['Goals.get', ['period' => $compareRange[0], 'date' => $compareRange[1], 'idGoal' => $id] + $segmentParam];
                    $calls[] = ['Goals.get', ['period' => 'day', 'date' => $compareRange[1], 'idGoal' => $id, 'filter_limit' => -1] + $segmentParam];
                }
            }
        }

        $results = $this->client->bulk($site, $calls);

        $visitorSeries = MatomoNormalizer::visitorSeries($results[3] ?? null);
        $topPages = MatomoNormalizer::topPages($results[2] ?? null);
        $trafficSources = MatomoNormalizer::trafficSources($results[1] ?? null);
        $kpisCompare = null;
        if ($compareRange !== null) {
            $kpisCompare = MatomoNormalizer::kpis($results[4] ?? null);
            $compareSeries = MatomoNormalizer::visitorSeries($results[7] ?? null);
            foreach ($visitorSeries as $i => $point) {
                if (isset($compareSeries[$i])) {
                    $visitorSeries[$i]['compare'] = $compareSeries[$i]['value'];
                }
            }
            $topPages = MatomoNormalizer::addPagesDelta($topPages, MatomoNormalizer::topPages($results[6] ?? null));
            $trafficSources = MatomoNormalizer::addSourcesDelta($trafficSources, MatomoNormalizer::trafficSources($results[5] ?? null));
        }

        $goals = [];
        $goalKpis = [];
        $goalSeries = [];
        if ($goalIds !== []) {
            $names = [];
            $meta = $results[$goalOffset] ?? [];
            foreach (is_array($meta) ? $meta : [] as $goal) {
                if (is_array($goal)) {
                    $names[(string)($goal['idgoal'] ?? '')] = (string)($goal['name'] ?? '');
                }
            }
            $goals = MatomoNormalizer::uniqueGoalLabels(array_map(
                static fn(string $id): array => ['id' => $id, 'name' => $names[$id] ?? ('#' . $id)],
                $goalIds
            ));
            $stride = $compareRange !== null ? 4 : 2;
            $entries = [];
            foreach ($goals as $i => $goal) {
                $base = $goalOffset + 1 + $i * $stride;
                $total = $results[$base] ?? [];
                $entry = ['key' => $goal['key'], 'series' => MatomoNormalizer::goalSeries($results[$base + 1] ?? null)];
                $conversionsCompare = null;
                if ($compareRange !== null) {
                    $compareTotal = $results[$base + 2] ?? [];
                    $conversionsCompare = (int)(is_array($compareTotal) ? ($compareTotal['nb_conversions'] ?? 0) : 0);
                    $entry['compareKey'] = $goal['key'] . '|compare';
                    $entry['compareSeries'] = MatomoNormalizer::goalSeries($results[$base + 3] ?? null);
                }
                $goalKpis[] = [
                    'id' => $goal['id'],
                    'name' => $goal['key'],
                    'conversions' => (int)(is_array($total) ? ($total['nb_conversions'] ?? 0) : 0),
                    'conversionsCompare' => $conversionsCompare,
                ];
                $entries[] = $entry;
            }
            $goalSeries = MatomoNormalizer::multiGoalSeries($entries);
        }

        return [
            'kpis' => MatomoNormalizer::kpis($results[0] ?? null),
            'kpisCompare' => $kpisCompare,
            'compare' => $compare !== '' ? $compare : null,
            'dateRange' => $this->dateRangeLabelParts($period, $date),
            'goals' => array_map(static fn(array $g): array => ['id' => $g['id'], 'name' => $g['key']], $goals),
            'goalKpis' => $goalKpis,
            'goalSeries' => $goalSeries,
            'visitorSeries' => $visitorSeries,
            'topPages' => $topPages,
            'trafficSources' => $trafficSources,
        ];
    }

    /**
     * @return list<string>
     */
    public function goalIds(string $site): array
    {
        return $this->settings->getList($site, 'matomo_goal_ids');
    }

    private function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today');
    }

    /**
     * Validates a custom range "YYYY-MM-DD,YYYY-MM-DD"; anything that is no date range falls back to the default preset.
     */
    private function customDate(string $date): string
    {
        if (!str_contains($date, ',')) {
            return 'last30';
        }
        $bounds = self::customRange($date, $this->today());
        if ($bounds === null) {
            throw new ApiException(sprintf('Invalid date range: dates must lie within the last %d months', self::CUSTOM_MAX_MONTHS), 400);
        }
        return $bounds[0]->format('Y-m-d') . ',' . $bounds[1]->format('Y-m-d');
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null [start, end], null = invalid
     */
    public static function customRange(string $date, \DateTimeImmutable $today): ?array
    {
        $parts = explode(',', $date);
        if (count($parts) !== 2) {
            return null;
        }
        $bounds = [];
        foreach ($parts as $part) {
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $part, $today->getTimezone());
            // Rejects overflowing dates such as 2026-02-31
            if ($day === false || $day->format('Y-m-d') !== $part) {
                return null;
            }
            $bounds[] = $day;
        }
        [$start, $end] = $bounds;
        if ($start > $end || $end > $today || $start < $today->modify('-' . self::CUSTOM_MAX_MONTHS . ' months')) {
            return null;
        }
        return [$start, $end];
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} [start, end] of a preset or custom range
     */
    private function rangeBounds(string $date): array
    {
        if (isset(self::RANGE_DAYS[$date])) {
            $end = $this->today();
            return [$end->modify('-' . (self::RANGE_DAYS[$date] - 1) . ' days'), $end];
        }
        return self::customRange($date, $this->today()) ?? throw new ApiException('Invalid date range', 400);
    }

    private function explicitDate(string $period, string $date): string
    {
        if ($period === 'day') {
            return $this->dayDate($date);
        }
        [$start, $end] = $this->rangeBounds($date);
        return $start->format('Y-m-d') . ',' . $end->format('Y-m-d');
    }

    private function dayDate(string $date): string
    {
        $day = $this->today();
        return ($date === 'yesterday' ? $day->modify('-1 day') : $day)->format('Y-m-d');
    }

    /**
     * @return array{0: string, 1: string} [period, date]
     */
    private function compareRange(string $period, string $date, string $compare): array
    {
        $today = $this->today();
        if ($period === 'day') {
            $base = $date === 'yesterday' ? $today->modify('-1 day') : $today;
            $base = $compare === 'year' ? $base->modify('-1 year') : $base->modify('-1 day');
            return ['day', $base->format('Y-m-d')];
        }
        [$start, $end] = $this->rangeBounds($date);
        if ($compare === 'year') {
            return ['range', $start->modify('-1 year')->format('Y-m-d') . ',' . $end->modify('-1 year')->format('Y-m-d')];
        }
        $days = (int)$start->diff($end)->days + 1;
        $compareEnd = $start->modify('-1 day');
        return ['range', $compareEnd->modify('-' . ($days - 1) . ' days')->format('Y-m-d') . ',' . $compareEnd->format('Y-m-d')];
    }

    /**
     * @return array{start: string, end: string}
     */
    private function dateRangeLabelParts(string $period, string $date): array
    {
        if ($period === 'day') {
            $day = $this->dayDate($date);
            return ['start' => $day, 'end' => $day];
        }
        [$start, $end] = explode(',', $this->explicitDate($period, $date));
        return ['start' => $start, 'end' => $end];
    }
}
