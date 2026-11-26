<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\BotIp\Model;

final readonly class BotIpUpdateResult
{
    /**
     * @param string[] $failedSources
     */
    private function __construct(
        public bool $updated,
        public int $added = 0,
        public int $refreshed = 0,
        public array $failedSources = [],
    ) {}

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
        return $this->updated && $this->added === 0 && $this->refreshed === 0 && $this->failedSources !== [];
    }
}
