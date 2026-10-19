<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Service;

use Supertext\NeosTranslation\Sdk\V1\Languages;

/**
 * The language mapping (dimension value → Supertext code and tone), without Neos classes so
 * it is tested on its own (Tests/LanguageMappingTest.php).
 *
 * Per language also *Translation review* (`review`: translations into it go through Supertext's
 * review service, see Api\SupertextClientFactory).
 *
 * Two sources: what an administrator chose under Administration → Supertext (stored in the
 * persistent cache Supertext_NeosTranslation_State, see LanguageMappingStore) and the
 * `Supertext.NeosTranslation.languages.<value>` settings (Settings.yaml), which win where set,
 * for automated setups. A value in neither falls back to the Supertext SDK: exact match,
 * else its default (de → de-DE), else unsupported_language.
 */
final class LanguageMapping
{
    public const POLITENESS = ['default', 'more', 'less'];

    /** Swiss German dialects: Locale::getDisplayName() has no good name for the -u-sd- extension. */
    private const DIALECTS = [
        'gsw-u-sd-chzh' => ['en' => 'Zurich', 'de' => 'Zürich', 'fr' => 'Zurich', 'it' => 'Zurigo'],
        'gsw-u-sd-chbe' => ['en' => 'Bern', 'de' => 'Bern', 'fr' => 'Berne', 'it' => 'Berna'],
    ];

    private function __construct()
    {
    }

    /**
     * The codes to translate with: stored choices, overridden by the settings' `code`s.
     *
     * @param array<string, array{code?: string, politeness?: string, review?: bool}> $stored
     * @param mixed $settingsLanguages the `languages` setting
     * @return array<string, string>
     */
    public static function codes(array $stored, mixed $settingsLanguages): array
    {
        $mapping = [];
        foreach ($stored as $value => $entry) {
            $code = trim((string)($entry['code'] ?? ''));
            if ($code !== '') {
                $mapping[(string)$value] = $code;
            }
        }
        foreach (self::overrides($settingsLanguages) as $value => $code) {
            $mapping[$value] = $code;
        }
        return $mapping;
    }

    /**
     * The settings' codes (`languages.<value>.code`, blank ones left out).
     *
     * @return array<string, string>
     */
    public static function overrides(mixed $settingsLanguages): array
    {
        $mapping = [];
        foreach (is_array($settingsLanguages) ? $settingsLanguages : [] as $value => $config) {
            $code = is_array($config) ? trim((string)($config['code'] ?? '')) : '';
            if ($code !== '') {
                $mapping[(string)$value] = $code;
            }
        }
        return $mapping;
    }

    /**
     * "more", "less" or "default": the settings' `politeness` where set, else the stored choice.
     *
     * @param array<string, array{code?: string, politeness?: string, review?: bool}> $stored
     */
    public static function politeness(string $value, array $stored, mixed $settingsLanguages): string
    {
        $config = is_array($settingsLanguages) && is_array($settingsLanguages[$value] ?? null) ? $settingsLanguages[$value] : [];
        $politeness = isset($config['politeness']) ? (string)$config['politeness'] : (string)($stored[$value]['politeness'] ?? 'default');
        return in_array($politeness, ['more', 'less'], true) ? $politeness : 'default';
    }

    /**
     * *Translation review* for a language: the settings' `review` where set, else the stored choice
     * (off by default).
     *
     * @param array<string, array{code?: string, politeness?: string, review?: bool}> $stored
     */
    public static function review(string $value, array $stored, mixed $settingsLanguages): bool
    {
        $config = is_array($settingsLanguages) && is_array($settingsLanguages[$value] ?? null) ? $settingsLanguages[$value] : [];
        return isset($config['review']) ? (bool)$config['review'] : (bool)($stored[$value]['review'] ?? false);
    }

    /**
     * Supertext's target codes for the combo box, sorted by English name, with the name in the
     * interface language where PHP's intl extension knows it: code => "Name (code)".
     *
     * @return array<string, string>
     */
    public static function options(string $interfaceLanguage = 'en'): array
    {
        $options = [];
        foreach (Languages::targetOptions() as $target) {
            $options[$target['code']] = self::name($target['code'], $target['name'], $interfaceLanguage) . ' (' . $target['code'] . ')';
        }
        return $options;
    }

    /** @return list<string> the target codes that offer formal/informal */
    public static function politenessCodes(): array
    {
        return array_values(array_map(
            static fn (array $target): string => $target['code'],
            array_filter(Languages::TARGETS, static fn (array $target): bool => $target['politeness']),
        ));
    }

