<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Service\Gsc;

use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Settings\SiteSettings;
use GuzzleHttp\Promise\Utils;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;

/**
 * Minimal Search Console REST client (Search Analytics, URL Inspection, Sites).
 */
final readonly class SearchConsoleClient
{
    private const BASE = 'https://searchconsole.googleapis.com';

    public function __construct(
        private GoogleServiceAccount $serviceAccount,
        private SiteSettings $settings,
        private GuzzleClientFactory $clientFactory,
    ) {}

    public function propertyUrl(string $site): string
    {
        $property = $this->settings->getString($site, 'gsc_site_url');
        if ($property === '') {
            throw new ApiException('Search Console is not configured: property URL missing', 503);
        }
        return $property;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function searchAnalytics(string $site, array $body): array
    {
        return $this->searchAnalyticsMany($site, [$body])[0];
    }

    /**
     * Runs several Search Analytics queries concurrently.
     *
     * @param array<int|string, array<string, mixed>> $bodies
     * @return array<int|string, array<string, mixed>>
     */
    public function searchAnalyticsMany(string $site, array $bodies): array
    {
        if ($bodies === []) {
            return [];
        }
        $url = self::BASE . '/webmasters/v3/sites/' . rawurlencode($this->propertyUrl($site)) . '/searchAnalytics/query';
        $client = $this->clientFactory->getClient();
        $options = $this->options($site);
        $promises = [];
        foreach ($bodies as $key => $body) {
            $promises[$key] = $client->requestAsync('POST', $url, $options + ['json' => self::cleanBody($body)]);
        }
        try {
            $responses = Utils::unwrap($promises);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ApiException('Search Console not reachable: ' . $e->getMessage(), 502, $e);
        }
        $results = [];
        foreach ($responses as $key => $response) {
            $results[$key] = $this->decode($response);
        }
        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    public function inspectUrl(string $site, string $url): array
    {
        $response = $this->send('POST', self::BASE . '/v1/urlInspection/index:inspect', $site, [
            'json' => ['inspectionUrl' => $url, 'siteUrl' => $this->propertyUrl($site)],
        ]);
        return $this->decode($response);
    }

    /**
     * @return list<array{siteUrl: string, permissionLevel: string}>
     */
    public function listSites(string $site): array
    {
        $data = $this->decode($this->send('GET', self::BASE . '/webmasters/v3/sites', $site));
        return array_map(static fn(array $s): array => [
            'siteUrl' => (string)($s['siteUrl'] ?? ''),
            'permissionLevel' => (string)($s['permissionLevel'] ?? ''),
        ], $data['siteEntry'] ?? []);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function send(string $method, string $url, string $site, array $options = []): ResponseInterface
    {
        try {
            return $this->clientFactory->getClient()->request($method, $url, $this->options($site) + $options);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ApiException('Search Console not reachable: ' . $e->getMessage(), 502, $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function options(string $site): array
    {
        return [
            'headers' => ['Authorization' => 'Bearer ' . $this->serviceAccount->accessToken($site)],
            'timeout' => 60,
            'http_errors' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $data = json_decode((string)$response->getBody(), true);
        $status = $response->getStatusCode();
        if ($status === 429 || ($data['error']['status'] ?? '') === 'RESOURCE_EXHAUSTED') {
            throw new ApiException('Search Console API quota exhausted', 429);
        }
        if ($status === 401) {
            throw new ApiException('Google rejected the access token of the service account', 503);
        }
        if ($status === 403) {
            // Configuration problems: reported as 503, so jobs stop and the UI points to the settings
            $reasons = array_column((array)($data['error']['details'] ?? []), 'reason');
            if (in_array('SERVICE_DISABLED', $reasons, true) || in_array('accessNotConfigured', array_column((array)($data['error']['errors'] ?? []), 'reason'), true)) {
                throw new ApiException('The Search Console API is not enabled in the Google Cloud project of the service account.', 503);
            }
            throw new ApiException('The service account has no access to this property. Add its e-mail address as a user of the property in Search Console.', 503);
        }
        if ($status >= 400) {
            throw new ApiException((string)($data['error']['message'] ?? ('Search Console API error (HTTP ' . $status . ')')), 502);
        }
        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function cleanBody(array $body): array
    {
        return array_filter($body, static fn(mixed $v): bool => $v !== null);
    }
}
