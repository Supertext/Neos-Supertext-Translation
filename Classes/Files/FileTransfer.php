<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Files;

use Supertext\NeosTranslation\Sdk\Files\FileJob;
use Supertext\NeosTranslation\Sdk\Files\FileSegment;
use Supertext\NeosTranslation\Sdk\Files\ImportedSegment;
use Supertext\NeosTranslation\Sdk\Files\TranslationFileError;
use Supertext\NeosTranslation\Sdk\Files\TranslationFiles;
use Supertext\NeosTranslation\Sdk\V1\Languages;

/**
 * supertext:export / supertext:import: a node's segments as an XLIFF 1.2 or HTML file (the
 * SDK's TranslationFiles), and a translated file written back like an API translation.
 * Plain PHP (no Flow), unit-tested on its own: TranslationFileService passes in the segments
 * (SegmentCollector, the same as for API translations) and the write-back as closures.
 *
 * Reference of a job: `node:<node aggregate id>:<source dimension value>:<target dimension value>`,
 * e.g. `node:3c9a4f6d-2b1e-4c55-9b0e-1f2a3b4c5d6e:en_US:fr`.
 */
final class FileTransfer
{
    /** The `plugin` of every job: this repository's name. */
    public const PLUGIN = 'Neos-Supertext-Translation';

    /** Files larger than this are refused before they are read. */
    public const MAX_FILE_BYTES = 10 * 1024 * 1024;

    public const XLIFF = 'xliff';
    public const HTML = 'html';

    /**
     * @param array<string, string> $mapping language dimension value → Supertext code (languages.<value>.code)
     */
    public function __construct(private readonly array $mapping = [])
    {
    }

    public static function reference(string $nodeId, string $source, string $target): string
    {
        return 'node:' . $nodeId . ':' . $source . ':' . $target;
    }

    /** @return array{node: string, source: string, target: string}|null */
    public static function parseReference(string $reference): ?array
    {
        if (!preg_match('/^node:([A-Za-z0-9-]{1,64}):([A-Za-z0-9_-]{1,64}):([A-Za-z0-9_-]{1,64})$/', $reference, $m)) {
            return null;
        }
        return ['node' => $m[1], 'source' => $m[2], 'target' => $m[3]];
    }

    /**
     * `neos-<node id>-fr.xlf`, `neos-<node id>.xlf` for several languages, `neos-<node id>-fr.html`.
     *
     * @param list<string> $targets
     */
    public static function fileName(string $nodeId, array $targets, string $format): string
    {
        $suffix = count($targets) === 1 ? '-' . preg_replace('/[^A-Za-z0-9_-]/', '', $targets[0]) : '';
        return 'neos-' . preg_replace('/[^A-Za-z0-9-]/', '', $nodeId) . $suffix . ($format === self::HTML ? '.html' : '.xlf');
    }

    /** The Supertext source code of a dimension value; the value itself (with `-`) where Supertext has none. */
    public function sourceCode(string $value): string
    {
        try {
            return Languages::source($value, $this->mapping);
        } catch (\Throwable) {
            return str_replace('_', '-', $value);
        }
    }

    /** The Supertext target code of a dimension value; the value itself (with `-`) where it can't be resolved. */
    public function targetCode(string $value): string
    {
        try {
            return Languages::target($value, $this->mapping);
        } catch (\Throwable) {
            return str_replace('_', '-', $value);
        }
    }

    /**
     * Only the segments the API translation would send (blank ones are left out the same way).
     *
     * @param array<string, array{0: string, 1: bool}> $fields key => [text, isHtml]
     *
     * @return list<FileSegment>
     */
    public static function segments(array $fields): array
    {
        $segments = [];
        foreach ($fields as $key => [$text, $isHtml]) {
            if (trim(strip_tags($text)) !== '') {
                $segments[] = new FileSegment((string)$key, $text, $isHtml);
            }
        }
        return $segments;
    }

