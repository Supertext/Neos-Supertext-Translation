<?php

// supertext:export / supertext:import: the glue around the SDK's TranslationFiles
// (Classes/Files), with the bundled SDK and fake loaders/writers. No Neos needed:
//   php Tests/FileTransferTest.php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Supertext\\NeosTranslation\\Sdk\\' => __DIR__ . '/../lib/supertext-sdk/src/',
        'Supertext\\NeosTranslation\\' => __DIR__ . '/../Classes/',
    ];
    foreach ($prefixes as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

use Supertext\NeosTranslation\Files\FileTransfer;
use Supertext\NeosTranslation\Files\FileTransferException;
use Supertext\NeosTranslation\Sdk\Files\TranslationFiles;

$failures = [];
$check = static function (string $name, mixed $actual, mixed $expected) use (&$failures): void {
    if ($actual !== $expected) {
        $failures[] = sprintf("%s:\n  expected: %s\n  actual:   %s", $name, json_encode($expected), json_encode($actual));
    }
};
$reason = static function (callable $fn): ?string {
    try {
        $fn();
    } catch (FileTransferException $e) {
        return $e->reason;
    }
    return null;
};

const NODE = '3c9a4f6d-2b1e-4c55-9b0e-1f2a3b4c5d6e';
// What SegmentCollector collects for a page with one text element (keys <node id>.<property>).
$fields = [
    NODE . '.title' => ['Our team', false],
    'b7d1-text.text' => ['<p>We ship <strong>Swiss chocolate</strong>.</p>', true],
    'b7d1-text.headline' => ['  ', false],
];
// Fills <target> elements the way a CAT tool does, for the given plain sources.
$translate = static function (string $xliff, array $translations) use (&$failures): string {
    foreach ($translations as $source => $target) {
        $xliff = (string)preg_replace('#(<source>' . preg_quote($source, '#') . '</source>)#', '$1<target state="translated">' . $target . '</target>', $xliff, 1, $count);
        if ($count !== 1) {
            $failures[] = 'source not found in the XLIFF: ' . $source;
        }
    }
    return $xliff;
};
$import = static function (string $content, bool $includeChanged, array $current, ?array &$written = null): array {
    $written = [];
    return (new FileTransfer())->import(
        $content,
        $includeChanged,
        static fn (array $ref): array => $current,
        static function (array $ref, array $translations) use (&$written): array {
            $written[] = [$ref, $translations];
            return ['nodes' => 1, 'fields' => count($translations)];
        },
    );
};

// 1. Reference and file names.
$check('reference', FileTransfer::reference(NODE, 'en_US', 'fr'), 'node:' . NODE . ':en_US:fr');
$check('reference round trip', FileTransfer::parseReference(FileTransfer::reference(NODE, 'en_US', 'de_CH')), ['node' => NODE, 'source' => 'en_US', 'target' => 'de_CH']);
foreach (['', 'node:' . NODE . ':en', 'page:12:en:fr', 'node:a b:en:fr', 'node:' . NODE . ':en:fr:x'] as $bad) {
    $check("bad reference '$bad'", FileTransfer::parseReference($bad), null);
}
$check('file name one language', FileTransfer::fileName(NODE, ['fr'], FileTransfer::XLIFF), 'neos-' . NODE . '-fr.xlf');
$check('file name several languages', FileTransfer::fileName(NODE, ['fr', 'it'], FileTransfer::XLIFF), 'neos-' . NODE . '.xlf');
$check('file name html', FileTransfer::fileName(NODE, ['fr'], FileTransfer::HTML), 'neos-' . NODE . '-fr.html');

// 2. Export: the collector's keys, the mapping, unmapped languages as they are.
$file = (new FileTransfer(['fr' => 'fr-CH']))->export(NODE, 'en_US', $fields, ['fr', 'en_IE'], FileTransfer::XLIFF);
$check('xliff type', $file['type'], 'application/x-xliff+xml');
$jobs = TranslationFiles::read($file['body']);
$check('one job per language', count($jobs), 2);
$check('plugin', $jobs[0]->plugin, 'Neos-Supertext-Translation');
$check('reference in file', $jobs[0]->reference, 'node:' . NODE . ':en_US:fr');
$check('source code', $jobs[0]->sourceLanguage, 'en');
$check('mapping wins', $jobs[0]->targetLanguage, 'fr-CH');
$check('unmapped language as it is', $jobs[1]->targetLanguage, 'en-IE');
$check('keys of the collector, blank left out', array_map(static fn ($u) => $u->key, $jobs[0]->units), [NODE . '.title', 'b7d1-text.text']);
$html = (new FileTransfer())->export(NODE, 'en_US', $fields, ['de'], FileTransfer::HTML);
$check('html type', $html['type'], 'text/html; charset=utf-8');
$check('html target default', TranslationFiles::read($html['body'])[0]->targetLanguage, 'de-DE');
$check('html needs exactly one language', $reason(static fn () => (new FileTransfer())->export(NODE, 'en_US', $fields, ['de', 'fr'], FileTransfer::HTML)), 'invalid_request');
$check('unknown format', $reason(static fn () => (new FileTransfer())->export(NODE, 'en_US', $fields, ['de'], 'docx')), 'invalid_request');
$check('nothing to translate', $reason(static fn () => (new FileTransfer())->export(NODE, 'en_US', ['x.title' => [' ', false]], ['de'], FileTransfer::XLIFF)), 'nothing_to_translate');

// 3. Import: ok written; untranslated not.
$xliff = (new FileTransfer())->export(NODE, 'en_US', $fields, ['fr'], FileTransfer::XLIFF)['body'];
$translated = $translate($xliff, ['Our team' => 'Notre équipe']);
$results = $import($translated, false, $fields, $written);
$check('ok written', $written, [[['node' => NODE, 'source' => 'en_US', 'target' => 'fr'], [NODE . '.title' => 'Notre équipe']]]);
$check('counts', $results[0]['counts'], ['imported' => 1, 'changed' => 0, 'untranslated' => 1, 'invalid' => 0, 'missing' => 0]);
$check('summary', FileTransfer::summary($results[0]), ['Imported: 1', 'Not translated in the file: 1']);
$results = $import($xliff, false, $fields, $written);
$check('nothing translated: nothing written', $written, []);
$check('nothing translated: counts', $results[0]['counts']['untranslated'], 2);

// 4. source_changed only with --include-changed.
$changed = [NODE . '.title' => ['Our new team', false]] + $fields;
$results = $import($translated, false, $changed, $written);
$check('changed skipped', $written, []);
$check('changed counted', $results[0]['counts']['changed'], 1);
$import($translated, true, $changed, $written);
$check('changed with option', $written[0][1] ?? null, [NODE . '.title' => 'Notre équipe']);

// 5. invalid_markup and missing are not written.
$page = (new FileTransfer())->export(NODE, 'en_US', $fields, ['fr'], FileTransfer::HTML)['body'];
$page = str_replace(['We ship <strong>Swiss chocolate</strong>.', '>Our team<'], ['Nous livrons <script>x</script>.', '>Notre équipe<'], $page);
$results = $import($page, false, $fields + ['new-node.text' => ['Added later', false]], $written);
$check('only the valid segment written', array_keys($written[0][1] ?? []), [NODE . '.title']);
$check('invalid counted', $results[0]['counts']['invalid'], 1);
$check('missing counted', $results[0]['counts']['missing'], 1);

// 6. Glue errors per job.
$results = $import(str_replace('Neos-Supertext-Translation', 'Contao-Supertext-Translation', $xliff), false, $fields, $written);
$check('other plugin', [$results[0]['error']->reason ?? null, $results[0]['error']->getMessage(), $written], ['other_system', 'This file belongs to another system (Contao-Supertext-Translation).', []]);
$results = $import(str_replace('node:' . NODE . ':en_US:fr', 'page:12', $xliff), false, $fields, $written);
$check('unknown reference', [$results[0]['error']->reason ?? null, $written], ['not_found', []]);
$results = (new FileTransfer())->import($translated, false, static fn (array $ref) => throw new FileTransferException('no_language', [$ref['target']]), static fn () => throw new LogicException('written'));
$check('missing language', [$results[0]['error']->reason ?? null, $results[0]['error']->getMessage()], ['no_language', 'The site has no language fr.']);
$results = (new FileTransfer())->import($translated, false, static fn () => throw new FileTransferException('not_found'), static fn () => throw new LogicException('written'));
$check('missing node', $results[0]['error']->reason ?? null, 'not_found');

// 7. File errors and the size limit.
$check('not a file', $reason(static fn () => (new FileTransfer())->import('hello', false, static fn () => [], static fn () => [])), 'invalid_file');
$check('dtd refused', $reason(static fn () => (new FileTransfer())->import('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "b">]><xliff/>', false, static fn () => [], static fn () => [])), 'invalid_file');
$check('no metadata', $reason(static fn () => (new FileTransfer())->import('<html><body><p>hi</p></body></html>', false, static fn () => [], static fn () => [])), 'missing_metadata');
$check('10 MB allowed', $reason(static fn () => FileTransfer::assertFileSize(FileTransfer::MAX_FILE_BYTES)), null);
$check('larger refused', $reason(static fn () => FileTransfer::assertFileSize(FileTransfer::MAX_FILE_BYTES + 1)), 'too_large');
$check('larger content refused', $reason(static fn () => (new FileTransfer())->import(str_repeat(' ', FileTransfer::MAX_FILE_BYTES + 1), false, static fn () => [], static fn () => [])), 'too_large');

foreach ($failures as $failure) {
    fwrite(STDERR, $failure . "\n");
}
echo $failures === [] ? "OK: translation files export and import\n" : 'FAILED: ' . count($failures) . "\n";
exit($failures === [] ? 0 : 1);
