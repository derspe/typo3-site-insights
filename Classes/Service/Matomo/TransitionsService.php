<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Service\Matomo;

use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Service\ApiCache;

/**
 * Matomo Transitions of one page: entries from outside, previous and following pages,
 * outlinks, downloads and exits, plus its average time on page and bounce rate, optionally
 * compared with another period.
 *
 * Matomo does not archive transitions but computes them from the raw log data on every
 * request, so the results are cached for a day.
 */
final readonly class TransitionsService
{
    // Rows per list, Matomo groups the rest as "Others"
    public const LIMIT = 10;
    private const LIFETIME = 86400;

    public function __construct(
        private MatomoClient $client,
        private ApiCache $cache,
    ) {}

    /**
     * "engagement" holds the figures of the archived page report; with a compare range
     * "compare" holds the same figures of that period.
     *
     * @param string $url absolute URL or path of the page
     * @param array{startDate: string, endDate: string}|null $compareRange
     * @return array<string, mixed>
     */
    public function forUrl(string $site, string $url, string $startDate, string $endDate, string $segment = '', ?array $compareRange = null): array
    {
        $url = $this->matomoUrl($site, $url);
        $range = ['startDate' => $startDate, 'endDate' => $endDate];
        $result = $this->cache->remember([$site, 'matomo', 'transitions', 3, $url, $range, $segment], function () use ($site, $url, $range, $segment): array {
            $params = self::params($url, $range, $segment) + ['limitBeforeGrouping' => self::LIMIT];
            try {
                $data = $this->client->call($site, 'Transitions.getTransitionsForPageUrl', $params);
            } catch (ApiException $e) {
                // Unknown URL or no pageviews in the period
                if (str_contains($e->getMessage(), 'NoDataForAction')) {
                    return ['url' => $url, 'range' => $range, 'noData' => true];
                }
                if (str_contains($e->getMessage(), 'PeriodNotAllowed')) {
                    throw new ApiException('Matomo does not compute transitions for a period this long ([Transitions] max_period_allowed)', 400, $e);
                }
                throw $e;
            }
            $engagement = $this->engagement($site, self::params($url, $range, $segment));
            return ['url' => $url, 'range' => $range, 'noData' => false]
                + MatomoNormalizer::transitions($data)
                + ['avgTimeOnPage' => $engagement['avgTimeOnPage'], 'bounceRate' => $engagement['bounceRate'], 'engagement' => $engagement];
        }, self::LIFETIME);
        if ($compareRange !== null && $result['noData'] === false) {
            $result['compare'] = $this->cache->remember(
                [$site, 'matomo', 'engagement', 1, $url, $compareRange, $segment],
                fn(): array => $this->engagement($site, self::params($url, $compareRange, $segment)),
                self::LIFETIME
            );
            $result['compareRange'] = $compareRange;
        }
        return $result;
    }

    /**
     * @param array{startDate: string, endDate: string} $range
     * @return array<string, scalar>
     */
    private static function params(string $url, array $range, string $segment): array
    {
        $day = $range['startDate'] === $range['endDate'];
        return [
            'pageUrl' => $url,
            'period' => $day ? 'day' : 'range',
            'date' => $day ? $range['startDate'] : $range['startDate'] . ',' . $range['endDate'],
        ] + ($segment !== '' ? ['segment' => $segment] : []);
    }

    /**
     * Time on page and bounce rate come from the archived page report; without them the
     * transitions are still shown.
     *
     * @param array<string, scalar> $params
     * @return array{avgTimeOnPage: int|null, bounceRate: float|null, pageviews: int, entries: int, exits: int}
     */
    private function engagement(string $site, array $params): array
    {
        try {
            return MatomoNormalizer::pageEngagement($this->client->call($site, 'Actions.getPageUrl', $params));
        } catch (ApiException) {
            return MatomoNormalizer::pageEngagement([]);
        }
    }

    /**
     * Matomo finds a page by its full URL. Paths and URLs of a host Matomo does not know
     * (e.g. a development environment) get the main URL of the Matomo site.
     */
    private function matomoUrl(string $site, string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower((string)($parts['host'] ?? ''));
        $siteUrls = $this->siteUrls($site);
        $known = array_map(static fn(string $u): string => self::bareHost((string)parse_url($u, PHP_URL_HOST)), $siteUrls);
        if (($host !== '' && in_array(self::bareHost($host), $known, true)) || $siteUrls === []) {
            return $url;
        }
        $path = '/' . ltrim((string)($parts['path'] ?? ''), '/');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        return rtrim($siteUrls[0], '/') . $path . $query;
    }

    /**
     * @return list<string>
     */
    private function siteUrls(string $site): array
    {
        try {
            $urls = $this->cache->remember([$site, 'matomo', 'siteUrls'], fn(): mixed => $this->client->call($site, 'SitesManager.getSiteUrlsFromId'), self::LIFETIME);
        } catch (ApiException $e) {
            if ($e->status === 503) {
                throw $e;
            }
            return [];
        }
        return is_array($urls) ? array_values(array_filter($urls, static fn(mixed $u): bool => is_string($u) && str_contains($u, '://'))) : [];
    }

    private static function bareHost(string $host): string
    {
        $host = strtolower($host);
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
