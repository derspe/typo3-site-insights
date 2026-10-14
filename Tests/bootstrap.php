<?php

declare(strict_types=1);

// Works standalone (vendor/ of the extension) and inside a TYPO3 project (packages/<ext>)
$candidates = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
];
foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        $loader = require $candidate;
        $loader->addPsr4('Derspe\\SiteInsights\\Tests\\', __DIR__);
        return;
    }
}
throw new RuntimeException('Run "composer install" first.');
