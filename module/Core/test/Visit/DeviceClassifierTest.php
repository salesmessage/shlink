<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Core\Visit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shlinkio\Shlink\Core\Visit\DeviceClassifier;
use Shlinkio\Shlink\Core\Visit\Model\DeviceClass;

class DeviceClassifierTest extends TestCase
{
    #[Test, DataProvider('provideUserAgents'), Group('spec:click-tracking:AC-14')]
    public function userAgentIsClassifiedAsExpected(
        string|null $userAgent,
        DeviceClass $expectedClass,
        string|null $expectedDetail,
    ): void {
        $classification = DeviceClassifier::classify($userAgent);

        self::assertSame($expectedClass, $classification->class);
        self::assertSame($expectedDetail, $classification->detail);
    }

    /**
     * @return iterable<string, array{string|null, DeviceClass, string|null}>
     */
    public static function provideUserAgents(): iterable
    {
        yield 'iPhone' => [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) '
                . 'Version/16.6 Mobile/15E148 Safari/604.1',
            DeviceClass::MOBILE,
            null,
        ];
        yield 'Android phone' => [
            'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 '
                . 'Mobile Safari/537.36',
            DeviceClass::MOBILE,
            null,
        ];
        yield 'iPad' => [
            'Mozilla/5.0 (iPad; CPU OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 '
                . 'Safari/604.1',
            DeviceClass::TABLET,
            null,
        ];
        yield 'Android tablet' => [
            'Mozilla/5.0 (Linux; Android 13; SM-T510) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 '
                . 'Safari/537.36',
            DeviceClass::TABLET,
            null,
        ];
        yield 'Windows desktop' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 '
                . 'Safari/537.36',
            DeviceClass::DESKTOP,
            null,
        ];
        yield 'macOS desktop' => [
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 '
                . 'Safari/605.1.15',
            DeviceClass::DESKTOP,
            null,
        ];
        yield 'smart TV' => [
            'Mozilla/5.0 (SMART-TV; Linux; Tizen 6.0) AppleWebKit/537.36',
            DeviceClass::OTHER,
            DeviceClassifier::DETAIL_SMART_TV,
        ];
        yield 'console' => [
            'Mozilla/5.0 (PlayStation; PlayStation 5/2.26) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/13.0',
            DeviceClass::OTHER,
            DeviceClassifier::DETAIL_CONSOLE,
        ];
        yield 'empty user agent' => ['', DeviceClass::OTHER, DeviceClassifier::DETAIL_UNKNOWN];
        yield 'blank user agent' => ['   ', DeviceClass::OTHER, DeviceClassifier::DETAIL_UNKNOWN];
        yield 'absent user agent' => [null, DeviceClass::OTHER, DeviceClassifier::DETAIL_UNKNOWN];
        yield 'unrecognized user agent' => [
            'some-internal-http-client/1.0',
            DeviceClass::OTHER,
            DeviceClassifier::DETAIL_UNKNOWN,
        ];
    }

    #[Test, DataProvider('provideUnrecognizedUserAgents'), Group('spec:click-tracking:AC-14')]
    public function noUnrecognizedUserAgentIsClassifiedAsDesktop(string|null $userAgent): void
    {
        $classification = DeviceClassifier::classify($userAgent);

        self::assertSame(DeviceClass::OTHER, $classification->class);
        self::assertNotNull($classification->detail);
    }

    public static function provideUnrecognizedUserAgents(): iterable
    {
        yield 'absent' => [null];
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'gibberish' => ['aaaaaaaa'];
        yield 'http client' => ['guzzlehttp'];
        yield 'crawler' => ['cf-facebook'];
        yield 'smart TV' => ['Mozilla/5.0 (SMART-TV; Linux; Tizen 6.0) AppleWebKit/537.36'];
        yield 'console' => ['Mozilla/5.0 (Nintendo Switch; WifiWebAuthApplet) AppleWebKit/609.4'];
    }

    #[Test, Group('spec:click-tracking:AC-14')]
    public function detailIsOnlyPresentForTheOtherClass(): void
    {
        foreach (self::provideUserAgents() as [$userAgent, $expectedClass]) {
            $classification = DeviceClassifier::classify($userAgent);

            if ($expectedClass === DeviceClass::OTHER) {
                self::assertNotNull($classification->detail);
            } else {
                self::assertNull($classification->detail);
            }
        }
    }
}
