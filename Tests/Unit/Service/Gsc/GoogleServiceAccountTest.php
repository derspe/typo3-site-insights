<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Tests\Unit\Service\Gsc;

use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Service\Gsc\GoogleServiceAccount;
use Derspe\SiteInsights\Tests\Unit\Fixtures\FakeHttp;
use Derspe\SiteInsights\Tests\Unit\Fixtures\KeyFile;
use Derspe\SiteInsights\Tests\Unit\Fixtures\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class GoogleServiceAccountTest extends UnitTestCase
{
    private FakeHttp $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new FakeHttp($this);
    }

    private function account(): GoogleServiceAccount
    {
        return new GoogleServiceAccount($this->settings(), $this->http->factory(), $this->cache());
    }

    private function storeKey(string $site = 'alpha', string $name = 'reports'): void
    {
        $this->settings()->set($site, GoogleServiceAccount::SETTING, GoogleServiceAccount::parseKey(KeyFile::json($name)));
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string, 3: string}
     */
    private static function decodeJwt(string $jwt): array
    {
        [$header, $claims, $signature] = explode('.', $jwt);
        $decode = static fn(string $part): string => base64_decode(strtr($part, '-_', '+/'), true) ?: '';
        return [json_decode($decode($header), true), json_decode($decode($claims), true), $header . '.' . $claims, $decode($signature)];
    }

    #[Test]
    public function accessTokenIsRequestedWithASignedReadOnlyAssertion(): void
    {
        $this->storeKey();
        $this->http->queue(FakeHttp::json(['access_token' => 'at-1', 'expires_in' => 3599]));
        self::assertSame('at-1', $this->account()->accessToken('alpha'));

        self::assertSame(['https://oauth2.googleapis.com/token'], $this->http->requestedUrls());
        $fields = $this->http->formFields(0);
        self::assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $fields['grant_type']);
        [$header, $claims, $signed, $signature] = self::decodeJwt($fields['assertion']);
        self::assertSame(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'kid-reports'], $header);
        self::assertSame(KeyFile::email(), $claims['iss']);
        self::assertSame(GoogleServiceAccount::SCOPE, $claims['scope']);
        self::assertSame('https://oauth2.googleapis.com/token', $claims['aud']);
        self::assertSame(3600, $claims['exp'] - $claims['iat']);
        self::assertSame(1, openssl_verify($signed, $signature, KeyFile::publicKey(), OPENSSL_ALGO_SHA256));
        self::assertArrayNotHasKey('private_key', $fields);
    }

    #[Test]
    public function tokenUriOfTheKeyFileIsIgnored(): void
    {
        // Even a key stored with a foreign token_uri never sends the assertion elsewhere
        $key = json_decode(KeyFile::json(), true);
        $key['token_uri'] = 'https://attacker.example/token';
        $this->settings()->set('alpha', GoogleServiceAccount::SETTING, $key);
        $this->http->queue(FakeHttp::json(['access_token' => 'at-1']));
        $this->account()->accessToken('alpha');
        self::assertSame(['https://oauth2.googleapis.com/token'], $this->http->requestedUrls());
    }

    #[Test]
    public function accessTokenIsCachedEncrypted(): void
    {
        $this->storeKey();
        $this->http->queue(FakeHttp::json(['access_token' => 'at-secret', 'expires_in' => 3599]));
        $account = $this->account();
        $account->accessToken('alpha');
        self::assertSame('at-secret', $account->accessToken('alpha'));
        self::assertCount(1, $this->http->history, 'second call uses the cache');
        self::assertStringNotContainsString('at-secret', json_encode($this->cacheStore));
    }

    #[Test]
    public function newKeyDoesNotReuseTheTokenOfTheOldKey(): void
    {
        $this->storeKey('alpha', 'old');
        $this->http->queue(FakeHttp::json(['access_token' => 'at-old']), FakeHttp::json(['access_token' => 'at-new']));
        $this->account()->accessToken('alpha');
        $this->storeKey('alpha', 'new');
        self::assertSame('at-new', $this->account()->accessToken('alpha'));
    }

    #[Test]
    public function rejectedKeyIsAConfigurationProblem(): void
    {
        $this->storeKey();
        $this->http->queue(FakeHttp::json(['error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.'], 400));
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(503);
        $this->account()->accessToken('alpha');
    }

    #[Test]
    public function missingKeyIsAConfigurationProblem(): void
    {
        self::assertFalse($this->account()->isConfigured('alpha'));
        $this->expectExceptionCode(503);
        $this->account()->accessToken('alpha');
    }

    #[Test]
    public function removeDeletesKeyAndCachedToken(): void
    {
        $this->storeKey();
        $this->http->queue(FakeHttp::json(['access_token' => 'at-1']));
        $account = $this->account();
        $account->accessToken('alpha');
        self::assertSame(KeyFile::email(), $account->clientEmail('alpha'));
        $account->remove('alpha');
        self::assertFalse($this->account()->isConfigured('alpha'));
        self::assertSame([], $this->cacheStore);
    }

    #[Test]
    public function keyIsStoredEncrypted(): void
    {
        $this->storeKey();
        $stored = json_encode($this->db->tables['tx_derspesiteinsights_setting']);
        self::assertStringNotContainsString('PRIVATE KEY', $stored);
        self::assertStringNotContainsString(KeyFile::email(), $stored);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'no json' => ['client_email=a@b.example'];
        yield 'oauth client file' => ['{"web":{"client_id":"x","client_secret":"y"}}'];
        yield 'user credentials' => [KeyFile::json('reports', ['type' => 'authorized_user'])];
        yield 'invalid e-mail' => [KeyFile::json('reports', ['client_email' => 'nobody'])];
        yield 'broken private key' => [KeyFile::json('reports', ['private_key' => "-----BEGIN PRIVATE KEY-----\nAAAA\n-----END PRIVATE KEY-----\n"])];
        yield 'too large' => [KeyFile::json('reports', ['padding' => str_repeat('x', 20000)])];
    }

    #[Test]
    #[DataProvider('invalidKeys')]
    public function invalidKeysAreRejected(string $json): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);
        GoogleServiceAccount::parseKey($json);
    }
}
