<?php

declare(strict_types=1);

use Shlinkio\Shlink\Core\Config\EnvVars;

return [

    'kafka' => [
        // spec:click-tracking: ADR-002
        'enabled' => (bool) EnvVars::KAFKA_ENABLED->loadFromEnv(false),
        'brokers' => EnvVars::KAFKA_BROKERS->loadFromEnv(),
        'topic_prefix' => EnvVars::KAFKA_TOPIC_PREFIX->loadFromEnv(''),
        'visits_topic' => EnvVars::KAFKA_VISITS_TOPIC->loadFromEnv('shortener'),
    ],

];
