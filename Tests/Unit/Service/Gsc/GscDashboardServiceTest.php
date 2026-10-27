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
}
