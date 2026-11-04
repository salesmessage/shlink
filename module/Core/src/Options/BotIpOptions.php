<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\Options;

final class BotIpOptions
{
    public function __construct(
        /** @var string[] */
        public readonly array $googleSources = [],
        public readonly int $refreshAfterDays = 7,
        public readonly int $checkIntervalSeconds = 600,
    ) {
    }
}
