<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Site\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Module\Event\SettingsChanged;
use SightPress\Core\Site\Contract\SiteProfile;
use SightPress\Core\Tests\Support\CoreSiteDatabase;
use SightPress\Core\Tests\Support\PageFixture;

#[CoversClass(SiteProfile::class)]
#[UsesNamespace('SightPress\Core')]
final class SiteProfileTest extends TestCase
{
    public function testReadsWhatTheAdminsWroteAndLeavesOutWhatDoesntFit(): void
    {
        $settings = new CoreSiteDatabase()->settings();
        self::assertEquals(new SiteProfile(), SiteProfile::read($settings));

        foreach ([
            SiteProfile::DESCRIPTION => ' A library. ', SiteProfile::EMAIL => 'not an email', SiteProfile::PHONE => '',
            SiteProfile::ADDRESS => '1 Mill Lane', SiteProfile::LEGAL_NAME => 'Riverside Trust',
            SiteProfile::SAME_AS => "https://example.social/@riverside\r\nhttp://insecure.example\n\nhttps://example.social/@riverside\nhttps://nodot\nhttps://directory.example/riverside?x=1",
        ] as $key => $value) {
            $settings->set($key, $value);
        }

        self::assertEquals(new SiteProfile('A library.', null, null, '1 Mill Lane', 'Riverside Trust', ['https://example.social/@riverside', 'https://directory.example/riverside?x=1']), SiteProfile::read($settings));
        $settings->set(SiteProfile::EMAIL, 'hello@example.org');
        self::assertSame('hello@example.org', SiteProfile::read($settings)->email);
        self::assertFalse(SiteProfile::isLink('https://' . str_repeat('a', 2000) . '.example'));
        self::assertSame(['a', 'b'], SiteProfile::lines("a\r\nb"));
    }

    public function testGivesTheSiteItsChosenDisplay(): void
    {
        $settings = new CoreSiteDatabase()->settings();
        $settings->set(SiteProfile::NAME, 'Riverside');
        $settings->set(SiteProfile::DEFAULT_LOCALE, 'pt-PT');
        $settings->set(SiteProfile::DEFAULT_TIMEZONE, 'Asia/Tokyo');

        $site = SiteProfile::display(PageFixture::site(['en-GB', 'pt-PT']), $settings);

        self::assertSame(['Riverside', 'pt-PT', 'Asia/Tokyo'], [$site->name, $site->defaultLocale, $site->defaultTimezone]);

        $settings->set(SiteProfile::LOCALES, 'pt-PT, es');
        self::assertSame(['pt-PT', 'es'], SiteProfile::display(PageFixture::site(['en-GB', 'pt-PT']), $settings)->locales);
    }

    public function testSaysWhichSettingsChanged(): void
    {
        $event = new SettingsChanged([SiteProfile::NAME], 4);

        self::assertTrue($event->has(SiteProfile::NAME));
        self::assertFalse($event->has(SiteProfile::EMAIL));
        self::assertSame(4, $event->actorId);
    }
}
