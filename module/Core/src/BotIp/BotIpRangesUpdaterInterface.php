<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\BotIp;

use Shlinkio\Shlink\Core\BotIp\Model\BotIpUpdateResult;

interface BotIpRangesUpdaterInterface
{
    /**
     * Downloads the Google lists only when none is stored yet or the newest range is older than the configured age.
     */
    public function updateIfStale(): BotIpUpdateResult;

    /**
     * Downloads the Google lists now.
     */
    public function update(): BotIpUpdateResult;
}
