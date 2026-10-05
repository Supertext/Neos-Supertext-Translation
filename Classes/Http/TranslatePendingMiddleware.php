<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Http;

use Neos\Flow\Annotations as Flow;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Supertext\NeosTranslation\Domain\PendingTranslations;
use Supertext\NeosTranslation\Service\NodeTranslator;

/**
 * After the request was handled, translates every node variant it created, as the
 * editor who created them. The Neos UI reloads the page afterwards, so the editor
 * sees the translated texts.
 */
class TranslatePendingMiddleware implements MiddlewareInterface
{
    #[Flow\Inject]
    protected PendingTranslations $pendingTranslations;

    #[Flow\Inject]
    protected NodeTranslator $nodeTranslator;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $next): ResponseInterface
    {
        $response = $next->handle($request);
        if (!$this->pendingTranslations->isEmpty()) {
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
        return $response;
    }
}
