<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Core\Visit;

use Doctrine\ORM\EntityManagerInterface;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\Uri;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Shlinkio\Shlink\Common\Util\DateRange;
use Shlinkio\Shlink\Core\EventDispatcher\Event\UrlVisited;
use Shlinkio\Shlink\Core\EventDispatcher\Event\VisitLocated;
use Shlinkio\Shlink\Core\EventDispatcher\LocateVisit;
use Shlinkio\Shlink\Core\ShortUrl\Entity\ShortUrl;
use Shlinkio\Shlink\Core\ShortUrl\Model\ShortUrlIdentifier;
use Shlinkio\Shlink\Core\Visit\Entity\Visit;
use Shlinkio\Shlink\Core\Visit\Model\Visitor;
use Shlinkio\Shlink\Core\Visit\Model\VisitsParams;
use Shlinkio\Shlink\Core\Visit\Paginator\Adapter\ShortUrlVisitsPaginatorAdapter;
use Shlinkio\Shlink\Core\Visit\Persistence\VisitsCountFiltering;
use Shlinkio\Shlink\Core\Visit\Persistence\VisitsListFiltering;
use Shlinkio\Shlink\Core\Visit\Repository\VisitRepositoryInterface;
use Shlinkio\Shlink\IpGeolocation\Exception\WrongIpException;
use Shlinkio\Shlink\IpGeolocation\GeoLite2\DbUpdaterInterface;
use Shlinkio\Shlink\IpGeolocation\Resolver\IpLocationResolverInterface;

use function sprintf;

/**
 * Ported from the api test suite, which this fork no longer runs. The database is mocked, so what survives here is
 * the behaviour of the units the removed end-to-end assertions were really about.
 */
class ClickTrackingTest extends TestCase
{
    private const SHORT_CODE = 'def456';
    private const CRAWLER_USER_AGENT = 'cf-facebook';
    private const BROWSER_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like '
        . 'Gecko) Chrome/120.0.0.0 Safari/537.36';

    /**
     * The per-message marker is an opaque query parameter to this service: it records the URL it receives and never
     * parses it, which is what lets the consumer attribute the click.
     *
     * @test
     * @group spec:click-tracking:AC-11
     */
    public function markerQueryParamSurvivesInTheRecordedVisitedUrl(): void
    {
        $marker = 'utm_sm_mid=90210';
        $request = ServerRequestFactory::fromGlobals()->withUri(
            new Uri(sprintf('https://sh.example.com/%s?%s', self::SHORT_CODE, $marker)),
        )->withHeader('User-Agent', self::BROWSER_USER_AGENT);

        $visit = Visit::forValidShortUrl(ShortUrl::createEmpty(), Visitor::fromRequest($request));

        self::assertNotNull($visit->visitedUrl());
        self::assertStringContainsString($marker, $visit->visitedUrl());
        self::assertStringContainsString($marker, $visit->jsonSerialize()['visitedUrl']);
    }

    /**
     * A location that cannot be resolved is a degradation, not an error: the visit is recorded and published all the
     * same, with no location on it.
     *
     * @test
     * @group spec:click-tracking:AC-11
     */
    public function visitWithNoResolvableLocationIsStillRecordedWithoutOne(): void
    {
        $visit = Visit::forValidShortUrl(
            ShortUrl::createEmpty(),
            new Visitor(self::BROWSER_USER_AGENT, '', '1.2.3.4', 'https://sh.example.com/def456?utm_sm_mid=90211'),
        );
        $em = $this->createMock(EntityManagerInterface::class);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $ipLocationResolver = $this->createMock(IpLocationResolverInterface::class);
        $dbUpdater = $this->createMock(DbUpdaterInterface::class);

        $em->method('find')->with(Visit::class, '123')->willReturn($visit);
        $em->expects($this->never())->method('flush');
        $dbUpdater->method('databaseFileExists')->willReturn(true);
        $ipLocationResolver->method('resolveIpLocation')->willThrowException(
            WrongIpException::fromIpAddress('1.2.3.4'),
        );
        $eventDispatcher->expects($this->once())->method('dispatch')->with(new VisitLocated('123'));

        $locateVisit = new LocateVisit(
            $ipLocationResolver,
            $em,
            $this->createMock(LoggerInterface::class),
            $dbUpdater,
            $eventDispatcher,
        );

        $locateVisit(new UrlVisited('123'));

        self::assertNull($visit->getVisitLocation());
        self::assertNull($visit->jsonSerialize()['visitLocation']);
    }

