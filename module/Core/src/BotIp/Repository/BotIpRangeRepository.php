<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\BotIp\Repository;

use Cake\Chronos\Chronos;
use Happyr\DoctrineSpecification\Repository\EntitySpecificationRepository;
use Shlinkio\Shlink\Core\BotIp\Entity\BotIpRange;
use Shlinkio\Shlink\Core\BotIp\Model\BotIpSource;

use function is_string;
use function Shlinkio\Shlink\Core\normalizeOptionalDate;

/** @extends EntitySpecificationRepository<BotIpRange> */
class BotIpRangeRepository extends EntitySpecificationRepository implements BotIpRangeRepositoryInterface
{
    public function findAllCidrsBySource(): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('r.ip', 'r.source')
            ->getQuery()
            ->getArrayResult();

        $cidrsBySource = [];
        foreach ($rows as $row) {
            $cidrsBySource[$row['source']][] = $row['ip'];
        }

        return $cidrsBySource;
    }

    public function findBySource(BotIpSource $source): array
    {
        /** @var BotIpRange[] $ranges */
        $ranges = $this->findBy(['source' => $source->value]);

        $rangesByIp = [];
        foreach ($ranges as $range) {
            $rangesByIp[$range->ip()] = $range;
        }

        return $rangesByIp;
    }

    public function findLastSeenAt(BotIpSource $source): ?Chronos
    {
        $lastSeenAt = $this->createQueryBuilder('r')
            ->select('MAX(r.updatedAt)')
            ->where('r.source = :source')
            ->setParameter('source', $source->value)
            ->getQuery()
            ->getSingleScalarResult();

        return is_string($lastSeenAt) ? normalizeOptionalDate($lastSeenAt) : null;
    }
}
