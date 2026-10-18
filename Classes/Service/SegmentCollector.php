<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Service;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;

/**
 * What is translated of a node: its translatable properties (PropertySelector) and those of
 * its tethered descendants, keyed `<node aggregate id>.<property>`. Used by API translations
 * (NodeTranslator) and translation files (TranslationFileService), so both send the same texts.
 */
#[Flow\Scope('singleton')]
class SegmentCollector
{
    #[Flow\Inject]
    protected PropertySelector $propertySelector;

    /**
     * Adds the node and its tethered descendants (e.g. a page's "main" collection): Neos creates
     * their variants together with the parent, without separate commands. With $withContent also
     * every content node below it (not descending into subpages), as supertext:translate copies them.
     *
     * @param array<string, array{0: Node, 1: array<string, bool>}> $nodes node id => [node, [property => isHtml]]
     */
    public function collect(ContentRepository $contentRepository, ContentSubgraphInterface $subgraph, Node $node, array &$nodes, bool $withContent = false): void
    {
        if (!isset($nodes[$node->aggregateId->value])) {
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
        if ($withContent) {
            foreach ($subgraph->findChildNodes($node->aggregateId, FindChildNodesFilter::create()) as $child) {
                if (!$this->isDocument($contentRepository, $child)) {
                    $this->collect($contentRepository, $subgraph, $child, $nodes, true);
                }
            }
        }
    }

    /**
     * The non-blank texts of the collected nodes.
     *
     * @param array<string, array{0: Node, 1: array<string, bool>}> $nodes
     *
     * @return array<string, array{0: string, 1: bool}> `<node id>.<property>` => [text, isHtml]
     */
    public function segments(array $nodes): array
    {
        $segments = [];
        foreach ($nodes as $nodeId => [$node, $properties]) {
            foreach ($properties as $property => $isHtml) {
                $value = $node->getProperty($property);
                if (is_string($value) && trim(strip_tags($value)) !== '') {
                    $segments[$nodeId . '.' . $property] = [$value, $isHtml];
                }
            }
        }
        return $segments;
    }

    /**
     * `<node id>.<property>` => text, grouped as node id => [property => text].
     *
     * @param array<string, string> $translations
     *
     * @return array<string, array<string, string>>
     */
    public static function group(array $translations): array
    {
        $values = [];
        foreach ($translations as $key => $text) {
            $dot = strpos((string)$key, '.');
            if ($dot !== false) {
                $values[substr((string)$key, 0, $dot)][substr((string)$key, $dot + 1)] = $text;
            }
        }
        return $values;
    }

    public function isDocument(ContentRepository $contentRepository, Node $node): bool
    {
        return $contentRepository->getNodeTypeManager()->getNodeType($node->nodeTypeName)
            ?->isOfType(NodeTypeNameFactory::NAME_DOCUMENT) ?? false;
    }
}
