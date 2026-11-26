<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Core\Visit\Entity;

use Cake\Chronos\Chronos;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shlinkio\Shlink\Common\Util\IpAddress;
use Shlinkio\Shlink\Core\ShortUrl\Entity\ShortUrl;
use Shlinkio\Shlink\Core\Visit\DeviceClassifier;
use Shlinkio\Shlink\Core\Visit\Entity\Visit;
use Shlinkio\Shlink\Core\Visit\Entity\VisitLocation;
use Shlinkio\Shlink\Core\Visit\Model\DeviceClass;
use Shlinkio\Shlink\Core\Visit\Model\Visitor;
use Shlinkio\Shlink\Core\Visit\Model\VisitType;
use Shlinkio\Shlink\Importer\Model\ImportedShlinkVisit;
use Shlinkio\Shlink\IpGeolocation\Model\Location;

use function date_default_timezone_get;
use function date_default_timezone_set;

class VisitTest extends TestCase
{
    #[Test, DataProvider('provideUserAgents')]
    public function isProperlyJsonSerialized(string $userAgent, bool $expectedToBePotentialBot): void
    {
        $visit = Visit::forValidShortUrl(
            ShortUrl::createFake(),
            Visitor::fromParams($userAgent, 'some site', '1.2.3.4'),
        );

        self::assertEquals(
            [
                'referer' => 'some site',
                'date' => $visit->date->setTimezone('UTC')->toAtomString(),
                'userAgent' => $userAgent,
                'visitLocation' => null,
                'potentialBot' => $expectedToBePotentialBot,
                'visitedUrl' => $visit->visitedUrl,
                'redirectUrl' => $visit->redirectUrl,
                'id' => 0,
                'deviceType' => DeviceClassifier::classify($userAgent)->class->value,
                'deviceTypeDetail' => DeviceClassifier::classify($userAgent)->detail,
            ],
            $visit->jsonSerialize(),
        );
    }

    public static function provideUserAgents(): iterable
    {
        yield 'Chrome' => [
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.88 Safari/537.36',
            false,
        ];
        yield 'Firefox' => ['Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:47.0) Gecko/20100101 Firefox/47.0', false];
        yield 'Facebook' => ['cf-facebook', true];
        yield 'Twitter' => ['IDG Twitter Links Resolver', true];
        yield 'Guzzle' => ['guzzlehttp', true];
    }

    #[Test, DataProvider('provideOrphanVisits')]
    public function isProperlyJsonSerializedWhenOrphan(Visit $visit, array $expectedResult): void
    {
        self::assertEquals($expectedResult, $visit->jsonSerialize());
    }

    public static function provideOrphanVisits(): iterable
    {
        yield 'base path visit' => [
            $visit = Visit::forBasePath(Visitor::empty()),
            [
                'referer' => '',
                'date' => $visit->date->setTimezone('UTC')->toAtomString(),
                'userAgent' => '',
                'visitLocation' => null,
                'potentialBot' => false,
                'visitedUrl' => '',
                'type' => VisitType::BASE_URL->value,
                'redirectUrl' => null,
                'id' => 0,
                'deviceType' => DeviceClass::OTHER->value,
                'deviceTypeDetail' => DeviceClassifier::DETAIL_UNKNOWN,
            ],
        ];
        yield 'invalid short url visit' => [
            $visit = Visit::forInvalidShortUrl(Visitor::fromRequest(
                ServerRequestFactory::fromGlobals()
                    ->withHeader('User-Agent', 'foo')
                    ->withHeader('Referer', 'bar')
                    ->withUri(new Uri('https://example.com/foo')),
            )),
            [
                'referer' => 'bar',
                'date' => $visit->date->setTimezone('UTC')->toAtomString(),
                'userAgent' => 'foo',
                'visitLocation' => null,
                'potentialBot' => false,
                'visitedUrl' => 'https://example.com/foo',
                'type' => VisitType::INVALID_SHORT_URL->value,
                'redirectUrl' => null,
                'id' => 0,
                'deviceType' => DeviceClass::OTHER->value,
                'deviceTypeDetail' => DeviceClassifier::DETAIL_UNKNOWN,
            ],
        ];
        yield 'regular 404 visit' => [
            $visit = Visit::forRegularNotFound(
                Visitor::fromRequest(
                    ServerRequestFactory::fromGlobals()
                        ->withHeader('User-Agent', 'user-agent')
                        ->withHeader('Referer', 'referer')
                        ->withUri(new Uri('https://s.test/foo/bar')),
                ),
            )->locate($location = VisitLocation::fromLocation(Location::empty())),
            [
                'referer' => 'referer',
                'date' => $visit->date->setTimezone('UTC')->toAtomString(),
                'userAgent' => 'user-agent',
                'visitLocation' => $location,
                'potentialBot' => false,
                'visitedUrl' => 'https://s.test/foo/bar',
                'type' => VisitType::REGULAR_404->value,
                'redirectUrl' => null,
                'id' => 0,
                'deviceType' => DeviceClass::OTHER->value,
                'deviceTypeDetail' => DeviceClassifier::DETAIL_UNKNOWN,
            ],
        ];
    }

