<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Core\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\SkipMigration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ShlinkMigrations\Version20261104120000;

// Migrations are loaded by doctrine/migrations from their folder, not by the composer autoloader
require_once __DIR__ . '/../../migrations/Version20261104120000.php';

class Version20261104120000Test extends TestCase
{
    private Version20261104120000 $migration;

    protected function setUp(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('createSchemaManager')->willReturn($this->createStub(AbstractSchemaManager::class));
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

        $this->migration = new Version20261104120000($connection, new NullLogger());
    }

    #[Test]
    public function createsTheBotIpRangesTableAsTheMappingDescribesIt(): void
    {
        $schema = new Schema();

        $this->migration->up($schema);

        $table = $schema->getTable('bot_ip_ranges');
        self::assertSame(64, $table->getColumn('ip')->getLength());
        self::assertTrue($table->getColumn('ip')->getNotnull());
        self::assertSame(32, $table->getColumn('source')->getLength());
        self::assertTrue($table->getColumn('created_at')->getNotnull());
        self::assertTrue($table->getColumn('updated_at')->getNotnull());
        self::assertTrue($table->hasIndex('UQ_bot_ip_ranges_ip_source'));
        // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
        self::assertTrue($table->getIndex('UQ_bot_ip_ranges_ip_source')->isUnique());
    }

    /**
     * Production may already have the table from the 3.x fork (SWR-26047), under this version name
     */
    #[Test]
    public function isSkippedWhenTheTableExists(): void
    {
        $schema = new Schema();
        // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
        $schema->createTable('bot_ip_ranges');

        $this->expectException(SkipMigration::class);

        $this->migration->up($schema);
    }
}
