<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\Visit\Model;

enum DeviceClass: string
{
    case MOBILE = 'mobile';
    case TABLET = 'tablet';
    case DESKTOP = 'desktop';
    case OTHER = 'other';
}
