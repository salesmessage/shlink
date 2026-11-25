<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Core\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ShlinkMigrations\Version20260607082210;

// Migrations are loaded by doctrine/migrations from their folder, not by the composer autoloader
require_once __DIR__ . '/../../migrations/Version20260607082210.php';

class Version20260607082210Test extends TestCase
{
    /**
     * short-url:fill-long-url-hash fills the column after the switch (D-009), so the window does not wait for it
     */
    #[Test]
    public function leavesTheLongUrlHashToTheFillCommand(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($this->createStub(AbstractSchemaManager::class));
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->expects($this->never())->method('createQueryBuilder');
        $connection->expects($this->never())->method('executeQuery');
        $connection->expects($this->never())->method('executeStatement');
        $connection->expects($this->never())->method('transactional');
        $migration = new Version20260607082210($connection, new NullLogger());

        $migration->up(new Schema());

        self::assertSame([], $migration->getSql());
    }
}
