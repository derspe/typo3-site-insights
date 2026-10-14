<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Tests\Unit\Service\Gsc;

use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Service\Gsc\GoogleOAuth;
use Derspe\SiteInsights\Tests\Unit\Fixtures\FakeHttp;
use Derspe\SiteInsights\Tests\Unit\Fixtures\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;

final class GoogleOAuthTest extends UnitTestCase
{
    private const STATE_TABLE = GoogleOAuth::STATE_TABLE;
    private FakeHttp $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new FakeHttp($this);
        $settings = $this->settings();
        $settings->set('alpha', 'gsc_oauth_client_id', 'client-id');
        $settings->set('alpha', 'gsc_oauth_client_secret', 'client-secret');
    }

    private function oauth(): GoogleOAuth
    {
        return new class ($this->settings(), $this->http->factory(), $this->db->connectionPool(), $this->cache()) extends GoogleOAuth {
            public function redirectUri(): string
            {
                return 'https://backend.example/_assets/abc/OAuth/callback.html';
            }
        };
    }

    private function storedState(): string
    {
        return (string)$this->db->tables[self::STATE_TABLE][0]['state'];
    }

    #[Test]
    public function authUrlRequestsOnlyTheReadOnlyScopeAndStoresABoundState(): void
    {
        $url = $this->oauth()->buildAuthUrl('alpha', 7);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        self::assertStringStartsWith('https://accounts.google.com/', $url);
        self::assertSame(GoogleOAuth::SCOPE, $query['scope']);
        self::assertSame('https://backend.example/_assets/abc/OAuth/callback.html', $query['redirect_uri']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $query['state']);
        self::assertArrayNotHasKey('client_secret', $query);
        self::assertSame(['alpha', 7], [$this->db->tables[self::STATE_TABLE][0]['site'], $this->db->tables[self::STATE_TABLE][0]['be_user']]);
    }

    #[Test]
    public function authUrlNeedsClientCredentials(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(503);
        $this->oauth()->buildAuthUrl('beta', 7);
    }

    #[Test]
    public function stateIsSingleUse(): void
    {
        $oauth = $this->oauth();
        $oauth->buildAuthUrl('alpha', 7);
        $state = $this->storedState();
        self::assertTrue($oauth->consumeState($state, 'alpha', 7));
        self::assertFalse($oauth->consumeState($state, 'alpha', 7));
    }

    #[Test]
    public function stateIsBoundToSiteAndAdministrator(): void
    {
        $oauth = $this->oauth();
        $oauth->buildAuthUrl('alpha', 7);
        self::assertFalse($oauth->consumeState($this->storedState(), 'alpha', 8));

        $oauth->buildAuthUrl('alpha', 7);
        self::assertFalse($oauth->consumeState($this->storedState(), 'beta', 7));
        // A failed attempt still burns the state
        self::assertSame([], $this->db->tables[self::STATE_TABLE]);
    }

    #[Test]
    public function expiredStateIsRejected(): void
    {
        $oauth = $this->oauth();
        $oauth->buildAuthUrl('alpha', 7);
        $this->db->tables[self::STATE_TABLE][0]['created_at'] = time() - GoogleOAuth::STATE_TTL - 1;
        self::assertFalse($oauth->consumeState($this->storedState(), 'alpha', 7));
    }

    #[Test]
    public function malformedStateNeverReachesTheDatabase(): void
    {
        $this->db->failWith = new \RuntimeException('database must not be queried');
        $oauth = $this->oauth();
        foreach (['', 'x', "' OR 1=1 --", str_repeat('a', 33), str_repeat('A', 32)] as $state) {
            self::assertFalse($oauth->consumeState($state, 'alpha', 7));
        }
    }

    #[Test]
    public function completeStoresTheRefreshTokenEncryptedAndCachesTheAccessTokenEncrypted(): void
    {
        $oauth = $this->oauth();
        $oauth->buildAuthUrl('alpha', 7);
        $this->http->queue(FakeHttp::json(['access_token' => 'access-123', 'refresh_token' => 'refresh-456', 'expires_in' => 3600]));
        $oauth->complete('alpha', 7, $this->storedState(), 'auth-code');

        $form = $this->http->formFields(0);
        self::assertSame('authorization_code', $form['grant_type']);
        self::assertSame('https://backend.example/_assets/abc/OAuth/callback.html', $form['redirect_uri']);
        self::assertSame('refresh-456', $this->settings()->getString('alpha', 'gsc_refresh_token'));
        $raw = json_encode($this->db->tables) . json_encode($this->cacheStore);
        self::assertStringNotContainsString('refresh-456', $raw);
        self::assertStringNotContainsString('access-123', $raw);
        // Served from the cache without a second request
        self::assertSame('access-123', $oauth->accessToken('alpha'));
        self::assertCount(1, $this->http->history);
    }

    #[Test]
    public function completeWithInvalidStateDoesNotContactGoogle(): void
    {
        try {
            $this->oauth()->complete('alpha', 7, str_repeat('a', 32), 'auth-code');
            self::fail('Exception expected');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status);
        }
        self::assertSame([], $this->http->history);
    }

    #[Test]
    public function missingRefreshTokenIsReported(): void
    {
        $oauth = $this->oauth();
        $oauth->buildAuthUrl('alpha', 7);
        $this->http->queue(FakeHttp::json(['access_token' => 'a']));
        $this->expectExceptionCode(400);
        $oauth->complete('alpha', 7, $this->storedState(), 'code');
    }

    #[Test]
    public function revokedGrantMeansDisconnectedNotServerError(): void
    {
        $this->settings()->set('alpha', 'gsc_refresh_token', 'old');
        $this->http->queue(FakeHttp::json(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400));
        $this->expectExceptionCode(503);
        $this->oauth()->accessToken('alpha');
    }

    #[Test]
    public function tamperedCacheEntryForcesANewToken(): void
    {
        $this->settings()->set('alpha', 'gsc_refresh_token', 'refresh');
        $this->cacheStore['gsc_access_' . sha1('alpha')] = 'plain-text-injected';
        $this->http->queue(FakeHttp::json(['access_token' => 'fresh', 'expires_in' => 3600]));
        self::assertSame('fresh', $this->oauth()->accessToken('alpha'));
    }

    #[Test]
    public function disconnectRemovesTokens(): void
    {
        $this->settings()->set('alpha', 'gsc_refresh_token', 'refresh');
        $this->cacheStore['gsc_access_' . sha1('alpha')] = 'x';
        $this->oauth()->disconnect('alpha');
        self::assertFalse($this->settings()->isSet('alpha', 'gsc_refresh_token'));
        self::assertArrayNotHasKey('gsc_access_' . sha1('alpha'), $this->cacheStore);
    }
}
