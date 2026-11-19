<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Provisioning;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Page\Contract\StarterContent;
use SightPress\Core\Page\Contract\StarterReport;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\Provisioning\StarterPages;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Site\Contract\FirstAdmin;
use SightPress\Core\Site\Contract\SiteProvisioning;
use SightPress\Core\Tenant\OwnerType;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tests\Support\ArrayContainer;
use SightPress\Core\Tests\Support\ContactFixture;
use SightPress\Core\Tests\Support\PageAdminFixture;

#[CoversClass(StarterPages::class)]
#[CoversClass(StarterContent::class)]
#[CoversClass(StarterReport::class)]
#[UsesNamespace('SightPress\Core')]
final class StarterPagesTest extends TestCase
{
    public function testAnOrganizationGetsSixPublishedPagesWithHomeAsTheHomePage(): void
    {
        $fixture = new ContactFixture(['en-GB']);
        $event = $this->event($fixture, OwnerType::Organization);
        $event->setAdminId(7);

        new StarterPages()($event);

        $pages = $fixture->pages->repository()->children(null);
        self::assertSame(
            ['home', 'about', 'contact', 'privacy', 'terms', 'accessibility'],
            array_map(static fn (Page $page): string => (string) $page->translation('en-GB')?->slug(), $pages),
        );
        self::assertSame(['Published the starter pages in British English: Welcome to Demo, About us, Contact us, Privacy notice, Terms of use, Accessibility statement.'], $event->lines());
        self::assertSame($pages[0], $fixture->pages->addresses()->home());
        self::assertSame('home', $pages[0]->layout());
        foreach ($pages as $page) {
            self::assertSame(PageState::Published, $page->state());
            self::assertSame(7, $page->authorId());
            self::assertNotNull($page->translation('en-GB')?->description());
        }
        $contact = $pages[2]->translation('en-GB')?->blocks() ?? [];
        self::assertSame(['type' => 'contact_form', 'version' => 1, 'data' => ['recipient' => 'grace@example.org', 'subject' => 'Message from the website of Demo']], $contact[1]);
        self::assertStringContainsString('This statement applies to https://demo.example. It was prepared on 14 November 2026.', self::html($pages[5], 'en-GB'));
        self::assertStringContainsString('<a href="/contact">Contact us</a>: send us a message.', self::html($pages[0], 'en-GB'));
        self::assertStringNotContainsString('Visitor statistics', self::html($pages[3], 'en-GB'), 'analytics is off');
    }

    public function testThePrivacyNoticeExplainsTheVisitorStatisticsWhileAnalyticsIsOn(): void
    {
        foreach (['en-GB' => 'Visitor statistics', 'pt-PT' => 'Estatísticas de visitas', 'es' => 'Estadísticas de visitas'] as $locale => $heading) {
            foreach ([OwnerType::Organization, OwnerType::Person] as $owner) {
                $fixture = new ContactFixture([$locale]);
                new StarterPages()($this->event($fixture, $owner, analytics: true));

                $privacy = self::html($fixture->pages->repository()->children(null)[3], $locale);
                self::assertStringContainsString('<h2>' . $heading . '</h2>', $privacy, $locale);
                self::assertStringContainsString('Global Privacy Control', $privacy);
            }
        }
    }

    public function testAPersonGetsPagesInTheFirstPersonThatAreWrittenForThem(): void
    {
        $fixture = new ContactFixture(['en-GB']);
        $event = $this->event($fixture, OwnerType::Person);

        new StarterPages()($event);

        $pages = $fixture->pages->repository()->children(null);
        $about = $pages[1]->translation('en-GB');
        self::assertNotNull($about);
        self::assertSame('About me', $about->title());
        self::assertSame('Who Grace Hopper is and what they do.', $about->description());
        self::assertStringContainsString('run by Grace Hopper', self::html($pages[4], 'en-GB'));
        self::assertNull($pages[0]->authorId());
    }

