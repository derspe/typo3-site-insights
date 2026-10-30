<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Service\Matomo;

use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Service\ApiCache;

/**
 * Matomo figures of one page that are not part of the transitions: device classes and
 * events triggered on the page. URLs and paths are mapped to the Matomo site with
 * TransitionsService::matomoUrl.
 */
final readonly class PageMatomoService
{
    private const LIFETIME = 3600;

    public function __construct(
        private MatomoClient $client,
        private ApiCache $cache,
        private TransitionsService $transitions,
    ) {}

    /**
     * Device shares of the visits that viewed the page, and of all visits for comparison; the
     * compare shares are left out when the compare period has no visits of the page.
     * Not available when Matomo cannot segment by the page although it has pageviews.
     *
     * @param array{startDate: string, endDate: string} $range
     * @param array{startDate: string, endDate: string}|null $compareRange
     * @return array<string, mixed>
     */
    public function devices(string $site, string $url, array $range, string $segment, ?array $compareRange): array
    {
        $url = $this->transitions->matomoUrl($site, $url);
        return $this->cache->remember([$site, 'matomo', 'pageDevices', 2, $url, $range, $segment, $compareRange], function () use ($site, $url, $range, $segment, $compareRange): array {
            $pageSegment = self::pageSegment('pageUrl', $url, $segment);
            $calls = [
                self::call('DevicesDetection.getType', $range, $pageSegment),
                self::call('DevicesDetection.getType', $range, $segment),
            ];
            if ($compareRange !== null) {
                $calls[] = self::call('DevicesDetection.getType', $compareRange, $pageSegment);
            }
            $results = $this->client->bulk($site, $calls);
            $page = MatomoNormalizer::deviceShares($results[0]);
            $available = !($this->transitions->pageReport($site, $url, $range, $segment)['pageviews'] > 0 && $page['visits'] === 0);
            $result = [
                'available' => $available,
                'visits' => $page['visits'],
                'page' => $page['shares'],
                'site' => MatomoNormalizer::deviceShares($results[1])['shares'],
            ];
            if ($compareRange !== null) {
                // Shares of a period without visits are all 0: a change against them would be made up
                $compare = MatomoNormalizer::deviceShares($results[2] ?? []);
                if ($compare['visits'] > 0) {
                    $result['compare'] = $compare['shares'];
                }
                $result['compareRange'] = $compareRange;
            }
            return $result;
        }, self::LIFETIME);
    }

    /**
     * Events of the page by category and action, events per 100 pageviews, and events per
     * day (per week for periods longer than TransitionsService::WEEKLY_FROM days); the compare
     * series is aligned by position.
     *
     * @param array{startDate: string, endDate: string} $range
     * @param array{startDate: string, endDate: string}|null $compareRange
     * @return array<string, mixed>
     */
    public function events(string $site, string $url, array $range, string $segment, ?array $compareRange): array
    {
        $url = $this->transitions->matomoUrl($site, $url);
        $days = (int)(new \DateTimeImmutable($range['startDate']))->diff(new \DateTimeImmutable($range['endDate']))->days + 1;
        $period = $days > TransitionsService::WEEKLY_FROM ? 'week' : 'day';
        return $this->cache->remember([$site, 'matomo', 'pageEvents', 1, $url, $range, $segment, $compareRange], function () use ($site, $url, $range, $segment, $compareRange, $period): array {
            $pageviews = $this->transitions->pageReport($site, $url, $range, $segment)['pageviews'];
            if (!$this->devices($site, $url, $range, $segment, null)['available']) {
                return [
                    'available' => false, 'total' => 0, 'pageviews' => $pageviews, 'per100' => null,
                    'categories' => [], 'period' => $period, 'series' => [], 'range' => $range,
                ];
            }
            // Matomo segments select visits: "pageUrl" would also count the events of other
            // pages in visits that viewed this page, "eventUrl" (verified against the raw log
            // on 2026-10-30) matches the events that happened on the URL.
            $eventSegment = self::pageSegment('eventUrl', $url, $segment);
            $calls = [self::categories($range, $eventSegment), self::series($range, $eventSegment, $period)];
            if ($compareRange !== null) {
                $calls[] = self::categories($compareRange, $eventSegment);
                $calls[] = self::series($compareRange, $eventSegment, $period);
            }
            $results = $this->client->bulk($site, $calls);
            $categories = MatomoNormalizer::eventCategories($results[0]);
            $total = array_sum(array_column($categories, 'count'));
            $series = MatomoNormalizer::eventSeries($results[1]);
            $result = [
                'available' => true,
                'total' => $total,
                'pageviews' => $pageviews,
                'per100' => self::per100($total, $pageviews),
                'categories' => $categories,
                'period' => $period,
                'series' => $series,
                'range' => $range,
            ];
            if ($compareRange !== null) {
                foreach (MatomoNormalizer::eventSeries($results[3] ?? []) as $i => $point) {
                    if (isset($result['series'][$i])) {
                        $result['series'][$i]['compare'] = $point['value'];
                    }
                }
                $compareTotal = array_sum(array_column(MatomoNormalizer::eventCategories($results[2] ?? []), 'count'));
                $comparePageviews = $this->transitions->pageReport($site, $url, $compareRange, $segment)['pageviews'];
                $result['compare'] = ['total' => $compareTotal, 'pageviews' => $comparePageviews, 'per100' => self::per100($compareTotal, $comparePageviews)];
                $result['compareRange'] = $compareRange;
            }
            return $result;
        }, self::LIFETIME);
    }

    /**
     * Event names per action of one event category of the page. The category must be one
     * Matomo returned for the page in events().
     *
     * @param array{startDate: string, endDate: string} $range
     * @return array{category: string, actions: list<array{label: string, count: int, names: list<array{label: string, count: int}>}>}
     * @throws ApiException 400 for a category the page has no events for
     */
    public function eventNames(string $site, string $url, array $range, string $segment, string $category): array
    {
        $url = $this->transitions->matomoUrl($site, $url);
        $known = array_column($this->events($site, $url, $range, $segment, null)['categories'], 'label');
        if (!in_array($category, $known, true)) {
            throw new ApiException('Unknown event category', 400);
        }
        return $this->cache->remember([$site, 'matomo', 'pageEventNames', 1, $url, $range, $segment, $category], function () use ($site, $url, $range, $segment, $category): array {
            $eventSegment = self::pageSegment('eventUrl', $url, '') . ';eventCategory==' . rawurlencode($category) . ($segment !== '' ? ';' . $segment : '');
            $call = self::call('Events.getAction', $range, $eventSegment);
            $call[1] += ['secondaryDimension' => 'eventName', 'expanded' => 1];
            $actions = [];
            foreach (MatomoNormalizer::eventCategories($this->client->bulk($site, [$call])[0] ?? []) as $action) {
                // The normalizer reads the subtable of a category row, here it holds the names of an action
                $actions[] = ['label' => $action['label'], 'count' => $action['count'], 'names' => $action['actions']];
            }
            return ['category' => $category, 'actions' => $actions];
        }, self::LIFETIME);
    }

    /**
     * Goals defined in Matomo with the conversions entering on the page and the ones of visits
     * that viewed the page on their way (unique conversions minus the entry ones).
     *
     * @param array{startDate: string, endDate: string} $range
     * @param array{startDate: string, endDate: string}|null $compareRange
     * @return array<string, mixed>
     */
    public function goals(string $site, string $url, array $range, string $segment, ?array $compareRange): array
    {
        $url = $this->transitions->matomoUrl($site, $url);
        return $this->cache->remember([$site, 'matomo', 'pageGoals', 1, $url, $range, $segment, $compareRange], function () use ($site, $url, $range, $segment, $compareRange): array {
            $defined = [];
            $rows = $this->cache->remember([$site, 'matomo', 'goalDefinitions', 1], fn(): mixed => $this->client->call($site, 'Goals.getGoals'), self::LIFETIME);
            foreach (is_array($rows) && array_is_list($rows) ? $rows : [] as $row) {
                if (is_array($row) && isset($row['idgoal'])) {
                    $defined[] = ['id' => (int)$row['idgoal'], 'name' => (string)($row['name'] ?? '')];
                }
            }
            $byId = static function (array $report): array {
                $figures = [];
                foreach ($report['goals'] as $goal) {
                    $figures[$goal['id']] = ['entry' => $goal['entry'], 'onTheWay' => $goal['onTheWay']];
                }
                return $figures;
            };
            $empty = ['entry' => 0, 'onTheWay' => 0];
            $page = $byId($this->transitions->pageReport($site, $url, $range, $segment));
            $compare = $compareRange !== null ? $byId($this->transitions->pageReport($site, $url, $compareRange, $segment)) : null;
            $goals = [];
            $totals = $compareTotals = $empty;
            foreach ($defined as $goal) {
                $figures = $page[$goal['id']] ?? $empty;
                $totals = ['entry' => $totals['entry'] + $figures['entry'], 'onTheWay' => $totals['onTheWay'] + $figures['onTheWay']];
                $goal += $figures;
                if ($compare !== null) {
                    $compareFigures = $compare[$goal['id']] ?? $empty;
                    $goal['compare'] = $compareFigures;
                    $compareTotals = ['entry' => $compareTotals['entry'] + $compareFigures['entry'], 'onTheWay' => $compareTotals['onTheWay'] + $compareFigures['onTheWay']];
                }
                $goals[] = $goal;
            }
            $result = ['defined' => $defined !== [], 'goals' => $goals, 'totals' => $totals];
            if ($compare !== null) {
                $result['compareTotals'] = $compareTotals;
                $result['compareRange'] = $compareRange;
            }
            return $result;
        }, self::LIFETIME);
    }

    /**
     * Matomo segment restricting the visits to those with the page; the user segment follows.
     */
    public static function pageSegment(string $dimension, string $url, string $segment): string
    {
        return $dimension . '==' . rawurlencode($url) . ($segment !== '' ? ';' . $segment : '');
    }

    private static function per100(int $events, int $pageviews): ?float
    {
        return $pageviews > 0 ? round($events / $pageviews * 100, 1) : null;
    }

    /**
     * @param array{startDate: string, endDate: string} $range
     * @return array{0: string, 1: array<string, scalar>}
     */
    private static function call(string $method, array $range, string $segment): array
    {
        $day = $range['startDate'] === $range['endDate'];
        return [$method, [
            'period' => $day ? 'day' : 'range',
            'date' => $day ? $range['startDate'] : $range['startDate'] . ',' . $range['endDate'],
        ] + ($segment !== '' ? ['segment' => $segment] : [])];
    }

    /**
     * @param array{startDate: string, endDate: string} $range
     * @return array{0: string, 1: array<string, scalar>}
     */
    private static function categories(array $range, string $segment): array
    {
        $call = self::call('Events.getCategory', $range, $segment);
        $call[1] += ['secondaryDimension' => 'eventAction', 'expanded' => 1];
        return $call;
    }

    /**
     * @param array{startDate: string, endDate: string} $range
     * @return array{0: string, 1: array<string, scalar>}
     */
    private static function series(array $range, string $segment, string $period): array
    {
        return ['Events.getCategory', [
            'period' => $period,
            'date' => $range['startDate'] . ',' . $range['endDate'],
            'segment' => $segment,
        ]];
    }
}
