<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Reporting;

use Supertext\NeosTranslation\Sdk\Http\HttpTransport;
use Supertext\NeosTranslation\Sdk\V1\ApiKeyCheck;
use Supertext\NeosTranslation\Sdk\V1\InstallationReporter;
use Supertext\NeosTranslation\Sdk\V1\PingResponse;
use Supertext\NeosTranslation\Sdk\V1\Protocol;
use Supertext\NeosTranslation\Sdk\V1\ReporterResult;
use Supertext\NeosTranslation\Sdk\V1\ReporterState;

/**
 * Version reports to the Supertext Commando Center, the automatic API key check and the
 * callback ping (Commando-Center-Supertext-Translation → docs/DEVELOPER.md → Report
 * protocol). The protocol is the SDK's InstallationReporter; this class only gives it what
 * Neos knows and keeps its state in a StateStorage. Plain PHP (no Flow), so it is unit-tested
 * on its own; Flow wires it up in ReportingService.
 *
 * Neos keeps the API key in Settings.yaml or the environment, so there is no "settings
 * saved": the key is checked when it changed (fingerprint), by supertext:check and after a
 * 401/403 from Supertext. Nothing here ever throws.
 */
final class Reporting
{
    /** The GitHub repository name; the center reads the latest release from it. */
    public const PLUGIN_NAME = 'Neos-Supertext-Translation';
    public const SYSTEM_NAME = 'Neos';
    /** Path of the ping route below the site URL (PingMiddleware). */
    public const PING_PATH = 'supertext/ping';

    public const ACTION_ON_REQUEST = 'onRequest';
    public const ACTION_AUTHENTICATION_FAILED = 'authenticationFailed';
    public const ACTION_TEST_CONNECTION = 'testConnection';

    private const STATE_KEY = 'reporter';
    private const SITE_URL_KEY = 'siteUrl';

    /**
     * @param bool                     $enabled       InstallationReporter::reportsEnabled() of the setting
     * @param string                   $pluginVersion from Composer (PackageVersion)
     * @param string|null              $systemVersion the installed neos/neos version
     * @param (callable(): float)|null $now           for tests
     * @param (callable(float): void)|null $sleep     for tests (the key check's 429 retries)
     */
    public function __construct(
        private readonly StateStorage $storage,
        public readonly bool $enabled,
        private readonly string $pluginVersion,
        private readonly ?string $systemVersion,
        private readonly string $apiBaseUrl = Protocol::LIVE_BASE_URL,
        private readonly ?HttpTransport $transport = null,
        private readonly ?string $reportUrl = null,
        private $now = null,
        private $sleep = null,
        private readonly ?\Closure $onError = null,
    ) {
    }

    /** An absolute http(s) URL reduced to scheme, host, port and path, without trailing slash; else null. */
    public static function normalizeSiteUrl(?string $url): ?string
    {
        $url = trim((string)$url);
        if (preg_match('#^https?://[^/\s?\#]+#i', $url) !== 1) {
            return null;
        }
        $url = (string)preg_replace('/[?#].*$/s', '', $url);
        return rtrim($url, '/');
    }

    public static function callbackUrl(string $siteUrl): string
    {
        return rtrim($siteUrl, '/') . '/' . self::PING_PATH;
    }

    /** Whether a request path is the ping route (also below a base path, e.g. /cms/supertext/ping). */
    public static function isPingPath(string $path): bool
    {
        $path = rtrim($path, '/');
        return $path === '/' . self::PING_PATH || str_ends_with($path, '/' . self::PING_PATH);
    }

    /** The ping route's answer (status, headers and body exactly as the SDK returns them). */
    public static function ping(mixed $challenge, bool $enabled): PingResponse
    {
        return InstallationReporter::pingResponse(is_string($challenge) ? $challenge : null, self::PLUGIN_NAME, $enabled);
    }

    /** Remembers the site URL seen on a back-end request, for CLI runs that have no request. */
    public function rememberSiteUrl(?string $url): void
    {
        $url = self::normalizeSiteUrl($url);
        if ($url === null) {
            return;
        }
        try {
            if ($this->storage->get(self::SITE_URL_KEY) !== $url) {
                $this->storage->set(self::SITE_URL_KEY, $url);
            }
        } catch (\Throwable $e) {
            $this->failed('storing the site URL', $e);
        }
    }

