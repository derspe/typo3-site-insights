<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Service\Gsc;

use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Settings\SiteSettings;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * URLs monitored by the index check: the XML sitemap (incl. sitemap index) plus internal
 * links harvested from configured source pages (for pages deliberately left out of the sitemap).
 */
final readonly class UrlCollector
{
    private const USER_AGENT = 'Mozilla/5.0 (compatible; TYPO3-SiteInsights/1.0)';
    public const MAX_CHILD_SITEMAPS = 50;
    public const MAX_URLS = 20000;
    public const MAX_BYTES = 20 * 1024 * 1024;

    public function __construct(
        private SiteSettings $settings,
        private SiteFinder $siteFinder,
        private GuzzleClientFactory $clientFactory,
        private LoggerInterface $logger,
    ) {}

    public function sitemapUrl(string $site): string
    {
        $configured = $this->settings->getString($site, 'gsc_sitemap_url');
        if ($configured !== '') {
            return $configured;
        }
        try {
            $base = rtrim((string)$this->siteFinder->getSiteByIdentifier($site)->getBase(), '/');
        } catch (\Throwable) {
            return '';
        }
        return str_starts_with($base, 'http') ? $base . '/sitemap.xml' : '';
    }

    /**
     * @return list<string>
     */
    public function collect(string $site): array
    {
        $sitemapUrl = $this->sitemapUrl($site);
        if ($sitemapUrl === '') {
            throw new ApiException('No sitemap URL configured', 503);
        }
        $urls = $this->sitemapUrls($sitemapUrl);
        $include = $this->settings->getString($site, 'gsc_extra_include');
        foreach ($this->settings->getList($site, 'gsc_extra_source_urls') as $source) {
            try {
                array_push($urls, ...array_slice($this->pageLinks($source, $include), 0, self::MAX_URLS));
            } catch (\Throwable $e) {
                $this->logger->warning('Link harvest failed for {url}: {message}', ['url' => $source, 'message' => $e->getMessage()]);
            }
        }
        return array_slice(array_values(array_unique($urls)), 0, self::MAX_URLS);
    }

    /**
     * Page URLs of a sitemap or sitemap index. Child sitemaps are only followed on the
     * host of the sitemap itself (the XML comes from outside and must not make this
     * server request arbitrary, e.g. internal, hosts).
     *
     * @return list<string>
     */
    public function sitemapUrls(string $sitemapUrl): array
    {
        $host = strtolower((string)parse_url($sitemapUrl, PHP_URL_HOST));
        return array_slice($this->readSitemap($sitemapUrl, $host, 0), 0, self::MAX_URLS);
    }

    /**
     * @return list<string>
     */
    private function readSitemap(string $sitemapUrl, string $host, int $depth): array
    {
        $document = $this->parseXml($this->fetch($sitemapUrl, 15));
        if ($document === null) {
            return [];
        }
        $document->registerXPathNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $pages = self::texts($document->xpath('//sm:url/sm:loc') ?: $document->xpath('//url/loc') ?: []);
        if ($pages !== [] || $depth >= 2) {
            return array_values(array_unique($pages));
        }
        $children = self::sameHost(self::texts($document->xpath('//sm:sitemap/sm:loc') ?: $document->xpath('//sitemap/loc') ?: []), $host);
        $urls = [];
        foreach (array_slice($children, 0, self::MAX_CHILD_SITEMAPS) as $child) {
            array_push($urls, ...$this->readSitemap($child, $host, $depth + 1));
            if (count($urls) >= self::MAX_URLS) {
                break;
            }
        }
        // Sub-sitemaps can contain the same URL (e.g. news and google news)
        return array_values(array_unique($urls));
    }

    /**
     * @param list<string> $urls
     * @return list<string>
     */
    public static function sameHost(array $urls, string $host): array
    {
        return array_values(array_filter($urls, static function (string $url) use ($host): bool {
            return in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
                && strtolower((string)parse_url($url, PHP_URL_HOST)) === $host;
        }));
    }

    /**
     * Internal links of a page (without query and hash), optionally filtered by a path fragment.
     *
     * @return list<string>
     */
    public function pageLinks(string $pageUrl, string $includePattern = ''): array
    {
        $html = $this->fetch($pageUrl, 20);
        $base = parse_url($pageUrl);
        $host = $base['host'] ?? '';
        $scheme = $base['scheme'] ?? 'https';
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $links = [];
        foreach ($dom->getElementsByTagName('a') as $anchor) {
            $href = trim($anchor->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:') || str_starts_with($href, 'javascript:')) {
                continue;
            }
            $absolute = self::resolve($href, $scheme, $host, (string)($base['path'] ?? '/'));
            $parts = parse_url($absolute);
            if (($parts['host'] ?? '') !== $host) {
                continue;
            }
            $clean = ($parts['scheme'] ?? $scheme) . '://' . $host . ($parts['path'] ?? '/');
            if ($includePattern !== '' && !str_contains($clean, $includePattern)) {
                continue;
            }
            $links[$clean] = true;
        }
        return array_keys($links);
    }

    private static function resolve(string $href, string $scheme, string $host, string $basePath): string
    {
        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }
        if (str_starts_with($href, '//')) {
            return $scheme . ':' . $href;
        }
        if (str_starts_with($href, '/')) {
            return $scheme . '://' . $host . $href;
        }
        $directory = str_ends_with($basePath, '/') ? $basePath : (dirname($basePath) === '/' ? '/' : dirname($basePath) . '/');
        return $scheme . '://' . $host . $directory . $href;
    }

    private function fetch(string $url, int $timeout): string
    {
        try {
            $response = $this->clientFactory->getClient()->request('GET', $url, [
                'headers' => ['User-Agent' => self::USER_AGENT],
                'timeout' => $timeout,
                'http_errors' => false,
                'allow_redirects' => ['max' => 3, 'protocols' => ['http', 'https']],
                'stream' => true,
            ]);
        } catch (\Throwable $e) {
            throw new ApiException('Could not fetch ' . $url, 502, $e);
        }
        if ($response->getStatusCode() >= 400) {
            throw new ApiException('Could not fetch ' . $url . ' (HTTP ' . $response->getStatusCode() . ')', 502);
        }
        $body = $response->getBody();
        $content = '';
        while (!$body->eof()) {
            $content .= $body->read(65536);
            if (strlen($content) > self::MAX_BYTES) {
                throw new ApiException('Response of ' . $url . ' is too large', 502);
            }
        }
        return $content;
    }

    private function parseXml(string $xml): ?\SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $document === false ? null : $document;
    }

    /**
     * @param array<\SimpleXMLElement> $nodes
     * @return list<string>
     */
    private static function texts(array $nodes): array
    {
        return array_values(array_filter(array_map(static fn(\SimpleXMLElement $n): string => trim((string)$n), $nodes)));
    }
}
