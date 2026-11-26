<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\BotIp\Entity;

use Cake\Chronos\Chronos;
use Shlinkio\Shlink\Common\Entity\AbstractEntity;
use Shlinkio\Shlink\Core\BotIp\Model\BotIpSource;

/**
 * A CIDR range a source publishes for its bots. Visits from it are tracked as potential bots.
 */
class BotIpRange extends AbstractEntity
{
    private function __construct(
        private readonly string $ip,
        private readonly string $source,
        private readonly Chronos $createdAt,
        private Chronos $updatedAt,
    ) {}

    public static function seenNow(string $ip, BotIpSource $source, Chronos $now): self
    {
        return new self($ip, $source->value, $now, $now);
    }

    public function markSeen(Chronos $now): void
    {
        $this->updatedAt = $now;
    }

    public function ip(): string
    {
        return $this->ip;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function createdAt(): Chronos
    {
        return $this->createdAt;
    }

    public function updatedAt(): Chronos
    {
        return $this->updatedAt;
    }
}
