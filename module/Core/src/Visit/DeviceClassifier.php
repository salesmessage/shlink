<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\Visit;

use Shlinkio\Shlink\Core\Visit\Model\DeviceClassification;

use function str_contains;
use function strtolower;
use function trim;

/** spec:click-tracking: ADR-003, AC-14 */
final class DeviceClassifier
{
    public const DETAIL_SMART_TV = 'smart-tv';
    public const DETAIL_CONSOLE = 'console';
    public const DETAIL_UNKNOWN = 'unknown';

    private const SMART_TV_HINTS = [
        'smart-tv',
        'smarttv',
        'googletv',
        'android tv',
        'appletv',
        'hbbtv',
        'netcast',
        'tizen',
        'web0s',
        'webos',
        'roku',
        'crkey',
    ];
    private const CONSOLE_HINTS = ['playstation', 'xbox', 'nintendo'];
    private const TABLET_HINTS = ['ipad', 'tablet', 'playbook', 'kindle', 'silk'];
    private const MOBILE_HINTS = [
        'mobile',
        'iphone',
        'ipod',
        'android',
        'windows phone',
        'iemobile',
        'blackberry',
        'opera mini',
        'opera mobi',
    ];
    private const DESKTOP_HINTS = [
        'windows nt',
        'macintosh',
        'mac os x',
        'x11',
        'cros',
        'linux x86_64',
        'freebsd',
        'openbsd',
    ];

    public static function classify(?string $userAgent): DeviceClassification
    {
        $normalized = strtolower(trim($userAgent ?? ''));

        if ($normalized === '') {
            return DeviceClassification::other(self::DETAIL_UNKNOWN);
        }

        if (self::containsAny($normalized, self::SMART_TV_HINTS)) {
            return DeviceClassification::other(self::DETAIL_SMART_TV);
        }

        if (self::containsAny($normalized, self::CONSOLE_HINTS)) {
            return DeviceClassification::other(self::DETAIL_CONSOLE);
        }

        if (self::containsAny($normalized, self::TABLET_HINTS)) {
            return DeviceClassification::tablet();
        }

        if (self::containsAny($normalized, self::MOBILE_HINTS)) {
            return str_contains($normalized, 'android') && ! str_contains($normalized, 'mobile')
                ? DeviceClassification::tablet()
                : DeviceClassification::mobile();
        }

        if (self::containsAny($normalized, self::DESKTOP_HINTS)) {
            return DeviceClassification::desktop();
        }

        return DeviceClassification::other(self::DETAIL_UNKNOWN);
    }

    /**
     * @param string[] $hints
     */
    private static function containsAny(string $normalizedUserAgent, array $hints): bool
    {
        foreach ($hints as $hint) {
            if (str_contains($normalizedUserAgent, $hint)) {
                return true;
            }
        }

        return false;
    }
}
