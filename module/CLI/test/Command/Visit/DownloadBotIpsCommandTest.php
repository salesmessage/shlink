<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\CLI\Command\Visit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shlinkio\Shlink\CLI\Command\Visit\DownloadBotIpsCommand;
use Shlinkio\Shlink\Core\BotIp\BotIpRangesUpdaterInterface;
use Shlinkio\Shlink\Core\BotIp\Model\BotIpUpdateResult;
use ShlinkioTest\Shlink\CLI\Util\CliTestUtils;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class DownloadBotIpsCommandTest extends TestCase
{
    private CommandTester $commandTester;
    private MockObject&BotIpRangesUpdaterInterface $updater;

    protected function setUp(): void
    {
        $this->updater = $this->createMock(BotIpRangesUpdaterInterface::class);
        $this->commandTester = CliTestUtils::testerForCommand(new DownloadBotIpsCommand($this->updater));
    }

    #[Test, DataProvider('provideResults')]
    public function resultIsReported(BotIpUpdateResult $result, int $expectedExitCode, string $expectedOutput): void
    {
        $this->updater->expects($this->once())->method('update')->willReturn($result);

        $this->commandTester->execute([]);

        self::assertEquals($expectedExitCode, $this->commandTester->getStatusCode());
        self::assertStringContainsString($expectedOutput, $this->commandTester->getDisplay());
    }

    public static function provideResults(): iterable
    {
        yield 'downloaded' => [
            BotIpUpdateResult::updated(10, 2, []),
            Command::SUCCESS,
            'Bot IP ranges: 10 added, 2 refreshed.',
        ];
        yield 'one source failed' => [
            BotIpUpdateResult::updated(10, 0, ['https://example.com/a.json']),
            Command::INVALID,
            'Could not download https://example.com/a.json',
        ];
        yield 'every source failed' => [
            BotIpUpdateResult::updated(0, 0, ['https://example.com/a.json']),
            Command::FAILURE,
            'No bot IP ranges were downloaded.',
        ];
        yield 'locked' => [
            BotIpUpdateResult::skipped(),
            Command::INVALID,
            'Another process is downloading the bot IP ranges right now.',
        ];
    }
}
