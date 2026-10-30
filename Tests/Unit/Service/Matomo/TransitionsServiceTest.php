<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Tests\Unit\Service\Matomo;

use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Service\Matomo\MatomoClient;
use Derspe\SiteInsights\Service\Matomo\MatomoDashboardService;
use Derspe\SiteInsights\Service\Matomo\PageMatomoService;
use Derspe\SiteInsights\Service\Matomo\TransitionsService;
use Derspe\SiteInsights\Tests\Unit\Fixtures\FakeHttp;
use Derspe\SiteInsights\Tests\Unit\Fixtures\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;

final class TransitionsServiceTest extends UnitTestCase
{
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

    private function service(): TransitionsService
    {
        return new TransitionsService(new MatomoClient($this->settings(), $this->http->factory()), $this->cache());
    }

    #[Test]
    public function knownHostsAreKeptAndRangesUseThePeriodRange(): void
    {
        $this->http->queue(
            FakeHttp::json(['https://www.alpha.example', 'https://alpha.example']),
            FakeHttp::json(['pageMetrics' => ['pageviews' => 10, 'entries' => 4, 'exits' => 3, 'loops' => 0]]),
            FakeHttp::json([['nb_hits' => 10, 'avg_time_on_page' => 95, 'entry_nb_visits' => 4, 'entry_bounce_count' => 1]]),
        );

        $result = $this->service()->forUrl('alpha', 'https://alpha.example/news/a', '2026-10-01', '2026-10-28');

        $form = $this->http->formFields(1);
        self::assertSame('Transitions.getTransitionsForPageUrl', $form['method']);
        self::assertSame(['https://alpha.example/news/a', 'range', '2026-10-01,2026-10-28'], [$form['pageUrl'], $form['period'], $form['date']]);
        self::assertSame((string)TransitionsService::LIMIT, $form['limitBeforeGrouping']);
        self::assertFalse($result['noData']);
        self::assertSame(10, $result['pageviews']);
        self::assertSame(['Actions.getPageUrl', 'https://alpha.example/news/a'], [$this->http->formFields(2)['method'], $this->http->formFields(2)['pageUrl']]);
        self::assertArrayNotHasKey('limitBeforeGrouping', $this->http->formFields(2));
        self::assertSame([95, 0.25], [$result['avgTimeOnPage'], $result['bounceRate']]);
    }

    #[Test]
    public function thePageReportIsSharedByTransitionsAndCached(): void
    {
        $this->http->queue(
            FakeHttp::json(['https://alpha.example']),
            FakeHttp::json(['pageMetrics' => ['pageviews' => 10, 'entries' => 4, 'exits' => 3, 'loops' => 0]]),
            FakeHttp::json([['nb_hits' => 10, 'avg_time_on_page' => 95, 'entry_nb_visits' => 4, 'entry_bounce_count' => 1]]),
        );
        $range = ['startDate' => '2026-10-01', 'endDate' => '2026-10-28'];

        $this->service()->forUrl('alpha', 'https://alpha.example/a', '2026-10-01', '2026-10-28');
        $report = $this->service()->pageReport('alpha', 'https://alpha.example/a', $range, '');

        self::assertSame(10, $report['pageviews']);
        $methods = array_map(fn(int $i): string => (string)$this->http->formFields($i)['method'], array_keys($this->http->history));
        self::assertSame(1, count(array_filter($methods, static fn(string $m): bool => $m === 'Actions.getPageUrl')));
    }

    #[Test]
    public function theFiguresOfThePageReportCanBeCompared(): void
    {
        $this->http->queue(
            FakeHttp::json(['https://alpha.example']),
            FakeHttp::json(['pageMetrics' => ['pageviews' => 10, 'entries' => 4, 'exits' => 3, 'loops' => 0]]),
            FakeHttp::json([['nb_hits' => 12, 'avg_time_on_page' => 60, 'entry_nb_visits' => 4, 'entry_bounce_count' => 2, 'exit_nb_visits' => 3]]),
            FakeHttp::json([['nb_hits' => 8, 'avg_time_on_page' => 40, 'entry_nb_visits' => 2, 'entry_bounce_count' => 2, 'exit_nb_visits' => 1]]),
        );

        $result = $this->service()->forUrl('alpha', 'https://alpha.example/a', '2026-10-08', '2026-10-14', '', ['startDate' => '2026-10-01', 'endDate' => '2026-10-07']);

        self::assertSame(12, $result['engagement']['pageviews']);
        self::assertSame(['avgTimeOnPage' => 40, 'bounceRate' => 1.0, 'pageviews' => 8, 'entries' => 2, 'exits' => 1, 'goals' => []], $result['compare']);
        self::assertSame(['Actions.getPageUrl', 'range', '2026-10-01,2026-10-07'], [$this->http->formFields(3)['method'], $this->http->formFields(3)['period'], $this->http->formFields(3)['date']]);
    }

    #[Test]
    public function pageviewsPerDayWithTheComparisonAlignedByPosition(): void
    {
        $this->http->queue(
            FakeHttp::json(['https://alpha.example']),
            FakeHttp::json([
                ['2026-10-08' => [['nb_hits' => 4]], '2026-10-09' => []],
                ['2026-10-06' => [['nb_hits' => 1]], '2026-10-07' => [['nb_hits' => 3]]],
            ]),
        );

        $result = $this->service()->series('alpha', '/a', ['startDate' => '2026-10-08', 'endDate' => '2026-10-09'], '', ['startDate' => '2026-10-06', 'endDate' => '2026-10-07']);

        self::assertSame('day', $result['period']);
        self::assertSame([['date' => '2026-10-08', 'value' => 4, 'compare' => 1], ['date' => '2026-10-09', 'value' => 0, 'compare' => 3]], $result['series']);
        parse_str($this->http->formFields(1)['urls'][0], $call);
        self::assertSame(['Actions.getPageUrl', 'https://alpha.example/a', 'day', '2026-10-08,2026-10-09'], [$call['method'], $call['pageUrl'], $call['period'], $call['date']]);
    }

