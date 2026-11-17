<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Page;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;

/**
 * The page screens through the whole kernel (C-7.2): a contributor starts a page, builds it from blocks with the
 * editor's buttons, saves it and sends it for review; an editor approves and publishes it; visitors then see it.
 */
#[CoversNothing]
final class PageEditingFlowTest extends TestCase
{
    private const string PASSWORD = 'correct horse battery staple';

    private KernelSite $site;

    protected function setUp(): void
    {
        $this->site = new KernelSite('page-editing');
        $container = $this->site->container();
        $now = new DateTimeImmutable();
        foreach (['bea@example.org' => ['Bea', 'editor'], 'cleo@example.org' => ['Cleo', 'contributor']] as $email => [$name, $role]) {
            $user = new User($email, $name, 'Tester', 'Europe/London', $now);
            $user->setPasswordHash(new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(self::PASSWORD), $now);
            Services::get($container, UserRepository::class)->save($user);
            $roles = Services::get($container, RoleRepository::class);
            $roles->assign((int) $user->id(), $roles->findRole($role) ?? throw new \LogicException($role));
        }
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testAContributorWritesAPageAndAnEditorPublishesIt(): void
    {
        $cleo = $this->signedIn('cleo@example.org');
        self::assertStringContainsString('<a href="/admin/pages">Pages</a>', (string) $cleo->request('GET', '/admin')->getBody());
        $started = $cleo->submit('/admin/pages/new', ['title' => 'Opening hours', 'parent' => 'top', 'layout' => 'default']);
        self::assertSame(303, $started->getStatusCode());
        $edit = $started->getHeaderLine('Location');
        self::assertMatchesRegularExpression('#^/admin/pages/\d+$#', $edit);

        $fields = ['title' => 'Opening hours', 'slug' => 'hours', 'layout' => 'default', 'summary' => 'When the library is open.', 'description' => 'Opening times of the library.'];
        $added = $cleo->submit($edit, $fields + ['op' => 'add:/blocks', 'add_type' => ['/blocks' => 'heading']]);
        self::assertSame(200, $added->getStatusCode());
        self::assertStringContainsString('Added a Heading block. It is block 1 of 1.', (string) $added->getBody());

        $blocks = [
            ['type' => 'heading', 'version' => '1', 'data' => ['text' => 'Every day', 'level' => '2']],
            ['type' => 'rich_text', 'version' => '1', 'data' => ['html' => '<p>From <strong>9</strong> to 5.</p><script>alert(1)</script>']],
        ];
        $saved = $cleo->submit($edit, $fields + ['op' => 'save', 'blocks' => $blocks]);
        self::assertSame(303, $saved->getStatusCode(), (string) $saved->getBody());
        self::assertStringContainsString('Saved “Opening hours”.', (string) $cleo->request('GET', $edit)->getBody());

        $submitted = $cleo->submit($edit, ['transition' => 'submit', 'comment' => 'Ready'], $edit . '/workflow');
        self::assertSame(303, $submitted->getStatusCode());
        self::assertStringContainsString('You can’t change this page now: it is waiting for review.', (string) $cleo->request('GET', $edit)->getBody());

        $bea = $this->signedIn('bea@example.org');
        self::assertStringContainsString('Waiting for review', (string) $bea->request('GET', '/admin/pages?state=in_review')->getBody());
        self::assertSame(303, $bea->submit($edit, ['transition' => 'approve'], $edit . '/workflow')->getStatusCode());
        self::assertSame(303, $bea->submit($edit, ['transition' => 'publish'], $edit . '/workflow')->getStatusCode());
        $page = Services::get($this->site->container(), PageRepository::class)->find((int) substr($edit, strlen('/admin/pages/')));
        self::assertSame(PageState::Published, $page?->state());

        $visitor = new Browser($this->site->kernel, 'https://example.org');
        // The site's only page is its home page.
        self::assertSame('https://example.org/', $visitor->request('GET', '/hours')->getHeaderLine('Location'));
        $public = (string) $visitor->request('GET', '/')->getBody();
        self::assertStringContainsString('<h2 class="block-heading">Every day</h2>', $public);
        self::assertStringContainsString('<p>From <strong>9</strong> to 5.</p>', $public);
        self::assertStringNotContainsString('<script>alert', $public);
    }

    private function signedIn(string $email): Browser
    {
        $browser = new Browser($this->site->kernel, 'https://example.org');
        $browser->submit('/login', ['email' => $email, 'password' => self::PASSWORD]);

        return $browser;
    }
}
