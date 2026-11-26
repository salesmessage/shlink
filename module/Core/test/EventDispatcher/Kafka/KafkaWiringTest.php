<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Core\EventDispatcher\Kafka;

use Laminas\ServiceManager\ServiceManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Salesmessage\Streaming\Driver\Kafka\Route\KafkaTopic;
use Salesmessage\Streaming\Message\MessageInterface;
use Salesmessage\Streaming\Producer\ProducerInterface;
use Salesmessage\Streaming\Route\RouteInterface;
use Shlinkio\Shlink\Core\EventDispatcher\Kafka\DeliveryFailures;
use Shlinkio\Shlink\Core\EventDispatcher\Kafka\KafkaProducerFactory;
use Shlinkio\Shlink\Core\EventDispatcher\Kafka\LazyProducer;
use Shlinkio\Shlink\Core\EventDispatcher\Kafka\NullProducer;
use Shlinkio\Shlink\Core\EventDispatcher\Kafka\NullRoute;
use Shlinkio\Shlink\Core\EventDispatcher\Kafka\VisitsTopicFactory;

/**
 * How the Kafka visit publisher is wired from the `kafka` config, as on the fork before the upgrade to 5.x: disabled
 * by default, the topic named by prefix + visits topic, and no broker connection until the first visit is sent.
 */
class KafkaWiringTest extends TestCase
{
    #[Test]
    #[TestWith([[]])]
    #[TestWith([['enabled' => false, 'brokers' => 'kafka:9092']])]
    public function disabledKafkaNeitherConnectsNorNamesATopic(array $kafkaConfig): void
    {
        $container = $this->container($kafkaConfig);

        self::assertInstanceOf(NullProducer::class, (new KafkaProducerFactory())($container));
        self::assertInstanceOf(NullRoute::class, (new VisitsTopicFactory())($container));
    }

    #[Test]
    #[TestWith([['enabled' => true], 'shortener'])]
    #[TestWith([['enabled' => true, 'topic_prefix' => '', 'visits_topic' => 'shortener'], 'shortener'])]
    #[TestWith([['enabled' => true, 'topic_prefix' => 'dev.', 'visits_topic' => 'shortener'], 'dev.shortener'])]
    public function enabledKafkaPublishesToThePrefixedVisitsTopic(array $kafkaConfig, string $expectedTopic): void
    {
        $topic = (new VisitsTopicFactory())($this->container($kafkaConfig));

        self::assertInstanceOf(KafkaTopic::class, $topic);
        self::assertSame($expectedTopic, $topic->getName());
    }

    #[Test]
    public function enabledKafkaDoesNotConnectBeforeTheFirstVisitIsSent(): void
    {
        $producer = (new KafkaProducerFactory())($this->container(['enabled' => true, 'brokers' => 'kafka:9092']));

        self::assertInstanceOf(LazyProducer::class, $producer);
    }

    #[Test]
    public function lazyProducerIsBuiltOnceOnTheFirstSendAndFlushBeforeThatIsANoOp(): void
    {
        $inner = $this->createMock(ProducerInterface::class);
        $inner->expects($this->exactly(2))->method('send');
        $inner->expects($this->once())->method('flush');
        // A counting factory object read through a method: the analyzer keeps a narrowed counter variable or
        // property at its first asserted value and reports the second assertion as impossible.
        $factory = new class($inner) {
            private int $builds = 0;

            public function __construct(private readonly ProducerInterface $inner) {}

            public function __invoke(): ProducerInterface
            {
                $this->builds++;
                return $this->inner;
            }

            public function builds(): int
            {
                return $this->builds;
            }
        };
        $producer = new LazyProducer($factory(...));

        $producer->flush();
        self::assertSame(0, $factory->builds());

        $producer->send($this->createStub(RouteInterface::class), $this->createStub(MessageInterface::class));
        $producer->send($this->createStub(RouteInterface::class), $this->createStub(MessageInterface::class));
        $producer->flush();

        self::assertSame(1, $factory->builds());
    }

    private function container(array $kafkaConfig): ServiceManager
    {
        return new ServiceManager([
            'services' => [
                'config' => ['kafka' => $kafkaConfig],
                'Logger_Shlink' => new NullLogger(),
                DeliveryFailures::class => new DeliveryFailures(),
            ],
        ]);
    }
}
