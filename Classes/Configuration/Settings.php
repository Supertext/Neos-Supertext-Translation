<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Configuration;

use Neos\Flow\Annotations as Flow;

/**
 * Typed access to Supertext.NeosTranslation settings; environment variables win.
 */
#[Flow\Scope('singleton')]
class Settings
{
    /** @var array<string, mixed> */
    #[Flow\InjectConfiguration(package: 'Supertext.NeosTranslation')]
    protected array $settings = [];

    public function isEnabled(): bool
    {
        return (bool)($this->settings['enabled'] ?? true);
    }

    public function getApiKey(): string
    {
        $fromEnv = getenv('SUPERTEXT_API_KEY');
        if (is_string($fromEnv) && trim($fromEnv) !== '') {
            return trim($fromEnv);
        }
        return trim((string)($this->settings['apiKey'] ?? ''));
    }

    public function getBaseUrl(): string
    {
        $fromEnv = getenv('SUPERTEXT_API_ENDPOINT');
        $url = is_string($fromEnv) && trim($fromEnv) !== '' ? trim($fromEnv) : (string)($this->settings['endpoint'] ?? '');
        $url = $url !== '' ? $url : 'https://api.supertext.com/v1/';
        return rtrim($url, '/') . '/';
    }

    public function getPollInterval(): int
    {
        return max(1, (int)($this->settings['pollInterval'] ?? 2));
    }

    public function getPollTimeout(): int
    {
        return max(10, (int)($this->settings['pollTimeout'] ?? 240));
    }

    public function getLanguageDimension(): string
    {
        return (string)($this->settings['languageDimension'] ?? 'language') ?: 'language';
    }

    /** @return array{code?: string, politeness?: string, enabled?: bool} */
    public function getLanguage(string $dimensionValue): array
    {
        $languages = $this->settings['languages'] ?? [];
        $config = is_array($languages) ? ($languages[$dimensionValue] ?? []) : [];
        return is_array($config) ? $config : [];
    }

    /** @return list<string> */
    public function getPlainTextEditors(): array
    {
        return array_values(array_map('strval', (array)($this->settings['editors']['plainText'] ?? [])));
    }

    /** @return list<string> */
    public function getRichTextEditors(): array
    {
        return array_values(array_map('strval', (array)($this->settings['editors']['richText'] ?? [])));
    }

    /** @return list<string> */
    public function getExcludedProperties(): array
    {
        return array_values(array_map('strval', (array)($this->settings['excludedProperties'] ?? [])));
    }
}
