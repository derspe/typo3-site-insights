<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Tests\Unit\Controller;

use Derspe\SiteInsights\Controller\Backend\ApiController;
use Derspe\SiteInsights\Domain\Repository\InspectRepository;
use Derspe\SiteInsights\Domain\Repository\SnapshotRepository;
use Derspe\SiteInsights\Security\SiteAccess;
use Derspe\SiteInsights\Service\Gsc\GoogleServiceAccount;
use Derspe\SiteInsights\Service\Gsc\GscDashboardService;
use Derspe\SiteInsights\Service\Gsc\InspectService;
use Derspe\SiteInsights\Service\Gsc\SearchConsoleClient;
use Derspe\SiteInsights\Service\Gsc\SnapshotService;
use Derspe\SiteInsights\Service\Gsc\UrlCollector;
use Derspe\SiteInsights\Service\Job\JobService;
use Derspe\SiteInsights\Service\Matomo\MatomoDashboardService;
use Derspe\SiteInsights\Service\Matomo\TransitionsService;
use Derspe\SiteInsights\Service\News\NewsInsightsService;
use Derspe\SiteInsights\Service\News\NewsRepository;
use Derspe\SiteInsights\Service\Page\PageInsightsService;
use Derspe\SiteInsights\Settings\SettingsValidator;
use Derspe\SiteInsights\Settings\Sources;
use Derspe\SiteInsights\Tests\Unit\Fixtures\FakeHttp;
use Derspe\SiteInsights\Tests\Unit\Fixtures\KeyFile;
use Derspe\SiteInsights\Tests\Unit\Fixtures\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Localization\LanguageService;

