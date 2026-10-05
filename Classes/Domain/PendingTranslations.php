<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Domain;

use Neos\Flow\Annotations as Flow;

/**
 * Collects node variants created during the current request or CLI command.
 *
 * The Neos UI's "Create and copy" sends one CreateNodeVariant command per content
 * element; translating each one on its own would mean one Supertext round trip per
 * element. They are collected here and translated together afterwards
 * (see TranslatePendingMiddleware and the supertext:translate command).
 */
#[Flow\Scope('singleton')]
class PendingTranslations
{
    /** @var array<string, PendingTranslation> */
    private array $items = [];

    public function add(PendingTranslation $item): void
    {
        $this->items[$item->batchKey() . '|' . $item->nodeAggregateId->value] = $item;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /** @return list<PendingTranslation> all collected items; the buffer is emptied */
    public function takeAll(): array
    {
        $items = array_values($this->items);
        $this->items = [];
        return $items;
    }
}
