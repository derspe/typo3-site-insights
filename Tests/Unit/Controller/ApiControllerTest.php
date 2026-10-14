<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Tests\Unit\Controller;

use Derspe\SiteInsights\Controller\Backend\ApiController;
use Derspe\SiteInsights\Domain\Repository\InspectRepository;
use Derspe\SiteInsights\Domain\Repository\SnapshotRepository;
use Derspe\SiteInsights\Security\SiteAccess;
use Derspe\SiteInsights\Service\Gsc\GoogleOAuth;
use Derspe\SiteInsights\Service\Gsc\GscDashboardService;
use Derspe\SiteInsights\Service\Gsc\InspectService;
use Derspe\SiteInsights\Service\Gsc\SearchConsoleClient;
use Derspe\SiteInsights\Service\Gsc\SnapshotService;
use Derspe\SiteInsights\Service\Gsc\UrlCollector;
use Derspe\SiteInsights\Service\Job\JobService;
use Derspe\SiteInsights\Service\Matomo\MatomoDashboardService;
use Derspe\SiteInsights\Settings\SettingsValidator;
use Derspe\SiteInsights\Tests\Unit\Fixtures\FakeHttp;
use Derspe\SiteInsights\Tests\Unit\Fixtures\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Localization\LanguageService;

final class ApiControllerTest extends UnitTestCase
{
    private FakeHttp $http;
    private GoogleOAuth $oauth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new FakeHttp($this);
        $this->oauth = $this->createMock(GoogleOAuth::class);
        $this->oauth->method('redirectUri')->willReturn('https://backend.example/callback.html');
        $this->oauth->method('accessToken')->willReturn('token');
        $language = $this->createMock(LanguageService::class);
        $language->method('getLabelsFromResource')->willReturn(['app.title' => 'Site Insights']);
        $GLOBALS['LANG'] = $language;
    }

    private function controller(): ApiController
    {
        $sites = $this->siteFinder([self::site('alpha', 1), self::site('beta', 2)]);
        $settings = $this->settings();
        return new ApiController(
            new SiteAccess($sites),
            $settings,
            new SettingsValidator(),
            self::unused(MatomoDashboardService::class),
            self::unused(GscDashboardService::class),
            new SearchConsoleClient($this->oauth, $settings, $this->http->factory()),
            $this->oauth,
            self::unused(SnapshotService::class),
            new SnapshotRepository($this->db->connectionPool()),
            self::unused(InspectService::class),
            new InspectRepository($this->db->connectionPool()),
            new UrlCollector($settings, $sites, $this->http->factory(), new NullLogger()),
            self::unused(JobService::class),
            new NullLogger(),
        );
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     * @return array{0: int, 1: mixed}
     */
    private function call(array $query, ?array $body = null): array
    {
        $request = (new ServerRequest('https://backend.example/typo3/ajax/x', $body === null ? 'GET' : 'POST'))->withQueryParams($query);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        $response = $this->controller()->handle($request);
        return [$response->getStatusCode(), json_decode((string)$response->getBody(), true)];
    }

    #[Test]
    public function anonymousRequestsAreRejected(): void
    {
        unset($GLOBALS['BE_USER']);
        self::assertSame(401, $this->call(['endpoint' => 'meta'])[0]);
    }

    #[Test]
    public function usersWithoutModuleAccessAreRejected(): void
    {
        $GLOBALS['BE_USER'] = $this->backendUser(false, false, [1, 2]);
        self::assertSame(403, $this->call(['endpoint' => 'meta'])[0]);
    }

    #[Test]
    public function unknownEndpointIs404(): void
    {
        $GLOBALS['BE_USER'] = $this->backendUser(true);
        self::assertSame(404, $this->call(['endpoint' => 'nope'])[0]);
        self::assertSame(404, $this->call(['endpoint' => 'gsc/auth/start'])[0], 'write endpoint via GET');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, ?array<string, mixed>}>
     */
    public static function adminOnlyRequests(): iterable
    {
        yield 'read settings' => [['endpoint' => 'settings'], null];
        yield 'write settings' => [['endpoint' => 'settings'], ['values' => ['matomo_url' => 'https://evil.example']]];
        yield 'connect google' => [['endpoint' => 'gsc/auth/start'], []];
        yield 'complete google' => [['endpoint' => 'gsc/auth/complete'], ['state' => str_repeat('a', 32), 'code' => 'x']];
        yield 'disconnect google' => [['endpoint' => 'gsc/auth/disconnect'], []];
        yield 'snapshot' => [['endpoint' => 'gsc/snapshot/run'], ['force' => true]];
        yield 'backfill' => [['endpoint' => 'job/start'], ['type' => 'backfill', 'days' => 90]];
        yield 'forced scan' => [['endpoint' => 'job/start'], ['type' => 'index_scan', 'force' => true]];
        yield 'forced inspection' => [['endpoint' => 'gsc/inspect'], ['url' => 'https://alpha.example/', 'force' => true]];
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     */
    #[Test]
    #[DataProvider('adminOnlyRequests')]
    public function editorsCannotUseAdministratorEndpoints(array $query, ?array $body): void
    {
        $GLOBALS['BE_USER'] = $this->backendUser(false, true, [1, 2]);
        $this->db->failWith = new \RuntimeException('must not be reached');
        [$status] = $this->call($query + ['site' => 'alpha'], $body);
        self::assertSame(403, $status);
    }

    #[Test]
    public function metaOnlyListsSitesOfTheUser(): void
    {
        $GLOBALS['BE_USER'] = $this->backendUser(false, true, [2]);
        [$status, $data] = $this->call(['endpoint' => 'meta']);
        self::assertSame(200, $status);
        self::assertSame(['beta'], array_column($data['sites'], 'identifier'));
        self::assertFalse($data['isAdmin']);
    }

    #[Test]
    public function editorsCannotReadOtherSites(): void
    {
        $GLOBALS['BE_USER'] = $this->backendUser(false, true, [2]);
        [$status] = $this->call(['endpoint' => 'gsc/snapshot/status', 'site' => 'alpha']);
        self::assertSame(403, $status);
        [$status] = $this->call(['endpoint' => 'gsc/snapshot/status', 'site' => 'unknown']);
        self::assertSame(403, $status);
    }

    #[Test]
    public function internalErrorsDoNotLeakDetails(): void
    {
        $GLOBALS['BE_USER'] = $this->backendUser(true);
        $this->db->failWith = new \RuntimeException('SQLSTATE[42S02]: table secret_internal_table missing in /var/www/html/x.php');
        [$status, $data] = $this->call(['endpoint' => 'gsc/snapshot/status', 'site' => 'alpha']);
        self::assertSame(500, $status);
        self::assertStringNotContainsString('SQLSTATE', $data['error']);
        self::assertStringNotContainsString('secret_internal_table', $data['error']);
        self::assertMatchesRegularExpression('/reference [a-f0-9]{8}/', $data['error']);
    }

    #[Test]
    public function settingsNeverContainSecrets(): void
    {
        $GLOBALS['BE_USER'] = $this->backendUser(true);
        $settings = $this->settings();
        $settings->set('alpha', 'matomo_token', 'tok-secret');
        $settings->set('alpha', 'gsc_oauth_client_secret', 'client-secret');
        $settings->set('alpha', 'gsc_refresh_token', 'refresh-secret');
        [$status, $data] = $this->call(['endpoint' => 'settings', 'site' => 'alpha']);
        self::assertSame(200, $status);
        $json = json_encode($data);
        foreach (['tok-secret', 'client-secret', 'refresh-secret'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
        self::assertTrue($data['matomo_token_set']);
        self::assertArrayNotHasKey('gsc_refresh_token', $data);
    }

    #[Test]
    public function changingTheMatomoHostDropsTheStoredToken(): void
    {
        $GLOBALS['BE_USER'] = $this->backendUser(true);
        $this->settings()->set('alpha', 'matomo_url', 'https://matomo.example.com');
        $this->settings()->set('alpha', 'matomo_token', 'tok-secret');

        $this->call(['endpoint' => 'settings', 'site' => 'alpha'], ['values' => ['matomo_url' => 'https://matomo.example.com/']]);
        self::assertTrue($this->settings()->isSet('alpha', 'matomo_token'), 'same origin keeps the token');

        $this->call(['endpoint' => 'settings', 'site' => 'alpha'], ['values' => ['matomo_url' => 'https://attacker.example']]);
        self::assertFalse($this->settings()->isSet('alpha', 'matomo_token'), 'new origin drops the token');
    }

    #[Test]
    public function newTokenCanBeSavedTogetherWithANewHost(): void
    {
        $GLOBALS['BE_USER'] = $this->backendUser(true);
        $this->settings()->set('alpha', 'matomo_url', 'https://old.example');
        $this->settings()->set('alpha', 'matomo_token', 'old');
        $this->call(['endpoint' => 'settings', 'site' => 'alpha'], ['values' => ['matomo_url' => 'https://new.example', 'matomo_token' => 'new']]);
        self::assertSame('new', $this->settings()->getString('alpha', 'matomo_token'));
    }

    #[Test]
    public function invalidSettingsAreRejected(): void
    {
        $GLOBALS['BE_USER'] = $this->backendUser(true);
        [$status] = $this->call(['endpoint' => 'settings', 'site' => 'alpha'], ['values' => ['gsc_sitemap_url' => 'file:///etc/passwd']]);
        self::assertSame(400, $status);
        self::assertSame('', $this->settings()->getString('alpha', 'gsc_sitemap_url'));
    }

    #[Test]
    public function editorsOnlySeeTheConfiguredProperty(): void
    {
        $this->settings()->set('alpha', 'gsc_oauth_client_id', 'id');
        $this->settings()->set('alpha', 'gsc_oauth_client_secret', 'secret');
        $this->settings()->set('alpha', 'gsc_refresh_token', 'refresh');
        $this->settings()->set('alpha', 'gsc_site_url', 'sc-domain:alpha.example');
        $this->oauth->method('isConnected')->willReturn(true);
        $this->oauth->method('isConfigured')->willReturn(true);
        $properties = ['siteEntry' => [
            ['siteUrl' => 'sc-domain:alpha.example', 'permissionLevel' => 'siteOwner'],
            ['siteUrl' => 'https://other-customer.example/', 'permissionLevel' => 'siteOwner'],
        ]];
        $this->http->queue(FakeHttp::json($properties), FakeHttp::json($properties));

        $GLOBALS['BE_USER'] = $this->backendUser(false, true, [1]);
        [, $editor] = $this->call(['endpoint' => 'gsc/auth/status', 'site' => 'alpha']);
        self::assertSame(['sc-domain:alpha.example'], array_column($editor['sites'], 'siteUrl'));

        $GLOBALS['BE_USER'] = $this->backendUser(true);
        [, $admin] = $this->call(['endpoint' => 'gsc/auth/status', 'site' => 'alpha']);
        self::assertCount(2, $admin['sites']);
    }

    #[Test]
    public function oauthCompletionUsesTheCurrentAdministrator(): void
    {
        $GLOBALS['BE_USER'] = $this->backendUser(true, true, [], 42);
        $this->oauth->expects(self::once())->method('complete')->with('alpha', 42, 'the-state', 'the-code');
        [$status] = $this->call(['endpoint' => 'gsc/auth/complete', 'site' => 'alpha'], ['state' => 'the-state', 'code' => 'the-code']);
        self::assertSame(200, $status);
    }

    #[Test]
    public function cancelledConsentIsNotCompleted(): void
    {
        $GLOBALS['BE_USER'] = $this->backendUser(true);
        $this->oauth->expects(self::never())->method('complete');
        [$status] = $this->call(['endpoint' => 'gsc/auth/complete', 'site' => 'alpha'], ['error' => 'access_denied']);
        self::assertSame(400, $status);
    }

    #[Test]
    public function jsonBodyIsAccepted(): void
    {
        $GLOBALS['BE_USER'] = $this->backendUser(true, true, [], 42);
        $this->oauth->expects(self::once())->method('complete')->with('alpha', 42, 's', 'c');
        $body = new Stream('php://temp', 'rw');
        $body->write(json_encode(['site' => 'alpha', 'state' => 's', 'code' => 'c']));
        $body->rewind();
        $request = (new ServerRequest('https://backend.example/typo3/ajax/x', 'POST', $body))
            ->withQueryParams(['endpoint' => 'gsc/auth/complete']);
        $response = $this->controller()->handle($request);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
    }
}
