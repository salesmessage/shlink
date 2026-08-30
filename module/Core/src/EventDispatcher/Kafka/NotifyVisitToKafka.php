<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\EventDispatcher\Kafka;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Salesmessage\Streaming\Message\MessageFactoryInterface;
use Salesmessage\Streaming\Producer\ProducerInterface;
use Salesmessage\Streaming\Route\RouteInterface;
use Shlinkio\Shlink\Core\EventDispatcher\Event\VisitLocated;
use Shlinkio\Shlink\Core\EventDispatcher\PublishingUpdatesGeneratorInterface;
use Shlinkio\Shlink\Core\Visit\Entity\Visit;
use Throwable;

/**
 * Publishes every located visit onto the platform's Kafka event bus, as the `click-recorded` contract defines it.
 *
 * This is the only route from this service to the messaging platform (click-tracking#ADR-002): nothing sweeps up
 * afterwards, so a publish that fails is a click that will never become a figure, and it is logged at error level
 * rather than swallowed at the debug level the neighbouring notifiers use.
 */
final class NotifyVisitToKafka
{
    public const EVENT_KEY = 'shortener.click_recorded';
    public const EVENT_KEY_HEADER = 'event-key';

    public function __construct(
        private readonly ProducerInterface $producer,
        private readonly MessageFactoryInterface $messageFactory,
        private readonly RouteInterface $topic,
        private readonly PublishingUpdatesGeneratorInterface $updatesGenerator,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly bool $enabled,
    ) {
    }

    public function __invoke(VisitLocated $visitLocated): void
    {
        if (! $this->enabled) {
            return;
        }

        $visitId = $visitLocated->visitId;
        $visit = $this->em->find(Visit::class, $visitId);

        if ($visit === null) {
            $this->logger->warning(
                'Tried to publish visit with id "{visitId}" to Kafka, but it does not exist.',
                ['visitId' => $visitId],
            );
            return;
        }

        // An orphan visit belongs to no short URL, so it carries neither of the two fields the consumer attributes a
        // click by. The contract covers clicks on short URLs only
        if ($visit->isOrphan()) {
            return;
        }

        $shortCode = $visit->getShortUrl()?->getShortCode();
        $payload = $this->updatesGenerator->newVisitUpdate($visit)->payload;

        try {
            $this->producer->send(
                $this->topic,
                $this->messageFactory->createProducerMessage($payload)->withHeader(
                    self::EVENT_KEY_HEADER,
                    self::EVENT_KEY,
                ),
            );
            // send() only enqueues. Under long-lived workers the message would sit in the client's buffer until the
            // worker happened to poll again, so the publish is not done until the buffer has been flushed
            $this->producer->flush();
        } catch (Throwable $e) {
            $this->logger->error(
                'Error publishing visit with id "{visitId}" on short code "{shortCode}" to Kafka. The click will '
                . 'never be attributed. {e}',
                ['visitId' => $visitId, 'shortCode' => $shortCode, 'e' => $e],
            );
        }
    }
}
