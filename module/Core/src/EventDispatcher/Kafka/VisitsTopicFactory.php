<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\EventDispatcher\Kafka;

use Psr\Container\ContainerInterface;
use Salesmessage\Streaming\Driver\Kafka\Route\KafkaTopic;
use Salesmessage\Streaming\Route\RouteInterface;

/**
 * Resolves the topic clicks are published to. The prefix has to match the one the consumer applies, or the two sides
 * end up on different topics of the same cluster.
 */
class VisitsTopicFactory
{
    public function __invoke(ContainerInterface $container): RouteInterface
    {
        $config = $container->get('config')['kafka'] ?? [];

        if (! ($config['enabled'] ?? false)) {
            return new NullRoute();
        }

        return new KafkaTopic(
            (string) ($config['topic_prefix'] ?? '') . (string) ($config['visits_topic'] ?? 'shortener'),
        );
    }
}
