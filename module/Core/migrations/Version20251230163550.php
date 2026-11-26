<?php

declare(strict_types=1);

namespace ShlinkMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Speeds up the visits of a short URL with or without bots. Production ran it on the 3.x fork under this version name, so it keeps the name and skips when the index
 * exists.
 */
final class Version20251230163550 extends AbstractMigration
{
    private const string INDEX_NAME = 'IDX_visits_short_url_id_potential_bot';

    public function up(Schema $schema): void
    {
        $visits = $schema->getTable('visits');
        $this->skipIf($visits->hasIndex(self::INDEX_NAME), 'Index ' . self::INDEX_NAME . ' already exists');

        // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
        $visits->addIndex(['short_url_id', 'potential_bot'], self::INDEX_NAME);
    }

    public function down(Schema $schema): void
    {
        $visits = $schema->getTable('visits');
        $this->skipIf(!$visits->hasIndex(self::INDEX_NAME), 'Index ' . self::INDEX_NAME . ' does not exist');

        // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
        $visits->dropIndex(self::INDEX_NAME);
    }

    public function isTransactional(): bool
    {
        return !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }
}
