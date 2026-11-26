<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Core\BotIp;

use Cake\Chronos\Chronos;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Shlinkio\Shlink\Core\BotIp\BotIpMatcher;
use Shlinkio\Shlink\Core\BotIp\Model\BotIpSource;
use Shlinkio\Shlink\Core\BotIp\Repository\BotIpRangeRepositoryInterface;
use Shlinkio\Shlink\Core\Config\Options\BotIpOptions;

#[AllowMockObjectsWithoutExpectations]
class BotIpMatcherTest extends TestCase
{
    private const array RANGES = [
        'google' => ['66.249.80.192/27', '192.178.8.0/27', '2001:4860:4801:10::/64'],
    ];

    private MockObject&BotIpRangeRepositoryInterface $repo;
    private MockObject&LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(BotIpRangeRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    protected function tearDown(): void
    {
        Chronos::setTestNow();
    }

    #[Test, DataProvider('provideAddresses')]
    public function fullAddressIsMatchedAgainstEveryRange(string|null $address, bool $expected): void
    {
        $this->repo->method('findAllCidrsBySource')->willReturn(self::RANGES);

        self::assertEquals($expected, $this->matcher()->isBotAddress($address));
    }

    public static function provideAddresses(): iterable
    {
        yield 'Google fetcher' => ['66.249.80.201', true];
        yield 'Google fetcher IPv6' => ['2001:4860:4801:10::1', true];
        yield 'person' => ['73.109.12.34', false];
        yield 'no address' => [null, false];
        yield 'not an address' => ['unknown', false];
    }

    #[Test, DataProvider('provideAnonymizedAddresses')]
    public function anonymizedAddressIsMatchedByItsNetwork(string $address, BotIpSource $source, bool $expected): void
    {
        $this->repo->method('findAllCidrsBySource')->willReturn(self::RANGES);

        self::assertEquals($expected, $this->matcher()->isInBotNetwork($address, $source));
    }

    public static function provideAnonymizedAddresses(): iterable
    {
        yield 'Google network, block not at .0' => ['66.249.80.0', BotIpSource::GOOGLE, true];
        yield 'Google network, block at .0' => ['192.178.8.0', BotIpSource::GOOGLE, true];
        yield 'other network' => ['73.109.12.0', BotIpSource::GOOGLE, false];
        yield 'IPv6 is not matched by network' => ['2001:4860:4801:10::', BotIpSource::GOOGLE, false];
    }

    #[Test]
    public function rangesAreReadOncePerInterval(): void
    {
        $this->repo->expects($this->exactly(2))->method('findAllCidrsBySource')->willReturn(self::RANGES);
        $matcher = $this->matcher();

        Chronos::setTestNow(Chronos::create(2026, 11, 4, 12));
        $matcher->isBotAddress('66.249.80.201');
        Chronos::setTestNow(Chronos::create(2026, 11, 4, 12, 9));
        $matcher->isBotAddress('66.249.80.201');
        Chronos::setTestNow(Chronos::create(2026, 11, 4, 12, 10));
        $matcher->isBotAddress('66.249.80.201');
    }

    #[Test]
    public function lastKnownRangesAreKeptWhenTheyCannotBeRead(): void
    {
        $calls = 0;
        $this->repo
            ->expects($this->exactly(2))
            ->method('findAllCidrsBySource')
            ->willReturnCallback(
                static function () use (&$calls): array {
                    $calls++;
                    return $calls === 1 ? self::RANGES : throw new RuntimeException('db down');
                },
            );
        $this->logger->expects($this->once())->method('error');
        $matcher = $this->matcher();

        Chronos::setTestNow(Chronos::create(2026, 11, 4, 12));
        self::assertTrue($matcher->isBotAddress('66.249.80.201'));
        Chronos::setTestNow(Chronos::create(2026, 11, 4, 12, 20));
        self::assertTrue($matcher->isBotAddress('66.249.80.201'));
    }

    #[Test]
    public function noRangesMeansNoBots(): void
    {
        $this->repo->method('findAllCidrsBySource')->willReturn([]);

        self::assertFalse($this->matcher()->isBotAddress('66.249.80.201'));
    }

    private function matcher(): BotIpMatcher
    {
        return new BotIpMatcher($this->repo, new BotIpOptions(checkIntervalSeconds: 600), $this->logger);
    }
}
