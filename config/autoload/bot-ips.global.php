<?php

declare(strict_types=1);

// SWR-26047: visits from these addresses are recorded as potential bots, so no consumer counts them as clicks.
return [

    'bot_ips' => [
        // Lists Google publishes as its own crawlers and fetchers. Not goog.json (every Google address, people on
        // Google networks included), not user-triggered-fetchers.json (apps of Google Cloud customers).
        // https://developers.google.com/crawling/docs/crawlers-fetchers/verify-google-requests
        'google_sources' => [
            'https://developers.google.com/static/crawling/ipranges/common-crawlers.json',
            'https://developers.google.com/static/crawling/ipranges/special-crawlers.json',
            'https://developers.google.com/static/crawling/ipranges/user-triggered-fetchers-google.json',
            'https://developers.google.com/static/crawling/ipranges/user-triggered-agents.json',
        ],

        // The Google list is downloaded again once its newest range is older than this
        'refresh_after_days' => 7,

        // How often a worker re-reads the ranges from the database and checks whether the list is stale
        'check_interval_seconds' => 600,
    ],

];
