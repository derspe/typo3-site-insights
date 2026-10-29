<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Tests\Unit\Service\News;

use Derspe\SiteInsights\Domain\Repository\SnapshotRepository;
use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Service\Gsc\GoogleServiceAccount;
use Derspe\SiteInsights\Service\Gsc\GscDashboardService;
use Derspe\SiteInsights\Service\Gsc\SearchConsoleClient;
use Derspe\SiteInsights\Service\Matomo\MatomoClient;
use Derspe\SiteInsights\Service\News\NewsInsightsService;
use Derspe\SiteInsights\Service\News\NewsRepository;
use Derspe\SiteInsights\Service\News\NewsUrlBuilder;
use Derspe\SiteInsights\Service\Page\PageInsightsService;
use Derspe\SiteInsights\Settings\Sources;
use Derspe\SiteInsights\Tests\Unit\Fixtures\FakeHttp;
use Derspe\SiteInsights\Tests\Unit\Fixtures\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

final class NewsInsightsServiceTest extends UnitTestCase
{
    private const FOLDER = 20;
    // Page of the other site
    private const BETA_PAGE = 99;

    private FakeHttp $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new FakeHttp($this);
        $GLOBALS['TCA'][NewsRepository::TABLE] = ['ctrl' => ['versioningWS' => true]];
        $this->db->tables[NewsRepository::TABLE] = [
            $this->news(1, 'Older', 100),
            $this->news(2, 'Newer', 200),
            $this->news(3, 'External link', 300, ['type' => '2']),
            $this->news(4, 'Other folder', 400, ['pid' => 21]),
            $this->news(5, 'Workspace version', 500, ['t3ver_wsid' => 1]),
            $this->news(6, 'Translation of older', 100, ['sys_language_uid' => 1, 'l10n_parent' => 1]),
            $this->news(7, 'All languages', 50, ['sys_language_uid' => -1]),
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function news(int $uid, string $title, int $datetime, array $override = []): array
    {
        return $override + [
            'uid' => $uid,
            'pid' => self::FOLDER,
            'title' => $title,
            'datetime' => $datetime,
            'type' => '0',
            'sys_language_uid' => 0,
            'l10n_parent' => 0,
            't3ver_wsid' => 0,
        ];
    }

    private function configure(bool $matomo = true, bool $gsc = true, int $detailPid = 10): void
    {
        $settings = $this->settings();
        if ($matomo) {
            $settings->set('alpha', 'matomo_url', 'https://matomo.example.com');
            $settings->set('alpha', 'matomo_token', 'tok');
            $settings->set('alpha', 'matomo_site_id', '3');
        }
        if ($gsc) {
            $settings->set('alpha', 'gsc_site_url', 'sc-domain:alpha.example');
        }
        if ($detailPid > 0) {
            $settings->set('alpha', 'news_detail_pid', $detailPid);
        }
    }

    private function service(): NewsInsightsService
    {
        $settings = $this->settings();
        $cache = $this->cache();
        $sources = new Sources(new ExtensionConfiguration());
        $alpha = self::site('alpha', 1);
        $finder = $this->createMock(SiteFinder::class);
        $finder->method('getSiteByPageId')->willReturnCallback(fn(int $id): Site => $id === self::BETA_PAGE ? self::site('beta', 2) : $alpha);
        $account = $this->createMock(GoogleServiceAccount::class);
        $account->method('accessToken')->willReturn('token');
        $account->method('isConfigured')->willReturn(true);
        $client = new SearchConsoleClient($account, $settings, $this->http->factory());
        $matomo = new MatomoClient($settings, $this->http->factory());
        $gsc = new GscDashboardService($client, $settings, $cache, new SnapshotRepository($this->db->connectionPool()), $matomo, $sources);
        $pageInsights = new PageInsightsService($finder, $matomo, $client, $gsc, $cache, $account, $settings, $sources);
        $urls = $this->createMock(NewsUrlBuilder::class);
        $urls->method('detailUrl')->willReturnCallback(static fn(Site $site, int $pid, int $uid, int $language): string => sprintf('https://alpha.example/%snews/detail/item-%d', $language > 0 ? 'de/' : '', $uid));
        $urls->method('editUrl')->willReturnCallback(static fn(int $uid, string $returnUrl): string => '/typo3/record/edit/' . $uid . '?returnUrl=' . $returnUrl);
        return new NewsInsightsService($finder, $settings, new NewsRepository($this->db->connectionPool()), $urls, $pageInsights, $client, $gsc, $matomo, $cache);
    }

    #[Test]
    public function figuresOfAllRecordsComeFromOneReportPerSourceAndPeriod(): void
    {
        $this->configure();
        $this->http->queue(
            // Matomo: both periods in one bulk request; flat labels without leading slash, query strings
            FakeHttp::json([
                [
                    ['label' => 'news/detail/item-2', 'nb_hits' => 50, 'entry_nb_visits' => 20],
                    ['label' => '/news/detail/item-2?utm_source=x', 'nb_hits' => 5, 'entry_nb_visits' => 1],
                    ['label' => 'news/detail/item-1', 'nb_hits' => 8, 'entry_nb_visits' => 2],
                ],
                [['label' => 'news/detail/item-2', 'nb_hits' => 40, 'entry_nb_visits' => 25]],
            ]),
            FakeHttp::json(['rows' => [['keys' => ['2026-10-19']]]]),
            FakeHttp::json(['rows' => [
                ['keys' => ['https://alpha.example/news/detail/item-2'], 'clicks' => 10, 'impressions' => 100, 'position' => 4.0],
                ['keys' => ['https://alpha.example/news/detail/item-2/'], 'clicks' => 2, 'impressions' => 100, 'position' => 8.0],
            ]]),
            FakeHttp::json(['rows' => [
                ['keys' => ['https://alpha.example/news/detail/item-2'], 'clicks' => 6, 'impressions' => 50, 'position' => 5.0],
                ['keys' => ['https://alpha.example/news/detail/item-1'], 'clicks' => 3, 'impressions' => 30, 'position' => 9.0],
            ]]),
        );

        $result = $this->service()->overview('alpha', self::FOLDER, 0, '/typo3/module/web/list');

        self::assertTrue($result['detailPage']);
        self::assertSame(['Newer', 'Older', 'All languages'], array_column($result['records'], 'title'), 'newest first, no links, other folders or versions');
        [$newer, $older, $all] = $result['records'];
        self::assertSame('https://alpha.example/news/detail/item-2', $newer['url']);
        self::assertSame('/typo3/record/edit/2?returnUrl=/typo3/module/web/list', $newer['editUrl']);
        self::assertSame(['pageviews' => 55, 'entries' => 21, 'compare' => ['pageviews' => 40, 'entries' => 25]], $newer['matomo']);
        self::assertSame(12, $newer['gsc']['clicks']);
        self::assertSame(200, $newer['gsc']['impressions']);
        self::assertEqualsWithDelta(6.0, $newer['gsc']['position'], 0.001, 'variants with and without slash are one page');
        self::assertSame(6, $newer['gsc']['compare']['clicks']);
        self::assertSame(0, $older['gsc']['impressions']);
        self::assertSame(3, $older['gsc']['compare']['clicks']);
        self::assertSame(0, $all['matomo']['pageviews'], 'records without data get zeros');

        self::assertSame(['pageviews' => 63, 'entries' => 23, 'compare' => ['pageviews' => 40, 'entries' => 25]], $result['totals']['matomo']);
        self::assertSame(12, $result['totals']['gsc']['clicks']);
        self::assertSame(9, $result['totals']['gsc']['compare']['clicks']);
        self::assertEqualsWithDelta((5 * 50 + 9 * 30) / 80, $result['totals']['gsc']['compare']['position'], 0.001);
        self::assertArrayNotHasKey('pages', $result['gsc']);
        self::assertSame('2026-10-19', $result['gsc']['dataDate']);

        self::assertCount(4, $this->http->history, 'one Matomo bulk request, latest date, two Search Console queries');
        parse_str($this->http->formFields(0)['urls'][0], $matomoCall);
        self::assertSame(['Actions.getPageUrls', '1', '^/?news/detail'], [$matomoCall['method'], $matomoCall['flat'], $matomoCall['filter_pattern']]);
        $body = json_decode((string)$this->http->history[2]['request']->getBody(), true);
        self::assertSame(['page'], $body['dimensions']);
        self::assertSame('^https?://[^/]+/news/detail/', $body['dimensionFilterGroups'][0]['filters'][0]['expression']);
    }

    #[Test]
    public function translatedRecordsLinkTheDetailViewOfTheirDefaultLanguageRecord(): void
    {
        $this->configure(matomo: false, gsc: false);
        $result = $this->service()->overview('alpha', self::FOLDER, 1, '');
        self::assertSame(['Translation of older', 'All languages'], array_column($result['records'], 'title'));
        self::assertSame(6, $result['records'][0]['uid'], 'edit the translation');
        self::assertSame('https://alpha.example/de/news/detail/item-1', $result['records'][0]['url']);
        self::assertSame([], $this->http->history);
    }

    #[Test]
    public function unconfiguredSourcesAreLeftOut(): void
    {
        $this->configure(gsc: false);
        $this->http->queue(FakeHttp::json([[], []]));
        $result = $this->service()->overview('alpha', self::FOLDER, 0, '');
        self::assertSame(['configured' => false], $result['gsc']);
        self::assertArrayNotHasKey('gsc', $result['records'][0]);
        self::assertArrayHasKey('matomo', $result['records'][0]);
    }

    #[Test]
    public function errorsAreReportedPerSource(): void
    {
        $this->configure(gsc: false);
        $this->http->queue(FakeHttp::json([[], ['result' => 'error', 'message' => 'no access']]));
        $result = $this->service()->overview('alpha', self::FOLDER, 0, '');
        self::assertStringContainsString('no access', $result['matomo']['error']);
        self::assertArrayNotHasKey('matomo', $result['records'][0]);
    }

    #[Test]
    public function withoutDetailPageNothingIsRequested(): void
    {
        $this->configure(detailPid: 0);
        self::assertSame(['detailPage' => false], $this->service()->overview('alpha', self::FOLDER, 0, ''));
        self::assertSame([], $this->http->history);
    }

    #[Test]
    public function theDetailPageMustBelongToTheSite(): void
    {
        $this->configure(detailPid: self::BETA_PAGE);
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);
        $this->service()->overview('alpha', self::FOLDER, 0, '');
    }

