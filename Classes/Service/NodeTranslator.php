<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Service;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\Neos\Utility\NodeUriPathSegmentGenerator;
use Psr\Log\LoggerInterface;
use Supertext\NeosTranslation\Api\HtmlDocument;
use Supertext\NeosTranslation\Api\SupertextClient;
use Supertext\NeosTranslation\Configuration\Settings;
use Supertext\NeosTranslation\Domain\PendingTranslation;
use Supertext\NeosTranslation\Domain\PendingTranslations;

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
    protected SupertextClient $client;

    #[Flow\Inject]
    protected PropertySelector $propertySelector;

    #[Flow\Inject]
    protected LanguageResolver $languageResolver;

    #[Flow\Inject]
    protected NodeUriPathSegmentGenerator $uriPathSegmentGenerator;

    #[Flow\Inject]
    protected Settings $settings;

    #[Flow\Inject]
    protected LoggerInterface $logger;

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
                    $this->collect($contentRepository, $sourceSubgraph, $node, $nodes);
                }
            }

            $segments = [];
            $map = [];
            foreach ($nodes as $nodeId => [$node, $properties]) {
                foreach ($properties as $property => $isHtml) {
                    $value = $node->getProperty($property);
                    if (!is_string($value) || trim(strip_tags($value)) === '') {
                        continue;
                    }
                    $map[] = [$nodeId, $property];
                    $segments[] = ['text' => $value, 'html' => $isHtml];
                }
            }
            if ($segments === []) {
                return $result;
            }

            $translated = [];
            foreach ($this->chunk($segments) as $offset => $chunk) {
                $html = $this->client->translateDocument(
                    HtmlDocument::build($chunk),
                    $this->languageResolver->targetCode($targetLanguage),
                    $this->languageResolver->sourceCode($sourceLanguage),
                    $this->languageResolver->politeness($targetLanguage),
                );
                $isHtml = array_map(static fn(array $s): bool => $s['html'], $chunk);
                foreach (HtmlDocument::parse($html, $isHtml) as $id => $text) {
                    $translated[$offset + $id] = $text;
                }
            }

            $values = [];
            foreach ($map as $index => [$nodeId, $property]) {
                if (isset($translated[$index]) && $translated[$index] !== '') {
                    $values[$nodeId][$property] = $translated[$index];
                }
            }

            $targetSubgraph = $contentRepository->getContentGraph($first->workspaceName)
                ->getSubgraph($first->targetOrigin->toDimensionSpacePoint(), VisibilityConstraints::withoutRestrictions());
            foreach ($values as $nodeId => $properties) {
                $node = $nodes[$nodeId][0];
                $targetNode = $targetSubgraph->findNodeById($node->aggregateId);
                if ($targetNode === null) {
                    continue; // variant was removed again meanwhile
                }
                if (isset($properties['title']) && $this->isDocument($contentRepository, $node) && $node->hasProperty('uriPathSegment')) {
                    $properties['uriPathSegment'] = $this->uriPathSegmentGenerator->generateUriPathSegment($targetNode, strip_tags($properties['title']));
                }
                $contentRepository->handle(SetNodeProperties::create(
                    $first->workspaceName,
                    $node->aggregateId,
                    $targetNode->originDimensionSpacePoint,
                    PropertyValuesToWrite::fromArray($properties),
                ));
                $result->nodes++;
                $result->fields += count($properties);
            }
        } catch (\Throwable $e) {
            $result->error = $e->getMessage();
            $this->logger->error(sprintf(
                'Supertext: translating %d node(s) from "%s" to "%s" in workspace "%s" failed: %s',
                count($items), $sourceLanguage, $targetLanguage, $first->workspaceName->value, $e->getMessage()
            ), ['exception' => $e]);
            return $result;
        }

        $this->logger->info(sprintf('Supertext: translated %d field(s) in %d node(s) from "%s" to "%s" in workspace "%s".',
            $result->fields, $result->nodes, $sourceLanguage, $targetLanguage, $result->workspace));
        return $result;
    }

    /**
     * Adds the node and its tethered descendants (e.g. a page's "main" collection):
     * Neos creates their variants together with the parent, without separate commands.
     *
     * @param array<string, array{0: Node, 1: array<string, bool>}> $nodes
     */
    private function collect(ContentRepository $contentRepository, ContentSubgraphInterface $subgraph, Node $node, array &$nodes): void
    {
        if (isset($nodes[$node->aggregateId->value])) {
            return;
        }
        $nodeType = $contentRepository->getNodeTypeManager()->getNodeType($node->nodeTypeName);
        if ($nodeType === null) {
            return;
        }
        $nodes[$node->aggregateId->value] = [$node, $this->propertySelector->translatableProperties($nodeType)];
        foreach ($nodeType->tetheredNodeTypeDefinitions as $definition) {
            $child = $subgraph->findNodeByPath($definition->name, $node->aggregateId);
            if ($child !== null) {
                $this->collect($contentRepository, $subgraph, $child, $nodes);
            }
        }
    }

    private function isDocument(ContentRepository $contentRepository, Node $node): bool
    {
        return $contentRepository->getNodeTypeManager()->getNodeType($node->nodeTypeName)
            ?->isOfType(NodeTypeNameFactory::NAME_DOCUMENT) ?? false;
    }

    /**
     * Splits segments into documents below Supertext's size limit.
     *
     * @param list<array{text: string, html: bool}> $segments
     * @return array<int, array<int, array{text: string, html: bool}>> first segment index => segments
     */
    private function chunk(array $segments): array
    {
        $chunks = [];
        $start = 0;
        $size = 0;
        $current = [];
        foreach ($segments as $index => $segment) {
            $length = mb_strlen($segment['text']) + 40;
            if ($current !== [] && $size + $length > SupertextClient::MAX_DOCUMENT_CHARACTERS) {
                $chunks[$start] = $current;
                $start = $index;
                $size = 0;
                $current = [];
            }
            $current[$index - $start] = $segment;
            $size += $length;
        }
        $chunks[$start] = $current;
        return $chunks;
    }
}
