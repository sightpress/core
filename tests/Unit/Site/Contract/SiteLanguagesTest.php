<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Site\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Module\Event\LocaleRemoved;
use SightPress\Module\Event\SettingsChanged;
use SightPress\Core\Site\Contract\SiteLanguageException;
use SightPress\Core\Site\Contract\SiteLanguages;
use SightPress\Core\Site\Contract\SiteProfile;
use SightPress\Core\Site\DbalSiteSettings;
use SightPress\Core\Tests\Support\CoreSiteDatabase;
use SightPress\Core\Tests\Support\PageFixture;
use SightPress\Core\Tests\Support\RecordingEvents;

#[CoversClass(SiteLanguages::class)]
#[UsesNamespace('SightPress\Core')]
final class SiteLanguagesTest extends TestCase
{
    private DbalSiteSettings $settings;

    private RecordingEvents $events;

    private SiteLanguages $languages;

    protected function setUp(): void
    {
        $this->settings = new CoreSiteDatabase()->settings();
        $this->events = new RecordingEvents();
        $this->languages = new SiteLanguages(PageFixture::site(['en-GB', 'pt-PT']), $this->settings, $this->events);
    }

    public function testAddsALanguageWrittenTheSightPressWay(): void
    {
        self::assertSame('es-ES', $this->languages->add(' ES_es ', 7));

        self::assertSame(['en-GB', 'pt-PT', 'es-ES'], $this->languages->locales());
        self::assertTrue($this->languages->has('es-ES'));
        self::assertSame('en-GB,pt-PT,es-ES', $this->settings->get(SiteProfile::LOCALES));
        self::assertSame('en-GB', $this->settings->get(SiteProfile::DEFAULT_LOCALE));
        self::assertEquals([new SettingsChanged([SiteProfile::LOCALES], 7)], $this->events->events);
        self::assertSame(['en-GB', 'pt-PT', 'es-ES'], SiteProfile::display(PageFixture::site(['en-GB']), $this->settings)->locales);
    }

    public function testRefusesWhatIsNotALocaleOrIsThereAlready(): void
    {
        foreach (['klingon' => '"klingon" is not a locale', 'pt-PT' => 'The site is already written in European Portuguese (pt-PT).'] as $locale => $message) {
            try {
                $this->languages->add($locale);
                self::fail($locale);
            } catch (SiteLanguageException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }
        self::assertSame([], $this->events->events);
    }

    public function testRemovesALanguageButNeverTheMainOne(): void
    {
        $this->languages->remove('pt-PT', 3);

        self::assertSame(['en-GB'], $this->languages->locales());
        self::assertSame('en-GB', $this->settings->get(SiteProfile::LOCALES));
        self::assertEquals([new SettingsChanged([SiteProfile::LOCALES], 3), new LocaleRemoved('pt-PT', 3)], $this->events->events);

        foreach (['en-GB' => 'British English (en-GB) is the main language, so it can’t be removed.', 'pt-PT' => 'The site isn’t written in “pt-PT”. Its languages are: en-GB.', '' => 'The site isn’t written in “”'] as $locale => $message) {
            try {
                $this->languages->remove($locale);
                self::fail($locale);
            } catch (SiteLanguageException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

    public function testMakesAnotherLanguageTheMainOne(): void
    {
        self::assertFalse($this->languages->makeDefault('en-GB'));
        self::assertSame([], $this->events->events);

        self::assertTrue($this->languages->makeDefault('pt-PT', 2));

        self::assertSame('pt-PT', $this->languages->defaultLocale());
        self::assertSame(['pt-PT', 'en-GB'], $this->languages->locales());
        self::assertSame('pt-PT', $this->settings->get(SiteProfile::DEFAULT_LOCALE));
        self::assertEquals([new SettingsChanged([SiteProfile::DEFAULT_LOCALE], 2)], $this->events->events);
        $this->languages->remove('en-GB');
        self::assertSame(['pt-PT'], $this->languages->locales());

        $this->expectException(SiteLanguageException::class);
        $this->languages->makeDefault('fr-FR');
    }
}
