<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Tests\Unit\Service;

use Derspe\SiteInsights\Domain\Repository\InspectRepository;
use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Service\Gsc\InspectService;
use Derspe\SiteInsights\Service\Gsc\SnapshotService;
use Derspe\SiteInsights\Service\Job\JobService;
use Derspe\SiteInsights\Tests\Unit\Fixtures\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

final class JobServiceTest extends UnitTestCase
{
    private function service(LockingStrategyInterface $lock): JobService
    {
        $factory = $this->createMock(LockFactory::class);
        $factory->method('createLocker')->willReturn($lock);
        return new JobService(
            $this->db->connectionPool(),
            $factory,
            self::unused(InspectService::class),
            self::unused(InspectRepository::class),
            self::unused(SnapshotService::class),
            new NullLogger(),
        );
    }

    #[Test]
    public function unknownJobTypesAreRejected(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);
        $this->service($this->createMock(LockingStrategyInterface::class))->create('alpha', 'shell');
    }

    #[Test]
    public function busyJobIsNotProcessedTwice(): void
    {
        $lock = $this->createMock(LockingStrategyInterface::class);
        $lock->method('acquire')->willThrowException(new LockAcquireWouldBlockException('busy'));
        $this->db->failWith = new \RuntimeException('job must not be loaded');
        self::assertNull($this->service($lock)->step('alpha', 1, 5.0));
    }

    #[Test]
    public function jobsOfOtherSitesAreNotProcessed(): void
    {
        $lock = $this->createMock(LockingStrategyInterface::class);
        $lock->method('acquire')->willReturn(true);
        $this->db->tables[JobService::TABLE][] = ['uid' => 1, 'site' => 'alpha', 'type' => 'index_scan', 'status' => 'pending', 'state' => '{}'];
        self::assertNull($this->service($lock)->step('beta', 1, 5.0));
        self::assertSame('pending', $this->db->tables[JobService::TABLE][0]['status']);
    }

    #[Test]
    public function dtoDoesNotExposeTheInternalState(): void
    {
        $dto = JobService::toDto([
            'uid' => 3, 'type' => 'index_scan', 'status' => 'running', 'total' => 10, 'done' => 2, 'inspected' => 1, 'skipped' => 1,
            'errors' => 0, 'progress' => '{"key":"job.pending"}', 'error' => '', 'state' => '{"urls":["https://internal.example/"]}', 'site' => 'alpha',
        ]);
        self::assertArrayNotHasKey('state', $dto);
        self::assertArrayNotHasKey('site', $dto);
        self::assertSame(['key' => 'job.pending'], $dto['progress']);
        self::assertNull($dto['error']);
    }
}
