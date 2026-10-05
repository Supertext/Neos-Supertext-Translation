<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Domain;

use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;

/**
 * A node variant that was just created in another language and still needs its
 * texts translated.
 */
final readonly class PendingTranslation
{
    public function __construct(
        public ContentRepositoryId $contentRepositoryId,
        public WorkspaceName $workspaceName,
        public NodeAggregateId $nodeAggregateId,
        public OriginDimensionSpacePoint $sourceOrigin,
        public OriginDimensionSpacePoint $targetOrigin,
    ) {}

    /** Requests that can share one Supertext document. */
    public function batchKey(): string
    {
        return implode('|', [
            $this->contentRepositoryId->value,
            $this->workspaceName->value,
            $this->sourceOrigin->toJson(),
            $this->targetOrigin->toJson(),
        ]);
    }
}
