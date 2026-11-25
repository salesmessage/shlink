<?php

declare(strict_types=1);

namespace ShlinkMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Upstream fills long_url_hash here for every short URL. This fork fills it after the switch with the
 * short-url:fill-long-url-hash command (LongUrlHashFiller), so the migration window does not wait for it
 * (SWR-25201, D-009). The version name stays, so a later merge of upstream does not run the fill again.
 */
final class Version20260607082210 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->write('long_url_hash is filled by the short-url:fill-long-url-hash command, not by this migration');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
