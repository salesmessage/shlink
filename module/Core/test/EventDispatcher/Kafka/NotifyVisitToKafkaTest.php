<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Core\EventDispatcher\Kafka;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Salesmessage\Streaming\Message\MessageFactoryInterface;
use Salesmessage\Streaming\Message\ProducerMessageInterface;
use Salesmessage\Streaming\Producer\ProducerInterface;
use Salesmessage\Streaming\Route\RouteInterface;
use Shlinkio\Shlink\Common\UpdatePublishing\Update;
use Shlinkio\Shlink\Core\EventDispatcher\Event\VisitLocated;
use Shlinkio\Shlink\Core\EventDispatcher\Kafka\DeliveryFailures;
use Shlinkio\Shlink\Core\EventDispatcher\Kafka\NotifyVisitToKafka;
use Shlinkio\Shlink\Core\EventDispatcher\PublishingUpdatesGeneratorInterface;
use Shlinkio\Shlink\Core\ShortUrl\Entity\ShortUrl;
use Shlinkio\Shlink\Core\ShortUrl\Model\ShortUrlCreation;
use Shlinkio\Shlink\Core\Visit\Entity\Visit;
use Shlinkio\Shlink\Core\Visit\Model\Visitor;
use Throwable;

use function sprintf;
use function str_contains;

class NotifyVisitToKafkaTest extends TestCase
{
    private MockObject & ProducerInterface $producer;
    private MockObject & MessageFactoryInterface $messageFactory;
    private MockObject & RouteInterface $topic;
    private MockObject & PublishingUpdatesGeneratorInterface $updatesGenerator;
    private MockObject & EntityManagerInterface $em;
    private MockObject & LoggerInterface $logger;
    private DeliveryFailures $deliveryFailures;

