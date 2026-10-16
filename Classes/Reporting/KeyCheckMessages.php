<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Reporting;

use Supertext\NeosTranslation\PluginHealth\KeyCheck;
use Supertext\NeosTranslation\Service\ErrorMessages;

/**
 * The Commando Center's key-check messages (English: the package's output is CLI and log
 * only), with the bare Supertext URLs for `invalid` and `missing`.
 */
final class KeyCheckMessages
{
    public static function for(KeyCheck $check): string
    {
        return match ($check->status) {
            KeyCheck::OK => 'API key checked: the connection to Supertext works.',
            KeyCheck::INVALID => 'Supertext rejected the API key. Check that you copied the whole key, or generate a new one at supertext.com → Integrations → API (requires the Admin role): '
                . ErrorMessages::API_KEY_URL . '. No Supertext account yet? Create one at supertext.com: ' . ErrorMessages::ACCOUNT_URL,
            KeyCheck::MISSING => 'No API key is set yet. No Supertext account yet? Create one at supertext.com: ' . ErrorMessages::ACCOUNT_URL
                . '. Generate your API key at supertext.com → Integrations → API (requires the Admin role): ' . ErrorMessages::API_KEY_URL,
            KeyCheck::UNREACHABLE => 'The API key couldn\'t be checked because Supertext can\'t be reached. Check again later (./flow supertext:check).',
            default => sprintf(
                'The API key couldn\'t be checked: Supertext answered with an error (%s). Check again later (./flow supertext:check).',
                $check->httpStatus !== null ? (string)$check->httpStatus : ($check->errorCode ?? 'unknown'),
            ),
        };
    }
}
