<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\ShortUrl\Model;

final readonly class LongUrlHashFillResult
{
    public function __construct(public int $filled, public int $lastId) {}
}
