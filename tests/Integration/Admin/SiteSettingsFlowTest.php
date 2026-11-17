<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;

/**
 * Site settings through the whole kernel (C-7.3): an admin renames the site, describes who runs it and adds a
 * search console code; visitors then see the new name, the owner's details in the structured data and the code on
 * the home page, and llms.txt starts with the description.
 */
#[CoversNothing]
final class SiteSettingsFlowTest extends TestCase
{
    private const string PASSWORD = 'correct horse battery staple';

    private KernelSite $site;

    protected function setUp(): void
    {
        $this->site = new KernelSite('settings');
        $container = $this->site->container();
        $now = new DateTimeImmutable();
        $home = Services::get($container, PageEditor::class)->create('en-GB', new PageContent('Welcome', 'welcome'));
        $home->changeState(PageState::Published, $now);
        Services::get($container, PageRepository::class)->flush();
        $user = new User('ada@example.org', 'Ada', 'Lovelace', 'Europe/London', $now);
        $user->setPasswordHash(new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(self::PASSWORD), $now);
        Services::get($container, UserRepository::class)->save($user);
        $roles = Services::get($container, RoleRepository::class);
        $roles->assign((int) $user->id(), $roles->findRole('admin') ?? throw new \LogicException('admin'));
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testAnAdminChangesTheSettingsAndVisitorsSeeThem(): void
    {
        $ada = new Browser($this->site->kernel, 'https://example.org');
        $ada->submit('/login', ['email' => 'ada@example.org', 'password' => self::PASSWORD]);
        self::assertStringContainsString('<a href="/admin/settings">Site settings</a>', (string) $ada->request('GET', '/admin')->getBody());

        $saved = $ada->submit('/admin/settings', [
            'name' => 'Riverside Reading Room',
            'timezone' => 'Europe/Lisbon',
            'setting__site__description' => 'A small library by the river.',
            'setting__site__same_as' => 'https://example.social/@riverside',
            'setting__seo__ai_crawlers' => 'search_only',
            'setting__seo__verify_google' => 'abc123',
        ]);
        self::assertSame(303, $saved->getStatusCode(), (string) $saved->getBody());
        self::assertStringContainsString('The settings have been saved.', (string) $ada->request('GET', '/admin/settings')->getBody());

        $visitor = new Browser($this->site->kernel, 'https://example.org');
        $home = (string) $visitor->request('GET', '/')->getBody();
        self::assertStringContainsString('Riverside Reading Room', $home);
        self::assertStringContainsString('<meta name="google-site-verification" content="abc123">', $home);
        self::assertStringContainsString('"sameAs":["https://example.social/@riverside"]', $home);
        self::assertStringStartsWith("# Riverside Reading Room\n\n> A small library by the river.", (string) $visitor->request('GET', '/llms.txt')->getBody());
        self::assertStringContainsString('Disallow', (string) $visitor->request('GET', '/robots.txt')->getBody());
        self::assertSame(404, $visitor->request('GET', '/indexnow-key.txt')->getStatusCode(), 'IndexNow stays off');

        $branding = (string) $ada->request('GET', '/admin/settings/branding')->getBody();
        self::assertStringContainsString('alt="The logo now: Riverside Reading Room"', $branding, 'the generated pictures use the new name');

        // C-7.4: the change is in the audit log, on screen and in the CSV file.
        $audit = (string) $ada->request('GET', '/admin/audit')->getBody();
        self::assertStringContainsString('Changed the site settings: Site name, Time zone, About the site, Profiles on other sites, AI crawlers, Google Search Console verification code.', $audit);
        self::assertMatchesRegularExpression('#<td>Ada</td>\s*<td>Changed the site settings#', $audit);
        $csv = $ada->request('GET', '/admin/audit.csv');
        self::assertSame('text/csv; charset=utf-8', $csv->getHeaderLine('Content-Type'));
        self::assertStringContainsString(',Ada,settings.changed,settings,,', (string) $csv->getBody());
        self::assertSame(303, $visitor->request('GET', '/admin/audit')->getStatusCode(), 'visitors are sent to sign in');
    }
}
