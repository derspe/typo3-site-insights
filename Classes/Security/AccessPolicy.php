<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Security;

/**
 * Which privilege an API endpoint needs.
 *
 * Module users may read reports and run quota friendly actions (index scan without force,
 * cached URL inspection). Actions that use up API quota on demand are reserved for
 * administrators. The settings (configuration, Google credentials, backfill) are only for
 * system maintainers: they set up the instance, other administrators only use it.
 */
final class AccessPolicy
{
    public const USER = 'user';
    public const ADMIN = 'admin';
    public const MAINTAINER = 'maintainer';

    private const READ_ENDPOINTS = [
        'matomo/segments',
        'matomo/goals',
        'matomo/transitions',
        'matomo/dashboard',
        'gsc/dashboard',
        'gsc/drilldown',
        'gsc/page',
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
        'page/transitions',
        'news/overview',
        'news/queries',
    ];

    private const MAINTAINER_POST_ENDPOINTS = [
        'settings',
        'gsc/auth/remove',
    ];

    private const ADMIN_POST_ENDPOINTS = [
        'gsc/snapshot/run',
        'gsc/visibility/rebuild',
    ];

    /**
     * @param array<string, mixed> $params query and body parameters
     * @return self::USER|self::ADMIN|self::MAINTAINER|null null = unknown endpoint or wrong method
     */
    public static function requiredLevel(string $endpoint, bool $isPost, array $params = []): ?string
    {
        $force = filter_var($params['force'] ?? false, FILTER_VALIDATE_BOOL);
        if ($endpoint === 'meta') {
            return $isPost ? null : self::USER;
        }
        if ($endpoint === 'settings' && !$isPost) {
            // Even without secrets the configuration is only for those who set up the instance
            return self::MAINTAINER;
        }
        if (in_array($endpoint, self::MAINTAINER_POST_ENDPOINTS, true)) {
            return $isPost ? self::MAINTAINER : null;
        }
        if (in_array($endpoint, self::ADMIN_POST_ENDPOINTS, true)) {
            return $isPost ? self::ADMIN : null;
        }
        if (in_array($endpoint, self::READ_ENDPOINTS, true)) {
            return $isPost ? null : self::USER;
        }
        return match ($endpoint) {
            'gsc/inspect' => $isPost && $force ? self::ADMIN : self::USER,
            'job/start' => !$isPost ? null : match ($params['type'] ?? '') {
                'index_scan' => $force ? self::ADMIN : self::USER,
                // Part of the settings
                'backfill' => self::MAINTAINER,
                default => self::ADMIN,
            },
            'job/step' => $isPost ? self::USER : null,
            default => null,
        };
    }
}
