<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\Config\Options;

final readonly class BotIpOptions
{
    /**
     * Lists Google publishes as its own crawlers and fetchers. Not goog.json (every Google address, people on Google
     * networks included), not user-triggered-fetchers.json (apps of Google Cloud customers).
     * https://developers.google.com/crawling/docs/crawlers-fetchers/verify-google-requests
     */
    public const array GOOGLE_SOURCES = [
        'https://developers.google.com/static/crawling/ipranges/common-crawlers.json',
        'https://developers.google.com/static/crawling/ipranges/special-crawlers.json',
        'https://developers.google.com/static/crawling/ipranges/user-triggered-fetchers-google.json',
        'https://developers.google.com/static/crawling/ipranges/user-triggered-agents.json',
    ];

    /**
     * @param string[] $googleSources
     * @param int $refreshAfterDays the Google list is downloaded again once its newest range is older than this
     * @param int $checkIntervalSeconds how often a worker re-reads the ranges and checks whether the list is stale
     */
    public function __construct(
        public array $googleSources = self::GOOGLE_SOURCES,
        public int $refreshAfterDays = 7,
        public int $checkIntervalSeconds = 600,
    ) {}
}
