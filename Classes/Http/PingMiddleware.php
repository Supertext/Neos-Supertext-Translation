<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Http;

use GuzzleHttp\Psr7\Response;
use Neos\Flow\Annotations as Flow;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Supertext\NeosTranslation\Reporting\Reporting;
use Supertext\NeosTranslation\Reporting\ReportingService;

/**
 * Public `GET /supertext/ping?challenge=…` for the Supertext Commando Center: answered
 * before the session middleware and Neos' routing, so there is no login, no session
 * cookie and no page cache. 404 while version reports are off. Other paths and methods
 * pass through.
 */
class PingMiddleware implements MiddlewareInterface
{
    #[Flow\Inject]
    protected ReportingService $reportingService;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $next): ResponseInterface
    {
        if ($request->getMethod() !== 'GET' || !Reporting::isPingPath($request->getUri()->getPath())) {
            return $next->handle($request);
        }
        $ping = $this->reportingService->ping($request->getQueryParams()['challenge'] ?? null);
        return new Response($ping->status, $ping->headers, $ping->body);
    }
}
