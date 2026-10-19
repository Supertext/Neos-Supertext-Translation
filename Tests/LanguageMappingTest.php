<?php

// The language mapping of Administration → Supertext (Classes/Service/LanguageMapping.php):
// rows and preselection, the Settings.yaml override, saving (validated against Supertext's
// list) and the codes translations use. Plain PHP with the bundled SDK, no Neos needed:
//   php Tests/LanguageMappingTest.php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Supertext\\NeosTranslation\\Sdk\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__ . '/../lib/supertext-sdk/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
require_once __DIR__ . '/../Classes/Service/LanguageMapping.php';

use Supertext\NeosTranslation\Sdk\V1\Languages;
use Supertext\NeosTranslation\Sdk\V1\SupertextException;
use Supertext\NeosTranslation\Service\LanguageMapping;

$failures = [];
$check = static function (string $name, mixed $actual, mixed $expected) use (&$failures): void {
    if ($actual !== $expected) {
        $failures[] = sprintf("%s:\n  expected: %s\n  actual:   %s", $name, json_encode($expected), json_encode($actual));
    }
};

$languages = [
    ['value' => 'en_US', 'label' => 'English (US)'],
    ['value' => 'de', 'label' => 'Deutsch'],
    ['value' => 'fr', 'label' => 'Français'],
    ['value' => 'it', 'label' => ''],
    ['value' => 'en_IE', 'label' => 'English (Ireland)'],
];
$stored = ['fr' => ['code' => 'fr-CH', 'politeness' => 'more'], 'gone' => ['code' => 'nl', 'politeness' => 'default']];
$settings = ['it' => ['code' => 'it-CH', 'politeness' => 'less'], 'de' => ['enabled' => true]];

// 1. Rows: settings > stored > SDK suggestion > "— choose —".
$rows = array_column(LanguageMapping::rows($languages, $stored, $settings), null, 'value');
$check('en_US exact match preselected', [$rows['en_US']['selected'], $rows['en_US']['source']], ['en-US', 'suggested']);
$check('de default preselected', [$rows['de']['selected'], $rows['de']['source']], ['de-DE', 'suggested']);
$check('fr stored choice', [$rows['fr']['selected'], $rows['fr']['source'], $rows['fr']['politeness']], ['fr-CH', 'stored', 'more']);
$check('it from Settings.yaml, read-only', [$rows['it']['selected'], $rows['it']['overridden'], $rows['it']['politenessFromSettings'], $rows['it']['politeness']], ['it-CH', true, true, 'less']);
$check('it label falls back to the value', $rows['it']['label'], 'it');
$check('en_IE has no default: choose', [$rows['en_IE']['selected'], $rows['en_IE']['source']], ['', 'none']);
$check('tone shown for de-DE', $rows['de']['politenessSupported'], true);
$check('tone hidden for en-US', $rows['en_US']['politenessSupported'], false);
$check('tone hidden without a code', $rows['en_IE']['politenessSupported'], false);

// 2. Codes for translations: Settings.yaml wins over the stored choice; nothing else added.
$codes = LanguageMapping::codes($stored, $settings);
$check('codes', $codes, ['fr' => 'fr-CH', 'gone' => 'nl', 'it' => 'it-CH']);
$check('target via mapping fr', Languages::target('fr', $codes), 'fr-CH');
$check('target via override it', Languages::target('it', $codes), 'it-CH');
$check('target unmapped de: SDK default', Languages::target('de', $codes), 'de-DE');
$check('source via mapping fr (bare)', Languages::source('fr', $codes), 'fr');
try {
    Languages::target('en_IE', $codes);
    $failures[] = 'expected unsupported_language for en_IE';
} catch (SupertextException $e) {
    $check('en_IE unmapped: unsupported_language', $e->errorCode, 'unsupported_language');
}
$check('politeness: settings win', LanguageMapping::politeness('it', $stored, $settings), 'less');
$check('politeness: stored', LanguageMapping::politeness('fr', $stored, $settings), 'more');
$check('politeness: default', LanguageMapping::politeness('de', $stored, $settings), 'default');
$check('politeness: unknown value is default', LanguageMapping::politeness('fr', ['fr' => ['politeness' => 'very']], []), 'default');
$check('overrides ignore blank codes', LanguageMapping::overrides(['de' => ['code' => ' '], 'fr' => ['politeness' => 'more'], 'x' => 'y']), []);

