<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Api;

use GuzzleHttp\Client;
use Supertext\NeosTranslation\Sdk\Http\HttpRequest;
use Supertext\NeosTranslation\Sdk\Http\HttpResponse;
use Supertext\NeosTranslation\Sdk\Http\HttpTransport;
use Supertext\NeosTranslation\Sdk\Http\TransportException;

/**
 * Sends the Supertext SDK's requests with Guzzle (already a Neos dependency), so
 * ext-curl isn't required and Guzzle's proxy environment variables apply.
 */
final class GuzzleHttpTransport implements HttpTransport
{
    private readonly Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client();
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $options = [
            'headers' => $request->headers,
            'http_errors' => false,
            'timeout' => $request->timeout,
            'allow_redirects' => false,
        ];
        if ($request->body !== null) {
            $options['body'] = $request->body;
        }
        try {
            $response = $this->client->request($request->method, $request->url, $options);
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[(string)$name] = implode(', ', $values);
        }
        return new HttpResponse($response->getStatusCode(), (string)$response->getBody(), $headers);
    }
}
