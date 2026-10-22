<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Tests\Unit\Service\Page;

use Derspe\SiteInsights\Domain\Repository\SnapshotRepository;
use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Service\Gsc\GoogleServiceAccount;
use Derspe\SiteInsights\Service\Gsc\GscDashboardService;
use Derspe\SiteInsights\Service\Gsc\SearchConsoleClient;
use Derspe\SiteInsights\Service\Matomo\MatomoClient;
use Derspe\SiteInsights\Service\Page\PageInsightsService;
use Derspe\SiteInsights\Tests\Unit\Fixtures\FakeHttp;
use Derspe\SiteInsights\Tests\Unit\Fixtures\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;

final class PageInsightsServiceTest extends UnitTestCase
{
    private const URL = 'https://alpha.example/smart-home/';

    private FakeHttp $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new FakeHttp($this);
    }

    private function configureMatomo(): void
    {
        $settings = $this->settings();
        $settings->set('alpha', 'matomo_url', 'https://matomo.example.com');
        $settings->set('alpha', 'matomo_token', 'tok');
        $settings->set('alpha', 'matomo_site_id', '3');
    }

    private function service(?SiteFinder $siteFinder = null): PageInsightsService
    {
        $settings = $this->settings();
        $cache = $this->cache();
        $account = $this->createMock(GoogleServiceAccount::class);
        $account->method('accessToken')->willReturn('token');
        $client = new SearchConsoleClient($account, $settings, $this->http->factory());
        $matomo = new MatomoClient($settings, $this->http->factory());
        $gsc = new GscDashboardService($client, $settings, $cache, new SnapshotRepository($this->db->connectionPool()), $matomo);
        return new PageInsightsService($siteFinder ?? $this->siteFinder([]), $matomo, $client, $gsc, $cache);
    }

    /**
     * @return array<string, mixed>
     */
    private function gscBody(int $index): array
    {
        return json_decode((string)$this->http->history[$index]['request']->getBody(), true);
    }

    #[Test]
    public function matomoAndSearchConsoleFiguresOfThePage(): void
    {
        $this->configureMatomo();
        $this->settings()->set('alpha', 'gsc_site_url', 'sc-domain:alpha.example');
        $this->http->queue(
            FakeHttp::json([[['label' => '/smart-home', 'nb_hits' => 120, 'entry_nb_visits' => 45]], [['nb_hits' => 100, 'entry_nb_visits' => 50]]]),
            FakeHttp::json(['rows' => [['keys' => ['2026-10-18']], ['keys' => ['2026-10-19']]]]),
            FakeHttp::json(['rows' => [['clicks' => 30, 'impressions' => 900, 'position' => 6.5]]]),
            FakeHttp::json(['rows' => [
                ['keys' => ['smart home'], 'clicks' => 20, 'impressions' => 400, 'ctr' => 0.05, 'position' => 4.0],
                ['keys' => ['alarm'], 'clicks' => 0, 'impressions' => 50],
                ['keys' => ['licht'], 'clicks' => 0, 'impressions' => 200],
            ]]),
            FakeHttp::json(['rows' => [['clicks' => 24, 'impressions' => 800, 'position' => 7.0]]]),
        );

        $result = $this->service()->insights('alpha', self::URL);

        self::assertSame(120, $result['matomo']['pageviews']);
        self::assertSame(45, $result['matomo']['entries']);
        self::assertSame(['pageviews' => 100, 'entries' => 50], $result['matomo']['compare']);
        $yesterday = new \DateTimeImmutable('yesterday');
        self::assertSame($yesterday->format('Y-m-d'), $result['matomo']['range']['endDate'], 'full days only');
        self::assertSame($yesterday->modify('-28 days')->format('Y-m-d'), $result['matomo']['compareRange']['endDate']);
        self::assertSame($yesterday->modify('-55 days')->format('Y-m-d'), $result['matomo']['compareRange']['startDate']);
        $form = $this->http->formFields(0);
        self::assertSame('API.getBulkRequest', $form['method']);
        parse_str($form['urls'][0], $sub);
        self::assertSame(['Actions.getPageUrl', self::URL, 'range'], [$sub['method'], $sub['pageUrl'], $sub['period']]);

        self::assertSame(30, $result['gsc']['clicks']);
        self::assertSame(900, $result['gsc']['impressions']);
        self::assertSame(['startDate' => '2026-09-22', 'endDate' => '2026-10-19'], $result['gsc']['range']);
        self::assertSame(['smart home', 'licht', 'alarm'], array_column($result['gsc']['queries'], 'query'), 'ties sorted by impressions');
        self::assertSame(['clicks' => 10, 'impressions' => 250], $result['gsc']['anonymized']);
        self::assertCount(5, $this->http->history, 'Matomo, latest date and three Search Console queries');
        self::assertSame(24, $result['gsc']['compare']['clicks']);
        self::assertSame(['startDate' => '2026-08-25', 'endDate' => '2026-09-21'], $result['gsc']['compareRange']);
        self::assertSame(['startDate' => '2026-08-25', 'endDate' => '2026-09-21'], array_intersect_key($this->gscBody(4), ['startDate' => 1, 'endDate' => 1]));

        $kpis = $this->gscBody(2);
        $queries = $this->gscBody(3);
        self::assertSame([], $kpis['dimensions']);
        self::assertSame(['query'], $queries['dimensions']);
        self::assertSame(25000, $queries['rowLimit']);
        $filter = $queries['dimensionFilterGroups'][0]['filters'][0];
        self::assertSame(['page', 'includingRegex'], [$filter['dimension'], $filter['operator']]);
        self::assertStringEndsWith('smart\\-home/?$', $filter['expression']);
    }

    #[Test]
    public function aMissingSourceDoesNotHideTheOther(): void
    {
        $this->configureMatomo();
        $this->http->queue(FakeHttp::json([[['nb_hits' => 7, 'entry_nb_visits' => 2]], []]));

        $result = $this->service()->insights('alpha', self::URL);

        self::assertSame(7, $result['matomo']['pageviews']);
        self::assertSame(503, $result['gsc']['status']);
        self::assertCount(1, $this->http->history, 'no Search Console request without property');
    }

    #[Test]
    public function unconfiguredMatomoIsReportedAsNotConfigured(): void
    {
        $result = $this->service()->insights('alpha', self::URL);
        self::assertSame(503, $result['matomo']['status']);
        self::assertSame([], $this->http->history);
    }

    #[Test]
    public function matomoErrorsAreReportedPerSource(): void
    {
        $this->configureMatomo();
        $this->http->queue(FakeHttp::json([[], ['result' => 'error', 'message' => 'no access']]));
        $result = $this->service()->insights('alpha', self::URL);
        self::assertSame(502, $result['matomo']['status']);
        self::assertStringContainsString('no access', $result['matomo']['error']);
    }

    #[Test]
    public function resultsAreCached(): void
    {
        $this->configureMatomo();
        $this->http->queue(FakeHttp::json([[['nb_hits' => 7]], []]));
        $this->service()->insights('alpha', self::URL);
        $this->service()->insights('alpha', self::URL);
        self::assertCount(1, $this->http->history);
    }

    #[Test]
    public function pagesOfOtherSitesAreRejected(): void
    {
        $finder = $this->createMock(SiteFinder::class);
        $finder->method('getSiteByPageId')->willReturn(self::site('beta', 2));
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(403);
        $this->service($finder)->pageUrl('alpha', 5, 0);
    }

    #[Test]
    public function unknownPagesAreNotFound(): void
    {
        $finder = $this->createMock(SiteFinder::class);
        $finder->method('getSiteByPageId')->willThrowException(new SiteNotFoundException('none', 1));
        $this->expectExceptionCode(404);
        $this->service($finder)->pageUrl('alpha', 5, 0);
    }
}
