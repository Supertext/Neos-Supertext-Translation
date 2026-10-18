<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Service;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeVariation\Command\CreateNodeVariant;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;

/**
 * Creates language variants the way "Create and copy" does (supertext:translate and
 * supertext:import), and finds dimension space points.
 */
#[Flow\Scope('singleton')]
class NodeVariants
{
    /** Creates missing variants of the node, its missing parents and (optionally) its content. */
    public function adopt(ContentRepository $cr, WorkspaceName $workspace, NodeAggregateId $id, ContentSubgraphInterface $source, ContentSubgraphInterface $target, DimensionSpacePoint $targetDsp, bool $copyContent): int
    {
        $missing = [];
        $current = $id;
        while ($current !== null && $target->findNodeById($current) === null) {
            $missing[] = $current;
            $current = $source->findParentNode($current)?->aggregateId;
        }
        $created = 0;
        foreach (array_reverse($missing) as $missingId) {
            $created += $this->createVariant($cr, $workspace, $missingId, $source, $target, $targetDsp);
        }
        if ($copyContent) {
            foreach ($this->descendants($cr, $source, $id, false) as $contentId) {
                $created += $this->createVariant($cr, $workspace, $contentId, $source, $target, $targetDsp);
            }
        }
        return $created;
    }

    private function createVariant(ContentRepository $cr, WorkspaceName $workspace, NodeAggregateId $id, ContentSubgraphInterface $source, ContentSubgraphInterface $target, DimensionSpacePoint $targetDsp): int
    {
        $node = $source->findNodeById($id);
        // Tethered nodes (e.g. a page's "main" collection) are created together with their parent.
        if ($node === null || $node->classification->isTethered() || $target->findNodeById($id) !== null) {
            return 0;
        }
        $cr->handle(CreateNodeVariant::create($workspace, $id, $node->originDimensionSpacePoint, OriginDimensionSpacePoint::fromDimensionSpacePoint($targetDsp)));
        return 1;
    }

    /**
     * Pages ($documents = true): all subpages. Content ($documents = false): all content
     * below the page, without descending into subpages. Parents come before children.
     *
     * @return list<NodeAggregateId>
     */
    public function descendants(ContentRepository $cr, ContentSubgraphInterface $subgraph, NodeAggregateId $parent, bool $documents): array
    {
        $result = [];
        foreach ($subgraph->findChildNodes($parent, FindChildNodesFilter::create()) as $child) {
            $isDocument = $cr->getNodeTypeManager()->getNodeType($child->nodeTypeName)?->isOfType(NodeTypeNameFactory::NAME_DOCUMENT) ?? false;
            if ($isDocument !== $documents) {
                continue;
            }
            $result[] = $child->aggregateId;
            array_push($result, ...$this->descendants($cr, $subgraph, $child->aggregateId, $documents));
        }
        return $result;
    }

    public function dimensionSpacePoint(ContentRepository $cr, ContentDimensionId $dimension, string $value): DimensionSpacePoint
    {
        return $this->defaultDimensionSpacePoint($cr)->vary($dimension, $value);
    }

    public function defaultDimensionSpacePoint(ContentRepository $cr): DimensionSpacePoint
    {
        // The first root value of every dimension, in configuration order (Neos.Demo: en_US).
        $coordinates = [];
        foreach ($cr->getContentDimensionSource()->getContentDimensionsOrderedByPriority() as $dimension) {
            $roots = $dimension->getRootValues();
            $coordinates[$dimension->id->value] = reset($roots)->value;
        }
        return DimensionSpacePoint::fromArray($coordinates);
    }
}
