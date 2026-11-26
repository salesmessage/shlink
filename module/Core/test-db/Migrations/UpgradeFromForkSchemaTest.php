<?php

declare(strict_types=1);

namespace ShlinkioDbTest\Shlink\Core\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\TextType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\AvailableMigration;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Shlinkio\Shlink\Core\ShortUrl\LongUrlHashFiller;
use Shlinkio\Shlink\Rest\Entity\ApiKey;
use Shlinkio\Shlink\TestUtils\DbTest\DatabaseTestCase;

use function array_map;
use function array_values;
use function file_get_contents;
use function Shlinkio\Shlink\Core\stringToBinHash;

/**
 * Runs the migrations of 5.x on the schema production has on the 3.5-dev fork, with the versions it has executed
 * (SWR-25201). Production runs on Postgres, so the test runs only there, in a database of its own.
 */
class UpgradeFromForkSchemaTest extends DatabaseTestCase
{
    private const string DATABASE = 'shlink_upgrade_from_fork_test';
    private const string PLAIN_API_KEY = 'b6f3b5a8-6f8b-4b52-9e3e-6c1d5f0a7c21';
    /** The rollback statement of the runbook: the old image compares the raw key (D-001) */
    private const string ROLLBACK_API_KEY_SQL = 'UPDATE api_keys SET "key" = old_key WHERE old_key IS NOT NULL';
    /** The runbook statement before the new image is deployed again: the hashing migration does not run twice */
    private const string REUPGRADE_API_KEY_SQL =
        'UPDATE api_keys SET "key" = encode(sha256(convert_to(old_key, ' . "'UTF8')), 'hex') WHERE old_key IS NOT NULL";
    /** The migrations of the fork, all executed in production */
    private const array FORK_VERSIONS = [
        'Version20160819142757',
        'Version20160820191203',
        'Version20171021093246',
        'Version20171022064541',
        'Version20180801183328',
        'Version20180913205455',
        'Version20180915110857',
        'Version20181020060559',
        'Version20181020065148',
        'Version20181110175521',
        'Version20190824075137',
        'Version20190930165521',
        'Version20191001201532',
        'Version20191020074522',
        'Version20200105165647',
        'Version20200106215144',
        'Version20200110182849',
        'Version20200323190014',
        'Version20200503170404',
        'Version20201023090929',
        'Version20201102113208',
        'Version20210102174433',
        'Version20210118153932',
        'Version20210202181026',
        'Version20210207100807',
        'Version20210306165711',
        'Version20210522051601',
        'Version20210522124633',
        'Version20210720143824',
        'Version20211002072605',
        'Version20220110113313',
        'Version20230127145327',
        'Version20251230155813',
        'Version20251230163550',
        'Version20260828120000',
        'Version20261104120000',
    ];

    /**
     * Migrations for Microsoft SQL or for the device_long_urls table, which the fork never had: they skip, and
     * doctrine/migrations records no skipped migration, so they stay new on every run (as in upstream)
     */
    private const array SKIPPED_ON_POSTGRES = [
        'ShlinkMigrations\\Version20240226214216',
        'ShlinkMigrations\\Version20240227080629',
        'ShlinkMigrations\\Version20241125213106',
        'ShlinkMigrations\\Version20250215100756',
    ];

    private Connection $admin;
    private Connection $conn;

    protected function setUp(): void
    {
        $connection = $this->getEntityManager()->getConnection();
        if (!$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            self::markTestSkipped('Production runs on Postgres');
        }

        $params = $connection->getParams();
        $this->admin = DriverManager::getConnection([...$params, 'dbname' => 'postgres']);
        $this->admin->executeStatement('DROP DATABASE IF EXISTS ' . self::DATABASE);
        $this->admin->executeStatement('CREATE DATABASE ' . self::DATABASE);

        $this->conn = DriverManager::getConnection([...$params, 'dbname' => self::DATABASE]);
        $this->conn->executeStatement((string) file_get_contents(__DIR__ . '/fixtures/prod-schema-3.5-fork.sql'));
        $this->seedForkData();
    }

    protected function tearDown(): void
    {
        if (!isset($this->admin)) {
            return;
        }

        $this->conn->close();
        $this->admin->executeStatement('DROP DATABASE IF EXISTS ' . self::DATABASE);
        $this->admin->close();
    }

