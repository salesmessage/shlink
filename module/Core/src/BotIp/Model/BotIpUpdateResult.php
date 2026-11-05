<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\BotIp\Model;

final class BotIpUpdateResult
{
    private function __construct(
        public readonly bool $updated,
        public readonly int $added = 0,
        public readonly int $refreshed = 0,
        /** @var string[] */
        public readonly array $failedSources = [],
    ) {
    }

    public static function skipped(): self
    {
        return new self(false);
    }

    /**
     * @param string[] $failedSources
     */
    public static function updated(int $added, int $refreshed, array $failedSources): self
    {
        return new self(true, $added, $refreshed, $failedSources);
    }

    public function allSourcesFailed(): bool
    {
        return $this->updated && $this->added === 0 && $this->refreshed === 0 && ! empty($this->failedSources);
    }
}
