<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Service;

/**
 * The period of the same length directly before a range (for comparisons).
 */
final class PreviousRange
{
    /**
     * @param array{startDate: string, endDate: string} $range
     * @return array{startDate: string, endDate: string}
     */
    public static function of(array $range): array
    {
        $start = new \DateTimeImmutable($range['startDate'] . ' 00:00:00', new \DateTimeZone('UTC'));
        $end = new \DateTimeImmutable($range['endDate'] . ' 00:00:00', new \DateTimeZone('UTC'));
        $days = (int)$start->diff($end)->days + 1;
        $previousEnd = $start->modify('-1 day');
        return [
            'startDate' => $previousEnd->modify('-' . ($days - 1) . ' days')->format('Y-m-d'),
            'endDate' => $previousEnd->format('Y-m-d'),
        ];
    }
}
