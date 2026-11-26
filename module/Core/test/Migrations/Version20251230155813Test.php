<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Core\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\Exception\SkipMigration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ShlinkMigrations\Version20251230155813;

// Migrations are loaded by doctrine/migrations from their folder, not by the composer autoloader
require_once __DIR__ . '/../../migrations/Version20251230155813.php';

class Version20251230155813Test extends TestCase
{
    private Version20251230155813 $migration;

    protected function setUp(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('createSchemaManager')->willReturn($this->createStub(AbstractSchemaManager::class));
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

        $this->migration = new Version20251230155813($connection, new NullLogger());
    }

    #[Test]
    public function addsTheIndex(): void
    {
        $schema = $this->schema();

        $this->migration->up($schema);

        // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
        $index = $schema->getTable('short_urls')->getIndex('IDX_short_urls_crawlable_short_code');
        self::assertSame(['crawlable', 'short_code'], $index->getColumns());
    }

    /**
     * Production has the index from the 3.x fork, under this version name and in lower case (Postgres)
     */
    #[Test]
    public function isSkippedWhenTheIndexExists(): void
    {
        $schema = $this->schema();
        // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
        $schema->getTable('short_urls')->addIndex(
            ['crawlable', 'short_code'],
            'idx_short_urls_crawlable_short_code',
        );

        $this->expectException(SkipMigration::class);

        $this->migration->up($schema);
    }

    private function schema(): Schema
    {
        $schema = new Schema();
        // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
        $table = $schema->createTable('short_urls');
        // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
        $table->addColumn('crawlable', Types::BIGINT);
        // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
        $table->addColumn('short_code', Types::STRING);

        return $schema;
    }
}
