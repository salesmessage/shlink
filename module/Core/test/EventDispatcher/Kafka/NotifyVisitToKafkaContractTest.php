<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Core\EventDispatcher\Kafka;

use Cake\Chronos\Chronos;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Salesmessage\Streaming\Driver\Kafka\Message\Interceptor\EncodingInterceptor;
use Salesmessage\Streaming\Driver\Kafka\Message\KafkaMessageFactory;
use Salesmessage\Streaming\Driver\Kafka\Route\KafkaTopic;
use Salesmessage\Streaming\Driver\Kafka\Serializer\JsonSerializer;
use Salesmessage\Streaming\Driver\ProducerDriverInterface;
use Salesmessage\Streaming\Message\Interceptor\InterceptorStack;
use Salesmessage\Streaming\Message\MessageInterface;
use Salesmessage\Streaming\Producer\SimpleProducer;
use Salesmessage\Streaming\Route\RouteInterface;
use Shlinkio\Shlink\Core\EventDispatcher\Event\UrlVisited;
use Shlinkio\Shlink\Core\EventDispatcher\Kafka\DeliveryFailures;
use Shlinkio\Shlink\Core\EventDispatcher\Kafka\NotifyVisitToKafka;
use Shlinkio\Shlink\Core\EventDispatcher\PublishingUpdatesGenerator;
use Shlinkio\Shlink\Core\ShortUrl\Entity\ShortUrl;
use Shlinkio\Shlink\Core\ShortUrl\Helper\ShortUrlStringifier;
use Shlinkio\Shlink\Core\ShortUrl\Model\ShortUrlCreation;
use Shlinkio\Shlink\Core\ShortUrl\Transformer\ShortUrlDataTransformer;
use Shlinkio\Shlink\Core\Visit\Entity\Visit;
use Shlinkio\Shlink\Core\Visit\Model\Visitor;
use Shlinkio\Shlink\IpGeolocation\Model\Location;

use function array_key_exists;
use function get_debug_type;
use function is_array;
use function json_decode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * The visit event core consumes (`shortener.click_recorded`), from the consumer's side: the message put on the wire for
 * a located visit carries every key of core's canonical payload with the same value and type.
 */
class NotifyVisitToKafkaContractTest extends TestCase
{
    /**
     * Copy of core `tests/Fixtures/UrlShortener/ClickRecordedPayloadFixture.php::canonical()`
     * (contract: core `specs/002-click-tracking/contracts/click-recorded.md`, version 2).
     */
    private const array CORE_CANONICAL_PAYLOAD = [
        'shortUrl' => [
            'shortCode' => 'aB3xK',
            'domain' => 'go.example.com',
            'longUrl' => 'https://example.com/spring-sale',
        ],
        'visit' => [
            'id' => 918_273,
            'date' => '2026-08-23T14:07:11+00:00',
            'visitedUrl' => 'https://sh.example.com/aB3xK?smg_domain=go.example.com&utm_sm_mid=918273',
            'potentialBot' => false,
            'deviceType' => 'other',
            'deviceTypeDetail' => 'smart-tv',
            'userAgent' => 'Mozilla/5.0 (SMART-TV; Linux; Tizen 6.0) AppleWebKit/537.36',
            'visitLocation' => [
                'countryCode' => 'CA',
                'countryName' => 'Canada',
            ],
        ],
    ];

    /** @var list<array{RouteInterface, MessageInterface}> */
    private array $sent = [];

    protected function setUp(): void
    {
        // A non-UTC time zone of the service must not leak into the date core reads
        Chronos::setTestNow(Chronos::parse('2026-08-23 09:07:11', 'America/Chicago'));
    }

    protected function tearDown(): void
    {
        Chronos::setTestNow();
    }

    #[Test]
    public function locatedVisitIsPublishedWithTheEventKeyAndThePayloadCoreReads(): void
    {
        $topic = new KafkaTopic('shortener');

        $this->listener($topic, $this->visit())(new UrlVisited('918273'));

        self::assertCount(1, $this->sent);
        [$route, $message] = $this->sent[0];
        self::assertSame($topic, $route);
        self::assertSame('shortener.click_recorded', $message->getHeader('event-key'));

        $payload = json_decode((string) $message->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $this->assertHasEveryKeyOf(self::CORE_CANONICAL_PAYLOAD, $payload, '');
    }

    /**
     * Every key of the expected payload is present with the same value and the same JSON type; keys the consumer does
     * not read may be there too.
     *
     * @param array<array-key, mixed> $expected
     * @param array<array-key, mixed> $actual
     */
    private function assertHasEveryKeyOf(array $expected, array $actual, string $path): void
    {
        foreach ($expected as $key => $expectedValue) {
            $keyPath = $path === '' ? (string) $key : sprintf('%s.%s', $path, $key);
            self::assertTrue(array_key_exists($key, $actual), sprintf('The payload has no "%s"', $keyPath));

            $actualValue = $actual[$key];
            if (is_array($expectedValue)) {
                self::assertIsArray($actualValue, sprintf('"%s" is not an object', $keyPath));
                $this->assertHasEveryKeyOf($expectedValue, $actualValue, $keyPath);
                continue;
            }

            self::assertSame(
                get_debug_type($expectedValue),
                get_debug_type($actualValue),
                sprintf('"%s" has another type', $keyPath),
            );
            self::assertSame($expectedValue, $actualValue, sprintf('"%s" has another value', $keyPath));
        }
    }

    private function visit(): Visit
    {
        $shortUrl = ShortUrl::create(new ShortUrlCreation(
            longUrl: 'https://example.com/spring-sale',
            customSlug: 'aB3xK',
            domain: 'go.example.com',
        ));
        $visit = Visit::forValidShortUrl($shortUrl, Visitor::fromParams(
            userAgent: 'Mozilla/5.0 (SMART-TV; Linux; Tizen 6.0) AppleWebKit/537.36',
            remoteAddress: '1.2.3.4',
            visitedUrl: 'https://sh.example.com/aB3xK?smg_domain=go.example.com&utm_sm_mid=918273',
            geolocation: new Location(countryCode: 'CA', countryName: 'Canada'),
        ));
        $visit->setId('918273');

        return $visit;
    }

    private function listener(RouteInterface $topic, Visit $visit): NotifyVisitToKafka
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($visit);

        $sent = &$this->sent;
        $driver = new class($sent) implements ProducerDriverInterface {
            /** @param list<array{RouteInterface, MessageInterface}> $sent */
            public function __construct(private array &$sent) {}

            public function send(RouteInterface $route, MessageInterface $message): void
            {
                $this->sent[] = [$route, $message];
            }

            public function flush(): void {}
        };
        $interceptors = new InterceptorStack();
        $interceptors->add(new EncodingInterceptor(new JsonSerializer()));

        return new NotifyVisitToKafka(
            new SimpleProducer($driver, $interceptors),
            new KafkaMessageFactory(),
            $topic,
            new PublishingUpdatesGenerator(new ShortUrlDataTransformer(new ShortUrlStringifier())),
            $em,
            new NullLogger(),
            new DeliveryFailures(),
            enabled: true,
        );
    }
}
