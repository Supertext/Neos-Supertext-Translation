<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Command;

use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Security\Context as SecurityContext;
use Supertext\NeosTranslation\Configuration\PackageVersion;
use Supertext\NeosTranslation\Configuration\Settings;
use Supertext\NeosTranslation\Files\FileTransfer;
use Supertext\NeosTranslation\Files\FileTransferException;
use Supertext\NeosTranslation\PluginHealth\KeyCheck;
use Supertext\NeosTranslation\Reporting\KeyCheckMessages;
use Supertext\NeosTranslation\Reporting\Reporting;
use Supertext\NeosTranslation\Reporting\ReportingService;
use Supertext\NeosTranslation\Sdk\V1\SupertextException;
use Supertext\NeosTranslation\Service\ErrorMessages;
use Supertext\NeosTranslation\Service\LanguageResolver;
use Supertext\NeosTranslation\Service\NodeTranslator;
use Supertext\NeosTranslation\Service\NodeVariants;
use Supertext\NeosTranslation\Service\TranslationFileService;

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

    #[Flow\Inject]
    protected NodeVariants $nodeVariants;

    #[Flow\Inject]
    protected TranslationFileService $translationFiles;

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
            ? $this->nodeVariants->dimensionSpacePoint($cr, $dimension, $source)
            : $this->nodeVariants->defaultDimensionSpacePoint($cr);
        $targetDsp = $sourceDsp->vary($dimension, $language);
        $sourceSubgraph = $graph->getSubgraph($sourceDsp, VisibilityConstraints::withoutRestrictions());
        $targetSubgraph = $graph->getSubgraph($targetDsp, VisibilityConstraints::withoutRestrictions());

        $start = $sourceSubgraph->findNodeById(NodeAggregateId::fromString($node));
        if ($start === null) {
            $this->outputLine('<error>Node %s not found in %s.</error>', [$node, $sourceDsp->toJson()]);
            $this->quit(1);
        }

        $this->securityContext->withoutAuthorizationChecks(function () use ($cr, $workspaceName, $start, $sourceSubgraph, $targetSubgraph, $targetDsp, $subpages, $copyContent) {
            $created = $this->nodeVariants->adopt($cr, $workspaceName, $start->aggregateId, $sourceSubgraph, $targetSubgraph, $targetDsp, $copyContent);
            if ($subpages) {
                foreach ($this->nodeVariants->descendants($cr, $sourceSubgraph, $start->aggregateId, true) as $page) {
                    $created += $this->nodeVariants->adopt($cr, $workspaceName, $page, $sourceSubgraph, $targetSubgraph, $targetDsp, $copyContent);
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
     * Export a page or content node for translation (XLIFF 1.2 or HTML)
     *
     * Writes the texts Supertext would translate (the page, its content elements and their
     * tethered children) to a file for a translation tool, a translator or Supertext's web
     * app. Import the translated file with supertext:import. Nothing is sent to Supertext.
     *
     * Examples:
     *   ./flow supertext:export 3c9a4f6d-... fr it                  (XLIFF, both languages in one file)
     *   ./flow supertext:export 3c9a4f6d-... fr --format=html --output=page-fr.html
     *
     * @param string $node Node aggregate id of the page or content node
     * @param string $languages Target language dimension value(s), e.g. fr (more as further arguments or comma-separated; exactly one for HTML)
     * @param string $format xliff (for translation tools, default) or html (one language per file)
     * @param string|null $output File to write (default: neos-<node>-<language>.xlf or .html in the current directory; - for standard output)
     * @param string|null $source Source language dimension value (default: the site's default language)
     * @param string $workspace Workspace to read from
     * @param string $contentRepository Content repository id
     */
    public function exportCommand(
        string $node,
        string $languages,
        string $format = 'xliff',
        ?string $output = null,
        ?string $source = null,
        string $workspace = 'live',
        string $contentRepository = 'default',
    ): void {
        $targets = [];
        foreach ([$languages, ...$this->request->getExceedingArguments()] as $argument) {
            foreach (explode(',', (string)$argument) as $target) {
                if (trim($target) !== '') {
                    $targets[] = trim($target);
                }
            }
        }
        try {
            $file = $this->translationFiles->export($node, $targets, strtolower($format), $source, $workspace, $contentRepository);
        } catch (FileTransferException $e) {
            $this->outputLine('<error>%s</error>', [$e->getMessage()]);
            $this->quit(1);
        }
        if ($output === '-') {
            $this->output($file['body']);
            return;
        }
        $path = $output ?? $file['name'];
        if (@file_put_contents($path, $file['body']) === false) {
            $this->outputLine('<error>Could not write %s.</error>', [$path]);
            $this->quit(1);
        }
        $this->outputLine('<success>Exported for translation: %s (%s)</success>', [$path, $file['type']]);
    }

    /**
     * Import a translated XLIFF or HTML file
     *
     * Reads a file exported with supertext:export and writes the translations into the
     * target language, like a translation with Supertext: missing language variants are
     * created first (as "Create and copy" does). Prints a summary per language. Texts whose
     * source changed since the export are skipped unless --include-changed is given.
     *
     * Example: ./flow supertext:import neos-3c9a4f6d-...-fr.xlf
     *
     * @param string $file The translated file (XLIFF or HTML, at most 10 MB)
     * @param bool $includeChanged Also import texts whose source changed since the export
     * @param string $workspace Workspace to write to. "live" publishes immediately.
     * @param string $contentRepository Content repository id
     */
    public function importCommand(string $file, bool $includeChanged = false, string $workspace = 'live', string $contentRepository = 'default'): void
    {
        try {
            if (!is_file($file) || !is_readable($file)) {
                $this->outputLine('<error>Can\'t read %s.</error>', [$file]);
                $this->quit(1);
            }
            // Refuse large files before reading them.
            FileTransfer::assertFileSize((int)filesize($file));
            $results = $this->translationFiles->import((string)file_get_contents($file), $includeChanged, $workspace, $contentRepository);
        } catch (FileTransferException $e) {
            $this->outputLine('<error>%s</error>', [$e->getMessage()]);
            $this->quit(1);
        }

        $failed = false;
        foreach ($results as $result) {
            $this->outputLine('%s -> %s (node %s):', [$result['source'] ?: '?', $result['target'] ?: '?', $result['node'] ?: $result['reference']]);
            if (isset($result['error'])) {
                $failed = true;
                $this->outputLine('  <error>%s</error>', [ErrorMessages::forException($result['error'], $result['target'])]);
                continue;
            }
            foreach (FileTransfer::summary($result) as $line) {
                $this->outputLine('  %s', [$line]);
            }
        }
        if ($failed) {
            $this->quit(1);
        }
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
        $this->outputLine('Review service (Translation review): %s', [$this->settings->getReviewUrl() !== '' ? $this->settings->getReviewUrl() : 'off (reviewUrl is empty)']);

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
            $line = sprintf(
                '  %s → %s%s%s',
                $value->value,
                $code,
                $politeness !== 'default' ? ' (' . $politeness . ')' : '',
                $this->languageResolver->review($value->value) ? ', Translation review' : '',
            );
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
}