    #[Test]
    public function foldersOfOtherSitesAreRejected(): void
    {
        $this->configure();
        $this->expectExceptionCode(403);
        $this->service()->overview('alpha', self::BETA_PAGE, 0, '');
    }

    #[Test]
    public function urlPrefixIsTheCommonDirectory(): void
    {
        self::assertSame('https://a.example/news/detail/', NewsInsightsService::urlPrefix(['https://a.example/news/detail/abc', 'https://a.example/news/detail/abd']));
        self::assertSame('https://a.example/', NewsInsightsService::urlPrefix(['https://a.example/news/x', 'https://a.example/blog/y']));
        self::assertSame('https://a.example/news/', NewsInsightsService::urlPrefix(['https://a.example/news/x']));
        self::assertNull(NewsInsightsService::urlPrefix(['https://a.example/x', 'https://b.example/x']));
        self::assertNull(NewsInsightsService::urlPrefix([]));
    }

    #[Test]
    public function pathKeyIgnoresHostQueryAndSlashes(): void
    {
        self::assertSame('/news/über', NewsInsightsService::pathKey('https://a.example/news/%C3%BCber/'));
        self::assertSame('/news/über', NewsInsightsService::pathKey('news/über?utm=1'));
        self::assertSame('/', NewsInsightsService::pathKey('https://a.example/'));
    }
}
