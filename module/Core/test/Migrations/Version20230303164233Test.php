<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Core\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ShlinkMigrations\Version20230303164233;

use function array_keys;
use function array_map;
use function array_values;
use function sort;

// Migrations are loaded by doctrine/migrations from their folder, not by the composer autoloader
require_once __DIR__ . '/../../migrations/Version20230303164233.php';

class Version20230303164233Test extends TestCase
{
    /**
     * Version20230211171904 builds nothing (D-032), so there is no IDX_visits_potential_bot to drop
     */
    #[Test]
    public function buildsThePotentialBotIndexWhenTheOldOneWasNeverBuilt(): void
    {
        [$schema, $visits] = $this->visitsSchema();

        $this->migration()->up($schema);

        self::assertSame(['visits_potential_bot_idx'], $this->indexNames($visits));
        self::assertSame(['potential_bot'], $visits->getIndex('visits_potential_bot_IDX')->getColumns());
    }

    /**
     * A database that ran upstream's Version20230211171904 still has the old index: it is replaced, as upstream does
     */
    #[Test]
    public function replacesTheOldIndexWhenItExists(): void
    {
        [$schema, $visits] = $this->visitsSchema();
        $visits->addIndex(['short_url_id', 'potential_bot'], 'IDX_visits_potential_bot');

        $this->migration()->up($schema);

        self::assertSame(['visits_potential_bot_idx'], $this->indexNames($visits));
    }

    /**
     * @return array{Schema, Table}
     */
    private function visitsSchema(): array
    {
        $schema = new Schema();
        $visits = $schema->createTable('visits');
        $visits->addColumn('id', 'bigint');
        $visits->addColumn('short_url_id', 'bigint');
        $visits->addColumn('potential_bot', 'boolean');

        return [$schema, $visits];
    }

    private function migration(): Version20230303164233
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('createSchemaManager')->willReturn($this->createStub(AbstractSchemaManager::class));
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

        return new Version20230303164233($connection, new NullLogger());
    }

    /**
     * @return list<string>
     */
    private function indexNames(Table $table): array
    {
        $names = array_values(array_map('strtolower', array_keys($table->getIndexes())));
        sort($names);

        return $names;
    }
}
