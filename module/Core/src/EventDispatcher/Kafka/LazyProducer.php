<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\EventDispatcher\Kafka;

use Closure;
use Salesmessage\Streaming\Message\MessageInterface;
use Salesmessage\Streaming\Producer\ProducerInterface;
use Salesmessage\Streaming\Route\RouteInterface;

final class LazyProducer implements ProducerInterface
{
    private ?ProducerInterface $producer = null;

    /** @param Closure(): ProducerInterface $factory */
    public function __construct(private readonly Closure $factory)
    {
    }

    public function send(RouteInterface $route, MessageInterface $message): void
    {
        $this->producer ??= ($this->factory)();
        $this->producer->send($route, $message);
    }

    public function flush(): void
    {
        $this->producer?->flush();
    }
}