    /**
     * @param array<string, array{0: string, 1: bool}> $fields  the node's segments (SegmentCollector)
     * @param list<string>                             $targets target dimension values; exactly one for HTML
     *
     * @return array{name: string, type: string, body: string}
     *
     * @throws FileTransferException nothing_to_translate, invalid_request
     */
    public function export(string $nodeId, string $source, array $fields, array $targets, string $format): array
    {
        $targets = array_values(array_unique($targets));
        if (!in_array($format, [self::XLIFF, self::HTML], true) || $targets === [] || ($format === self::HTML && count($targets) !== 1)) {
            throw new FileTransferException('invalid_request');
        }
        $segments = self::segments($fields);
        if ($segments === []) {
            throw new FileTransferException('nothing_to_translate');
        }

        $jobs = [];
        foreach ($targets as $target) {
            $jobs[] = new FileJob(self::PLUGIN, self::reference($nodeId, $source, $target), $this->sourceCode($source), $this->targetCode($target), $segments);
        }
        try {
            $body = $format === self::HTML ? TranslationFiles::exportHtml($jobs[0]) : TranslationFiles::exportXliff($jobs);
        } catch (TranslationFileError $e) {
            throw new FileTransferException($e->errorCode === TranslationFileError::INVALID_JOB ? 'nothing_to_translate' : $e->errorCode);
        }

        return [
            'name' => self::fileName($nodeId, $targets, $format),
            'type' => $format === self::HTML ? 'text/html; charset=utf-8' : 'application/x-xliff+xml',
            'body' => $body,
        ];
    }

    /** @throws FileTransferException too_large */
    public static function assertFileSize(int $bytes): void
    {
        if ($bytes > self::MAX_FILE_BYTES) {
            throw new FileTransferException('too_large');
        }
    }

    /**
     * Reads a translated file and writes every job back.
     *
     * @param \Closure(array{node: string, source: string, target: string}): array<string, array{0: string, 1: bool}> $current
     *        the node's current segments; throws FileTransferException not_found / no_language
     * @param \Closure(array{node: string, source: string, target: string}, array<string, string>): array{nodes: int, fields: int} $write
     *        writes the translations (key => text) into the target language like an API translation
     *
     * @return list<array{reference: string, plugin: string, node: string, source: string, target: string, counts: array{imported: int, changed: int, untranslated: int, invalid: int, missing: int}, written?: array{nodes: int, fields: int}, error?: \Throwable}>
     *
     * @throws FileTransferException invalid_file, missing_metadata, too_large (the whole file)
     */
    public function import(string $content, bool $includeChanged, \Closure $current, \Closure $write): array
    {
        self::assertFileSize(strlen($content));
        try {
            $files = TranslationFiles::read($content);
        } catch (TranslationFileError $e) {
            throw new FileTransferException($e->errorCode);
        }

        $results = [];
        foreach ($files as $file) {
            $ref = self::parseReference($file->reference);
            $result = [
                'reference' => $file->reference,
                'plugin' => $file->plugin,
                'node' => $ref['node'] ?? '',
                'source' => $ref['source'] ?? '',
                'target' => $ref['target'] ?? '',
                'counts' => ['imported' => 0, 'changed' => 0, 'untranslated' => 0, 'invalid' => 0, 'missing' => 0],
            ];
            try {
                if ($file->plugin !== self::PLUGIN) {
                    throw new FileTransferException('other_system', [$file->plugin]);
                }
                if ($ref === null) {
                    throw new FileTransferException('not_found');
                }
                $translations = [];
                foreach (TranslationFiles::importTranslations($file, self::segments($current($ref))) as $segment) {
                    $use = $segment->status === ImportedSegment::OK
                        || ($segment->status === ImportedSegment::SOURCE_CHANGED && $includeChanged);
                    if ($use && $segment->text !== null && trim(strip_tags($segment->text)) !== '') {
                        $translations[$segment->key] = $segment->text;
                        continue;
                    }
                    $bucket = match ($segment->status) {
                        ImportedSegment::SOURCE_CHANGED => 'changed',
                        ImportedSegment::INVALID_MARKUP => 'invalid',
                        ImportedSegment::MISSING => 'missing',
                        default => 'untranslated',
                    };
                    $result['counts'][$bucket]++;
                }
                if ($translations !== []) {
                    $result['written'] = $write($ref, $translations);
                    $result['counts']['imported'] = count($translations);
                }
            } catch (\Throwable $e) {
                $result['error'] = $e;
            }
            $results[] = $result;
        }
        return $results;
    }

    /**
     * The summary lines of one job (English, as all of the package's CLI output).
     *
     * @param array{counts: array{imported: int, changed: int, untranslated: int, invalid: int, missing: int}} $result
     *
     * @return list<string>
     */
    public static function summary(array $result): array
    {
        $c = $result['counts'];
        $lines = [sprintf('Imported: %d', $c['imported'])];
        $optional = [
            'changed' => 'Skipped, source changed since the export: %d',
            'untranslated' => 'Not translated in the file: %d',
            'invalid' => 'Formatting changed, not imported: %d',
            'missing' => 'Not in the file: %d',
        ];
        foreach ($optional as $key => $format) {
            if ($c[$key] > 0) {
                $lines[] = sprintf($format, $c[$key]);
            }
        }
        return $lines;
    }
}
