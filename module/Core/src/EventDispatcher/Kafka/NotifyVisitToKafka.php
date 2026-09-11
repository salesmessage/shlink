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

/** spec:click-tracking: ADR-002 */
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
        private readonly DeliveryFailures $deliveryFailures,
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

        if ($visit->isOrphan()) {
            return;
        }

        $shortCode = $visit->getShortUrl()?->getShortCode();
        $payload = $this->updatesGenerator->newVisitUpdate($visit)->payload;

        $this->deliveryFailures->reset();

        try {
            $this->producer->send(
                $this->topic,
                $this->messageFactory->createProducerMessage($payload)->withHeader(
                    self::EVENT_KEY_HEADER,
                    self::EVENT_KEY,
                ),
            );
            $this->producer->flush();
        } catch (Throwable $e) {
            $this->logger->error(
                'Error publishing visit with id "{visitId}" on short code "{shortCode}" to Kafka. The click will '
                . 'never be attributed. {e}',
                ['visitId' => $visitId, 'shortCode' => $shortCode, 'e' => $e],
            );
            return;
        }

        if ($this->deliveryFailures->hasAny()) {
            $this->logger->error(
                'Kafka rejected the message for visit with id "{visitId}" on short code "{shortCode}". The click '
                . 'will never be attributed. Delivery error codes: {errorCodes}',
                [
                    'visitId' => $visitId,
                    'shortCode' => $shortCode,
                    'errorCodes' => $this->deliveryFailures->summary(),
                ],
            );
        }
    }
}
