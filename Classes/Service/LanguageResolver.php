<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Service;

use Neos\Flow\Annotations as Flow;
use Supertext\NeosTranslation\Configuration\Settings;
use Supertext\NeosTranslation\Sdk\V1\Languages;

/**
 * Maps language dimension values ("de", "en_US") to Supertext language codes with the
 * Supertext SDK's rules (the same in every Supertext plugin, defaults only where obvious): the
 * language mapping (`languages.<value>.code`) wins; otherwise a dimension value that
 * equals a Supertext code apart from case and "_"/"-" is used ("en_US" => "en-US",
 * "es"); otherwise the SDK's default ("de" => "de-DE", "es_ES" => "es");
 * anything else ("en_UK", "gsw") throws SupertextException `unsupported_language` before
 * anything is sent. Sources are bare ("en_US" => "en", a mapped "de-CH" => "de").
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
        return self::primary($source) !== self::primary($target);
    }

    /** Supertext target code: the mapped code, else an exact Supertext code ("en_US" => "en-US"), else the SDK's default ("fr" => "fr-FR"). */
    public function targetCode(string $dimensionValue): string
    {
        return Languages::target($dimensionValue, $this->settings->getLanguageMapping());
    }

    /** Supertext source code: the bare language ("en_US" => "en"; a mapped "de-CH" => "de"). */
    public function sourceCode(string $dimensionValue): string
    {
        return Languages::source($dimensionValue, $this->settings->getLanguageMapping());
    }

    /** The target code, or null when the dimension value has no Supertext language (for supertext:check). */
    public function targetCodeOrNull(string $dimensionValue): ?string
    {
        return self::resolveTarget($dimensionValue, $this->settings->getLanguageMapping());
    }

    /** "more", "less" or "default", as configured (sent as it is: see supertext:check for targets without it) */
    public function politeness(string $dimensionValue): string
    {
        $politeness = (string)($this->settings->getLanguage($dimensionValue)['politeness'] ?? 'default');
        return in_array($politeness, ['more', 'less'], true) ? $politeness : 'default';
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