// 3. Saving: only known values and Supertext target codes; overridden and vanished values keep what was stored.
$known = array_column($languages, 'value');
$result = LanguageMapping::fromForm([
    'en_US' => ['code' => 'en-GB', 'politeness' => 'more'], // en-GB has no tone: stored as default
    'de' => ['code' => 'de_ch', 'politeness' => 'more'],    // normalised to de-CH
    'fr' => ['code' => '', 'politeness' => 'more'],         // back to "— choose —" (the SDK default applies)
    'it' => ['code' => 'nl'],                                // set in Settings.yaml: ignored
    'unknown' => ['code' => 'nl'],                           // not a dimension value: ignored
], $known, $stored, $settings);
$check('saved mapping', $result['mapping'], [
    'de' => ['code' => 'de-CH', 'politeness' => 'more', 'review' => false],
    'en_US' => ['code' => 'en-GB', 'politeness' => 'default', 'review' => false],
    'fr' => ['code' => '', 'politeness' => 'default', 'review' => false],
    'gone' => ['code' => 'nl', 'politeness' => 'default', 'review' => false],
    'it' => ['code' => '', 'politeness' => 'default', 'review' => false],
]);
$check('nothing invalid', $result['invalid'], []);
$invalid = LanguageMapping::fromForm(['de' => ['code' => 'de-XX'], 'en_IE' => ['code' => 'en']], $known, [], []);
$check('codes not in the list are refused', $invalid['invalid'], ['de', 'en_IE']);
$check('refused codes are not stored', $invalid['mapping'], []);
$check('not an array: nothing', LanguageMapping::fromForm('x', $known, [], [])['mapping'], []);

// 3b. Translation review: off by default, a checkbox per language (also for codes set in
//     Settings.yaml), Settings.yaml's `review` wins.
$check('review off by default', LanguageMapping::review('de', [], []), false);
$check('review stored', LanguageMapping::review('fr', ['fr' => ['review' => true]], []), true);
$check('review from settings wins', LanguageMapping::review('fr', ['fr' => ['review' => true]], ['fr' => ['review' => false]]), false);
$check('review from settings on', LanguageMapping::review('it', [], ['it' => ['review' => true]]), true);
$reviewRows = array_column(LanguageMapping::rows($languages, ['fr' => ['code' => 'fr-CH', 'review' => true]], ['it' => ['code' => 'it-CH', 'review' => true]]), null, 'value');
$check('row review stored', [$reviewRows['fr']['review'], $reviewRows['fr']['reviewFromSettings']], [true, false]);
$check('row review from settings', [$reviewRows['it']['review'], $reviewRows['it']['reviewFromSettings']], [true, true]);
$check('row review off', $reviewRows['de']['review'], false);
$saved = LanguageMapping::fromForm([
    'de' => ['code' => 'de-CH', 'review' => '1'],
    'fr' => ['code' => 'fr-CH', 'review' => ''],           // unticked (Flow's hidden field)
    'it' => ['code' => 'nl', 'review' => '1'],             // code in Settings.yaml: only review saved
], $known, ['fr' => ['code' => 'fr-CH', 'review' => true], 'it' => ['code' => 'it-IT', 'politeness' => 'more']], $settings)['mapping'];
$check('review ticked', $saved['de']['review'], true);
$check('review unticked', $saved['fr']['review'], false);
$check('review for a Settings.yaml code, stored code kept', $saved['it'], ['code' => 'it-IT', 'politeness' => 'more', 'review' => true]);
$check('decode review', LanguageMapping::decode('{"de":{"code":"de-CH","review":true},"fr":{"review":"yes"}}'), ['de' => ['code' => 'de-CH', 'review' => true], 'fr' => []]);

// 4. Combo box options: every target, "Name (code)", localised where intl knows the name.
$options = LanguageMapping::options('en');
$check('options: one per target', count($options), count(Languages::TARGETS));
$check('options: en label', $options['de-CH'] ?? null, 'German (Switzerland) (de-CH)');
if (class_exists(\Locale::class)) {
    $german = LanguageMapping::options('de');
    $check('options: de label localised', $german['de-CH'] ?? null, 'Deutsch (Schweiz) (de-CH)');
    $check('options: dialect name', str_ends_with($german['gsw-u-sd-chzh'] ?? '', '(Zürich) (gsw-u-sd-chzh)'), true);
}
$check('politeness codes include de-DE, not en-US', [in_array('de-DE', LanguageMapping::politenessCodes(), true), in_array('en-US', LanguageMapping::politenessCodes(), true)], [true, false]);

// 5. Stored JSON: garbage is ignored.
$check('decode null', LanguageMapping::decode(null), []);
$check('decode garbage', LanguageMapping::decode('{"de":"x","fr":{"code":"fr-CH","politeness":1}}'), ['fr' => ['code' => 'fr-CH']]);

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "OK: language mapping\n";
