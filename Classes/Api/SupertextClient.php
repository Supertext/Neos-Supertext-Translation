<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Api;

use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Client;
use Neos\Flow\Annotations as Flow;
use Supertext\NeosTranslation\Configuration\Settings;

/**
 * Supertext AI file translation (https://api.supertext.com/v1/).
 *
 * Same protocol as the Supertext WordPress plugin: submit one HTML document
 * (up to 1,000,000 characters), poll its status, download the translation,
 * delete the file. The file endpoint allows ~1 request per second.
 */
#[Flow\Scope('singleton')]
class SupertextClient
{
    /** Retries after HTTP 429 (rate limit per second). */
    private const RATE_LIMIT_RETRIES = 4;

    public const MAX_DOCUMENT_CHARACTERS = 900000;

    #[Flow\Inject]
    protected Settings $settings;

    private ?Client $client = null;

    public function hasApiKey(): bool
    {
        return $this->settings->getApiKey() !== '';
    }

    /**
     * Translates a complete HTML document and returns the translated HTML.
     *
     * @param string $targetLanguage BCP-47 code, e.g. "de-CH"
     * @param string $sourceLanguage primary subtag ("de"), empty for auto-detection
     * @param string $politeness "default", "more" or "less"
     */
    public function translateDocument(string $html, string $targetLanguage, string $sourceLanguage = '', string $politeness = 'default'): string
    {
        $fileId = $this->submit($html, $targetLanguage, $sourceLanguage, $politeness);
        try {
            $this->waitUntilDone($fileId);
            return $this->download($fileId);
        } finally {
            $this->deleteQuietly($fileId);
        }
    }

    /** Cost-free check of the API key. */
    public function validateApiKey(): void
    {
        $this->request('GET', 'features');
    }

    private function submit(string $html, string $targetLanguage, string $sourceLanguage, string $politeness): string
    {
        $fields = ['target_lang' => $targetLanguage];
        if ($sourceLanguage !== '') {
            // Supertext expects the source as a primary subtag ("de", not "de-CH"),
            // otherwise the pair is rejected with INVALID_LANGUAGE_PAIR.
            $fields['source_lang'] = strtolower((string)strtok($sourceLanguage, '-_'));
        }
        if (in_array($politeness, ['more', 'less'], true)) {
            $fields['politeness'] = $politeness;
        }

        $multipart = [];
        foreach ($fields as $name => $contents) {
            $multipart[] = ['name' => $name, 'contents' => $contents];
        }
        // The part's Content-Type must be exactly "text/html" (no charset suffix),
        // otherwise Supertext answers 415 FILETYPE_NOT_ALLOWED.
        $multipart[] = [
            'name' => 'file',
            'contents' => $html,
            'filename' => 'content.html',
            'headers' => ['Content-Type' => 'text/html'],
        ];

        $data = $this->json($this->request('POST', 'translate/ai/file', ['multipart' => $multipart]));
        $fileId = (string)($data['file_id'] ?? '');
        if ($fileId === '') {
            throw new SupertextException('Supertext did not return a file id.', 1759500001);
        }
        return $fileId;
    }

    private function waitUntilDone(string $fileId): void
    {
        $interval = $this->settings->getPollInterval();
        $timeout = $this->settings->getPollTimeout();
        if (function_exists('set_time_limit')) {
            @set_time_limit($timeout + 60);
        }
        $deadline = time() + $timeout;

        do {
            $status = (string)($this->json($this->request('GET', 'translate/ai/file/' . rawurlencode($fileId) . '/status'))['status'] ?? '');
            switch ($status) {
                case 'done':
                    return;
                case 'error':
                    throw new SupertextException('Supertext failed to translate the document.', 1759500002);
                case 'limit_exceeded':
                    throw new SupertextException('Your Supertext translation limit is exceeded.', 1759500003);
                case 'deleted':
                    throw new SupertextException('The Supertext file was deleted before it could be downloaded.', 1759500004);
            }
            sleep($interval);
        } while (time() < $deadline);

        throw new SupertextException('Timed out waiting for the Supertext translation.', 1759500005);
    }

    private function download(string $fileId): string
    {
        $body = (string)$this->request('GET', 'translate/ai/file/' . rawurlencode($fileId) . '/translation')->getBody();
        if (trim($body) === '') {
            throw new SupertextException('The translated document was empty.', 1759500006);
        }
        return $body;
    }

    private function deleteQuietly(string $fileId): void
    {
        try {
            $this->request('DELETE', 'translate/ai/file/' . rawurlencode($fileId));
        } catch (\Throwable) {
            // Files expire after 24h anyway.
        }
    }

    /** @param array<string, mixed> $options */
    private function request(string $method, string $path, array $options = []): ResponseInterface
    {
        // Accept the key with or without the "Supertext-Auth-Key " prefix Supertext shows it with.
        $apiKey = (string)preg_replace('/^Supertext-Auth-Key\s+/i', '', trim($this->settings->getApiKey()));
        if ($apiKey === '') {
            throw new SupertextException('No Supertext API key configured (Supertext.NeosTranslation.apiKey or SUPERTEXT_API_KEY).', 1759500010);
        }
        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Authorization' => 'Supertext-Auth-Key ' . $apiKey,
            'Accept' => 'application/json',
        ]);
        $options['http_errors'] = false;
        $options['timeout'] ??= 30;

        // The API limits requests per second; on HTTP 429 wait (Retry-After, else 1 s, 2 s, 4 s, 8 s) and retry.
        for ($attempt = 0; ; $attempt++) {
            try {
                $this->client ??= new Client();
                $response = $this->client->request($method, $this->settings->getBaseUrl() . ltrim($path, '/'), $options);
            } catch (\Throwable $e) {
                throw new SupertextException('Could not reach Supertext: ' . $e->getMessage(), 1759500011, $e);
            }
            if ($response->getStatusCode() !== 429 || $attempt >= self::RATE_LIMIT_RETRIES) {
                break;
            }
            $retryAfter = $response->getHeaderLine('Retry-After');
            $delayMs = is_numeric($retryAfter) ? min(30000, (int)((float)$retryAfter * 1000)) : 1000 * 2 ** $attempt + random_int(0, 250);
            usleep($delayMs * 1000);
        }

        $code = $response->getStatusCode();
        if ($code >= 200 && $code < 300) {
            return $response;
        }
        $message = match (true) {
            $code === 401, $code === 403 => 'Authentication failed. Please check the Supertext API key.',
            $code === 404 => 'The requested Supertext resource was not found.',
            $code === 413 => 'The content is too large for Supertext to translate in one go.',
            $code === 429 => 'Too many requests to Supertext. Please try again shortly.',
            $code >= 500 => 'The Supertext service is currently unavailable.',
            default => sprintf('Supertext answered with HTTP %d.', $code),
        };
        $detail = trim(strip_tags((string)$response->getBody()));
        if ($detail !== '') {
            $message .= ' (' . mb_substr($detail, 0, 200) . ')';
        }
        throw new SupertextException($message, 1759500012);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $data = json_decode((string)$response->getBody(), true);
        return is_array($data) ? $data : [];
    }
}
