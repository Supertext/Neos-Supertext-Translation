<?php

// Checks the glue between the package and the Supertext SDK: the Guzzle transport and
// how Neos dimension values become Supertext codes. Uses the bundled SDK (lib/supertext-sdk)
// and needs Guzzle, e.g. from the demo's dependencies (CI: composer install --working-dir=demo):
//   php Tests/SdkTest.php
// The SDK has its own tests for the protocol, HTML packing and language codes.
declare(strict_types=1);

foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../demo/Packages/Libraries/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}
// The bundled SDK and the transport, independent of where the package's own autoloading points.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Supertext\\NeosTranslation\\Sdk\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__ . '/../lib/supertext-sdk/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
require_once __DIR__ . '/../Classes/Api/GuzzleHttpTransport.php';

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Supertext\NeosTranslation\Api\GuzzleHttpTransport;
use Supertext\NeosTranslation\Sdk\Client;
use Supertext\NeosTranslation\Sdk\LanguageCodes;
use Supertext\NeosTranslation\Sdk\Segment;
use Supertext\NeosTranslation\Sdk\SupertextException;

$failures = [];
$check = static function (string $name, mixed $actual, mixed $expected) use (&$failures): void {
    if ($actual !== $expected) {
        $failures[] = sprintf("%s:\n  expected: %s\n  actual:   %s", $name, json_encode($expected), json_encode($actual));
    }
};

// 1. Neos language dimension values, as LanguageResolver hands them to the SDK.
$check('target en_US', LanguageCodes::target('en_US'), 'en-US');
$check('target bare de', LanguageCodes::target('de'), 'de-DE');
$check('target configured de-ch', LanguageCodes::target('de-ch'), 'de-CH');
$check('source en_US', LanguageCodes::source('en_US'), 'en');
$check('target variant dropped', LanguageCodes::target('de_DE_formal'), 'de-DE');

// 2. A full translateTexts() run through Guzzle (mocked): 429 retry, upload, poll, download, delete.
$history = [];
$mock = new MockHandler([
    new Response(429, ['Retry-After' => '0']),
    new Response(200, [], '{"file_id":"f1"}'),
    new Response(200, [], '{"status":"translating"}'),
    new Response(200, [], '{"status":"done"}'),
    new Response(200, [], '<html><body><div data-st-id="0">Fisch &amp; Chips<br>für zwei</div>'
        . '<div data-st-id="1"><p>Wir liefern <strong>Schweizer Schokolade</strong>.</p></div></body></html>'),
    new Response(204),
]);
$stack = HandlerStack::create($mock);
$stack->push(Middleware::history($history));
$client = new Client(
    'Supertext-Auth-Key test-key',
    baseUrl: 'https://api.supertext.com/v1/',
    pollInterval: 0.0,
    transport: new GuzzleHttpTransport(new \GuzzleHttp\Client(['handler' => $stack])),
    sleep: static function (): void {},
);
$result = $client->translateTexts([
    new Segment("Fish & Chips\nfor two"),
    new Segment('<p>We ship <strong>Swiss chocolate</strong>.</p>', true),
], 'de_CH', 'en_US', 'more');
$check('plain text segment', $result[0] ?? null, "Fisch & Chips\nfür zwei");
$check('html segment', $result[1] ?? null, '<p>Wir liefern <strong>Schweizer Schokolade</strong>.</p>');
$check('requests', array_map(static fn(array $h): string => $h['request']->getMethod() . ' ' . $h['request']->getUri()->getPath(), $history), [
    'POST /v1/translate/ai/file', 'POST /v1/translate/ai/file',
    'GET /v1/translate/ai/file/f1/status', 'GET /v1/translate/ai/file/f1/status',
    'GET /v1/translate/ai/file/f1/translation', 'DELETE /v1/translate/ai/file/f1',
]);
$upload = $history[1]['request'] ?? null;
$check('auth header, prefix not doubled', $upload?->getHeaderLine('Authorization'), 'Supertext-Auth-Key test-key');
$body = (string)$upload?->getBody();
$check('target sent regional', str_contains($body, "name=\"target_lang\"\r\n\r\nde-CH\r\n"), true);
$check('source sent bare', str_contains($body, "name=\"source_lang\"\r\n\r\nen\r\n"), true);
$check('file part is text/html', str_contains($body, "filename=\"content.html\"\r\nContent-Type: text/html\r\n"), true);

// 3. Errors arrive as SupertextException with a stable code; network failures as transport_error.
$failing = new Client('k', transport: new GuzzleHttpTransport(new \GuzzleHttp\Client(['handler' => HandlerStack::create(new MockHandler([
    new Response(401, [], '{"error_code":"UNAUTHORIZED"}'),
    new \GuzzleHttp\Exception\ConnectException('DNS failure', new \GuzzleHttp\Psr7\Request('GET', 'https://api.supertext.com/v1/features')),
]))])));
foreach (['authentication_failure', 'transport_error'] as $expected) {
    try {
        $failing->validateApiKey();
        $failures[] = "expected $expected, got no error";
    } catch (SupertextException $e) {
        $check("error code $expected", $e->errorCode, $expected);
    }
}

// 4. A language Supertext doesn't know at all: HTTP 404 with INVALID_LANGUAGE_PAIR, not "not found".
$unknown = new Client('k', transport: new GuzzleHttpTransport(new \GuzzleHttp\Client(['handler' => HandlerStack::create(new MockHandler([
    new Response(404, ['Content-Type' => 'application/json'], '{"error_code":"INVALID_LANGUAGE_PAIR","source_lang":"en","target_lang":"xx-YY"}'),
]))])));
try {
    $unknown->submit('<p data-st-id="0">Hi</p>', 'xx-YY', 'en');
    $failures[] = 'expected invalid_language_pair, got no error';
} catch (SupertextException $e) {
    $check('unknown language: error code', $e->errorCode, 'invalid_language_pair');
    $check('unknown language: codes', [$e->sourceLang, $e->targetLang], ['en', 'xx-YY']);
}

foreach ($failures as $failure) {
    fwrite(STDERR, $failure . "\n");
}
echo $failures === [] ? "OK: package and Supertext SDK fit together\n" : 'FAILED: ' . count($failures) . "\n";
exit($failures === [] ? 0 : 1);
