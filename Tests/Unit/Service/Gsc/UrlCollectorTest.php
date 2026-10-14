<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Tests\Unit\Service\Gsc;

use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Service\Gsc\GscDashboardService;
use Derspe\SiteInsights\Service\Gsc\UrlCollector;
use Derspe\SiteInsights\Tests\Unit\Fixtures\FakeHttp;
use Derspe\SiteInsights\Tests\Unit\Fixtures\UnitTestCase;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;

final class UrlCollectorTest extends UnitTestCase
{
    private FakeHttp $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new FakeHttp($this);
    }

    private function collector(): UrlCollector
    {
        return new UrlCollector($this->settings(), $this->siteFinder([self::site('alpha', 1, 'https://www.example.com/')]), $this->http->factory(), new NullLogger());
    }

    private static function xml(string $body): Response
    {
        return new Response(200, ['Content-Type' => 'application/xml'], '<?xml version="1.0"?>' . $body);
    }

    #[Test]
    public function sitemapUrlDefaultsToTheSiteBase(): void
    {
        self::assertSame('https://www.example.com/sitemap.xml', $this->collector()->sitemapUrl('alpha'));
    }

    #[Test]
    public function childSitemapsOnOtherHostsAreNeverRequested(): void
    {
        $this->http->queue(
            self::xml('<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                . '<sitemap><loc>https://www.example.com/pages.xml</loc></sitemap>'
                . '<sitemap><loc>http://169.254.169.254/latest/meta-data/</loc></sitemap>'
                . '<sitemap><loc>http://localhost:6379/</loc></sitemap>'
                . '<sitemap><loc>file:///etc/passwd</loc></sitemap>'
                . '</sitemapindex>'),
            self::xml('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://www.example.com/a</loc></url></urlset>')
        );
        $urls = $this->collector()->sitemapUrls('https://www.example.com/sitemap.xml');
        self::assertSame(['https://www.example.com/a'], $urls);
        self::assertSame(['https://www.example.com/sitemap.xml', 'https://www.example.com/pages.xml'], $this->http->requestedUrls());
    }

    #[Test]
    public function externalEntitiesAreNotResolved(): void
    {
        $this->http->queue(self::xml('<!DOCTYPE urlset [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://www.example.com/&xxe;</loc></url></urlset>'));
        $urls = $this->collector()->sitemapUrls('https://www.example.com/sitemap.xml');
        self::assertStringNotContainsString('root:', implode(' ', $urls));
    }

    #[Test]
    public function oversizedResponsesAreRejected(): void
    {
        $this->http->queue(new Response(200, [], new PumpStream(static fn(): string => str_repeat('x', 65536))));
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('too large');
        $this->collector()->sitemapUrls('https://www.example.com/sitemap.xml');
    }

    #[Test]
    public function numberOfChildSitemapsIsLimited(): void
    {
        $children = '';
        for ($i = 0; $i < UrlCollector::MAX_CHILD_SITEMAPS + 20; $i++) {
            $children .= '<sitemap><loc>https://www.example.com/s' . $i . '.xml</loc></sitemap>';
        }
        $this->http->queue(self::xml('<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . $children . '</sitemapindex>'));
        for ($i = 0; $i < UrlCollector::MAX_CHILD_SITEMAPS; $i++) {
            $this->http->queue(self::xml('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://www.example.com/p' . $i . '</loc></url></urlset>'));
        }
        $urls = $this->collector()->sitemapUrls('https://www.example.com/sitemap.xml');
        self::assertCount(UrlCollector::MAX_CHILD_SITEMAPS, $urls);
        self::assertCount(UrlCollector::MAX_CHILD_SITEMAPS + 1, $this->http->history);
    }

    #[Test]
    public function pageLinksAreInternalCleanAndFiltered(): void
    {
        $this->http->queue(new Response(200, [], '<a href="/products/a?x=1#top">a</a><a href="https://www.example.com/products/b">b</a>'
            . '<a href="https://evil.example/products/c">c</a><a href="/blog/d">d</a><a href="javascript:alert(1)">e</a><a href="mailto:x@y">f</a>'));
        self::assertSame(
            ['https://www.example.com/products/a', 'https://www.example.com/products/b'],
            $this->collector()->pageLinks('https://www.example.com/products', '/products/')
        );
    }

    #[Test]
    public function sameHostFilterRejectsOtherSchemesAndHosts(): void
    {
        self::assertSame(['https://www.example.com/x', 'http://WWW.example.com/y'], UrlCollector::sameHost([
            'https://www.example.com/x',
            'http://WWW.example.com/y',
            'ftp://www.example.com/z',
            'https://example.com/a',
            'https://www.example.com.evil.example/b',
        ], 'www.example.com'));
    }

    #[Test]
    public function countryAndDeviceFiltersAreWhitelisted(): void
    {
        self::assertSame('deu', GscDashboardService::country('DEU'));
        self::assertSame('', GscDashboardService::country('deutschland'));
        self::assertSame('', GscDashboardService::country(['x']));
        self::assertSame('MOBILE', GscDashboardService::device('mobile'));
        self::assertSame('', GscDashboardService::device('FRIDGE'));
    }
}
