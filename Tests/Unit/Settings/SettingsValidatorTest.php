<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Tests\Unit\Settings;

use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Settings\SettingsValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SettingsValidatorTest extends TestCase
{
    private SettingsValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SettingsValidator();
    }

    #[Test]
    public function unknownKeysAreIgnored(): void
    {
        self::assertSame([], $this->validator->validate(['gsc_refresh_token' => 'x', 'gsc_inspect_quota' => ['count' => 0], 'foo' => 'bar']));
    }

    #[Test]
    public function emptySecretKeepsTheStoredValue(): void
    {
        self::assertSame([], $this->validator->validate(['matomo_token' => '  ', 'gsc_oauth_client_secret' => '']));
        self::assertSame(['matomo_token' => 'abc'], $this->validator->validate(['matomo_token' => ' abc ']));
    }

    #[Test]
    public function emptyValuesRemoveSettings(): void
    {
        self::assertSame(['matomo_url' => null, 'gsc_brand_terms' => null, 'gsc_site_url' => null], $this->validator->validate([
            'matomo_url' => '',
            'gsc_brand_terms' => ' , ',
            'gsc_site_url' => ' ',
        ]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUrls(): iterable
    {
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'file' => ['file:///etc/passwd'];
        yield 'gopher' => ['gopher://127.0.0.1:6379/_x'];
        yield 'ftp' => ['ftp://example.com/sitemap.xml'];
        yield 'no scheme' => ['example.com'];
    }

    #[Test]
    #[DataProvider('invalidUrls')]
    public function onlyHttpUrlsAreAccepted(string $url): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);
        $this->validator->validate(['matomo_url' => $url]);
    }

    #[Test]
    #[DataProvider('invalidUrls')]
    public function sourceUrlListsOnlyAcceptHttpUrls(string $url): void
    {
        $this->expectExceptionCode(400);
        $this->validator->validate(['gsc_extra_source_urls' => ['https://example.com/a', $url]]);
    }

    #[Test]
    public function urlsAreNormalized(): void
    {
        self::assertSame(['matomo_url' => 'https://matomo.example.com'], $this->validator->validate(['matomo_url' => ' https://matomo.example.com/ ']));
    }

    #[Test]
    public function integersAreRangeChecked(): void
    {
        self::assertSame(['gsc_top_n' => 50], $this->validator->validate(['gsc_top_n' => '50']));
        $this->expectExceptionCode(400);
        $this->validator->validate(['gsc_snapshot_top_n' => 100000]);
    }

    #[Test]
    public function listsAreTrimmedDeduplicatedAndLimited(): void
    {
        self::assertSame(['gsc_brand_terms' => ['acme', 'acme shop']], $this->validator->validate(['gsc_brand_terms' => 'acme, acme shop ,acme,']));
        $long = $this->validator->validate(['gsc_brand_terms' => array_map('strval', range(1, 500))]);
        self::assertCount(100, $long['gsc_brand_terms']);
    }

    #[Test]
    public function nonScalarValuesAreIgnored(): void
    {
        self::assertSame([], $this->validator->validate(['matomo_site_id' => new \stdClass()]));
    }

    #[Test]
    public function originComparesSchemeHostAndPort(): void
    {
        self::assertSame('https://matomo.example.com', SettingsValidator::origin('https://Matomo.example.com/sub/'));
        self::assertSame('https://matomo.example.com:8443', SettingsValidator::origin('https://matomo.example.com:8443'));
        self::assertNotSame(SettingsValidator::origin('https://a.example'), SettingsValidator::origin('http://a.example'));
        self::assertSame('', SettingsValidator::origin('not a url'));
    }
}
