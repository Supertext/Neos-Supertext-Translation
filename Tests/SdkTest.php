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
require_once __DIR__ . '/../Classes/Service/LanguageMapping.php';
require_once __DIR__ . '/../Classes/Configuration/PackageVersion.php';
require_once __DIR__ . '/../Classes/Configuration/Settings.php';
require_once __DIR__ . '/../Classes/Api/SupertextClientFactory.php';
require_once __DIR__ . '/../Classes/Service/LanguageResolver.php';
require_once __DIR__ . '/../Classes/Service/ErrorMessages.php';

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Supertext\NeosTranslation\Api\GuzzleHttpTransport;
use Supertext\NeosTranslation\Api\SupertextClientFactory;
use Supertext\NeosTranslation\Configuration\Settings;
use Supertext\NeosTranslation\Sdk\V1\Client;
use Supertext\NeosTranslation\Sdk\V1\Languages;
use Supertext\NeosTranslation\Sdk\V1\Segment;
use Supertext\NeosTranslation\Sdk\V1\SupertextException;
use Supertext\NeosTranslation\Service\ErrorMessages;
use Supertext\NeosTranslation\Service\LanguageMapping;
use Supertext\NeosTranslation\Service\LanguageResolver;

$failures = [];
$check = static function (string $name, mixed $actual, mixed $expected) use (&$failures): void {
    if ($actual !== $expected) {
        $failures[] = sprintf("%s:\n  expected: %s\n  actual:   %s", $name, json_encode($expected), json_encode($actual));
    }
};

// 1. Neos language dimension values, as LanguageResolver hands them to the SDK: the
//    mapping (languages.<value>.code) wins, else an exact Supertext code, else the SDK's
//    default where one is obvious (SDK 0.4.0), else nothing is sent.
$mapping = LanguageMapping::overrides([
    'de' => ['code' => 'de-CH', 'politeness' => 'more'],
    'en_UK' => ['code' => 'en-GB'],
    'fr' => ['politeness' => 'more'],           // no code: not part of the mapping
    'it' => ['code' => '  '],
]);
$check('mapping from settings', $mapping, ['de' => 'de-CH', 'en_UK' => 'en-GB']);
$check('target mapped de', Languages::target('de', $mapping), 'de-CH');
$check('target mapped en_UK', Languages::target('en_UK', $mapping), 'en-GB');
$check('target exact en_US', Languages::target('en_US', $mapping), 'en-US');
$check('target exact bare es', Languages::target('es', $mapping), 'es');
$check('target exact de_ch', Languages::target('de_ch'), 'de-CH');
$check('source en_US', Languages::source('en_US', $mapping), 'en');
$check('source mapped de (sent bare)', Languages::source('de', $mapping), 'de');
$check('target unmapped fr: default', LanguageResolver::resolveTarget('fr', $mapping), 'fr-FR');
$check('target region of a bare code es_ES: default', LanguageResolver::resolveTarget('es_ES', $mapping), 'es');
$check('target without default en_IE', LanguageResolver::resolveTarget('en_IE', $mapping), null);
try {
    Languages::target('gsw', $mapping);
    $failures[] = 'expected unsupported_language for gsw';
} catch (SupertextException $e) {
    $check('unmapped gsw: error code', $e->errorCode, 'unsupported_language');
    $check('unmapped gsw: message names the setting', ErrorMessages::forException($e),
        "The language gsw has no Supertext language yet. Ask your administrator to choose one under Administration → Supertext (or to set Supertext.NeosTranslation.languages.gsw.code in Settings.yaml, e.g. 'de-CH').");
}
$check('politeness unsupported for en-US', LanguageResolver::politenessUnsupported('en-US', 'more'), true);
$check('politeness supported for de-CH', LanguageResolver::politenessUnsupported('de-CH', 'more'), false);
$check('default politeness never warns', LanguageResolver::politenessUnsupported('en-US', 'default'), false);
$check('unsupported_feature message', ErrorMessages::forException(new SupertextException(SupertextException::UNSUPPORTED_FEATURE, feature: 'politeness'), 'en_US'),
    "Supertext offers no formal or informal tone for this language. Set it to the default tone (Supertext.NeosTranslation.languages.en_US.politeness: 'default').");
