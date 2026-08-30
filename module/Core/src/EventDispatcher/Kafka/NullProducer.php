<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\EventDispatcher\Kafka;

use Salesmessage\Streaming\Message\MessageInterface;
use Salesmessage\Streaming\Producer\ProducerInterface;
use Salesmessage\Streaming\Route\RouteInterface;

/**
 * Stands in for the Kafka producer while publishing is disabled, so that no client is built and no connection to the
 * brokers is opened for a listener which returns before it publishes anything.
 */
final class NullProducer implements ProducerInterface
{
    public function send(RouteInterface $route, MessageInterface $message): void
    {
    }

    public function flush(): void
    {
    }
}
