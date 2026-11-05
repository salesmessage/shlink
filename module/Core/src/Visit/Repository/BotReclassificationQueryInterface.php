<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\Visit\Repository;

use Cake\Chronos\Chronos;

/**
 * SWR-26047: the visit reads and writes of the one-off bot reclassification (visit:reclassify-bots).
 */
interface BotReclassificationQueryInterface
{
    /**
     * Id of the first visit on or after the date, so a pass starts there instead of walking older ids.
     */
    public function findFirstVisitIdSince(Chronos $since): ?int;

    /**
     * Visits to short URLs not flagged as potential bots yet, after the given id, ordered by id.
     *
     * @return array<array{id: string|int, userAgent: string|null, remoteAddr: string|null}>
     */
    public function findUnflaggedShortUrlVisits(Chronos $since, int $afterId, int $limit): array;

    /**
     * @param array<string|int> $visitIds
     */
    public function flagAsPotentialBots(array $visitIds): void;
}
