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
use ShlinkMigrations\Version20240318084804;

use function preg_replace;
use function trim;

// Migrations are loaded by doctrine/migrations from their folder, not by the composer autoloader
require_once __DIR__ . '/../../migrations/Version20240318084804.php';

class Version20240318084804Test extends TestCase
{
    /**
     * Production has ~13M short URLs: the counts are built in one pass over visits, not with two queries per short
     * URL (D-031)
     */
    #[Test]
    public function buildsTheVisitsCountsWithOneStatement(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($this->createStub(AbstractSchemaManager::class));
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->expects($this->never())->method('createQueryBuilder');
        $connection->expects($this->never())->method('executeQuery');
        $connection->expects($this->never())->method('executeStatement');
        $migration = new Version20240318084804($connection, new NullLogger());

        $migration->up(new Schema());

        $queries = [];
        foreach ($migration->getSql() as $query) {
            $queries[] = trim((string) preg_replace('/\s+/', ' ', $query->getStatement()));
        }
        self::assertSame(
            [
                'INSERT INTO short_url_visits_counts (short_url_id, potential_bot, count) '
                    . 'SELECT short_url_id, potential_bot, COUNT(*) FROM visits WHERE short_url_id IS NOT NULL '
                    . 'GROUP BY short_url_id, potential_bot',
            ],
            $queries,
        );
    }
}
