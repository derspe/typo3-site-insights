<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Service\Matomo;

use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Settings\SiteSettings;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;

/**
 * Matomo Reporting API. All requests are sent as POST so the token never ends up in access logs.
 */
final readonly class MatomoClient
{
    public function __construct(
        private SiteSettings $settings,
        private GuzzleClientFactory $clientFactory,
    ) {}

    public function isConfigured(string $site): bool
    {
        return $this->settings->isSet($site, 'matomo_url')
            && $this->settings->isSet($site, 'matomo_token')
            && $this->settings->isSet($site, 'matomo_site_id');
    }

    /**
     * @param array<string, scalar> $params
     */
    public function call(string $site, string $method, array $params = []): mixed
    {
        [$url, $token, $siteId] = $this->config($site);
        $data = $this->post($url, [
            'module' => 'API',
            'method' => $method,
            'idSite' => $siteId,
            'token_auth' => $token,
            'format' => 'JSON',
        ] + $params);
        if (is_array($data) && ($data['result'] ?? null) === 'error') {
            throw new ApiException('Matomo API error: ' . ($data['message'] ?? 'unknown'), 502);
        }
        return $data;
    }

    /**
     * Several API calls in one HTTP request (API.getBulkRequest), results in the same order.
     *
     * @param list<array{0: string, 1: array<string, scalar>}> $calls
     * @return list<mixed>
     */
    public function bulk(string $site, array $calls): array
    {
        if ($calls === []) {
            return [];
        }
        [$url, $token, $siteId] = $this->config($site);
        $urls = [];
        foreach ($calls as [$method, $params]) {
            $urls[] = http_build_query(['method' => $method, 'idSite' => $siteId] + $params, '', '&', PHP_QUERY_RFC3986);
        }
        $data = $this->post($url, [
            'module' => 'API',
            'method' => 'API.getBulkRequest',
            'token_auth' => $token,
            'format' => 'JSON',
            'urls' => $urls,
        ]);
        if (!is_array($data) || !array_is_list($data)) {
            $message = is_array($data) ? (string)($data['message'] ?? 'unexpected response') : 'unexpected response';
            throw new ApiException('Matomo API error: ' . $message, 502);
        }
        foreach ($data as $result) {
            if (is_array($result) && ($result['result'] ?? null) === 'error') {
                throw new ApiException('Matomo API error: ' . ($result['message'] ?? 'unknown'), 502);
            }
        }
        return $data;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function config(string $site): array
    {
        if (!$this->isConfigured($site)) {
            throw new ApiException('Matomo is not configured. Please enter URL, token and site ID in the settings.', 503);
        }
        return [
            rtrim($this->settings->getString($site, 'matomo_url'), '/'),
            $this->settings->getString($site, 'matomo_token'),
            $this->settings->getString($site, 'matomo_site_id'),
        ];
    }

    /**
     * @param array<string, mixed> $formParams
     */
    private function post(string $url, array $formParams): mixed
    {
        try {
            $response = $this->clientFactory->getClient()->request('POST', $url . '/index.php', [
                'form_params' => $formParams,
                'timeout' => 30,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            throw new ApiException('Matomo not reachable: ' . $e->getMessage(), 502, $e);
        }
        if ($response->getStatusCode() >= 400) {
            throw new ApiException('Matomo not reachable (HTTP ' . $response->getStatusCode() . ')', 502);
        }
        try {
            return json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ApiException('Matomo returned invalid JSON', 502, $e);
        }
    }
}
