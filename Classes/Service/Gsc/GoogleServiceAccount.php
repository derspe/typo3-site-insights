<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Service\Gsc;

use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Service\ApiCache;
use Derspe\SiteInsights\Settings\SiteSettings;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;

/**
 * Access to the Search Console API with a Google service account (read-only scope).
 *
 * The JSON key is stored encrypted per site. Access tokens are requested with a signed JWT
 * (RFC 7523) and cached encrypted. The token endpoint is fixed, the "token_uri" of the key
 * file is ignored, so a manipulated key can never send a signed assertion elsewhere.
 */
class GoogleServiceAccount
{
    public const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';
    public const SETTING = 'gsc_service_account_key';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const MAX_KEY_LENGTH = 16384;

    public function __construct(
        private readonly SiteSettings $settings,
        private readonly GuzzleClientFactory $clientFactory,
        private readonly ApiCache $cache,
    ) {}

    public function isConfigured(string $site): bool
    {
        return $this->key($site) !== null;
    }

    public function clientEmail(string $site): string
    {
        return $this->key($site)['client_email'] ?? '';
    }

    public function remove(string $site): void
    {
        $key = $this->key($site);
        if ($key !== null) {
            $this->cache->remove($this->accessTokenIdentifier($site, $key));
        }
        $this->settings->remove($site, self::SETTING);
    }

    public function accessToken(string $site): string
    {
        $key = $this->key($site) ?? throw new ApiException('Search Console is not configured: service account key missing', 503);
        $identifier = $this->accessTokenIdentifier($site, $key);
        $cached = $this->cache->get($identifier);
        if (is_string($cached) && $cached !== '') {
            $token = $this->settings->decryptValue($cached, $identifier);
            if ($token !== null && $token !== '') {
                return $token;
            }
        }
        $tokens = $this->tokenRequest($this->assertion($key));
        $accessToken = (string)($tokens['access_token'] ?? '');
        if ($accessToken === '') {
            throw new ApiException('Google did not return an access token', 502);
        }
        $lifetime = max(60, (int)($tokens['expires_in'] ?? 3600) - 120);
        $this->cache->set($identifier, $this->settings->encryptValue($accessToken, $identifier), $lifetime);
        return $accessToken;
    }

    /**
     * Validates the content of a downloaded JSON key file and reduces it to the fields needed.
     *
     * @return array{client_email: string, private_key: string, private_key_id: string, project_id: string}
     */
    public static function parseKey(string $json): array
    {
        $json = trim($json);
        if (strlen($json) > self::MAX_KEY_LENGTH) {
            throw new ApiException('The service account key is too large', 400);
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new ApiException('The service account key is not valid JSON. Paste the complete content of the downloaded key file.', 400);
        }
        if (($data['type'] ?? '') !== 'service_account') {
            throw new ApiException('This is not a service account key (type "service_account" expected)', 400);
        }
        $email = (string)($data['client_email'] ?? '');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ApiException('The service account key contains no valid "client_email"', 400);
        }
        $privateKey = (string)($data['private_key'] ?? '');
        $keyResource = $privateKey !== '' ? openssl_pkey_get_private($privateKey) : false;
        if ($keyResource === false || (openssl_pkey_get_details($keyResource)['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new ApiException('The service account key contains no valid RSA "private_key"', 400);
        }
        return [
            'client_email' => $email,
            'private_key' => $privateKey,
            'private_key_id' => (string)($data['private_key_id'] ?? ''),
            'project_id' => (string)($data['project_id'] ?? ''),
        ];
    }

    /**
     * @return array{client_email: string, private_key: string, private_key_id: string, project_id: string}|null
     */
    private function key(string $site): ?array
    {
        $key = $this->settings->get($site, self::SETTING);
        if (!is_array($key) || ($key['client_email'] ?? '') === '' || ($key['private_key'] ?? '') === '') {
            return null;
        }
        return [
            'client_email' => (string)$key['client_email'],
            'private_key' => (string)$key['private_key'],
            'private_key_id' => (string)($key['private_key_id'] ?? ''),
            'project_id' => (string)($key['project_id'] ?? ''),
        ];
    }

    /**
     * @param array{client_email: string, private_key: string, private_key_id: string} $key
     */
    private function assertion(array $key): string
    {
        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'] + ($key['private_key_id'] !== '' ? ['kid' => $key['private_key_id']] : []);
        $claims = [
            'iss' => $key['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ];
        $input = self::base64Url(json_encode($header, JSON_THROW_ON_ERROR)) . '.' . self::base64Url(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $signature = '';
        if (!openssl_sign($input, $signature, $key['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new ApiException('The service account key could not be used for signing', 500);
        }
        return $input . '.' . self::base64Url($signature);
    }

    /**
     * @return array<string, mixed>
     */
    private function tokenRequest(string $assertion): array
    {
        try {
            $response = $this->clientFactory->getClient()->request('POST', self::TOKEN_URL, [
                'form_params' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $assertion,
                ],
                'timeout' => 20,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            throw new ApiException('Google token endpoint not reachable', 502, $e);
        }
        $data = json_decode((string)$response->getBody(), true) ?: [];
        if ($response->getStatusCode() >= 400) {
            $error = (string)($data['error'] ?? 'HTTP ' . $response->getStatusCode());
            // Deleted/disabled key or account: a configuration problem, not a server error
            if (in_array($error, ['invalid_grant', 'invalid_client', 'unauthorized_client'], true)) {
                throw new ApiException('Google rejected the service account key (deleted or disabled?). Create a new key and save it again.', 503);
            }
            throw new ApiException('Google token request failed: ' . $error, 502);
        }
        return $data;
    }

    /**
     * Includes the key id, so a new key never uses a token cached for the old one.
     *
     * @param array{client_email: string, private_key_id: string, private_key: string} $key
     */
    private function accessTokenIdentifier(string $site, array $key): string
    {
        return 'gsc_access_' . sha1($site . '|' . $key['client_email'] . '|' . ($key['private_key_id'] ?: sha1($key['private_key'])));
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
