<?php

use Derspe\SiteInsights\Controller\Backend\ApiController;

return [
    'derspe_site_insights_api' => [
        'path' => '/derspe-site-insights/api',
        'target' => ApiController::class . '::handle',
        'methods' => ['GET', 'POST'],
    ],
];
