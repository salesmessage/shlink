<?php

declare(strict_types=1);

namespace ShlinkioDbTest\Shlink\Core\Visit\Repository;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Shlinkio\Shlink\Core\ShortUrl\Entity\ShortUrl;
use Shlinkio\Shlink\Core\Visit\DeviceClassifier;
use Shlinkio\Shlink\Core\Visit\Entity\Visit;
use Shlinkio\Shlink\Core\Visit\Model\DeviceClass;
use Shlinkio\Shlink\Core\Visit\Model\Visitor;
use Shlinkio\Shlink\TestUtils\DbTest\DatabaseTestCase;

class VisitDeviceClassMappingTest extends DatabaseTestCase
{
    #[Test, Group('spec:click-tracking:AC-14')]
    public function deviceClassIsStoredAndLoadedBack(): void
    {
        $em = $this->getEntityManager();
        $shortUrl = ShortUrl::createFake();
        $em->persist($shortUrl);
        $tvVisit = Visit::forValidShortUrl(
            $shortUrl,
            Visitor::fromParams(userAgent: 'Mozilla/5.0 (SMART-TV; Linux; Tizen 6.0) AppleWebKit/537.36'),
        );
        $mobileVisit = Visit::forValidShortUrl(
            $shortUrl,
            Visitor::fromParams(userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) Mobile/15E148'),
        );
        $em->persist($tvVisit);
        $em->persist($mobileVisit);
        $em->flush();
        $em->clear();

        $loadedTvVisit = $em->find(Visit::class, $tvVisit->getId());
        $loadedMobileVisit = $em->find(Visit::class, $mobileVisit->getId());

        self::assertSame(DeviceClass::OTHER, $loadedTvVisit?->deviceType);
        self::assertSame(DeviceClassifier::DETAIL_SMART_TV, $loadedTvVisit?->deviceTypeDetail);
        self::assertSame(DeviceClass::MOBILE, $loadedMobileVisit?->deviceType);
        self::assertNull($loadedMobileVisit?->deviceTypeDetail);
    }
}
