<?php

declare(strict_types=1);

use Shlinkio\Shlink\Core\Config\EnvVars;

return [

    'kafka' => [
        // Ships disabled. Publishing is the only route from this service to the messaging platform, so it is switched
        // on per environment once the broker values below are in place (click-tracking#ADR-002)
        'enabled' => (bool) EnvVars::KAFKA_ENABLED->loadFromEnv(false),
        'brokers' => EnvVars::KAFKA_BROKERS->loadFromEnv(),
        // Has to match the prefix the consumer applies, or the two sides use different topics in the same cluster
        'topic_prefix' => EnvVars::KAFKA_TOPIC_PREFIX->loadFromEnv(''),
        'visits_topic' => EnvVars::KAFKA_VISITS_TOPIC->loadFromEnv('shortener'),
    ],

];
