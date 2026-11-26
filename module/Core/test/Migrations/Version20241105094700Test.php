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
use ShlinkMigrations\Version20241105094700;

// Migrations are loaded by doctrine/migrations from their folder, not by the composer autoloader
require_once __DIR__ . '/../../migrations/Version20241105094700.php';

class Version20241105094700Test extends TestCase
{
    #[Test]
    public function addsANullableColumnForThePlainKey(): void
    {
        $schema = $this->schemaWithApiKeys();

        $this->migration()->up($schema);

        $column = $schema->getTable('api_keys')->getColumn('old_key');
        self::assertFalse($column->getNotnull());
        self::assertSame(255, $column->getLength());
    }

    #[Test]
    public function isSkippedWhenTheColumnExists(): void
    {
        $schema = $this->schemaWithApiKeys();
        // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
        $schema->getTable('api_keys')->addColumn('old_key', Types::STRING, ['notnull' => false]);

        $this->expectException(SkipMigration::class);

        $this->migration()->up($schema);
    }

    #[Test]
    public function copiesThePlainKeyOnceTheColumnExists(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($this->schemaManagerWithApiKeys(withOldKey: true));
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $name) => '"' . $name . '"');
        $connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                'UPDATE api_keys SET old_key = "key"',
            );

        $this->migration($connection)->postUp($this->schemaWithApiKeys());
    }

    /**
     * migrations:migrate --dry-run prints the new column without creating it, then runs postUp all the same
     */
    #[Test]
    public function copiesNothingOnADryRun(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($this->schemaManagerWithApiKeys(withOldKey: false));
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->expects($this->never())->method('executeStatement');

        $this->migration($connection)->postUp($this->schemaWithApiKeys());
    }

    private function schemaManagerWithApiKeys(bool $withOldKey): AbstractSchemaManager
    {
        $apiKeys = $this->schemaWithApiKeys()->getTable('api_keys');
        if ($withOldKey) {
            // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
            $apiKeys->addColumn('old_key', Types::STRING, ['notnull' => false]);
        }

        $schemaManager = $this->createStub(AbstractSchemaManager::class);
        $schemaManager->method('introspectTableByUnquotedName')->willReturn($apiKeys);

        return $schemaManager;
    }

    private function migration(Connection|null $connection = null): Version20241105094700
    {
        if ($connection === null) {
            $connection = $this->createStub(Connection::class);
            $connection->method('createSchemaManager')->willReturn($this->createStub(AbstractSchemaManager::class));
            $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        }

        return new Version20241105094700($connection, new NullLogger());
    }

    private function schemaWithApiKeys(): Schema
    {
        $schema = new Schema();
        // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
        $apiKeys = $schema->createTable('api_keys');
        // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
        $apiKeys->addColumn('key', Types::STRING, ['length' => 255]);

        return $schema;
    }
}
