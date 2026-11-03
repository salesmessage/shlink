<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Core\BotIp;

use Cake\Chronos\Chronos;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shlinkio\Shlink\Core\BotIp\BotIpRangesUpdater;
use Shlinkio\Shlink\Core\BotIp\Entity\BotIpRange;
use Shlinkio\Shlink\Core\BotIp\Model\BotIpSource;
use Shlinkio\Shlink\Core\BotIp\Repository\BotIpRangeRepositoryInterface;
use Shlinkio\Shlink\Core\Options\BotIpOptions;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

use function json_encode;
use function str_contains;

class BotIpRangesUpdaterTest extends TestCase
{
    private const CRAWLERS = 'https://example.com/common-crawlers.json';
    private const FETCHERS = 'https://example.com/user-triggered-fetchers-google.json';

    private MockObject & ClientInterface $httpClient;
    private MockObject & EntityManagerInterface $em;
    private MockObject & BotIpRangeRepositoryInterface $repo;
    private MockObject & LockFactory $locker;
    private MockObject & SharedLockInterface $lock;
    private MockObject & LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(ClientInterface::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->repo = $this->createMock(BotIpRangeRepositoryInterface::class);
        $this->lock = $this->createMock(SharedLockInterface::class);
        $this->locker = $this->createMock(LockFactory::class);
        $this->locker->method('createLock')->willReturn($this->lock);
        $this->logger = $this->createMock(LoggerInterface::class);

        Chronos::setTestNow(Chronos::create(2026, 11, 4, 12));
    }

    protected function tearDown(): void
    {
        Chronos::setTestNow();
    }

    /** @test */
    public function newRangesAreAddedAndKnownOnesMarkedAsSeen(): void
    {
        $known = BotIpRange::seenNow('66.249.80.192/27', BotIpSource::GOOGLE, Chronos::create(2026, 10, 1));
        $this->lock->method('acquire')->willReturn(true);
        $this->lock->expects($this->once())->method('release');
        $this->respondWith([
            self::CRAWLERS => ['66.249.64.0/27', '2001:4860:4801:10::/64'],
            self::FETCHERS => ['66.249.80.192/27', 'not-a-range'],
        ]);
        $this->repo->method('findBySource')->with(BotIpSource::GOOGLE)->willReturn(['66.249.80.192/27' => $known]);
        $persisted = [];
        $this->em->expects($this->exactly(2))->method('persist')->willReturnCallback(
            function (BotIpRange $range) use (&$persisted): void {
                $persisted[] = $range->ip();
            },
        );
        $this->em->expects($this->once())->method('flush');

        $result = $this->updater()->update();

        self::assertTrue($result->updated);
        self::assertEquals(2, $result->added);
        self::assertEquals(1, $result->refreshed);
        self::assertEmpty($result->failedSources);
        self::assertEquals(['66.249.64.0/27', '2001:4860:4801:10::/64'], $persisted);
        self::assertEquals(Chronos::now(), $known->updatedAt());
    }

    /** @test */
    public function failingSourceChangesNothingOfItsOwnAndIsReported(): void
    {
        $this->lock->method('acquire')->willReturn(true);
        $this->httpClient->method('request')->willReturnCallback(
            fn (string $method, string $url) => $url === self::CRAWLERS
                ? throw new TransferException('timeout')
                : new Response(200, [], (string) json_encode(['prefixes' => []])),
        );
        $this->repo->method('findBySource')->willReturn([]);
        $this->em->expects($this->never())->method('persist');
        $this->logger->expects($this->once())->method('error');

        $result = $this->updater()->update();

        self::assertEquals([self::CRAWLERS, self::FETCHERS], $result->failedSources);
        self::assertTrue($result->allSourcesFailed());
    }

    /** @test */
    public function nothingIsDownloadedWhileAnotherProcessHoldsTheLock(): void
    {
        $this->lock->method('acquire')->willReturn(false);
        $this->httpClient->expects($this->never())->method('request');

        self::assertFalse($this->updater()->update()->updated);
    }

    /**
     * @test
     * @dataProvider provideLastSeen
     */
    public function listIsDownloadedOnlyWhenStale(?Chronos $lastSeenAt, bool $expectedDownload): void
    {
        $this->repo->method('findLastSeenAt')->with(BotIpSource::GOOGLE)->willReturn($lastSeenAt);
        $this->repo->method('findBySource')->willReturn([]);
        $this->lock->method('acquire')->willReturn(true);
        $this->httpClient->expects($expectedDownload ? $this->exactly(2) : $this->never())->method('request')
            ->willReturn(new Response(200, [], (string) json_encode(['prefixes' => []])));

        self::assertEquals($expectedDownload, $this->updater()->updateIfStale()->updated);
    }

    public function provideLastSeen(): iterable
    {
        yield 'never downloaded' => [null, true];
        yield 'eight days old' => [Chronos::create(2026, 10, 27, 11), true];
        yield 'six days old' => [Chronos::create(2026, 10, 29, 12), false];
    }

    /**
     * @param array<string, string[]> $cidrsByUrl
     */
    private function respondWith(array $cidrsByUrl): void
    {
        $respond = static function (string $method, string $url) use ($cidrsByUrl): Response {
            $prefixes = [];
            foreach ($cidrsByUrl[$url] ?? [] as $cidr) {
                $prefixes[] = str_contains($cidr, ':') ? ['ipv6Prefix' => $cidr] : ['ipv4Prefix' => $cidr];
            }

            return new Response(200, [], (string) json_encode(['creationTime' => 'now', 'prefixes' => $prefixes]));
        };
        $this->httpClient->method('request')->willReturnCallback($respond);
    }

    private function updater(): BotIpRangesUpdater
    {
        return new BotIpRangesUpdater(
            $this->httpClient,
            $this->em,
            $this->repo,
            $this->locker,
            new BotIpOptions(googleSources: [self::CRAWLERS, self::FETCHERS], refreshAfterDays: 7),
            $this->logger,
        );
    }
}
