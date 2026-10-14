<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Service;

use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * Short-lived cache for upstream API results (default lifetime: 10 minutes).
 */
final readonly class ApiCache
{
    public function __construct(
        private FrontendInterface $cache,
    ) {}

    /**
     * @template T
     * @param list<mixed> $keyParts
     * @param callable(): T $producer
     * @return T
     */
    public function remember(array $keyParts, callable $producer, ?int $lifetime = null): mixed
    {
        $identifier = self::identifier($keyParts);
        if ($this->cache->has($identifier)) {
            return $this->cache->get($identifier);
        }
        $value = $producer();
        $this->cache->set($identifier, $value, [], $lifetime);
        return $value;
    }

    /**
     * @param list<mixed> $keyParts
     */
    public static function identifier(array $keyParts): string
    {
        return 'api_' . sha1(json_encode($keyParts, JSON_THROW_ON_ERROR));
    }

    public function get(string $identifier): mixed
    {
        return $this->cache->get($identifier);
    }

    public function set(string $identifier, mixed $value, int $lifetime): void
    {
        $this->cache->set($identifier, $value, [], $lifetime);
    }

    public function remove(string $identifier): void
    {
        $this->cache->remove($identifier);
    }

    public function flush(): void
    {
        $this->cache->flush();
    }
}
