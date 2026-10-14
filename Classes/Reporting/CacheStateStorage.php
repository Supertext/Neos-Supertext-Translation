<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Reporting;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cache\CacheManager;

/**
 * StateStorage in the persistent Flow cache `Supertext_NeosTranslation_State`
 * (Configuration/Caches.yaml; not emptied by flow:cache:flush).
 */
#[Flow\Scope('singleton')]
class CacheStateStorage implements StateStorage
{
    public const CACHE_NAME = 'Supertext_NeosTranslation_State';

    #[Flow\Inject]
    protected CacheManager $cacheManager;

    public function get(string $key): ?string
    {
        $value = $this->cacheManager->getCache(self::CACHE_NAME)->get($key);
        return is_string($value) ? $value : null;
    }

    public function set(string $key, string $value): void
    {
        $this->cacheManager->getCache(self::CACHE_NAME)->set($key, $value);
    }
}
