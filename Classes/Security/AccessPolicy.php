<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Security;

/**
 * Which privilege an API endpoint needs.
 *
 * Module users may read reports and run quota friendly actions (index scan without force,
 * cached URL inspection). Everything that changes configuration, uses up API quota on
 * demand or touches the Google credentials is reserved for administrators.
 */
final class AccessPolicy
{
    public const USER = 'user';
    public const ADMIN = 'admin';

    private const READ_ENDPOINTS = [
        'matomo/segments',
        'matomo/goals',
        'matomo/dashboard',
        'gsc/dashboard',
        'gsc/drilldown',
        'gsc/cannibalization',
        'gsc/appearance',
        'gsc/visibility',
        'gsc/tracker',
        'gsc/tracker/series',
        'gsc/snapshot/status',
        'gsc/index/pages',
        'gsc/auth/status',
        'job/active',
        'page/insights',
    ];

    private const ADMIN_POST_ENDPOINTS = [
        'settings',
        'gsc/auth/remove',
        'gsc/snapshot/run',
        'gsc/visibility/rebuild',
    ];

    /**
     * @param array<string, mixed> $params query and body parameters
     * @return self::USER|self::ADMIN|null null = unknown endpoint or wrong method
     */
    public static function requiredLevel(string $endpoint, bool $isPost, array $params = []): ?string
    {
        $force = filter_var($params['force'] ?? false, FILTER_VALIDATE_BOOL);
        if ($endpoint === 'meta') {
            return $isPost ? null : self::USER;
        }
        if ($endpoint === 'settings' && !$isPost) {
            // Even without secrets the configuration is nothing for editors
            return self::ADMIN;
        }
        if (in_array($endpoint, self::ADMIN_POST_ENDPOINTS, true)) {
            return $isPost ? self::ADMIN : null;
        }
        if (in_array($endpoint, self::READ_ENDPOINTS, true)) {
            return $isPost ? null : self::USER;
        }
        return match ($endpoint) {
            'gsc/inspect' => $isPost && $force ? self::ADMIN : self::USER,
            'job/start' => !$isPost ? null : (($params['type'] ?? '') === 'index_scan' && !$force ? self::USER : self::ADMIN),
            'job/step' => $isPost ? self::USER : null,
            default => null,
        };
    }
}
