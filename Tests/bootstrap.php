<?php

declare(strict_types=1);

// Works standalone (vendor/ of the extension), inside a TYPO3 project (packages/<ext>)
// and from the root of a TYPO3 project that uses this extension (vendor/bin/phpunit -c <ext>/phpunit.xml.dist)
$candidates = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
    getcwd() . '/vendor/autoload.php',
];
foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        $loader = require $candidate;
        $loader->addPsr4('Derspe\\SiteInsights\\Tests\\', __DIR__);
        return;
    }
}
throw new RuntimeException('Run "composer install" first.');
