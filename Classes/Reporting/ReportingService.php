<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Reporting;

use Composer\InstalledVersions;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Helper\RequestInformationHelper;
use Neos\Neos\Domain\Repository\SiteRepository;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Supertext\NeosTranslation\Api\GuzzleHttpTransport;
use Supertext\NeosTranslation\Configuration\PackageVersion;
use Supertext\NeosTranslation\Configuration\Settings;
use Supertext\NeosTranslation\Sdk\V1\ApiKeyCheck;
use Supertext\NeosTranslation\Sdk\V1\InstallationReporter;
use Supertext\NeosTranslation\Sdk\V1\PingResponse;
use Supertext\NeosTranslation\Sdk\V1\ReporterResult;

/**
 * Flow glue for Reporting: settings, the site URL Neos knows, the Neos and package
 * versions, Guzzle and the persistent state cache. Every method is safe to call from a
 * request or a command: nothing here throws.
 */
#[Flow\Scope('singleton')]
class ReportingService
{
    #[Flow\Inject]
    protected Settings $settings;

    #[Flow\Inject]
    protected CacheStateStorage $storage;

    #[Flow\Inject]
    protected SiteRepository $siteRepository;

    #[Flow\Inject]
    protected LoggerInterface $logger;

    private ?Reporting $reporting = null;

    public function reporting(): Reporting
    {
        return $this->reporting ??= new Reporting(
            $this->storage,
            $this->isEnabled(),
            PackageVersion::get(),
            self::neosVersion(),
            apiBaseUrl: $this->settings->getBaseUrl(),
            transport: new GuzzleHttpTransport(),
            onError: function (string $message): void {
                $this->logger->debug($message);
            },
        );
    }

    /** The setting combined with SUPERTEXT_VERSION_REPORTS (default on). */
    public function isEnabled(): bool
    {
        return InstallationReporter::reportsEnabled($this->settings->getVersionReports());
    }

    public function ping(mixed $challenge): PingResponse
    {
        return Reporting::ping($challenge, $this->isEnabled());
    }

    /**
     * On back-end requests and commands that run the package's code: checks a changed key and
     * reports `installed`, `upgraded` or `api_key_check` when due (usually nothing to do).
     */
    public function onRequest(?ServerRequestInterface $request = null): ?ReporterResult
    {
        try {
            if ($request !== null) {
                $this->reporting()->rememberSiteUrl((string)RequestInformationHelper::generateBaseUri($request));
            }
            return $this->reporting()->run(Reporting::ACTION_ON_REQUEST, $this->settings->getApiKey(), $this->siteUrl());
        } catch (\Throwable $e) {
            $this->logger->debug('Supertext: version report skipped: ' . $e->getMessage());
            return null;
        }
    }

    /** A translation was rejected with 401/403. */
    public function authenticationFailed(int $httpStatus): void
    {
        try {
            $this->reporting()->run(Reporting::ACTION_AUTHENTICATION_FAILED, $this->settings->getApiKey(), $this->siteUrl(), $httpStatus);
        } catch (\Throwable $e) {
            $this->logger->debug('Supertext: version report skipped: ' . $e->getMessage());
        }
    }

    /**
     * supertext:check: the key check now, then a `check` report.
     *
     * @return array{check: ApiKeyCheck, report: ReporterResult|null}
     */
    public function check(): array
    {
        return $this->reporting()->checkAndReport($this->settings->getApiKey(), $this->siteUrl());
    }

    /**
     * The site's base URL: Neos.Flow.http.baseUri, else the default site's primary domain,
     * else the URL of the last back-end request (remembered for commands), else null.
     */
    public function siteUrl(): ?string
    {
        return $this->reporting()->siteUrl($this->configuredSiteUrl());
    }

    public function callbackUrl(): ?string
    {
        $siteUrl = $this->siteUrl();
        return $siteUrl !== null ? Reporting::callbackUrl($siteUrl) : null;
    }

    private function configuredSiteUrl(): ?string
    {
        $baseUri = $this->settings->getFlowBaseUri();
        if (Reporting::normalizeSiteUrl($baseUri) !== null) {
            return $baseUri;
        }
        try {
            $domain = $this->siteRepository->findDefault()?->getPrimaryDomain();
            if ($domain !== null) {
                $url = (string)$domain;
                // A domain without scheme is served on whatever the request uses; HTTPS is what the public sees.
                return str_contains($url, '://') ? $url : 'https://' . $url;
            }
        } catch (\Throwable) {
            // no database yet, no site
        }
        return null;
    }

    public static function neosVersion(): ?string
    {
        try {
            if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('neos/neos')) {
                return InstalledVersions::getPrettyVersion('neos/neos');
            }
        } catch (\Throwable) {
        }
        return null;
    }
}
