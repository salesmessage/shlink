<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\EventDispatcher\Kafka;

use Salesmessage\Streaming\Route\RouteInterface;

final class NullRoute implements RouteInterface
{
    public function getName(): string
    {
        return 'kafka-publishing-disabled';
    }
}
