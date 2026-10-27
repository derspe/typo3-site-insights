<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\EventListener;

use Derspe\SiteInsights\Controller\Backend\ApiController;
use Derspe\SiteInsights\Security\SiteAccess;
use Derspe\SiteInsights\Service\News\NewsRepository;
use Derspe\SiteInsights\Service\Page\PageInsightsService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Context\PageContext;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Backend\Controller\Event\RenderAdditionalContentToRecordListEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Adds the key figures of the news records of a folder above the page module (folders only)
 * and the list module. Only a placeholder is rendered here; the web component loads the data
 * via {@see ApiController}.
 */
final readonly class NewsFolderInsights
{
    public function __construct(
        private SiteAccess $siteAccess,
        private PageRenderer $pageRenderer,
        private PageInsightsService $pageInsights,
        private NewsRepository $news,
    ) {}

    #[AsEventListener(identifier: 'derspe-site-insights/news-page-layout')]
    public function pageLayout(ModifyPageLayoutContentEvent $event): void
    {
        $context = $event->getRequest()->getAttribute('pageContext');
        // Regular pages get the key figures of the page itself
        if ($context instanceof PageContext && (int)($context->pageRecord['doktype'] ?? 0) === PageRepository::DOKTYPE_SYSFOLDER) {
            $event->addHeaderContent($this->render($event->getRequest()));
        }
    }

    #[AsEventListener(identifier: 'derspe-site-insights/news-record-list')]
    public function recordList(RenderAdditionalContentToRecordListEvent $event): void
    {
        $request = $event->getRequest();
        $table = (string)($request->getQueryParams()['table'] ?? '');
        if ($table === '' || $table === NewsRepository::TABLE) {
            $event->addContentAbove($this->render($request));
        }
    }

    private function render(ServerRequestInterface $request): string
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        $context = $request->getAttribute('pageContext');
        if (!$user instanceof BackendUserAuthentication
            || !$user->check('modules', ApiController::MODULE)
            || !$user->check('tables_select', NewsRepository::TABLE)
            || !$context instanceof PageContext
            || $context->pageId <= 0
            || !$context->isAccessible()
            || !$context->site instanceof Site
            || !array_key_exists($context->site->getIdentifier(), $this->siteAccess->allowedSites($user))
            || !$this->news->hasRecords($context->pageId)
        ) {
            return '';
        }
        $site = $context->site->getIdentifier();
        $matomo = $this->pageInsights->matomoConfigured($site);
        $gsc = $this->pageInsights->gscConfigured($site);
        if (!$matomo && !$gsc) {
            return '';
        }
        $this->pageRenderer->loadJavaScriptModule('@derspe/site-insights/news.js');
        return sprintf(
            '<derspe-site-insights-news site="%s" folder="%d" language="%d"%s%s></derspe-site-insights-news>',
            htmlspecialchars($site, ENT_QUOTES),
            $context->pageId,
            $context->getPrimaryLanguageId(),
            $matomo ? ' matomo' : '',
            $gsc ? ' gsc' : ''
        );
    }
}
