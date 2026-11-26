<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\BotIp;

use Cake\Chronos\Chronos;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;
use IPLib\Factory;
use Psr\Log\LoggerInterface;
use Shlinkio\Shlink\Core\BotIp\Entity\BotIpRange;
use Shlinkio\Shlink\Core\BotIp\Model\BotIpSource;
use Shlinkio\Shlink\Core\BotIp\Model\BotIpUpdateResult;
use Shlinkio\Shlink\Core\BotIp\Repository\BotIpRangeRepositoryInterface;
use Shlinkio\Shlink\Core\Config\Options\BotIpOptions;
use Symfony\Component\Lock\LockFactory;
use Throwable;

use function array_unique;
use function is_array;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Ranges are only added or marked as seen again, never removed: a list that comes back short for a while must not
 * turn bots back into clicks. Ranges of other sources are never touched.
 */
readonly class BotIpRangesUpdater implements BotIpRangesUpdaterInterface
{
    private const string LOCK_NAME = 'bot-ip-ranges-update';
    private const float LOCK_TTL = 120.0;
    private const int REQUEST_TIMEOUT = 10;

    public function __construct(
        private ClientInterface $httpClient,
        private EntityManagerInterface $em,
        private BotIpRangeRepositoryInterface $repo,
        private LockFactory $locker,
        private BotIpOptions $options,
        private LoggerInterface $logger,
    ) {}

    public function updateIfStale(): BotIpUpdateResult
    {
        if (!$this->isStale()) {
            return BotIpUpdateResult::skipped();
        }

        // Checked again under the lock: another worker may have refreshed the list in the meantime
        return $this->withLock(fn () => $this->isStale() ? $this->downloadAndStore() : BotIpUpdateResult::skipped());
    }

    public function update(): BotIpUpdateResult
    {
        return $this->withLock(fn () => $this->downloadAndStore());
    }

    /**
     * @param callable(): BotIpUpdateResult $update
     */
    private function withLock(callable $update): BotIpUpdateResult
    {
        // Another worker or pod is already downloading the list: there is nothing to wait for
        $lock = $this->locker->createLock(self::LOCK_NAME, self::LOCK_TTL);
        if (!$lock->acquire()) {
            return BotIpUpdateResult::skipped();
        }

        try {
            return $update();
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
            if ($sourceCidrs === []) {
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

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Without a shared lock store (no Redis) another pod can add the same ranges at the same moment: they are
            // stored either way
            $this->logger->notice('Bot IP ranges were stored by another process at the same time.');
            return BotIpUpdateResult::updated(0, 0, $failedSources);
        }

        return BotIpUpdateResult::updated($added, $refreshed, $failedSources);
    }

    /**
     * @return string[] valid CIDRs of the list, or none when it could not be downloaded or read
     */
    private function downloadCidrs(string $url): array
    {
        try {
            $response = $this->httpClient->request('GET', $url, [RequestOptions::TIMEOUT => self::REQUEST_TIMEOUT]);
            // @mago-expect analysis:invalid-type-cast (decoded JSON of a downloaded list, checked below)
            $list = (array) json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->logger->error('Could not download bot IP ranges from {url}. {e}', ['url' => $url, 'e' => $e]);
            return [];
        }

        if (!isset($list['prefixes']) || !is_array($list['prefixes'])) {
            $this->logger->error('Bot IP ranges list {url} has no prefixes.', ['url' => $url]);
            return [];
        }

        $cidrs = [];
        // @mago-expect analysis:mixed-assignment (entries of a downloaded JSON list, checked below)
        foreach ($list['prefixes'] as $prefix) {
            if (!is_array($prefix)) {
                continue;
            }

            // @mago-expect analysis:mixed-assignment (a value of a downloaded JSON list, checked below)
            $cidr = $prefix['ipv4Prefix'] ?? $prefix['ipv6Prefix'] ?? null;
            if (is_string($cidr) && Factory::parseRangeString($cidr) !== null) {
                $cidrs[] = $cidr;
            }
        }

        return $cidrs;
    }
}
