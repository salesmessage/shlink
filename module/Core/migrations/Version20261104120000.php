<?php

declare(strict_types=1);

namespace ShlinkMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bot IP ranges, downloaded at runtime: visits from them are tracked as potential bots (SWR-26047). Production may
 * already have the table from the 3.x fork under this version name, so the migration is skipped when it exists.
 */
final class Version20261104120000 extends AbstractMigration
{
    private const string TABLE = 'bot_ip_ranges';

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable(self::TABLE), 'Table bot_ip_ranges already exists');

        // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
        $table = $schema->createTable(self::TABLE);
        // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
        $table->addColumn('id', Types::BIGINT, ['unsigned' => true, 'autoincrement' => true, 'notnull' => true]);
        // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
        $table->setPrimaryKey(['id']);
        // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
        $table->addColumn('ip', Types::STRING, ['length' => 64, 'notnull' => true]);
        // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
        $table->addColumn('source', Types::STRING, ['length' => 32, 'notnull' => true]);
        // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
        $table->addColumn('created_at', Types::DATETIME_MUTABLE, ['notnull' => true]);
        // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
        $table->addColumn('updated_at', Types::DATETIME_MUTABLE, ['notnull' => true]);
        // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
        $table->addUniqueIndex(['ip', 'source'], 'UQ_bot_ip_ranges_ip_source');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable(self::TABLE), 'Table bot_ip_ranges does not exist');
        // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
        $schema->dropTable(self::TABLE);
    }

    public function isTransactional(): bool
    {
        return !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }
}
