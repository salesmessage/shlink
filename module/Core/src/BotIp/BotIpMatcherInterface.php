<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\BotIp;

use Shlinkio\Shlink\Core\BotIp\Model\BotIpSource;

interface BotIpMatcherInterface
{
    /**
     * Whether a full, not anonymized, address is inside a range of any source.
     */
    public function isBotAddress(?string $address): bool;

    /**
     * Whether the /24 network of an IPv4 address overlaps a range of the source. Stored visits keep only that
     * network (the last octet is anonymized), so this is how past visits are checked.
     */
    public function isInBotNetwork(?string $address, BotIpSource $source): bool;
}
