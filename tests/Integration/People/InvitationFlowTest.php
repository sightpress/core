<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\People;

use DateTimeImmutable;
use DI\Container;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Mail\MailSender;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\User\Avatar\AvatarService;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;
use Symfony\Component\Mime\Email;

/**
 * Invitations through the whole kernel (C-3.4): an admin invites an editor, the email has the link, the person sets
 * up their account with the role and an avatar, and the link then stops working. Editors may only invite
 * contributors, whatever they send.
 */
#[CoversNothing]
final class InvitationFlowTest extends TestCase
{
    private const string PASSWORD = 'correct horse battery staple';

    private KernelSite $site;

    /** @var \ArrayObject<int, Email> */
    private \ArrayObject $sent;

    protected function setUp(): void
    {
        $this->site = new KernelSite('invitations');
        $kernel = $this->site->kernel->container();
        self::assertInstanceOf(Container::class, $kernel);
        $this->sent = new \ArrayObject();
        $kernel->set(MailSender::class, new class ($this->sent) implements MailSender {
            /** @param \ArrayObject<int, Email> $sent */
            public function __construct(private readonly \ArrayObject $sent)
            {
            }

            public function send(Email $email): void
            {
                $this->sent->append($email);
            }
        });
        $this->user('ada@example.org', 'Ada', 'Lovelace', 'admin');
        $this->user('bea@example.org', 'Bea', 'Okafor', 'editor');
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testAnAdminInvitesAnEditorWhoSetsUpTheirAccountOnce(): void
    {
        $admin = $this->signedIn('ada@example.org');
        $form = (string) $admin->request('GET', '/admin/users/invite')->getBody();
        foreach (['admin', 'editor', 'contributor', 'member'] as $role) {
            self::assertStringContainsString(sprintf('value="%s"', $role), $form);
        }

        $response = $admin->submit('/admin/users/invite', [
            'email' => ' Zoe@Example.org ',
            'role' => 'editor',
            'first_name' => 'Zoë',
            'last_name' => '',
        ]);
        self::assertSame(303, $response->getStatusCode());
        self::assertStringContainsString(
            'We have sent an invitation to zoe@example.org to join as editor.',
            (string) $admin->request('GET', '/admin/users/invite')->getBody(),
        );

        self::assertCount(1, $this->sent);
        $email = $this->email(0);
        self::assertSame('zoe@example.org', $email->getTo()[0]->getAddress());
        self::assertSame('Ada invited you to ' . $this->site->site()->name, $email->getSubject());
        $text = (string) $email->getTextBody();
        self::assertSame(1, preg_match('#http://example\.org/invite/([A-Za-z0-9_-]{43})#', $text, $link));
        self::assertStringContainsString($link[0], (string) $email->getHtmlBody());
        self::assertStringContainsString('Hello Zoë,', $text);

        $guest = new Browser($this->site->kernel, 'http://example.org');
        $page = $guest->request('GET', '/invite/' . $link[1]);
        self::assertSame(200, $page->getStatusCode());
        self::assertSame('noindex, nofollow', $page->getHeaderLine('X-Robots-Tag'));
        self::assertStringContainsString('value="Zoë"', (string) $page->getBody());

        $weak = $guest->submit('/invite/' . $link[1], $this->acceptance('short'));
        self::assertSame(422, $weak->getStatusCode());

        $done = $guest->submit('/invite/' . $link[1], $this->acceptance(self::PASSWORD));
        self::assertSame(303, $done->getStatusCode());
        self::assertSame('/login', $done->getHeaderLine('Location'));

        $zoe = Services::get($this->site->container(), UserRepository::class)->findByEmail('zoe@example.org');
        self::assertNotNull($zoe);
        self::assertSame('Zoë', $zoe->firstName());
        self::assertSame('Ødegaard', $zoe->lastName());
        self::assertSame('Asia/Tokyo', $zoe->timezone());
        self::assertNotNull(AvatarService::url($zoe));
        $roles = Services::get($this->site->container(), RoleRepository::class);
        self::assertSame(['editor'], array_map(static fn ($role) => $role->name(), $roles->rolesOf((int) $zoe->id())));

        self::assertSame(410, $guest->request('GET', '/invite/' . $link[1])->getStatusCode(), 'a link works once');
        $signIn = $guest->submit('/login', ['email' => 'zoe@example.org', 'password' => self::PASSWORD]);
        self::assertSame('/admin', $signIn->getHeaderLine('Location'));
    }

    public function testInvitingAgainReplacesTheOlderLink(): void
    {
        $admin = $this->signedIn('ada@example.org');
        foreach ([1, 2] as $ignored) {
            $admin->submit('/admin/users/invite', ['email' => 'zoe@example.org', 'role' => 'contributor', 'first_name' => '', 'last_name' => '']);
        }

        self::assertCount(2, $this->sent);
        $guest = new Browser($this->site->kernel, 'http://example.org');
        self::assertSame(410, $guest->request('GET', $this->path($this->email(0)))->getStatusCode());
        self::assertSame(200, $guest->request('GET', $this->path($this->email(1)))->getStatusCode());
    }

    public function testEditorsInviteOnlyContributorsWhateverTheySend(): void
    {
        $editor = $this->signedIn('bea@example.org');
        $form = (string) $editor->request('GET', '/admin/users/invite')->getBody();
        self::assertStringContainsString('value="contributor"', $form);
        self::assertStringNotContainsString('value="admin"', $form);
        self::assertStringNotContainsString('value="editor"', $form);

        $refused = $editor->submit('/admin/users/invite', ['email' => 'zoe@example.org', 'role' => 'admin', 'first_name' => '', 'last_name' => '']);
        self::assertSame(422, $refused->getStatusCode());
        self::assertCount(0, $this->sent);

        $editor->submit('/admin/users/invite', ['email' => 'zoe@example.org', 'role' => 'contributor', 'first_name' => '', 'last_name' => '']);
        self::assertCount(1, $this->sent);
    }

    public function testAnExistingAccountCantBeInvited(): void
    {
        $admin = $this->signedIn('ada@example.org');

        $response = $admin->submit('/admin/users/invite', ['email' => 'BEA@example.org', 'role' => 'editor', 'first_name' => '', 'last_name' => '']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('There is already an account for bea@example.org.', (string) $response->getBody());
        self::assertCount(0, $this->sent);
    }

    /**
     * @return array<string, string>
     */
    private function acceptance(string $password): array
    {
        return [
            'first_name' => 'Zoë',
            'last_name' => 'Ødegaard',
            'display_name' => '',
            'timezone' => 'Asia/Tokyo',
            'password' => $password,
        ];
    }

    private function email(int $index): Email
    {
        return $this->sent[$index] ?? self::fail(sprintf('Email %d was not sent.', $index + 1));
    }

    private function path(Email $email): string
    {
        preg_match('#/invite/[A-Za-z0-9_-]{43}#', (string) $email->getTextBody(), $path);

        return $path[0] ?? self::fail('The email has no invitation link.');
    }

    private function signedIn(string $email): Browser
    {
        $browser = new Browser($this->site->kernel, 'http://example.org');
        $browser->submit('/login', ['email' => $email, 'password' => self::PASSWORD]);

        return $browser;
    }

    private function user(string $email, string $first, string $last, string $role): void
    {
        $container = $this->site->container();
        $user = new User($email, $first, $last, 'Europe/London', new DateTimeImmutable());
        $user->setPasswordHash(new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(self::PASSWORD), new DateTimeImmutable());
        $users = Services::get($container, UserRepository::class);
        $users->save($user);
        Services::get($container, AvatarService::class)->generate($user);
        $users->save($user);
        $roles = Services::get($container, RoleRepository::class);
        $roles->assign((int) $user->id(), $roles->findRole($role) ?? throw new \LogicException($role));
    }
}
