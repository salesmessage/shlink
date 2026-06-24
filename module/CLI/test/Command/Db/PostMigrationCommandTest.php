<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\CLI\Command\Db;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shlinkio\Shlink\CLI\Command\Db\PostMigrationCommand;
use Shlinkio\Shlink\CLI\Util\ExitCodes;
use ShlinkioTest\Shlink\CLI\CliTestUtilsTrait;
use Symfony\Component\Console\Tester\CommandTester;

use function sprintf;

class PostMigrationCommandTest extends TestCase
{
    use CliTestUtilsTrait;

    private CommandTester $commandTester;
    private MockObject & Connection $conn;

    protected function setUp(): void
    {
        $this->conn = $this->createMock(Connection::class);
        $this->conn->method('quoteIdentifier')->willReturnCallback(static fn (string $id) => sprintf('"%s"', $id));

        $this->commandTester = $this->testerForCommand(new PostMigrationCommand($this->conn));
    }

    /** @test */
    public function abortsWhenConnectionIsNotPostgres(): void
    {
        $this->conn->method('getDatabasePlatform')->willReturn($this->createMock(MySQLPlatform::class));
        $this->conn->expects($this->never())->method('executeQuery');
        $this->conn->expects($this->never())->method('executeStatement');

        $exitCode = $this->commandTester->execute([]);

        self::assertEquals(ExitCodes::EXIT_FAILURE, $exitCode);
        self::assertStringContainsString(
            'This command targets a Postgres connection',
            $this->commandTester->getDisplay(),
        );
    }

    /** @test */
    public function sequencesAreResetAndAnalyzeIsRun(): void
    {
        $this->conn->method('getDatabasePlatform')->willReturn($this->createMock(PostgreSQLPlatform::class));

        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([
            ['table_name' => 'short_urls', 'column_name' => 'id', 'sequence_name' => 'short_urls_id_seq'],
            ['table_name' => 'tags', 'column_name' => 'id', 'sequence_name' => 'tags_id_seq'],
        ]);
        $this->conn->expects($this->once())->method('executeQuery')->willReturn($result);

        // First table has rows (max 42), second table is empty (null)
        $this->conn->method('fetchOne')->willReturnOnConsecutiveCalls(42, null);

        $statements = [];
        $this->conn->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params = []) use (&$statements): int {
                $statements[] = ['sql' => $sql, 'params' => $params];
                return 1;
            },
        );

        $exitCode = $this->commandTester->execute([]);

        self::assertEquals(ExitCodes::EXIT_SUCCESS, $exitCode);
        self::assertContains(
            ['sql' => 'SELECT setval(?, ?, true)', 'params' => ['short_urls_id_seq', 42]],
            $statements,
        );
        self::assertContains(['sql' => 'SELECT setval(?, 1, false)', 'params' => ['tags_id_seq']], $statements);
        self::assertContains(['sql' => 'ANALYZE', 'params' => []], $statements);

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Sequence short_urls_id_seq set to 42.', $output);
        self::assertStringContainsString('Sequence tags_id_seq set to 1.', $output);
        self::assertStringContainsString('ANALYZE completed.', $output);
        self::assertStringContainsString('Post-migration finished', $output);
    }

    /** @test */
    public function sequenceResetIsSkippedWithFlag(): void
    {
        $this->conn->method('getDatabasePlatform')->willReturn($this->createMock(PostgreSQLPlatform::class));
        $this->conn->expects($this->never())->method('executeQuery');
        $this->conn->expects($this->once())->method('executeStatement')->with('ANALYZE');

        $exitCode = $this->commandTester->execute(['--skip-sequences' => true]);

        self::assertEquals(ExitCodes::EXIT_SUCCESS, $exitCode);
    }

    /** @test */
    public function analyzeIsSkippedWithFlag(): void
    {
        $this->conn->method('getDatabasePlatform')->willReturn($this->createMock(PostgreSQLPlatform::class));

        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([]);
        $this->conn->expects($this->once())->method('executeQuery')->willReturn($result);
        $this->conn->expects($this->never())->method('executeStatement');

        $exitCode = $this->commandTester->execute(['--skip-analyze' => true]);

        self::assertEquals(ExitCodes::EXIT_SUCCESS, $exitCode);
    }
}
