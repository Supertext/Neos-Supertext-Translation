<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Reporting;

/**
 * Where the reporter's state (JSON) and the last known site URL are kept. Implementations
 * may throw; Reporting catches everything, so storage problems never break a request.
 */
interface StateStorage
{
    public function get(string $key): ?string;

    public function set(string $key, string $value): void;
}
