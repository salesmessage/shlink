<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Core\Visit;

use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Shlinkio\Shlink\Core\BotIp\BotIpMatcherInterface;
use Shlinkio\Shlink\Core\Config\Options\TrackingOptions;
use Shlinkio\Shlink\Core\EventDispatcher\Event\UrlVisited;
use Shlinkio\Shlink\Core\ShortUrl\Entity\ShortUrl;
use Shlinkio\Shlink\Core\Visit\Entity\Visit;
use Shlinkio\Shlink\Core\Visit\Model\Visitor;
use Shlinkio\Shlink\Core\Visit\VisitsTracker;

class VisitsTrackerTest extends TestCase
{
    private MockObject&EntityManager $em;
    private MockObject&EventDispatcherInterface $eventDispatcher;
    private BotIpMatcherInterface $botIpMatcher;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManager::class);
        $this->em->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback) => $callback());

        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->botIpMatcher = $this->createStub(BotIpMatcherInterface::class);
    }

    #[Test, DataProvider('provideTrackingMethodNames')]
    public function trackPersistsVisitAndDispatchesEvent(string $method, array $args): void
    {
        $this->em->expects($this->once())->method('persist')->with($this->isInstanceOf(Visit::class));
        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with(
                $this->isInstanceOf(UrlVisited::class),
            );

        $result = $this->visitsTracker()->{$method}(...$args);

        self::assertInstanceOf(Visit::class, $result);
    }

    #[Test, DataProvider('provideTrackingMethodNames')]
    public function trackingIsSkippedCompletelyWhenDisabledFromOptions(string $method, array $args): void
    {
        $this->em->expects($this->never())->method('persist');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $result = $this->visitsTracker(new TrackingOptions(disableTracking: true))->{$method}(...$args);

        self::assertNull($result);
    }

    public static function provideTrackingMethodNames(): iterable
    {
        yield 'track' => ['track', [ShortUrl::createFake(), Visitor::empty()]];
        yield 'trackInvalidShortUrlVisit' => ['trackInvalidShortUrlVisit', [Visitor::empty()]];
        yield 'trackBaseUrlVisit' => ['trackBaseUrlVisit', [Visitor::empty()]];
        yield 'trackRegularNotFoundVisit' => ['trackRegularNotFoundVisit', [Visitor::empty()]];
    }

    #[Test, DataProvider('provideOrphanTrackingMethodNames')]
    public function orphanVisitsAreNotTrackedWhenDisabled(string $method): void
    {
        $this->em->expects($this->never())->method('persist');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $result = $this->visitsTracker(new TrackingOptions(trackOrphanVisits: false))->{$method}(Visitor::empty());

        self::assertNull($result);
    }

    public static function provideOrphanTrackingMethodNames(): iterable
    {
        yield 'trackInvalidShortUrlVisit' => ['trackInvalidShortUrlVisit'];
        yield 'trackBaseUrlVisit' => ['trackBaseUrlVisit'];
        yield 'trackRegularNotFoundVisit' => ['trackRegularNotFoundVisit'];
    }

    #[Test, DataProvider('provideBotAddressMatches')]
    public function visitFromBotAddressIsRecordedAsPotentialBot(bool $isBotAddress, bool $expectedPotentialBot): void
    {
        $linuxChrome =
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 '
            . 'Safari/537.36';
        $botIpMatcher = $this->createMock(BotIpMatcherInterface::class);
        // Checked on the full address: the stored one is anonymized
        $botIpMatcher
            ->expects($this->once())
            ->method('isBotAddress')
            ->with('66.249.80.201')
            ->willReturn(
                $isBotAddress,
            );
        $this->botIpMatcher = $botIpMatcher;
        $this->em
            ->expects($this->once())
            ->method('persist')
            ->with($this->callback(
                static fn (Visit $visit) => $visit->potentialBot === $expectedPotentialBot,
            ));
        $this->eventDispatcher->expects($this->once())->method('dispatch');

        $this->visitsTracker(new TrackingOptions(anonymizeRemoteAddr: true))->track(
            ShortUrl::createFake(),
            Visitor::fromParams(userAgent: $linuxChrome, remoteAddress: '66.249.80.201'),
        );
    }

    public static function provideBotAddressMatches(): iterable
    {
        yield 'Google fetcher posing as Linux Chrome' => [true, true];
        yield 'person on Linux Chrome' => [false, false];
    }

    #[Test]
    public function addressIsNotCheckedForVisitAlreadyFlaggedByUserAgent(): void
    {
        $botIpMatcher = $this->createMock(BotIpMatcherInterface::class);
        $botIpMatcher->expects($this->never())->method('isBotAddress');
        $this->botIpMatcher = $botIpMatcher;
        $this->em
            ->expects($this->once())
            ->method('persist')
            ->with($this->callback(
                static fn (Visit $visit) => $visit->potentialBot,
            ));
        $this->eventDispatcher->expects($this->once())->method('dispatch');

        $this->visitsTracker()->track(
            ShortUrl::createFake(),
            Visitor::fromParams(userAgent: 'cf-facebook', remoteAddress: '1.2.3.4'),
        );
    }

    private function visitsTracker(TrackingOptions|null $options = null): VisitsTracker
    {
        return new VisitsTracker(
            $this->em,
            $this->eventDispatcher,
            $options ?? new TrackingOptions(),
            $this->botIpMatcher,
        );
    }
}
