<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\BotIp;

use Cake\Chronos\Chronos;
use Shlinkio\Shlink\Core\BotIp\Model\ReclassificationResult;

interface VisitBotReclassifierInterface
{
    /**
     * Flags as potential bots the visits to short URLs since the date that today's rules would flag, in batches of
     * visits after the given id. With dry run, only counts them.
     *
     * @param (callable(ReclassificationResult): void)|null $onBatch
     */
    public function reclassify(
        Chronos $since,
        int $fromId,
        int $batchSize,
        bool $dryRun,
        callable|null $onBatch = null,
    ): ReclassificationResult;
}
