<?php

declare(strict_types=1);

namespace ShlinkMigrations;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use PDO;
use Shlinkio\Shlink\Common\Exception\InvalidArgumentException;
use Shlinkio\Shlink\Common\Util\IpAddress;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20180913205455 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        // Nothing to create
    }

    /**
     * @throws Exception
     */
    public function postUp(Schema $schema): void
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->select('id', 'remote_addr')
           ->from('visits');
        $st = $this->connection->executeQuery($qb->getSQL());

        $qb = $this->connection->createQueryBuilder();
        // Note: the SET target column must NOT be qualified with the table alias.
        // PostgreSQL rejects `SET v.remote_addr = ...` (SQLSTATE 42703), while MySQL tolerated it.
        $qb->update('visits', 'v')
           ->set('remote_addr', ':obfuscatedAddr')
           ->where('v.id=:id');

        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $addr = $row['remote_addr'] ?? null;
            if ($addr === null) {
                continue;
            }

            $qb->setParameters([
                'id' => $row['id'],
                'obfuscatedAddr' => $this->determineAddress((string) $addr),
            ])->execute();
        }
    }

    private function determineAddress(string $addr): ?string
    {
        if ($addr === IpAddress::LOCALHOST) {
            return $addr;
        }

        try {
            return (string) IpAddress::fromString($addr)->getAnonymizedCopy();
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function down(Schema $schema): void
    {
        // Nothing to rollback
    }

    public function isTransactional(): bool
    {
        return ! ($this->connection->getDatabasePlatform() instanceof MySQLPlatform);
    }
}
