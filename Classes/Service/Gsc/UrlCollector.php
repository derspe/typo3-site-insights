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
                array_push($urls, ...$this->pageLinks($source, $include));
            } catch (\Throwable $e) {
                $this->logger->warning('Link harvest failed for {url}: {message}', ['url' => $source, 'message' => $e->getMessage()]);
            }
        }
        return array_values(array_unique($urls));
    }

    /**
     * @return list<string>
     */
    public function sitemapUrls(string $sitemapUrl, int $depth = 0): array
    {
        $xml = $this->fetch($sitemapUrl, 15);
        $document = $this->parseXml($xml);
        if ($document === null) {
            return [];
        }
        $document->registerXPathNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $pages = self::texts($document->xpath('//sm:url/sm:loc') ?: $document->xpath('//url/loc') ?: []);
        if ($pages !== [] || $depth > 2) {
            return $pages;
        }
        $children = self::texts($document->xpath('//sm:sitemap/sm:loc') ?: $document->xpath('//sitemap/loc') ?: []);
        $urls = [];
        foreach ($children as $child) {
            array_push($urls, ...$this->sitemapUrls($child, $depth + 1));
        }
        // Sub-sitemaps can contain the same URL (e.g. news and google news)
        return array_values(array_unique($urls));
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
            ]);
        } catch (\Throwable $e) {
            throw new ApiException('Could not fetch ' . $url . ': ' . $e->getMessage(), 502, $e);
        }
        if ($response->getStatusCode() >= 400) {
            throw new ApiException('Could not fetch ' . $url . ' (HTTP ' . $response->getStatusCode() . ')', 502);
        }
        return (string)$response->getBody();
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
