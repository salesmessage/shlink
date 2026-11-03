<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\BotIp;

use Cake\Chronos\Chronos;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;
use IPLib\Factory;
use Psr\Log\LoggerInterface;
use Shlinkio\Shlink\Core\BotIp\Entity\BotIpRange;
use Shlinkio\Shlink\Core\BotIp\Model\BotIpSource;
use Shlinkio\Shlink\Core\BotIp\Model\BotIpUpdateResult;
use Shlinkio\Shlink\Core\BotIp\Repository\BotIpRangeRepositoryInterface;
use Shlinkio\Shlink\Core\Options\BotIpOptions;
use Symfony\Component\Lock\LockFactory;
use Throwable;

use function array_unique;
use function is_array;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Ranges are only added or marked as seen again, never removed: a list that comes back short for a while must not
 * turn bots back into clicks. Ranges of other sources (the hand-seeded scanner) are never touched.
 */
class BotIpRangesUpdater implements BotIpRangesUpdaterInterface
{
    private const LOCK_NAME = 'bot-ip-ranges-update';
    private const LOCK_TTL = 120.0;
    private const REQUEST_TIMEOUT = 10;

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly EntityManagerInterface $em,
        private readonly BotIpRangeRepositoryInterface $repo,
        private readonly LockFactory $locker,
        private readonly BotIpOptions $options,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function updateIfStale(): BotIpUpdateResult
    {
        return $this->isStale() ? $this->update() : BotIpUpdateResult::skipped();
    }

    public function update(): BotIpUpdateResult
    {
        // Another worker or pod is already downloading the list: there is nothing to wait for
        $lock = $this->locker->createLock(self::LOCK_NAME, self::LOCK_TTL);
        if (! $lock->acquire()) {
            return BotIpUpdateResult::skipped();
        }

        try {
            return $this->downloadAndStore();
        } finally {
            $lock->release();
        }
    }

    private function isStale(): bool
    {
        $lastSeenAt = $this->repo->findLastSeenAt(BotIpSource::GOOGLE);
        return $lastSeenAt === null || $lastSeenAt->addDays($this->options->refreshAfterDays)->lessThan(Chronos::now());
    }

    private function downloadAndStore(): BotIpUpdateResult
    {
        $cidrs = [];
        $failedSources = [];
        foreach ($this->options->googleSources as $url) {
            $sourceCidrs = $this->downloadCidrs($url);
            if (empty($sourceCidrs)) {
                $failedSources[] = $url;
                continue;
            }

            $cidrs = [...$cidrs, ...$sourceCidrs];
        }

        $now = Chronos::now();
        $existing = $this->repo->findBySource(BotIpSource::GOOGLE);
        $added = 0;
        $refreshed = 0;
        foreach (array_unique($cidrs) as $cidr) {
            if (isset($existing[$cidr])) {
                $existing[$cidr]->markSeen($now);
                $refreshed++;
            } else {
                $this->em->persist(BotIpRange::seenNow($cidr, BotIpSource::GOOGLE, $now));
                $added++;
            }
        }
        $this->em->flush();

        return BotIpUpdateResult::updated($added, $refreshed, $failedSources);
    }

    /**
     * @return string[] valid CIDRs of the list, or none when it could not be downloaded or read
     */
    private function downloadCidrs(string $url): array
    {
        try {
            $response = $this->httpClient->request('GET', $url, [RequestOptions::TIMEOUT => self::REQUEST_TIMEOUT]);
            $list = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->logger->error('Could not download bot IP ranges from {url}. {e}', ['url' => $url, 'e' => $e]);
            return [];
        }

        $prefixes = is_array($list) ? ($list['prefixes'] ?? null) : null;
        if (! is_array($prefixes)) {
            $this->logger->error('Bot IP ranges list {url} has no prefixes.', ['url' => $url]);
            return [];
        }

        $cidrs = [];
        foreach ($prefixes as $prefix) {
            $cidr = is_array($prefix) ? ($prefix['ipv4Prefix'] ?? $prefix['ipv6Prefix'] ?? null) : null;
            if (is_string($cidr) && Factory::parseRangeString($cidr) !== null) {
                $cidrs[] = $cidr;
            }
        }

        return $cidrs;
    }
}
