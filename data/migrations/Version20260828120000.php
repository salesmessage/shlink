<?php

declare(strict_types=1);

namespace ShlinkMigrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260828120000 extends AbstractMigration
{
    private const DEVICE_TYPE = 'device_type';
    private const DEVICE_TYPE_DETAIL = 'device_type_detail';

    public function up(Schema $schema): void
    {
        $visits = $schema->getTable('visits');

        if (! $visits->hasColumn(self::DEVICE_TYPE)) {
            $visits->addColumn(self::DEVICE_TYPE, Types::STRING, ['length' => 32, 'notnull' => false]);
        }

        if (! $visits->hasColumn(self::DEVICE_TYPE_DETAIL)) {
            $visits->addColumn(self::DEVICE_TYPE_DETAIL, Types::STRING, ['length' => 64, 'notnull' => false]);
        }
    }

    public function down(Schema $schema): void
    {
        $visits = $schema->getTable('visits');

        if ($visits->hasColumn(self::DEVICE_TYPE)) {
            $visits->dropColumn(self::DEVICE_TYPE);
        }

        if ($visits->hasColumn(self::DEVICE_TYPE_DETAIL)) {
            $visits->dropColumn(self::DEVICE_TYPE_DETAIL);
        }
    }

    public function isTransactional(): bool
    {
        return ! ($this->connection->getDatabasePlatform() instanceof MySQLPlatform);
    }
}
