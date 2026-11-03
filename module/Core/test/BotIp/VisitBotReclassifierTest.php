<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Core\BotIp;

use Cake\Chronos\Chronos;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shlinkio\Shlink\Core\BotIp\BotIpMatcherInterface;
use Shlinkio\Shlink\Core\BotIp\Model\BotIpSource;
use Shlinkio\Shlink\Core\BotIp\Model\ReclassificationResult;
use Shlinkio\Shlink\Core\BotIp\VisitBotReclassifier;
use Shlinkio\Shlink\Core\Options\BotIpOptions;

use function array_map;
use function array_slice;
use function count;

class VisitBotReclassifierTest extends TestCase
{
    private const SCANNER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:115.0) Gecko/20100101 Firefox/115.0';
    private const LINUX_CHROME = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/141.0.0.0 Safari/537.36';

    private MockObject & EntityManagerInterface $em;
    private MockObject & BotIpMatcherInterface $matcher;
    /** @var array<array{id: int, userAgent: string, remoteAddr: string|null}> */
    private array $visits = [];
    /** @var array<array<int>> */
    private array $updatedIds = [];

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->matcher = $this->createMock(BotIpMatcherInterface::class);
        $this->matcher->method('isInBotNetwork')->willReturnCallback(
            static fn (?string $address, BotIpSource $source) => match ($source) {
                BotIpSource::GOOGLE => $address === '66.249.80.0',
                BotIpSource::SCANNER => $address === '203.127.145.0',
            },
        );
        $this->em->method('createQueryBuilder')->willReturnCallback(fn () => $this->queryBuilder());
    }

    /** @test */
    public function eachRuleFlagsTheVisitsItMatches(): void
    {
        $this->visits = [
            ['id' => 1, 'userAgent' => 'pcp-stier-sdk/1.5.1', 'remoteAddr' => '73.109.12.0'],
            ['id' => 2, 'userAgent' => self::LINUX_CHROME, 'remoteAddr' => '66.249.80.0'],
            ['id' => 3, 'userAgent' => self::SCANNER_UA, 'remoteAddr' => '203.127.145.0'],
            // A person in the scanner's network, on another browser
            ['id' => 4, 'userAgent' => self::LINUX_CHROME, 'remoteAddr' => '203.127.145.0'],
            // The scanner's user agent elsewhere is a person
            ['id' => 5, 'userAgent' => self::SCANNER_UA, 'remoteAddr' => '73.109.12.0'],
            ['id' => 6, 'userAgent' => self::LINUX_CHROME, 'remoteAddr' => null],
        ];

        $result = $this->reclassifier()->reclassify(Chronos::create(2026, 9, 22), 0, 100, false);

        self::assertEquals(1, $result->byUserAgent);
        self::assertEquals(1, $result->byGoogleNetwork);
        self::assertEquals(1, $result->byScanner);
        self::assertEquals(6, $result->scanned);
        self::assertEquals(6, $result->lastId);
        self::assertEquals([[1, 2, 3]], $this->updatedIds);
    }

    /** @test */
    public function dryRunChangesNothing(): void
    {
        $this->visits = [['id' => 1, 'userAgent' => '', 'remoteAddr' => null]];

        $result = $this->reclassifier()->reclassify(Chronos::create(2026, 9, 22), 0, 100, true);

        self::assertEquals(1, $result->marked());
        self::assertEmpty($this->updatedIds);
    }

    /** @test */
    public function visitsAreReadInBatchesFromTheGivenId(): void
    {
        $this->visits = array_map(
            static fn (int $id) => ['id' => $id, 'userAgent' => '', 'remoteAddr' => null],
            [11, 12, 13, 14, 15],
        );
        $batches = [];

        $result = $this->reclassifier()->reclassify(
            Chronos::create(2026, 9, 22),
            10,
            2,
            false,
            static function (ReclassificationResult $r) use (&$batches): void {
                $batches[] = $r->lastId;
            },
        );

        self::assertEquals([12, 14, 15], $batches);
        self::assertEquals([[11, 12], [13, 14], [15]], $this->updatedIds);
        self::assertEquals(15, $result->lastId);
    }

    private function queryBuilder(): MockObject & QueryBuilder
    {
        $qb = $this->createMock(QueryBuilder::class);
        $params = [];
        $maxResults = 0;
        $isUpdate = false;
        foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'set'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('update')->willReturnCallback(function () use ($qb, &$isUpdate) {
            $isUpdate = true;
            return $qb;
        });
        $qb->method('setParameter')->willReturnCallback(function (string $name, mixed $value) use ($qb, &$params) {
            $params[$name] = $value;
            return $qb;
        });
        $qb->method('setMaxResults')->willReturnCallback(function (int $max) use ($qb, &$maxResults) {
            $maxResults = $max;
            return $qb;
        });

        $query = $this->createMock(AbstractQuery::class);
        $query->method('getArrayResult')->willReturnCallback(function () use (&$params, &$maxResults): array {
            $after = [];
            foreach ($this->visits as $visit) {
                if ($visit['id'] > $params['afterId']) {
                    $after[] = $visit;
                }
            }
            return array_slice($after, 0, $maxResults);
        });
        $query->method('execute')->willReturnCallback(function () use (&$params, &$isUpdate) {
            if ($isUpdate) {
                $this->updatedIds[] = $params['ids'];
            }
            return count($params['ids']);
        });
        $qb->method('getQuery')->willReturn($query);

        return $qb;
    }

    private function reclassifier(): VisitBotReclassifier
    {
        return new VisitBotReclassifier($this->em, $this->matcher, new BotIpOptions(scannerUserAgents: [
            self::SCANNER_UA,
        ]));
    }
}
