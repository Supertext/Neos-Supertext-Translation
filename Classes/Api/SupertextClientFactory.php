<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Api;

use Neos\Flow\Annotations as Flow;
use Supertext\NeosTranslation\Configuration\PackageVersion;
use Supertext\NeosTranslation\Configuration\Settings;
use Supertext\Sdk\Client;

/**
 * Creates the official Supertext SDK client (supertext/supertext-sdk) from the
 * package settings. The SDK handles the protocol: authentication header, multipart
 * upload, 429 retries, polling, download, delete, HTML packing and language codes.
 */
#[Flow\Scope('singleton')]
class SupertextClientFactory
{
    #[Flow\Inject]
    protected Settings $settings;

    public function hasApiKey(): bool
    {
        return $this->settings->getApiKey() !== '';
    }

    public function create(): Client
    {
        $timeout = $this->settings->getPollTimeout();
        if (function_exists('set_time_limit')) {
            @set_time_limit($timeout + 60);
        }
        return new Client(
            $this->settings->getApiKey(),
            baseUrl: $this->settings->getBaseUrl(),
            pollInterval: (float)$this->settings->getPollInterval(),
            timeout: (float)$timeout,
            userAgent: 'Neos-Supertext-Translation/' . PackageVersion::get(),
            transport: new GuzzleHttpTransport(),
        );
    }
}
