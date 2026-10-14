<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Tests\Unit\Widgets;

use Derspe\SiteInsights\Security\SiteAccess;
use Derspe\SiteInsights\Tests\Unit\Fixtures\UnitTestCase;
use Derspe\SiteInsights\Widgets\InsightsWidget;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Settings\SettingsInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetContext;

final class InsightsWidgetTest extends UnitTestCase
{
    private function widget(string $view = 'gsc-kpis'): InsightsWidget
    {
        return new InsightsWidget(
            $this->createMock(WidgetConfigurationInterface::class),
            new SiteAccess($this->siteFinder([self::site('alpha', 1), self::site('beta', 2), self::site('gamma', 3)])),
            ['view' => $view]
        );
    }

    #[Test]
    public function siteChoiceOnlyOffersSitesOfTheUser(): void
    {
        $GLOBALS['BE_USER'] = $this->backendUser(false, true, [1, 3]);
        $definitions = $this->widget()->getSettingsDefinitions();
        self::assertSame(['alpha', 'gamma'], array_keys($definitions[0]->enum));
    }

    #[Test]
    public function noSiteChoiceWithoutBackendUser(): void
    {
        unset($GLOBALS['BE_USER']);
        self::assertSame([], $this->widget()->getSettingsDefinitions());
    }

    #[Test]
    public function attributesAreEscaped(): void
    {
        $settings = $this->createMock(SettingsInterface::class);
        $settings->method('has')->willReturn(true);
        $settings->method('get')->willReturn('"><script>alert(1)</script>');
        $context = new WidgetContext('id', [], $this->createMock(WidgetConfigurationInterface::class), $settings, new ServerRequest('https://backend.example/'));
        $html = $this->widget('x" onmouseover="alert(1)')->renderWidget($context)->content;
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('" onmouseover', $html);
        self::assertStringContainsString('&quot;', $html);
    }
}
