<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\EventListener;

use Derspe\SiteInsights\Controller\Backend\ApiController;
use Derspe\SiteInsights\Security\SiteAccess;
use Derspe\SiteInsights\Service\Page\PageInsightsService;
use TYPO3\CMS\Backend\Context\PageContext;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Adds the key figures of the current page to the header of the page module.
 * Only a placeholder is rendered here; the web component loads the data via {@see ApiController}.
 */
#[AsEventListener(identifier: 'derspe-site-insights/page-layout')]
final readonly class PageLayoutInsights
{
    // Pages without a frontend URL of their own
    private const SKIPPED_DOKTYPES = [
        PageRepository::DOKTYPE_LINK,
        PageRepository::DOKTYPE_SHORTCUT,
        PageRepository::DOKTYPE_BE_USER_SECTION,
        PageRepository::DOKTYPE_MOUNTPOINT,
        PageRepository::DOKTYPE_SPACER,
        PageRepository::DOKTYPE_SYSFOLDER,
    ];

    public function __construct(
        private SiteAccess $siteAccess,
        private PageRenderer $pageRenderer,
        private PageInsightsService $pageInsights,
    ) {}

    public function __invoke(ModifyPageLayoutContentEvent $event): void
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        $context = $event->getRequest()->getAttribute('pageContext');
        if (!$user instanceof BackendUserAuthentication
            || !$user->check('modules', ApiController::MODULE)
            || !$context instanceof PageContext
            || $context->pageId <= 0
            || !$context->isAccessible()
            || !$context->site instanceof Site
            || in_array((int)($context->pageRecord['doktype'] ?? 0), self::SKIPPED_DOKTYPES, true)
            || !array_key_exists($context->site->getIdentifier(), $this->siteAccess->allowedSites($user))
        ) {
            return;
        }
        $site = $context->site->getIdentifier();
        $matomo = $this->pageInsights->matomoConfigured($site);
        $gsc = $this->pageInsights->gscConfigured($site);
        if (!$matomo && !$gsc) {
            return;
        }
        $this->pageRenderer->loadJavaScriptModule('@derspe/site-insights/page.js');
        $event->addHeaderContent(sprintf(
            '<derspe-site-insights-page site="%s" page="%d" language="%d"%s%s></derspe-site-insights-page>',
            htmlspecialchars($site, ENT_QUOTES),
            $context->pageId,
            $context->getPrimaryLanguageId(),
            $matomo ? ' matomo' : '',
            $gsc ? ' gsc' : ''
        ));
    }
}
