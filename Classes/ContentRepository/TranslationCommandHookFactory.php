<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\ContentRepository;

use Neos\ContentRepository\Core\CommandHandler\CommandHookInterface;
use Neos\ContentRepository\Core\Factory\CommandHookFactoryInterface;
use Neos\ContentRepository\Core\Factory\CommandHooksFactoryDependencies;
use Neos\Flow\Annotations as Flow;
use Supertext\NeosTranslation\Configuration\Settings;
use Supertext\NeosTranslation\Domain\PendingTranslations;
use Supertext\NeosTranslation\Service\LanguageResolver;

#[Flow\Scope('singleton')]
class TranslationCommandHookFactory implements CommandHookFactoryInterface
{
    #[Flow\Inject]
    protected PendingTranslations $pendingTranslations;

    #[Flow\Inject]
    protected LanguageResolver $languageResolver;

    #[Flow\Inject]
    protected Settings $settings;

    public function build(CommandHooksFactoryDependencies $commandHooksFactoryDependencies): CommandHookInterface
    {
        return new TranslationCommandHook(
            $commandHooksFactoryDependencies->contentRepositoryId,
            $this->pendingTranslations,
            $this->languageResolver,
            $this->settings,
        );
    }
}
