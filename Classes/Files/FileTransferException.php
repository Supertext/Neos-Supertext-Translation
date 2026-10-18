<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Files;

/**
 * An export or import of a translation file that can't go ahead. `$reason` is one of
 * nothing_to_translate, invalid_request, invalid_file, missing_metadata, too_large,
 * other_system (args: the file's plugin), not_found, no_language (args: the dimension value);
 * the message is the English text the CLI prints. The back-end module shows `error.<reason>`
 * from Resources/Private/Translations/<language>/Main.xlf instead (Tests/InterfaceStringsTest.php
 * checks that every reason has one).
 */
final class FileTransferException extends \RuntimeException
{
    private const MESSAGES = [
        'nothing_to_translate' => 'Nothing to translate.',
        'invalid_request' => 'Choose --format=xliff or --format=html and at least one language (exactly one for HTML).',
        'invalid_file' => 'This file isn\'t a valid XLIFF or HTML translation file.',
        'missing_metadata' => 'This file wasn\'t exported by the Supertext package, or its header was removed.',
        'too_large' => 'The file is too large (at most 10 MB).',
        'other_system' => 'This file belongs to another system (%s).',
        'not_found' => 'The node this file was exported from no longer exists.',
        'no_language' => 'The site has no language %s.',
    ];

    /** @param list<string> $args */
    public function __construct(public readonly string $reason, public readonly array $args = [])
    {
        $format = self::MESSAGES[$reason] ?? $reason;
        parent::__construct(count($args) === substr_count($format, '%s') ? vsprintf($format, $args) : $format);
    }
}
