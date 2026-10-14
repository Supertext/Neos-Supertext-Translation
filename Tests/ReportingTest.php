<?php

// Version reports, API key check and ping (Classes/Reporting), with the bundled SDK and a fake
// HTTP transport: never the live API, never the Commando Center. No Neos needed:
//   php Tests/ReportingTest.php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Supertext\\NeosTranslation\\Sdk\\' => __DIR__ . '/../lib/supertext-sdk/src/',
        'Supertext\\NeosTranslation\\' => __DIR__ . '/../Classes/',
    ];
    foreach ($prefixes as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

use Supertext\NeosTranslation\Reporting\KeyCheckMessages;
use Supertext\NeosTranslation\Reporting\Reporting;
use Supertext\NeosTranslation\Reporting\StateStorage;
use Supertext\NeosTranslation\Sdk\Http\HttpRequest;
use Supertext\NeosTranslation\Sdk\Http\HttpResponse;
use Supertext\NeosTranslation\Sdk\Http\HttpTransport;
use Supertext\NeosTranslation\Sdk\V1\ApiKeyCheck;

final class MemoryStorage implements StateStorage
{
    /** @var array<string, string> */
    public array $values = [];
    public bool $broken = false;

    public function get(string $key): ?string
    {
        if ($this->broken) {
            throw new RuntimeException('cache backend down');
        }
        return $this->values[$key] ?? null;
    }

    public function set(string $key, string $value): void
    {
        if ($this->broken) {
            throw new RuntimeException('cache backend down');
        }
        $this->values[$key] = $value;
    }
}

final class FakeTransport implements HttpTransport
{
    /** @var list<HttpRequest> */
    public array $requests = [];
    public int $featuresStatus = 200;
    public int $reportStatus = 202;

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        if (str_ends_with($request->url, '/features')) {
            return new HttpResponse($this->featuresStatus, $this->featuresStatus < 300 ? '[]' : '{"error_code":"UNAUTHORIZED"}', ['Content-Type' => 'application/json']);
        }
        if ($request->url === REPORT_URL) {
            return new HttpResponse($this->reportStatus, '{"ok":true}', ['Content-Type' => 'application/json']);
        }
        return new HttpResponse(404, '', []);
    }

    /** @return list<string> "GET features" / "report <event>" */
    public function take(): array
    {
        $seen = [];
        foreach ($this->requests as $request) {
            $seen[] = $request->url === REPORT_URL
                ? 'report ' . (json_decode((string)$request->body, true)['event'] ?? '?')
                : $request->method . ' ' . basename($request->url);
        }
        $this->requests = [];
        return $seen;
    }

    /** @return array<string, mixed> */
    public function lastReport(): array
    {
        foreach (array_reverse($this->requests) as $request) {
            if ($request->url === REPORT_URL) {
                return json_decode((string)$request->body, true);
            }
        }
        return [];
    }
}

const REPORT_URL = 'http://127.0.0.1:9/report';
const SITE = 'https://www.example.ch';
const KEY = 'Supertext-Auth-Key secret-test-key';

$failures = [];
$check = static function (string $name, mixed $actual, mixed $expected) use (&$failures): void {
    if ($actual !== $expected) {
        $failures[] = sprintf("%s:\n  expected: %s\n  actual:   %s", $name, json_encode($expected), json_encode($actual));
    }
};
$clock = 1_791_878_400.0; // 2026-10-14T08:00:00Z
$make = static function (MemoryStorage $storage, FakeTransport $transport, bool $enabled = true, string $version = '0.3.0') use (&$clock): Reporting {
    return new Reporting($storage, $enabled, $version, '9.1.2', transport: $transport, reportUrl: REPORT_URL,
        now: static function () use (&$clock): float {
            return $clock;
        }, sleep: static function (): void {});
};

