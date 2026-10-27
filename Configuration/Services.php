<?php

declare(strict_types=1);

use Derspe\SiteInsights\DependencyInjection\DisabledSourceWidgetPass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container, ContainerBuilder $containerBuilder): void {
    // Higher priority than the DashboardWidgetPass, which collects the widgets
    $containerBuilder->addCompilerPass(
        new DisabledSourceWidgetPass($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['derspe_site_insights']['sources'] ?? null),
        PassConfig::TYPE_BEFORE_OPTIMIZATION,
        10
    );
};
