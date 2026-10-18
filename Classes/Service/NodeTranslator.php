<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Service;

use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Psr\Log\LoggerInterface;
use Supertext\NeosTranslation\Api\SupertextClientFactory;
use Supertext\NeosTranslation\Configuration\Settings;
use Supertext\NeosTranslation\Domain\PendingTranslation;
use Supertext\NeosTranslation\Domain\PendingTranslations;
use Supertext\NeosTranslation\Reporting\ReportingService;
use Supertext\NeosTranslation\Sdk\V1\Segment;
use Supertext\NeosTranslation\Sdk\V1\SupertextException;

/**
 * Translates all pending node variants: one Supertext document per
 * (workspace, source language, target language), then one SetNodeProperties
 * command per node.
 */
#[Flow\Scope('singleton')]
class NodeTranslator
{
    #[Flow\Inject]
    protected PendingTranslations $pendingTranslations;

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected SupertextClientFactory $clientFactory;

    #[Flow\Inject]
    protected SegmentCollector $segmentCollector;

    #[Flow\Inject]
    protected TranslationWriter $translationWriter;

    #[Flow\Inject]
    protected LanguageResolver $languageResolver;

    #[Flow\Inject]
    protected Settings $settings;

    #[Flow\Inject]
    protected LoggerInterface $logger;

    #[Flow\Inject]
    protected ReportingService $reportingService;

    private bool $running = false;

    /** @return list<TranslationResult> one result per target language and workspace */
    public function translatePending(): array
    {
        if ($this->running || $this->pendingTranslations->isEmpty()) {
            return [];
        }
        $this->running = true;
        try {
            $batches = [];
            foreach ($this->pendingTranslations->takeAll() as $item) {
                $batches[$item->batchKey()][] = $item;
            }
            $results = [];
            foreach ($batches as $items) {
                $results[] = $this->translateBatch($items);
            }
            return $results;
        } finally {
            $this->running = false;
        }
    }

    /** @param non-empty-list<PendingTranslation> $items all with the same batch key */
    private function translateBatch(array $items): TranslationResult
    {
        $first = $items[0];
        $dimension = new ContentDimensionId($this->settings->getLanguageDimension());
        $sourceLanguage = (string)$first->sourceOrigin->toDimensionSpacePoint()->getCoordinate($dimension);
        $targetLanguage = (string)$first->targetOrigin->toDimensionSpacePoint()->getCoordinate($dimension);
        $result = new TranslationResult($first->workspaceName->value, $sourceLanguage, $targetLanguage);

        try {
            $contentRepository = $this->contentRepositoryRegistry->get($first->contentRepositoryId);
            $sourceSubgraph = $contentRepository->getContentGraph($first->workspaceName)
                ->getSubgraph($first->sourceOrigin->toDimensionSpacePoint(), VisibilityConstraints::withoutRestrictions());

            // node id => [node, [property => isHtml]]
            $nodes = [];
            foreach ($items as $item) {
                $node = $sourceSubgraph->findNodeById($item->nodeAggregateId);
                if ($node !== null) {
                    $this->segmentCollector->collect($contentRepository, $sourceSubgraph, $node, $nodes);
                }
            }

            $fields = $this->segmentCollector->segments($nodes);
            if ($fields === []) {
                return $result;
            }
            $keys = array_keys($fields);
            $segments = array_map(static fn (array $field): Segment => new Segment($field[0], $field[1]), array_values($fields));

            // Language codes first: an unmapped language stops here, before anything is sent.
            $targetCode = $this->languageResolver->targetCode($targetLanguage);
            $sourceCode = $this->languageResolver->sourceCode($sourceLanguage);
            // One document per batch; the SDK splits only above ~900k characters.
            $translated = $this->clientFactory->create()->translateTexts(
                $segments,
                $targetCode,
                $sourceCode,
                $this->languageResolver->politeness($targetLanguage),
            );

            $translations = [];
            foreach ($keys as $index => $key) {
                if (isset($translated[$index]) && trim($translated[$index]) !== '') {
                    $translations[$key] = $translated[$index];
                }
            }

            $targetSubgraph = $contentRepository->getContentGraph($first->workspaceName)
                ->getSubgraph($first->targetOrigin->toDimensionSpacePoint(), VisibilityConstraints::withoutRestrictions());
            $written = $this->translationWriter->write($contentRepository, $first->workspaceName, $targetSubgraph, $nodes, SegmentCollector::group($translations));
            $result->nodes += $written['nodes'];
            $result->fields += $written['fields'];
        } catch (\Throwable $e) {
            $result->error = ErrorMessages::forException($e, $targetLanguage);
            if ($e instanceof SupertextException && $e->errorCode === SupertextException::AUTHENTICATION_FAILURE) {
                $this->reportingService->authenticationFailed($e->status ?? 401);
            }
            $this->logger->error(sprintf(
                'Supertext: translating %d node(s) from "%s" to "%s" in workspace "%s" failed: %s',
                count($items), $sourceLanguage, $targetLanguage, $first->workspaceName->value, $result->error
            ), ['exception' => $e]);
            return $result;
        }

        $this->logger->info(sprintf('Supertext: translated %d field(s) in %d node(s) from "%s" to "%s" in workspace "%s".',
            $result->fields, $result->nodes, $sourceLanguage, $targetLanguage, $result->workspace));
        return $result;
    }
}
