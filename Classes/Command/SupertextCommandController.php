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
use Supertext\NeosTranslation\Configuration\PackageVersion;
use Supertext\NeosTranslation\Configuration\Settings;
use Supertext\NeosTranslation\PluginHealth\KeyCheck;
use Supertext\NeosTranslation\Reporting\KeyCheckMessages;
use Supertext\NeosTranslation\Reporting\Reporting;
use Supertext\NeosTranslation\Reporting\ReportingService;
use Supertext\NeosTranslation\Sdk\V1\SupertextException;
use Supertext\NeosTranslation\Service\ErrorMessages;
use Supertext\NeosTranslation\Service\LanguageResolver;
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
    protected LanguageResolver $languageResolver;

    #[Flow\Inject]
    protected ReportingService $reportingService;

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
            $this->reportingService->onRequest();

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
     * Show the package version, the languages and the API key check; send a check report
     *
     * Checks the API key with Supertext (cost-free, nothing is translated), lists every
     * language dimension value with the Supertext code it is translated with, and, while
     * version reports are on, sends a "check" report to Supertext.
     *
     * @param string $contentRepository Content repository id
     */
    public function checkCommand(string $contentRepository = 'default'): void
    {
        $this->outputLine(PackageVersion::label());
        $this->outputLine('Endpoint: %s', [$this->settings->getBaseUrl()]);

        $languagesOk = $this->outputLanguages($contentRepository);

        $reportsOn = $this->reportingService->isEnabled();
        $siteUrl = $this->reportingService->siteUrl();
        ['check' => $check, 'report' => $report] = $this->reportingService->check();
        $keyOk = $check->status === KeyCheck::OK;
        $this->outputLine();
        $this->outputLine(
            $keyOk ? '<success>%s</success>' : '<error>%s</error>',
            [KeyCheckMessages::for($check) . ' (' . $check->checkedAt . ')'],
        );

        $this->outputLine();
        $this->outputLine('Version information to Supertext: %s', [$reportsOn ? 'on' : 'off (versionReports: false or SUPERTEXT_VERSION_REPORTS=0)']);
        if ($reportsOn) {
            $this->outputLine('Site URL: %s', [$siteUrl ?? 'unknown: set Neos.Flow.http.baseUri or a primary domain for the site, or open the Neos back end once']);
            if ($siteUrl !== null) {
                $this->outputLine('Ping endpoint: %s', [Reporting::callbackUrl($siteUrl)]);
                $this->outputLine('Check report: %s', [$report?->sent ? 'sent' : 'not sent (Supertext can\'t be reached; nothing else is affected)']);
            }
        }

        if (!$keyOk || !$languagesOk) {
            $this->quit(1);
        }
    }

    /** Lists the language dimension values and their Supertext codes; false when one has none. */
    private function outputLanguages(string $contentRepository): bool
    {
        try {
            $cr = $this->contentRepositoryRegistry->get(ContentRepositoryId::fromString($contentRepository));
            $dimension = $cr->getContentDimensionSource()->getDimension(new ContentDimensionId($this->settings->getLanguageDimension()));
        } catch (\Throwable $e) {
            $this->outputLine('Languages: not available (%s)', [$e->getMessage()]);
            return true;
        }
        if ($dimension === null) {
            $this->outputLine('Languages: no dimension "%s" in content repository "%s".', [$this->settings->getLanguageDimension(), $contentRepository]);
            return true;
        }
        $ok = true;
        $this->outputLine();
        $this->outputLine('Languages (dimension value → Supertext code):');
        foreach ($dimension->values as $value) {
            $code = $this->languageResolver->targetCodeOrNull($value->value);
            if ($code === null) {
                $ok = false;
                $this->outputLine('  <error>%s → not mapped</error>: %s', [$value->value, ErrorMessages::forException(
                    new SupertextException(SupertextException::UNSUPPORTED_LANGUAGE, targetLang: $value->value)
                )]);
                continue;
            }
            $politeness = $this->languageResolver->politeness($value->value);
            $line = sprintf('  %s → %s%s', $value->value, $code, $politeness !== 'default' ? ' (' . $politeness . ')' : '');
            $this->outputLine($line);
            if (LanguageResolver::politenessUnsupported($code, $politeness)) {
                $this->outputLine('    <comment>%s</comment>', [ErrorMessages::forException(
                    new SupertextException(SupertextException::UNSUPPORTED_FEATURE, feature: 'politeness'),
                    $value->value,
                )]);
            }
        }
        return $ok;
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
