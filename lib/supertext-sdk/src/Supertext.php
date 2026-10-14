<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Sdk;

/**
 * Version-neutral facts about this SDK. Everything that talks to the API lives in a versioned namespace:
 * Supertext\NeosTranslation\Sdk\V1 (a later API version gets Supertext\NeosTranslation\Sdk\V2 next to it).
 */
final class Supertext
{
    /** This SDK's version (sent in version reports). The same number in every Supertext SDK. */
    public const SDK_VERSION = '0.2.1';

    private function __construct()
    {
    }
}
