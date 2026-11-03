<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\BotIp;

use Cake\Chronos\Chronos;
use Doctrine\ORM\EntityManagerInterface;
use Shlinkio\Shlink\Core\BotIp\Model\BotIpSource;
use Shlinkio\Shlink\Core\BotIp\Model\ReclassificationResult;
use Shlinkio\Shlink\Core\Options\BotIpOptions;
use Shlinkio\Shlink\Core\Visit\Entity\Visit;

use function count;
use function in_array;
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
        private readonly EntityManagerInterface $em,
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
        $result->lastId = $fromId;

        do {
            $visits = $this->nextBatch($since, $result->lastId, $batchSize);
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
                $this->markAsBots($botIds);
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

    /**
     * @return array<array{id: string|int, userAgent: string|null, remoteAddr: string|null}>
     */
    private function nextBatch(Chronos $since, int $afterId, int $batchSize): array
    {
        /** @var array<array{id: string|int, userAgent: string|null, remoteAddr: string|null}> */
        return $this->em->createQueryBuilder()
            ->select('v.id', 'v.userAgent', 'v.remoteAddr')
            ->from(Visit::class, 'v')
            ->where('v.id > :afterId')
            ->andWhere('v.shortUrl IS NOT NULL')
            ->andWhere('v.potentialBot = :notBot')
            ->andWhere('v.date >= :since')
            ->setParameter('afterId', $afterId)
            ->setParameter('notBot', false)
            ->setParameter('since', $since)
            ->orderBy('v.id', 'ASC')
            ->setMaxResults($batchSize)
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * @param array<string|int> $ids
     */
    private function markAsBots(array $ids): void
    {
        $this->em->createQueryBuilder()
            ->update(Visit::class, 'v')
            ->set('v.potentialBot', ':bot')
            ->where('v.id IN (:ids)')
            ->setParameter('bot', true)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }
}
