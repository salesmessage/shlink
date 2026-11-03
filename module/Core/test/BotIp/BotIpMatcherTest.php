<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Core\BotIp;

use Cake\Chronos\Chronos;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Shlinkio\Shlink\Core\BotIp\BotIpMatcher;
use Shlinkio\Shlink\Core\BotIp\Model\BotIpSource;
use Shlinkio\Shlink\Core\BotIp\Repository\BotIpRangeRepositoryInterface;
use Shlinkio\Shlink\Core\Options\BotIpOptions;

class BotIpMatcherTest extends TestCase
{
    private const RANGES = [
        'google' => ['66.249.80.192/27', '192.178.8.0/27', '2001:4860:4801:10::/64'],
        'scanner' => ['203.127.145.64/32'],
    ];

    private MockObject & BotIpRangeRepositoryInterface $repo;
    private MockObject & LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(BotIpRangeRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    protected function tearDown(): void
    {
        Chronos::setTestNow();
    }

    /**
     * @test
     * @dataProvider provideAddresses
     */
    public function fullAddressIsMatchedAgainstEveryRange(?string $address, bool $expected): void
    {
        $this->repo->method('findAllCidrsBySource')->willReturn(self::RANGES);

        self::assertEquals($expected, $this->matcher()->isBotAddress($address));
    }

    public function provideAddresses(): iterable
    {
        yield 'Google fetcher' => ['66.249.80.201', true];
        yield 'Google fetcher IPv6' => ['2001:4860:4801:10::1', true];
        yield 'scanner' => ['203.127.145.64', true];
        yield 'neighbour of the scanner' => ['203.127.145.65', false];
        yield 'person' => ['73.109.12.34', false];
        yield 'no address' => [null, false];
        yield 'not an address' => ['unknown', false];
    }

    /**
     * @test
     * @dataProvider provideAnonymizedAddresses
     */
    public function anonymizedAddressIsMatchedByItsNetwork(string $address, BotIpSource $source, bool $expected): void
    {
        $this->repo->method('findAllCidrsBySource')->willReturn(self::RANGES);

        self::assertEquals($expected, $this->matcher()->isInBotNetwork($address, $source));
    }

    public function provideAnonymizedAddresses(): iterable
    {
        yield 'Google network, block not at .0' => ['66.249.80.0', BotIpSource::GOOGLE, true];
        yield 'Google network, block at .0' => ['192.178.8.0', BotIpSource::GOOGLE, true];
        yield 'scanner network' => ['203.127.145.0', BotIpSource::SCANNER, true];
        yield 'scanner network is not Google' => ['203.127.145.0', BotIpSource::GOOGLE, false];
        yield 'other network' => ['73.109.12.0', BotIpSource::GOOGLE, false];
        yield 'IPv6 is not matched by network' => ['2001:4860:4801:10::', BotIpSource::GOOGLE, false];
    }

    /** @test */
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

    /** @test */
    public function lastKnownRangesAreKeptWhenTheyCannotBeRead(): void
    {
        $this->repo->expects($this->exactly(2))->method('findAllCidrsBySource')->willReturnOnConsecutiveCalls(
            self::RANGES,
            $this->throwException(new RuntimeException('db down')),
        );
        $this->logger->expects($this->once())->method('error');
        $matcher = $this->matcher();

        Chronos::setTestNow(Chronos::create(2026, 11, 4, 12));
        self::assertTrue($matcher->isBotAddress('66.249.80.201'));
        Chronos::setTestNow(Chronos::create(2026, 11, 4, 12, 20));
        self::assertTrue($matcher->isBotAddress('66.249.80.201'));
    }

    /** @test */
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
