<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Tests\Unit\Fixtures;

use Derspe\SiteInsights\Service\ApiCache;
use Derspe\SiteInsights\Settings\SiteSettings;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Crypto\Cipher\CipherService;
use TYPO3\CMS\Core\Crypto\Cipher\KeyFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

abstract class UnitTestCase extends TestCase
{
    protected InMemoryDatabase $db;
    /** @var array<string, mixed> */
    protected array $cacheStore = [];
    /** @var array<string, mixed> */
    private array $globalsBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->globalsBackup = [
            'TYPO3_CONF_VARS' => $GLOBALS['TYPO3_CONF_VARS'] ?? null,
            'BE_USER' => $GLOBALS['BE_USER'] ?? null,
            'LANG' => $GLOBALS['LANG'] ?? null,
        ];
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('k', 96);
        $this->db = new InMemoryDatabase($this);
        $this->cacheStore = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->globalsBackup as $key => $value) {
            if ($value === null) {
                unset($GLOBALS[$key]);
            } else {
                $GLOBALS[$key] = $value;
            }
        }
        parent::tearDown();
    }

    protected function settings(): SiteSettings
    {
        return new SiteSettings($this->db->connectionPool(), new CipherService(), new KeyFactory());
    }

    protected function cache(): ApiCache
    {
        $frontend = $this->createMock(FrontendInterface::class);
        $frontend->method('has')->willReturnCallback(fn(string $id): bool => array_key_exists($id, $this->cacheStore));
        $frontend->method('get')->willReturnCallback(fn(string $id): mixed => $this->cacheStore[$id] ?? false);
        $frontend->method('set')->willReturnCallback(function (string $id, mixed $value): void {
            $this->cacheStore[$id] = $value;
        });
        $frontend->method('remove')->willReturnCallback(function (string $id): bool {
            unset($this->cacheStore[$id]);
            return true;
        });
        return new ApiCache($frontend);
    }

    protected static function site(string $identifier, int $rootPageId, string $base = ''): Site
    {
        return new Site($identifier, $rootPageId, [
            'base' => $base !== '' ? $base : 'https://' . $identifier . '.example/',
            'websiteTitle' => ucfirst($identifier),
            'languages' => [['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/']],
        ]);
    }

    /**
     * @param list<Site> $sites
     */
    protected function siteFinder(array $sites): SiteFinder
    {
        $indexed = [];
        foreach ($sites as $site) {
            $indexed[$site->getIdentifier()] = $site;
        }
        $finder = $this->createMock(SiteFinder::class);
        $finder->method('getAllSites')->willReturn($indexed);
        $finder->method('getSiteByIdentifier')->willReturnCallback(fn(string $id): Site => $indexed[$id] ?? throw new \RuntimeException('no site'));
        return $finder;
    }

    /**
     * @param list<int> $mountedRootPages page ids inside the user's DB mounts
     */
    protected function backendUser(bool $admin, bool $moduleAccess = true, array $mountedRootPages = [], int $uid = 7): BackendUserAuthentication
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->user = ['uid' => $uid];
        $user->method('isAdmin')->willReturn($admin);
        $user->method('check')->willReturn($moduleAccess);
        $user->method('isInWebMount')->willReturnCallback(fn($id): ?int => in_array((int)$id, $mountedRootPages, true) ? (int)$id : null);
        return $user;
    }

    /**
     * Instance of a class without running its constructor (for dependencies a test never touches).
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    protected static function unused(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