$check('auth failure message has both links', str_contains(ErrorMessages::forException(new SupertextException(SupertextException::AUTHENTICATION_FAILURE, 401)), 'https://www.supertext.com/person/en/account/signin')
    && str_contains(ErrorMessages::forException(new SupertextException(SupertextException::AUTHENTICATION_FAILURE, 401)), 'https://www.supertext.com/en/integrations/api'), true);

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
], Languages::target('de', $mapping), Languages::source('en_US'), 'more');
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

// 5. Translation review: a language with review on goes to the review service (all four calls),
//    others go directly to Supertext; an empty reviewUrl turns it off; the default is
//    Supertext's review service; SUPERTEXT_REVIEW_URL wins.
$factory = static function (array $settingsValues): SupertextClientFactory {
    $settings = (new ReflectionClass(Settings::class))->newInstanceWithoutConstructor();
    (fn () => $this->settings = $settingsValues)->call($settings);
    $factory = new SupertextClientFactory();
    (fn () => $this->settings = $settings)->call($factory);
    return $factory;
};
$run = static function (SupertextClientFactory $factory, bool $review): array {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], '{"file_id":"r1"}'),
        new Response(200, [], '{"status":"done"}'),
        new Response(200, [], '<html><body><div data-st-id="0">Hallo</div></body></html>'),
        new Response(204),
    ]));
    $stack->push(Middleware::history($history));
    $factory->create($review, new GuzzleHttpTransport(new \GuzzleHttp\Client(['handler' => $stack])))->translateTexts([new Segment('Hello')], 'de-CH', 'en');
    return array_map(static fn (array $h): string => $h['request']->getMethod() . ' ' . $h['request']->getUri(), $history);
};
$base = ['apiKey' => 'test-key', 'endpoint' => 'https://api.supertext.com/v1/', 'pollInterval' => 1, 'pollTimeout' => 30];
putenv('SUPERTEXT_REVIEW_URL');
$check('review: default is the review service', (fn () => $this->settings->getReviewUrl())->call($factory($base)), SupertextClientFactory::REVIEW_URL);
$check('review: default URL', SupertextClientFactory::REVIEW_URL, 'https://localizationreview-production.up.railway.app/v1/');
$check('review language goes to the review service (all four calls)', $run($factory($base + ['reviewUrl' => 'https://review.test/v1']), true), [
    'POST https://review.test/v1/translate/ai/file',
    'GET https://review.test/v1/translate/ai/file/r1/status',
    'GET https://review.test/v1/translate/ai/file/r1/translation',
    'DELETE https://review.test/v1/translate/ai/file/r1',
]);
$check('other languages go directly to Supertext', $run($factory($base + ['reviewUrl' => 'https://review.test/v1']), false), [
    'POST https://api.supertext.com/v1/translate/ai/file',
    'GET https://api.supertext.com/v1/translate/ai/file/r1/status',
    'GET https://api.supertext.com/v1/translate/ai/file/r1/translation',
    'DELETE https://api.supertext.com/v1/translate/ai/file/r1',
]);
$check('empty reviewUrl: review off, directly to Supertext', $run($factory($base + ['reviewUrl' => '']), true)[0], 'POST https://api.supertext.com/v1/translate/ai/file');
$check('reviewUrl equal to the API: no second endpoint', SupertextClientFactory::baseUrl(true, 'https://api.supertext.com/v1/', 'https://api.supertext.com/v1'), 'https://api.supertext.com/v1/');
putenv('SUPERTEXT_REVIEW_URL=https://other-review.test/v1');
$check('SUPERTEXT_REVIEW_URL wins', $run($factory($base + ['reviewUrl' => '']), true)[0], 'POST https://other-review.test/v1/translate/ai/file');
putenv('SUPERTEXT_REVIEW_URL');
$userAgent = null;
$uaStack = HandlerStack::create(new MockHandler([new Response(200, [], '{}')]));
$uaStack->push(Middleware::mapRequest(static function ($request) use (&$userAgent) { $userAgent = $request->getHeaderLine('User-Agent'); return $request; }));
try {
    $factory($base)->create(true, new GuzzleHttpTransport(new \GuzzleHttp\Client(['handler' => $uaStack])))->getFeatures();
} catch (\Throwable) {
}
$check('review client: same User-Agent with the package version', str_contains((string)$userAgent, 'Neos-Supertext-Translation/'), true);

foreach ($failures as $failure) {
    fwrite(STDERR, $failure . "\n");
}
echo $failures === [] ? "OK: package and Supertext SDK fit together\n" : 'FAILED: ' . count($failures) . "\n";
exit($failures === [] ? 0 : 1);
