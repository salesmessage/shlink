<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\BotIp;

use Cake\Chronos\Chronos;
use IPLib\Address\AddressInterface;
use IPLib\Address\IPv4;
use IPLib\Factory;
use IPLib\Range\RangeInterface;
use Psr\Log\LoggerInterface;
use Shlinkio\Shlink\Core\BotIp\Model\BotIpSource;
use Shlinkio\Shlink\Core\BotIp\Repository\BotIpRangeRepositoryInterface;
use Shlinkio\Shlink\Core\Options\BotIpOptions;
use Throwable;

use function array_slice;
use function explode;
use function implode;

/**
 * Keeps the ranges in memory and re-reads them every few minutes, so a long-lived worker picks up a refreshed list
 * without a restart and the redirect path does not query the database on every click.
 */
class BotIpMatcher implements BotIpMatcherInterface
{
    /** @var array<string, RangeInterface[]> */
    private array $rangesBySource = [];
    private ?int $loadedAt = null;

    public function __construct(
        private readonly BotIpRangeRepositoryInterface $repo,
        private readonly BotIpOptions $options,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isBotAddress(?string $address): bool
    {
        $parsedAddress = $address === null ? null : Factory::parseAddressString($address);
        if ($parsedAddress === null) {
            return false;
        }

        foreach ($this->rangesBySource() as $ranges) {
            foreach ($ranges as $range) {
                if ($range->contains($parsedAddress)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function isInBotNetwork(?string $address, BotIpSource $source): bool
    {
        $parsedAddress = $address === null ? null : Factory::parseAddressString($address);
        $network = $parsedAddress === null ? null : $this->networkOf($parsedAddress);
        if ($network === null) {
            return false;
        }

        foreach ($this->rangesBySource()[$source->value] ?? [] as $range) {
            // CIDR blocks are either nested or disjoint, so they overlap only when one contains the other
            if ($range->containsRange($network) || $network->containsRange($range)) {
                return true;
            }
        }

        return false;
    }

    private function networkOf(AddressInterface $address): ?RangeInterface
    {
        if (! $address instanceof IPv4) {
            return null;
        }

        $octets = array_slice(explode('.', $address->toString()), 0, 3);
        return Factory::parseRangeString(implode('.', [...$octets, '0']) . '/24');
    }

    /**
     * @return array<string, RangeInterface[]>
     */
    private function rangesBySource(): array
    {
        $now = Chronos::now()->getTimestamp();
        if ($this->loadedAt !== null && $now - $this->loadedAt < $this->options->checkIntervalSeconds) {
            return $this->rangesBySource;
        }

        $this->loadedAt = $now;
        try {
            $rangesBySource = [];
            foreach ($this->repo->findAllCidrsBySource() as $source => $cidrs) {
                foreach ($cidrs as $cidr) {
                    $range = Factory::parseRangeString($cidr);
                    if ($range !== null) {
                        $rangesBySource[$source][] = $range;
                    }
                }
            }
            $this->rangesBySource = $rangesBySource;
        } catch (Throwable $e) {
            // A visit must never be lost because the ranges cannot be read: keep the last known ones
            $this->logger->error('Could not load bot IP ranges. {e}', ['e' => $e]);
        }

        return $this->rangesBySource;
    }
}
