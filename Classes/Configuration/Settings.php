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

    /** @var array<string, mixed> */
    #[Flow\InjectConfiguration(path: 'http', package: 'Neos.Flow')]
    protected array $flowHttpSettings = [];

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

    /**
     * The language mapping: dimension value => Supertext code, from the `code` of each
     * `languages` entry (blank codes left out). Used for targets and sources alike.
     *
     * @return array<string, string>
     */
    public function getLanguageMapping(): array
    {
        return self::mappingFrom($this->settings['languages'] ?? []);
    }

    /**
     * @param mixed $languages the `languages` setting
     * @return array<string, string>
     */
    public static function mappingFrom(mixed $languages): array
    {
        $mapping = [];
        foreach (is_array($languages) ? $languages : [] as $dimensionValue => $config) {
            $code = is_array($config) ? trim((string)($config['code'] ?? '')) : '';
            if ($code !== '') {
                $mapping[(string)$dimensionValue] = $code;
            }
        }
        return $mapping;
    }

    /** The "Send version information to Supertext" setting; null when not set (default on). */
    public function getVersionReports(): ?bool
    {
        $value = $this->settings['versionReports'] ?? null;
        return $value === null ? null : (bool)$value;
    }

    /** The configured site URL (Neos.Flow.http.baseUri), if any. */
    public function getFlowBaseUri(): ?string
    {
        $value = trim((string)($this->flowHttpSettings['baseUri'] ?? ''));
        return $value !== '' ? $value : null;
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
