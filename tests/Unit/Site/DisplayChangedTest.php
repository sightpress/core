<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Site;

use DI\Container;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Module\Event\SettingsChanged;
use SightPress\Core\Site\Contract\SiteProfile;
use SightPress\Core\Site\DisplayChanged;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\SiteFixture;

#[CoversClass(DisplayChanged::class)]
#[UsesNamespace('SightPress\Core')]
final class DisplayChangedTest extends TestCase
{
    public function testANewNameLanguageOrTimeZoneRebuildsTheSiteOnTheNextRequest(): void
    {
        $factory = new SiteContainerFactory(new Container(), SiteFixture::secretBox(), TenantConfig::fromArray([], '/srv/app'));
        $site = SiteFixture::site('demo');
        $container = $factory->forSite($site);
        $listener = new DisplayChanged($factory, $site);

        $listener(new SettingsChanged([SiteProfile::EMAIL], 1));
        self::assertSame($container, $factory->forSite($site));

        foreach ([SiteProfile::NAME, SiteProfile::LOCALES, SiteProfile::DEFAULT_LOCALE, SiteProfile::DEFAULT_TIMEZONE] as $key) {
            $listener(new SettingsChanged([$key], 1));
            self::assertNotSame($container, $factory->forSite($site), $key);
            $container = $factory->forSite($site);
        }
    }
}
