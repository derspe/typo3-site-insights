<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Tests\Unit\Service\Gsc;

use Derspe\SiteInsights\Domain\Repository\SnapshotRepository;
use Derspe\SiteInsights\Service\Gsc\GoogleServiceAccount;
use Derspe\SiteInsights\Service\Gsc\GscDashboardService;
use Derspe\SiteInsights\Service\Gsc\SearchConsoleClient;
use Derspe\SiteInsights\Service\Matomo\MatomoClient;
use Derspe\SiteInsights\Settings\Sources;
use Derspe\SiteInsights\Tests\Unit\Fixtures\FakeHttp;
use Derspe\SiteInsights\Tests\Unit\Fixtures\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class GscDashboardServiceTest extends UnitTestCase
{
    #[Test]
    public function topQueriesAndPagesBreakTiesInClicksByImpressions(): void
    {
        $http = new FakeHttp($this);
        $settings = $this->settings();
        $settings->set('alpha', 'gsc_site_url', 'sc-domain:alpha.example');
        $settings->set('alpha', 'gsc_top_n', 2);
        $account = $this->createMock(GoogleServiceAccount::class);
        $account->method('accessToken')->willReturn('token');
        $client = new SearchConsoleClient($account, $settings, $http->factory());
        $service = new GscDashboardService($client, $settings, $this->cache(), new SnapshotRepository($this->db->connectionPool()), new MatomoClient($settings, $http->factory()), new Sources(new ExtensionConfiguration()));

        $http->queue(
            FakeHttp::json(['rows' => [['keys' => ['2026-10-19']]]]),
            FakeHttp::json(['rows' => []]),
            FakeHttp::json(['rows' => []]),
            // Search Console order: clicks, then alphabetically
            FakeHttp::json(['rows' => [
                ['keys' => ['https://alpha.example/'], 'clicks' => 5, 'impressions' => 90],
                ['keys' => ['https://alpha.example/a'], 'clicks' => 0, 'impressions' => 3],
                ['keys' => ['https://alpha.example/b'], 'clicks' => 0, 'impressions' => 40],
            ]]),
            FakeHttp::json(['rows' => [
                ['keys' => ['abnuu'], 'clicks' => 0, 'impressions' => 3],
                ['keys' => ['agentur technik'], 'clicks' => 0, 'impressions' => 19],
            ]]),
        );

        $result = $service->dashboard('alpha', ['period' => 'range', 'date' => 'last28', 'searchType' => 'web']);

        self::assertSame(['https://alpha.example/', 'https://alpha.example/b'], array_column($result['topPages'], 'page'));
        self::assertSame(['agentur technik', 'abnuu'], array_column($result['topQueries'], 'query'));
        $pagesBody = json_decode((string)$http->history[3]['request']->getBody(), true);
        self::assertSame(['page'], $pagesBody['dimensions']);
        self::assertSame(25000, $pagesBody['rowLimit']);
        self::assertCount(5, $http->history, 'no separate top queries request');
    }

    #[Test]
    public function pageFiguresUseTheGivenRangeAndMatchThePath(): void
    {
        $http = new FakeHttp($this);
        $settings = $this->settings();
        $settings->set('alpha', 'gsc_site_url', 'sc-domain:alpha.example');
        $account = $this->createMock(GoogleServiceAccount::class);
        $account->method('accessToken')->willReturn('token');
        $client = new SearchConsoleClient($account, $settings, $http->factory());
        $service = new GscDashboardService($client, $settings, $this->cache(), new SnapshotRepository($this->db->connectionPool()), new MatomoClient($settings, $http->factory()), new Sources(new ExtensionConfiguration()));
        $http->queue(
            FakeHttp::json(['rows' => [['clicks' => 12, 'impressions' => 300, 'position' => 4.2]]]),
            FakeHttp::json(['rows' => [['keys' => ['licht'], 'clicks' => 10, 'impressions' => 200]]]),
        );
        $end = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->modify('-3 days');
        $start = $end->modify('-6 days');

        $result = $service->page('alpha', ['url' => 'https://alpha.ddev.site/news/a', 'start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')]);

        self::assertSame(12, $result['clicks']);
        self::assertSame(['licht'], array_column($result['queries'], 'query'));
        self::assertSame(['clicks' => 2, 'impressions' => 100], $result['anonymized']);
        $body = json_decode((string)$http->history[1]['request']->getBody(), true);
        self::assertSame([$start->format('Y-m-d'), $end->format('Y-m-d'), 'web'], [$body['startDate'], $body['endDate'], $body['type']]);
        self::assertSame('^https?://[^/]+/news/a/?$', $body['dimensionFilterGroups'][0]['filters'][0]['expression']);
    }

    #[Test]
    public function pageFiguresCanBeComparedWithThePeriodBefore(): void
    {
        $http = new FakeHttp($this);
        $settings = $this->settings();
        $settings->set('alpha', 'gsc_site_url', 'sc-domain:alpha.example');
        $account = $this->createMock(GoogleServiceAccount::class);
        $account->method('accessToken')->willReturn('token');
        $client = new SearchConsoleClient($account, $settings, $http->factory());
        $service = new GscDashboardService($client, $settings, $this->cache(), new SnapshotRepository($this->db->connectionPool()), new MatomoClient($settings, $http->factory()), new Sources(new ExtensionConfiguration()));
        $http->queue(
            FakeHttp::json(['rows' => [['clicks' => 12, 'impressions' => 300, 'position' => 4.2]]]),
            FakeHttp::json(['rows' => []]),
            FakeHttp::json(['rows' => [['clicks' => 8, 'impressions' => 200, 'position' => 5.0]]]),
        );
        $end = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->modify('-3 days');
        $start = $end->modify('-6 days');

        $result = $service->page('alpha', ['url' => '/news/a', 'start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'), 'compare' => '1']);

        self::assertSame(8, $result['compare']['clicks']);
        self::assertSame(['startDate' => $start->modify('-7 days')->format('Y-m-d'), 'endDate' => $start->modify('-1 day')->format('Y-m-d')], $result['compareRange']);
        $body = json_decode((string)$http->history[2]['request']->getBody(), true);
        self::assertSame($result['compareRange'], ['startDate' => $body['startDate'], 'endDate' => $body['endDate']]);
    }

    #[Test]
    public function explicitRangesStayInsideTheDataOfSearchConsole(): void
    {
        $today = new \DateTimeImmutable('2026-10-28', new \DateTimeZone('UTC'));
        self::assertSame(['startDate' => '2026-10-01', 'endDate' => '2026-10-29'], GscDashboardService::explicitRange('2026-10-01', '2026-10-29', $today), 'one day ahead of UTC');
        self::assertNull(GscDashboardService::explicitRange('2026-10-01', '2026-10-30', $today));
        self::assertNull(GscDashboardService::explicitRange('2026-10-20', '2026-10-01', $today));
        self::assertNull(GscDashboardService::explicitRange('2025-06-01', '2026-10-01', $today));
        self::assertNull(GscDashboardService::explicitRange('2026-02-31', '2026-03-01', $today));
    }
}
