<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\BotIp;

use Cake\Chronos\Chronos;
use Shlinkio\Shlink\Core\BotIp\Model\BotIpSource;
use Shlinkio\Shlink\Core\BotIp\Model\ReclassificationResult;
use Shlinkio\Shlink\Core\Visit\Repository\BotReclassificationQueryInterface;

use function count;
use function max;
use function Shlinkio\Shlink\Core\isCrawler;

/**
 * One-off pass over past visits to short URLs, flagging as potential bots the ones today's rules would flag. Stored
 * addresses keep only their /24 network, so the address rule works on that network: it overlaps a Google range, and
 * those networks belong to Google as a whole.
 * The flag is only ever set, never cleared. The visit counts of short URLs (visitsSummary) are not recalculated.
 */
readonly class VisitBotReclassifier implements VisitBotReclassifierInterface
{
    private const string RULE_USER_AGENT = 'user_agent';
    private const string RULE_GOOGLE_NETWORK = 'google_network';

    public function __construct(
        private BotReclassificationQueryInterface $query,
        private BotIpMatcherInterface $matcher,
    ) {}

    public function reclassify(
        Chronos $since,
        int $fromId,
        int $batchSize,
        bool $dryRun,
        callable|null $onBatch = null,
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
                if ($rule === self::RULE_USER_AGENT) {
                    $result->byUserAgent++;
                } elseif ($rule === self::RULE_GOOGLE_NETWORK) {
                    $result->byGoogleNetwork++;
                }

                if ($rule !== null) {
                    $botIds[] = $visit['id'];
                }
            }

            if (!$dryRun && $botIds !== []) {
                $this->query->flagAsPotentialBots($botIds);
            }

            if ($onBatch !== null) {
                $onBatch($result);
            }
        } while (count($visits) === $batchSize);

        return $result;
    }

    private function matchingRule(string $userAgent, string|null $remoteAddr): string|null
    {
        if (isCrawler($userAgent)) {
            return self::RULE_USER_AGENT;
        }

        if ($this->matcher->isInBotNetwork($remoteAddr, BotIpSource::GOOGLE)) {
            return self::RULE_GOOGLE_NETWORK;
        }

        return null;
    }
}
