<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Auth;

use DateTimeImmutable;
use DI\Container;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Audit\AuditFilter;
use SightPress\Core\Audit\AuditLog;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Mail\MailSender;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Tests\Support\AuthFixture;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\User\Avatar\AvatarService;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Mime\Email;

/**
 * Self-registration through the whole kernel (U-1): closed until an admin opens it on the sign-up screen; then a
 * visitor signs up, gets a six-digit code by email (no link), can't sign in until they type it, and becomes a
 * `member` with an avatar. An address that has an account gets the same answer, its owner a notice. A `role` sent
 * with the form is ignored, an unsafe sign-up role stored straight in the database gives `member` and is audited, and
 * `schedule:run` keeps an account that is less than a week old.
 */
#[CoversNothing]
final class SelfRegistrationFlowTest extends TestCase
{
    private const string PASSWORD = 'Correct horse battery staple 7!';

    private KernelSite $site;

    /** @var \ArrayObject<int, Email> */
    private \ArrayObject $sent;

    protected function setUp(): void
    {
        $this->site = new KernelSite('sign-up');
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
        $this->user('ada@example.org', 'admin');
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testAnAdminOpensSignUpAndAVisitorBecomesAMemberWithTheEmailedCode(): void
    {
        $guest = new Browser($this->site->kernel, 'http://example.org');
        self::assertSame(404, $guest->request('GET', '/register')->getStatusCode(), 'closed by default');
        self::assertStringNotContainsString('Create an account', (string) $guest->request('GET', '/login')->getBody());

        $admin = $this->signedIn('ada@example.org', self::PASSWORD);
        $screen = (string) $admin->request('GET', '/admin/settings/sign-up')->getBody();
        self::assertStringContainsString('<option value="member" selected>Member</option>', $screen);
        self::assertStringNotContainsString('<option value="editor"', $screen);
        self::assertSame(422, $admin->submit('/admin/settings/sign-up', ['open' => '1', 'role' => 'editor'])->getStatusCode(), 'the server refuses an unsafe role');
        self::assertSame(303, $admin->submit('/admin/settings/sign-up', ['open' => '1', 'role' => 'member'])->getStatusCode());

        self::assertStringContainsString('<a href="/register">Create an account</a>', (string) $guest->request('GET', '/login')->getBody());
        $form = $guest->request('GET', '/register');
        self::assertSame(200, $form->getStatusCode());
        self::assertSame('noindex, nofollow', $form->getHeaderLine('X-Robots-Tag'));
        $signedUp = $guest->submit('/register', $this->details() + ['role' => 'admin']);
        self::assertSame(303, $signedUp->getStatusCode());
        self::assertSame('/register/code', $signedUp->getHeaderLine('Location'));

        self::assertCount(1, $this->sent);
        $email = $this->email(0);
        self::assertSame('Your code to confirm your email address for ' . $this->site->site()->name, $email->getSubject());
        foreach ([(string) $email->getTextBody(), (string) $email->getHtmlBody()] as $body) {
            self::assertDoesNotMatchRegularExpression('#https?://|<a\b|www\.|example\.org/|<img\b#i', $body);
        }
        $code = AuthFixture::codeIn($email);

        $early = new Browser($this->site->kernel, 'http://example.org')->submit('/login', ['email' => 'nia@example.org', 'password' => self::PASSWORD]);
        self::assertSame('/register/code', $early->getHeaderLine('Location'), 'can’t sign in before the code');

        self::assertSame(422, $guest->submit('/register/code', ['code' => $code === '000000' ? '111111' : '000000'])->getStatusCode());
        $confirmed = $guest->submit('/register/code', ['code' => substr($code, 0, 3) . ' ' . substr($code, 3)]);
        self::assertSame(303, $confirmed->getStatusCode());
        self::assertSame('/login', $confirmed->getHeaderLine('Location'));

        $signIn = $guest->submit('/login', ['email' => 'nia@example.org', 'password' => self::PASSWORD]);
        self::assertSame('/', $signIn->getHeaderLine('Location'), 'a member has no admin panel');
        self::assertSame(403, $guest->request('GET', '/admin')->getStatusCode());

        $nia = Services::get($this->site->container(), UserRepository::class)->findByEmail('nia@example.org');
        self::assertNotNull($nia);
        self::assertSame(['Nia', 'Lind', 'Asia/Tokyo'], [$nia->firstName(), $nia->lastName(), $nia->timezone()]);
        self::assertNotNull(AvatarService::url($nia));
        $roles = Services::get($this->site->container(), RoleRepository::class);
        self::assertSame(['member'], array_map(static fn ($role) => $role->name(), $roles->rolesOf((int) $nia->id())));
        $actions = array_map(static fn ($entry): string => $entry->action, Services::get($this->site->container(), AuditLog::class)->find(new AuditFilter(), 20));
        self::assertContains('user.registered', $actions);
        self::assertContains('settings.changed', $actions);
    }

    public function testAnAddressWithAnAccountGetsTheSameAnswerAndItsOwnerANotice(): void
    {
        Services::get($this->site->container(), SiteSettings::class)->set('people.registration', '1');
        $guest = new Browser($this->site->kernel, 'http://example.org');

        $response = $guest->submit('/register', ['email' => 'ADA@example.org'] + $this->details());

        self::assertSame('/register/code', $response->getHeaderLine('Location'));
        self::assertCount(1, $this->sent);
        self::assertSame('ada@example.org', $this->email(0)->getTo()[0]->getAddress());
        self::assertStringContainsString('You already have an account there', (string) $this->email(0)->getTextBody());
        $ada = new Browser($this->site->kernel, 'http://example.org')->submit('/login', ['email' => 'ada@example.org', 'password' => self::PASSWORD]);
        self::assertSame('/admin', $ada->getHeaderLine('Location'), 'the owner keeps their password');
    }

    public function testAnUnsafeSignUpRoleStoredInTheDatabaseGivesMemberAndIsAudited(): void
    {
        $settings = Services::get($this->site->container(), SiteSettings::class);
        $settings->set('people.registration', '1');
        $settings->set('rbac.registration_role', 'editor');
        $guest = new Browser($this->site->kernel, 'http://example.org');
        $guest->submit('/register', $this->details());

        $guest->submit('/register/code', ['code' => AuthFixture::codeIn($this->email(0))]);

        $nia = Services::get($this->site->container(), UserRepository::class)->findByEmail('nia@example.org');
        self::assertTrue($nia?->isActive());
        $roles = Services::get($this->site->container(), RoleRepository::class);
        self::assertSame(['member'], array_map(static fn ($role) => $role->name(), $roles->rolesOf((int) $nia?->id())));
        $entries = Services::get($this->site->container(), AuditLog::class)->find(new AuditFilter(), 20);
        $registered = array_values(array_filter($entries, static fn ($entry): bool => $entry->action === 'user.registered'));
        self::assertSame(['role' => 'member', 'refused_role' => 'editor'], $registered[0]->details);
    }

    public function testTheScheduleKeepsAnAccountThatIsLessThanAWeekOld(): void
    {
        Services::get($this->site->container(), SiteSettings::class)->set('people.registration', '1');
        new Browser($this->site->kernel, 'http://example.org')->submit('/register', $this->details());

        $application = $this->site->kernel->console();
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        self::assertSame(0, $tester->run(['command' => 'schedule:run'], ['interactive' => false]), $tester->getDisplay());

        self::assertTrue(Services::get($this->site->container(), UserRepository::class)->findByEmail('nia@example.org')?->awaitsEmailVerification());
    }

    /**
     * @return array<string, string>
     */
    private function details(): array
    {
        return [
            'first_name' => 'Nia',
            'last_name' => 'Lind',
            'display_name' => '',
            'email' => 'nia@example.org',
            'timezone' => 'Asia/Tokyo',
            'password' => self::PASSWORD,
            'website' => '',
        ];
    }

    private function email(int $index): Email
    {
        return $this->sent[$index] ?? self::fail(sprintf('Email %d was not sent.', $index + 1));
    }

    private function signedIn(string $email, string $password): Browser
    {
        $browser = new Browser($this->site->kernel, 'http://example.org');
        $browser->submit('/login', ['email' => $email, 'password' => $password]);

        return $browser;
    }

    private function user(string $email, string $role): void
    {
        $container = $this->site->container();
        $user = new User($email, 'Ada', 'Lovelace', 'Europe/London', new DateTimeImmutable());
        $user->setPasswordHash(new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(self::PASSWORD), new DateTimeImmutable());
        $users = Services::get($container, UserRepository::class);
        $users->save($user);
        $roles = Services::get($container, RoleRepository::class);
        $roles->assign((int) $user->id(), $roles->findRole($role) ?? throw new \LogicException($role));
    }
}
