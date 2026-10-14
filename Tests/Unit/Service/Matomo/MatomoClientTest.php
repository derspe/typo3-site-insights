<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Tests\Unit\Service\Matomo;

use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Service\Matomo\MatomoClient;
use Derspe\SiteInsights\Service\Matomo\MatomoDashboardService;
use Derspe\SiteInsights\Tests\Unit\Fixtures\FakeHttp;
use Derspe\SiteInsights\Tests\Unit\Fixtures\UnitTestCase;
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
}
