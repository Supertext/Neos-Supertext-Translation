<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Service;

use Neos\Flow\Annotations as Flow;
use Supertext\NeosTranslation\Configuration\Settings;
use Supertext\NeosTranslation\Sdk\V1\Languages;

/**
 * Maps language dimension values ("de", "en_US") to Supertext language codes with the
 * Supertext SDK's rules (the same in every Supertext plugin): the language mapping wins
 * (`languages.<value>.code` in Settings.yaml, else the choice under Administration →
 * Supertext); otherwise a dimension value that equals a Supertext code apart from case and
 * "_"/"-" is used ("en_US" => "en-US", "es"); otherwise the SDK's default where one is
 * obvious ("de" => "de-DE", "es_ES" => "es"); anything else ("en_IE", "gsw") throws
 * SupertextException `unsupported_language` before anything is sent. Sources are bare
 * ("en_US" => "en", a mapped "de-CH" => "de").
 */
#[Flow\Scope('singleton')]
class LanguageResolver
{
    #[Flow\Inject]
    protected Settings $settings;

    #[Flow\Inject]
    protected LanguageMappingStore $mappingStore;

    /**
     * The mapping in use: dimension value => Supertext code (Settings.yaml over the module).
     *
     * @return array<string, string>
     */
    public function mapping(): array
    {
        return LanguageMapping::codes($this->mappingStore->get(), $this->settings->getLanguages());
    }

    /**
     * *Translation review* for a target language: translations into it go through Supertext's
     * review service (Settings.yaml's `review` where set, else the choice under Administration →
     * Supertext; off by default, and off for all when `reviewUrl` is empty).
     */
    public function review(string $dimensionValue): bool
    {
        return $this->settings->getReviewUrl() !== ''
            && LanguageMapping::review($dimensionValue, $this->mappingStore->get(), $this->settings->getLanguages());
    }

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
        return self::primary($source) !== self::primary($target);
    }

    /** Supertext target code: the mapped code, else an exact Supertext code ("en_US" => "en-US"), else the default ("de" => "de-DE"). */
    public function targetCode(string $dimensionValue): string
    {
        return Languages::target($dimensionValue, $this->mapping());
    }

    /** Supertext source code: the bare language ("en_US" => "en"; a mapped "de-CH" => "de"). */
    public function sourceCode(string $dimensionValue): string
    {
        return Languages::source($dimensionValue, $this->mapping());
    }

    /** The target code, or null when the dimension value has no Supertext language (for supertext:check). */
    public function targetCodeOrNull(string $dimensionValue): ?string
    {
        return self::resolveTarget($dimensionValue, $this->mapping());
    }

    /**
     * "more", "less" or "default": Settings.yaml's `politeness` where set, else the choice under
     * Administration → Supertext (sent as it is: see supertext:check for targets without it).
     */
    public function politeness(string $dimensionValue): string
    {
        return LanguageMapping::politeness($dimensionValue, $this->mappingStore->get(), $this->settings->getLanguages());
    }

    /**
     * @param array<string, string> $mapping
     */
    public static function resolveTarget(string $dimensionValue, array $mapping): ?string
    {
        try {
            return Languages::target($dimensionValue, $mapping);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Whether a configured formal/informal tone can't be used for this target: true when
     * politeness is "more"/"less" and Supertext offers no tone for the target code.
     */
    public static function politenessUnsupported(string $targetCode, string $politeness): bool
    {
        return $politeness !== 'default' && Languages::supportsPoliteness($targetCode) === false;
    }

    private static function primary(string $value): string
    {
        return strtolower((string)strtok($value, '-_'));
    }
}
