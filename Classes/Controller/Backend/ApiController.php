<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Controller\Backend;

use Derspe\SiteInsights\Domain\Repository\InspectRepository;
use Derspe\SiteInsights\Domain\Repository\SnapshotRepository;
use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Security\AccessPolicy;
use Derspe\SiteInsights\Security\SiteAccess;
use Derspe\SiteInsights\Service\Gsc\GoogleServiceAccount;
use Derspe\SiteInsights\Service\Gsc\GscDashboardService;
use Derspe\SiteInsights\Service\Gsc\InspectService;
use Derspe\SiteInsights\Service\Gsc\SearchConsoleClient;
use Derspe\SiteInsights\Service\Gsc\SnapshotService;
use Derspe\SiteInsights\Service\Gsc\UrlCollector;
use Derspe\SiteInsights\Service\Job\JobService;
use Derspe\SiteInsights\Service\Matomo\MatomoDashboardService;
use Derspe\SiteInsights\Service\News\NewsInsightsService;
use Derspe\SiteInsights\Service\News\NewsRepository;
use Derspe\SiteInsights\Service\Page\PageInsightsService;
use Derspe\SiteInsights\Settings\SettingsValidator;
use Derspe\SiteInsights\Settings\Sources;
use Derspe\SiteInsights\Settings\SiteSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Single AJAX entry point of the backend module and the dashboard widgets.
 * The endpoint is passed as parameter "endpoint" (e.g. "gsc/dashboard"), the site as "site".
 *
 * Every request is checked against the module permission, the {@see AccessPolicy} of the
 * endpoint and the sites the user may see ({@see SiteAccess}).
 */
