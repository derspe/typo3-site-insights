<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Service\Page;

use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Service\News\NewsRepository;
use Derspe\SiteInsights\Settings\SettingsValidator;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Routing\SiteMatcher;
use TYPO3\CMS\Core\Routing\SiteRouteResult;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Title and description of the record behind a URL, as entered by the editors: SEO title or
 * title of the page, or of the news record of a news detail URL (like EXT:news, the teaser
 * stands in for a missing description). Only the path of the URL is
 * resolved, on the host of the site base, so URLs of Search Console or Matomo (production
 * host) also work in development environments.
 */
final readonly class PageSnippetService
{
    // A teaser standing in for the description is cut to this length (incl. the ellipsis)
    public const TEASER_LENGTH = 280;

    public function __construct(
        private SiteFinder $siteFinder,
        private SiteMatcher $siteMatcher,
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @return array{type: string, title: string, description: string}
     */
    public function forUrl(string $site, string $url): array
    {
        if (!SettingsValidator::isPageUrlOrPath($url)) {
            throw new ApiException('Invalid page URL', 400);
        }
        try {
            $siteEntity = $this->siteFinder->getSiteByIdentifier($site);
        } catch (SiteNotFoundException) {
            throw new ApiException('Site not found', 404);
        }
        $request = new ServerRequest(self::targetUrl($siteEntity, $url));
        try {
            $siteResult = $this->siteMatcher->matchRequest($request);
            if (!$siteResult instanceof SiteRouteResult || $siteResult->getSite()->getIdentifier() !== $site || $siteResult->getLanguage() === null) {
                throw new ApiException('No page for this URL', 404);
            }
            $arguments = $siteEntity->getRouter()->matchRequest($request, $siteResult);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new ApiException('No page for this URL', 404);
        }
        if (!$arguments instanceof PageArguments) {
            throw new ApiException('No page for this URL', 404);
        }
        return $this->forRoute($arguments->getPageId(), $siteResult->getLanguage()->getLanguageId(), $arguments->getRouteArguments())
            ?? throw new ApiException('No page for this URL', 404);
    }

    /**
     * @param array<string, mixed> $routeArguments
     * @return array{type: string, title: string, description: string}|null
     */
    public function forRoute(int $pageId, int $languageId, array $routeArguments): ?array
    {
        $newsUid = self::newsUid($routeArguments);
        if ($newsUid > 0 && isset($GLOBALS['TCA'][NewsRepository::TABLE])) {
            $news = $this->record(NewsRepository::TABLE, $newsUid, $languageId);
            if ($news !== null) {
                $snippet = self::snippet('news', $news, 'alternative_title');
                if ($snippet['description'] === '') {
                    $snippet['description'] = self::crop(self::clean(html_entity_decode(strip_tags((string)($news['teaser'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                }
                return $snippet;
            }
        }
        $page = $this->record('pages', $pageId, $languageId);
        return $page === null ? null : self::snippet('page', $page, 'seo_title');
    }

    /**
     * Path and query of the URL on the host of the site base.
     */
    private static function targetUrl(Site $site, string $url): string
    {
        $origin = SettingsValidator::origin((string)$site->getBase());
        if ($origin === '') {
            throw new ApiException('The site base has no host', 503);
        }
        $parts = parse_url($url);
        $path = '/' . ltrim((string)($parts['path'] ?? '/'), '/');
        return $origin . $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * Uid of the news record of a news detail route (argument "news" of a tx_news plugin).
     *
     * @param array<string, mixed> $routeArguments
     */
    private static function newsUid(array $routeArguments): int
    {
        foreach ($routeArguments as $namespace => $values) {
            if (str_starts_with((string)$namespace, 'tx_news') && is_array($values) && isset($values['news'])) {
                return (int)$values['news'];
            }
        }
        return 0;
    }

    /**
     * The record in the given language: its translation, else the record itself if it is shown
     * in all languages or the default language is requested.
     *
     * @return array<string, mixed>|null
     */
    private function record(string $table, int $uid, int $languageId): ?array
    {
        $connection = $this->connectionPool->getConnectionForTable($table);
        $versioned = ($GLOBALS['TCA'][$table]['ctrl']['versioningWS'] ?? false) === true;
        $live = $versioned ? ['t3ver_wsid' => 0] : [];
        if ($languageId > 0) {
            $translation = $connection->select(['*'], $table, ['l10n_parent' => $uid, 'sys_language_uid' => $languageId] + $live, [], [], 1)->fetchAssociative();
            if ($translation !== false) {
                return $translation;
            }
        }
        $row = $connection->select(['*'], $table, ['uid' => $uid] + $live, [], [], 1)->fetchAssociative();
        if ($row === false) {
            return null;
        }
        $rowLanguage = (int)($row['sys_language_uid'] ?? 0);
        return $rowLanguage === -1 || $rowLanguage === $languageId ? $row : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{type: string, title: string, description: string}
     */
    private static function snippet(string $type, array $row, string $titleField): array
    {
        $title = self::clean($row[$titleField] ?? '');
        return [
            'type' => $type,
            'title' => $title !== '' ? $title : self::clean($row['title'] ?? ''),
            'description' => self::clean($row['description'] ?? ''),
        ];
    }

    /**
     * Cuts the text at a word boundary and appends an ellipsis.
     */
    private static function crop(string $text): string
    {
        if (mb_strlen($text) <= self::TEASER_LENGTH) {
            return $text;
        }
        $cut = mb_substr($text, 0, self::TEASER_LENGTH - 1);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > self::TEASER_LENGTH * 0.6) {
            $cut = mb_substr($cut, 0, $space);
        }
        return rtrim($cut, " \t,.;:-–") . '…';
    }

    private static function clean(mixed $text): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', (string)$text));
    }
}
