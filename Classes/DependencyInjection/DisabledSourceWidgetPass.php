<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\DependencyInjection;

use Derspe\SiteInsights\Settings\Sources;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Removes the dashboard widgets of a source disabled in the extension configuration, so they
 * neither render nor show up in the widget selection. Runs before the dashboard collects its widgets.
 * The container is only rebuilt when caches are flushed.
 */
final readonly class DisabledSourceWidgetPass implements CompilerPassInterface
{
    /**
     * @param mixed $sources value of the extension configuration "sources"
     */
    public function __construct(
        private mixed $sources,
    ) {}

    public function process(ContainerBuilder $container): void
    {
        foreach ($container->findTaggedServiceIds('dashboard.widget') as $id => $tags) {
            $view = (string)($container->getDefinition($id)->getArguments()['$options']['view'] ?? '');
            $source = str_starts_with($view, 'matomo-') ? Sources::MATOMO : (str_starts_with($view, 'gsc-') ? Sources::GSC : null);
            if ($source !== null && !Sources::isEnabled($this->sources, $source)) {
                $container->removeDefinition($id);
            }
        }
    }
}
