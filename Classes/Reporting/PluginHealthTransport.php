<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Reporting;

use Supertext\NeosTranslation\PluginHealth\Http\HttpRequest;
use Supertext\NeosTranslation\PluginHealth\Http\HttpResponse;
use Supertext\NeosTranslation\PluginHealth\Http\HttpTransport;
use Supertext\NeosTranslation\PluginHealth\Http\TransportException;
use Supertext\NeosTranslation\Sdk\Http\HttpRequest as SdkHttpRequest;
use Supertext\NeosTranslation\Sdk\Http\HttpTransport as SdkHttpTransport;

/**
 * Sends Supertext Plugin Health's reports through the SDK's HttpTransport (Guzzle in Neos),
 * so reports and Supertext requests share one HTTP client. Plain PHP (no Flow).
 */
final class PluginHealthTransport implements HttpTransport
{
    public function __construct(private readonly SdkHttpTransport $transport)
    {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        try {
            $response = $this->transport->send(new SdkHttpRequest($request->method, $request->url, $request->headers, $request->body, $request->timeout));
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }
        return new HttpResponse($response->status, $response->body, $response->headers);
    }
}
