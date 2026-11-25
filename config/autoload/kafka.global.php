<?php

declare(strict_types=1);

use Shlinkio\Shlink\Core\Config\EnvVars;

return [
    // Visits published to Kafka for core (shortener.click_recorded). spec:click-tracking: ADR-002
    'kafka' => [
        'enabled' => (bool) EnvVars::KAFKA_ENABLED->loadFromEnv(),
        'brokers' => EnvVars::KAFKA_BROKERS->loadFromEnv(),
        'topic_prefix' => EnvVars::KAFKA_TOPIC_PREFIX->loadFromEnv(),
        'visits_topic' => EnvVars::KAFKA_VISITS_TOPIC->loadFromEnv(),
    ],
];
