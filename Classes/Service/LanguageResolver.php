<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Service;

use Neos\Flow\Annotations as Flow;
use Supertext\NeosTranslation\Configuration\Settings;

/**
 * Maps language dimension values ("de", "en_US") to Supertext language codes.
 */
#[Flow\Scope('singleton')]
class LanguageResolver
{
    #[Flow\Inject]
    protected Settings $settings;

    /**
     * True when a variant from $source to $target needs translating: the target is
     * enabled and the languages differ (en_US -> en_UK is a regional variant of the
     * same language and is left alone).
     */
    public function needsTranslation(string $source, string $target): bool
    {
        if (($this->settings->getLanguage($target)['enabled'] ?? true) === false) {
            return false;
        }
        return $this->primary($source) !== $this->primary($target);
    }

    /** Supertext target code, e.g. "de" => "de-CH" when configured, "en_US" => "en-US". */
    public function targetCode(string $dimensionValue): string
    {
        $code = trim((string)($this->settings->getLanguage($dimensionValue)['code'] ?? ''));
        return $code !== '' ? $code : str_replace('_', '-', $dimensionValue);
    }

    /** Supertext source code: the primary subtag ("en_US" => "en"). */
    public function sourceCode(string $dimensionValue): string
    {
        return $this->primary($dimensionValue);
    }

    /** "more", "less" or "default" */
    public function politeness(string $dimensionValue): string
    {
        $politeness = (string)($this->settings->getLanguage($dimensionValue)['politeness'] ?? 'default');
        return in_array($politeness, ['more', 'less'], true) ? $politeness : 'default';
    }

    private function primary(string $value): string
    {
        return strtolower((string)strtok($value, '-_'));
    }
}
