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
use ShlinkMigrations\Version20230211171904;

// Migrations are loaded by doctrine/migrations from their folder, not by the composer autoloader
require_once __DIR__ . '/../../migrations/Version20230211171904.php';

class Version20230211171904Test extends TestCase
{
    /**
     * Production already indexes visits (short_url_id, potential_bot); upstream would build the same columns again
     * under its own name, only for Version20230303164233 to drop it (D-032)
     */
    #[Test]
    public function buildsNoIndexOnVisits(): void
    {
        $schema = new Schema();
        $visits = $schema->createTable('visits');
        $visits->addColumn('id', 'bigint');
        $visits->addColumn('short_url_id', 'bigint');
        $visits->addColumn('potential_bot', 'boolean');
        $connection = $this->createStub(Connection::class);
        $connection->method('createSchemaManager')->willReturn($this->createStub(AbstractSchemaManager::class));
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $migration = new Version20230211171904($connection, new NullLogger());

        $migration->up($schema);

        self::assertSame([], $visits->getIndexes());
    }
}
