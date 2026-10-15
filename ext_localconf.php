<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

defined('TYPO3') or die();

// API responses (10 minutes) and Google access tokens
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['derspe_site_insights'] ??= [
    'frontend' => VariableFrontend::class,
    'backend' => Typo3DatabaseBackend::class,
    'options' => [
        'defaultLifetime' => 600,
    ],
];
