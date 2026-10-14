<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Service\Gsc;

use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Settings\SiteSettings;

/**
 * Daily budget for URL Inspection calls per site (Google allows 2,000 per day and property).
 * Shared by single inspections and index scans, so restarting a scan cannot use up the quota.
 */
final readonly class InspectQuota
{
    public const DAILY_LIMIT = 1800;
    private const SETTING = 'gsc_inspect_quota';

    public function __construct(
        private SiteSettings $settings,
    ) {}

    public function used(string $site, ?\DateTimeImmutable $now = null): int
    {
        $state = $this->settings->get($site, self::SETTING);
        return is_array($state) && ($state['date'] ?? '') === self::today($now) ? (int)($state['count'] ?? 0) : 0;
    }

    public function remaining(string $site, ?\DateTimeImmutable $now = null): int
    {
        return max(0, self::DAILY_LIMIT - $this->used($site, $now));
    }

    /**
     * Books one inspection or throws (HTTP 429) when the daily budget is used up.
     */
    public function consume(string $site, ?\DateTimeImmutable $now = null): void
    {
        $used = $this->used($site, $now);
        if ($used >= self::DAILY_LIMIT) {
            throw new ApiException(sprintf('Daily limit of %d URL inspections reached', self::DAILY_LIMIT), 429);
        }
        $this->settings->set($site, self::SETTING, ['date' => self::today($now), 'count' => $used + 1]);
    }

    private static function today(?\DateTimeImmutable $now): string
    {
        // Google resets the quota at midnight Pacific Time
        return ($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('America/Los_Angeles'))->format('Y-m-d');
    }
}
