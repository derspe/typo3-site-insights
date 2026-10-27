<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Settings;

use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Service\Gsc\GoogleServiceAccount;

/**
 * Validates settings submitted by the backend module.
 */
final class SettingsValidator
{
    public const TYPE_STRING = 'string';
    public const TYPE_URL = 'url';
    public const TYPE_SECRET = 'secret';
    public const TYPE_INT = 'int';
    public const TYPE_LIST = 'list';
    public const TYPE_URL_LIST = 'url-list';
    public const TYPE_SERVICE_ACCOUNT_KEY = 'service-account-key';
    public const TYPE_PAGE_ID = 'page-id';

    /** Settings that can be edited in the module */
    public const KEYS = [
        'matomo_url' => self::TYPE_URL,
        'matomo_token' => self::TYPE_SECRET,
        'matomo_site_id' => self::TYPE_STRING,
        'matomo_goal_ids' => self::TYPE_LIST,
        'gsc_service_account_key' => self::TYPE_SERVICE_ACCOUNT_KEY,
        'gsc_site_url' => self::TYPE_STRING,
        'gsc_brand_terms' => self::TYPE_LIST,
        'gsc_snapshot_top_n' => self::TYPE_INT,
        'gsc_top_n' => self::TYPE_INT,
        'gsc_sitemap_url' => self::TYPE_URL,
        'gsc_extra_source_urls' => self::TYPE_URL_LIST,
        'gsc_extra_include' => self::TYPE_STRING,
        'news_detail_pid' => self::TYPE_PAGE_ID,
    ];

    private const INT_RANGE = [10, 1000];
    private const MAX_STRING = 500;

    /**
     * Returns the changes to store; null means "remove the setting". Secrets submitted
     * empty are left untouched (the stored value is kept). Unknown keys are ignored.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function validate(array $values): array
    {
        $changes = [];
        foreach ($values as $key => $value) {
            $type = self::KEYS[$key] ?? null;
            if ($type === null || (!is_scalar($value) && !is_array($value) && $value !== null)) {
                continue;
            }
            switch ($type) {
                case self::TYPE_SECRET:
                    $secret = is_string($value) ? trim($value) : '';
                    if ($secret !== '') {
                        $changes[$key] = mb_substr($secret, 0, self::MAX_STRING);
                    }
                    break;
                case self::TYPE_SERVICE_ACCOUNT_KEY:
                    // Secret as well: submitted empty keeps the stored key
                    $json = is_string($value) ? trim($value) : '';
                    if ($json !== '') {
                        $changes[$key] = GoogleServiceAccount::parseKey($json);
                    }
                    break;
                case self::TYPE_STRING:
                    $string = trim(is_scalar($value) ? (string)$value : '');
                    $changes[$key] = $string === '' ? null : mb_substr($string, 0, self::MAX_STRING);
                    break;
                case self::TYPE_URL:
                    $url = trim(is_scalar($value) ? (string)$value : '');
                    if ($url !== '' && !self::isHttpUrl($url)) {
                        throw new ApiException(sprintf('"%s" must be an http(s) URL', $key), 400);
                    }
                    $changes[$key] = $url === '' ? null : mb_substr(rtrim($url, '/'), 0, self::MAX_STRING);
                    break;
                case self::TYPE_INT:
                    $int = (int)$value;
                    if ($int < self::INT_RANGE[0] || $int > self::INT_RANGE[1]) {
                        throw new ApiException(sprintf('"%s" must be between %d and %d', $key, ...self::INT_RANGE), 400);
                    }
                    $changes[$key] = $int;
                    break;
                case self::TYPE_PAGE_ID:
                    $id = trim(is_scalar($value) ? (string)$value : '');
                    if ($id !== '' && $id !== '0' && preg_match('/^[1-9]\d{0,9}$/', $id) !== 1) {
                        throw new ApiException(sprintf('"%s" must be a page ID', $key), 400);
                    }
                    $changes[$key] = $id === '' || $id === '0' ? null : (int)$id;
                    break;
                case self::TYPE_LIST:
                case self::TYPE_URL_LIST:
                    $list = self::toList($value);
                    if ($type === self::TYPE_URL_LIST) {
                        foreach ($list as $url) {
                            if (!self::isHttpUrl($url)) {
                                throw new ApiException(sprintf('"%s" is not an http(s) URL', $url), 400);
                            }
                        }
                    }
                    $changes[$key] = $list === [] ? null : $list;
                    break;
            }
        }
        return $changes;
    }

    public static function isHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        return in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    /**
     * scheme://host[:port] in lower case, or '' for invalid URLs.
     */
    public static function origin(string $url): string
    {
        $parts = parse_url(trim($url));
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }
        return strtolower($parts['scheme'] . '://' . $parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * @return list<string>
     */
    private static function toList(mixed $value): array
    {
        $items = is_array($value) ? $value : explode(',', (string)$value);
        $list = [];
        foreach ($items as $item) {
            $item = is_scalar($item) ? trim((string)$item) : '';
            if ($item !== '') {
                $list[] = mb_substr($item, 0, self::MAX_STRING);
            }
        }
        return array_slice(array_values(array_unique($list)), 0, 100);
    }
}
