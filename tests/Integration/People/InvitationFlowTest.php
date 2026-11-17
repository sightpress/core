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
use SightPress\Core\Tests\Support\AuthFixture;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\User\Avatar\AvatarService;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;
use Symfony\Component\Mime\Email;

/**
 * Invitations through the whole kernel (C-3.4, C-3.7): an admin invites an editor, the email has a six-digit code
 * and no link, the person types it with their email address on "Accept an invitation", sets up their account with
 * the role and an avatar, and the code then stops working. Editors may only invite contributors, whatever they send.
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
        $html = (string) $email->getHtmlBody();
        foreach ([$text, $html] as $body) {
            self::assertStringNotContainsString('http', $body, 'no address in the email');
            self::assertStringNotContainsString('<a ', $body, 'no link in the email');
            self::assertStringNotContainsString('example.org/', $body);
        }
        self::assertStringContainsString('Hello Zoë,', $text);
        $code = AuthFixture::codeIn($email);
        self::assertStringNotContainsString($code, $email->getSubject());

        $guest = new Browser($this->site->kernel, 'http://example.org');
        self::assertSame(404, $guest->request('GET', '/invite/abc')->getStatusCode(), 'the link-based page is gone');
        self::assertSame('/login/invitation', $guest->request('GET', '/login/invitation/account')->getHeaderLine('Location'));
        self::assertStringContainsString('Accept an invitation', (string) $guest->request('GET', '/login')->getBody());
        $wrong = $guest->submit('/login/invitation', ['email' => 'zoe@example.org', 'code' => $code === '000000' ? '111111' : '000000']);
        self::assertSame(422, $wrong->getStatusCode());
        $right = $guest->submit('/login/invitation', ['email' => 'ZOE@example.org', 'code' => substr($code, 0, 3) . '-' . substr($code, 3)]);
        self::assertSame('/login/invitation/account', $right->getHeaderLine('Location'));
        $page = $guest->request('GET', '/login/invitation/account');
        self::assertSame(200, $page->getStatusCode());
        self::assertSame('noindex, nofollow', $page->getHeaderLine('X-Robots-Tag'));
        self::assertStringContainsString('value="Zoë"', (string) $page->getBody());

        $weak = $guest->submit('/login/invitation/account', $this->acceptance('short'));
        self::assertSame(422, $weak->getStatusCode());

        $done = $guest->submit('/login/invitation/account', $this->acceptance(self::PASSWORD));
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

        $again = new Browser($this->site->kernel, 'http://example.org');
        self::assertSame(422, $again->submit('/login/invitation', ['email' => 'zoe@example.org', 'code' => $code])->getStatusCode(), 'a code works once');
        $signIn = $guest->submit('/login', ['email' => 'zoe@example.org', 'password' => self::PASSWORD]);
        self::assertSame('/admin', $signIn->getHeaderLine('Location'));
    }

    public function testInvitingAgainReplacesTheOlderCode(): void
    {
        $admin = $this->signedIn('ada@example.org');
        foreach ([1, 2] as $ignored) {
            $admin->submit('/admin/users/invite', ['email' => 'zoe@example.org', 'role' => 'contributor', 'first_name' => '', 'last_name' => '']);
        }

        self::assertCount(2, $this->sent);
        $guest = new Browser($this->site->kernel, 'http://example.org');
        $older = AuthFixture::codeIn($this->email(0));
        $newer = AuthFixture::codeIn($this->email(1));
        if ($older !== $newer) {
            self::assertSame(422, $guest->submit('/login/invitation', ['email' => 'zoe@example.org', 'code' => $older])->getStatusCode());
        }
        self::assertSame('/login/invitation/account', $guest->submit('/login/invitation', ['email' => 'zoe@example.org', 'code' => $newer])->getHeaderLine('Location'));
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
