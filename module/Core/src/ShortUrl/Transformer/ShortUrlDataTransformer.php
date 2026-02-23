<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\ShortUrl\Transformer;

use Shlinkio\Shlink\Common\Rest\DataTransformerInterface;
use Shlinkio\Shlink\Core\ShortUrl\Entity\ShortUrl;
use Shlinkio\Shlink\Core\ShortUrl\Helper\ShortUrlStringifierInterface;
use Shlinkio\Shlink\Core\Visit\Model\VisitsSummary;

use function Functional\invoke;
use function Functional\invoke_if;

class ShortUrlDataTransformer implements DataTransformerInterface
{
    public function __construct(private readonly ShortUrlStringifierInterface $stringifier)
    {
    }

    /**
     * @param ShortUrl $shortUrl
     * @param bool $withVisitsCount
     * @return array
     */
    public function transform($shortUrl, bool $withVisitsCount = false): array // phpcs:ignore
    {
        $data = [
            'shortCode' => $shortUrl->getShortCode(),
            'shortUrl' => $this->stringifier->stringify($shortUrl),
            'longUrl' => $shortUrl->getLongUrl(),
            'dateCreated' => $shortUrl->getDateCreated()->toAtomString(),
            'tags' => invoke($shortUrl->getTags(), '__toString'),
            'meta' => $this->buildMeta($shortUrl),
            'domain' => $shortUrl->getDomain(),
            'title' => $shortUrl->title(),
            'crawlable' => $shortUrl->crawlable(),
            'forwardQuery' => $shortUrl->forwardQuery(),

        ];
        if ($withVisitsCount) {
            $visitsCount = $shortUrl->getVisitsCount();

            $visitsCount['visitsSummary'] = VisitsSummary::fromTotalAndNonBots(
                $visitsCount,
                $shortUrl->nonBotVisitsCount(),
            );

            // Deprecated
            $data['visitsCount'] = $visitsCount;
        }

        return $data;
    }

    private function buildMeta(ShortUrl $shortUrl): array
    {
        $validSince = $shortUrl->getValidSince();
        $validUntil = $shortUrl->getValidUntil();
        $maxVisits = $shortUrl->getMaxVisits();

        return [
            'validSince' => invoke_if($validSince, 'toAtomString'),
            'validUntil' => invoke_if($validUntil, 'toAtomString'),
            'maxVisits' => $maxVisits,
        ];
    }
}
