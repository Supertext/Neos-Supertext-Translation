<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Service;

use Supertext\NeosTranslation\Sdk\V1\SupertextException;

/**
 * English messages for administrators (log, CLI output, X-Supertext-Error header). The
 * back-end module's own messages are translated in Resources/Private/Translations (see
 * docs/DEVELOPER.md → Interface strings); it falls back to these for other errors. Most
 * messages are the SDK's; the ones an administrator has to act on name the setting to
 * change and, for the API key, both Supertext links.
 */
final class ErrorMessages
{
    public const ACCOUNT_URL = 'https://www.supertext.com/person/en/account/signin';
    public const API_KEY_URL = 'https://www.supertext.com/en/integrations/api';

    public static function keyLinks(): string
    {
        return 'No Supertext account yet? Create one at supertext.com (' . self::ACCOUNT_URL . '). '
            . 'Generate your API key at supertext.com → Integrations → API (requires the Admin role): ' . self::API_KEY_URL;
    }

    /**
     * @param string|null $targetDimensionValue the dimension value translated into (names the setting for unsupported_feature)
     */
    public static function forException(\Throwable $error, ?string $targetDimensionValue = null): string
    {
        if (!$error instanceof SupertextException) {
            return $error->getMessage();
        }
        switch ($error->errorCode) {
            case SupertextException::UNSUPPORTED_LANGUAGE:
                $language = $error->targetLang ?? $error->sourceLang ?? '?';
                return sprintf(
                    'The language %s has no Supertext language yet. Ask your administrator to choose one under Administration → Supertext (or to set Supertext.NeosTranslation.languages.%s.code in Settings.yaml, e.g. \'de-CH\').',
                    $language,
                    $language,
                );
            case SupertextException::UNSUPPORTED_FEATURE:
                return sprintf(
                    'Supertext offers no formal or informal tone for this language. Set it to the default tone (Supertext.NeosTranslation.languages.%s.politeness: \'default\').',
                    $targetDimensionValue ?? '<language>',
                );
            case SupertextException::MISSING_API_KEY:
                return 'No Supertext API key is set (SUPERTEXT_API_KEY or Supertext.NeosTranslation.apiKey). ' . self::keyLinks();
            case SupertextException::AUTHENTICATION_FAILURE:
                return 'Supertext rejected the API key. Check that you copied the whole key, or generate a new one. ' . self::keyLinks();
            default:
                return $error->getMessage();
        }
    }
}
