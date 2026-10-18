<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Controller;

use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Translator;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Neos\Neos\Domain\Service\UserService;
use Neos\Neos\Domain\Service\WorkspaceService;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;
use Psr\Http\Message\UploadedFileInterface;
use Supertext\NeosTranslation\Files\FileTransfer;
use Supertext\NeosTranslation\Files\FileTransferException;
use Supertext\NeosTranslation\Service\ErrorMessages;
use Supertext\NeosTranslation\Service\TranslationFileService;

/**
 * Back-end module Management → Supertext: Export for translation / Import translation, the
 * same as supertext:export / supertext:import, but in the current user's personal workspace
 * (editors review and publish the imported translations in the Neos UI as usual).
 *
 * Export answers with the file (format "download": Neos's module controller then returns
 * the response as it is instead of wrapping it in the back-end layout). Uploads are read from
 * the request without Flow's resource management, so nothing is stored.
 */
class TranslationFilesController extends AbstractModuleController
{
    private const SOURCE = 'Main';
    private const PACKAGE = 'Supertext.NeosTranslation';

    #[Flow\Inject]
    protected TranslationFileService $translationFiles;

    #[Flow\Inject]
    protected UserService $userService;

    #[Flow\Inject]
    protected WorkspaceService $workspaceService;

    #[Flow\Inject]
    protected Translator $translator;

    public function indexAction(): void
    {
        $this->assignForm();
    }

    /**
     * @param string $node node aggregate id of the page
     * @param array<string> $languages target dimension values (Flow's property mapper needs array<…>, not list<…>)
     * @param string $format xliff or html
     */
    public function exportAction(string $node = '', array $languages = [], string $format = FileTransfer::XLIFF): string
    {
        [$contentRepository, $workspace] = $this->context();
        try {
            $file = $this->translationFiles->export($node, array_values(array_map('strval', $languages)), $format, null, $workspace, $contentRepository);
        } catch (\Throwable $e) {
            $this->addFlashMessage($this->message($e), '', \Neos\Error\Messages\Message::SEVERITY_ERROR);
            $this->redirect('index', null, null, ['node' => $node, 'format' => $format], 0, 303, 'html');
        }
        $this->response->setContentType($file['type']);
        $this->response->setHttpHeader('Content-Disposition', sprintf('attachment; filename="%s"', $file['name']));
        $this->response->setHttpHeader('X-Content-Type-Options', 'nosniff');
        $this->response->setHttpHeader('Cache-Control', 'no-store');
        return $file['body'];
    }

