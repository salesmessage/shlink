<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\ShortUrl;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Shlinkio\Shlink\Core\ShortUrl\Model\LongUrlHashFillResult;

use function array_key_last;
use function count;
use function Shlinkio\Shlink\Core\stringToBinHash;

/**
 * Fills long_url_hash of the short URLs created before the upgrade to 5.x, in place of upstream's
 * Version20260607082210, so the migration window does not wait for it (SWR-25201, D-009).
 */
readonly class LongUrlHashFiller implements LongUrlHashFillerInterface
{
    public function __construct(private EntityManagerInterface $em) {}

    public function fill(int $fromId, int $batchSize, callable|null $onBatch = null): LongUrlHashFillResult
    {
        $conn = $this->em->getConnection();
        $filled = 0;
        $lastId = $fromId;

        do {
            $rows = $this->findWithEmptyHash($conn, $lastId, $batchSize);
            if ($rows === []) {
                break;
            }

            $conn->transactional(static function (Connection $conn) use ($rows): void {
                foreach ($rows as $row) {
                    $conn->update(
                        'short_urls',
                        ['long_url_hash' => stringToBinHash($row['original_url'])],
                        ['id' => $row['id']],
                        ['long_url_hash' => Types::BINARY],
                    );
                }
            });

            $filled += count($rows);
            $lastId = (int) $rows[array_key_last($rows)]['id'];
            if ($onBatch !== null) {
                $onBatch(new LongUrlHashFillResult($filled, $lastId));
            }
        } while (count($rows) === $batchSize);

        return new LongUrlHashFillResult($filled, $lastId);
    }

    public function countLeft(): int
    {
        $qb = $this->em->getConnection()->createQueryBuilder();
        $qb
            ->select('COUNT(id)')
            ->from('short_urls')
            ->where($qb->expr()->eq('long_url_hash', ':empty'))
            ->setParameter('empty', '', Types::BINARY);

        return (int) $qb->executeQuery()->fetchOne();
    }

    /**
     * @return list<array{id: int|string, original_url: string}>
     */
    private function findWithEmptyHash(Connection $conn, int $afterId, int $limit): array
    {
        $qb = $conn->createQueryBuilder();
        $qb
            ->select('id', 'original_url')
            ->from('short_urls')
            ->where($qb->expr()->gt('id', ':afterId'))
            ->andWhere($qb->expr()->eq('long_url_hash', ':empty'))
            ->setParameter('afterId', $afterId)
            ->setParameter('empty', '', Types::BINARY)
            ->orderBy('id', 'ASC')
            ->setMaxResults($limit);

        /** @var list<array{id: int|string, original_url: string}> */
        return $qb->executeQuery()->fetchAllAssociative();
    }
}
