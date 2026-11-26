<?php

declare(strict_types=1);

namespace ShlinkMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20230303164233 extends AbstractMigration
{
    private const string INDEX_NAME = 'visits_potential_bot_IDX';

    public function up(Schema $schema): void
    {
        $visits = $schema->getTable('visits');
        $this->skipIf($visits->hasIndex(self::INDEX_NAME));

        // The old index exists only where upstream's Version20230211171904 ran: this fork skips it (D-032)
        if ($visits->hasIndex('IDX_visits_potential_bot')) {
            $visits->dropIndex('IDX_visits_potential_bot');
        }
        $visits->addIndex(['potential_bot'], self::INDEX_NAME);
    }

    public function isTransactional(): bool
    {
        return !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }
}
