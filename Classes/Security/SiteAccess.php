<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Security;

use Derspe\SiteInsights\Http\ApiException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Sites a backend user may see: administrators all, everybody else only sites whose
 * root page is inside one of their DB mounts.
 */
final readonly class SiteAccess
{
    public function __construct(
        private SiteFinder $siteFinder,
    ) {}

    /**
     * @return array<string, Site>
     */
    public function allowedSites(BackendUserAuthentication $user): array
    {
        $sites = $this->siteFinder->getAllSites();
        if ($user->isAdmin()) {
            return $sites;
        }
        return array_filter($sites, static fn(Site $site): bool => $user->isInWebMount($site->getRootPageId()) !== null);
    }

    /**
     * Resolves the requested site; an empty identifier selects the first allowed site.
     */
    public function resolve(BackendUserAuthentication $user, string $identifier): Site
    {
        $sites = $this->allowedSites($user);
        if ($sites === []) {
            throw new ApiException('No site available for your account', 403);
        }
        if ($identifier === '') {
            return reset($sites);
        }
        return $sites[$identifier] ?? throw new ApiException('Access to this site denied', 403);
    }
}
