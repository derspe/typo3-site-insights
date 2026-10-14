<?php

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    'module-site-insights' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:derspe_site_insights/Resources/Public/Icons/module.svg',
    ],
    'content-widget-site-insights' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:derspe_site_insights/Resources/Public/Icons/widget.svg',
    ],
];
