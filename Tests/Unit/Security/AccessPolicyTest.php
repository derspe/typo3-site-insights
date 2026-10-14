<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Tests\Unit\Security;

use Derspe\SiteInsights\Security\AccessPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AccessPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool, array<string, mixed>, ?string}>
     */
    public static function endpoints(): iterable
    {
        // Reading reports: module users
        yield 'meta' => ['meta', false, [], AccessPolicy::USER];
        yield 'gsc dashboard' => ['gsc/dashboard', false, [], AccessPolicy::USER];
        yield 'matomo dashboard' => ['matomo/dashboard', false, [], AccessPolicy::USER];
        yield 'index pages' => ['gsc/index/pages', false, [], AccessPolicy::USER];
        yield 'auth status' => ['gsc/auth/status', false, [], AccessPolicy::USER];
        yield 'job step' => ['job/step', true, [], AccessPolicy::USER];
        // Quota friendly actions: module users
        yield 'inspect cached' => ['gsc/inspect', true, [], AccessPolicy::USER];
        yield 'index scan' => ['job/start', true, ['type' => 'index_scan'], AccessPolicy::USER];
        // Configuration, connection and on-demand quota: administrators
        yield 'settings read' => ['settings', false, [], AccessPolicy::ADMIN];
        yield 'settings write' => ['settings', true, [], AccessPolicy::ADMIN];
        yield 'auth start' => ['gsc/auth/start', true, [], AccessPolicy::ADMIN];
        yield 'auth complete' => ['gsc/auth/complete', true, [], AccessPolicy::ADMIN];
        yield 'auth disconnect' => ['gsc/auth/disconnect', true, [], AccessPolicy::ADMIN];
        yield 'snapshot run' => ['gsc/snapshot/run', true, [], AccessPolicy::ADMIN];
        yield 'visibility rebuild' => ['gsc/visibility/rebuild', true, [], AccessPolicy::ADMIN];
        yield 'inspect forced' => ['gsc/inspect', true, ['force' => true], AccessPolicy::ADMIN];
        yield 'inspect forced as string' => ['gsc/inspect', true, ['force' => '1'], AccessPolicy::ADMIN];
        yield 'index scan forced' => ['job/start', true, ['type' => 'index_scan', 'force' => true], AccessPolicy::ADMIN];
        yield 'backfill' => ['job/start', true, ['type' => 'backfill'], AccessPolicy::ADMIN];
        yield 'unknown job type' => ['job/start', true, ['type' => 'whatever'], AccessPolicy::ADMIN];
        // Wrong method or unknown endpoint
        yield 'write endpoint via GET' => ['gsc/auth/start', false, [], null];
        yield 'read endpoint via POST' => ['gsc/dashboard', true, [], null];
        yield 'job start via GET' => ['job/start', false, [], null];
        yield 'unknown' => ['../../etc/passwd', false, [], null];
        yield 'empty' => ['', false, [], null];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[Test]
    #[DataProvider('endpoints')]
    public function requiredLevel(string $endpoint, bool $isPost, array $params, ?string $expected): void
    {
        self::assertSame($expected, AccessPolicy::requiredLevel($endpoint, $isPost, $params));
    }
}
