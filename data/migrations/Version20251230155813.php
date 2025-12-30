<?php

declare(strict_types=1);

namespace ShlinkMigrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251230155813 extends AbstractMigration
{
    private const INDEX_NAME = 'IDX_short_urls_crawlable_short_code';

    public function up(Schema $schema): void
    {
        $shortUrls = $schema->getTable('short_urls');
        $this->skipIf($shortUrls->hasIndex(self::INDEX_NAME));
        $shortUrls->addIndex(['crawlable', 'short_code'], self::INDEX_NAME);
    }

    public function down(Schema $schema): void
    {
        $shortUrls = $schema->getTable('short_urls');
        $this->skipIf(! $shortUrls->hasIndex(self::INDEX_NAME));
        $shortUrls->dropIndex(self::INDEX_NAME);
    }

    public function isTransactional(): bool
    {
        return ! ($this->connection->getDatabasePlatform() instanceof MySQLPlatform);
    }
}