    protected function setUp(): void
    {
        $this->producer = $this->createMock(ProducerInterface::class);
        $this->messageFactory = $this->createMock(MessageFactoryInterface::class);
        $this->topic = $this->createMock(RouteInterface::class);
        $this->updatesGenerator = $this->createMock(PublishingUpdatesGeneratorInterface::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->deliveryFailures = new DeliveryFailures();
    }

    /**
     * @test
     * @group spec:click-tracking:AC-11
     */
    public function locatedVisitIsPublishedOnceOnConfiguredTopicAndProducerIsFlushed(): void
    {
        $visit = $this->visit();
        $payload = ['shortUrl' => ['shortCode' => 'aB3xK'], 'visit' => $visit->jsonSerialize()];
        $message = $this->createMock(ProducerMessageInterface::class);
        $calls = [];

        $this->em->expects($this->once())->method('find')->with(Visit::class, '123')->willReturn($visit);
        $this->updatesGenerator->expects($this->once())->method('newVisitUpdate')->with($visit)->willReturn(
            Update::forTopicAndPayload('any', $payload),
        );
        $this->messageFactory->expects($this->once())->method('createProducerMessage')->with($payload)->willReturn(
            $message,
        );
        $message->expects($this->once())->method('withHeader')->with(
            'event-key',
            'shortener.click_recorded',
        )->willReturnSelf();
        $this->producer->expects($this->once())->method('send')->with($this->topic, $message)->willReturnCallback(
            function () use (&$calls): void {
                $calls[] = 'send';
            },
        );
        $this->producer->expects($this->once())->method('flush')->willReturnCallback(
            function () use (&$calls): void {
                $calls[] = 'flush';
            },
        );
        $this->logger->expects($this->never())->method('error');

        ($this->listener())(new VisitLocated('123'));

        self::assertEquals(['send', 'flush'], $calls);
    }

    /**
     * @test
     * @dataProvider provideFailingMethods
     */
    public function producerFailureIsLoggedAtErrorLevelWithVisitIdAndShortCode(string $failingMethod): void
    {
        $e = new RuntimeException('Broker is unreachable');
        $visit = $this->visit();
        $message = $this->createMock(ProducerMessageInterface::class);
        $message->method('withHeader')->willReturnSelf();

        $this->em->method('find')->willReturn($visit);
        $this->updatesGenerator->method('newVisitUpdate')->willReturn(Update::forTopicAndPayload('any', []));
        $this->messageFactory->method('createProducerMessage')->willReturn($message);
        $this->producer->method($failingMethod)->willThrowException($e);
        $this->logger->expects($this->never())->method('debug');
        $this->logger->expects($this->once())->method('error')->with(
            $this->callback(static fn (string $message): bool =>
                str_contains($message, '{visitId}') && str_contains($message, '{shortCode}')),
            $this->callback(static fn (array $context): bool =>
                $context['visitId'] === '123' && $context['shortCode'] === 'aB3xK' && $context['e'] instanceof
                    Throwable),
        );

        ($this->listener())(new VisitLocated('123'));
    }

    public function provideFailingMethods(): iterable
    {
        yield 'send fails' => ['send'];
        yield 'flush fails' => ['flush'];
    }

    /** @test */
    public function nothingIsPublishedWhenPublisherIsDisabled(): void
    {
        $this->em->expects($this->never())->method('find');
        $this->updatesGenerator->expects($this->never())->method('newVisitUpdate');
        $this->messageFactory->expects($this->never())->method('createProducerMessage');
        $this->producer->expects($this->never())->method('send');
        $this->producer->expects($this->never())->method('flush');
        $this->logger->expects($this->never())->method('error');
        $this->logger->expects($this->never())->method('warning');

        ($this->listener(enabled: false))(new VisitLocated('123'));
    }

    /** @test */
    public function nothingIsPublishedWhenVisitCannotBeFound(): void
    {
        $this->em->expects($this->once())->method('find')->willReturn(null);
        $this->producer->expects($this->never())->method('send');
        $this->producer->expects($this->never())->method('flush');
        $this->logger->expects($this->once())->method('warning');
        $this->logger->expects($this->never())->method('error');

        ($this->listener())(new VisitLocated('123'));
    }

    /** @test */
    public function orphanVisitsAreNotPublished(): void
    {
        $orphanVisit = Visit::forBasePath(new Visitor('Chrome', '', '1.2.3.4', ''));

        $this->em->expects($this->once())->method('find')->willReturn($orphanVisit);
        $this->updatesGenerator->expects($this->never())->method('newVisitUpdate');
        $this->producer->expects($this->never())->method('send');
        $this->producer->expects($this->never())->method('flush');
        $this->logger->expects($this->never())->method('error');

        ($this->listener())(new VisitLocated('123'));
    }

    /** @test */
    public function deliveryFailureReportedWhilePublishingIsLoggedAtErrorLevelWithVisitIdAndShortCode(): void
    {
        $visit = $this->visit();
        $message = $this->createMock(ProducerMessageInterface::class);
        $message->method('withHeader')->willReturnSelf();

        $this->em->method('find')->willReturn($visit);
        $this->updatesGenerator->method('newVisitUpdate')->willReturn(Update::forTopicAndPayload('any', []));
        $this->messageFactory->method('createProducerMessage')->willReturn($message);
        $this->producer->method('flush')->willReturnCallback(function (): void {
            $this->deliveryFailures->record(-192);
        });
        $this->logger->expects($this->once())->method('error')->with(
            $this->callback(static fn (string $message): bool =>
                str_contains($message, '{visitId}') && str_contains($message, '{shortCode}')),
            $this->callback(static fn (array $context): bool =>
                $context['visitId'] === '123' && $context['shortCode'] === 'aB3xK'
                && $context['errorCodes'] === '-192'),
        );

        ($this->listener())(new VisitLocated('123'));
    }

    /** @test */
    public function deliveryFailuresFromAnEarlierPublishAreNotAttributedToThisVisit(): void
    {
        $message = $this->createMock(ProducerMessageInterface::class);
        $message->method('withHeader')->willReturnSelf();
        $this->deliveryFailures->record(-192);

        $this->em->method('find')->willReturn($this->visit());
        $this->updatesGenerator->method('newVisitUpdate')->willReturn(Update::forTopicAndPayload('any', []));
        $this->messageFactory->method('createProducerMessage')->willReturn($message);
        $this->logger->expects($this->never())->method('error');

        ($this->listener())(new VisitLocated('123'));
    }

    private function visit(): Visit
    {
        $shortUrl = ShortUrl::create(ShortUrlCreation::fromRawData([
            'customSlug' => 'aB3xK',
            'longUrl' => 'https://example.com/spring-sale',
        ]));
        $visit = Visit::forValidShortUrl($shortUrl, new Visitor(
            'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) Mobile/15E148 Safari/604.1',
            '',
            '1.2.3.4',
            sprintf('https://sh.example.com/%s?utm_sm_mid=90210', $shortUrl->getShortCode()),
        ));
        $visit->setId('918273');

        return $visit;
    }

    private function listener(bool $enabled = true): NotifyVisitToKafka
    {
        return new NotifyVisitToKafka(
            $this->producer,
            $this->messageFactory,
            $this->topic,
            $this->updatesGenerator,
            $this->em,
            $this->logger,
            $this->deliveryFailures,
            $enabled,
        );
    }
}
