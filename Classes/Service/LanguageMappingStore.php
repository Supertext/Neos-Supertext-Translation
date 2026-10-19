<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Service;

use Neos\Flow\Annotations as Flow;
use Supertext\NeosTranslation\Reporting\CacheStateStorage;

/**
 * The language mapping chosen under Administration → Supertext: JSON
 * `{"<dimension value>": {"code": "de-CH", "politeness": "more"}}` in the persistent cache
 * Supertext_NeosTranslation_State (Configuration/Caches.yaml), next to the reporting state,
 * so it survives flow:cache:flush and deployments.
 */
#[Flow\Scope('singleton')]
class LanguageMappingStore
{
    public const KEY = 'languageMapping';

    #[Flow\Inject]
    protected CacheStateStorage $storage;

    /** @return array<string, array{code?: string, politeness?: string}> */
    public function get(): array
    {
        try {
            return LanguageMapping::decode($this->storage->get(self::KEY));
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<string, array{code: string, politeness: string}> $mapping */
    public function save(array $mapping): void
    {
        $this->storage->set(self::KEY, (string)json_encode($mapping, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
