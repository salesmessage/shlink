<?php

declare(strict_types=1);

namespace ShlinkioDbTest\Shlink\Core\ShortUrl;

use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\Test;
use Shlinkio\Shlink\Core\ShortUrl\Entity\ShortUrl;
use Shlinkio\Shlink\Core\ShortUrl\LongUrlHashFiller;
use Shlinkio\Shlink\Core\ShortUrl\Model\LongUrlHashFillResult;
use Shlinkio\Shlink\Core\ShortUrl\Model\ShortUrlCreation;
use Shlinkio\Shlink\TestUtils\DbTest\DatabaseTestCase;

use function Shlinkio\Shlink\Core\stringToBinHash;

class LongUrlHashFillerTest extends DatabaseTestCase
{
    private LongUrlHashFiller $filler;

    protected function setUp(): void
    {
        $this->filler = new LongUrlHashFiller($this->getEntityManager());
    }

    #[Test]
    public function fillsOnlyEmptyHashesInBatchesAndReportsWhatIsLeft(): void
    {
        $first = $this->shortUrlWithEmptyHash('https://first.test');
        $kept = $this->shortUrl('https://kept.test');
        $second = $this->shortUrlWithEmptyHash('https://second.test');
        $batches = [];

        $leftBefore = $this->filler->countLeft();
        $result = $this->filler->fill(0, 1, static function (LongUrlHashFillResult $batch) use (&$batches): void {
            $batches[] = [$batch->filled, $batch->lastId];
        });

        self::assertSame(2, $leftBefore);
        self::assertSame(2, $result->filled);
        self::assertSame((int) $second->getId(), $result->lastId);
        self::assertSame([[1, (int) $first->getId()], [2, (int) $second->getId()]], $batches);
        self::assertSame(0, $this->filler->countLeft());
        self::assertTrue($this->hasHashOf($first, 'https://first.test'));
        self::assertTrue($this->hasHashOf($second, 'https://second.test'));
        self::assertTrue($this->hasHashOf($kept, 'https://kept.test'));
    }

    #[Test]
    public function resumesAfterTheGivenId(): void
    {
        $first = $this->shortUrlWithEmptyHash('https://first.test');
        $second = $this->shortUrlWithEmptyHash('https://second.test');

        $result = $this->filler->fill((int) $first->getId(), 100);

        self::assertSame(1, $result->filled);
        self::assertSame((int) $second->getId(), $result->lastId);
        self::assertSame(1, $this->filler->countLeft());
        self::assertTrue($this->hasHashOf($second, 'https://second.test'));
        self::assertFalse($this->hasHashOf($first, 'https://first.test'));
    }

    #[Test]
    public function nothingToFillKeepsTheStartId(): void
    {
        $this->shortUrl('https://kept.test');

        $result = $this->filler->fill(7, 100);

        self::assertSame(0, $result->filled);
        self::assertSame(7, $result->lastId);
    }

    private function shortUrl(string $longUrl): ShortUrl
    {
        $shortUrl = ShortUrl::create(new ShortUrlCreation($longUrl));
        $this->getEntityManager()->persist($shortUrl);
        $this->getEntityManager()->flush();

        return $shortUrl;
    }

    /**
     * A row as the old image wrote it before the switch to 5.x (D-009)
     */
    private function shortUrlWithEmptyHash(string $longUrl): ShortUrl
    {
        $shortUrl = $this->shortUrl($longUrl);
        $this
            ->getEntityManager()
            ->getConnection()
            ->executeStatement(
                'UPDATE short_urls SET long_url_hash = :empty WHERE id = :id',
                ['empty' => '', 'id' => $shortUrl->getId()],
                ['empty' => Types::BINARY],
            );

        return $shortUrl;
    }

    private function hasHashOf(ShortUrl $shortUrl, string $longUrl): bool
    {
        $count = $this
            ->getEntityManager()
            ->getConnection()
            ->fetchOne(
                'SELECT COUNT(*) FROM short_urls WHERE id = :id AND long_url_hash = :hash',
                ['id' => $shortUrl->getId(), 'hash' => stringToBinHash($longUrl)],
                ['hash' => Types::BINARY],
            );

        return (int) $count === 1;
    }
}
