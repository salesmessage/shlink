<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\CLI\Command\Visit;

use Cake\Chronos\Chronos;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shlinkio\Shlink\CLI\Command\Visit\ReclassifyBotVisitsCommand;
use Shlinkio\Shlink\CLI\Util\ExitCodes;
use Shlinkio\Shlink\Core\BotIp\Model\ReclassificationResult;
use Shlinkio\Shlink\Core\BotIp\VisitBotReclassifier;
use ShlinkioTest\Shlink\CLI\CliTestUtilsTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock;

class ReclassifyBotVisitsCommandTest extends TestCase
{
    use CliTestUtilsTrait;

    private CommandTester $commandTester;
    private MockObject & VisitBotReclassifier $reclassifier;
    private MockObject & Lock\SharedLockInterface $lock;

    protected function setUp(): void
    {
        $this->reclassifier = $this->createMock(VisitBotReclassifier::class);
        $this->lock = $this->createMock(Lock\SharedLockInterface::class);
        $locker = $this->createMock(Lock\LockFactory::class);
        $locker->method('createLock')->with('visit:reclassify-bots', 600.0, false)->willReturn($this->lock);

        $this->commandTester = $this->testerForCommand(new ReclassifyBotVisitsCommand($this->reclassifier, $locker));
    }

    /** @test */
    public function optionsArePassedAndCountsShown(): void
    {
        $result = new ReclassificationResult();
        $result->scanned = 50;
        $result->byUserAgent = 1;
        $result->byGoogleNetwork = 20;
        $result->lastId = 1234;
        $this->lock->method('acquire')->willReturn(true);
        $this->reclassifier->expects($this->once())->method('reclassify')->with(
            Chronos::create(2026, 9, 22, 0, 0, 0),
            100,
            500,
            true,
            $this->isType('callable'),
        )->willReturn($result);

        $this->commandTester->execute([
            '--since' => '2026-09-22',
            '--from-id' => '100',
            '--batch-size' => '500',
            '--dry-run' => true,
        ]);
        $output = $this->commandTester->getDisplay();

        self::assertEquals(ExitCodes::EXIT_SUCCESS, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Google network', $output);
        self::assertStringContainsString('21', $output);
        self::assertStringContainsString('Dry run, nothing was changed.', $output);
        self::assertStringContainsString('Last visit id: 1234', $output);
    }

    /**
     * @test
     * @dataProvider provideInvalidOptions
     */
    public function invalidOptionsAreRejected(array $options): void
    {
        $this->lock->method('acquire')->willReturn(true);
        $this->reclassifier->expects($this->never())->method('reclassify');

        $this->commandTester->execute($options);

        self::assertEquals(ExitCodes::EXIT_FAILURE, $this->commandTester->getStatusCode());
    }

    public function provideInvalidOptions(): iterable
    {
        yield 'no since' => [[]];
        yield 'bad since' => [['--since' => 'yesterday']];
        yield 'bad from-id' => [['--since' => '2026-09-22', '--from-id' => 'abc']];
        yield 'zero batch' => [['--since' => '2026-09-22', '--batch-size' => '0']];
    }

    /** @test */
    public function nothingRunsWhileAnotherRunHoldsTheLock(): void
    {
        $this->lock->method('acquire')->willReturn(false);
        $this->reclassifier->expects($this->never())->method('reclassify');

        $this->commandTester->execute(['--since' => '2026-09-22']);

        self::assertEquals(ExitCodes::EXIT_WARNING, $this->commandTester->getStatusCode());
    }
}
