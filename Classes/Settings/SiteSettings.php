<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Settings;

use TYPO3\CMS\Core\Crypto\Cipher\CipherService;
use TYPO3\CMS\Core\Crypto\Cipher\CipherValue;
use TYPO3\CMS\Core\Crypto\Cipher\KeyFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Per-site key/value configuration, stored as JSON in tx_derspesiteinsights_setting.
 *
 * Secrets (Matomo token, Google service account key) are encrypted with a key
 * derived from the instance encryptionKey and are never sent to the browser.
 */
final class SiteSettings
{
    private const TABLE = 'tx_derspesiteinsights_setting';

    public const SECRET_KEYS = [
        'matomo_token',
        'gsc_service_account_key',
    ];

    private const DEFAULTS = [
        'matomo_url' => '',
        'matomo_token' => '',
        'matomo_site_id' => '',
        'matomo_goal_ids' => [],
        'gsc_service_account_key' => [],
        'gsc_site_url' => '',
        'gsc_brand_terms' => [],
        'gsc_snapshot_top_n' => 100,
        'gsc_top_n' => 50,
        'gsc_sitemap_url' => '',
        'gsc_extra_source_urls' => [],
        'gsc_extra_include' => '',
        'news_detail_pid' => 0,
    ];

    /** @var array<string, array<string, mixed>> */
    private array $runtimeCache = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly CipherService $cipherService,
        private readonly KeyFactory $keyFactory,
    ) {}

    public function get(string $site, string $name): mixed
    {
        $values = $this->load($site);
        return array_key_exists($name, $values) ? $values[$name] : (self::DEFAULTS[$name] ?? null);
    }

    public function getString(string $site, string $name): string
    {
        $value = $this->get($site, $name);
        return is_scalar($value) ? trim((string)$value) : '';
    }

    public function getInt(string $site, string $name): int
    {
        return (int)$this->get($site, $name);
    }

    /**
     * @return list<string>
     */
    public function getList(string $site, string $name): array
    {
        $value = $this->get($site, $name);
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter(array_map(static fn($v): string => trim((string)$v), $value), static fn(string $v): bool => $v !== ''));
    }

    public function isSet(string $site, string $name): bool
    {
        $value = $this->get($site, $name);
        return $value !== null && $value !== '' && $value !== [];
    }

    public function set(string $site, string $name, mixed $value): void
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (in_array($name, self::SECRET_KEYS, true)) {
            $encoded = $this->encrypt($encoded, $site, $name);
        }
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->delete(self::TABLE, ['site' => $site, 'name' => $name]);
        $connection->insert(self::TABLE, ['site' => $site, 'name' => $name, 'value' => $encoded]);
        unset($this->runtimeCache[$site]);
    }

    public function remove(string $site, string $name): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)
            ->delete(self::TABLE, ['site' => $site, 'name' => $name]);
        unset($this->runtimeCache[$site]);
    }

    /**
     * @return array<string, mixed>
     */
    private function load(string $site): array
    {
        if (isset($this->runtimeCache[$site])) {
            return $this->runtimeCache[$site];
        }
        $rows = $this->connectionPool->getConnectionForTable(self::TABLE)
            ->select(['name', 'value'], self::TABLE, ['site' => $site])
            ->fetchAllAssociative();
        $values = [];
        foreach ($rows as $row) {
            $name = (string)$row['name'];
            $raw = (string)$row['value'];
            if (in_array($name, self::SECRET_KEYS, true)) {
                $raw = $this->decrypt($raw, $site, $name);
                if ($raw === null) {
                    // e.g. encryptionKey changed: treat the secret as not set
                    continue;
                }
            }
            try {
                $values[$name] = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
        }
        return $this->runtimeCache[$site] = $values;
    }

    /**
     * Encrypts a value for storage outside the settings table (e.g. cached access tokens).
     */
    public function encryptValue(string $plain, string $context): string
    {
        return $this->encrypt($plain, 'value', $context);
    }

    public function decryptValue(string $encrypted, string $context): ?string
    {
        return $this->decrypt($encrypted, 'value', $context);
    }

    private function encrypt(string $plain, string $site, string $name): string
    {
        $key = $this->keyFactory->deriveSharedKeyFromEncryptionKey(self::class);
        return $this->cipherService->encrypt($plain, $key, $site . '|' . $name)->encode();
    }

    private function decrypt(string $encrypted, string $site, string $name): ?string
    {
        try {
            $key = $this->keyFactory->deriveSharedKeyFromEncryptionKey(self::class);
            return $this->cipherService->decrypt(CipherValue::fromSerialized($encrypted), $key, $site . '|' . $name);
        } catch (\Throwable) {
            return null;
        }
    }
}