    #[Test]
    public function migratesTheForkSchemaToTheLatestVersion(): void
    {
        $dependencyFactory = $this->migrate();

        self::assertSame(self::SKIPPED_ON_POSTGRES, $this->newMigrations($dependencyFactory));
        $schema = $this->conn->createSchemaManager()->introspectSchema();
        $shortUrls = $schema->getTable('short_urls');
        // @mago-expect analysis:deprecated-method (deprecated in a newer doctrine/dbal than upstream released with)
        self::assertInstanceOf(TextType::class, $shortUrls->getColumn('original_url')->getType());
        self::assertTrue($shortUrls->hasColumn('long_url_hash'));
        self::assertTrue($schema->getTable('visits')->hasColumn('redirect_url'));
        foreach (['short_url_visits_counts', 'orphan_visits_counts', 'short_url_redirect_rules'] as $table) {
            self::assertTrue($schema->hasTable($table), $table);
        }
        // The index of the fork stays, upstream's copy of it is never built (D-032), and the new one is on potential_bot
        $visits = $schema->getTable('visits');
        self::assertTrue($visits->hasIndex('IDX_visits_short_url_id_potential_bot'));
        self::assertFalse($visits->hasIndex('IDX_visits_potential_bot'));
        self::assertSame(['potential_bot'], $visits->getIndex('visits_potential_bot_IDX')->getColumns());
        self::assertSame(
            [['device_type' => 'mobile', 'device_type_detail' => 'iphone']],
            $this->conn->fetchAllAssociative('SELECT device_type, device_type_detail FROM visits WHERE id = 1'),
        );
    }

    #[Test]
    public function aSecondRunMigratesNothingMore(): void
    {
        $this->migrate();
        $dependencyFactory = $this->migrate();

        self::assertSame(self::SKIPPED_ON_POSTGRES, $this->newMigrations($dependencyFactory));
    }

    #[Test]
    public function keepsThePlainApiKeyForTheRollbackAndTheUpgradeAfterIt(): void
    {
        $this->migrate();

        self::assertSame(
            ['key' => ApiKey::hashKey(self::PLAIN_API_KEY), 'old_key' => self::PLAIN_API_KEY],
            $this->conn->fetchAssociative('SELECT "key", old_key FROM api_keys WHERE id = 1'),
        );

        $this->conn->executeStatement(self::ROLLBACK_API_KEY_SQL);

        self::assertSame(self::PLAIN_API_KEY, $this->conn->fetchOne('SELECT "key" FROM api_keys WHERE id = 1'));

        $this->conn->executeStatement(self::REUPGRADE_API_KEY_SQL);

        self::assertSame(
            ApiKey::hashKey(self::PLAIN_API_KEY),
            $this->conn->fetchOne('SELECT "key" FROM api_keys WHERE id = 1'),
        );
    }

    #[Test]
    public function countsTheVisitsTheForkTracked(): void
    {
        $this->migrate();

        self::assertSame(['1:false:2', '1:true:1', '2:false:1'], $this->conn->fetchFirstColumn(
            "SELECT short_url_id || ':' || potential_bot || ':' || SUM(count) FROM short_url_visits_counts "
            . 'GROUP BY short_url_id, potential_bot ORDER BY short_url_id, potential_bot',
        ));
        self::assertSame(1, (int) $this->conn->fetchOne(
            'SELECT SUM(count) FROM orphan_visits_counts WHERE potential_bot = true',
        ));
    }

    #[Test]
    public function leavesTheLongUrlHashToTheFillCommand(): void
    {
        $this->migrate();

        self::assertSame(2, $this->countShortUrlsWithHash(''));

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->conn);
        new LongUrlHashFiller($em)->fill(fromId: 0, batchSize: 1000);

