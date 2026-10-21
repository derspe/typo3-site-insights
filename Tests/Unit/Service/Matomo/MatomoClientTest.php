<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Tests\Unit\Service\Matomo;

use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Service\Matomo\MatomoClient;
use Derspe\SiteInsights\Service\Matomo\MatomoDashboardService;
use Derspe\SiteInsights\Tests\Unit\Fixtures\FakeHttp;
use Derspe\SiteInsights\Tests\Unit\Fixtures\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class MatomoClientTest extends UnitTestCase
{
    private FakeHttp $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new FakeHttp($this);
        $settings = $this->settings();
        $settings->set('alpha', 'matomo_url', 'https://matomo.example.com');
        $settings->set('alpha', 'matomo_token', 'tok-secret');
        $settings->set('alpha', 'matomo_site_id', '3');
    }

    private function client(): MatomoClient
    {
        return new MatomoClient($this->settings(), $this->http->factory());
    }

    #[Test]
    public function tokenIsSentInThePostBodyNeverInTheUrl(): void
    {
        $this->http->queue(FakeHttp::json(['nb_visits' => 1]));
        $this->client()->call('alpha', 'VisitsSummary.get', ['period' => 'day']);
        $request = $this->http->history[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://matomo.example.com/index.php', (string)$request->getUri());
        self::assertStringNotContainsString('tok-secret', (string)$request->getUri());
        self::assertSame('tok-secret', $this->http->formFields(0)['token_auth']);
    }

    #[Test]
    public function parametersCannotOverrideSiteOrToken(): void
    {
        $this->http->queue(FakeHttp::json([]));
        $this->client()->call('alpha', 'VisitsSummary.get', ['idSite' => '99', 'token_auth' => 'other', 'module' => 'Login']);
        $form = $this->http->formFields(0);
        self::assertSame(['3', 'tok-secret', 'API'], [$form['idSite'], $form['token_auth'], $form['module']]);
    }

    #[Test]
    public function bulkSubRequestsCannotOverrideTheSite(): void
    {
        $this->http->queue(FakeHttp::json([[], []]));
        $this->client()->bulk('alpha', [
            ['VisitsSummary.get', ['period' => 'day', 'idSite' => '99']],
            ['Referrers.getAll', ['segment' => 'pageUrl=@x&idSite=99']],
        ]);
        $form = $this->http->formFields(0);
        self::assertSame('API.getBulkRequest', $form['method']);
        foreach ($form['urls'] as $url) {
            parse_str($url, $sub);
            self::assertSame('3', $sub['idSite']);
            self::assertArrayNotHasKey('token_auth', $sub);
        }
        parse_str($form['urls'][1], $second);
        self::assertSame('pageUrl=@x&idSite=99', $second['segment']);
    }

    #[Test]
    public function apiErrorsBecomeExceptions(): void
    {
        $this->http->queue(FakeHttp::json([['nb_visits' => 1], ['result' => 'error', 'message' => 'Segment not supported']]));
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Segment not supported');
        $this->client()->bulk('alpha', [['A.b', []], ['C.d', []]]);
    }

    #[Test]
    public function notConfiguredDoesNotSendAnything(): void
    {
        try {
            $this->client()->call('beta', 'VisitsSummary.get');
            self::fail('Exception expected');
        } catch (ApiException $e) {
            self::assertSame(503, $e->status);
        }
        self::assertSame([], $this->http->history);
    }

    #[Test]
    public function unknownSegmentIsRejectedBeforeAnyReportRequest(): void
    {
        $this->http->queue(FakeHttp::json([['idsegment' => 1, 'name' => 'Mobile', 'definition' => 'deviceType==smartphone']]));
        $service = new MatomoDashboardService($this->client(), $this->settings(), $this->cache());
        try {
            $service->dashboard('alpha', 'range', 'last30', 'pageUrl=@secret', '');
            self::fail('Exception expected');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status);
        }
        // Only the segment list was requested
        self::assertCount(1, $this->http->history);
        self::assertSame('SegmentEditor.getAll', $this->http->formFields(0)['method']);
    }

    #[Test]
    public function dashboardIsCached(): void
    {
        $bulk = FakeHttp::json([[], [], [], []]);
        $this->http->queue($bulk);
        $service = new MatomoDashboardService($this->client(), $this->settings(), $this->cache());
        $service->dashboard('alpha', 'range', 'last30', '', '');
        $service->dashboard('alpha', 'range', 'last30', '', '');
        self::assertCount(1, $this->http->history);
    }

    /**
     * @return list<string> "date" parameter of each bulk sub-request
     */
    private function bulkDates(): array
    {
        return array_map(static function (string $url): string {
            parse_str($url, $sub);
            return (string)$sub['date'];
        }, $this->http->formFields(0)['urls']);
    }

    #[Test]
    public function customRangeIsComparedWithThePeriodBefore(): void
    {
        $from = (new \DateTimeImmutable('today'))->modify('-40 days');
        $to = $from->modify('+9 days');
        $this->http->queue(FakeHttp::json(array_fill(0, 8, [])));
        $service = new MatomoDashboardService($this->client(), $this->settings(), $this->cache());
        $result = $service->dashboard('alpha', 'range', $from->format('Y-m-d') . ',' . $to->format('Y-m-d'), '', 'previous');

        $range = $from->format('Y-m-d') . ',' . $to->format('Y-m-d');
        $compare = $from->modify('-10 days')->format('Y-m-d') . ',' . $from->modify('-1 day')->format('Y-m-d');
        self::assertSame([$range, $range, $range, $range, $compare, $compare, $compare, $compare], $this->bulkDates());
        self::assertSame(['start' => $from->format('Y-m-d'), 'end' => $to->format('Y-m-d')], $result['dateRange']);
    }

    #[Test]
    public function customRangeIsComparedWithThePreviousYear(): void
    {
        $this->http->queue(FakeHttp::json(array_fill(0, 8, [])));
        $from = (new \DateTimeImmutable('today'))->modify('-20 days');
        $date = $from->format('Y-m-d') . ',' . $from->modify('+5 days')->format('Y-m-d');
        $service = new MatomoDashboardService($this->client(), $this->settings(), $this->cache());
        $service->dashboard('alpha', 'range', $date, '', 'year');
        self::assertSame($from->modify('-1 year')->format('Y-m-d') . ',' . $from->modify('+5 days')->modify('-1 year')->format('Y-m-d'), $this->bulkDates()[4]);
    }

    #[Test]
    public function invalidCustomRangeIsRejectedWithoutRequest(): void
    {
        $service = new MatomoDashboardService($this->client(), $this->settings(), $this->cache());
        try {
            $service->dashboard('alpha', 'range', '2020-01-01,2020-02-01', '', '');
            self::fail('Exception expected');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status);
        }
        self::assertSame([], $this->http->history);
    }

    #[Test]
    public function presetsKeepTheirComparison(): void
    {
        $this->http->queue(FakeHttp::json(array_fill(0, 8, [])));
        $service = new MatomoDashboardService($this->client(), $this->settings(), $this->cache());
        $service->dashboard('alpha', 'range', 'last30', '', 'previous');
        $today = new \DateTimeImmutable('today');
        self::assertSame($today->modify('-29 days')->format('Y-m-d') . ',' . $today->format('Y-m-d'), $this->bulkDates()[0]);
        self::assertSame($today->modify('-59 days')->format('Y-m-d') . ',' . $today->modify('-30 days')->format('Y-m-d'), $this->bulkDates()[4]);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function customRanges(): iterable
    {
        yield 'valid' => ['2026-05-01,2026-05-31', true];
        yield 'single day' => ['2026-10-21,2026-10-21', true];
        yield 'exactly 12 months back' => ['2025-10-21,2026-10-21', true];
        yield 'more than 12 months back' => ['2025-10-20,2026-10-21', false];
        yield 'end in the future' => ['2026-10-01,2026-10-22', false];
        yield 'start after end' => ['2026-05-31,2026-05-01', false];
        yield 'impossible date' => ['2026-02-31,2026-03-05', false];
        yield 'not a date' => ['yesterday,today', false];
        yield 'three parts' => ['2026-05-01,2026-05-02,2026-05-03', false];
        yield 'time appended' => ['2026-05-01 10:00,2026-05-02', false];
    }

    #[Test]
    #[DataProvider('customRanges')]
    public function customRangeValidation(string $date, bool $valid): void
    {
        $today = new \DateTimeImmutable('2026-10-21');
        self::assertSame($valid, MatomoDashboardService::customRange($date, $today) !== null);
    }
}
