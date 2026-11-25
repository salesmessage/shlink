<?php

declare(strict_types=1);

namespace ShlinkMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Speeds up the crawlable short URLs of robots.txt. Production ran it on the 3.x fork under this version name, so it keeps the name and skips when the index
 * exists.
 */
final class Version20251230155813 extends AbstractMigration
{
    private const string INDEX_NAME = 'IDX_short_urls_crawlable_short_code';

    public function up(Schema $schema): void
    {
        $shortUrls = $schema->getTable('short_urls');
        $this->skipIf($shortUrls->hasIndex(self::INDEX_NAME), 'Index ' . self::INDEX_NAME . ' already exists');

        // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
        $shortUrls->addIndex(['crawlable', 'short_code'], self::INDEX_NAME);
    }

    public function down(Schema $schema): void
    {
        $shortUrls = $schema->getTable('short_urls');
        $this->skipIf(!$shortUrls->hasIndex(self::INDEX_NAME), 'Index ' . self::INDEX_NAME . ' does not exist');

        // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
        $shortUrls->dropIndex(self::INDEX_NAME);
    }

    public function isTransactional(): bool
    {
        return !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }
}
