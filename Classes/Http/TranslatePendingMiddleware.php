<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Http;

use Neos\Flow\Annotations as Flow;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Supertext\NeosTranslation\Domain\PendingTranslations;
use Supertext\NeosTranslation\Reporting\ReportingService;
use Supertext\NeosTranslation\Service\NodeTranslator;

/**
 * After the request was handled, translates every node variant it created, as the
 * editor who created them. The Neos UI reloads the page afterwards, so the editor
 * sees the translated texts.
 *
 * Back-end requests (the Neos UI under /neos, and any request that translated) also give
 * the version reports their chance (ReportingService::onRequest(): usually nothing to do;
 * a request to Supertext only when the API key changed, a report only after an update).
 * Front-end page views never do.
 */
class TranslatePendingMiddleware implements MiddlewareInterface
{
    #[Flow\Inject]
    protected PendingTranslations $pendingTranslations;

    #[Flow\Inject]
    protected NodeTranslator $nodeTranslator;

    #[Flow\Inject]
    protected ReportingService $reportingService;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $next): ResponseInterface
    {
        $response = $next->handle($request);
        $translated = !$this->pendingTranslations->isEmpty();
        if ($translated) {
            $errors = [];
            foreach ($this->nodeTranslator->translatePending() as $result) {
                if ($result->error !== null) {
                    $errors[] = $result->targetLanguage . ': ' . $result->error;
                } elseif ($result->fields > 0) {
                    $response = $response->withAddedHeader('X-Supertext-Translated', sprintf('%s=%d', $result->targetLanguage, $result->fields));
                }
            }
            if ($errors !== []) {
                $response = $response->withHeader('X-Supertext-Error', mb_substr(str_replace(["\r", "\n"], ' ', implode('; ', $errors)), 0, 500));
            }
        }
        if ($translated || self::isBackendPage($request)) {
            $this->reportingService->onRequest($request);
        }
        return $response;
    }

    /** A GET request for the Neos back end (/neos, /neos/…), not a front-end page. */
    public static function isBackendPage(ServerRequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();
        return $request->getMethod() === 'GET' && preg_match('#^/neos(/|$)#', $path) === 1;
    }
}
