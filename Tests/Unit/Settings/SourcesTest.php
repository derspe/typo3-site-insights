<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Tests\Unit\Settings;

use Derspe\SiteInsights\Settings\Sources;
use Derspe\SiteInsights\Tests\Unit\Fixtures\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class SourcesTest extends UnitTestCase
{
    /**
     * @return iterable<string, array{mixed, bool, bool}>
     */
    public static function values(): iterable
    {
        yield 'both' => ['both', true, true];
        yield 'Search Console only' => ['gsc', false, true];
        yield 'Matomo only' => ['matomo', true, false];
        yield 'unknown value enables both' => ['nonsense', true, true];
        yield 'missing value enables both' => [null, true, true];
    }

    #[Test]
    #[DataProvider('values')]
    public function enabledSources(mixed $value, bool $matomo, bool $gsc): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['derspe_site_insights']['sources'] = $value;
        $sources = new Sources(new ExtensionConfiguration());
        self::assertSame(['matomo' => $matomo, 'gsc' => $gsc], $sources->toArray());
    }
}
