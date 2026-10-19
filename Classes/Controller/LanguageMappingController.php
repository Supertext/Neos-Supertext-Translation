<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Controller;

use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Error\Messages\Message;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Service as LocalizationService;
use Neos\Flow\I18n\Translator;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Supertext\NeosTranslation\Configuration\PackageVersion;
use Supertext\NeosTranslation\Configuration\Settings;
use Supertext\NeosTranslation\Service\LanguageMapping;
use Supertext\NeosTranslation\Service\LanguageMappingStore;

/**
 * Back-end module Administration → Supertext: one combo box per value of the language
 * dimension with Supertext's target codes (and the tone where the code offers one), stored by
 * LanguageMappingStore. Codes and tones set in Settings.yaml (`languages.<value>.code` /
 * `politeness`) win and are shown read-only. The rules are in Service\LanguageMapping.
 */
class LanguageMappingController extends AbstractModuleController
{
    private const SOURCE = 'Main';
    private const PACKAGE = 'Supertext.NeosTranslation';

    #[Flow\Inject]
    protected Settings $supertextSettings;

    #[Flow\Inject]
    protected LanguageMappingStore $mappingStore;

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected LocalizationService $localizationService;

    #[Flow\Inject]
    protected Translator $translator;

    public function indexAction(): void
    {
        $version = PackageVersion::get();
        $this->view->assignMultiple([
            'rows' => LanguageMapping::rows($this->dimensionValues(), $this->mappingStore->get(), $this->supertextSettings->getLanguages()),
            'options' => LanguageMapping::options($this->localizationService->getConfiguration()->getCurrentLocale()->getLanguage()),
            'politenessOptions' => [
                'default' => $this->translate('mapping.politeness.default'),
                'more' => $this->translate('mapping.politeness.more'),
                'less' => $this->translate('mapping.politeness.less'),
            ],
            'politenessCodes' => implode(' ', LanguageMapping::politenessCodes()),
            'dimension' => $this->supertextSettings->getLanguageDimension(),
            'version' => $version,
            'versionUrl' => preg_match('/^\d+\.\d+\.\d+$/', $version) === 1
                ? 'https://github.com/Supertext/Neos-Supertext-Translation/releases/tag/v' . $version
                : null,
        ]);
    }

    /**
     * @param array $mapping mapping[<dimension value>][code|politeness] (plain "array": Flow's property mapper can't map array<mixed>)
     */
    public function saveAction(array $mapping = []): void
    {
        $values = array_map(static fn (array $language): string => $language['value'], $this->dimensionValues());
        $result = LanguageMapping::fromForm($mapping, $values, $this->mappingStore->get(), $this->supertextSettings->getLanguages());
        if ($result['invalid'] !== []) {
            $this->addFlashMessage($this->translate('mapping.invalid', [implode(', ', $result['invalid'])]), '', Message::SEVERITY_ERROR);
            $this->redirect('index');
        }
        $this->mappingStore->save($result['mapping']);
        $this->addFlashMessage($this->translate('mapping.saved'));
        $this->redirect('index');
    }

    /**
     * The language dimension's values of every content repository (one mapping serves them all).
     *
     * @return list<array{value: string, label: string}>
     */
    private function dimensionValues(): array
    {
        $languages = [];
        foreach ($this->contentRepositoryRegistry->getContentRepositoryIds() as $id) {
            try {
                $dimension = $this->contentRepositoryRegistry->get($id)->getContentDimensionSource()
                    ->getDimension(new ContentDimensionId($this->supertextSettings->getLanguageDimension()));
            } catch (\Throwable) {
                continue;
            }
            foreach ($dimension->values ?? [] as $value) {
                $label = $value->getConfigurationValue('label');
                $languages[$value->value] ??= ['value' => $value->value, 'label' => is_string($label) ? $label : ''];
            }
        }
        return array_values($languages);
    }

    /** @param list<string|int> $arguments */
    private function translate(string $id, array $arguments = []): string
    {
        return (string)($this->translator->translateById($id, $arguments, null, null, self::SOURCE, self::PACKAGE) ?? $id);
    }
}
