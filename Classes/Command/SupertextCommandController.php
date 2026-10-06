<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Command;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeVariation\Command\CreateNodeVariant;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Supertext\NeosTranslation\Api\SupertextClient;
use Supertext\NeosTranslation\Configuration\Settings;
use Supertext\NeosTranslation\Service\NodeTranslator;

/**
 * Supertext translation commands
 */
class SupertextCommandController extends CommandController
{
    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected NodeTranslator $nodeTranslator;

    #[Flow\Inject]
    protected SupertextClient $client;

    #[Flow\Inject]
    protected Settings $settings;

    #[Flow\Inject]
    protected SecurityContext $securityContext;

    /**
     * Translate a page and its content into another language
     *
     * Does what "Create and copy" does in the Neos UI: creates the page (and any
     * missing parent pages) in the target language, copies its content elements, and
     * translates all of it with Supertext in one request.
     *
     * Example: ./flow supertext:translate --node 3c9a4f6d-... --language fr
     *
     * @param string $node Node aggregate id of the page
     * @param string $language Target language dimension value, e.g. fr
     * @param string|null $source Source language dimension value (default: the site's default language)
     * @param string $workspace Workspace to work in. "live" publishes immediately.
     * @param bool $subpages Also translate all subpages
     * @param bool $copyContent Copy and translate the content elements, not just the page properties
     * @param string $contentRepository Content repository id
     */
    public function translateCommand(
        string $node,
        string $language,
        ?string $source = null,
        string $workspace = 'live',
        bool $subpages = false,
        bool $copyContent = true,
        string $contentRepository = 'default',
    ): void {
        $cr = $this->contentRepositoryRegistry->get(ContentRepositoryId::fromString($contentRepository));
        $dimension = new ContentDimensionId($this->settings->getLanguageDimension());
        $workspaceName = WorkspaceName::fromString($workspace);
        $graph = $cr->getContentGraph($workspaceName);

        $sourceDsp = $source !== null
            ? $this->dimensionSpacePoint($cr, $dimension, $source)
            : $this->defaultDimensionSpacePoint($cr);
        $targetDsp = $sourceDsp->vary($dimension, $language);
        $sourceSubgraph = $graph->getSubgraph($sourceDsp, VisibilityConstraints::withoutRestrictions());
        $targetSubgraph = $graph->getSubgraph($targetDsp, VisibilityConstraints::withoutRestrictions());

        $start = $sourceSubgraph->findNodeById(NodeAggregateId::fromString($node));
        if ($start === null) {
            $this->outputLine('<error>Node %s not found in %s.</error>', [$node, $sourceDsp->toJson()]);
            $this->quit(1);
        }

        $this->securityContext->withoutAuthorizationChecks(function () use ($cr, $workspaceName, $start, $sourceSubgraph, $targetSubgraph, $targetDsp, $subpages, $copyContent) {
            $created = $this->adopt($cr, $workspaceName, $start->aggregateId, $sourceSubgraph, $targetSubgraph, $targetDsp, $copyContent);
            if ($subpages) {
                foreach ($this->descendants($cr, $sourceSubgraph, $start->aggregateId, true) as $page) {
                    $created += $this->adopt($cr, $workspaceName, $page, $sourceSubgraph, $targetSubgraph, $targetDsp, $copyContent);
                }
            }
            $this->outputLine('Created %d node variant(s) in %s; translating with Supertext...', [$created, $targetDsp->toJson()]);

            $failed = false;
            foreach ($this->nodeTranslator->translatePending() as $result) {
                if ($result->error !== null) {
                    $failed = true;
                    $this->outputLine('<error>%s -> %s: %s</error>', [$result->sourceLanguage, $result->targetLanguage, $result->error]);
                } else {
                    $this->outputLine('<success>%s -> %s: translated %d field(s) in %d node(s).</success>', [$result->sourceLanguage, $result->targetLanguage, $result->fields, $result->nodes]);
                }
            }
            if ($failed) {
                $this->quit(1);
            }
        });
    }

    /**
     * Check the Supertext API key and endpoint
     */
    public function checkCommand(): void
    {
        $this->outputLine('Endpoint: %s', [$this->settings->getBaseUrl()]);
        if (!$this->client->hasApiKey()) {
            $this->outputLine('<error>No API key configured (SUPERTEXT_API_KEY or Supertext.NeosTranslation.apiKey).</error>');
            $this->outputLine('No Supertext account yet? Create one at https://www.supertext.com/person/en/account/signin');
            $this->outputLine('Generate your API key at supertext.com > Integrations > API (requires the Admin role): https://www.supertext.com/en/integrations/api');
            $this->quit(1);
        }
        try {
            $this->client->validateApiKey();
            $this->outputLine('<success>API key accepted.</success>');
        } catch (\Throwable $e) {
            $this->outputLine('<error>%s</error>', [$e->getMessage()]);
            $this->quit(1);
        }
    }

    /** Creates missing variants of the node, its missing parents and (optionally) its content. */
    private function adopt(ContentRepository $cr, WorkspaceName $workspace, NodeAggregateId $id, ContentSubgraphInterface $source, ContentSubgraphInterface $target, DimensionSpacePoint $targetDsp, bool $copyContent): int
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
    private function descendants(ContentRepository $cr, ContentSubgraphInterface $subgraph, NodeAggregateId $parent, bool $documents): array
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

    private function dimensionSpacePoint(ContentRepository $cr, ContentDimensionId $dimension, string $value): DimensionSpacePoint
    {
        return $this->defaultDimensionSpacePoint($cr)->vary($dimension, $value);
    }

    private function defaultDimensionSpacePoint(ContentRepository $cr): DimensionSpacePoint
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