    /**
     * Wiring only: the repository is mocked, so this asserts that `excludeBots` reaches both filters, not that crawler
     * visits are actually omitted from a count. The behavioural assertion was T017, withdrawn with this fork's
     * `test-db` and `test-api` suites because it needs persisted rows. The AC-4 tag therefore covers this flag's
     * plumbing; the bot verdict itself is covered by `crawlerVisitIsRecordedAsPotentialBot` below.
     *
     * @test
     * @group spec:click-tracking:AC-4
     */
    public function excludeBotsFlagIsPassedThroughToTheVisitFilters(): void
    {
        $repo = $this->createMock(VisitRepositoryInterface::class);
        $adapter = new ShortUrlVisitsPaginatorAdapter(
            $repo,
            ShortUrlIdentifier::fromShortCodeAndDomain(self::SHORT_CODE),
            VisitsParams::fromRawData(['excludeBots' => true]),
            null,
        );

        $repo->expects($this->once())->method('countVisitsByShortCode')->with(
            ShortUrlIdentifier::fromShortCodeAndDomain(self::SHORT_CODE),
            new VisitsCountFiltering(DateRange::allTime(), true, null),
        )->willReturn(1);
        $repo->expects($this->once())->method('findVisitsByShortCode')->with(
            ShortUrlIdentifier::fromShortCodeAndDomain(self::SHORT_CODE),
            new VisitsListFiltering(DateRange::allTime(), true, null, 10, 0),
        )->willReturn([]);

        self::assertEquals(1, $adapter->getNbResults());
        $adapter->getSlice(0, 10);
    }

    /**
     * @test
     * @group spec:click-tracking:AC-4
     */
    public function crawlerVisitIsRecordedAsPotentialBot(): void
    {
        $visit = Visit::forValidShortUrl(
            ShortUrl::createEmpty(),
            new Visitor(self::CRAWLER_USER_AGENT, '', null, ''),
        );

        self::assertTrue($visit->jsonSerialize()['potentialBot']);
    }

    /**
     * The fragments moved here from micro-shortener-proxy, which applied them while it wrote its own click record.
     * CrawlerDetect passes every one of these user agents, so each case fails without the moved list.
     *
     * @test
     * @dataProvider provideUserAgentsMovedFromTheRedirectProxy
     * @group spec:click-tracking:AC-4
     */
    public function userAgentMovedFromTheRedirectProxyIsRecordedAsPotentialBot(string $userAgent): void
    {
        $visit = Visit::forValidShortUrl(ShortUrl::createEmpty(), new Visitor($userAgent, '', null, ''));

        self::assertTrue($visit->jsonSerialize()['potentialBot']);
    }

    public static function provideUserAgentsMovedFromTheRedirectProxy(): iterable
    {
        yield 'iMessage link preview' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 '
            . '(KHTML, like Gecko) Version/17.0 Safari/605.1.15 LinkPresentation/1.0'];
        yield 'Google Messages link preview' => ['Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like '
            . 'Gecko) Chrome/120.0.0.0 Mobile Safari/537.36 GoogleMessages/1.0'];
        yield 'page renderer' => ['Mozilla/5.0 (compatible; PageRenderer/1.0)'];
        yield 'sprinklr' => ['Mozilla/5.0 (compatible; Sprinklr/1.0)'];
        yield 'sogou' => ['Mozilla/5.0 (compatible; Sogou/1.0)'];
    }

    /**
     * @test
     * @dataProvider provideOrdinaryBrowserUserAgents
     * @group spec:click-tracking:AC-4
     */
    public function ordinaryBrowserIsNotRecordedAsPotentialBot(string $userAgent): void
    {
        $visit = Visit::forValidShortUrl(ShortUrl::createEmpty(), new Visitor($userAgent, '', null, ''));

        self::assertFalse($visit->jsonSerialize()['potentialBot']);
    }

    public static function provideOrdinaryBrowserUserAgents(): iterable
    {
        yield 'desktop' => [self::BROWSER_USER_AGENT];
        yield 'mobile' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like '
            . 'Gecko) Version/17.0 Mobile/15E148 Safari/604.1'];
    }
}
