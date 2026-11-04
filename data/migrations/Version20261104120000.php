<?php

declare(strict_types=1);

namespace ShlinkMigrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * SWR-26047: bot IP ranges, downloaded at runtime. Visits from them are tracked as potential bots.
 */
final class Version20261104120000 extends AbstractMigration
{
    private const TABLE = 'bot_ip_ranges';

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable(self::TABLE));

        $table = $schema->createTable(self::TABLE);
        $table->addColumn('id', Types::BIGINT, ['unsigned' => true, 'autoincrement' => true, 'notnull' => true]);
        $table->setPrimaryKey(['id']);
        $table->addColumn('ip', Types::STRING, ['length' => 64, 'notnull' => true]);
        $table->addColumn('source', Types::STRING, ['length' => 32, 'notnull' => true]);
        $table->addColumn('created_at', Types::DATETIME_MUTABLE, ['notnull' => true]);
        $table->addColumn('updated_at', Types::DATETIME_MUTABLE, ['notnull' => true]);
        $table->addUniqueIndex(['ip', 'source'], 'UQ_bot_ip_ranges_ip_source');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(! $schema->hasTable(self::TABLE));
        $schema->dropTable(self::TABLE);
    }

    public function isTransactional(): bool
    {
        return ! ($this->connection->getDatabasePlatform() instanceof MySQLPlatform);
    }
}
