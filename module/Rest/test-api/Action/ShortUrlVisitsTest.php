<?php

declare(strict_types=1);

namespace ShlinkioApiTest\Shlink\Rest\Action;

use GuzzleHttp\Psr7\Query;
use GuzzleHttp\RequestOptions;
use Laminas\Diactoros\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Shlinkio\Shlink\Common\Paginator\Paginator;
use Shlinkio\Shlink\TestUtils\ApiTest\ApiTestCase;
use ShlinkioApiTest\Shlink\Rest\Utils\ApiTestDataProviders;
use ShlinkioApiTest\Shlink\Rest\Utils\UrlBuilder;

use function sprintf;

class ShortUrlVisitsTest extends ApiTestCase
{
    #[Test, DataProviderExternal(ApiTestDataProviders::class, 'invalidUrlsProvider')]
    public function tryingToGetVisitsForInvalidUrlReturnsNotFoundError(
        string $shortCode,
        string|null $domain,
        string $expectedDetail,
        string $apiKey,
    ): void {
        $resp = $this->callApiWithKey(
            self::METHOD_GET,
            UrlBuilder::buildShortUrlPath($shortCode, $domain, '/visits'),
            apiKey: $apiKey,
        );
        $payload = $this->getJsonResponsePayload($resp);

        self::assertEquals(self::STATUS_NOT_FOUND, $resp->getStatusCode());
        self::assertEquals(self::STATUS_NOT_FOUND, $payload['status']);
        self::assertEquals('https://shlink.io/api/error/short-url-not-found', $payload['type']);
        self::assertEquals($expectedDetail, $payload['detail']);
        self::assertEquals('Short URL not found', $payload['title']);
        self::assertEquals($shortCode, $payload['shortCode']);
        self::assertEquals($domain, $payload['domain'] ?? null);
    }

    #[Test, DataProvider('provideDomains')]
    public function properVisitsAreReturnedWhenDomainIsProvided(string|null $domain, int $expectedAmountOfVisits): void
    {
        $shortCode = 'ghi789';
        $url = new Uri(sprintf('/short-urls/%s/visits', $shortCode));

        if ($domain !== null) {
            $url = $url->withQuery(Query::build(['domain' => $domain]));
        }

        $resp = $this->callApiWithKey(self::METHOD_GET, (string) $url);
        $payload = $this->getJsonResponsePayload($resp);

        self::assertEquals(
            $expectedAmountOfVisits,
            $payload['visits']['pagination']['totalItems'] ?? Paginator::ALL_ITEMS,
        );
        self::assertCount($expectedAmountOfVisits, $payload['visits']['data'] ?? []);
    }

    public static function provideDomains(): iterable
    {
        yield 'domain' => ['example.com', 0];
        yield 'no domain' => [null, 2];
    }

    #[Test, DataProvider('provideVisitsForBots')]
    public function properVisitsAreReturnedWhenExcludingBots(bool $excludeBots, int $expectedAmountOfVisits): void
    {
        $shortCode = 'def456';
        $url = new Uri(sprintf('/short-urls/%s/visits', $shortCode));

        if ($excludeBots) {
            $url = $url->withQuery(Query::build(['excludeBots' => true]));
        }

        $resp = $this->callApiWithKey(self::METHOD_GET, (string) $url);
        $payload = $this->getJsonResponsePayload($resp);

        self::assertEquals(
            $expectedAmountOfVisits,
            $payload['visits']['pagination']['totalItems'] ?? Paginator::ALL_ITEMS,
        );
        self::assertCount($expectedAmountOfVisits, $payload['visits']['data'] ?? []);
    }

    public static function provideVisitsForBots(): iterable
    {
        yield 'bots excluded' => [true, 1];
        yield 'bots not excluded' => [false, 2];
    }

    /**
     * The fields core reads from each visit (core app/UrlShortener/Structures/Dto/VisitDto.php)
     */
    #[Test, Group('spec:click-tracking:AC-15')]
    public function visitsOfShortUrlWithDomainHaveTheFieldsCoreReads(): void
    {
        $userAgent = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) Mobile/15E148 Safari/604.1';
        $redirect = $this->callShortUrl('ghi789', [
            RequestOptions::HEADERS => [
                'Host' => 'example.com',
                'User-Agent' => $userAgent,
                'Referer' => 'https://referer.test',
            ],
        ]);
        self::assertEquals(self::STATUS_FOUND, $redirect->getStatusCode());

        $resp = $this->callApiWithKey(
            self::METHOD_GET,
            '/short-urls/ghi789/visits',
            [RequestOptions::QUERY => ['domain' => 'example.com']],
        );
        /** @var array{visits: array{data: list<array<string, mixed>>, pagination: array{totalItems: int}}} $payload */
        $payload = $this->getJsonResponsePayload($resp);
        $visits = $payload['visits']['data'];

        self::assertEquals(1, $payload['visits']['pagination']['totalItems']);
        self::assertCount(1, $visits);
        $visit = $visits[0];
        self::assertIsInt($visit['id'] ?? null);
        self::assertGreaterThan(0, $visit['id']);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/',
            (string) ($visit['date'] ?? ''),
        );
        self::assertSame('https://referer.test', $visit['referer'] ?? null);
        self::assertSame($userAgent, $visit['userAgent'] ?? null);
        self::assertSame('mobile', $visit['deviceType'] ?? null);
        self::assertArrayHasKey('deviceTypeDetail', $visit);
        self::assertNull($visit['deviceTypeDetail']);
        self::assertFalse($visit['potentialBot'] ?? null);
        self::assertArrayHasKey('visitLocation', $visit);
    }
}
