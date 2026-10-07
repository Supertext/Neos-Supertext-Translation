<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Configuration;

use Composer\InstalledVersions;

/**
 * The installed version of this package, read from Composer at runtime
 * (e.g. "0.1.0" or "dev-main"). No hardcoded copy.
 */
final class PackageVersion
{
    public const PACKAGE_NAME = 'supertext/neos-translation';

    public static function get(string $packageName = self::PACKAGE_NAME): string
    {
        if (!class_exists(InstalledVersions::class) || !InstalledVersions::isInstalled($packageName)) {
            return 'unknown';
        }
        return InstalledVersions::getPrettyVersion($packageName) ?? 'unknown';
    }

    public static function label(): string
    {
        return 'Supertext Translation for Neos ' . self::get();
    }
}
