<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Tests\Unit\Service\Matomo;

use Derspe\SiteInsights\Service\Matomo\MatomoClient;
use Derspe\SiteInsights\Service\Matomo\PageMatomoService;
use Derspe\SiteInsights\Service\Matomo\TransitionsService;
use Derspe\SiteInsights\Tests\Unit\Fixtures\FakeHttp;
use Derspe\SiteInsights\Tests\Unit\Fixtures\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;

final class PageMatomoServiceTest extends UnitTestCase
{
    private const RANGE = ['startDate' => '2026-10-01', 'endDate' => '2026-10-28'];
    private const URL = 'https://alpha.example/a';

    private FakeHttp $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new FakeHttp($this);
        $settings = $this->settings();
        $settings->set('alpha', 'matomo_url', 'https://matomo.example.com');
        $settings->set('alpha', 'matomo_token', 'tok');
        $settings->set('alpha', 'matomo_site_id', '3');
    }

    private function service(): PageMatomoService
    {
        $client = new MatomoClient($this->settings(), $this->http->factory());
        return new PageMatomoService($client, $this->cache(), new TransitionsService($client, $this->cache()));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function types(int $smartphone, int $desktop = 0): array
    {
        return [
            ['label' => 'Smartphone', 'nb_visits' => $smartphone, 'segment' => 'deviceType==smartphone'],
            ['label' => 'Desktop', 'nb_visits' => $desktop, 'segment' => 'deviceType==desktop'],
        ];
    }

    private static function report(int $pageviews): \GuzzleHttp\Psr7\Response
    {
        return FakeHttp::json($pageviews > 0 ? [['nb_hits' => $pageviews]] : []);
    }

    /**
     * @return array<string, mixed>
     */
    private function bulkCall(int $request, int $index): array
    {
        parse_str($this->http->formFields($request)['urls'][$index], $call);
        return $call;
    }

    #[Test]
    public function devicesMapsRowsBySegmentValue(): void
    {
        $this->http->queue(
            FakeHttp::json([[
                ['label' => 'Smartphone', 'nb_visits' => 6, 'segment' => 'deviceType==smartphone'],
                ['label' => 'Phablet', 'nb_visits' => 2, 'segment' => 'deviceType==phablet'],
                ['label' => 'Desktop', 'nb_visits' => 10, 'segment' => 'deviceType==desktop'],
                ['label' => 'Tablet', 'nb_visits' => 2, 'segment' => 'deviceType==tablet'],
                ['label' => 'Fernseher', 'nb_visits' => 0, 'segment' => 'deviceType==tv'],
            ], self::types(50, 50)]),
            self::report(20),
        );

        $result = $this->service()->devices('alpha', self::URL, self::RANGE, '', null);

        self::assertSame(['mobile' => 0.4, 'desktop' => 0.5, 'tablet' => 0.1, 'other' => 0.0], $result['page']);
        self::assertSame(20, $result['visits']);
        self::assertSame(['mobile' => 0.5, 'desktop' => 0.5, 'tablet' => 0.0, 'other' => 0.0], $result['site']);
        self::assertTrue($result['available']);
        self::assertArrayNotHasKey('compare', $result);
    }

    #[Test]
    public function devicesCanBeCompared(): void
    {
        $this->http->queue(
            FakeHttp::json([self::types(3, 1), self::types(8, 8), self::types(1, 3)]),
            self::report(4),
        );

        $result = $this->service()->devices('alpha', self::URL, self::RANGE, '', ['startDate' => '2026-09-03', 'endDate' => '2026-09-30']);

        self::assertSame(['mobile' => 0.25, 'desktop' => 0.75, 'tablet' => 0.0, 'other' => 0.0], $result['compare']);
        self::assertSame('2026-09-03,2026-09-30', $this->bulkCall(0, 2)['date']);
    }

    #[Test]
    public function segmentsEncodeTheUrlAndKeepTheUserSegmentLast(): void
    {
        $this->http->queue(FakeHttp::json([self::types(1), self::types(1)]), self::report(1));

        $this->service()->devices('alpha', 'https://alpha.example/news/größe?x=1;y', self::RANGE, 'browserCode==FF,browserCode==CH', null);

        self::assertSame('pageUrl==https%3A%2F%2Falpha.example%2Fnews%2Fgr%C3%B6%C3%9Fe%3Fx%3D1%3By;browserCode==FF,browserCode==CH', $this->bulkCall(0, 0)['segment']);
        self::assertSame('browserCode==FF,browserCode==CH', $this->bulkCall(0, 1)['segment']);
        self::assertSame('DevicesDetection.getType', $this->bulkCall(0, 0)['method']);
    }

    #[Test]
    public function segmentedReportsWithoutVisitsAreNotAvailable(): void
    {
        $this->http->queue(
            FakeHttp::json([self::types(0), self::types(5)]),
            self::report(12),
        );

        $service = $this->service();
        $devices = $service->devices('alpha', self::URL, self::RANGE, '', null);
        $events = $service->events('alpha', self::URL, self::RANGE, '', null);

        self::assertFalse($devices['available']);
        self::assertFalse($events['available']);
        self::assertCount(2, $this->http->history);
    }

    #[Test]
    public function aPageWithoutPageviewsIsAvailableWithoutData(): void
    {
        $this->http->queue(
            FakeHttp::json([self::types(0), self::types(5)]),
            self::report(0),
            FakeHttp::json([[], []]),
        );

        $service = $this->service();
        $devices = $service->devices('alpha', self::URL, self::RANGE, '', null);
        $events = $service->events('alpha', self::URL, self::RANGE, '', null);

        self::assertTrue($devices['available']);
        self::assertSame(0, $devices['visits']);
        self::assertTrue($events['available']);
        self::assertSame(0, $events['total']);
        self::assertNull($events['per100']);
    }

    #[Test]
    public function eventsUseTheEventUrlSegmentAndCountPer100Pageviews(): void
    {
        $this->http->queue(
            self::report(140),
            FakeHttp::json([self::types(10), self::types(10)]),
            FakeHttp::json([
                [
                    ['label' => 'Video', 'nb_events' => 2, 'subtable' => [['label' => 'Play', 'nb_events' => 2]]],
                    ['label' => 'Amazon', 'nb_events' => 5, 'subtable' => [['label' => 'Direkt-Klick', 'nb_events' => 3], ['label' => 'Bild', 'nb_events' => 2]]],
                ],
                ['2026-10-01' => [['nb_events' => 7]]],
            ]),
        );

        $result = $this->service()->events('alpha', self::URL, self::RANGE, 'browserCode==FF', null);

        self::assertSame(7, $result['total']);
        self::assertSame(140, $result['pageviews']);
        self::assertSame(5.0, $result['per100']);
        self::assertSame('Amazon', $result['categories'][0]['label']);
        self::assertSame([['label' => 'Direkt-Klick', 'count' => 3], ['label' => 'Bild', 'count' => 2]], $result['categories'][0]['actions']);
        self::assertSame([['date' => '2026-10-01', 'value' => 7]], $result['series']);
        $expected = 'eventUrl==https%3A%2F%2Falpha.example%2Fa;browserCode==FF';
        foreach ([0, 1] as $i) {
            $call = $this->bulkCall(2, $i);
            self::assertSame('Events.getCategory', $call['method']);
            self::assertSame($expected, $call['segment']);
        }
        self::assertSame(['eventAction', '1', 'range'], [$this->bulkCall(2, 0)['secondaryDimension'], $this->bulkCall(2, 0)['expanded'], $this->bulkCall(2, 0)['period']]);
        self::assertSame(['day', '2026-10-01,2026-10-28'], [$this->bulkCall(2, 1)['period'], $this->bulkCall(2, 1)['date']]);
        self::assertStringNotContainsString('pageUrl==', (string)$this->http->formFields(2)['urls'][0] . $this->http->formFields(2)['urls'][1]);
    }

    #[Test]
    public function eventSeriesIsWeeklyAbove92DaysAndAlignsTheComparison(): void
    {
        $this->http->queue(
            self::report(100),
            FakeHttp::json([self::types(10), self::types(10)]),
            FakeHttp::json([
                [['label' => 'A', 'nb_events' => 9, 'subtable' => []]],
                ['2026-09-28,2026-10-04' => [['nb_events' => 4], ['nb_events' => 1]], '2026-10-05,2026-10-11' => []],
                [['label' => 'A', 'nb_events' => 3, 'subtable' => []]],
                ['2026-06-29,2026-07-05' => [['nb_events' => 2]], '2026-07-06,2026-07-12' => [['nb_events' => 1]]],
            ]),
            self::report(60),
        );

        $result = $this->service()->events('alpha', self::URL, ['startDate' => '2026-09-28', 'endDate' => '2027-01-26'], '', ['startDate' => '2026-06-29', 'endDate' => '2026-09-27']);

        self::assertSame('week', $result['period']);
        self::assertSame([['date' => '2026-09-28', 'value' => 5, 'compare' => 2], ['date' => '2026-10-05', 'value' => 0, 'compare' => 1]], $result['series']);
        self::assertSame(['total' => 3, 'pageviews' => 60, 'per100' => 5.0], $result['compare']);
        self::assertSame('week', $this->bulkCall(2, 1)['period']);
        self::assertSame('2026-06-29,2026-09-27', $this->bulkCall(2, 3)['date']);
    }
}
