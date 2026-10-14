<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Service\Matomo;

use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Service\ApiCache;
use Derspe\SiteInsights\Settings\SiteSettings;

/**
 * Matomo endpoints of the backend module: segments, goals and the dashboard.
 */
final readonly class MatomoDashboardService
{
    private const RANGE_DAYS = ['last7' => 7, 'last30' => 30, 'last90' => 90, 'last365' => 365];

    public function __construct(
        private MatomoClient $client,
        private SiteSettings $settings,
        private ApiCache $cache,
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
            : (isset(self::RANGE_DAYS[$date]) ? $date : 'last30');
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

    private function explicitDate(string $period, string $date): string
    {
        if ($period === 'day') {
            return $this->dayDate($date);
        }
        $days = self::RANGE_DAYS[$date];
        $end = $this->today();
        $start = $end->modify('-' . ($days - 1) . ' days');
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
        $days = self::RANGE_DAYS[$date];
        $end = $compare === 'year' ? $today->modify('-1 year') : $today->modify('-' . $days . ' days');
        $start = $end->modify('-' . ($days - 1) . ' days');
        return ['range', $start->format('Y-m-d') . ',' . $end->format('Y-m-d')];
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
