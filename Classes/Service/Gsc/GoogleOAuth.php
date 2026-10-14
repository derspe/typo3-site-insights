<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Service\Gsc;

use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Service\ApiCache;
use Derspe\SiteInsights\Settings\SiteSettings;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;

/**
 * OAuth 2.0 web server flow for the Search Console API (read-only scope).
 *
 * The refresh token is stored encrypted per site; short-lived access tokens are cached.
 */
final readonly class GoogleOAuth
{
    public const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const STATE_TABLE = 'tx_derspesiteinsights_oauth_state';
    private const STATE_TTL = 600;

    public function __construct(
        private SiteSettings $settings,
        private GuzzleClientFactory $clientFactory,
        private ConnectionPool $connectionPool,
        private UriBuilder $uriBuilder,
        private ApiCache $cache,
    ) {}

    public function isConfigured(string $site): bool
    {
        return $this->settings->isSet($site, 'gsc_oauth_client_id') && $this->settings->isSet($site, 'gsc_oauth_client_secret');
    }

    public function isConnected(string $site): bool
    {
        return $this->settings->isSet($site, 'gsc_refresh_token');
    }

    public function redirectUri(): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute('derspe_site_insights_oauth_callback', [], UriBuilder::ABSOLUTE_URL);
    }

    public function buildAuthUrl(string $site, int $backendUserId): string
    {
        if (!$this->isConfigured($site)) {
            throw new ApiException('OAuth is not configured: client ID or secret missing', 503);
        }
        $state = bin2hex(random_bytes(16));
        $connection = $this->connectionPool->getConnectionForTable(self::STATE_TABLE);
        $connection->executeStatement('DELETE FROM ' . self::STATE_TABLE . ' WHERE created_at < ?', [time() - self::STATE_TTL]);
        $connection->insert(self::STATE_TABLE, [
            'state' => $state,
            'site' => $site,
            'be_user' => $backendUserId,
            'created_at' => time(),
        ]);
        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => $this->settings->getString($site, 'gsc_oauth_client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Validates and removes the state. Returns the site identifier, or null if invalid/expired.
     */
    public function consumeState(string $state): ?string
    {
        if ($state === '') {
            return null;
        }
        $connection = $this->connectionPool->getConnectionForTable(self::STATE_TABLE);
        $row = $connection->select(['site', 'created_at'], self::STATE_TABLE, ['state' => $state])->fetchAssociative();
        $connection->delete(self::STATE_TABLE, ['state' => $state]);
        if ($row === false || (int)$row['created_at'] < time() - self::STATE_TTL) {
            return null;
        }
        return (string)$row['site'];
    }

    public function exchangeCode(string $site, string $code): void
    {
        $tokens = $this->tokenRequest($site, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
        ]);
        $refreshToken = (string)($tokens['refresh_token'] ?? '');
        if ($refreshToken === '') {
            throw new ApiException('No refresh token received. Remove the app access at myaccount.google.com/permissions and connect again.', 400);
        }
        $this->settings->set($site, 'gsc_refresh_token', $refreshToken);
        $this->settings->set($site, 'gsc_token_obtained_at', gmdate('c'));
        $this->cacheAccessToken($site, $tokens);
    }

    public function disconnect(string $site): void
    {
        $this->settings->remove($site, 'gsc_refresh_token');
        $this->settings->remove($site, 'gsc_token_obtained_at');
        $this->cache->remove($this->accessTokenIdentifier($site));
    }

    public function accessToken(string $site): string
    {
        if (!$this->isConfigured($site)) {
            throw new ApiException('OAuth is not configured: client ID or secret missing', 503);
        }
        if (!$this->isConnected($site)) {
            throw new ApiException('Not connected to Google', 503);
        }
        $cached = $this->cache->get($this->accessTokenIdentifier($site));
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $tokens = $this->tokenRequest($site, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->settings->getString($site, 'gsc_refresh_token'),
        ]);
        return $this->cacheAccessToken($site, $tokens);
    }

    /**
     * @param array<string, mixed> $tokens
     */
    private function cacheAccessToken(string $site, array $tokens): string
    {
        $accessToken = (string)($tokens['access_token'] ?? '');
        $lifetime = max(60, (int)($tokens['expires_in'] ?? 3600) - 120);
        if ($accessToken !== '') {
            $this->cache->set($this->accessTokenIdentifier($site), $accessToken, $lifetime);
        }
        return $accessToken;
    }

    private function accessTokenIdentifier(string $site): string
    {
        return 'gsc_access_' . sha1($site);
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function tokenRequest(string $site, array $params): array
    {
        try {
            $response = $this->clientFactory->getClient()->request('POST', self::TOKEN_URL, [
                'form_params' => $params + [
                    'client_id' => $this->settings->getString($site, 'gsc_oauth_client_id'),
                    'client_secret' => $this->settings->getString($site, 'gsc_oauth_client_secret'),
                ],
                'timeout' => 20,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            throw new ApiException('Google token endpoint not reachable: ' . $e->getMessage(), 502, $e);
        }
        $data = json_decode((string)$response->getBody(), true) ?: [];
        if ($response->getStatusCode() >= 400) {
            $error = (string)($data['error'] ?? 'HTTP ' . $response->getStatusCode());
            // Revoked or expired grant: the connection is gone, not a server error
            if ($error === 'invalid_grant') {
                throw new ApiException('Google connection expired or revoked. Please connect again.', 503);
            }
            throw new ApiException('OAuth token request failed: ' . $error . (isset($data['error_description']) ? ' (' . $data['error_description'] . ')' : ''), 502);
        }
        return $data;
    }
}
