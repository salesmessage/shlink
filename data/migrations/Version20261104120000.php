<?php

declare(strict_types=1);

namespace ShlinkMigrations;

use Cake\Chronos\Chronos;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * SWR-26047: bot IP ranges. Visits from them are tracked as potential bots.
 *
 * The `google` rows are downloaded at runtime. The `scanner` rows are seeded here: a link scanner that opened every
 * link of a broadcast twice, once as Android and once as Windows, never as an iPhone, seconds after the send. The
 * addresses come from the proxy's CloudWatch logs over two weeks (2026-10-21..2026-11-03), unchanged over that time;
 * whois: Pulsant (GB, hosting), Singtel (SG, mobile network) and Simba Telecom (SG, mobile network). Exact addresses
 * only, never their networks: the Singtel and Simba networks carry real subscribers.
 */
final class Version20261104120000 extends AbstractMigration
{
    private const TABLE = 'bot_ip_ranges';
    private const SCANNER_ADDRESSES = [
        '87.246.116.168/32',
        '87.246.116.169/32',
        '203.127.145.64/32',
        '203.127.145.66/32',
        '210.10.10.75/32',
        '210.10.11.75/32',
    ];

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable(self::TABLE));

        $table = $schema->createTable(self::TABLE);
        $table->addColumn('id', Types::BIGINT, ['unsigned' => true, 'autoincrement' => true, 'notnull' => true]);
        $table->setPrimaryKey(['id']);
        $table->addColumn('ip', Types::STRING, ['length' => 64, 'notnull' => true]);
        $table->addColumn('source', Types::STRING, ['length' => 32, 'notnull' => true]);
        $table->addColumn('created_at', Types::DATETIME_MUTABLE, ['notnull' => true]);
        $table->addColumn('updated_at', Types::DATETIME_MUTABLE, ['notnull' => true]);
        $table->addUniqueIndex(['ip', 'source'], 'UQ_bot_ip_ranges_ip_source');
    }

    public function postUp(Schema $schema): void
    {
        $now = Chronos::now()->toDateTimeString();
        foreach (self::SCANNER_ADDRESSES as $ip) {
            $this->connection->insert(self::TABLE, [
                'ip' => $ip,
                'source' => 'scanner',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(! $schema->hasTable(self::TABLE));
        $schema->dropTable(self::TABLE);
    }

    public function isTransactional(): bool
    {
        return ! ($this->connection->getDatabasePlatform() instanceof MySQLPlatform);
    }
}
