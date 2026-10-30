<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Site Insights',
    'description' => 'Matomo and Google Search Console insights for the TYPO3 backend: KPIs, comparisons, ranking tracker, visibility index, cannibalization, index coverage and dashboard widgets.',
    'category' => 'module',
    'author' => 'Frank Specht',
    'state' => 'beta',
    'version' => '0.2.0',
    'constraints' => [
        'depends' => [
            'typo3' => '14.3.0-14.4.99',
            'backend' => '14.3.0-14.4.99',
            'dashboard' => '14.3.0-14.4.99',
        ],
        'conflicts' => [],
        'suggests' => [
            'scheduler' => '14.3.0-14.4.99',
        ],
    ],
];
