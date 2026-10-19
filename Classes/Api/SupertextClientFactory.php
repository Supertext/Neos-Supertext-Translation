<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Api;

use Neos\Flow\Annotations as Flow;
use Supertext\NeosTranslation\Configuration\PackageVersion;
use Supertext\NeosTranslation\Configuration\Settings;
use Supertext\NeosTranslation\Sdk\Http\HttpTransport;
use Supertext\NeosTranslation\Sdk\V1\Client;

/**
 * Creates the Supertext SDK client (bundled in lib/supertext-sdk, namespace
 * Supertext\NeosTranslation\Sdk; see scripts/vendor-sdk.sh) from the
 * package settings. The SDK handles the protocol: authentication header, multipart
 * upload, 429 retries, polling, download, delete and HTML packing (namespace V1 for
 * API v1). Language codes are resolved before, by LanguageResolver.
 *
 * Translation review: for a target language with review on, create(true) returns a client with
 * the same key, timeouts, transport and User-Agent whose base URL is the review service
 * (`reviewUrl`, default REVIEW_URL). It speaks the same API v1 and forwards to the live
 * Supertext API, so nothing else changes; a job is collected by the client that submitted it
 * (translateTexts() does all four calls). The API key check and the version reports
 * (Reporting\Reporting) and translation files never use it.
 */
#[Flow\Scope('singleton')]
class SupertextClientFactory
{
    /**
     * Supertext's review service (the translation proxy of Supertext Localization Review): the
     * same API as Supertext, forwarding to the live API with the site's key and keeping every
     * translation, so reviewers can correct them on the website.
     */
    public const REVIEW_URL = 'https://localizationreview-production.up.railway.app/v1/';

    #[Flow\Inject]
    protected Settings $settings;

    public function hasApiKey(): bool
    {
        return $this->settings->getApiKey() !== '';
    }

    /**
     * The client for a translation; $review: the target language has *Translation review* on.
     */
    public function create(bool $review = false, ?HttpTransport $transport = null): Client
    {
        $timeout = $this->settings->getPollTimeout();
        if (function_exists('set_time_limit')) {
            @set_time_limit($timeout + 60);
        }
        return new Client(
            $this->settings->getApiKey(),
            baseUrl: self::baseUrl($review, $this->settings->getBaseUrl(), $this->settings->getReviewUrl()),
            pollInterval: (float)$this->settings->getPollInterval(),
            timeout: (float)$timeout,
            userAgent: 'Neos-Supertext-Translation/' . PackageVersion::get(),
            transport: $transport ?? new GuzzleHttpTransport(),
        );
    }

    /**
     * The review service for a review language, else the API: the API also when the review URL
     * is empty (review off) or the same as the API's.
     */
    public static function baseUrl(bool $review, string $apiUrl, string $reviewUrl): string
    {
        if (!$review || trim($reviewUrl) === '' || rtrim(trim($reviewUrl), '/') === rtrim($apiUrl, '/')) {
            return $apiUrl;
        }
        return trim($reviewUrl);
    }
}