    /** The configured site URL, else the one remembered from the last back-end request, else null. */
    public function siteUrl(?string $configured = null): ?string
    {
        $url = self::normalizeSiteUrl($configured);
        if ($url !== null) {
            return $url;
        }
        try {
            return self::normalizeSiteUrl($this->storage->get(self::SITE_URL_KEY));
        } catch (\Throwable $e) {
            $this->failed('reading the site URL', $e);
            return null;
        }
    }

    /** Without a site URL, reports are impossible: the reporter only checks the key. */
    public function reporter(?string $siteUrl, ?bool $enabled = null): InstallationReporter
    {
        $siteUrl = self::normalizeSiteUrl($siteUrl);
        return new InstallationReporter(
            enabled: ($enabled ?? $this->enabled) && $siteUrl !== null,
            siteUrl: $siteUrl ?? '',
            pluginName: self::PLUGIN_NAME,
            pluginVersion: $this->pluginVersion,
            systemName: self::SYSTEM_NAME,
            systemVersion: $this->systemVersion,
            callbackUrl: $siteUrl !== null && $this->enabled ? self::callbackUrl($siteUrl) : null,
            reportUrl: $this->reportUrl,
            transport: $this->transport,
            apiBaseUrl: $this->apiBaseUrl,
            sleep: $this->sleep,
            now: $this->now,
        );
    }

    /** Runs one reporter action with the stored state and stores the result. Never throws. */
    public function run(string $action, #[\SensitiveParameter] string $apiKey, ?string $siteUrl, int $httpStatus = 401): ?ReporterResult
    {
        try {
            $reporter = $this->reporter($siteUrl);
            $state = $this->loadState();
            $result = match ($action) {
                self::ACTION_AUTHENTICATION_FAILED => $reporter->authenticationFailed($state, $apiKey, $httpStatus),
                self::ACTION_TEST_CONNECTION => $reporter->testConnection($state, $apiKey),
                default => $reporter->onRequest($state, $apiKey),
            };
            $this->saveState($result->state);
            return $result;
        } catch (\Throwable $e) {
            $this->failed('version report', $e);
            return null;
        }
    }

    /**
     * For supertext:check: checks the key now, then sends a `check` report (when reports are
     * on and the site URL is known). Never throws.
     *
     * @return array{check: ApiKeyCheck, report: ReporterResult|null}
     */
    public function checkAndReport(#[\SensitiveParameter] string $apiKey, ?string $siteUrl): array
    {
        try {
            $state = $this->loadState();
            // The check alone: the `check` report below carries its result.
            $checked = $this->reporter($siteUrl, false)->testConnection($state, $apiKey);
            $check = $checked->check ?? new ApiKeyCheck(ApiKeyCheck::ERROR, ApiKeyCheck::timestamp(microtime(true)));
            $report = null;
            $state = $checked->state;
            if ($this->enabled && self::normalizeSiteUrl($siteUrl) !== null) {
                $report = $this->reporter($siteUrl)->reportCheck($state);
                $state = $report->state;
            }
            $this->saveState($state);
            return ['check' => $check, 'report' => $report];
        } catch (\Throwable $e) {
            $this->failed('key check', $e);
            return ['check' => new ApiKeyCheck(ApiKeyCheck::ERROR, ApiKeyCheck::timestamp(microtime(true))), 'report' => null];
        }
    }

    /** The latest stored key check, or null. */
    public function lastCheck(): ?ApiKeyCheck
    {
        try {
            return $this->loadState()->keyCheck;
        } catch (\Throwable) {
            return null;
        }
    }

    private function loadState(): ReporterState
    {
        try {
            return ReporterState::from($this->storage->get(self::STATE_KEY));
        } catch (\Throwable $e) {
            $this->failed('reading the reporter state', $e);
            return ReporterState::empty();
        }
    }

    private function saveState(ReporterState $state): void
    {
        try {
            $this->storage->set(self::STATE_KEY, (string)json_encode($state, JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            $this->failed('storing the reporter state', $e);
        }
    }

    private function failed(string $what, \Throwable $e): void
    {
        if ($this->onError !== null) {
            try {
                ($this->onError)(sprintf('Supertext: %s skipped: %s', $what, $e->getMessage()));
            } catch (\Throwable) {
            }
        }
    }
}
