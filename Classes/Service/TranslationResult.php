<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Service;

/** Outcome of one batch (one target language in one workspace). */
final class TranslationResult
{
    public int $nodes = 0;
    public int $fields = 0;
    public ?string $error = null;

    public function __construct(
        public readonly string $workspace,
        public readonly string $sourceLanguage,
        public readonly string $targetLanguage,
    ) {}
}
