<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Core\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ShlinkMigrations\Version20260828120000;

// Migrations are loaded by doctrine/migrations from their folder, not by the composer autoloader
require_once __DIR__ . '/../../migrations/Version20260828120000.php';

class Version20260828120000Test extends TestCase
{
    private Version20260828120000 $migration;

    protected function setUp(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('createSchemaManager')->willReturn($this->createStub(AbstractSchemaManager::class));
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

        $this->migration = new Version20260828120000($connection, new NullLogger());
    }

    #[Test]
    public function addsTheDeviceColumnsWithTheLengthsOfTheMapping(): void
    {
        [$schema, $visits] = $this->schemaWithVisitsTable();

        $this->migration->up($schema);

        self::assertSame(32, $visits->getColumn('device_type')->getLength());
        self::assertFalse($visits->getColumn('device_type')->getNotnull());
        self::assertSame(64, $visits->getColumn('device_type_detail')->getLength());
        self::assertFalse($visits->getColumn('device_type_detail')->getNotnull());
    }

    /**
     * Production already ran this version on the 3.x fork, so the columns are there when it runs again
     */
    #[Test]
    public function skipsTheColumnsThatAlreadyExist(): void
    {
        [$schema, $visits] = $this->schemaWithVisitsTable();
        $this->addStringColumn($visits, 'device_type', 32);
        $this->addStringColumn($visits, 'device_type_detail', 64);

        $this->migration->up($schema);

        self::assertCount(2, $visits->getColumns());
        self::assertSame(32, $visits->getColumn('device_type')->getLength());
        self::assertSame(64, $visits->getColumn('device_type_detail')->getLength());
    }

    #[Test]
    public function addsOnlyTheColumnThatIsMissing(): void
    {
        [$schema, $visits] = $this->schemaWithVisitsTable();
        $this->addStringColumn($visits, 'device_type', 32);

        $this->migration->up($schema);

        self::assertCount(2, $visits->getColumns());
        self::assertSame(64, $visits->getColumn('device_type_detail')->getLength());
    }

    /**
     * @return array{Schema, Table}
     */
    private function schemaWithVisitsTable(): array
    {
        $schema = new Schema();
        // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
        $visits = $schema->createTable('visits');

        return [$schema, $visits];
    }

    private function addStringColumn(Table $table, string $name, int $length): void
    {
        // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
        $table->addColumn($name, Types::STRING, ['length' => $length, 'notnull' => false]);
    }
}
