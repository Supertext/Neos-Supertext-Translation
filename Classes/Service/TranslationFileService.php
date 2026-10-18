<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Service;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Neos\Domain\NodeLabel\NodeLabelGeneratorInterface;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Supertext\NeosTranslation\Configuration\Settings;
use Supertext\NeosTranslation\Domain\PendingTranslations;
use Supertext\NeosTranslation\Files\FileTransfer;
use Supertext\NeosTranslation\Files\FileTransferException;

/**
 * supertext:export / supertext:import in Neos: finds the node and the languages, collects the
 * segments exactly as API translations do (SegmentCollector) and writes imported translations
 * with the same writer (TranslationWriter), after creating missing language variants like
 * supertext:translate. The file formats and the import rules are in Files\FileTransfer.
 * Also used by the back-end module (Controller\TranslationFilesController), which adds
 * languages() and documents() to fill its form.
 */
#[Flow\Scope('singleton')]
class TranslationFileService
{
    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected SegmentCollector $segmentCollector;

    #[Flow\Inject]
    protected TranslationWriter $translationWriter;

    #[Flow\Inject]
    protected NodeVariants $nodeVariants;

    #[Flow\Inject]
    protected PendingTranslations $pendingTranslations;

    #[Flow\Inject]
    protected SecurityContext $securityContext;

    #[Flow\Inject]
    protected Settings $settings;

    #[Flow\Inject]
    protected NodeLabelGeneratorInterface $nodeLabelGenerator;

    /**
     * The source language (the language of the default dimension space point, as
     * supertext:export uses without --source) and every other value of the language dimension.
     *
     * @return array{source: array{value: string, label: string}, targets: list<array{value: string, label: string}>}
     */
    public function languages(string $contentRepository): array
    {
        $cr = $this->contentRepository($contentRepository);
        $dimensionId = new ContentDimensionId($this->settings->getLanguageDimension());
        $dimension = $cr->getContentDimensionSource()->getDimension($dimensionId);
        $source = (string)$this->nodeVariants->defaultDimensionSpacePoint($cr)->getCoordinate($dimensionId);
        $label = static fn ($value): string => (string)($value->getConfigurationValue('label') ?? $value->value);

        $result = ['source' => ['value' => $source, 'label' => $source], 'targets' => []];
        foreach ($dimension->values ?? [] as $value) {
            if ($value->value === $source) {
                $result['source']['label'] = $label($value);
                continue;
            }
            $result['targets'][] = ['value' => $value->value, 'label' => $label($value)];
        }
        return $result;
    }

    /** The node's label in a language (for the import summary), null when it isn't there. */
    public function label(string $nodeId, string $language, string $workspace, string $contentRepository): ?string
    {
        try {
            $cr = $this->contentRepository($contentRepository);
            $dsp = $this->dimensionSpacePoint($cr, new ContentDimensionId($this->settings->getLanguageDimension()), $language);
            $node = $this->subgraph($cr, $workspace, $dsp)->findNodeById(NodeAggregateId::fromString($nodeId));
        } catch (\Throwable) {
            return null;
        }
        return $node !== null ? $this->nodeLabelGenerator->getLabel($node) : null;
    }

    /**
     * The site's pages in the source language, in tree order (the site's home page first).
     *
     * @return list<array{id: string, label: string, depth: int}>
     */
    public function documents(string $siteNodeName, string $workspace, string $contentRepository): array
    {
        $cr = $this->contentRepository($contentRepository);
        $subgraph = $this->subgraph($cr, $workspace, $this->nodeVariants->defaultDimensionSpacePoint($cr));
        $sites = $subgraph->findRootNodeByType(NodeTypeNameFactory::forSites());
        $site = $sites !== null ? $subgraph->findNodeByPath(NodeName::fromString($siteNodeName), $sites->aggregateId) : null;
        if ($site === null) {
            return [];
        }
        $result = [];
        $add = function ($node, int $depth) use (&$add, &$result, $subgraph): void {
            $result[] = ['id' => $node->aggregateId->value, 'label' => $this->nodeLabelGenerator->getLabel($node), 'depth' => $depth];
            foreach ($subgraph->findChildNodes($node->aggregateId, FindChildNodesFilter::create(nodeTypes: NodeTypeNameFactory::NAME_DOCUMENT)) as $child) {
                $add($child, $depth + 1);
            }
        };
        $add($site, 0);
        return $result;
    }

    /**
     * @param list<string> $targets target dimension values (exactly one for HTML)
     * @param string|null  $source  source dimension value (default: the site's default language)
     *
     * @return array{name: string, type: string, body: string}
     *
     * @throws FileTransferException
     */
    public function export(string $nodeId, array $targets, string $format, ?string $source, string $workspace, string $contentRepository): array
    {
        $cr = $this->contentRepository($contentRepository);
        $dimension = new ContentDimensionId($this->settings->getLanguageDimension());
        $sourceDsp = $source !== null && $source !== ''
            ? $this->dimensionSpacePoint($cr, $dimension, $source)
            : $this->nodeVariants->defaultDimensionSpacePoint($cr);
        $source = (string)$sourceDsp->getCoordinate($dimension);
        foreach ($targets as $target) {
            if ($target === $source) {
                throw new FileTransferException('no_language', [$target]);
            }
            $this->dimensionSpacePoint($cr, $dimension, $target);
        }

        $nodes = $this->nodes($cr, $this->subgraph($cr, $workspace, $sourceDsp), $nodeId);
        return $this->files()->export($nodeId, $source, $this->segmentCollector->segments($nodes), $targets, $format);
    }

