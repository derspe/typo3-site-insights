<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Tests\Unit\Service;

use Derspe\SiteInsights\Service\PagePeriod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PagePeriodTest extends TestCase
{
    #[Test]
    public function periodsEndWithTheGivenDay(): void
    {
        self::assertSame(['startDate' => '2026-10-22', 'endDate' => '2026-10-28'], PagePeriod::ending('2026-10-28', '7d'));
        self::assertSame(['startDate' => '2026-10-01', 'endDate' => '2026-10-28'], PagePeriod::ending('2026-10-28', '28d'));
        self::assertSame(['startDate' => '2026-07-31', 'endDate' => '2026-10-28'], PagePeriod::ending('2026-10-28', '90d'));
        self::assertSame(['startDate' => '2026-07-29', 'endDate' => '2026-10-28'], PagePeriod::ending('2026-10-28', '3m'));
        self::assertSame(['startDate' => '2025-10-29', 'endDate' => '2026-10-28'], PagePeriod::ending('2026-10-28', '1y'));
    }

    #[Test]
    public function unknownKeysAreTheDefault(): void
    {
        self::assertSame('28d', PagePeriod::normalize('14d'));
        self::assertSame('28d', PagePeriod::normalize(28));
        self::assertSame('3m', PagePeriod::normalize('3m'));
    }
}
