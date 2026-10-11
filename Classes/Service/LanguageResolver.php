<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Service;

use Neos\Flow\Annotations as Flow;
use Supertext\NeosTranslation\Api\LanguageCodes;
use Supertext\NeosTranslation\Configuration\Settings;

/**
 * Maps language dimension values ("de", "en_US") to Supertext language codes:
 * regional targets ("de" => "de-DE", "en_US" => "en-US", or the configured code)
 * and bare sources ("en_US" => "en"). Rules in Api\LanguageCodes.
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

    /**
     * Supertext target code, always with a region: the configured code ("de" =>
     * "de-CH"), else the dimension value ("en_US" => "en-US", bare "de" => "de-DE").
     */
    public function targetCode(string $dimensionValue): string
    {
        return LanguageCodes::target($dimensionValue, $this->configuredCode($dimensionValue));
    }

    /** Supertext source code: the bare language ("en_US" => "en"; a configured "de-CH" => "de"). */
    public function sourceCode(string $dimensionValue): string
    {
        return LanguageCodes::source($dimensionValue, $this->configuredCode($dimensionValue));
    }

    /** "more", "less" or "default" */
    public function politeness(string $dimensionValue): string
    {
        $politeness = (string)($this->settings->getLanguage($dimensionValue)['politeness'] ?? 'default');
        return in_array($politeness, ['more', 'less'], true) ? $politeness : 'default';
    }

    private function configuredCode(string $dimensionValue): string
    {
        return trim((string)($this->settings->getLanguage($dimensionValue)['code'] ?? ''));
    }

    private function primary(string $value): string
    {
        return strtolower((string)strtok($value, '-_'));
    }
}
