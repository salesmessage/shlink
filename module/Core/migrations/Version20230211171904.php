<?php

declare(strict_types=1);

namespace ShlinkMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Upstream builds IDX_visits_potential_bot on visits (short_url_id, potential_bot) here, and Version20230303164233
 * drops it again. This fork already indexes these columns (idx_visits_short_url_id_potential_bot, Version20251230163550),
 * so building a copy only to drop it would cost a whole index build on visits in the migration window
 * (SWR-25201, D-032). The version name stays, so a later merge of upstream does not run it again.
 */
final class Version20230211171904 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->write(
            'No index built here (D-032): visits (short_url_id, potential_bot) comes from Version20251230163550',
        );
    }
}