final class ApiControllerTest extends UnitTestCase
{
    private FakeHttp $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new FakeHttp($this);
        $language = $this->createMock(LanguageService::class);
        $language->method('getLabelsFromResource')->willReturn(['app.title' => 'Site Insights']);
        $GLOBALS['LANG'] = $language;
    }

    private function controller(): ApiController
    {
        $sites = $this->siteFinder([self::site('alpha', 1), self::site('beta', 2)]);
        $settings = $this->settings();
        // Real key handling, only the token request to Google is skipped
        $serviceAccount = new class ($settings, $this->http->factory(), $this->cache()) extends GoogleServiceAccount {
            public function accessToken(string $site): string
            {
                return 'token';
            }
        };
        return new ApiController(
            new SiteAccess($sites),
            $settings,
            new SettingsValidator(),
            self::unused(MatomoDashboardService::class),
            self::unused(GscDashboardService::class),
            new SearchConsoleClient($serviceAccount, $settings, $this->http->factory()),
            $serviceAccount,
            self::unused(SnapshotService::class),
            new SnapshotRepository($this->db->connectionPool()),
            self::unused(InspectService::class),
            new InspectRepository($this->db->connectionPool()),
            new UrlCollector($settings, $sites, $this->http->factory(), new NullLogger()),
            self::unused(JobService::class),
            new NullLogger(),
            self::unused(PageInsightsService::class),
            new Sources(new ExtensionConfiguration()),
            self::unused(NewsInsightsService::class),
            new NewsRepository($this->db->connectionPool()),
            self::unused(TransitionsService::class),
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
        self::assertSame(404, $this->call(['endpoint' => 'gsc/auth/remove'])[0], 'write endpoint via GET');
        self::assertSame(404, $this->call(['endpoint' => 'gsc/auth/start'], [])[0], 'OAuth endpoints are gone');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, ?array<string, mixed>}>
     */
    public static function adminOnlyRequests(): iterable
    {
        yield 'read settings' => [['endpoint' => 'settings'], null];
        yield 'write settings' => [['endpoint' => 'settings'], ['values' => ['matomo_url' => 'https://evil.example']]];
        yield 'save service account key' => [['endpoint' => 'settings'], ['values' => ['gsc_service_account_key' => '{}']]];
        yield 'remove service account key' => [['endpoint' => 'gsc/auth/remove'], []];
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
        self::assertFalse($data['canConfigure']);
        self::assertSame(['matomo' => true, 'gsc' => true], $data['sources']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, ?array<string, mixed>}>
     */
    public static function maintainerOnlyRequests(): iterable
    {
        yield 'read settings' => [['endpoint' => 'settings'], null];
        yield 'write settings' => [['endpoint' => 'settings'], ['values' => ['matomo_url' => 'https://evil.example']]];
        yield 'remove service account key' => [['endpoint' => 'gsc/auth/remove'], []];
        yield 'backfill' => [['endpoint' => 'job/start'], ['type' => 'backfill', 'days' => 90]];
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     */
    #[Test]
    #[DataProvider('maintainerOnlyRequests')]
    public function administratorsWithoutSystemMaintainerRightsCannotConfigure(array $query, ?array $body): void
    {
        $GLOBALS['BE_USER'] = $this->backendUser(true, true, [], 7, false);
        $this->db->failWith = new \RuntimeException('must not be reached');
        [$status, $data] = $this->call($query + ['site' => 'alpha'], $body);
        self::assertSame(403, $status);
        self::assertSame('Only system maintainers can do this', $data['error']);
    }

    #[Test]
    public function metaTellsWhoMayConfigure(): void
    {
        $GLOBALS['BE_USER'] = $this->backendUser(true, true, [], 7, false);
        self::assertFalse($this->call(['endpoint' => 'meta'])[1]['canConfigure']);
        $GLOBALS['BE_USER'] = $this->backendUser(true);
        self::assertTrue($this->call(['endpoint' => 'meta'])[1]['canConfigure']);
    }

    #[Test]
    public function endpointsOfDisabledSourcesDoNotExist(): void
    {
        $GLOBALS['BE_USER'] = $this->backendUser(true);
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['derspe_site_insights']['sources'] = 'gsc';
        $this->db->failWith = new \RuntimeException('must not be reached');
        [$status, $data] = $this->call(['endpoint' => 'matomo/dashboard', 'site' => 'alpha']);
        self::assertSame(404, $status);
        self::assertStringContainsString('disabled', $data['error']);
        self::assertSame(['matomo' => false, 'gsc' => true], $this->call(['endpoint' => 'meta'])[1]['sources']);

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['derspe_site_insights']['sources'] = 'matomo';
        self::assertSame(404, $this->call(['endpoint' => 'gsc/snapshot/status', 'site' => 'alpha'])[0]);
        self::assertSame(404, $this->call(['endpoint' => 'job/active', 'site' => 'alpha'])[0]);
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
        $settings->set('alpha', 'gsc_service_account_key', GoogleServiceAccount::parseKey(KeyFile::json()));
        [$status, $data] = $this->call(['endpoint' => 'settings', 'site' => 'alpha']);
        self::assertSame(200, $status);
        $json = json_encode($data);
        self::assertStringNotContainsString('tok-secret', $json);
        self::assertStringNotContainsString('PRIVATE KEY', $json);
        self::assertStringNotContainsString('kid-reports', $json);
        self::assertTrue($data['matomo_token_set']);
        self::assertTrue($data['gsc_service_account_key_set']);
        self::assertArrayNotHasKey('gsc_service_account_key', $data);
        // The address is needed to add the account in Search Console
        self::assertSame(KeyFile::email(), $data['gsc_service_account_email']);
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
    public function newsEndpointsNeedTheNewsTableAndAFolder(): void
    {
        $GLOBALS['BE_USER'] = $this->backendUser(true);
        [$status] = $this->call(['endpoint' => 'news/overview', 'site' => 'alpha']);
        self::assertSame(400, $status);
        [$status, $data] = $this->call(['endpoint' => 'news/overview', 'site' => 'alpha', 'folder' => 5]);
        self::assertSame(403, $status, 'EXT:news not installed');
        self::assertSame('Access to news records denied', $data['error']);
        [, $meta] = $this->call(['endpoint' => 'meta']);
        self::assertFalse($meta['news']);
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
    public function onlySystemMaintainersSeeAllPropertiesAndTheServiceAccount(): void
    {
        $this->settings()->set('alpha', 'gsc_service_account_key', GoogleServiceAccount::parseKey(KeyFile::json()));
        $this->settings()->set('alpha', 'gsc_site_url', 'sc-domain:alpha.example');
        $properties = ['siteEntry' => [
            ['siteUrl' => 'sc-domain:alpha.example', 'permissionLevel' => 'siteFullUser'],
            ['siteUrl' => 'https://other-customer.example/', 'permissionLevel' => 'siteFullUser'],
        ]];
        $this->http->queue(FakeHttp::json($properties), FakeHttp::json($properties));

        $GLOBALS['BE_USER'] = $this->backendUser(true, true, [], 7, false);
        [, $editor] = $this->call(['endpoint' => 'gsc/auth/status', 'site' => 'alpha']);
        self::assertSame(['sc-domain:alpha.example'], array_column($editor['sites'], 'siteUrl'));
        self::assertSame('', $editor['clientEmail']);
        self::assertTrue($editor['propertyAccessible']);

        $GLOBALS['BE_USER'] = $this->backendUser(true);
        [, $maintainer] = $this->call(['endpoint' => 'gsc/auth/status', 'site' => 'alpha']);
        self::assertCount(2, $maintainer['sites']);
        self::assertSame(KeyFile::email(), $maintainer['clientEmail']);
    }

    #[Test]
    public function statusTellsWhenThePropertyIsNotSharedWithTheServiceAccount(): void
    {
        $GLOBALS['BE_USER'] = $this->backendUser(true);
        $this->settings()->set('alpha', 'gsc_service_account_key', GoogleServiceAccount::parseKey(KeyFile::json()));
        $this->settings()->set('alpha', 'gsc_site_url', 'sc-domain:alpha.example');
        $this->http->queue(FakeHttp::json(['siteEntry' => []]));
        [$status, $data] = $this->call(['endpoint' => 'gsc/auth/status', 'site' => 'alpha']);
        self::assertSame(200, $status);
        self::assertTrue($data['connected']);
        self::assertFalse($data['propertyAccessible']);
    }

    #[Test]
    public function statusWithoutKeyDoesNotCallGoogle(): void
    {
        $GLOBALS['BE_USER'] = $this->backendUser(true);
        [, $data] = $this->call(['endpoint' => 'gsc/auth/status', 'site' => 'alpha']);
        self::assertFalse($data['configured']);
        self::assertSame([], $this->http->history);
    }

    #[Test]
    public function keyIsSavedThroughTheSettingsAndCanBeRemoved(): void
    {
        $GLOBALS['BE_USER'] = $this->backendUser(true);
        [$status, $data] = $this->call(['endpoint' => 'settings', 'site' => 'alpha'], ['values' => ['gsc_service_account_key' => KeyFile::json()]]);
        self::assertSame(200, $status);
        self::assertSame(KeyFile::email(), $data['gsc_service_account_email']);

        [$status] = $this->call(['endpoint' => 'settings', 'site' => 'alpha'], ['values' => ['gsc_service_account_key' => '']]);
        self::assertSame(200, $status);
        self::assertTrue($this->settings()->isSet('alpha', 'gsc_service_account_key'), 'empty field keeps the key');

        [$status] = $this->call(['endpoint' => 'gsc/auth/remove', 'site' => 'alpha'], []);
        self::assertSame(200, $status);
        self::assertFalse($this->settings()->isSet('alpha', 'gsc_service_account_key'));
    }

    #[Test]
    public function invalidKeyIsRejectedWithAHint(): void
    {
        $GLOBALS['BE_USER'] = $this->backendUser(true);
        [$status, $data] = $this->call(['endpoint' => 'settings', 'site' => 'alpha'], ['values' => ['gsc_service_account_key' => '{"web":{}}']]);
        self::assertSame(400, $status);
        self::assertStringContainsString('service account key', $data['error']);
    }

    #[Test]
    public function jsonBodyIsAccepted(): void
    {
        $GLOBALS['BE_USER'] = $this->backendUser(true);
        $body = new Stream('php://temp', 'rw');
        $body->write(json_encode(['site' => 'alpha', 'values' => ['gsc_site_url' => 'sc-domain:alpha.example']]));
        $body->rewind();
        $request = (new ServerRequest('https://backend.example/typo3/ajax/x', 'POST', $body))
            ->withQueryParams(['endpoint' => 'settings']);
        $response = $this->controller()->handle($request);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('sc-domain:alpha.example', $this->settings()->getString('alpha', 'gsc_site_url'));
    }
}
