<?php

declare(strict_types=1);

namespace ShlinkMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the device class of each visit (DeviceClassifier). Production already ran it on the 3.x fork under this
 * version name, so it keeps the name and skips the columns that exist.
 */
final class Version20260828120000 extends AbstractMigration
{
    private const string DEVICE_TYPE = 'device_type';
    private const string DEVICE_TYPE_DETAIL = 'device_type_detail';

    public function up(Schema $schema): void
    {
        $visits = $schema->getTable('visits');

        if (!$visits->hasColumn(self::DEVICE_TYPE)) {
            // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
            $visits->addColumn(self::DEVICE_TYPE, Types::STRING, ['length' => 32, 'notnull' => false]);
        }

        if (!$visits->hasColumn(self::DEVICE_TYPE_DETAIL)) {
            // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
            $visits->addColumn(self::DEVICE_TYPE_DETAIL, Types::STRING, ['length' => 64, 'notnull' => false]);
        }
    }

    public function down(Schema $schema): void
    {
        $visits = $schema->getTable('visits');

        if ($visits->hasColumn(self::DEVICE_TYPE)) {
            // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
            $visits->dropColumn(self::DEVICE_TYPE);
        }

        if ($visits->hasColumn(self::DEVICE_TYPE_DETAIL)) {
            // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
            $visits->dropColumn(self::DEVICE_TYPE_DETAIL);
        }
    }

    public function isTransactional(): bool
    {
        return !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }
}
