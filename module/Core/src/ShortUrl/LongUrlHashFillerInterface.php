<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\ShortUrl;

use Shlinkio\Shlink\Core\ShortUrl\Model\LongUrlHashFillResult;

interface LongUrlHashFillerInterface
{
    /**
     * @param callable(LongUrlHashFillResult): void|null $onBatch
     */
    public function fill(int $fromId, int $batchSize, callable|null $onBatch = null): LongUrlHashFillResult;

    public function countLeft(): int;
}
