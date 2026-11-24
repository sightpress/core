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
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;
use Symfony\Component\Mime\Email;

/**
 * Domain registration through the whole kernel (X-7): on a members-only site whose sign-up is off, someone at the
 * organisation's domain registers from the sign-in page's link, can't sign in before typing the emailed code, then
 * gets the sign-up role (never an unsafe one); other addresses are refused by name; the site can turn it off, and a
 * public site never offers it. The audit log says the account came from the domain.
 */
#[CoversNothing]
final class DomainRegistrationFlowTest extends TestCase
{
    private const string PASSWORD = 'Correct horse battery staple 7!';

    private KernelSite $site;

    /** @var \ArrayObject<int, Email> */
    private \ArrayObject $sent;

    protected function setUp(): void
    {
        $this->site = new KernelSite('domain-registration', tenant: [
            'email_policy' => ['domains' => ['example.org', 'example.com']],
            'domain_registration' => ['domains' => ['Example.ORG']],
        ]);
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
        $user = new User('ada@example.org', 'Ada', 'Lovelace', 'Europe/London', new DateTimeImmutable());
        $user->setPasswordHash(new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(self::PASSWORD), new DateTimeImmutable());
        Services::get($this->site->container(), UserRepository::class)->save($user);
        $roles = Services::get($this->site->container(), RoleRepository::class);
        $roles->assign((int) $user->id(), $roles->findRole('admin') ?? self::fail());
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testAPublicSiteNeverOffersIt(): void
    {
        $guest = new Browser($this->site->kernel, 'http://example.org');

        self::assertStringNotContainsString('Register with your organisation email', (string) $guest->request('GET', '/login')->getBody());
        self::assertSame(404, $guest->request('GET', '/register')->getStatusCode());
    }

    public function testSomeoneAtTheDomainRegistersOnAMembersOnlySite(): void
    {
        $settings = Services::get($this->site->container(), SiteSettings::class);
        $settings->set('site.visibility', 'members');
        $settings->set('rbac.registration_role', 'editor');
        $guest = new Browser($this->site->kernel, 'http://example.org');

        self::assertStringContainsString('<a href="/register">Register with your organisation email</a>', (string) $guest->request('GET', '/login')->getBody());
        $refused = $guest->submit('/register', $this->details('nia@example.com'));
        self::assertSame(422, $refused->getStatusCode(), 'allowed by email_policy, but not listed for registration');
        self::assertStringContainsString('Only people with an email address at example.org can create an account here.', (string) $refused->getBody());
        self::assertSame(422, $guest->submit('/register', $this->details('nia@sub.example.org'))->getStatusCode());
        self::assertCount(0, $this->sent);

        self::assertSame('/register/code', $guest->submit('/register', $this->details('Nia@EXAMPLE.org'))->getHeaderLine('Location'));
        $early = new Browser($this->site->kernel, 'http://example.org')->submit('/login', ['email' => 'nia@example.org', 'password' => self::PASSWORD]);
        self::assertSame('/register/code', $early->getHeaderLine('Location'), 'inactive until the code is typed');
        self::assertSame('/login', $guest->submit('/register/code', ['code' => AuthFixture::codeIn($this->sent[0] ?? self::fail())])->getHeaderLine('Location'));

        $nia = Services::get($this->site->container(), UserRepository::class)->findByEmail('nia@example.org') ?? self::fail();
        self::assertSame(['member'], array_map(static fn ($role) => $role->name(), Services::get($this->site->container(), RoleRepository::class)->rolesOf((int) $nia->id())), 'never an unsafe role');
        $entry = Services::get($this->site->container(), AuditLog::class)->find(new AuditFilter(entityType: 'user'), 1)[0];
        self::assertSame(['user.registered', 'domain'], [$entry->action, $entry->details['source'] ?? null]);
    }

    public function testTheSiteCanTurnItOffButNotOn(): void
    {
        Services::get($this->site->container(), SiteSettings::class)->set('site.visibility', 'members');
        $admin = new Browser($this->site->kernel, 'http://example.org');
        $admin->submit('/login', ['email' => 'ada@example.org', 'password' => self::PASSWORD]);

        self::assertSame(303, $admin->submit('/admin/settings/sign-up', ['role' => 'member', 'domain_registration' => 'off'])->getStatusCode());
        $guest = new Browser($this->site->kernel, 'http://example.org');
        self::assertStringNotContainsString('Register with your organisation email', (string) $guest->request('GET', '/login')->getBody());
        self::assertSame(404, $guest->request('GET', '/register')->getStatusCode());
        self::assertSame(422, $admin->submit('/admin/settings/sign-up', ['role' => 'member', 'domain_registration' => 'on'])->getStatusCode());
    }

    /**
     * @return array<string, string>
     */
    private function details(string $email): array
    {
        return ['first_name' => 'Nia', 'last_name' => 'Lind', 'display_name' => '', 'email' => $email, 'timezone' => 'UTC', 'password' => self::PASSWORD, 'website' => ''];
    }
}
