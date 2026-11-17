<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\User;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\FixedClock;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\User\Avatar\AvatarService;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;

/**
 * *My account* and avatars through the whole kernel: a member changes their details, their avatar follows the new
 * name, and dates in the admin are shown in each person's own time zone.
 */
#[CoversNothing]
final class AccountFlowTest extends TestCase
{
    private const string PASSWORD = 'Correct horse battery staple 7!';

    private KernelSite $site;

    protected function setUp(): void
    {
        $this->site = new KernelSite('account');
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testAMemberChangesTheirDetailsAndTheirAvatarFollows(): void
    {
        $max = $this->user('max@example.org', 'Max', 'Müller', 'member', 'Europe/Berlin');
        $browser = new Browser($this->site->kernel, 'http://example.org');

        self::assertSame('/login?return=%2Faccount', $browser->request('GET', '/account')->getHeaderLine('Location'));
        $browser->submit('/login', ['email' => 'max@example.org', 'password' => self::PASSWORD]);

        $page = $browser->request('GET', '/account');
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('<h1>My account</h1>', (string) $page->getBody());
        $old = (string) AvatarService::url($max);
        $avatar = $browser->request('GET', $old);
        self::assertSame('image/svg+xml', $avatar->getHeaderLine('Content-Type'));
        self::assertSame("default-src 'none'; style-src 'unsafe-inline'", $avatar->getHeaderLine('Content-Security-Policy'));
        self::assertStringContainsString('>MM</text>', (string) $avatar->getBody());

        $response = $browser->submit('/account', [
            'first_name' => 'Zoë',
            'last_name' => 'Ødegaard',
            'display_name' => '',
            'timezone' => 'America/Sao_Paulo',
        ]);
        self::assertSame('/account', $response->getHeaderLine('Location'));
        $html = (string) $browser->request('GET', '/account')->getBody();
        self::assertMatchesRegularExpression('#role="status">\s*<p>Your details have been saved.</p>#', $html);
        self::assertSame(1, preg_match('#src="(/avatars/[0-9a-f]{32}\.svg)"#', $html, $match));
        $new = $match[1];
        self::assertNotSame($old, $new);
        self::assertStringContainsString('>ZØ</text>', (string) $browser->request('GET', $new)->getBody());
        self::assertSame(404, $browser->request('GET', $old)->getStatusCode(), 'the old avatar is deleted');

        $saved = Services::get($this->site->container(), UserRepository::class)->findByEmail('max@example.org');
        self::assertSame('America/Sao_Paulo', $saved?->timezone());
    }

    public function testDatesInTheAdminAreShownInEachPersonsTimeZone(): void
    {
        $tokyo = $this->user('ada@example.org', 'Ada', 'Lovelace', 'editor', 'Asia/Tokyo');
        $this->user('bea@example.org', 'Bea', 'Okafor', 'editor', 'America/Sao_Paulo');
        $container = $this->site->container();
        $editor = new PageEditor(
            Services::get($container, \SightPress\Core\Page\PageRepository::class),
            $this->site->site(),
            new FixedClock(new DateTimeImmutable('2026-11-16 23:30:00 UTC')),
            Services::get($container, \Psr\EventDispatcher\EventDispatcherInterface::class),
            Services::get($container, \SightPress\Core\Block\Contract\BlockValidator::class),
        );
        $editor->create('en-GB', new PageContent('Opening hours'), authorId: $tokyo->id());

        $times = [];
        foreach (['ada@example.org', 'bea@example.org'] as $email) {
            $browser = new Browser($this->site->kernel, 'http://example.org');
            $browser->submit('/login', ['email' => $email, 'password' => self::PASSWORD]);
            preg_match('#<time datetime="2026-11-16T23:30:00Z">([^<]+)</time>#', (string) $browser->request('GET', '/admin')->getBody(), $time);
            $times[$email] = $time[1] ?? '';
        }

        self::assertStringContainsString('17 Nov 2026, 08:30', $times['ada@example.org']);
        self::assertStringContainsString('16 Nov 2026, 20:30', $times['bea@example.org']);
    }

    private function user(string $email, string $first, string $last, string $role, string $timezone): User
    {
        $container = $this->site->container();
        $user = new User($email, $first, $last, $timezone, new DateTimeImmutable());
        $user->setPasswordHash(new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(self::PASSWORD), new DateTimeImmutable());
        $users = Services::get($container, UserRepository::class);
        $users->save($user);
        Services::get($container, AvatarService::class)->generate($user);
        $users->save($user);
        $roles = Services::get($container, RoleRepository::class);
        $roles->assign((int) $user->id(), $roles->findRole($role) ?? throw new \LogicException($role));

        return $user;
    }
}
