<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\BotIp\Repository;

use Cake\Chronos\Chronos;
use Shlinkio\Shlink\Core\BotIp\Entity\BotIpRange;
use Shlinkio\Shlink\Core\BotIp\Model\BotIpSource;

interface BotIpRangeRepositoryInterface
{
    /**
     * @return array<string, string[]> CIDRs grouped by source
     */
    public function findAllCidrsBySource(): array;

    /**
     * @return array<string, BotIpRange> indexed by CIDR
     */
    public function findBySource(BotIpSource $source): array;

    public function findLastSeenAt(BotIpSource $source): ?Chronos;
}
