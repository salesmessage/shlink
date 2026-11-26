<?php

declare(strict_types=1);

namespace ShlinkMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create initial entries in the short_url_visits_counts table for existing visits.
 *
 * Upstream runs two COUNT queries per short URL. This fork builds the same rows (one per short URL and potential_bot
 * value with visits, slot_id 1 by default) in one pass over visits, since production has ~13M short URLs and ~29M
 * visits (SWR-25201, D-031). The version name stays, so a later merge of upstream does not run the backfill again.
 */
final class Version20240318084804 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql(
            'INSERT INTO short_url_visits_counts (short_url_id, potential_bot, count) '
            . 'SELECT short_url_id, potential_bot, COUNT(id) FROM visits WHERE short_url_id IS NOT NULL '
            . 'GROUP BY short_url_id, potential_bot',
        );
    }
}
