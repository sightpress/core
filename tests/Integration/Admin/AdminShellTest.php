<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Page\Contract\PageTransition;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\Workflow\PageWorkflow;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;

/**
 * The admin shell and dashboard through the whole kernel: who gets in, what they see, and the sign-out button.
 */
#[CoversNothing]
final class AdminShellTest extends TestCase
{
    private const string PASSWORD = 'Correct horse battery staple 7!';

    private KernelSite $site;

    protected function setUp(): void
    {
        $this->site = new KernelSite('admin-shell');
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testAnonymousVisitorsSignInFirstAndMembersAreTurnedAway(): void
    {
        $this->user('max@example.org', 'member');
        $browser = new Browser($this->site->kernel, 'http://example.org');

        $response = $browser->request('GET', '/admin');
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/login?return=%2Fadmin', $response->getHeaderLine('Location'));

        $this->signIn($browser, 'max@example.org');
        $response = $browser->request('GET', '/admin');
        self::assertSame(403, $response->getStatusCode());
        self::assertStringNotContainsString('aria-label="Admin"', (string) $response->getBody());
    }

    public function testContributorsAndEditorsSeeTheirOwnDashboard(): void
    {
        $cleo = $this->user('cleo@example.org', 'contributor');
        $this->user('ada@example.org', 'editor');
        $container = $this->site->container();
        $editor = Services::get($container, PageEditor::class);
        $editor->create('en-GB', new PageContent('Opening hours'), authorId: $cleo->id());
        $summer = $editor->create('en-GB', new PageContent('Summer reading'), authorId: $cleo->id());
        $roles = Services::get($container, RoleRepository::class);
        Services::get($container, PageWorkflow::class)->apply(
            $summer,
            PageTransition::Submit,
            new Actor($cleo->id(), $roles->permissionNamesOf((int) $cleo->id())),
        );

        $contributor = new Browser($this->site->kernel, 'http://example.org');
        $this->signIn($contributor, 'cleo@example.org');
        $html = (string) $contributor->request('GET', '/admin')->getBody();
        self::assertStringContainsString('<title>Dashboard - Admin - Demo</title>', $html);
        self::assertStringContainsString('<a href="/admin" aria-current="page">Dashboard</a>', $html);
        self::assertMatchesRegularExpression('#<a href="/admin/pages/\d+" lang="en-GB">Opening hours</a>#', $html);
        self::assertStringNotContainsString('Waiting for review', $html);
        self::assertStringContainsString('Cleo saved “Summer reading”', $html);

        $editorBrowser = new Browser($this->site->kernel, 'http://example.org');
        $this->signIn($editorBrowser, 'ada@example.org');
        $response = $editorBrowser->request('GET', '/admin');
        $html = (string) $response->getBody();
        self::assertSame('noindex, nofollow', $response->getHeaderLine('X-Robots-Tag'));
        self::assertMatchesRegularExpression('#Waiting for review</h2>\s*<ul class="admin-list">\s*<li>\s*<a href="/admin/pages/\d+" lang="en-GB">Summer reading</a>#', $html);

        $response = $editorBrowser->submit('/admin', []);
        self::assertSame(405, $response->getStatusCode());
        preg_match_all('/<input type="hidden" name="(csrf_[a-z]+)" value="([^"]+)">/', $html, $hidden, PREG_SET_ORDER);
        $fields = [];
        foreach ($hidden as [, $name, $value]) {
            $fields[$name] = html_entity_decode($value);
        }
        $response = $editorBrowser->request('POST', '/logout', $fields);
        self::assertSame('/login', $response->getHeaderLine('Location'));
        self::assertSame(303, $editorBrowser->request('GET', '/admin')->getStatusCode());
    }

    private function user(string $email, string $role): User
    {
        $container = $this->site->container();
        $user = new User($email, ucfirst(explode('@', $email)[0]), 'Example', 'Europe/London', new DateTimeImmutable());
        $user->setPasswordHash(new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(self::PASSWORD), new DateTimeImmutable());
        Services::get($container, UserRepository::class)->save($user);
        $roles = Services::get($container, RoleRepository::class);
        $roles->assign((int) $user->id(), $roles->findRole($role) ?? throw new \LogicException($role));

        return $user;
    }

    private function signIn(Browser $browser, string $email): void
    {
        $response = $browser->submit('/login', ['email' => $email, 'password' => self::PASSWORD]);
        self::assertSame(303, $response->getStatusCode());
    }
}
