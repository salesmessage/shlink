<?php

declare(strict_types=1);

namespace ShlinkioApiTest\Shlink\Rest\Action;

use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\Test;
use Shlinkio\Shlink\TestUtils\ApiTest\ApiTestCase;

use function sprintf;
use function str_repeat;
use function strlen;

/**
 * The requests core sends to the shortener and the fields it reads from the responses (SWR-25201).
 * Consumer: core app/UrlShortener/Services/Shlink/ShlinkShortener.php (always rest/v3, always with a domain).
 */
class CoreShortenerContractTest extends ApiTestCase
{
    private const string DOMAIN = 'example.com';
    private const int CORE_SHORT_CODE_LENGTH = 6;

    #[Test]
    public function createAcceptsTheBodyCoreSendsAndReturnsTheFieldsItReads(): void
    {
        [$statusCode, $payload] = $this->createAsCore('https://core.test/create?utm=1', validateUrl: true);

        self::assertEquals(self::STATUS_OK, $statusCode);
        $this->assertShortUrlFieldsCoreReads($payload, 'https://core.test/create?utm=1');
        self::assertSame(self::CORE_SHORT_CODE_LENGTH, strlen(self::shortCodeOf($payload)));
    }

    #[Test]
    public function createWithFindIfExistsReturnsTheExistingShortUrl(): void
    {
        [, $first] = $this->createAsCore('https://core.test/same', findIfExists: true);
        [$statusCode, $second] = $this->createAsCore('https://core.test/same', findIfExists: true);

        self::assertEquals(self::STATUS_OK, $statusCode);
        self::assertSame(self::shortCodeOf($first), self::shortCodeOf($second));
    }

    #[Test]
    public function editAcceptsTheBodyCoreSends(): void
    {
        [, $created] = $this->createAsCore('https://core.test/before');

        $resp = $this->callApiWithKey(
            self::METHOD_PATCH,
            sprintf('/rest/v3/short-urls/%s', self::shortCodeOf($created)),
            [
                RequestOptions::QUERY => ['domain' => self::DOMAIN],
                RequestOptions::JSON => ['longUrl' => 'https://core.test/after', 'validateUrl' => false],
            ],
        );
        $payload = $this->getJsonResponsePayload($resp);

        self::assertEquals(self::STATUS_OK, $resp->getStatusCode());
        $this->assertShortUrlFieldsCoreReads($payload, 'https://core.test/after');
        self::assertSame(self::shortCodeOf($created), self::shortCodeOf($payload));
    }

    #[Test]
    public function infoReturnsTheFieldsCoreReads(): void
    {
        [, $created] = $this->createAsCore('https://core.test/info');

        $resp = $this->callApiWithKey(
            self::METHOD_GET,
            sprintf('/rest/v3/short-urls/%s', self::shortCodeOf($created)),
            [RequestOptions::QUERY => ['domain' => self::DOMAIN]],
        );

        self::assertEquals(self::STATUS_OK, $resp->getStatusCode());
        $this->assertShortUrlFieldsCoreReads($this->getJsonResponsePayload($resp), 'https://core.test/info');
    }

    #[Test]
    public function visitsAcceptTheQueryCoreSendsAndReturnThePaginationItReads(): void
    {
        [, $created] = $this->createAsCore('https://core.test/visits');
        $shortCode = self::shortCodeOf($created);
        $this->callShortUrl($shortCode, [
            RequestOptions::HEADERS => [
                'Host' => self::DOMAIN,
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/141.0.0.0 Safari/537.36',
            ],
        ]);

        // core ListVisitsController sends excludeBots, BotVisitResync sends startDate as ATOM, getLinkStats both dates
        $resp = $this->callApiWithKey(
            self::METHOD_GET,
            sprintf('/rest/v3/short-urls/%s/visits', $shortCode),
            [
                RequestOptions::QUERY => [
                    'page' => 1,
                    'itemsPerPage' => 10,
                    'excludeBots' => true,
                    'startDate' => '2020-01-01T00:00:00+00:00',
                    'endDate' => '2099-01-01T00:00:00+00:00',
                    'domain' => self::DOMAIN,
                ],
            ],
        );
        /** @var array{visits: array{data: list<mixed>, pagination: array<string, mixed>}} $payload */
        $payload = $this->getJsonResponsePayload($resp);

        self::assertEquals(self::STATUS_OK, $resp->getStatusCode());
        self::assertCount(1, $payload['visits']['data']);
        self::assertSame(1, $payload['visits']['pagination']['currentPage']);
        self::assertSame(10, $payload['visits']['pagination']['itemsPerPage']);
        self::assertSame(1, $payload['visits']['pagination']['totalItems']);
        self::assertSame(1, $payload['visits']['pagination']['pagesCount']);
    }