    #[Test, DataProvider('provideAddresses')]
    public function addressIsAnonymizedWhenRequested(
        bool $anonymize,
        string|null $address,
        string|null $expectedAddress,
    ): void {
        $visit = Visit::forValidShortUrl(
            ShortUrl::createFake(),
            Visitor::fromParams('Chrome', 'some site', $address),
            $anonymize,
        );

        self::assertEquals($expectedAddress, $visit->remoteAddr);
    }

    public static function provideAddresses(): iterable
    {
        yield 'anonymized null address' => [true, null, null];
        yield 'non-anonymized null address' => [false, null, null];
        yield 'anonymized localhost' => [true, IpAddress::LOCALHOST, IpAddress::LOCALHOST];
        yield 'non-anonymized localhost' => [false, IpAddress::LOCALHOST, IpAddress::LOCALHOST];
        yield 'anonymized regular address' => [true, '1.2.3.4', '1.2.3.0'];
        yield 'non-anonymized regular address' => [false, '1.2.3.4', '1.2.3.4'];
    }

    #[Test, Group('spec:click-tracking:AC-14')]
    public function visitFromUnrecognizedUserAgentIsClassifiedAsOther(): void
    {
        $visit = Visit::forValidShortUrl(
            ShortUrl::createFake(),
            Visitor::fromParams(userAgent: 'some-internal-http-client/1.0'),
        );

        self::assertSame(DeviceClass::OTHER, $visit->deviceType);
        self::assertSame(DeviceClassifier::DETAIL_UNKNOWN, $visit->deviceTypeDetail);
    }

    #[Test, DataProvider('provideDeviceUserAgents'), Group('spec:click-tracking:AC-15')]
    public function serializedDeviceClassIsTheClassifiersOutputForThatUserAgent(string $userAgent): void
    {
        $visit = Visit::forValidShortUrl(ShortUrl::createFake(), Visitor::fromParams(userAgent: $userAgent));
        $classification = DeviceClassifier::classify($userAgent);
        $serialized = $visit->jsonSerialize();

        self::assertSame($classification->class->value, $serialized['deviceType'] ?? null);
        self::assertSame($classification->detail, $serialized['deviceTypeDetail'] ?? null);
    }

    public static function provideDeviceUserAgents(): iterable
    {
        yield 'mobile' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) Mobile/15E148 Safari/604.1'];
        yield 'tablet' => ['Mozilla/5.0 (iPad; CPU OS 16_6 like Mac OS X) Version/16.6 Safari/604.1'];
        yield 'desktop' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/115.0.0.0 Safari/537.36'];
        yield 'other' => ['Mozilla/5.0 (SMART-TV; Linux; Tizen 6.0) AppleWebKit/537.36'];
    }

    #[Test, DataProvider('provideTimezones'), Group('spec:click-tracking:AC-10')]
    public function serializedDateIsUtcRegardlessOfConfiguredTimezone(string $timezone): void
    {
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set($timezone);

        try {
            $visit = Visit::forValidShortUrl(ShortUrl::createFake(), Visitor::empty());
            /** @var string $serializedDate */
            $serializedDate = $visit->jsonSerialize()['date'];
        } finally {
            date_default_timezone_set($originalTimezone);
        }

        self::assertStringEndsWith('+00:00', $serializedDate);
        self::assertSame($visit->date->getTimestamp(), Chronos::parse($serializedDate)->getTimestamp());
    }

    public static function provideTimezones(): iterable
    {
        yield 'UTC' => ['UTC'];
        yield 'behind UTC' => ['America/New_York'];
        yield 'ahead of UTC' => ['Europe/Kyiv'];
    }

    #[Test, Group('spec:click-tracking:AC-15')]
    public function persistedVisitIsSerializedWithItsId(): void
    {
        $visit = Visit::forValidShortUrl(ShortUrl::createFake(), Visitor::empty());
        $visit->setId('123');

        self::assertSame(123, $visit->jsonSerialize()['id'] ?? null);
    }

    #[Test, Group('spec:click-tracking:AC-15')]
    public function importedVisitHasNoDeviceClassAndSerializesItAsNull(): void
    {
        $visit = Visit::fromImport(
            ShortUrl::createFake(),
            new ImportedShlinkVisit('some site', 'Chrome', Chronos::now(), null),
        );

        self::assertNull($visit->deviceType);
        self::assertNull($visit->deviceTypeDetail);

        $serialized = $visit->jsonSerialize();

        self::assertArrayHasKey('deviceType', $serialized);
        self::assertArrayHasKey('deviceTypeDetail', $serialized);
        self::assertNull($serialized['deviceType'] ?? null);
        self::assertNull($serialized['deviceTypeDetail'] ?? null);
    }
}
