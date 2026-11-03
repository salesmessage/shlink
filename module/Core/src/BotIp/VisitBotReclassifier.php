<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\BotIp;

use Cake\Chronos\Chronos;
use Shlinkio\Shlink\Core\BotIp\Model\BotIpSource;
use Shlinkio\Shlink\Core\BotIp\Model\ReclassificationResult;
use Shlinkio\Shlink\Core\Options\BotIpOptions;
use Shlinkio\Shlink\Core\Visit\Repository\BotReclassificationQueryInterface;

use function count;
use function in_array;
use function max;
use function Shlinkio\Shlink\Core\isCrawler;

/**
 * One-off pass over past visits to short URLs, flagging as potential bots the ones today's rules would flag. Stored
 * addresses keep only their /24 network, so the address rules work on that network:
 * - Google: the network overlaps a Google range; those networks belong to Google as a whole.
 * - Scanner: the network of a seeded scanner address AND one of the scanner's exact user agents, because those
 *   networks are shared with real mobile subscribers.
 * The flag is only ever set, never cleared.
 */
class VisitBotReclassifier
{
    private const RULE_USER_AGENT = 'user_agent';
    private const RULE_GOOGLE_NETWORK = 'google_network';
    private const RULE_SCANNER = 'scanner';

    public function __construct(
        private readonly BotReclassificationQueryInterface $query,
        private readonly BotIpMatcherInterface $matcher,
        private readonly BotIpOptions $options,
    ) {
    }

    /**
     * @param callable(ReclassificationResult): void|null $onBatch
     */
    public function reclassify(
        Chronos $since,
        int $fromId,
        int $batchSize,
        bool $dryRun,
        ?callable $onBatch = null,
    ): ReclassificationResult {
        $result = new ReclassificationResult();
        // Start at the first visit since the date: walking older ids first would read the whole history
        $firstIdSince = $this->query->findFirstVisitIdSince($since);
        $result->lastId = $firstIdSince === null ? $fromId : max($fromId, $firstIdSince - 1);

        do {
            $visits = $this->query->findUnflaggedShortUrlVisits($since, $result->lastId, $batchSize);
            $botIds = [];
            foreach ($visits as $visit) {
                $result->lastId = (int) $visit['id'];
                $result->scanned++;

                $rule = $this->matchingRule($visit['userAgent'] ?? '', $visit['remoteAddr'] ?? null);
                match ($rule) {
                    self::RULE_USER_AGENT => $result->byUserAgent++,
                    self::RULE_GOOGLE_NETWORK => $result->byGoogleNetwork++,
                    self::RULE_SCANNER => $result->byScanner++,
                    default => null,
                };
                if ($rule !== null) {
                    $botIds[] = $visit['id'];
                }
            }

            if (! $dryRun && ! empty($botIds)) {
                $this->query->flagAsPotentialBots($botIds);
            }

            if ($onBatch !== null) {
                $onBatch($result);
            }
        } while (count($visits) === $batchSize);

        return $result;
    }

    private function matchingRule(string $userAgent, ?string $remoteAddr): ?string
    {
        if (isCrawler($userAgent)) {
            return self::RULE_USER_AGENT;
        }

        if ($this->matcher->isInBotNetwork($remoteAddr, BotIpSource::GOOGLE)) {
            return self::RULE_GOOGLE_NETWORK;
        }

        if (
            in_array($userAgent, $this->options->scannerUserAgents, true)
            && $this->matcher->isInBotNetwork($remoteAddr, BotIpSource::SCANNER)
        ) {
            return self::RULE_SCANNER;
        }

        return null;
    }
}
