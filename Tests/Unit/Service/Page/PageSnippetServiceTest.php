<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Tests\Unit\Service\Page;

use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Service\News\NewsRepository;
use Derspe\SiteInsights\Service\Page\PageSnippetService;
use Derspe\SiteInsights\Tests\Unit\Fixtures\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Routing\SiteMatcher;

final class PageSnippetServiceTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TCA']['pages'] = ['ctrl' => ['versioningWS' => true]];
        $this->db->tables['pages'] = [
            ['uid' => 5, 'title' => 'Smart Home', 'seo_title' => 'Smart Home – Guide', 'description' => "Tips  and\ntricks", 'sys_language_uid' => 0, 'l10n_parent' => 0, 't3ver_wsid' => 0],
            ['uid' => 6, 'title' => 'Smart Home EN', 'seo_title' => '', 'description' => '', 'sys_language_uid' => 1, 'l10n_parent' => 5, 't3ver_wsid' => 0],
            ['uid' => 7, 'title' => 'Draft', 'seo_title' => '', 'description' => '', 'sys_language_uid' => 1, 'l10n_parent' => 8, 't3ver_wsid' => 1],
            ['uid' => 8, 'title' => 'Live', 'seo_title' => '', 'description' => 'Live page', 'sys_language_uid' => 0, 'l10n_parent' => 0, 't3ver_wsid' => 0],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']);
        parent::tearDown();
    }

    private function service(): PageSnippetService
    {
        return new PageSnippetService($this->siteFinder([self::site('alpha', 1)]), $this->createMock(SiteMatcher::class), $this->db->connectionPool());
    }

    #[Test]
    public function theSeoTitleOfThePageComesFirst(): void
    {
        self::assertSame(
            ['type' => 'page', 'title' => 'Smart Home – Guide', 'description' => 'Tips and tricks'],
            $this->service()->forRoute(5, 0, [])
        );
    }

    #[Test]
    public function translationsFallBackToTheirTitle(): void
    {
        self::assertSame(['type' => 'page', 'title' => 'Smart Home EN', 'description' => ''], $this->service()->forRoute(5, 1, []));
    }

    #[Test]
    public function workspaceVersionsAreIgnored(): void
    {
        self::assertNull($this->service()->forRoute(8, 1, []), 'no live translation, the default record is not in that language');
        self::assertSame('Live page', $this->service()->forRoute(8, 0, [])['description']);
    }

    #[Test]
    public function newsDetailRoutesShowTheNewsRecord(): void
    {
        $GLOBALS['TCA'][NewsRepository::TABLE] = ['ctrl' => []];
        $this->db->tables[NewsRepository::TABLE] = [
            ['uid' => 12, 'title' => 'Matter is here', 'alternative_title' => '', 'description' => 'What changes', 'sys_language_uid' => 0, 'l10n_parent' => 0],
            ['uid' => 13, 'title' => 'Alle Sprachen', 'alternative_title' => 'Für alle', 'description' => '', 'teaser' => '', 'sys_language_uid' => -1, 'l10n_parent' => 0],
            ['uid' => 14, 'title' => 'Teaser only', 'alternative_title' => '', 'description' => '', 'teaser' => "<p>Short &amp; <b>sweet</b>\n</p>", 'sys_language_uid' => 0, 'l10n_parent' => 0],
        ];
        $route = static fn(int $uid): array => ['tx_news_pi1' => ['action' => 'detail', 'controller' => 'News', 'news' => (string)$uid]];

        self::assertSame(['type' => 'news', 'title' => 'Matter is here', 'description' => 'What changes'], $this->service()->forRoute(5, 0, $route(12)));
        self::assertSame(['type' => 'news', 'title' => 'Für alle', 'description' => ''], $this->service()->forRoute(5, 1, $route(13)));
        self::assertSame('Short & sweet', $this->service()->forRoute(5, 0, $route(14))['description'], 'the teaser stands in for the description');

        $this->db->tables[NewsRepository::TABLE][2]['teaser'] = str_repeat('Wörter ', 60);
        $cropped = $this->service()->forRoute(5, 0, $route(14))['description'];
        self::assertLessThanOrEqual(PageSnippetService::TEASER_LENGTH, mb_strlen($cropped));
        self::assertStringEndsWith('Wörter…', $cropped, 'cut at a word boundary');
        self::assertSame('page', $this->service()->forRoute(5, 0, $route(99))['type'], 'unknown news: the detail page');
    }

    #[Test]
    public function invalidUrlsAreRejected(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);
        $this->service()->forUrl('alpha', 'javascript:alert(1)');
    }
}
