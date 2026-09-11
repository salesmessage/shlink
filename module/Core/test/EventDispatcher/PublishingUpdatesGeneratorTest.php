<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Core\EventDispatcher;

use PHPUnit\Framework\TestCase;
use Shlinkio\Shlink\Common\UpdatePublishing\Update;
use Shlinkio\Shlink\Core\EventDispatcher\PublishingUpdatesGenerator;
use Shlinkio\Shlink\Core\EventDispatcher\Topic;
use Shlinkio\Shlink\Core\ShortUrl\Entity\ShortUrl;
use Shlinkio\Shlink\Core\ShortUrl\Helper\ShortUrlStringifier;
use Shlinkio\Shlink\Core\ShortUrl\Model\ShortUrlCreation;
use Shlinkio\Shlink\Core\ShortUrl\Transformer\ShortUrlDataTransformer;
use Shlinkio\Shlink\Core\Visit\DeviceClassifier;
use Shlinkio\Shlink\Core\Visit\Entity\Visit;
use Shlinkio\Shlink\Core\Visit\Entity\VisitLocation;
use Shlinkio\Shlink\Core\Visit\Model\DeviceClass;
use Shlinkio\Shlink\Core\Visit\Model\Visitor;
use Shlinkio\Shlink\Core\Visit\Model\VisitType;
use Shlinkio\Shlink\Core\Visit\Transformer\OrphanVisitDataTransformer;
use Shlinkio\Shlink\IpGeolocation\Model\Location;

