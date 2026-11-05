<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\EventDispatcher;

use Cake\Chronos\Chronos;
use Psr\Log\LoggerInterface;
use Shlinkio\Shlink\Core\BotIp\BotIpRangesUpdaterInterface;
use Shlinkio\Shlink\Core\Options\BotIpOptions;
use Throwable;

use function implode;

/**
 * Refreshes the Google bot IP ranges the way UpdateGeoLiteDb refreshes the GeoLite2 db: on a visit, when the list
 * is stale. Each worker checks at most once per interval, so a visit does not cost a query.
 */
class UpdateBotIpRanges
{
    private ?int $lastCheckAt = null;

    public function __construct(
        private readonly BotIpRangesUpdaterInterface $updater,
        private readonly BotIpOptions $options,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        $now = Chronos::now()->getTimestamp();
        if ($this->lastCheckAt !== null && $now - $this->lastCheckAt < $this->options->checkIntervalSeconds) {
            return;
        }
        $this->lastCheckAt = $now;

        try {
            $result = $this->updater->updateIfStale();
        } catch (Throwable $e) {
            $this->logger->error('Bot IP ranges update failed. {e}', ['e' => $e]);
            return;
        }

        if (! $result->updated) {
            return;
        }

        $this->logger->notice('Bot IP ranges updated: {added} added, {refreshed} refreshed.', [
            'added' => $result->added,
            'refreshed' => $result->refreshed,
        ]);
        if (! empty($result->failedSources)) {
            $this->logger->warning('Bot IP ranges not downloaded from {sources}.', [
                'sources' => implode(', ', $result->failedSources),
            ]);
        }
    }
}