    /**
     * One row per dimension value for the Supertext module.
     *
     * `selected`: the code the combo box shows (the setting's code where one is set, else the
     * stored choice, else the SDK's suggestion, else '' for "— choose —"); `source`:
     * 'settings', 'stored', 'suggested' or 'none'.
     *
     * @param list<array{value: string, label: string}> $languages the language dimension's values
     * @param array<string, array{code?: string, politeness?: string, review?: bool}> $stored
     * @return list<array{value: string, label: string, selected: string, source: string, overridden: bool, politeness: string, politenessFromSettings: bool, politenessSupported: bool, review: bool, reviewFromSettings: bool}>
     */
    public static function rows(array $languages, array $stored, mixed $settingsLanguages): array
    {
        $overrides = self::overrides($settingsLanguages);
        $rows = [];
        foreach ($languages as $language) {
            $value = $language['value'];
            $storedCode = trim((string)($stored[$value]['code'] ?? ''));
            if (isset($overrides[$value])) {
                [$selected, $source] = [$overrides[$value], 'settings'];
            } elseif ($storedCode !== '') {
                [$selected, $source] = [$storedCode, 'stored'];
            } elseif (($suggested = Languages::suggestTarget($value)) !== null) {
                [$selected, $source] = [$suggested, 'suggested'];
            } else {
                [$selected, $source] = ['', 'none'];
            }
            $config = is_array($settingsLanguages) && is_array($settingsLanguages[$value] ?? null) ? $settingsLanguages[$value] : [];
            $rows[] = [
                'value' => $value,
                'label' => $language['label'] !== '' ? $language['label'] : $value,
                'selected' => $selected,
                'source' => $source,
                'overridden' => $source === 'settings',
                'politeness' => self::politeness($value, $stored, $settingsLanguages),
                'politenessFromSettings' => isset($config['politeness']),
                'politenessSupported' => $selected !== '' && Languages::supportsPoliteness($selected) === true,
                'review' => self::review($value, $stored, $settingsLanguages),
                'reviewFromSettings' => isset($config['review']),
            ];
        }
        return $rows;
    }

    /**
     * Checks a submitted form against Supertext's list and returns what to store: only known
     * dimension values, only Supertext target codes, a tone only where the target offers one.
     * Values set in the settings are left as they were stored (their combo box is read-only);
     * *Translation review* is stored for every known value (on only when ticked).
     *
     * @param mixed $input mapping[<value>][code|politeness] from the form
     * @param list<string> $knownValues the language dimension's values
     * @param array<string, array{code?: string, politeness?: string, review?: bool}> $stored what is stored now
     * @return array{mapping: array<string, array{code: string, politeness: string, review: bool}>, invalid: list<string>}
     */
    public static function fromForm(mixed $input, array $knownValues, array $stored, mixed $settingsLanguages): array
    {
        $overrides = self::overrides($settingsLanguages);
        $mapping = [];
        $invalid = [];
        foreach ($stored as $value => $entry) {
            // Keep choices for values that are set in the settings or not in the dimension (any more).
            if (isset($overrides[$value]) || !in_array((string)$value, $knownValues, true)) {
                $mapping[(string)$value] = ['code' => (string)($entry['code'] ?? ''), 'politeness' => (string)($entry['politeness'] ?? 'default'), 'review' => (bool)($entry['review'] ?? false)];
            }
        }
        foreach (is_array($input) ? $input : [] as $value => $entry) {
            $value = (string)$value;
            if (!in_array($value, $knownValues, true) || !is_array($entry)) {
                continue;
            }
            $review = in_array($entry['review'] ?? '', ['1', 1, true, 'on'], true);
            if (isset($overrides[$value])) {
                // Code set in Settings.yaml: only the review checkbox comes from the form.
                $mapping[$value] = ['code' => $mapping[$value]['code'] ?? '', 'politeness' => $mapping[$value]['politeness'] ?? 'default', 'review' => $review];
                continue;
            }
            $code = trim((string)($entry['code'] ?? ''));
            if ($code !== '' && !Languages::isSupportedTarget($code)) {
                $invalid[] = $value;
                continue;
            }
            $code = $code === '' ? '' : (string)Languages::matchTarget($code);
            $politeness = (string)($entry['politeness'] ?? 'default');
            if (!in_array($politeness, self::POLITENESS, true) || $code === '' || Languages::supportsPoliteness($code) !== true) {
                $politeness = 'default';
            }
            $mapping[$value] = ['code' => $code, 'politeness' => $politeness, 'review' => $review];
        }
        ksort($mapping);
        return ['mapping' => $mapping, 'invalid' => $invalid];
    }

    /**
     * @return array<string, array{code?: string, politeness?: string, review?: bool}>
     */
    public static function decode(?string $json): array
    {
        $data = $json !== null ? json_decode($json, true) : null;
        $stored = [];
        foreach (is_array($data) ? $data : [] as $value => $entry) {
            if (is_array($entry)) {
                $stored[(string)$value] = array_filter([
                    'code' => is_string($entry['code'] ?? null) ? $entry['code'] : null,
                    'politeness' => is_string($entry['politeness'] ?? null) ? $entry['politeness'] : null,
                    'review' => is_bool($entry['review'] ?? null) ? $entry['review'] : null,
                ], static fn (string|bool|null $v): bool => $v !== null);
            }
        }
        return $stored;
    }

    private static function name(string $code, string $englishName, string $interfaceLanguage): string
    {
        $language = strtolower(substr($interfaceLanguage, 0, 2));
        if ($language === 'en' || $language === '' || !class_exists(\Locale::class)) {
            return $englishName;
        }
        if (isset(self::DIALECTS[$code])) {
            $base = \Locale::getDisplayLanguage('gsw', $language);
            $place = self::DIALECTS[$code][$language] ?? self::DIALECTS[$code]['en'];
            return (is_string($base) && $base !== 'gsw' ? $base : $englishName) . ' (' . $place . ')';
        }
        $name = \Locale::getDisplayName(str_replace('-', '_', $code), $language);
        if (!is_string($name) || $name === $code || $name === str_replace('-', '_', $code)) {
            return $englishName;
        }
        return function_exists('mb_strtoupper')
            ? mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1)
            : ucfirst($name);
    }
}
