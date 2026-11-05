<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\CLI\Command\Visit;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shlinkio\Shlink\CLI\Command\Visit\DownloadBotIpsCommand;
use Shlinkio\Shlink\CLI\Util\ExitCodes;
use Shlinkio\Shlink\Core\BotIp\BotIpRangesUpdaterInterface;
use Shlinkio\Shlink\Core\BotIp\Model\BotIpUpdateResult;
use ShlinkioTest\Shlink\CLI\CliTestUtilsTrait;
use Symfony\Component\Console\Tester\CommandTester;

class DownloadBotIpsCommandTest extends TestCase
{
    use CliTestUtilsTrait;

    private CommandTester $commandTester;
    private MockObject & BotIpRangesUpdaterInterface $updater;

    protected function setUp(): void
    {
        $this->updater = $this->createMock(BotIpRangesUpdaterInterface::class);
        $this->commandTester = $this->testerForCommand(new DownloadBotIpsCommand($this->updater));
    }

    /**
     * @test
     * @dataProvider provideResults
     */
    public function resultIsReported(BotIpUpdateResult $result, int $expectedExitCode, string $expectedOutput): void
    {
        $this->updater->expects($this->once())->method('update')->willReturn($result);

        $this->commandTester->execute([]);

        self::assertEquals($expectedExitCode, $this->commandTester->getStatusCode());
        self::assertStringContainsString($expectedOutput, $this->commandTester->getDisplay());
    }

    public function provideResults(): iterable
    {
        yield 'downloaded' => [
            BotIpUpdateResult::updated(10, 2, []),
            ExitCodes::EXIT_SUCCESS,
            'Bot IP ranges: 10 added, 2 refreshed.',
        ];
        yield 'one source failed' => [
            BotIpUpdateResult::updated(10, 0, ['https://example.com/a.json']),
            ExitCodes::EXIT_WARNING,
            'Could not download https://example.com/a.json',
        ];
        yield 'every source failed' => [
            BotIpUpdateResult::updated(0, 0, ['https://example.com/a.json']),
            ExitCodes::EXIT_FAILURE,
            'No bot IP ranges were downloaded.',
        ];
        yield 'locked' => [
            BotIpUpdateResult::skipped(),
            ExitCodes::EXIT_WARNING,
            'Another process is downloading the bot IP ranges right now.',
        ];
    }
}
