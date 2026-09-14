<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\EventDispatcher\Kafka;

use Salesmessage\Streaming\Message\MessageInterface;
use Salesmessage\Streaming\Producer\ProducerInterface;
use Salesmessage\Streaming\Route\RouteInterface;

final class NullProducer implements ProducerInterface
{
    public function send(RouteInterface $route, MessageInterface $message): void
    {
    }

    public function flush(): void
    {
    }
}
