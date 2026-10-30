<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Service\Matomo;

use Derspe\SiteInsights\Service\ApiCache;

/**
 * Matomo figures of one page that are not part of the transitions: device classes and
 * events triggered on the page. The URL is the one returned by TransitionsService::matomoUrl.
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
     * Device shares of the visits that viewed the page, and of all visits for comparison.
     * Not available when Matomo cannot segment by the page although it has pageviews.
     *
     * @param array{startDate: string, endDate: string} $range
     * @param array{startDate: string, endDate: string}|null $compareRange
     * @return array<string, mixed>
     */
    public function devices(string $site, string $url, array $range, string $segment, ?array $compareRange): array
    {
        return $this->cache->remember([$site, 'matomo', 'pageDevices', 1, $url, $range, $segment, $compareRange], function () use ($site, $url, $range, $segment, $compareRange): array {
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
                $result['compare'] = MatomoNormalizer::deviceShares($results[2] ?? [])['shares'];
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