    public function importAction(bool $includeChanged = false): void
    {
        [$contentRepository, $workspace] = $this->context();
        $upload = $this->upload();
        $results = null;
        $error = null;
        $imported = false;
        try {
            if ($upload === null || $upload->getError() === UPLOAD_ERR_NO_FILE) {
                $error = $this->translate('import.noFile');
            } elseif (in_array($upload->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw new FileTransferException('too_large');
            } elseif ($upload->getError() !== UPLOAD_ERR_OK) {
                throw new FileTransferException('invalid_file');
            } else {
                // Refuse large files before reading them.
                FileTransfer::assertFileSize((int)$upload->getSize());
                $content = $upload->getStream()->getContents();
                $results = [];
                $languages = $this->translationFiles->languages($contentRepository);
                $names = array_column([$languages['source'], ...$languages['targets']], 'label', 'value');
                foreach ($this->translationFiles->import($content, $includeChanged, $workspace, $contentRepository, true) as $result) {
                    $imported = $imported || ($result['counts']['imported'] ?? 0) > 0;
                    $page = $result['node'] !== '' ? $this->translationFiles->label($result['node'], $result['source'], $workspace, $contentRepository) : null;
                    $results[] = $this->jobView($result, $names, $page);
                }
            }
        } catch (\Throwable $e) {
            $error = $this->message($e);
        }
        $this->assignForm();
        $this->view->assignMultiple(['importError' => $error, 'results' => $results, 'imported' => $imported, 'includeChanged' => $includeChanged]);
    }

    private function assignForm(): void
    {
        [$contentRepository, $workspace, $siteNodeName] = $this->context();
        $arguments = $this->request->getArguments();
        $this->view->assignMultiple([
            'languages' => $this->translationFiles->languages($contentRepository),
            'documents' => array_map(
                static fn (array $d): array => $d + ['indentedLabel' => str_repeat("\u{00A0}\u{00A0}\u{00A0}", $d['depth']) . $d['label']],
                $this->translationFiles->documents($siteNodeName, $workspace, $contentRepository),
            ),
            'selectedNode' => (string)($arguments['node'] ?? ''),
            'selectedFormat' => (string)($arguments['format'] ?? FileTransfer::XLIFF),
            'formats' => [
                FileTransfer::XLIFF => $this->translate('export.format.xliff'),
                FileTransfer::HTML => $this->translate('export.format.html'),
            ],
        ]);
    }

    /**
     * The content repository and site of the request, and the user's personal workspace
     * (created if the user has none yet, as Neos does at login).
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function context(): array
    {
        $site = SiteDetectionResult::fromRequest($this->request->getHttpRequest());
        $user = $this->userService->getCurrentUser();
        if ($user === null) {
            throw new \RuntimeException('No backend user.', 1760800001);
        }
        $this->workspaceService->createPersonalWorkspaceForUserIfMissing($site->contentRepositoryId, $user);
        $workspace = $this->workspaceService->getPersonalWorkspaceForUser($site->contentRepositoryId, $user->getId());
        return [$site->contentRepositoryId->value, $workspace->workspaceName->value, $site->siteNodeName->value];
    }

    /** The uploaded file of the form field "file" (in the module's argument namespace). */
    private function upload(): ?UploadedFileInterface
    {
        $files = $this->request->getHttpRequest()->getUploadedFiles();
        $candidate = $files['moduleArguments']['file'] ?? $files['file'] ?? null;
        return $candidate instanceof UploadedFileInterface ? $candidate : null;
    }

    /**
     * @param array<string, mixed>  $result one result of FileTransfer::import()
     * @param array<string, string> $names  dimension value → label
     * @param string|null           $page   the page's label, null when unknown
     *
     * @return array{heading: string, error: ?string, lines: list<string>}
     */
    private function jobView(array $result, array $names, ?string $page): array
    {
        $language = static fn (string $value): string => $value === '' ? '?' : ($names[$value] ?? $value);
        $heading = $this->translate('import.job', [
            $language((string)$result['source']),
            $language((string)$result['target']),
            $page ?? ($result['node'] !== '' ? $result['node'] : $result['reference']),
        ]);
        if (isset($result['error'])) {
            return ['heading' => $heading, 'error' => $this->message($result['error'], $result['target']), 'lines' => []];
        }
        $counts = $result['counts'];
        $lines = [$this->translate('summary.ok', [$counts['imported']])];
        foreach (['changed', 'untranslated', 'invalid', 'missing'] as $key) {
            if ($counts[$key] > 0) {
                $lines[] = $this->translate('summary.' . $key, [$counts[$key]]);
            }
        }
        return ['heading' => $heading, 'error' => null, 'lines' => $lines];
    }

    /** The message of an error in the user's interface language (file errors) or English (others, as in the log). */
    private function message(\Throwable $e, ?string $target = null): string
    {
        if ($e instanceof FileTransferException) {
            $id = 'error.' . $e->reason;
            $text = $this->translate($id, $e->args);
            return $text !== $id ? $text : $e->getMessage();
        }
        return ErrorMessages::forException($e, $target);
    }

    /** @param list<string|int> $arguments */
    private function translate(string $id, array $arguments = []): string
    {
        return (string)($this->translator->translateById($id, $arguments, null, null, self::SOURCE, self::PACKAGE) ?? $id);
    }
}
