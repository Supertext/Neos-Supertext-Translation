<?php

// Standalone test for the version helper (no Neos needed):
//   php Tests/PackageVersionTest.php
declare(strict_types=1);

namespace Composer {
    // Minimal stand-in for Composer's runtime API, defined only after the first check.
    if (getenv('PACKAGE_VERSION_STUB') === '1' && !class_exists(InstalledVersions::class, false)) {
        class InstalledVersions
        {
            public static function isInstalled(string $name): bool
            {
                return $name === 'supertext/neos-translation';
            }

            public static function getPrettyVersion(string $name): ?string
            {
                return '0.1.0';
            }
        }
    }
}

namespace {
    require __DIR__ . '/../Classes/Configuration/PackageVersion.php';

    use Supertext\NeosTranslation\Configuration\PackageVersion;

    function check(string $expected, string $actual, string $what): void
    {
        if ($expected !== $actual) {
            fwrite(STDERR, "FAIL $what: expected '$expected', got '$actual'\n");
            exit(1);
        }
        echo "ok   $what\n";
    }

    if (getenv('PACKAGE_VERSION_STUB') === '1') {
        check('0.1.0', PackageVersion::get(), 'version from Composer');
        check('Supertext Translation for Neos 0.1.0', PackageVersion::label(), 'label');
        check('unknown', PackageVersion::get('other/package'), 'package not installed');
    } else {
        check('unknown', PackageVersion::get(), 'no Composer runtime');
        // Same checks again with the Composer stub loaded.
        putenv('PACKAGE_VERSION_STUB=1');
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__), $code);
        exit($code);
    }
}
