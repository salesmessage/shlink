<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\Visit\Repository;

use Cake\Chronos\Chronos;
use Happyr\DoctrineSpecification\Repository\EntitySpecificationRepository;
use Shlinkio\Shlink\Core\Visit\Entity\Visit;

use function is_numeric;

/** @extends EntitySpecificationRepository<Visit> */
class BotReclassificationQuery extends EntitySpecificationRepository implements BotReclassificationQueryInterface
{
    public function findFirstVisitIdSince(Chronos $since): ?int
    {
        $firstId = $this->getEntityManager()->createQueryBuilder()
            ->select('MIN(v.id)')
            ->from(Visit::class, 'v')
            ->where('v.date >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($firstId) ? (int) $firstId : null;
    }

    public function findUnflaggedShortUrlVisits(Chronos $since, int $afterId, int $limit): array
    {
        /** @var array<array{id: string|int, userAgent: string|null, remoteAddr: string|null}> */
        return $this->getEntityManager()->createQueryBuilder()
            ->select('v.id', 'v.userAgent', 'v.remoteAddr')
            ->from(Visit::class, 'v')
            ->where('v.id > :afterId')
            ->andWhere('v.shortUrl IS NOT NULL')
            ->andWhere('v.potentialBot = :notBot')
            ->andWhere('v.date >= :since')
            ->setParameter('afterId', $afterId)
            ->setParameter('notBot', false)
            ->setParameter('since', $since)
            ->orderBy('v.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();
    }

    public function flagAsPotentialBots(array $visitIds): void
    {
        $this->getEntityManager()->createQueryBuilder()
            ->update(Visit::class, 'v')
            ->set('v.potentialBot', ':bot')
            ->where('v.id IN (:ids)')
            ->setParameter('bot', true)
            ->setParameter('ids', $visitIds)
            ->getQuery()
            ->execute();
    }
}
