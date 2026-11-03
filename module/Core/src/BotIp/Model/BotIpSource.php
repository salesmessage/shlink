<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\BotIp\Model;

enum BotIpSource: string
{
    /** Published by Google as its own crawlers and fetchers, refreshed automatically */
    case GOOGLE = 'google';
    /** Added by hand through a migration, never touched by the refresh */
    case SCANNER = 'scanner';
}
