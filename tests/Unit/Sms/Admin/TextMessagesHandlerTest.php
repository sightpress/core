<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Sms\Admin;

use SightPress\Core\Infrastructure\Http\OutboundHttp;
use DateTimeImmutable;
use DI\Container;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Audit\AuditFilter;
use SightPress\Core\Audit\AuditLog;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Sms\Admin\TextMessagesHandler;
use SightPress\Core\Sms\SmsSender;
use SightPress\Core\Sms\SmsSettings;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;
use SightPress\Module\Sms\PhoneNumber;
use SightPress\Module\Sms\SmsMessage;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[CoversClass(TextMessagesHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class TextMessagesHandlerTest extends TestCase
{
    private const string PASSWORD = 'Correct horse battery staple 7!';

    private KernelSite $site;

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    /**
     * @param array<string, mixed> $sms
     */
    private function boot(array $sms = []): void
    {
        $this->site = new KernelSite('text_messages_screen', tenant: ['sms' => $sms + ['provider' => 'twilio', 'account' => 'AC' . '0123456789abcdef0123456789abcdef', 'sender' => 'SightPress']], variables: ['SMS_API_KEY' => 'canary5f2b9c1d7e4a']);
        $tenant = $this->site->kernel->container();
        self::assertInstanceOf(Container::class, $tenant);
        $tenant->set(OutboundHttp::INNER, new MockHttpClient(static fn (string $method, string $url, array $options): MockResponse => str_contains(is_string($options['body'] ?? null) ? $options['body'] : '', '447400123459')
            ? new MockResponse('{"code":21408}', ['http_code' => 400])
            : new MockResponse('{"sid":"SM1"}', ['http_code' => 201])));
        Services::get($tenant, SiteModules::class)->turnOn($this->site->site(), 'sms', null);
        Services::get($tenant, SiteContainerFactory::class)->evict('demo');
    }

    public function testShowsTheStatusTheBudgetAndTheLogWithNumbersMasked(): void
    {
        $this->boot(['monthly_budget' => 10, 'reserved_for_codes' => 2]);
        Services::get($this->site->container(), SmsSender::class)->send(new SmsMessage(new PhoneNumber('+447400123456'), 'Hello'));
        $browser = $this->signedIn('ada@example.org', 'admin');
        $screen = $browser->request('GET', '/admin/sms');
        self::assertSame(200, $screen->getStatusCode());
        $page = (string) $screen->getBody();

        self::assertStringContainsString('<h1>Text messages</h1>', $page);
        self::assertStringContainsString('Ready: messages are sent with Twilio. Your organisation set up this provider.', $page);
        self::assertStringContainsString('Used this month: 1 of 10 message parts (10%).', $page);
        self::assertStringContainsString('<td>+44•••••••456</td>', $page);
        self::assertStringNotContainsString('7400123456', $page);
        self::assertStringNotContainsString('Hello', $page, 'never the text');
        self::assertStringContainsString('href="/admin/sms"', $page, 'in the admin menu');

        for ($i = 0; $i < 7; ++$i) {
            Services::get($this->site->container(), SmsSender::class)->send(new SmsMessage(new PhoneNumber('+447400123456'), 'Hello'));
        }
        self::assertStringContainsString('Most of the budget is used.', (string) $browser->request('GET', '/admin/sms')->getBody());
        Services::get($this->site->container(), SmsSender::class)->send(new SmsMessage(new PhoneNumber('+447400123456'), 'Code 123456', \SightPress\Module\Sms\SmsPurpose::SecurityCode));
        Services::get($this->site->container(), SmsSender::class)->send(new SmsMessage(new PhoneNumber('+447400123456'), 'Code 123457', \SightPress\Module\Sms\SmsPurpose::SecurityCode));
        $usedUp = (string) $browser->request('GET', '/admin/sms')->getBody();
        self::assertStringContainsString('The budget is used up.', $usedUp);
        self::assertStringContainsString('Security code', $usedUp);
    }

    public function testSendsATestMessageAndExplainsARefusal(): void
    {
        $this->boot(['monthly_budget' => 0]);
        $browser = $this->signedIn('ada@example.org', 'admin');
        self::assertStringContainsString('No text messages have been sent yet.', (string) $browser->request('GET', '/admin/sms')->getBody());
        self::assertStringContainsString('There is no limit.', (string) $browser->request('GET', '/admin/sms')->getBody());

        $sent = $browser->submit('/admin/sms', ['to' => '07400 123456']);
        self::assertSame([303, '/admin/sms'], [$sent->getStatusCode(), $sent->getHeaderLine('Location')]);
        self::assertStringContainsString('The test message is on its way to +44•••••••456.', (string) $browser->request('GET', '/admin/sms')->getBody());

        $refused = $browser->submit('/admin/sms', ['to' => '+447400123459']);
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('Text messages can’t be sent to numbers in this country from this site.', (string) $refused->getBody());
        $invalid = $browser->submit('/admin/sms', ['to' => 'nope']);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertStringContainsString('Enter a mobile number', (string) $invalid->getBody());

        $audit = array_map(static fn ($entry): string => $entry->summary, Services::get($this->site->container(), AuditLog::class)->find(new AuditFilter(), 10));
        self::assertContains('Sent a test text message to +44•••••••456 with twilio.', $audit);
        self::assertContains('Tried to send a test text message to +44•••••••459 with twilio: unsupported country.', $audit);
    }

    public function testOnlyForPeopleWhoManageTextMessages(): void
    {
        $this->boot();
        self::assertSame(403, $this->signedIn('bea@example.org', 'editor')->request('GET', '/admin/sms')->getStatusCode());
        Services::get($this->site->container(), SmsSettings::class)->connect('acme', null, null, null, null, null);
        self::assertStringContainsString('Not ready yet.', (string) $this->signedIn('ada@example.org', 'admin')->request('GET', '/admin/sms')->getBody());
    }

    private function signedIn(string $email, string $role): Browser
    {
        $users = Services::get($this->site->container(), UserRepository::class);
        $user = new User($email, 'Ada', 'Lovelace', 'Europe/London', new DateTimeImmutable());
        $user->setPasswordHash(new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(self::PASSWORD), new DateTimeImmutable());
        $user->activate(new DateTimeImmutable());
        $users->save($user);
        $roles = Services::get($this->site->container(), RoleRepository::class);
        $roles->assign((int) $user->id(), $roles->findRole($role) ?? throw new \LogicException($role));
        $browser = new Browser($this->site->kernel, 'http://example.org');
        $signedIn = $browser->submit('/login', ['email' => $email, 'password' => self::PASSWORD]);
        self::assertSame(303, $signedIn->getStatusCode());
        if ($signedIn->getHeaderLine('Location') === '/account/phone') {
            $browser->submit('/account/phone', ['op' => 'skip']);
        }

        return $browser;
    }
}
