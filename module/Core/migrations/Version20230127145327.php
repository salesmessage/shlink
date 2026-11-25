<?php

declare(strict_types=1);

namespace ShlinkMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The 3.x fork made short_urls.original_url 4096 long here. Upstream Version20240220214031 makes it TEXT, so this
 * migration changes nothing any more; it stays only so its version, executed in production, is still known.
 */
final class Version20230127145327 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->write('short_urls.original_url becomes TEXT in Version20240220214031');
    }
}
