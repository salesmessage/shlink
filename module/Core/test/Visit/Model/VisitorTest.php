<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Core\Visit\Model;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shlinkio\Shlink\Core\Config\Options\TrackingOptions;
use Shlinkio\Shlink\Core\Visit\Model\Visitor;

use function random_int;
use function str_repeat;
use function strlen;
use function substr;

class VisitorTest extends TestCase
{
    #[Test, DataProvider('provideParams')]
    public function providedFieldsValuesAreCropped(array $params, array $expected): void
    {
        $visitor = Visitor::fromParams(...$params);
        ['userAgent' => $userAgent, 'referer' => $referer, 'remoteAddress' => $remoteAddress] = $expected;

        self::assertEquals($userAgent, $visitor->userAgent);
        self::assertEquals($referer, $visitor->referer);
        self::assertEquals($remoteAddress, $visitor->remoteAddress);
    }

    public static function provideParams(): iterable
    {
        yield 'all values are bigger' => [
            [str_repeat('a', 1000), str_repeat('b', 2000), str_repeat('c', 500), ''],
            [
                'userAgent' => str_repeat('a', Visitor::USER_AGENT_MAX_LENGTH),
                'referer' => str_repeat('b', Visitor::REFERER_MAX_LENGTH),
                'remoteAddress' => str_repeat('c', Visitor::REMOTE_ADDRESS_MAX_LENGTH),
            ],
        ];
        yield 'some values are smaller' => [
            [str_repeat('a', 10), str_repeat('b', 2000), null, ''],
            [
                'userAgent' => str_repeat('a', 10),
                'referer' => str_repeat('b', Visitor::REFERER_MAX_LENGTH),
                'remoteAddress' => null,
            ],
        ];
        yield 'random strings' => [
            [
                $userAgent = self::generateRandomString(2000),
                $referer = self::generateRandomString(50),
                null,
                '',
            ],
            [
                'userAgent' => substr($userAgent, 0, Visitor::USER_AGENT_MAX_LENGTH),
                'referer' => $referer,
                'remoteAddress' => null,
            ],
        ];
    }

    private static function generateRandomString(int $length): string
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[random_int(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    #[Test]
    public function newNormalizedInstanceIsCreatedFromTrackingOptions(): void
    {
        $visitor = Visitor::fromParams(
            self::generateRandomString(2000),
            self::generateRandomString(2000),
            self::generateRandomString(2000),
            self::generateRandomString(2000),
        );
        $normalizedVisitor = $visitor->normalizeForTrackingOptions(new TrackingOptions(
            disableIpTracking: true,
            disableReferrerTracking: true,
            disableUaTracking: true,
        ));

        self::assertNotSame($visitor, $normalizedVisitor);
        self::assertEmpty($normalizedVisitor->userAgent);
        self::assertNotEmpty($visitor->userAgent);
        self::assertEmpty($normalizedVisitor->referer);
        self::assertNotEmpty($visitor->referer);
        self::assertNull($normalizedVisitor->remoteAddress);
        self::assertNotNull($visitor->remoteAddress);
    }

    #[Test, DataProvider('provideNonBrowserUserAgents')]
    public function nonBrowserClientIsPotentialBot(string $userAgent): void
    {
        self::assertTrue(Visitor::fromParams(userAgent: $userAgent)->potentialBot);
    }

    /**
     * Link previews and non-browser clients seen on short URLs that CrawlerDetect does not flag
     * (BOT_USER_AGENT_FRAGMENTS), and requests without a user agent, which browsers and apps always send
     */
    public static function provideNonBrowserUserAgents(): iterable
    {
        yield 'iMessage link preview' => ['Mozilla/5.0 (Macintosh) AppleWebKit/605.1.15 LinkPresentation/1.0'];
        yield 'Google Messages' => ['Mozilla/5.0 (Linux; Android 14) GoogleMessages/20.0'];
        yield 'page renderer' => ['Mozilla/5.0 (compatible; PageRenderer/1.0)'];
        yield 'Sprinklr' => ['Sprinklr Crawler/1.0'];
        yield 'Sogou' => ['Sogou web spider/4.0'];
        yield 'Viber' => ['Mozilla/5.0 ViberUrlDownloader/0.1'];
        yield 'LINE' => ['line-poker/1.0'];
        yield 'Signal link preview' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Signal-Desktop/6.42.0'];
        yield 'pcp-stier-sdk' => ['pcp-stier-sdk/1.5.1 na/na'];
        yield 'antispam' => ['antispam/1.0.0'];
        yield 'RiskScanner' => ['RiskScanner/1.0'];
        yield 'Google-Safety' => ['Google-Safety'];
        yield 'IE8' => ['Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 6.1; WOW64; Trident/4.0)'];
        yield 'empty' => [''];
        yield 'blank' => ['   '];
    }

    #[Test, DataProvider('provideAppsPeopleOpenLinksWith')]
    public function appPeopleOpenLinksWithIsNotPotentialBot(string $userAgent): void
    {
        self::assertFalse(Visitor::fromParams(userAgent: $userAgent)->potentialBot);
    }

    /**
     * Left out of the bot rules on purpose: people may be behind them
     */
    public static function provideAppsPeopleOpenLinksWith(): iterable
    {
        yield 'TextFree' => ['textfreeUltra 13.28.1'];
        yield 'Google Lens' => ['Google-Lens'];
        yield 'Android app' => ['Dalvik/2.1.0 (Linux; U; Android 13; SM-A326U Build/TP1A.220624.014)'];
        yield 'Firefox 115' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:115.0) Gecko/20100101 Firefox/115.0'];
    }

    #[Test]
    public function markingAsPotentialBotKeepsTheRestAndSurvivesNormalization(): void
    {
        $visitor = Visitor::fromParams(
            userAgent: 'Mozilla/5.0 (X11; Linux x86_64) Chrome/141.0.0.0',
            referer: 'ref',
            remoteAddress: '66.249.80.201',
            visitedUrl: 'url',
        );
        $bot = $visitor->markAsPotentialBot();

        self::assertFalse($visitor->potentialBot);
        self::assertTrue($bot->potentialBot);
        self::assertEquals($visitor->userAgent, $bot->userAgent);
        self::assertEquals($visitor->referer, $bot->referer);
        self::assertEquals($visitor->remoteAddress, $bot->remoteAddress);
        self::assertEquals($visitor->visitedUrl, $bot->visitedUrl);
        $normalized = $bot->normalizeForTrackingOptions(new TrackingOptions(disableUaTracking: true));
        self::assertTrue($normalized->potentialBot);
    }
}
