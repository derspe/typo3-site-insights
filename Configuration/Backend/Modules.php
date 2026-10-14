<?php

use Derspe\SiteInsights\Controller\Backend\ModuleController;

return [
    'site_insights' => [
        'parent' => 'site',
        'position' => ['after' => '*'],
        'access' => 'user',
        'path' => '/module/site/insights',
        'iconIdentifier' => 'module-site-insights',
        'labels' => 'derspe_site_insights.module',
        'routes' => [
            '_default' => [
                'target' => ModuleController::class . '::handleRequest',
            ],
        ],
    ],
];