// 1. Ping endpoint: answers exactly as the SDK says; 404 while reports are off.
$ping = Reporting::ping('abcdefghijklmnop0123', true);
$check('ping ok status', $ping->status, 200);
$check('ping ok body', $ping->body, '{"ok":true,"plugin":"Neos-Supertext-Translation","challenge":"abcdefghijklmnop0123"}');
$check('ping headers', $ping->headers, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
$check('ping bad challenge', Reporting::ping('<script>', true)->status, 400);
$check('ping missing challenge', Reporting::ping(null, true)->status, 400);
$check('ping array challenge', Reporting::ping(['x'], true)->status, 400);
$check('ping off', Reporting::ping('abcdefghijklmnop0123', false)->status, 404);
$check('ping path', Reporting::isPingPath('/supertext/ping'), true);
$check('ping path below base path', Reporting::isPingPath('/cms/supertext/ping/'), true);
$check('not the ping path', Reporting::isPingPath('/en/supertext/pings'), false);

// 2. Site URL: configured wins, else the one remembered from a back-end request.
$check('normalize', Reporting::normalizeSiteUrl('https://www.example.ch/de/?x=1#top'), 'https://www.example.ch/de');
$check('normalize rejects relative', Reporting::normalizeSiteUrl('/neos'), null);
$storage = new MemoryStorage();
$transport = new FakeTransport();
$reporting = $make($storage, $transport);
$check('no site URL yet', $reporting->siteUrl(null), null);
$reporting->rememberSiteUrl('https://neos.example.ch/');
$check('remembered site URL', $reporting->siteUrl(null), 'https://neos.example.ch');
$check('configured site URL wins', $reporting->siteUrl('https://www.example.ch/'), 'https://www.example.ch');
$check('callback URL', Reporting::callbackUrl(SITE), 'https://www.example.ch/supertext/ping');

// 3. onRequest: first run checks the key and reports `installed`; then nothing until something changes.
$storage = new MemoryStorage();
$transport = new FakeTransport();
$result = $make($storage, $transport)->run(Reporting::ACTION_ON_REQUEST, KEY, SITE);
$report = $transport->lastReport();
$check('first request', $transport->take(), ['GET features', 'report installed']);
$check('first request sent', $result?->sent, true);
$check('report plugin', $report['plugin'] ?? null, ['name' => 'Neos-Supertext-Translation', 'version' => '0.3.0']);
$check('report system', $report['system'] ?? null, ['name' => 'Neos', 'version' => '9.1.2']);
$check('report runtime', ($report['runtime']['name'] ?? null), 'PHP');
$check('report site', $report['site_url'] ?? null, SITE);
$check('report callback', $report['callback'] ?? null, ['url' => 'https://www.example.ch/supertext/ping']);
$check('report key check', [$report['api_key_configured'] ?? null, $report['api_key_check']['status'] ?? null], [true, 'ok']);
$check('state stored as JSON', is_array(json_decode($storage->values['reporter'] ?? '', true)), true);
$make($storage, $transport)->run(Reporting::ACTION_ON_REQUEST, KEY, SITE);
$check('second request: nothing to do', $transport->take(), []);
$make($storage, $transport, version: '0.4.0')->run(Reporting::ACTION_ON_REQUEST, KEY, SITE);
$check('after an update', $transport->take(), ['report upgraded']);
$transport->featuresStatus = 401;
$make($storage, $transport, version: '0.4.0')->run(Reporting::ACTION_ON_REQUEST, 'another-key', SITE);
$check('changed key is checked', $transport->take(), ['GET features', 'report api_key_check']);
$check('changed key result', $make($storage, $transport)->lastCheck()?->status, ApiKeyCheck::INVALID);
$transport->featuresStatus = 200;

// 4. A translation got 401: invalid without a request, reported once.
$storage = new MemoryStorage();
$transport = new FakeTransport();
$make($storage, $transport)->run(Reporting::ACTION_ON_REQUEST, KEY, SITE);
$transport->take();
$make($storage, $transport)->run(Reporting::ACTION_AUTHENTICATION_FAILED, KEY, SITE, 403);
$check('401/403 reported', $transport->take(), ['report api_key_check']);
$check('401/403 status', $make($storage, $transport)->lastCheck()?->httpStatus, 403);
$make($storage, $transport)->run(Reporting::ACTION_AUTHENTICATION_FAILED, KEY, SITE, 403);
$check('401/403 only once', $transport->take(), []);

// 5. Reports off: the key is still checked, nothing is reported.
$storage = new MemoryStorage();
$transport = new FakeTransport();
$make($storage, $transport, false)->run(Reporting::ACTION_ON_REQUEST, KEY, SITE);
$check('off: check only', $transport->take(), ['GET features']);
$check('off: check stored', $make($storage, $transport, false)->lastCheck()?->status, ApiKeyCheck::OK);

// 6. No site URL (a command before anyone opened the back end): check only.
$storage = new MemoryStorage();
$transport = new FakeTransport();
$make($storage, $transport)->run(Reporting::ACTION_ON_REQUEST, KEY, null);
$check('no site URL: check only', $transport->take(), ['GET features']);

// 7. The center fails: no retry for an hour.
$storage = new MemoryStorage();
$transport = new FakeTransport();
$transport->reportStatus = 503;
$result = $make($storage, $transport)->run(Reporting::ACTION_ON_REQUEST, KEY, SITE);
$check('center down', [$transport->take(), $result?->sent], [['GET features', 'report installed'], false]);
$transport->reportStatus = 202;
$clock += 600;
$make($storage, $transport)->run(Reporting::ACTION_ON_REQUEST, KEY, SITE);
$check('throttled after a failure', $transport->take(), []);
$clock += 3600;
$make($storage, $transport)->run(Reporting::ACTION_ON_REQUEST, KEY, SITE);
$check('retried after an hour', $transport->take(), ['report installed']);

// 8. supertext:check: checks the key and sends `check` (not `installed` first).
$storage = new MemoryStorage();
$transport = new FakeTransport();
['check' => $keyCheck, 'report' => $report] = $make($storage, $transport)->checkAndReport(KEY, SITE);
$check('check command', $transport->take(), ['GET features', 'report check']);
$check('check command result', [$keyCheck->status, $report?->sent], [ApiKeyCheck::OK, true]);
$make($storage, $transport)->run(Reporting::ACTION_ON_REQUEST, KEY, SITE);
$check('after check: nothing due', $transport->take(), []);
['report' => $report] = $make($storage, $transport, false)->checkAndReport(KEY, SITE);
$check('check command, reports off', [$transport->take(), $report], [['GET features'], null]);
['check' => $keyCheck] = $make(new MemoryStorage(), $transport)->checkAndReport('', SITE);
$check('check command, no key', [$transport->take(), $keyCheck->status], [['report check'], ApiKeyCheck::MISSING]);

// 9. Broken storage never breaks the caller.
$storage = new MemoryStorage();
$storage->broken = true;
$transport = new FakeTransport();
$result = $make($storage, $transport)->run(Reporting::ACTION_ON_REQUEST, KEY, SITE);
$check('broken storage: still runs', $result?->event, 'installed');
$check('broken storage: last check', $make($storage, $transport)->lastCheck(), null);

// 10. The API key never leaves except in Supertext's Authorization header.
$storage = new MemoryStorage();
$transport = new FakeTransport();
$make($storage, $transport)->run(Reporting::ACTION_ON_REQUEST, KEY, SITE);
foreach ($transport->requests as $request) {
    if ($request->url === REPORT_URL) {
        $check('no key in report', str_contains((string)$request->body, 'secret-test-key'), false);
        $check('no key in report headers', str_contains(json_encode($request->headers), 'secret-test-key'), false);
    }
}
$check('no key in stored state', str_contains(implode('', $storage->values), 'secret-test-key'), false);

// 11. Messages
$invalid = KeyCheckMessages::for(new ApiKeyCheck(ApiKeyCheck::INVALID, '2026-10-14T08:00:00Z', 401));
$check('invalid message links', str_contains($invalid, 'https://www.supertext.com/en/integrations/api') && str_contains($invalid, 'https://www.supertext.com/person/en/account/signin'), true);
$check('ok message', KeyCheckMessages::for(new ApiKeyCheck(ApiKeyCheck::OK, '2026-10-14T08:00:00Z', 200)), 'API key checked: the connection to Supertext works.');
$check('error message status', str_contains(KeyCheckMessages::for(new ApiKeyCheck(ApiKeyCheck::ERROR, '2026-10-14T08:00:00Z', 418)), '(418)'), true);

foreach ($failures as $failure) {
    fwrite(STDERR, $failure . "\n");
}
echo $failures === [] ? "OK: version reports, key check and ping\n" : 'FAILED: ' . count($failures) . "\n";
exit($failures === [] ? 0 : 1);
