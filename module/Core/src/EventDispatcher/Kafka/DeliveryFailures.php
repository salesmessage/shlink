<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\EventDispatcher\Kafka;

use function implode;

final class DeliveryFailures
{
    /** @var int[] */
    private array $errorCodes = [];

    public function record(int $errorCode): void
    {
        $this->errorCodes[] = $errorCode;
    }

    public function reset(): void
    {
        $this->errorCodes = [];
    }

    public function hasAny(): bool
    {
        return ! empty($this->errorCodes);
    }

    public function summary(): string
    {
        return implode(', ', $this->errorCodes);
    }
}
