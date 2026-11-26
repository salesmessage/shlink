<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Core\EventDispatcher;

use Cake\Chronos\Chronos;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Shlinkio\Shlink\Core\BotIp\BotIpRangesUpdaterInterface;
use Shlinkio\Shlink\Core\BotIp\Model\BotIpUpdateResult;
use Shlinkio\Shlink\Core\Config\Options\BotIpOptions;
use Shlinkio\Shlink\Core\EventDispatcher\UpdateBotIpRanges;

#[AllowMockObjectsWithoutExpectations]
class UpdateBotIpRangesTest extends TestCase
{
    private MockObject&BotIpRangesUpdaterInterface $updater;
    private MockObject&LoggerInterface $logger;
    private UpdateBotIpRanges $listener;

    protected function setUp(): void
    {
        $this->updater = $this->createMock(BotIpRangesUpdaterInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->listener = new UpdateBotIpRanges(
            $this->updater,
            new BotIpOptions(checkIntervalSeconds: 600),
            $this->logger,
        );
    }

    protected function tearDown(): void
    {
        Chronos::setTestNow();
    }

    #[Test]
    public function listIsCheckedAtMostOncePerInterval(): void
    {
        $this->updater
            ->expects($this->exactly(2))
            ->method('updateIfStale')
            ->willReturn(
                BotIpUpdateResult::skipped(),
            );

        Chronos::setTestNow(Chronos::create(2026, 11, 4, 12));
        ($this->listener)();
        Chronos::setTestNow(Chronos::create(2026, 11, 4, 12, 5));
        ($this->listener)();
        Chronos::setTestNow(Chronos::create(2026, 11, 4, 12, 10));
        ($this->listener)();
    }

    #[Test]
    public function updateIsLoggedWithTheSourcesThatFailed(): void
    {
        $this->updater
            ->method('updateIfStale')
            ->willReturn(
                BotIpUpdateResult::updated(3, 5, ['https://example.com/list.json']),
            );
        $this->logger
            ->expects($this->once())
            ->method('notice')
            ->with(
                'Bot IP ranges updated: {added} added, {refreshed} refreshed.',
                ['added' => 3, 'refreshed' => 5],
            );
        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with(
                'Bot IP ranges not downloaded from {sources}.',
                ['sources' => 'https://example.com/list.json'],
            );

        ($this->listener)();
    }

    #[Test]
    public function failureIsLoggedAndDoesNotEscape(): void
    {
        $e = new RuntimeException('db down');
        $this->updater->method('updateIfStale')->willThrowException($e);
        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with(
                'Bot IP ranges update failed. {e}',
                ['e' => $e],
            );

        ($this->listener)();
    }
}
