<?php

// The back-end module's strings (Resources/Private/Translations/{en,de,fr,it}/Main.xlf).
// No Neos needed:
//   php Tests/InterfaceStringsTest.php
// Checks that German, French and Italian have exactly the English ids, a non-empty target
// for each, the same placeholders ({0}, {1}, …) and "Supertext" untranslated; and that every
// id the module uses (templates, controller, Settings.yaml) exists in English.
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

/** @return array<string, array{source: string, target: ?string}> */
$read = static function (string $file) use (&$failures): array {
    $dom = new DOMDocument();
    if (!@$dom->load($file, LIBXML_NONET)) {
        $failures[] = "$file: not well-formed XML";
        return [];
    }
    $units = [];
    foreach ($dom->getElementsByTagName('trans-unit') as $unit) {
        $id = $unit->getAttribute('id');
        if (isset($units[$id])) {
            $failures[] = "$file: duplicate id $id";
        }
        $source = $unit->getElementsByTagName('source')->item(0)?->textContent ?? '';
        $target = $unit->getElementsByTagName('target')->item(0)?->textContent;
        $units[$id] = ['source' => $source, 'target' => $target];
    }
    return $units;
};
$placeholders = static function (string $text): array {
    preg_match_all('/\{\d+\}/', $text, $m);
    $list = $m[0];
    sort($list);
    return $list;
};

$english = $read("$root/Resources/Private/Translations/en/Main.xlf");
if ($english === []) {
    $failures[] = 'en/Main.xlf has no strings';
}
foreach (['de', 'fr', 'it'] as $language) {
    $file = "$root/Resources/Private/Translations/$language/Main.xlf";
    $units = $read($file);
    foreach (array_diff(array_keys($english), array_keys($units)) as $id) {
        $failures[] = "$language: missing $id";
    }
    foreach (array_diff(array_keys($units), array_keys($english)) as $id) {
        $failures[] = "$language: $id is not in English";
    }
    foreach ($units as $id => $unit) {
        if (!isset($english[$id])) {
            continue;
        }
        if ($unit['source'] !== $english[$id]['source']) {
            $failures[] = "$language: $id has another source than English";
        }
        if ($unit['target'] === null || trim($unit['target']) === '') {
            $failures[] = "$language: $id has no target";
            continue;
        }
        if ($placeholders($unit['target']) !== $placeholders($english[$id]['source'])) {
            $failures[] = "$language: $id placeholders differ";
        }
        if (str_contains($english[$id]['source'], 'Supertext') && !str_contains($unit['target'], 'Supertext')) {
            $failures[] = "$language: $id translates \"Supertext\"";
        }
    }
}

// Ids used by the module.
$used = [];
foreach (glob("$root/Resources/Private/{Templates,Partials}/*/*.html", GLOB_BRACE) ?: [] as $template) {
    preg_match_all("/id: '([a-zA-Z0-9_.]+)'/", (string)file_get_contents($template), $m);
    array_push($used, ...$m[1]);
}
foreach (glob("$root/Classes/Controller/*.php") ?: [] as $controller) {
    preg_match_all("/translate\\('([a-zA-Z0-9_.]+)'/", (string)file_get_contents($controller), $m);
    array_push($used, ...$m[1]);
}
// Built as 'summary.' . $key in the controller.
foreach (['ok', 'changed', 'untranslated', 'invalid', 'missing'] as $key) {
    $used[] = 'summary.' . $key;
}
preg_match_all("/Supertext\\.NeosTranslation:Main:([a-zA-Z0-9_.]+)/", (string)file_get_contents("$root/Configuration/Settings.yaml"), $m);
array_push($used, ...$m[1]);
// Every FileTransferException reason has an error.<reason> string.
preg_match_all("/'([a-z_]+)' => '/", (string)file_get_contents("$root/Classes/Files/FileTransferException.php"), $m);
foreach ($m[1] as $reason) {
    $used[] = 'error.' . $reason;
}
// A prefix such as 'summary.' . $key is covered above.
$used = array_filter($used, static fn (string $id): bool => !str_ends_with($id, '.'));
foreach (array_unique($used) as $id) {
    if (!isset($english[$id])) {
        $failures[] = "en: $id is used but missing";
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
printf("OK: %d strings in en, de, fr, it; %d ids used by the module\n", count($english), count(array_unique($used)));