    #[Test]
    public function longPeriodsArePerWeek(): void
    {
        $this->http->queue(FakeHttp::json(['https://alpha.example']), FakeHttp::json([[]]));
        $result = $this->service()->series('alpha', '/a', ['startDate' => '2025-10-29', 'endDate' => '2026-10-28']);
        self::assertSame('week', $result['period']);
        self::assertArrayNotHasKey('compareRange', $result);
    }

    #[Test]
    public function unknownHostsAndPathsGetTheMainUrlOfTheMatomoSite(): void
    {
        $this->http->queue(
            FakeHttp::json(['https://www.alpha.example', 'https://alpha.example']),
            FakeHttp::json(['pageMetrics' => []]),
            FakeHttp::json([]),
            FakeHttp::json(['pageMetrics' => []]),
            FakeHttp::json(['result' => 'error', 'message' => 'no report']),
        );
        $service = $this->service();

        $service->forUrl('alpha', 'https://alpha.ddev.site/news/a?x=1', '2026-10-28', '2026-10-28');
        $service->forUrl('alpha', '/news/b', '2026-10-28', '2026-10-28');

        self::assertSame(['https://www.alpha.example/news/a?x=1', 'day', '2026-10-28'], [$this->http->formFields(1)['pageUrl'], $this->http->formFields(1)['period'], $this->http->formFields(1)['date']]);
        self::assertSame('https://www.alpha.example/news/b', $this->http->formFields(3)['pageUrl']);
        self::assertCount(5, $this->http->history, 'site URLs are cached');
    }

    #[Test]
    public function pagesWithoutDataAreNoError(): void
    {
        $this->http->queue(
            FakeHttp::json(['https://alpha.example']),
            FakeHttp::json(['result' => 'error', 'message' => 'NoDataForAction']),
        );

        $result = $this->service()->forUrl('alpha', 'https://alpha.example/x', '2026-10-01', '2026-10-28');

        self::assertTrue($result['noData']);
    }

    #[Test]
    public function aLimitedPeriodIsExplained(): void
    {
        $this->http->queue(
            FakeHttp::json(['https://alpha.example']),
            FakeHttp::json(['result' => 'error', 'message' => 'PeriodNotAllowed']),
        );
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('max_period_allowed');

        $this->service()->forUrl('alpha', 'https://alpha.example/x', '2026-01-01', '2026-10-28');
    }

    #[Test]
    public function dashboardValidatesUrlAndRangeBeforeAnyRequest(): void
    {
        $client = new MatomoClient($this->settings(), $this->http->factory());
        $dashboard = new MatomoDashboardService($client, $this->settings(), $this->cache(), $this->service(), new PageMatomoService($client, $this->cache(), $this->service()));
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        foreach ([
            ['url' => 'javascript:alert(1)', 'start' => $today, 'end' => $today],
            ['url' => '/x', 'start' => '2020-01-01', 'end' => $today],
            ['url' => '/x', 'start' => $today, 'end' => 'tomorrow'],
        ] as $query) {
            try {
                $dashboard->transitions('alpha', $query);
                self::fail('Exception expected');
            } catch (ApiException $e) {
                self::assertSame(400, $e->status);
            }
        }
        self::assertSame([], $this->http->history);
    }

    #[Test]
    public function eventNamesNeedACategoryOfReasonableLengthBeforeAnyRequest(): void
    {
        $client = new MatomoClient($this->settings(), $this->http->factory());
        $dashboard = new MatomoDashboardService($client, $this->settings(), $this->cache(), $this->service(), new PageMatomoService($client, $this->cache(), $this->service()));
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $page = ['url' => '/x', 'start' => $today, 'end' => $today];
        foreach ([$page, $page + ['category' => ''], $page + ['category' => str_repeat('a', 256)], $page + ['category' => ['a']]] as $query) {
            try {
                $dashboard->pageEventNames('alpha', $query);
                self::fail('Exception expected');
            } catch (ApiException $e) {
                self::assertSame(400, $e->status);
            }
        }
        self::assertSame([], $this->http->history);
    }

    #[Test]
    public function aYearOfSearchConsoleStartsAtTheEarliestDayMatomoAllows(): void
    {
        $this->http->queue(FakeHttp::json(['https://alpha.example']), FakeHttp::json(['pageMetrics' => ['pageviews' => 1]]), FakeHttp::json([]));
        $client = new MatomoClient($this->settings(), $this->http->factory());
        $dashboard = new MatomoDashboardService($client, $this->settings(), $this->cache(), $this->service(), new PageMatomoService($client, $this->cache(), $this->service()));
        $today = new \DateTimeImmutable('today');
        $earliest = $today->modify('-' . MatomoDashboardService::CUSTOM_MAX_MONTHS . ' months')->format('Y-m-d');

        $result = $dashboard->transitions('alpha', ['url' => '/x', 'start' => $today->modify('-367 days')->format('Y-m-d'), 'end' => $today->modify('-3 days')->format('Y-m-d')]);

        self::assertSame($earliest, $result['range']['startDate']);
    }
}
