<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Core\Visit\Entity;

use Cake\Chronos\Chronos;
use PHPUnit\Framework\TestCase;
use Shlinkio\Shlink\Common\Util\IpAddress;
use Shlinkio\Shlink\Core\ShortUrl\Entity\ShortUrl;
use Shlinkio\Shlink\Core\Visit\DeviceClassifier;
use Shlinkio\Shlink\Core\Visit\Entity\Visit;
use Shlinkio\Shlink\Core\Visit\Model\DeviceClass;
use Shlinkio\Shlink\Core\Visit\Model\Visitor;
use Shlinkio\Shlink\Importer\Model\ImportedShlinkVisit;

use function date_default_timezone_get;
use function date_default_timezone_set;

class VisitTest extends TestCase
{
    /**
     * @test
     * @dataProvider provideUserAgents
     */
    public function isProperlyJsonSerialized(string $userAgent, bool $expectedToBePotentialBot): void
    {
        $visit = Visit::forValidShortUrl(ShortUrl::createEmpty(), new Visitor($userAgent, 'some site', '1.2.3.4', ''));

        self::assertEquals([
            'referer' => 'some site',
            'date' => $visit->getDate()->toAtomString(),
            'userAgent' => $userAgent,
            'visitLocation' => null,
            'potentialBot' => $expectedToBePotentialBot,
            'id' => 0,
            'visitedUrl' => '',
            'deviceType' => DeviceClassifier::classify($userAgent)->class->value,
            'deviceTypeDetail' => DeviceClassifier::classify($userAgent)->detail,
        ], $visit->jsonSerialize());
    }

    public function provideUserAgents(): iterable
    {
        yield 'Chrome' => [
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.88 Safari/537.36',
            true,
        ];
        yield 'Firefox' => ['Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:47.0) Gecko/20100101 Firefox/47.0', false];
        yield 'Facebook' => ['cf-facebook', true];
        yield 'Twitter' => ['IDG Twitter Links Resolver', true];
        yield 'Guzzle' => ['guzzlehttp', true];
    }

    /**
     * @test
     * @dataProvider provideAddresses
     */
    public function addressIsAnonymizedWhenRequested(bool $anonymize, ?string $address, ?string $expectedAddress): void
    {
        $visit = Visit::forValidShortUrl(
            ShortUrl::createEmpty(),
            new Visitor('Chrome', 'some site', $address, ''),
            $anonymize,
        );

        self::assertEquals($expectedAddress, $visit->getRemoteAddr());
    }

    public function provideAddresses(): iterable
    {
        yield 'anonymized null address' => [true, null, null];
        yield 'non-anonymized null address' => [false, null, null];
        yield 'anonymized localhost' => [true, IpAddress::LOCALHOST, IpAddress::LOCALHOST];
        yield 'non-anonymized localhost' => [false, IpAddress::LOCALHOST, IpAddress::LOCALHOST];
        yield 'anonymized regular address' => [true, '1.2.3.4', '1.2.3.0'];
        yield 'non-anonymized regular address' => [false, '1.2.3.4', '1.2.3.4'];
    }

    /**
     * @test
     * @group spec:click-tracking:AC-14
     */
    public function visitFromUnrecognizedUserAgentIsClassifiedAsOther(): void
    {
        $visit = Visit::forValidShortUrl(
            ShortUrl::createEmpty(),
            new Visitor('some-internal-http-client/1.0', '', null, ''),
        );

        self::assertSame(DeviceClass::OTHER, $visit->deviceType());
        self::assertSame(DeviceClassifier::DETAIL_UNKNOWN, $visit->deviceTypeDetail());
    }

    /**
     * @test
     * @dataProvider provideDeviceUserAgents
     * @group spec:click-tracking:AC-15
     */
    public function serializedDeviceClassIsTheClassifiersOutputForThatUserAgent(string $userAgent): void
    {
        $visit = Visit::forValidShortUrl(ShortUrl::createEmpty(), new Visitor($userAgent, '', null, ''));
        $classification = DeviceClassifier::classify($userAgent);
        $serialized = $visit->jsonSerialize();

        self::assertSame($classification->class->value, $serialized['deviceType']);
        self::assertSame($classification->detail, $serialized['deviceTypeDetail']);
    }

    public function provideDeviceUserAgents(): iterable
    {
        yield 'mobile' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) Mobile/15E148 Safari/604.1'];
        yield 'tablet' => ['Mozilla/5.0 (iPad; CPU OS 16_6 like Mac OS X) Version/16.6 Safari/604.1'];
        yield 'desktop' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/115.0.0.0 Safari/537.36'];
        yield 'other' => ['Mozilla/5.0 (SMART-TV; Linux; Tizen 6.0) AppleWebKit/537.36'];
    }

    /**
     * @test
     * @dataProvider provideTimezones
     * @group spec:click-tracking:AC-10
     */
    public function serializedDateIsUtcRegardlessOfConfiguredTimezone(string $timezone): void
    {
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set($timezone);

        try {
            $visit = Visit::forValidShortUrl(ShortUrl::createEmpty(), Visitor::emptyInstance());
            $serializedDate = $visit->jsonSerialize()['date'];
        } finally {
            date_default_timezone_set($originalTimezone);
        }

        self::assertStringEndsWith('+00:00', $serializedDate);
        self::assertEquals(
            Chronos::parse($serializedDate)->getTimestamp(),
            $visit->getDate()->getTimestamp(),
        );
    }

    public function provideTimezones(): iterable
    {
        yield 'UTC' => ['UTC'];
        yield 'behind UTC' => ['America/New_York'];
        yield 'ahead of UTC' => ['Europe/Kyiv'];
    }

    /**
     * @test
     * @dataProvider provideCrawlerUserAgents
     * @group spec:click-tracking:AC-4
     */
    public function visitFromCrawlerIsSerializedAsPotentialBot(string $userAgent): void
    {
        $visit = Visit::forValidShortUrl(ShortUrl::createEmpty(), new Visitor($userAgent, '', null, ''));

        self::assertTrue($visit->jsonSerialize()['potentialBot']);
    }

    public function provideCrawlerUserAgents(): iterable
    {
        yield 'Facebook' => ['cf-facebook'];
        yield 'Twitter' => ['IDG Twitter Links Resolver'];
        yield 'Guzzle' => ['guzzlehttp'];
    }

    /**
     * @test
     * @group spec:click-tracking:AC-15
     */
    public function visitWithNoDeviceColumnsSerializesThemAsNull(): void
    {
        $visit = Visit::fromImport(
            ShortUrl::createEmpty(),
            new ImportedShlinkVisit('some site', 'Chrome', Chronos::now(), null),
        );

        self::assertNull($visit->deviceType());
        self::assertNull($visit->deviceTypeDetail());

        $serialized = $visit->jsonSerialize();

        self::assertArrayHasKey('deviceType', $serialized);
        self::assertArrayHasKey('deviceTypeDetail', $serialized);
        self::assertNull($serialized['deviceType']);
        self::assertNull($serialized['deviceTypeDetail']);
    }
}
