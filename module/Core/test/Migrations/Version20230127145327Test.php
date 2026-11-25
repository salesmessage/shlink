<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Core\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ShlinkMigrations\Version20230127145327;

// Migrations are loaded by doctrine/migrations from their folder, not by the composer autoloader
require_once __DIR__ . '/../../migrations/Version20230127145327.php';

class Version20230127145327Test extends TestCase
{
    /**
     * The fork made original_url 4096 long; upstream Version20240220214031 makes it TEXT, so this one changes nothing
     */
    #[Test]
    public function leavesTheLongUrlColumnToTheTextMigration(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('createSchemaManager')->willReturn($this->createStub(AbstractSchemaManager::class));
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $migration = new Version20230127145327($connection, new NullLogger());
        $schema = new Schema();
        // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
        $shortUrls = $schema->createTable('short_urls');
        // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
        $shortUrls->addColumn('original_url', Types::STRING, ['length' => 2048]);

        $migration->up($schema);

        self::assertSame(2048, $schema->getTable('short_urls')->getColumn('original_url')->getLength());
        self::assertSame([], $migration->getSql());
    }
}