    public function testEveryLanguageOfANewSiteGetsTheStarterPagesPublished(): void
    {
        $fixture = new ContactFixture(['en-GB', 'pt-PT', 'es', 'cy']);
        $event = $this->event($fixture, OwnerType::Organization);

        new StarterPages()($event);

        self::assertSame([
            'Published the starter pages in British English, European Portuguese, Spanish, Welsh: Welcome to Demo, About us, Contact us, Privacy notice, Terms of use, Accessibility statement.',
            'Warning: there are no starter pages in Welsh yet, so they are in British English. Translate them.',
        ], $event->lines());
        $pages = $fixture->pages->repository()->children(null);
        foreach (['pt-PT' => ['inicio', 'sobre', 'contacto', 'privacidade', 'termos', 'acessibilidade'], 'es' => ['inicio', 'sobre', 'contacto', 'privacidad', 'condiciones', 'accesibilidad'], 'cy' => ['home', 'about', 'contact', 'privacy', 'terms', 'accessibility']] as $locale => $slugs) {
            self::assertSame($slugs, array_map(static fn (Page $page): string => (string) $page->translation($locale)?->slug(), $pages), $locale);
            foreach ($pages as $page) {
                self::assertTrue($page->translation($locale)?->isPublished(), $locale);
            }
        }
        self::assertSame(['Sobre nós', 'Quem é Demo e o que fazemos.'], [$pages[1]->translation('pt-PT')?->title(), $pages[1]->translation('pt-PT')?->description()]);
        self::assertStringContainsString('<a href="/pt-pt/sobre">Sobre nós</a>', self::html($pages[0], 'pt-PT'));
        self::assertStringContainsString('<a href="/es/privacidad">aviso de privacidad</a>', self::html($pages[2], 'es'));
        self::assertStringContainsString('el 14 de noviembre de 2026', self::html($pages[3], 'es'));
        self::assertSame('Mensaje del sitio web de Demo', $pages[2]->translation('es')?->blocks()[1]['data']['subject'] ?? null);
        self::assertStringContainsString('<a href="/cy/contact">Contact us</a>', self::html($pages[0], 'cy'), 'the English text, at the language’s own addresses');
    }

    public function testALanguageAddedLaterGetsTheStarterPagesAsDrafts(): void
    {
        $fixture = new ContactFixture(['en-GB', 'pt-PT']);
        $event = $this->event($fixture, OwnerType::Person, ['en-GB']);
        new StarterPages()($event);
        $site = $event->site;
        $starter = $this->starter($fixture, new Site($site->id, $site->slug, $site->name, $site->status, OwnerType::Person, $site->domains, $site->databaseDsn, $site->storage, 'en-GB', ['en-GB', 'pt-PT'], $site->defaultTimezone, $site->createdAt));

        self::assertTrue($starter->missingIn('pt-PT'));
        $report = $starter->writeIn('pt-PT', 3);

        self::assertSame(['Bem-vindo a Demo', 'Sobre mim', 'Contacte-me', 'Aviso de privacidade', 'Termos de utilização', 'Declaração de acessibilidade'], $report->titles);
        self::assertSame([], $report->fallbacks);
        self::assertFalse($starter->missingIn('pt-PT'));
        foreach ($fixture->pages->repository()->children(null) as $page) {
            self::assertTrue($page->translation('pt-PT')?->isDraft(), 'never published by itself');
        }
        self::assertSame([], $starter->writeIn('pt-PT', 3)->titles, 'nothing left to write');
        self::assertNull($starter->fallback('pt-PT'));
        self::assertSame('pt-PT', $starter->fallback('pt-BR'), 'the closest language');
        self::assertSame('en-GB', $starter->fallback('cy'));
    }

    public function testASiteWithoutStarterPagesHasNothingToWrite(): void
    {
        $fixture = new ContactFixture(['en-GB', 'pt-PT']);
        $fixture->pages->database->settings()->set(StarterContent::SETTING, '{"pages": {"home": 999, "nonsense": 1, "about": "x"}}');
        $starter = $this->starter($fixture, $fixture->pages->site);

        self::assertFalse($starter->missingIn('pt-PT'));
        self::assertSame([], $starter->writeIn('pt-PT', null)->titles);
        $fixture->pages->database->settings()->set(StarterContent::SETTING, 'not json');
        self::assertFalse($starter->missingIn('pt-PT'));
    }

    private static function html(Page $page, string $locale): string
    {
        $data = $page->translation($locale)?->blocks()[0]['data'] ?? [];

        return is_string($data['html'] ?? null) ? $data['html'] : '';
    }

    private function starter(ContactFixture $fixture, Site $site, bool $analytics = false): StarterContent
    {
        return new StarterContent(
            $fixture->pages->editor(),
            $fixture->pages->repository(),
            $fixture->pages->addresses(),
            $fixture->pages->database->settings(),
            $fixture->auth->translations,
            $fixture->auth->twig,
            $fixture->pages->clock,
            $site,
            $analytics ? PageAdminFixture::modules('analytics') : PageAdminFixture::modules(),
        );
    }

    /**
     * @param list<string>|null $locales the languages the site is made with (default: the fixture's)
     */
    private function event(ContactFixture $fixture, OwnerType $owner, ?array $locales = null, bool $analytics = false): SiteProvisioning
    {
        $site = $fixture->pages->site;
        $locales ??= $site->locales;
        $site = new Site($site->id, $site->slug, $site->name, $site->status, $owner, $site->domains, $site->databaseDsn, $site->storage, $locales[0], $locales, $site->defaultTimezone, $site->createdAt);

        return new SiteProvisioning($site, new ArrayContainer([StarterContent::class => $this->starter($fixture, $site, $analytics)]), new FirstAdmin('grace@example.org', 'Grace', 'Hopper'), 'https://demo.example/');
    }
}
