<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\User;

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
use SightPress\Core\Tests\Support\AuthFixture;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;
use Symfony\Component\Mime\Email;

/**
 * *My account* through the whole kernel (U-3): a new password signs out the other browsers, a new email address
 * only after the emailed code (and the old address is told, without a link), admin preferences change the admin's
 * CSS classes, and every change is in the audit log without its values.
 */
#[CoversNothing]
final class ProfileFlowTest extends TestCase
{
    private const string PASSWORD = 'Correct horse battery staple 7!';

    private const string NEW_PASSWORD = 'A brand new password 9?';

    private KernelSite $site;

    /** @var \ArrayObject<int, Email> */
    private \ArrayObject $sent;

    protected function setUp(): void
    {
        $this->site = new KernelSite('profile');
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
        $this->user('ada@example.org', 'editor');
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testANewPasswordSignsOutTheOtherBrowsersButNotThisOne(): void
    {
        $here = $this->signedIn('ada@example.org', self::PASSWORD);
        $there = $this->signedIn('ada@example.org', self::PASSWORD);
        self::assertSame(200, $there->request('GET', '/account')->getStatusCode());

        self::assertSame(422, $here->submit('/account/password', ['current_password' => 'Wrong password 1!', 'new_password' => self::NEW_PASSWORD])->getStatusCode());
        $changed = $here->submit('/account/password', ['current_password' => self::PASSWORD, 'new_password' => self::NEW_PASSWORD]);
        self::assertSame('/account', $changed->getHeaderLine('Location'));

        self::assertSame(200, $here->request('GET', '/account')->getStatusCode());
        self::assertSame('/login?return=%2Faccount', $there->request('GET', '/account')->getHeaderLine('Location'), 'the other browser is signed out');
        self::assertSame('/login', $this->signedInAt('ada@example.org', self::PASSWORD), 'the old password no longer works');
        self::assertSame('/admin', $this->signedInAt('ada@example.org', self::NEW_PASSWORD));
        self::assertSame(['Changed the password of Ada.'], $this->audited());
    }

    public function testANewEmailAddressOnlyAfterTheCodeAndTheOldOneIsTold(): void
    {
        $browser = $this->signedIn('ada@example.org', self::PASSWORD);

        self::assertSame('/account/email/code', $browser->submit('/account/email', ['new_email' => 'ada@new.example', 'current_password' => self::PASSWORD])->getHeaderLine('Location'));
        self::assertCount(1, $this->sent);
        self::assertSame('ada@new.example', $this->email(0)->getTo()[0]->getAddress());
        self::assertSame('/admin', $this->signedInAt('ada@example.org', self::PASSWORD), 'nothing changes before the code');

        $done = $browser->submit('/account/email/code', ['code' => AuthFixture::codeIn($this->email(0))]);
        self::assertSame('/account', $done->getHeaderLine('Location'));
        self::assertStringContainsString('Email address: ada@new.example', (string) $browser->request('GET', '/account')->getBody());
        self::assertCount(2, $this->sent);
        self::assertSame('ada@example.org', $this->email(1)->getTo()[0]->getAddress());
        foreach ([$this->email(0), $this->email(1)] as $email) {
            foreach ([(string) $email->getTextBody(), (string) $email->getHtmlBody()] as $body) {
                self::assertDoesNotMatchRegularExpression('#https?://|<a\b|www\.|example\.org/|<img\b#i', $body);
            }
        }
        self::assertSame('/admin', $this->signedInAt('ada@new.example', self::PASSWORD));
        self::assertSame('/login', $this->signedInAt('ada@example.org', self::PASSWORD));
        self::assertSame(['Changed the email of Ada.'], $this->audited());
        self::assertStringNotContainsString('ada@new.example', json_encode(Services::get($this->site->container(), AuditLog::class)->find(new AuditFilter(), 20), JSON_THROW_ON_ERROR));
    }

    public function testAdminPreferencesChangeTheAdminPanelWithoutScript(): void
    {
        $browser = $this->signedIn('ada@example.org', self::PASSWORD);
        self::assertStringContainsString('<html lang="en-GB" dir="ltr">', (string) $browser->request('GET', '/admin')->getBody());

        self::assertSame('/account', $browser->submit('/account', ['reduced_motion' => '1', 'larger_text' => '1'], '/account/preferences')->getHeaderLine('Location'));

        self::assertStringContainsString('<html lang="en-GB" dir="ltr" class="admin-reduced-motion admin-larger-text">', (string) $browser->request('GET', '/admin')->getBody());
        self::assertStringNotContainsString('admin-larger-text', (string) $browser->request('GET', '/account')->getBody(), 'the site keeps its own look');
        self::assertSame(['Changed the admin preferences of Ada.'], $this->audited());

        $this->user('max@example.org', 'member');
        $member = $this->signedIn('max@example.org', self::PASSWORD);
        self::assertStringNotContainsString('Admin preferences', (string) $member->request('GET', '/account')->getBody());
        self::assertSame(403, $member->submit('/account', ['larger_text' => '1'], '/account/preferences')->getStatusCode());
    }

    /**
     * @return list<string> the summaries of the `user.changed` audit entries, oldest first
     */
    private function audited(): array
    {
        $entries = array_filter(Services::get($this->site->container(), AuditLog::class)->find(new AuditFilter(), 20), static fn ($entry): bool => $entry->action === 'user.changed');

        return array_reverse(array_values(array_map(static fn ($entry): string => $entry->summary, $entries)));
    }

    private function email(int $index): Email
    {
        return $this->sent[$index] ?? self::fail(sprintf('Email %d was not sent.', $index + 1));
    }

    private function signedIn(string $email, string $password): Browser
    {
        $browser = new Browser($this->site->kernel, 'http://example.org');
        self::assertSame(303, $browser->submit('/login', ['email' => $email, 'password' => $password])->getStatusCode());

        return $browser;
    }

    /**
     * Where signing in from a new browser goes: `/` (or the admin) when it worked, back to `/login` when it didn't.
     */
    private function signedInAt(string $email, string $password): string
    {
        $response = new Browser($this->site->kernel, 'http://example.org')->submit('/login', ['email' => $email, 'password' => $password]);

        return $response->getStatusCode() === 303 ? $response->getHeaderLine('Location') : '/login';
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
