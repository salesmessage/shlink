<?php

declare(strict_types=1);

namespace ShlinkioDbTest\Shlink\Core\Visit\Repository;

use Cake\Chronos\Chronos;
use PHPUnit\Framework\Attributes\Test;
use Shlinkio\Shlink\Core\ShortUrl\Entity\ShortUrl;
use Shlinkio\Shlink\Core\Visit\Entity\Visit;
use Shlinkio\Shlink\Core\Visit\Model\Visitor;
use Shlinkio\Shlink\Core\Visit\Repository\BotReclassificationQuery;
use Shlinkio\Shlink\TestUtils\DbTest\DatabaseTestCase;

use function array_column;
use function array_map;

class BotReclassificationQueryTest extends DatabaseTestCase
{
    private const string BROWSER =
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
            . 'Chrome/141.0.0.0 Safari/537.36';

    private BotReclassificationQuery $query;

    protected function setUp(): void
    {
        $this->query = new BotReclassificationQuery(
            $this->getEntityManager(),
            $this->getEntityManager()->getClassMetadata(Visit::class),
        );
    }

    #[Test]
    public function onlyUnflaggedShortUrlVisitsSinceTheDateAreReadAndFlagged(): void
    {
        $em = $this->getEntityManager();
        $shortUrl = ShortUrl::createFake();
        $em->persist($shortUrl);

        $before = $this->visit(fn () => Visit::forValidShortUrl(
            $shortUrl,
            $this->person('1.1.1.1'),
        ), '2026-09-01 12:00:00');
        $first = $this->visit(fn () => Visit::forValidShortUrl(
            $shortUrl,
            $this->person('66.249.80.1'),
        ), '2026-09-22 12:00:00');
        $bot = $this->visit(
            static fn () => Visit::forValidShortUrl($shortUrl, Visitor::fromParams(userAgent: 'cf-facebook')),
            '2026-09-23 12:00:00',
        );
        $orphan = $this->visit(fn () => Visit::forBasePath($this->person('2.2.2.2')), '2026-09-23 12:00:00');
        $second = $this->visit(fn () => Visit::forValidShortUrl(
            $shortUrl,
            $this->person('3.3.3.3'),
        ), '2026-09-24 12:00:00');
        $em->flush();

        $since = Chronos::create(2026, 9, 22, 0, 0, 0);
        self::assertEquals((int) $first->getId(), $this->query->findFirstVisitIdSince($since));
        self::assertNull($this->query->findFirstVisitIdSince(Chronos::create(2026, 10, 1, 0, 0, 0)));

        $found = $this->query->findUnflaggedShortUrlVisits($since, 0, 10);
        self::assertEquals(
            [(int) $first->getId(), (int) $second->getId()],
            array_map('intval', array_column($found, 'id')),
        );
        self::assertEquals('66.249.80.0', $found[0]['remoteAddr']);
        self::assertEquals(self::BROWSER, $found[0]['userAgent']);
        self::assertCount(1, $this->query->findUnflaggedShortUrlVisits($since, (int) $first->getId(), 10));
        self::assertCount(1, $this->query->findUnflaggedShortUrlVisits($since, 0, 1));

        $this->query->flagAsPotentialBots([$first->getId()]);
        $em->clear();

        self::assertTrue($em->find(Visit::class, $first->getId())?->potentialBot);
        self::assertFalse($em->find(Visit::class, $second->getId())?->potentialBot);
        self::assertFalse($em->find(Visit::class, $before->getId())?->potentialBot);
        self::assertFalse($em->find(Visit::class, $orphan->getId())?->potentialBot);
        self::assertTrue($em->find(Visit::class, $bot->getId())?->potentialBot);
    }

    private function person(string $address): Visitor
    {
        return Visitor::fromParams(userAgent: self::BROWSER, remoteAddress: $address);
    }

    /**
     * @param callable(): Visit $createVisit
     */
    private function visit(callable $createVisit, string $date): Visit
    {
        Chronos::setTestNow(Chronos::parse($date));
        $visit = $createVisit();
        Chronos::setTestNow();
        $this->getEntityManager()->persist($visit);

        return $visit;
    }
}