    /**
     * @param bool $checkPermissions true (back-end module): the writes run with the logged-in user's
     *                               permissions; false (CLI, no user): without authorization checks
     *
     * @return list<array<string, mixed>> FileTransfer::import() results
     *
     * @throws FileTransferException for the whole file (invalid_file, missing_metadata, too_large)
     */
    public function import(string $content, bool $includeChanged, string $workspace, string $contentRepository, bool $checkPermissions = false): array
    {
        $cr = $this->contentRepository($contentRepository);
        $dimension = new ContentDimensionId($this->settings->getLanguageDimension());
        $workspaceName = WorkspaceName::fromString($workspace);

        $current = function (array $ref) use ($cr, $dimension, $workspace): array {
            $this->dimensionSpacePoint($cr, $dimension, $ref['target']);
            $sourceDsp = $this->dimensionSpacePoint($cr, $dimension, $ref['source']);
            return $this->segmentCollector->segments($this->nodes($cr, $this->subgraph($cr, $workspace, $sourceDsp), $ref['node']));
        };

        $write = function (array $ref, array $translations) use ($cr, $dimension, $workspace, $workspaceName, $checkPermissions): array {
            $sourceDsp = $this->dimensionSpacePoint($cr, $dimension, $ref['source']);
            $targetDsp = $sourceDsp->vary($dimension, $ref['target']);
            $sourceSubgraph = $this->subgraph($cr, $workspace, $sourceDsp);
            $start = $sourceSubgraph->findNodeById(NodeAggregateId::fromString($ref['node']))
                ?? throw new FileTransferException('not_found');
            $withContent = $this->segmentCollector->isDocument($cr, $start);

            $written = ['nodes' => 0, 'fields' => 0];
            $run = function () use ($cr, $workspace, $workspaceName, $sourceSubgraph, $start, $targetDsp, $withContent, $translations, &$written): void {
                // Missing variants are created like "Create and copy" (with the source texts); the
                // import writes the translations, so nothing is queued for an API translation.
                $this->nodeVariants->adopt($cr, $workspaceName, $start->aggregateId, $sourceSubgraph, $this->subgraph($cr, $workspace, $targetDsp), $targetDsp, $withContent);
                $this->pendingTranslations->takeAll();

                $nodes = [];
                $this->segmentCollector->collect($cr, $sourceSubgraph, $start, $nodes, $withContent);
                $written = $this->translationWriter->write($cr, $workspaceName, $this->subgraph($cr, $workspace, $targetDsp), $nodes, SegmentCollector::group($translations));
            };
            if ($checkPermissions) {
                $run();
            } else {
                $this->securityContext->withoutAuthorizationChecks($run);
            }
            return $written;
        };

        return $this->files()->import($content, $includeChanged, $current, $write);
    }

    private function files(): FileTransfer
    {
        return new FileTransfer($this->settings->getLanguageMapping());
    }

    private function contentRepository(string $id): ContentRepository
    {
        return $this->contentRepositoryRegistry->get(ContentRepositoryId::fromString($id));
    }

    private function subgraph(ContentRepository $cr, string $workspace, DimensionSpacePoint $dsp): ContentSubgraphInterface
    {
        return $cr->getContentGraph(WorkspaceName::fromString($workspace))->getSubgraph($dsp, VisibilityConstraints::withoutRestrictions());
    }

    /** @throws FileTransferException no_language when the dimension has no such value */
    private function dimensionSpacePoint(ContentRepository $cr, ContentDimensionId $dimension, string $value): DimensionSpacePoint
    {
        if ($cr->getContentDimensionSource()->getDimension($dimension)?->getValue($value) === null) {
            throw new FileTransferException('no_language', [$value]);
        }
        return $this->nodeVariants->dimensionSpacePoint($cr, $dimension, $value);
    }

    /**
     * A page with its content, or a content node with its tethered children.
     *
     * @return array<string, array{0: \Neos\ContentRepository\Core\Projection\ContentGraph\Node, 1: array<string, bool>}>
     *
     * @throws FileTransferException not_found
     */
    private function nodes(ContentRepository $cr, ContentSubgraphInterface $subgraph, string $nodeId): array
    {
        try {
            $start = $subgraph->findNodeById(NodeAggregateId::fromString($nodeId));
        } catch (\InvalidArgumentException) {
            $start = null;
        }
        if ($start === null) {
            throw new FileTransferException('not_found');
        }
        $nodes = [];
        $this->segmentCollector->collect($cr, $subgraph, $start, $nodes, $this->segmentCollector->isDocument($cr, $start));
        return $nodes;
    }
}
