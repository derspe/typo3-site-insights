<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Tests\Unit\DependencyInjection;

use Derspe\SiteInsights\DependencyInjection\DisabledSourceWidgetPass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class DisabledSourceWidgetPassTest extends TestCase
{
    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        foreach (['gscKpis' => 'gsc-kpis', 'visibility' => 'gsc-visibility', 'matomoKpis' => 'matomo-kpis'] as $id => $view) {
            $container->setDefinition($id, (new Definition(\stdClass::class))->setArgument('$options', ['view' => $view])->addTag('dashboard.widget'));
        }
        // Widgets of other extensions are never touched
        $container->setDefinition('foreign', (new Definition(\stdClass::class))->addTag('dashboard.widget'));
        return $container;
    }

    #[Test]
    public function widgetsOfTheDisabledSourceAreRemoved(): void
    {
        $container = $this->container();
        (new DisabledSourceWidgetPass('gsc'))->process($container);
        self::assertSame(['foreign', 'gscKpis', 'visibility'], self::widgets($container));

        $container = $this->container();
        (new DisabledSourceWidgetPass('matomo'))->process($container);
        self::assertSame(['foreign', 'matomoKpis'], self::widgets($container));
    }

    #[Test]
    public function allWidgetsStayWhenBothSourcesAreEnabled(): void
    {
        $container = $this->container();
        (new DisabledSourceWidgetPass(null))->process($container);
        self::assertSame(['foreign', 'gscKpis', 'matomoKpis', 'visibility'], self::widgets($container));
    }

    /**
     * @return list<string>
     */
    private static function widgets(ContainerBuilder $container): array
    {
        $ids = array_keys($container->findTaggedServiceIds('dashboard.widget'));
        sort($ids);
        return $ids;
    }
}
