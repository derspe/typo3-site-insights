<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Tests\Unit\Service\Gsc;

use Derspe\SiteInsights\Service\Gsc\GscDashboardService;
use Derspe\SiteInsights\Service\Gsc\SnapshotService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DateRangeTest extends TestCase
{
    #[Test]
    public function rangeIsAnchoredAtTheLatestDataDay(): void
    {
        self::assertSame(['startDate' => '2026-09-14', 'endDate' => '2026-10-11'], GscDashboardService::dateRange('2026-10-11', 'range', 'last28'));
        self::assertSame(['startDate' => '2026-10-05', 'endDate' => '2026-10-11'], GscDashboardService::dateRange('2026-10-11', 'range', 'last7'));
    }

    #[Test]
    public function dayPeriodUsesAnchorOrTheDayBefore(): void
    {
        self::assertSame(['startDate' => '2026-10-11', 'endDate' => '2026-10-11'], GscDashboardService::dateRange('2026-10-11', 'day', 'today'));
        self::assertSame(['startDate' => '2026-10-10', 'endDate' => '2026-10-10'], GscDashboardService::dateRange('2026-10-11', 'day', 'yesterday'));
    }

    #[Test]
    public function unknownPresetFallsBackTo28Days(): void
    {
        self::assertSame('2026-09-14', GscDashboardService::dateRange('2026-10-11', 'range', 'nonsense')['startDate']);
    }

    #[Test]
    public function previousPeriodDirectlyPrecedesTheRange(): void
    {
        self::assertSame(['startDate' => '2026-08-17', 'endDate' => '2026-09-13'], GscDashboardService::compareRange('2026-10-11', 'range', 'last28', 'previous'));
        self::assertSame(['startDate' => '2026-10-10', 'endDate' => '2026-10-10'], GscDashboardService::compareRange('2026-10-11', 'day', 'today', 'previous'));
    }

    #[Test]
    public function previousYearShiftsByOneYear(): void
    {
        self::assertSame(['startDate' => '2025-09-14', 'endDate' => '2025-10-11'], GscDashboardService::compareRange('2026-10-11', 'range', 'last28', 'year'));
    }

    #[Test]
    public function noCompareRangeWithoutCompareType(): void
    {
        self::assertNull(GscDashboardService::compareRange('2026-10-11', 'range', 'last28', ''));
    }

    #[Test]
    public function defaultSnapshotDateRespectsLatency(): void
    {
        $now = new \DateTimeImmutable('2026-10-14 08:00:00', new \DateTimeZone('UTC'));
        self::assertSame('2026-10-11', SnapshotService::defaultSnapshotDate($now));
    }

    #[Test]
    public function backfillDatesAreNewestFirstAndCapped(): void
    {
        $now = new \DateTimeImmutable('2026-10-14 08:00:00', new \DateTimeZone('UTC'));
        self::assertSame(['2026-10-11', '2026-10-10', '2026-10-09'], SnapshotService::backfillDates(3, $now));
        self::assertCount(SnapshotService::MAX_BACKFILL_DAYS, SnapshotService::backfillDates(500, $now));
        self::assertCount(1, SnapshotService::backfillDates(0, $now));
    }

    #[Test]
    public function snapshotIsCompleteWithHalfOfTopN(): void
    {
        self::assertTrue(SnapshotService::isComplete(50, 100));
        self::assertFalse(SnapshotService::isComplete(49, 100));
    }
}
