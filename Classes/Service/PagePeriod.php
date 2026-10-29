<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Service;

/**
 * Periods of the page module, the news folders and the page details: 7, 28 or 90 days,
 * 3 months or a year, each ending with a given day.
 */
final class PagePeriod
{
    public const KEYS = ['7d', '28d', '90d', '3m', '1y'];
    public const DEFAULT = '28d';
    private const MODIFIERS = ['7d' => '-7 days', '28d' => '-28 days', '90d' => '-90 days', '3m' => '-3 months', '1y' => '-1 year'];

    /**
     * One of the keys; anything else is the default.
     */
    public static function normalize(mixed $value): string
    {
        return in_array($value, self::KEYS, true) ? $value : self::DEFAULT;
    }

    /**
     * The period ending with the given day ("YYYY-MM-DD").
     *
     * @return array{startDate: string, endDate: string}
     */
    public static function ending(string $endDate, string $key): array
    {
        $end = new \DateTimeImmutable($endDate . ' 00:00:00', new \DateTimeZone('UTC'));
        return [
            'startDate' => $end->modify(self::MODIFIERS[self::normalize($key)])->modify('+1 day')->format('Y-m-d'),
            'endDate' => $end->format('Y-m-d'),
        ];
    }
}