        self::assertSame(0, $this->countShortUrlsWithHash(''));
        self::assertSame(1, $this->countShortUrlsWithHash(stringToBinHash('https://example.com/first')));
    }

    /**
     * After a rollback the old image writes rows without the columns 5.x added
     */
    #[Test]
    public function theOldImageCanStillWriteAfterTheMigrations(): void
    {
        $this->migrate();

        $this->conn->executeStatement(
            "INSERT INTO short_urls (domain_id, author_api_key_id, original_url, short_code, date_created)
             VALUES (NULL, 1, 'https://example.com/after-rollback', 'ghi56', '2026-12-01 10:00:00')",
        );
        $this->conn->executeStatement(
            "INSERT INTO visits (short_url_id, date, type, potential_bot, device_type, device_type_detail)
             VALUES (1, '2026-12-01 10:00:00', 'valid_short_url', false, 'desktop', 'windows')",
        );

        self::assertSame(3, $this->countShortUrlsWithHash(''));
    }

    private function migrate(): DependencyFactory
    {
        $dependencyFactory = DependencyFactory::fromConnection(new ConfigurationArray([
            'migrations_paths' => ['ShlinkMigrations' => __DIR__ . '/../../migrations'],
            'table_storage' => ['table_name' => 'migrations'],
        ]), new ExistingConnection($this->conn));
        $dependencyFactory->getMetadataStorage()->ensureInitialized();

        $latest = $dependencyFactory->getVersionAliasResolver()->resolveVersionAlias('latest');
        $plan = $dependencyFactory->getMigrationPlanCalculator()->getPlanUntilVersion($latest);
        $dependencyFactory->getMigrator()->migrate($plan, new MigratorConfiguration());

        return $dependencyFactory;
    }

    /**
     * @return list<string>
     */
    private function newMigrations(DependencyFactory $dependencyFactory): array
    {
        return array_values(array_map(
            static fn (AvailableMigration $migration) => (string) $migration->getVersion(),
            $dependencyFactory->getMigrationStatusCalculator()->getNewMigrations()->getItems(),
        ));
    }

    private function countShortUrlsWithHash(string $hash): int
    {
        return (int) $this->conn->fetchOne(
            'SELECT COUNT(id) FROM short_urls WHERE long_url_hash = :hash',
            ['hash' => $hash],
            ['hash' => Types::BINARY],
        );
    }

    private function seedForkData(): void
    {
        foreach (self::FORK_VERSIONS as $version) {
            $this->conn->insert('migrations', [
                'version' => 'ShlinkMigrations\\' . $version,
                'executed_at' => '2026-01-01 00:00:00',
                'execution_time' => 1,
            ]);
        }

        $this->conn->insert(
            'api_keys',
            ['id' => 1, 'key' => self::PLAIN_API_KEY, 'enabled' => true],
            [
                'enabled' => Types::BOOLEAN,
            ],
        );
        $this->conn->executeStatement(<<<'SQL'
            INSERT INTO domains (id, authority) VALUES (1, 'sm.link');
            INSERT INTO short_urls (id, domain_id, author_api_key_id, original_url, short_code, date_created) VALUES
                (1, NULL, 1, 'https://example.com/first', 'abc12', '2025-01-01 10:00:00'),
                (2, 1, 1, 'https://example.com/second?utm=1', 'def34', '2025-01-02 10:00:00');
            INSERT INTO visit_locations (id, country_code, country_name, lat, lon, is_empty)
                VALUES (1, 'US', 'United States', 1.5, 2.5, false);
            INSERT INTO visits
                (id, short_url_id, visit_location_id, date, user_agent, type, potential_bot, device_type,
                 device_type_detail)
            VALUES
                (1, 1, 1, '2025-01-03 10:00:00', 'Mozilla/5.0', 'valid_short_url', false, 'mobile', 'iphone'),
                (2, 1, NULL, '2025-01-03 11:00:00', 'Mozilla/5.0', 'valid_short_url', false, 'desktop', 'mac'),
                (3, 1, NULL, '2025-01-03 12:00:00', '', 'valid_short_url', true, NULL, NULL),
                (4, 2, NULL, '2025-01-04 10:00:00', 'Mozilla/5.0', 'valid_short_url', false, 'tablet', 'ipad'),
                (5, NULL, NULL, '2025-01-05 10:00:00', 'curl/8.0', 'base_url', true, NULL, NULL);
            SELECT setval('api_keys_id_seq', 1);
            SELECT setval('domains_id_seq', 1);
            SELECT setval('short_urls_id_seq', 2);
            SELECT setval('visit_locations_id_seq', 1);
            SELECT setval('visits_id_seq', 5);
            SQL);
    }
}