class PublishingUpdatesGeneratorTest extends TestCase
{
    private PublishingUpdatesGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new PublishingUpdatesGenerator(
            new ShortUrlDataTransformer(new ShortUrlStringifier([])),
            new OrphanVisitDataTransformer(),
        );
    }

    /**
     * @test
     * @dataProvider provideMethod
     */
    public function visitIsProperlySerializedIntoUpdate(string $method, string $expectedTopic, ?string $title): void
    {
        $shortUrl = ShortUrl::create(ShortUrlCreation::fromRawData([
            'customSlug' => 'foo',
            'longUrl' => '',
            'title' => $title,
        ]));
        $visit = Visit::forValidShortUrl($shortUrl, Visitor::emptyInstance());

        /** @var Update $update */
        $update = $this->generator->{$method}($visit);

        self::assertEquals($expectedTopic, $update->topic);
        self::assertEquals([
            'shortUrl' => [
                'shortCode' => $shortUrl->getShortCode(),
                'shortUrl' => 'http:/' . $shortUrl->getShortCode(),
                'longUrl' => '',
                'dateCreated' => $shortUrl->getDateCreated()->toAtomString(),
                'tags' => [],
                'meta' => [
                    'validSince' => null,
                    'validUntil' => null,
                    'maxVisits' => null,
                ],
                'domain' => null,
                'title' => $title,
                'crawlable' => false,
                'forwardQuery' => true,
            ],
            'visit' => [
                'referer' => '',
                'userAgent' => '',
                'visitLocation' => null,
                'date' => $visit->getDate()->toAtomString(),
                'potentialBot' => false,
                'id' => 0,
                'visitedUrl' => '',
                'deviceType' => DeviceClass::OTHER->value,
                'deviceTypeDetail' => DeviceClassifier::DETAIL_UNKNOWN,
            ],
        ], $update->payload);
    }

    public function provideMethod(): iterable
    {
        yield 'newVisitUpdate' => ['newVisitUpdate', 'https://shlink.io/new-visit', 'the cool title'];
        yield 'newShortUrlVisitUpdate' => ['newShortUrlVisitUpdate', 'https://shlink.io/new-visit/foo', null];
    }

    /**
     * @test
     * @dataProvider provideOrphanVisits
     */
    public function orphanVisitIsProperlySerializedIntoUpdate(Visit $orphanVisit): void
    {
        $update = $this->generator->newOrphanVisitUpdate($orphanVisit);

        self::assertEquals('https://shlink.io/new-orphan-visit', $update->topic);
        self::assertEquals([
            'visit' => [
                'referer' => '',
                'userAgent' => '',
                'visitLocation' => null,
                'date' => $orphanVisit->getDate()->toAtomString(),
                'potentialBot' => false,
                'visitedUrl' => $orphanVisit->visitedUrl(),
                'type' => $orphanVisit->type()->value,
                'id' => 0,
                'deviceType' => DeviceClass::OTHER->value,
                'deviceTypeDetail' => DeviceClassifier::DETAIL_UNKNOWN,
            ],
        ], $update->payload);
    }

    public function provideOrphanVisits(): iterable
    {
        $visitor = Visitor::emptyInstance();

        yield VisitType::REGULAR_404->value => [Visit::forRegularNotFound($visitor)];
        yield VisitType::INVALID_SHORT_URL->value => [Visit::forInvalidShortUrl($visitor)];
        yield VisitType::BASE_URL->value => [Visit::forBasePath($visitor)];
    }

    /** @test */
    public function shortUrlIsProperlySerializedIntoUpdate(): void
    {
        $shortUrl = ShortUrl::create(ShortUrlCreation::fromRawData([
            'customSlug' => 'foo',
            'longUrl' => '',
            'title' => 'The title',
        ]));

        $update = $this->generator->newShortUrlUpdate($shortUrl);

        self::assertEquals(Topic::NEW_SHORT_URL->value, $update->topic);
        self::assertEquals(['shortUrl' => [
            'shortCode' => $shortUrl->getShortCode(),
            'shortUrl' => 'http:/' . $shortUrl->getShortCode(),
            'longUrl' => '',
            'dateCreated' => $shortUrl->getDateCreated()->toAtomString(),
            'tags' => [],
            'meta' => [
                'validSince' => null,
                'validUntil' => null,
                'maxVisits' => null,
            ],
            'domain' => null,
            'title' => $shortUrl->title(),
            'crawlable' => false,
            'forwardQuery' => true,
        ]], $update->payload);
    }

    /**
     * @test
     * @dataProvider provideVisitPublishingMethods
     * @group spec:click-tracking:AC-11
     */
    public function publishedVisitCarriesEveryElementTheConsumerDependsOn(string $method): void
    {
        $shortUrl = ShortUrl::create(ShortUrlCreation::fromRawData([
            'customSlug' => 'aB3xK',
            'longUrl' => 'https://example.com/spring-sale',
        ]));
        $visitedUrl = 'https://sh.example.com/aB3xK?smg_domain=go.example.com&utm_sm_mid=90210';
        $visit = Visit::forValidShortUrl($shortUrl, new Visitor(
            'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) Mobile/15E148 Safari/604.1',
            '',
            '1.2.3.4',
            $visitedUrl,
        ))->locate(VisitLocation::fromGeolocation(
            new Location('CA', 'Canada', 'Ontario', 'Toronto', 43.6, -79.3, 'America/Toronto'),
        ));
        $visit->setId('918273');

        $payload = $this->generator->{$method}($visit)->payload;
        $serializedLocation = $payload['visit']['visitLocation']->jsonSerialize();

        self::assertSame('aB3xK', $payload['shortUrl']['shortCode']);
        self::assertArrayHasKey('domain', $payload['shortUrl']);
        self::assertSame(918273, $payload['visit']['id']);
        self::assertSame($visitedUrl, $payload['visit']['visitedUrl']);
        self::assertStringContainsString('utm_sm_mid=90210', $payload['visit']['visitedUrl']);
        self::assertSame(DeviceClass::MOBILE->value, $payload['visit']['deviceType']);
        self::assertNull($payload['visit']['deviceTypeDetail']);
        self::assertSame('CA', $serializedLocation['countryCode']);
        self::assertSame('Canada', $serializedLocation['countryName']);
    }

    public function provideVisitPublishingMethods(): iterable
    {
        yield 'newVisitUpdate' => ['newVisitUpdate'];
        yield 'newShortUrlVisitUpdate' => ['newShortUrlVisitUpdate'];
    }
}
