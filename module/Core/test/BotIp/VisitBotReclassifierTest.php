<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Core\BotIp;

use Cake\Chronos\Chronos;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shlinkio\Shlink\Core\BotIp\BotIpMatcherInterface;
use Shlinkio\Shlink\Core\BotIp\Model\BotIpSource;
use Shlinkio\Shlink\Core\BotIp\Model\ReclassificationResult;
use Shlinkio\Shlink\Core\BotIp\VisitBotReclassifier;
use Shlinkio\Shlink\Core\Visit\Repository\BotReclassificationQueryInterface;

use function array_map;
use function array_slice;
use function array_values;

#[AllowMockObjectsWithoutExpectations]
class VisitBotReclassifierTest extends TestCase
{
    private const string FIREFOX_115 = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:115.0) Gecko/20100101 Firefox/115.0';
    private const string LINUX_CHROME =
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) ' . 'Chrome/141.0.0.0 Safari/537.36';

    private MockObject&BotReclassificationQueryInterface $query;
    private BotIpMatcherInterface $matcher;
    /** @var array<array{id: int, userAgent: string, remoteAddr: string|null}> */
    private array $visits = [];
    /** @var list<list<mixed>> */
    private array $flaggedIds = [];
    /** @var int[] */
    private array $requestedAfterIds = [];

    protected function setUp(): void
    {
        $this->query = $this->createMock(BotReclassificationQueryInterface::class);
        $this->query
            ->method('findUnflaggedShortUrlVisits')
            ->willReturnCallback(
                function (Chronos $since, int $afterId, int $limit): array {
                    $this->requestedAfterIds[] = $afterId;
                    $after = [];
                    foreach ($this->visits as $visit) {
                        if ($visit['id'] > $afterId) {
                            $after[] = $visit;
                        }
                    }
                    return array_slice($after, 0, $limit);
                },
            );
        $this->query
            ->method('flagAsPotentialBots')
            ->willReturnCallback(function (array $ids): void {
                $this->flaggedIds[] = array_values($ids);
            });

        $matcher = $this->createStub(BotIpMatcherInterface::class);
        $matcher
            ->method('isInBotNetwork')
            ->willReturnCallback(
                static fn (string|null $address, BotIpSource $source) => $address === '66.249.80.0',
            );
        $this->matcher = $matcher;
    }

    #[Test]
    public function eachRuleFlagsTheVisitsItMatches(): void
    {
        $this->visits = [
            ['id' => 1, 'userAgent' => 'pcp-stier-sdk/1.5.1', 'remoteAddr' => '73.109.12.0'],
            ['id' => 2, 'userAgent' => self::LINUX_CHROME, 'remoteAddr' => '66.249.80.0'],
            // A link scanner that passes as a person is not flagged
            ['id' => 3, 'userAgent' => self::FIREFOX_115, 'remoteAddr' => '203.127.145.0'],
            ['id' => 4, 'userAgent' => self::LINUX_CHROME, 'remoteAddr' => '73.109.12.0'],
            ['id' => 5, 'userAgent' => self::LINUX_CHROME, 'remoteAddr' => null],
        ];

        $result = $this->reclassifier()->reclassify(Chronos::create(2026, 9, 22), 0, 100, false);

        self::assertEquals(1, $result->byUserAgent);
        self::assertEquals(1, $result->byGoogleNetwork);
        self::assertEquals(5, $result->scanned);
        self::assertEquals(5, $result->lastId);
        self::assertEquals([[1, 2]], $this->flaggedIds);
    }

    #[Test]
    public function dryRunChangesNothing(): void
    {
        $this->visits = [['id' => 1, 'userAgent' => '', 'remoteAddr' => null]];
        $this->query->expects($this->never())->method('flagAsPotentialBots');

        $result = $this->reclassifier()->reclassify(Chronos::create(2026, 9, 22), 0, 100, true);

        self::assertEquals(1, $result->marked());
    }

    #[Test]
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
        self::assertEquals([[11, 12], [13, 14], [15]], $this->flaggedIds);
        self::assertEquals(15, $result->lastId);
    }

    #[Test, DataProvider('provideStartingPoints')]
    public function passStartsAtTheFirstVisitSinceTheDate(
        int $fromId,
        int|null $firstIdSince,
        int $expectedAfterId,
    ): void {
        $this->query->method('findFirstVisitIdSince')->willReturn($firstIdSince);

        $this->reclassifier()->reclassify(Chronos::create(2026, 9, 22), $fromId, 100, true);

        self::assertEquals([$expectedAfterId], $this->requestedAfterIds);
    }

    public static function provideStartingPoints(): iterable
    {
        yield 'no from-id: skips the older history' => [0, 5000, 4999];
        yield 'from-id past the first visit wins' => [7000, 5000, 7000];
        yield 'no visit since the date' => [0, null, 0];
    }

    private function reclassifier(): VisitBotReclassifier
    {
        return new VisitBotReclassifier($this->query, $this->matcher);
    }
}
