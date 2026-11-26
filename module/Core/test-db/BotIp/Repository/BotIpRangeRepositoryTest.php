<?php

declare(strict_types=1);

namespace ShlinkioDbTest\Shlink\Core\BotIp\Repository;

use Cake\Chronos\Chronos;
use PHPUnit\Framework\Attributes\Test;
use Shlinkio\Shlink\Core\BotIp\Entity\BotIpRange;
use Shlinkio\Shlink\Core\BotIp\Model\BotIpSource;
use Shlinkio\Shlink\Core\BotIp\Repository\BotIpRangeRepository;
use Shlinkio\Shlink\TestUtils\DbTest\DatabaseTestCase;

use function array_keys;

class BotIpRangeRepositoryTest extends DatabaseTestCase
{
    private BotIpRangeRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new BotIpRangeRepository(
            $this->getEntityManager(),
            $this->getEntityManager()->getClassMetadata(BotIpRange::class),
        );
    }

    #[Test]
    public function nothingIsFoundWhenNoRangeIsStored(): void
    {
        self::assertSame([], $this->repo->findAllCidrsBySource());
        self::assertSame([], $this->repo->findBySource(BotIpSource::GOOGLE));
        self::assertNull($this->repo->findLastSeenAt(BotIpSource::GOOGLE));
    }

    #[Test]
    public function storedRangesAreFoundBySourceWithTheirLastSeenDate(): void
    {
        $em = $this->getEntityManager();
        $older = BotIpRange::seenNow('66.249.64.0/27', BotIpSource::GOOGLE, Chronos::create(2026, 10, 1, 8));
        $newer = BotIpRange::seenNow('2001:4860:4801:10::/64', BotIpSource::GOOGLE, Chronos::create(2026, 10, 1, 8));
        $newer->markSeen(Chronos::create(2026, 11, 3, 9, 30));
        $em->persist($older);
        $em->persist($newer);
        $em->flush();
        $em->clear();

        self::assertEquals(
            ['google' => ['66.249.64.0/27', '2001:4860:4801:10::/64']],
            $this->repo->findAllCidrsBySource(),
        );
        self::assertEquals(
            ['66.249.64.0/27', '2001:4860:4801:10::/64'],
            array_keys($this->repo->findBySource(BotIpSource::GOOGLE)),
        );
        self::assertEquals(Chronos::create(2026, 11, 3, 9, 30), $this->repo->findLastSeenAt(BotIpSource::GOOGLE));
    }
}