final readonly class ApiController
{
    public const MODULE = 'site_insights';
    private const JOB_STEP_SECONDS = 8.0;

    public function __construct(
        private SiteAccess $siteAccess,
        private SiteSettings $settings,
        private SettingsValidator $validator,
        private MatomoDashboardService $matomo,
        private GscDashboardService $gsc,
        private SearchConsoleClient $searchConsole,
        private GoogleServiceAccount $serviceAccount,
        private SnapshotService $snapshots,
        private SnapshotRepository $snapshotRepository,
        private InspectService $inspect,
        private InspectRepository $inspectRepository,
        private UrlCollector $urlCollector,
        private JobService $jobs,
        private LoggerInterface $logger,
        private PageInsightsService $pageInsights,
        private Sources $sources,
        private NewsInsightsService $newsInsights,
        private NewsRepository $news,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $body = $this->body($request);
        $endpoint = (string)($query['endpoint'] ?? $body['endpoint'] ?? '');
        $isPost = $request->getMethod() === 'POST';
        try {
            $user = $this->backendUser();
            if (!$user->check('modules', self::MODULE)) {
                throw new ApiException('Access denied', 403);
            }
            $level = AccessPolicy::requiredLevel($endpoint, $isPost, $body + $query);
            if ($level === null) {
                throw new ApiException('Unknown endpoint', 404);
            }
            if ($level === AccessPolicy::ADMIN && !$user->isAdmin()) {
                throw new ApiException('Only administrators can do this', 403);
            }
            if ($level === AccessPolicy::MAINTAINER && !$user->isSystemMaintainer()) {
                throw new ApiException('Only system maintainers can do this', 403);
            }
            $this->assertSourceEnabled($endpoint);
            if ($endpoint === 'meta') {
                return new JsonResponse($this->meta($user));
            }
            $site = $this->siteAccess->resolve($user, (string)($query['site'] ?? $body['site'] ?? ''))->getIdentifier();
            return new JsonResponse($this->dispatch($endpoint, $isPost, $site, $query, $body, $user));
        } catch (ApiException $e) {
            return new JsonResponse(['error' => $e->getMessage()], $e->status);
        } catch (\Throwable $e) {
            // Details (SQL, paths) only go to the log
            $reference = bin2hex(random_bytes(4));
            $this->logger->error('Site Insights API error {reference} on {endpoint}: {message}', [
                'reference' => $reference,
                'endpoint' => $endpoint,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
            return new JsonResponse(['error' => sprintf('Internal error (reference %s, see the TYPO3 log)', $reference)], 500);
        }
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     */
    private function dispatch(string $endpoint, bool $isPost, string $site, array $query, array $body, BackendUserAuthentication $user): mixed
    {
        return match (true) {
            $endpoint === 'settings' && !$isPost => $this->readSettings($site),
            $endpoint === 'settings' => $this->writeSettings($site, (array)($body['values'] ?? [])),

            $endpoint === 'matomo/segments' => $this->matomo->segments($site),
            $endpoint === 'matomo/goals' => $this->matomo->goals($site),
            $endpoint === 'matomo/dashboard' => $this->matomo->dashboard(
                $site,
                (string)($query['period'] ?? 'range'),
                (string)($query['date'] ?? 'last30'),
                (string)($query['segment'] ?? ''),
                (string)($query['compare'] ?? '')
            ),

            $endpoint === 'gsc/dashboard' => $this->gsc->dashboard($site, $query),
            $endpoint === 'gsc/drilldown' => $this->gsc->drilldown($site, $query),
            $endpoint === 'gsc/cannibalization' => $this->gsc->cannibalization($site, $query),
            $endpoint === 'gsc/appearance' => $this->gsc->appearance($site, $query),
            $endpoint === 'gsc/visibility' => $this->gsc->visibility($site, $query),
            $endpoint === 'gsc/visibility/rebuild' => ['days' => $this->snapshots->rebuildVisibility($site)],
            $endpoint === 'gsc/tracker' => $this->gsc->trackerList($site, $this->dimension($query), (string)($query['order'] ?? 'clicks')),
            $endpoint === 'gsc/tracker/series' => $this->gsc->trackerSeries($site, $this->dimension($query), (string)($query['key'] ?? '')),
            $endpoint === 'gsc/snapshot/status' => $this->snapshotStatus($site),
            $endpoint === 'gsc/snapshot/run' => $this->snapshots->run($site, null, (bool)($body['force'] ?? false)),
            $endpoint === 'gsc/inspect' && !$isPost => InspectRepository::toDto($this->inspectCached($site, (string)($query['url'] ?? '')), true),
            $endpoint === 'gsc/inspect' => $this->inspectNow($site, (string)($body['url'] ?? ''), (bool)($body['force'] ?? false)),
            $endpoint === 'gsc/index/pages' => $this->inspect->pages($site),
            $endpoint === 'gsc/auth/status' => $this->authStatus($site, $user->isSystemMaintainer()),
            $endpoint === 'gsc/auth/remove' => $this->authRemove($site),

            $endpoint === 'job/start' => $this->startJob($site, $body),
            $endpoint === 'job/active' => ['job' => JobService::toDto($this->jobs->findActive($site, (string)($query['type'] ?? '')))],
            $endpoint === 'job/step' => $this->stepJob($site, (int)($body['id'] ?? 0)),

            $endpoint === 'page/insights' => $this->pageInsights($site, $query, $user),
            $endpoint === 'news/overview' => $this->newsInsights->overview(
                $site,
                $this->newsFolder($query, $user),
                max(0, (int)($query['language'] ?? 0)),
                GeneralUtility::sanitizeLocalUrl((string)($query['returnUrl'] ?? ''))
            ),
            $endpoint === 'news/queries' => $this->newsInsights->queries(
                $site,
                $this->newsFolder($query, $user),
                (int)($query['uid'] ?? 0),
                max(0, (int)($query['language'] ?? 0))
            ),

            default => throw new ApiException('Unknown endpoint', 404),
        };
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function pageInsights(string $site, array $query, BackendUserAuthentication $user): array
    {
        $pageId = (int)($query['page'] ?? 0);
        if ($pageId <= 0) {
            throw new ApiException('Parameter "page" missing', 400);
        }
        if (!$user->isAdmin() && BackendUtility::readPageAccess($pageId, $user->getPagePermsClause(Permission::PAGE_SHOW)) === false) {
            throw new ApiException('Access to this page denied', 403);
        }
        $url = $this->pageInsights->pageUrl($site, $pageId, max(0, (int)($query['language'] ?? 0)));
        return $this->pageInsights->insights($site, $url);
    }

    /**
     * Folder with news records the user may see.
     *
     * @param array<string, mixed> $query
     */
    private function newsFolder(array $query, BackendUserAuthentication $user): int
    {
        $folder = (int)($query['folder'] ?? 0);
        if ($folder <= 0) {
            throw new ApiException('Parameter "folder" missing', 400);
        }
        if (!$this->news->isAvailable() || !$user->check('tables_select', NewsRepository::TABLE)) {
            throw new ApiException('Access to news records denied', 403);
        }
        if (!$user->isAdmin() && BackendUtility::readPageAccess($folder, $user->getPagePermsClause(Permission::PAGE_SHOW)) === false) {
            throw new ApiException('Access to this page denied', 403);
        }
        return $folder;
    }

    /**
     * Endpoints of a source disabled in the extension configuration do not exist.
     */
    private function assertSourceEnabled(string $endpoint): void
    {
        $source = match (true) {
            str_starts_with($endpoint, 'matomo/') => Sources::MATOMO,
            // Jobs only exist for Search Console (index scan, backfill)
            str_starts_with($endpoint, 'gsc/'), str_starts_with($endpoint, 'job/') => Sources::GSC,
            default => null,
        };
        if ($source === Sources::MATOMO && !$this->sources->matomo() || $source === Sources::GSC && !$this->sources->gsc()) {
            throw new ApiException('This data source is disabled in the extension configuration', 404);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(BackendUserAuthentication $user): array
    {
        $sites = array_values(array_map(static fn(Site $site): array => [
            'identifier' => $site->getIdentifier(),
            'title' => (string)($site->getConfiguration()['websiteTitle'] ?? '') ?: $site->getIdentifier(),
            'base' => (string)$site->getBase(),
        ], $this->siteAccess->allowedSites($user)));
        $labels = $this->languageService()->getLabelsFromResource('EXT:derspe_site_insights/Resources/Private/Language/locallang_js.xlf');
        return [
            'sites' => $sites,
            'isAdmin' => $user->isAdmin(),
            'canConfigure' => $user->isSystemMaintainer(),
            'sources' => $this->sources->toArray(),
            'news' => $this->news->isAvailable(),
            'locale' => (string)($this->languageService()->getLocale() ?? 'en'),
            'labels' => $labels,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readSettings(string $site): array
    {
        $values = [];
        foreach (SettingsValidator::KEYS as $key => $type) {
            if ($type === SettingsValidator::TYPE_SECRET || $type === SettingsValidator::TYPE_SERVICE_ACCOUNT_KEY) {
                $values[$key . '_set'] = $this->settings->isSet($site, $key);
                continue;
            }
            $values[$key] = $this->settings->get($site, $key);
        }
        $values['gsc_sitemap_url_default'] = $this->urlCollector->sitemapUrl($site);
        $values['gsc_service_account_email'] = $this->serviceAccount->clientEmail($site);
        return $values;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function writeSettings(string $site, array $values): array
    {
        $changes = $this->validator->validate($values);
        if (isset($changes['news_detail_pid'])) {
            $this->newsInsights->assertDetailPage($site, $changes['news_detail_pid']);
        }
        // The Matomo token must never be sent to a different host than the one it was entered for
        if (array_key_exists('matomo_url', $changes)
            && !array_key_exists('matomo_token', $changes)
            && SettingsValidator::origin((string)$changes['matomo_url']) !== SettingsValidator::origin($this->settings->getString($site, 'matomo_url'))
        ) {
            $this->settings->remove($site, 'matomo_token');
        }
        foreach ($changes as $key => $value) {
            $value === null ? $this->settings->remove($site, $key) : $this->settings->set($site, $key, $value);
        }
        return $this->readSettings($site);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotStatus(string $site): array
    {
        $run = $this->snapshotRepository->latestRun($site);
        return [
            'latestRun' => $run === null ? null : [
                'status' => $run['status'],
                'snapshotDate' => $run['snapshot_date'],
                'finishedAt' => (int)$run['finished_at'],
                'rowsWritten' => (int)$run['rows_written'],
            ],
            'latestSnapshotDate' => $this->snapshotRepository->latestQuerySnapshotDate($site),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function inspectCached(string $site, string $url): ?array
    {
        if ($url === '') {
            throw new ApiException('Parameter "url" missing', 400);
        }
        return $this->inspectRepository->find($site, $url);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function inspectNow(string $site, string $url, bool $force): ?array
    {
        if (!SettingsValidator::isHttpUrl($url)) {
            throw new ApiException('Please enter a valid URL', 400);
        }
        $result = $this->inspect->inspect($site, $url, $force);
        return InspectRepository::toDto($result['row'], $result['cacheHit']);
    }

    /**
     * Whether the service account works: the properties it can see and whether the configured
     * property is one of them.
     *
     * @return array<string, mixed>
     */
    private function authStatus(string $site, bool $canConfigure): array
    {
        $configured = $this->serviceAccount->isConfigured($site);
        $property = $this->settings->getString($site, 'gsc_site_url');
        $status = [
            'configured' => $configured,
            'connected' => false,
            // Only system maintainers need the address (to add it in Search Console)
            'clientEmail' => $canConfigure ? $this->serviceAccount->clientEmail($site) : '',
            'property' => $property,
            'propertyAccessible' => false,
            'sites' => [],
            'error' => null,
        ];
        if (!$configured) {
            return $status;
        }
        try {
            $properties = $this->searchConsole->listSites($site);
            $status['connected'] = true;
            $status['propertyAccessible'] = in_array($property, array_column($properties, 'siteUrl'), true);
            // Editors only see the configured property, not every property of the service account
            if (!$canConfigure) {
                $properties = array_values(array_filter($properties, static fn(array $p): bool => $p['siteUrl'] === $property));
            }
            $status['sites'] = $properties;
        } catch (ApiException $e) {
            $status['error'] = $e->getMessage();
        }
        return $status;
    }

    /**
     * @return array{configured: false}
     */
    private function authRemove(string $site): array
    {
        $this->serviceAccount->remove($site);
        return ['configured' => false];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function startJob(string $site, array $body): array
    {
        $id = $this->jobs->create($site, (string)($body['type'] ?? ''), [
            'force' => (bool)($body['force'] ?? false),
            'days' => (int)($body['days'] ?? 90),
        ]);
        return ['job' => JobService::toDto($this->jobs->find($site, $id))];
    }

    /**
     * @return array<string, mixed>
     */
    private function stepJob(string $site, int $id): array
    {
        $job = $this->jobs->step($site, $id, self::JOB_STEP_SECONDS) ?? $this->jobs->find($site, $id);
        if ($job === null) {
            throw new ApiException('Job not found', 404);
        }
        return ['job' => JobService::toDto($job)];
    }

    /**
     * @param array<string, mixed> $query
     * @return 'query'|'page'
     */
    private function dimension(array $query): string
    {
        return ($query['dimension'] ?? 'query') === 'page' ? 'page' : 'query';
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && $parsed !== []) {
            return $parsed;
        }
        $raw = (string)$request->getBody();
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function backendUser(): BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication) {
            throw new ApiException('Not logged in', 401);
        }
        return $user;
    }

    private function languageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
