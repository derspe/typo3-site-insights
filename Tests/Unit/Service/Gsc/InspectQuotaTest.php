<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Tests\Unit\Service\Gsc;

use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Service\Gsc\InspectQuota;
use Derspe\SiteInsights\Tests\Unit\Fixtures\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;

final class InspectQuotaTest extends UnitTestCase
{
    #[Test]
    public function consumptionIsCountedPerSite(): void
    {
        $quota = new InspectQuota($this->settings());
        $now = new \DateTimeImmutable('2026-10-14 12:00:00', new \DateTimeZone('UTC'));
        $quota->consume('alpha', $now);
        $quota->consume('alpha', $now);
        self::assertSame(2, $quota->used('alpha', $now));
        self::assertSame(0, $quota->used('beta', $now));
        self::assertSame(InspectQuota::DAILY_LIMIT - 2, $quota->remaining('alpha', $now));
    }

    #[Test]
    public function limitIsEnforcedAcrossInstances(): void
    {
        $now = new \DateTimeImmutable('2026-10-14 12:00:00', new \DateTimeZone('UTC'));
        $this->settings()->set('alpha', 'gsc_inspect_quota', ['date' => '2026-10-14', 'count' => InspectQuota::DAILY_LIMIT]);
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(429);
        (new InspectQuota($this->settings()))->consume('alpha', $now);
    }

    #[Test]
    public function budgetResetsAtMidnightPacificTime(): void
    {
        $this->settings()->set('alpha', 'gsc_inspect_quota', ['date' => '2026-10-14', 'count' => InspectQuota::DAILY_LIMIT]);
        $quota = new InspectQuota($this->settings());
        // 06:00 UTC on the 15th is still the 14th in Los Angeles
        self::assertSame(0, $quota->remaining('alpha', new \DateTimeImmutable('2026-10-15 06:00:00', new \DateTimeZone('UTC'))));
        self::assertSame(InspectQuota::DAILY_LIMIT, $quota->remaining('alpha', new \DateTimeImmutable('2026-10-15 08:00:00', new \DateTimeZone('UTC'))));
    }
}
