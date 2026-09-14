<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\Visit\Model;

final class DeviceClassification
{
    private function __construct(public readonly DeviceClass $class, public readonly ?string $detail)
    {
    }

    public static function mobile(): self
    {
        return new self(DeviceClass::MOBILE, null);
    }

    public static function tablet(): self
    {
        return new self(DeviceClass::TABLET, null);
    }

    public static function desktop(): self
    {
        return new self(DeviceClass::DESKTOP, null);
    }

    public static function other(string $detail): self
    {
        return new self(DeviceClass::OTHER, $detail);
    }
}
