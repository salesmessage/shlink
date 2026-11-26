<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\CLI\Command\ShortUrl;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Shlinkio\Shlink\CLI\Command\ShortUrl\FillLongUrlHashCommand;
use Shlinkio\Shlink\Core\ShortUrl\LongUrlHashFillerInterface;
use Shlinkio\Shlink\Core\ShortUrl\Model\LongUrlHashFillResult;
use ShlinkioTest\Shlink\CLI\Util\CliTestUtils;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock;

#[AllowMockObjectsWithoutExpectations]
class FillLongUrlHashCommandTest extends TestCase
{
    private CommandTester $commandTester;
    private MockObject&LongUrlHashFillerInterface $filler;
    private Stub&Lock\SharedLockInterface $lock;

    protected function setUp(): void
    {
        $this->filler = $this->createMock(LongUrlHashFillerInterface::class);
        $this->lock = $this->createStub(Lock\SharedLockInterface::class);
        $locker = $this->createStub(Lock\LockFactory::class);
        $locker->method('createLock')->willReturn($this->lock);

        $this->commandTester = CliTestUtils::testerForCommand(new FillLongUrlHashCommand($this->filler, $locker));
    }

    #[Test]
    public function optionsArePassedAndRowsLeftShown(): void
    {
        $this->lock->method('acquire')->willReturn(true);
        $this->filler
            ->expects($this->once())
            ->method('fill')
            ->with(100, 500, $this->isCallable())
            ->willReturn(new LongUrlHashFillResult(filled: 40, lastId: 1234));
        $this->filler->expects($this->once())->method('countLeft')->willReturn(3);

        $this->commandTester->execute(['--from-id' => '100', '--batch-size' => '500']);
        $output = $this->commandTester->getDisplay();

        self::assertEquals(Command::SUCCESS, $this->commandTester->getStatusCode());
        self::assertMatchesRegularExpression('/Filled\s+40/', $output);
        self::assertMatchesRegularExpression('/Left\s+3/', $output);
        self::assertStringContainsString('Last short URL id: 1234', $output);
    }

    #[Test]
    public function defaultsStartFromTheFirstRow(): void
    {
        $this->lock->method('acquire')->willReturn(true);
        $this->filler
            ->expects($this->once())
            ->method('fill')
            ->with(0, 1000, $this->isCallable())
            ->willReturn(new LongUrlHashFillResult(filled: 0, lastId: 0));
        $this->filler->method('countLeft')->willReturn(0);

        $this->commandTester->execute([]);

        self::assertEquals(Command::SUCCESS, $this->commandTester->getStatusCode());
    }

    #[Test, DataProvider('provideInvalidOptions')]
    public function invalidOptionsFail(array $input): void
    {
        $this->filler->expects($this->never())->method('fill');

        $this->commandTester->execute($input);

        self::assertEquals(Command::FAILURE, $this->commandTester->getStatusCode());
    }

    public static function provideInvalidOptions(): iterable
    {
        yield 'non-numeric from id' => [['--from-id' => 'abc']];
        yield 'zero batch size' => [['--batch-size' => '0']];
        yield 'non-numeric batch size' => [['--batch-size' => 'x']];
    }

    #[Test]
    public function doesNothingWhenAnotherRunHoldsTheLock(): void
    {
        $this->lock->method('acquire')->willReturn(false);
        $this->filler->expects($this->never())->method('fill');

        $this->commandTester->execute([]);

        self::assertEquals(Command::INVALID, $this->commandTester->getStatusCode());
    }
}