    #[Test]
    public function deleteAnswersNoContentThenNotFound(): void
    {
        [, $created] = $this->createAsCore('https://core.test/delete');
        $url = sprintf('/rest/v3/short-urls/%s', self::shortCodeOf($created));
        $options = [RequestOptions::QUERY => ['domain' => self::DOMAIN]];

        $first = $this->callApiWithKey(self::METHOD_DELETE, $url, $options);
        $second = $this->callApiWithKey(self::METHOD_DELETE, $url, $options);

        self::assertEquals(self::STATUS_NO_CONTENT, $first->getStatusCode());
        self::assertEquals(self::STATUS_NOT_FOUND, $second->getStatusCode());
    }

    #[Test]
    public function longUrlOf4096CharactersIsStored(): void
    {
        $base = 'https://core.test/long?q=';
        $longUrl = $base . str_repeat('a', 4096 - strlen($base));

        [$statusCode, $created] = $this->createAsCore($longUrl);
        $info = $this->getJsonResponsePayload($this->callApiWithKey(
            self::METHOD_GET,
            sprintf('/rest/v3/short-urls/%s', self::shortCodeOf($created)),
            [RequestOptions::QUERY => ['domain' => self::DOMAIN]],
        ));

        self::assertEquals(self::STATUS_OK, $statusCode);
        self::assertSame(4096, strlen($longUrl));
        self::assertSame($longUrl, $created['longUrl']);
        self::assertSame($longUrl, $info['longUrl'] ?? null);
    }

    /**
     * The body of core ShlinkShortener::getRequestBodyForCreate
     * @return array{int, array<array-key, mixed>}
     */
    private function createAsCore(string $longUrl, bool $validateUrl = false, bool $findIfExists = false): array
    {
        $resp = $this->callApiWithKey(self::METHOD_POST, '/rest/v3/short-urls', [
            RequestOptions::JSON => [
                'longUrl' => $longUrl,
                'validateUrl' => $validateUrl,
                'tags' => ['core'],
                'findIfExists' => $findIfExists,
                'shortCodeLength' => self::CORE_SHORT_CODE_LENGTH,
                'domain' => self::DOMAIN,
                'forwardQuery' => true,
            ],
        ]);

        return [$resp->getStatusCode(), $this->getJsonResponsePayload($resp)];
    }

    /**
     * core ShlinkShortener::mapShortUrlResponse
     * @param array<array-key, mixed> $payload
     */
    private function assertShortUrlFieldsCoreReads(array $payload, string $expectedLongUrl): void
    {
        $shortCode = self::shortCodeOf($payload);
        self::assertSame(sprintf('http://%s/%s', self::DOMAIN, $shortCode), $payload['shortUrl'] ?? null);
        self::assertSame($expectedLongUrl, $payload['longUrl'] ?? null);
        self::assertSame(self::DOMAIN, $payload['domain'] ?? null);
        $dateCreated = $payload['dateCreated'] ?? null;
        self::assertIsString($dateCreated);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $dateCreated);
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private static function shortCodeOf(array $payload): string
    {
        $shortCode = $payload['shortCode'] ?? null;
        self::assertIsString($shortCode);

        return $shortCode;
    }
}
