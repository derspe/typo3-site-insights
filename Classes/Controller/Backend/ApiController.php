<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Controller\Backend;

use Derspe\SiteInsights\Domain\Repository\InspectRepository;
use Derspe\SiteInsights\Domain\Repository\SnapshotRepository;
use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Service\Gsc\GoogleOAuth;
use Derspe\SiteInsights\Service\Gsc\GscDashboardService;
use Derspe\SiteInsights\Service\Gsc\InspectService;
use Derspe\SiteInsights\Service\Gsc\SearchConsoleClient;
use Derspe\SiteInsights\Service\Gsc\SnapshotService;
use Derspe\SiteInsights\Service\Gsc\UrlCollector;
use Derspe\SiteInsights\Service\Job\JobService;
use Derspe\SiteInsights\Service\Matomo\MatomoDashboardService;
use Derspe\SiteInsights\Settings\SiteSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Single AJAX entry point of the backend module and the dashboard widgets.
 * The endpoint is passed as parameter "endpoint" (e.g. "gsc/dashboard"), the site as "site".
 */
final readonly class ApiController
{
    public const MODULE = 'site_insights';
    private const JOB_STEP_SECONDS = 8.0;

    private const SETTING_KEYS = [
        'matomo_url' => 'string',
        'matomo_token' => 'secret',
        'matomo_site_id' => 'string',
        'matomo_goal_ids' => 'list',
        'gsc_oauth_client_id' => 'string',
        'gsc_oauth_client_secret' => 'secret',
        'gsc_site_url' => 'string',
        'gsc_brand_terms' => 'list',
        'gsc_snapshot_top_n' => 'int',
        'gsc_top_n' => 'int',
        'gsc_sitemap_url' => 'string',
        'gsc_extra_source_urls' => 'list',
        'gsc_extra_include' => 'string',
    ];

    public function __construct(
        private SiteFinder $siteFinder,
        private SiteSettings $settings,
        private MatomoDashboardService $matomo,
        private GscDashboardService $gsc,
        private SearchConsoleClient $searchConsole,
        private GoogleOAuth $oauth,
        private SnapshotService $snapshots,
        private SnapshotRepository $snapshotRepository,
        private InspectService $inspect,
        private InspectRepository $inspectRepository,
        private UrlCollector $urlCollector,
        private JobService $jobs,
        private LoggerInterface $logger,
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
            if ($endpoint === 'meta') {
                return new JsonResponse($this->meta());
            }
            $site = $this->resolveSite((string)($query['site'] ?? $body['site'] ?? ''))->getIdentifier();
            $data = match (true) {
                $endpoint === 'settings' && !$isPost => $this->readSettings($site),
                $endpoint === 'settings' && $isPost => $this->writeSettings($site, (array)($body['values'] ?? [])),

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
                $endpoint === 'gsc/visibility/rebuild' && $isPost => ['days' => $this->snapshots->rebuildVisibility($site)],
                $endpoint === 'gsc/tracker' => $this->gsc->trackerList($site, $this->dimension($query), (string)($query['order'] ?? 'clicks')),
                $endpoint === 'gsc/tracker/series' => $this->gsc->trackerSeries($site, $this->dimension($query), (string)($query['key'] ?? '')),
                $endpoint === 'gsc/snapshot/status' => $this->snapshotStatus($site),
                $endpoint === 'gsc/snapshot/run' && $isPost => $this->snapshots->run($site, null, (bool)($body['force'] ?? false)),
                $endpoint === 'gsc/inspect' && !$isPost => InspectRepository::toDto($this->inspectCached($site, (string)($query['url'] ?? '')), true),
                $endpoint === 'gsc/inspect' && $isPost => $this->inspectNow($site, (string)($body['url'] ?? ''), (bool)($body['force'] ?? false)),
                $endpoint === 'gsc/index/pages' => $this->inspect->pages($site),
                $endpoint === 'gsc/auth/status' => $this->authStatus($site),
                $endpoint === 'gsc/auth/start' && $isPost => $this->authStart($site),
                $endpoint === 'gsc/auth/disconnect' && $isPost => $this->authDisconnect($site),

                $endpoint === 'job/start' && $isPost => $this->startJob($site, $body),
                $endpoint === 'job/active' => ['job' => JobService::toDto($this->jobs->findActive($site, (string)($query['type'] ?? '')))],
                $endpoint === 'job/step' && $isPost => $this->stepJob($site, (int)($body['id'] ?? 0)),

                default => throw new ApiException('Unknown endpoint', 404),
            };
            return new JsonResponse($data);
        } catch (ApiException $e) {
            return new JsonResponse(['error' => $e->getMessage()], $e->status);
        } catch (\Throwable $e) {
            $this->logger->error('Site Insights API error on {endpoint}: {message}', ['endpoint' => $endpoint, 'message' => $e->getMessage(), 'exception' => $e]);
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(): array
    {
        $sites = array_values(array_map(static fn(Site $site): array => [
            'identifier' => $site->getIdentifier(),
            'title' => (string)($site->getConfiguration()['websiteTitle'] ?? '') ?: $site->getIdentifier(),
            'base' => (string)$site->getBase(),
        ], $this->siteFinder->getAllSites()));
        $labels = $this->languageService()->getLabelsFromResource('EXT:derspe_site_insights/Resources/Private/Language/locallang_js.xlf');
        return [
            'sites' => $sites,
            'isAdmin' => $this->backendUser()->isAdmin(),
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
        foreach (self::SETTING_KEYS as $key => $type) {
            if ($type === 'secret') {
                $values[$key . '_set'] = $this->settings->isSet($site, $key);
                continue;
            }
            $values[$key] = $this->settings->get($site, $key);
        }
        $values['gsc_sitemap_url_default'] = $this->urlCollector->sitemapUrl($site);
        $values['oauth_redirect_uri'] = $this->oauth->redirectUri();
        return $values;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function writeSettings(string $site, array $values): array
    {
        if (!$this->backendUser()->isAdmin()) {
            throw new ApiException('Only administrators can change the settings', 403);
        }
        foreach ($values as $key => $value) {
            $type = self::SETTING_KEYS[$key] ?? null;
            switch ($type) {
                case 'secret':
                    // Empty = keep the stored secret
                    if (is_string($value) && trim($value) !== '') {
                        $this->settings->set($site, $key, trim($value));
                    }
                    break;
                case 'string':
                    $this->settings->set($site, $key, trim((string)$value));
                    break;
                case 'int':
                    if ((int)$value > 0) {
                        $this->settings->set($site, $key, min(25000, (int)$value));
                    }
                    break;
                case 'list':
                    $list = is_array($value) ? $value : explode(',', (string)$value);
                    $this->settings->set($site, $key, array_values(array_filter(array_map(static fn($v): string => trim((string)$v), $list), static fn(string $v): bool => $v !== '')));
                    break;
            }
        }
        return $this->readSettings($site);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotStatus(string $site): array
    {
        return [
            'latestRun' => $this->snapshotRepository->latestRun($site),
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
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new ApiException('Please enter a valid URL', 400);
        }
        $result = $this->inspect->inspect($site, $url, $force);
        return InspectRepository::toDto($result['row'], $result['cacheHit']);
    }

    /**
     * @return array<string, mixed>
     */
    private function authStatus(string $site): array
    {
        $status = [
            'configured' => $this->oauth->isConfigured($site),
            'connected' => $this->oauth->isConnected($site),
            'obtainedAt' => $this->settings->getString($site, 'gsc_token_obtained_at') ?: null,
            'sites' => [],
            'error' => null,
        ];
        if ($status['connected']) {
            try {
                $status['sites'] = $this->searchConsole->listSites($site);
            } catch (ApiException $e) {
                // Revoked token etc.: report as disconnected, not as server error
                $status['connected'] = $e->status !== 503;
                $status['error'] = $e->getMessage();
            }
        }
        return $status;
    }

    /**
     * @return array{url: string}
     */
    private function authStart(string $site): array
    {
        if (!$this->backendUser()->isAdmin()) {
            throw new ApiException('Only administrators can connect Google', 403);
        }
        return ['url' => $this->oauth->buildAuthUrl($site, (int)($this->backendUser()->user['uid'] ?? 0))];
    }

    /**
     * @return array{connected: false}
     */
    private function authDisconnect(string $site): array
    {
        if (!$this->backendUser()->isAdmin()) {
            throw new ApiException('Only administrators can disconnect Google', 403);
        }
        $this->oauth->disconnect($site);
        return ['connected' => false];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function startJob(string $site, array $body): array
    {
        $type = (string)($body['type'] ?? '');
        $id = $this->jobs->create($site, $type, [
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

    private function resolveSite(string $identifier): Site
    {
        $sites = $this->siteFinder->getAllSites();
        if ($sites === []) {
            throw new ApiException('No site configuration found', 503);
        }
        return $sites[$identifier] ?? reset($sites);
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
