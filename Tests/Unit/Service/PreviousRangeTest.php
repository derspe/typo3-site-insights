<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Tests\Unit\Service;

use Derspe\SiteInsights\Service\PreviousRange;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PreviousRangeTest extends TestCase
{
    #[Test]
    public function thePeriodBeforeHasTheSameLength(): void
    {
        self::assertSame(['startDate' => '2026-09-03', 'endDate' => '2026-09-30'], PreviousRange::of(['startDate' => '2026-10-01', 'endDate' => '2026-10-28']));
        self::assertSame(['startDate' => '2026-10-27', 'endDate' => '2026-10-27'], PreviousRange::of(['startDate' => '2026-10-28', 'endDate' => '2026-10-28']));
        self::assertSame(['startDate' => '2026-02-27', 'endDate' => '2026-02-28'], PreviousRange::of(['startDate' => '2026-03-01', 'endDate' => '2026-03-02']));
    }
}
