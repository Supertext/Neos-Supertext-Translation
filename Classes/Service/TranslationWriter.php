<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Service;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Utility\NodeUriPathSegmentGenerator;

/**
 * Writes translated properties into the target language variants: one SetNodeProperties
 * command per node; a translated document title also gets a new URL path segment. Used by
 * API translations and translation file imports.
 */
#[Flow\Scope('singleton')]
class TranslationWriter
{
    #[Flow\Inject]
    protected NodeUriPathSegmentGenerator $uriPathSegmentGenerator;

    #[Flow\Inject]
    protected SegmentCollector $segmentCollector;

    /**
     * @param array<string, array{0: Node, 1: array<string, bool>}> $nodes  the source nodes (SegmentCollector::collect())
     * @param array<string, array<string, string>>                  $values node id => [property => translation]
     *
     * @return array{nodes: int, fields: int}
     */
    public function write(ContentRepository $contentRepository, WorkspaceName $workspaceName, ContentSubgraphInterface $targetSubgraph, array $nodes, array $values): array
    {
        $written = ['nodes' => 0, 'fields' => 0];
        foreach ($values as $nodeId => $properties) {
            if (!isset($nodes[$nodeId])) {
                continue;
            }
            $node = $nodes[$nodeId][0];
            // Only properties the collector offered for this node.
            $properties = array_intersect_key($properties, $nodes[$nodeId][1]);
            $targetNode = $targetSubgraph->findNodeById($node->aggregateId);
            if ($targetNode === null || $properties === []) {
                continue; // variant was removed again meanwhile
            }
            if (isset($properties['title']) && $this->segmentCollector->isDocument($contentRepository, $node) && $node->hasProperty('uriPathSegment')) {
                $properties['uriPathSegment'] = $this->uriPathSegmentGenerator->generateUriPathSegment($targetNode, strip_tags($properties['title']));
            }
            $contentRepository->handle(SetNodeProperties::create(
                $workspaceName,
                $node->aggregateId,
                $targetNode->originDimensionSpacePoint,
                PropertyValuesToWrite::fromArray($properties),
            ));
            $written['nodes']++;
            $written['fields'] += count($properties);
        }
        return $written;
    }
}
