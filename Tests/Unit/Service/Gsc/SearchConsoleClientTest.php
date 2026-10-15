<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Tests\Unit\Service\Gsc;

use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Service\Gsc\GoogleServiceAccount;
use Derspe\SiteInsights\Service\Gsc\SearchConsoleClient;
use Derspe\SiteInsights\Tests\Unit\Fixtures\FakeHttp;
use Derspe\SiteInsights\Tests\Unit\Fixtures\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class SearchConsoleClientTest extends UnitTestCase
{
    private FakeHttp $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new FakeHttp($this);
        $this->settings()->set('alpha', 'gsc_site_url', 'sc-domain:example.com');
    }

    private function client(): SearchConsoleClient
    {
        $account = $this->createMock(GoogleServiceAccount::class);
        $account->method('accessToken')->willReturn('token-1');
        return new SearchConsoleClient($account, $this->settings(), $this->http->factory());
    }

    #[Test]
    public function propertyIsUrlEncodedAndTokenSentAsBearer(): void
    {
        $this->http->queue(FakeHttp::json(['rows' => []]));
        $this->client()->searchAnalytics('alpha', ['startDate' => '2026-01-01', 'dimensionFilterGroups' => null]);
        $request = $this->http->history[0]['request'];
        self::assertSame('https://searchconsole.googleapis.com/webmasters/v3/sites/sc-domain%3Aexample.com/searchAnalytics/query', (string)$request->getUri());
        self::assertSame('Bearer token-1', $request->getHeaderLine('Authorization'));
        // null values are not sent
        self::assertSame(['startDate' => '2026-01-01'], json_decode((string)$request->getBody(), true));
    }

    #[Test]
    public function missingPropertyMeansNotConfigured(): void
    {
        $this->expectExceptionCode(503);
        $this->client()->searchAnalytics('beta', []);
    }

    /**
     * @return iterable<string, array{int, array<string, mixed>, int}>
     */
    public static function errors(): iterable
    {
        yield 'quota' => [429, ['error' => ['message' => 'Quota']], 429];
        yield 'resource exhausted' => [403, ['error' => ['status' => 'RESOURCE_EXHAUSTED', 'message' => 'x']], 429];
        yield 'token revoked' => [401, ['error' => ['message' => 'Invalid Credentials']], 503];
        yield 'bad request' => [400, ['error' => ['message' => 'Invalid property']], 502];
        yield 'no permission for property' => [403, ['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'User does not have sufficient permission']], 503];
        yield 'api not enabled' => [403, ['error' => ['status' => 'PERMISSION_DENIED', 'details' => [['reason' => 'SERVICE_DISABLED']]]], 503];
    }

    #[Test]
    public function missingPermissionPointsToSearchConsoleUsers(): void
    {
        $this->http->queue(
            FakeHttp::json(['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'User does not have sufficient permission']], 403),
            FakeHttp::json(['error' => ['errors' => [['reason' => 'accessNotConfigured']], 'message' => 'API has not been used']], 403),
        );
        foreach (['user of the property', 'API is not enabled'] as $expected) {
            try {
                $this->client()->inspectUrl('alpha', 'https://example.com/');
                self::fail('Exception expected');
            } catch (ApiException $e) {
                self::assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('errors')]
    public function upstreamErrorsAreMapped(int $status, array $body, int $expected): void
    {
        $this->http->queue(FakeHttp::json($body, $status));
        try {
            $this->client()->inspectUrl('alpha', 'https://example.com/');
            self::fail('Exception expected');
        } catch (ApiException $e) {
            self::assertSame($expected, $e->status);
        }
    }

    #[Test]
    public function concurrentQueriesKeepTheirKeys(): void
    {
        $this->http->queue(FakeHttp::json(['rows' => [['keys' => ['a']]]]), FakeHttp::json(['rows' => [['keys' => ['b']]]]));
        $result = $this->client()->searchAnalyticsMany('alpha', ['first' => ['x' => 1], 'second' => ['x' => 2]]);
        self::assertSame(['first', 'second'], array_keys($result));
    }

    #[Test]
    public function credentialErrorsAreNotReportedAsUnreachable(): void
    {
        $account = $this->createMock(GoogleServiceAccount::class);
        $account->method('accessToken')->willThrowException(new ApiException('Google rejected the service account key', 503));
        $client = new SearchConsoleClient($account, $this->settings(), $this->http->factory());
        try {
            $client->listSites('alpha');
            self::fail('Exception expected');
        } catch (ApiException $e) {
            self::assertSame(503, $e->status);
            self::assertSame('Google rejected the service account key', $e->getMessage());
        }
    }
}
