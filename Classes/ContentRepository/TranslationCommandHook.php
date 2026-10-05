<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\ContentRepository;

use Neos\ContentRepository\Core\CommandHandler\CommandHookInterface;
use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\CommandHandler\Commands;
use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\EventStore\PublishedEvents;
use Neos\ContentRepository\Core\Feature\NodeVariation\Command\CreateNodeVariant;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Supertext\NeosTranslation\Configuration\Settings;
use Supertext\NeosTranslation\Domain\PendingTranslation;
use Supertext\NeosTranslation\Domain\PendingTranslations;
use Supertext\NeosTranslation\Service\LanguageResolver;

/**
 * Notices node variants created in another language and queues them for translation.
 * It never changes or adds commands itself; the texts are translated in one batch
 * after the request (TranslatePendingMiddleware).
 */
final class TranslationCommandHook implements CommandHookInterface
{
    public function __construct(
        private readonly ContentRepositoryId $contentRepositoryId,
        private readonly PendingTranslations $pendingTranslations,
        private readonly LanguageResolver $languageResolver,
        private readonly Settings $settings,
    ) {}

    public function onBeforeHandle(CommandInterface $command): CommandInterface
    {
        return $command;
    }

    public function onAfterHandle(CommandInterface $command, PublishedEvents $events): Commands
    {
        if (!$command instanceof CreateNodeVariant || !$this->settings->isEnabled()) {
            return Commands::createEmpty();
        }

        $dimension = new ContentDimensionId($this->settings->getLanguageDimension());
        $source = $command->sourceOrigin->toDimensionSpacePoint()->getCoordinate($dimension);
        $target = $command->targetOrigin->toDimensionSpacePoint()->getCoordinate($dimension);
        if ($source !== null && $target !== null && $this->languageResolver->needsTranslation($source, $target)) {
            $this->pendingTranslations->add(new PendingTranslation(
                $this->contentRepositoryId,
                $command->workspaceName,
                $command->nodeAggregateId,
                $command->sourceOrigin,
                $command->targetOrigin,
            ));
        }
        return Commands::createEmpty();
    }
}
