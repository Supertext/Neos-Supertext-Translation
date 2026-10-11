<?php

// Standalone test for the language code rules (no Neos needed):
//   php Tests/LanguageCodesTest.php
declare(strict_types=1);

require __DIR__ . '/../Classes/Api/LanguageCodes.php';

use Supertext\NeosTranslation\Api\LanguageCodes;

$cases = [
    // [description, actual, expected]
    ['bare source', LanguageCodes::source('en'), 'en'],
    ['regional source => bare', LanguageCodes::source('en-US'), 'en'],
    ['regional source with underscore and encoding => bare', LanguageCodes::source('de_CH.UTF-8'), 'de'],
    ['source override wins, reduced to its language', LanguageCodes::source('en_US.UTF-8', 'fr-CH'), 'fr'],
    ['empty source stays empty', LanguageCodes::source(''), ''],
    ['bare target => default region', LanguageCodes::target('de'), 'de-DE'],
    ['bare target en => en-US', LanguageCodes::target('en'), 'en-US'],
    ['bare target zh => zh-CN', LanguageCodes::target('zh'), 'zh-CN'],
    ['regional target kept', LanguageCodes::target('fr-CH'), 'fr-CH'],
    ['regional target underscore normalized', LanguageCodes::target('de_CH'), 'de-CH'],
    ['regional target case normalized', LanguageCodes::target('DE-ch'), 'de-CH'],
    ['Neos dimension value', LanguageCodes::target('en_US'), 'en-US'],
    ['script subtag kept', LanguageCodes::target('zh-hant'), 'zh-Hant'],
    ['numeric region kept', LanguageCodes::target('es-419'), 'es-419'],
    ['target override wins', LanguageCodes::target('de_DE.UTF-8', 'de-CH'), 'de-CH'],
    ['target override normalized', LanguageCodes::target('de', 'de_at'), 'de-AT'],
    ['bare override gets default region', LanguageCodes::target('de_CH.UTF-8', 'it'), 'it-IT'],
    ['empty override ignored', LanguageCodes::target('fr', ' '), 'fr-FR'],
    ['unknown bare target unchanged', LanguageCodes::target('rm'), 'rm'],
    ['empty target stays empty', LanguageCodes::target(''), ''],
];

$failures = 0;
foreach ($cases as [$description, $actual, $expected]) {
    if ($actual !== $expected) {
        $failures++;
        fwrite(STDERR, sprintf("%s: expected %s, got %s\n", $description, json_encode($expected), json_encode($actual)));
    }
}
echo $failures === 0 ? 'OK: ' . count($cases) . " language code cases\n" : "FAILED: $failures\n";
exit($failures === 0 ? 0 : 1);
