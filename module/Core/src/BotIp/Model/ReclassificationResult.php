<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\BotIp\Model;

final class ReclassificationResult
{
    public int $scanned = 0;
    public int $byUserAgent = 0;
    public int $byGoogleNetwork = 0;
    public int $lastId = 0;

    public function marked(): int
    {
        return $this->byUserAgent + $this->byGoogleNetwork;
    }
}
