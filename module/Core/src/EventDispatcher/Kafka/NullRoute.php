<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\EventDispatcher\Kafka;

use Salesmessage\Streaming\Route\RouteInterface;

/**
 * Stands in for the topic while publishing is disabled. It is never published to, and it keeps the driver's own
 * `KafkaTopic` - which resolves `ext-rdkafka` constants as it is loaded - off the path of a disabled deployment.
 */
final class NullRoute implements RouteInterface
{
    public function getName(): string
    {
        return 'kafka-publishing-disabled';
    }
}
