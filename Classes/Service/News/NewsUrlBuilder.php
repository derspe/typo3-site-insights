<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Service\News;

use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Frontend URL of the detail view and backend URL of the edit form of a news record.
 */
class NewsUrlBuilder
{
    public function __construct(
        private readonly UriBuilder $uriBuilder,
    ) {}

    /**
     * URL as built by the route enhancer of the site, null if none can be generated.
     */
    public function detailUrl(Site $site, int $detailPid, int $newsUid, int $language): ?string
    {
        try {
            return (string)$site->getRouter()->generateUri($detailPid, [
                '_language' => $language,
                'tx_news_pi1' => ['action' => 'detail', 'controller' => 'News', 'news' => $newsUid],
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    public function editUrl(int $uid, string $returnUrl): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [NewsRepository::TABLE => [$uid => 'edit']],
            'returnUrl' => $returnUrl,
        ]);
    }
}
