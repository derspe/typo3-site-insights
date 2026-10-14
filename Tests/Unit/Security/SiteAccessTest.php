<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Tests\Unit\Security;

use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Security\SiteAccess;
use Derspe\SiteInsights\Tests\Unit\Fixtures\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;

final class SiteAccessTest extends UnitTestCase
{
    private SiteAccess $access;

    protected function setUp(): void
    {
        parent::setUp();
        $this->access = new SiteAccess($this->siteFinder([self::site('alpha', 1), self::site('beta', 2)]));
    }

    #[Test]
    public function administratorsSeeAllSites(): void
    {
        self::assertSame(['alpha', 'beta'], array_keys($this->access->allowedSites($this->backendUser(true))));
    }

    #[Test]
    public function editorsOnlySeeSitesInsideTheirMounts(): void
    {
        self::assertSame(['beta'], array_keys($this->access->allowedSites($this->backendUser(false, true, [2]))));
    }

    #[Test]
    public function emptyIdentifierSelectsTheFirstAllowedSite(): void
    {
        self::assertSame('beta', $this->access->resolve($this->backendUser(false, true, [2]), '')->getIdentifier());
    }

    #[Test]
    public function foreignSiteIsDenied(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(403);
        $this->access->resolve($this->backendUser(false, true, [2]), 'alpha');
    }

    #[Test]
    public function unknownSiteIsDeniedInsteadOfFallingBack(): void
    {
        $this->expectExceptionCode(403);
        $this->access->resolve($this->backendUser(true), 'gamma');
    }

    #[Test]
    public function userWithoutAnyMountedSiteIsDenied(): void
    {
        $this->expectExceptionCode(403);
        $this->access->resolve($this->backendUser(false, true, []), '');
    }
}
