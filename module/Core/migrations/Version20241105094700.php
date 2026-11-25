<?php

declare(strict_types=1);

namespace ShlinkMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Keeps the plain API keys in api_keys.old_key before Version20241105215309 hashes them, so a rollback to the 3.x
 * fork image can restore them: UPDATE api_keys SET "key" = old_key WHERE old_key IS NOT NULL (SWR-25201, D-001).
 * The column is not mapped by the entity.
 */
final class Version20241105094700 extends AbstractMigration
{
    private const string TABLE = 'api_keys';
    private const string COLUMN = 'old_key';

    public function up(Schema $schema): void
    {
        $apiKeys = $schema->getTable(self::TABLE);
        // Once there, the keys may be hashed already: copying them again would lose the plain ones
        $this->skipIf($apiKeys->hasColumn(self::COLUMN), 'Column api_keys.old_key already exists');

        // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
        $apiKeys->addColumn(self::COLUMN, Types::STRING, ['length' => 255, 'notnull' => false]);
    }

    public function postUp(Schema $schema): void
    {
        // On --dry-run the column is only printed, not created: there is nothing to copy into
        $apiKeys = $this->connection->createSchemaManager()->introspectTableByUnquotedName(self::TABLE);
        if (!$apiKeys->hasColumn(self::COLUMN)) {
            return;
        }

        // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
        $keyColumn = $this->connection->quoteIdentifier('key');
        $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET ' . self::COLUMN . ' = ' . $keyColumn);
    }

    public function down(Schema $schema): void
    {
        $apiKeys = $schema->getTable(self::TABLE);
        $this->skipIf(!$apiKeys->hasColumn(self::COLUMN), 'Column api_keys.old_key does not exist');

        // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
        $apiKeys->dropColumn(self::COLUMN);
    }

    public function isTransactional(): bool
    {
        return !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }
}
