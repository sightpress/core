<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Provisioning;

use DI\Container;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\PageAddresses;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Page\Provisioning\StarterPages;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Site\Contract\FirstAdmin;
use SightPress\Core\Site\Contract\SiteProvisioning;
use SightPress\Core\Tenant\OwnerType;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tests\Support\ContactFixture;
use Twig\Environment;

#[CoversClass(StarterPages::class)]
#[UsesNamespace('SightPress\Core')]
final class StarterPagesTest extends TestCase
{
    public function testAnOrganizationGetsSixPublishedPagesWithHomeAsTheHomePage(): void
    {
        $fixture = new ContactFixture();
        $event = $this->event($fixture, OwnerType::Organization);
        $event->setAdminId(7);

        new StarterPages()($event);

        $pages = $fixture->pages->repository()->children(null);
        self::assertSame(
            ['home', 'about', 'contact', 'privacy', 'terms', 'accessibility'],
            array_map(static fn (Page $page): string => (string) $page->translation('en-GB')?->slug(), $pages),
        );
        self::assertSame(['Published the starter pages: Welcome to Demo, About us, Contact us, Privacy notice, Terms of use, Accessibility statement.'], $event->lines());
        self::assertSame($pages[0], $fixture->pages->addresses()->home());
        self::assertSame('home', $pages[0]->layout());
        foreach ($pages as $page) {
            self::assertSame(PageState::Published, $page->state());
            self::assertSame(7, $page->authorId());
            self::assertNotNull($page->translation('en-GB')?->description());
        }
        $contact = $pages[2]->translation('en-GB')?->blocks() ?? [];
        self::assertSame(['type' => 'contact_form', 'version' => 1, 'data' => ['recipient' => 'grace@example.org', 'subject' => 'Message from the website of Demo']], $contact[1]);
        self::assertStringContainsString('This statement applies to https://demo.example. It was prepared on 14 November 2026.', $this->html($pages[5]));
        self::assertStringContainsString('<a href="/contact">Contact us</a>: send us a message.', $this->html($pages[0]));
    }

    public function testAPersonGetsPagesInTheFirstPersonThatAreWrittenForThem(): void
    {
        $fixture = new ContactFixture();
        $event = $this->event($fixture, OwnerType::Person);

        new StarterPages()($event);

        $pages = $fixture->pages->repository()->children(null);
        $about = $pages[1]->translation('en-GB');
        self::assertNotNull($about);
        self::assertSame('About me', $about->title());
        self::assertSame('Who Grace Hopper is and what they do.', $about->description());
        self::assertStringContainsString('run by Grace Hopper', $this->html($pages[4]));
        self::assertNull($pages[0]->authorId());
    }

    private function html(Page $page): string
    {
        $data = $page->translation('en-GB')?->blocks()[0]['data'] ?? [];

        return is_string($data['html'] ?? null) ? $data['html'] : '';
    }

    private function event(ContactFixture $fixture, OwnerType $owner): SiteProvisioning
    {
        $site = $fixture->pages->site;
        $site = new Site($site->id, $site->slug, $site->name, $site->status, $owner, $site->domains, $site->databaseDsn, $site->storage, $site->defaultLocale, $site->locales, $site->defaultTimezone, $site->createdAt);

        return new SiteProvisioning($site, new Container([
            PageEditor::class => $fixture->pages->editor(),
            PageRepository::class => $fixture->pages->repository(),
            PageAddresses::class => $fixture->pages->addresses(),
            Environment::class => $fixture->auth->twig,
            ClockInterface::class => $fixture->pages->clock,
        ]), new FirstAdmin('grace@example.org', 'Grace', 'Hopper'), 'https://demo.example/');
    }
}
