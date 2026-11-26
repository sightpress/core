<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Auth\StepUp;

use DateTimeImmutable;
use DI\Container;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use SightPress\Core\Audit\AuditFilter;
use SightPress\Core\Audit\AuditLog;
use SightPress\Core\Auth\Command\ResetPhoneCommand;
use SightPress\Core\Auth\Contract\StepUpChecked;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Auth\PasswordReset;
use SightPress\Core\Auth\StepUp\ConfirmHandler;
use SightPress\Core\Auth\StepUp\ContinueHandler;
use SightPress\Core\Auth\StepUp\CriticalActions;
use SightPress\Core\Auth\StepUp\SecuritySettingsHandler;
use SightPress\Core\Auth\StepUp\StepUpGate;
use SightPress\Core\Auth\StepUp\StepUpSession;
use SightPress\Core\Auth\StepUp\TwoStep;
use SightPress\Core\Http\Security\StepUpMiddleware;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Mail\MailSender;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\People\Http\UserHandler;
use SightPress\Core\People\Http\UserPhoneHandler;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tests\Support\ArrayCommandInput;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\CallbackHandler;
use SightPress\Core\Tests\Support\FakeModule;
use SightPress\Core\Tests\Support\FixedClock;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\Tests\Support\RecordingMail;
use SightPress\Core\Tests\Support\RecordingOutput;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\Routing\Route;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Two-step confirmation through the whole kernel (N-6): off changes nothing; on, a critical change writes nothing
 * until a code is typed, the form waits, and the confirmation lasts 10 minutes in this session only.
 */
#[CoversClass(TwoStep::class)]
#[CoversClass(StepUpGate::class)]
#[CoversClass(StepUpSession::class)]
#[CoversClass(CriticalActions::class)]
#[CoversClass(ConfirmHandler::class)]
#[CoversClass(ContinueHandler::class)]
#[CoversClass(SecuritySettingsHandler::class)]
#[CoversClass(StepUpMiddleware::class)]
#[CoversClass(StepUpChecked::class)]
#[CoversClass(ResetPhoneCommand::class)]
#[CoversClass(UserPhoneHandler::class)]
#[CoversClass(UserHandler::class)]
#[CoversClass(PasswordReset::class)]
#[CoversClass(\SightPress\Core\Auth\PhonePrompt::class)]
#[CoversClass(\SightPress\Core\Audit\AuditRecorder::class)]
#[UsesNamespace('SightPress\Core')]
final class TwoStepFlowTest extends TestCase
{
    private const string PASSWORD = 'Correct horse battery staple 7!';

    private KernelSite $site;

    private FixedClock $clock;

    private RecordingMail $mail;

    /** @var list<array{to: string, text: string}> */
    private array $texts = [];

    private bool $providerDown = false;

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    /**
     * @param array<string, mixed> $tenant
     */
    private function boot(array $tenant = [], bool $sms = true): void
    {
        $critical = new FakeModule('acme.vault', register: static function (ModuleRegistrar $registrar): void {
            $registrar->routes()->add(new Route('acme.vault.empty', '/admin/vault', \SightPress\Core\Tests\Support\Modules\HelloHandler::class, ['GET', 'POST'], critical: true));
        });
        $this->site = new KernelSite('two_step', extraModules: [new ModuleCandidate($critical, 'acme/vault')], tenant: $tenant + ['sms' => ['provider' => 'twilio', 'account' => 'AC' . '0123456789abcdef0123456789abcdef', 'sender' => 'SightPress']], variables: ['SMS_API_KEY' => 'canary5f2b9c1d7e4a']);
        $container = $this->site->kernel->container();
        self::assertInstanceOf(Container::class, $container);
        $this->clock = new FixedClock(new DateTimeImmutable());
        $container->set(ClockInterface::class, $this->clock);
        $this->mail = new RecordingMail();
        $container->set(MailSender::class, $this->mail);
        $container->set(HttpClientInterface::class, new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if ($this->providerDown) {
                return new MockResponse('', ['http_code' => 503]);
            }
            if (str_contains($url, 'lookups.twilio.com')) {
                return new MockResponse('{"valid":true,"country_code":"GB","line_type_intelligence":{"type":"mobile"}}');
            }
            parse_str(is_string($options['body'] ?? null) ? $options['body'] : '', $form);
            $this->texts[] = ['to' => is_string($form['To'] ?? null) ? $form['To'] : '', 'text' => is_string($form['Body'] ?? null) ? $form['Body'] : ''];

            return new MockResponse('{"sid":"SM' . count($this->texts) . '"}', ['http_code' => 201]);
        }));
        if ($sms) {
            Services::get($container, SiteModules::class)->turnOn($this->site->site(), 'sms', null);
            Services::get($container, SiteContainerFactory::class)->evict('demo');
        }
    }

    public function testOffChangesNothing(): void
    {
        $this->boot();
        $this->person('max@example.org', 'member', '+447400123456');
        $browser = $this->signedIn('max@example.org');
        self::assertSame([303, '/login/forgot/code'], self::moved(new Browser($this->site->kernel, 'http://example.org')->submit('/login/forgot', ['email' => '07400 123456'])));
        self::assertCount(1, $this->texts, 'a reset by text while two-step is off');
        self::assertSame([303, '/account'], self::moved($browser->submit('/account/password', ['current_password' => self::PASSWORD, 'new_password' => 'A brand new long password 9!'])));
        self::assertSame(303, $browser->request('GET', '/confirm')->getStatusCode(), 'nothing waits');
    }

    public function testAnAdminTurnsItOnWithTheirOwnNumberAndACode(): void
    {
        $this->boot();
        $ada = $this->person('ada@example.org', 'admin');
        $browser = $this->signedIn('ada@example.org');
        $security = $browser->request('GET', '/admin/settings/security');
        self::assertSame(200, $security->getStatusCode());
        $page = (string) $security->getBody();
        self::assertStringContainsString('<h1>Security</h1>', $page);
        self::assertStringContainsString('It is off.', $page);
        self::assertStringContainsString('href="/admin/settings/security"', $page, 'in the admin menu');

        $refused = $browser->submit('/admin/settings/security', ['two_step' => 'on']);
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('Add your own mobile number first', (string) $refused->getBody());

        Services::get($this->site->container(), UserRepository::class)->confirmPhone($ada, '+447400123450', null);
        self::assertSame([303, '/confirm'], self::moved($browser->submit('/admin/settings/security', ['two_step' => 'on'])), 'a code first');
        self::assertFalse($this->twoStep()->isOn());
        $this->confirm($browser);
        $continue = (string) $browser->request('GET', '/confirm/continue')->getBody();
        self::assertStringContainsString('<form action="/admin/settings/security" method="post">', $continue);
        self::assertStringContainsString('<input type="hidden" name="two_step" value="on">', $continue);
        self::assertSame(303, $browser->submit('/admin/settings/security', ['two_step' => 'on'])->getStatusCode(), 'sent again within the window');
        self::assertTrue($this->twoStep()->isOn());
        self::assertStringContainsString('It is on.', (string) $browser->request('GET', '/admin/settings/security')->getBody());
        self::assertSame(303, $browser->submit('/admin/settings/security', ['two_step' => 'on'])->getStatusCode(), 'nothing changed, still confirmed');

        $audit = array_map(static fn ($entry): string => $entry->summary, Services::get($this->site->container(), AuditLog::class)->find(new AuditFilter(), 20));
        self::assertContains('Changed the site settings: Two-step confirmation.', $audit);
        self::assertContains('Ada confirmed it was them with a code sent to +44•••••••450.', $audit);

        $this->clock->advance(StepUpSession::WINDOW + 60);
        self::assertSame([303, '/confirm'], self::moved($browser->submit('/admin/settings/security', ['two_step' => 'off'])), 'an 11-minute-old confirmation');
        self::assertTrue($this->twoStep()->isOn());
    }

    public function testOnEveryCriticalChangeWaitsForACodeAndPasswordsAreNotKept(): void
    {
        $this->boot();
        $this->twoStep()->choose(true, null);
        $this->person('max@example.org', 'member', '+447400123456');
        $browser = $this->signedIn('max@example.org');

        self::assertSame([303, '/confirm'], self::moved($browser->submit('/account/email', ['new_email' => 'max@new.example', 'current_password' => self::PASSWORD])));
        self::assertSame([], $this->mail->subjectsTo('max@new.example'), 'nothing happened');
        $page = (string) $browser->request('GET', '/confirm')->getBody();
        self::assertStringContainsString('<h1>Confirm it’s you</h1>', $page);
        self::assertStringContainsString('•••• •••• 3456', $page);
        self::assertSame([], $this->texts, 'no code until asked for');

        $browser->submit('/confirm', ['op' => 'send']);
        self::assertCount(1, $this->texts);
        self::assertMatchesRegularExpression('/^\d{3} \d{3} is your code to confirm a change on Demo\. It works for 10 minutes\. Never share it\.$/', implode('', array_column($this->texts, 'text')));
        self::assertSame(429, $browser->submit('/confirm', ['op' => 'send'])->getStatusCode(), 'once a minute');
        self::assertSame(422, $browser->submit('/confirm', ['code' => '000000'])->getStatusCode());
        self::assertSame(422, $browser->submit('/confirm', ['code' => 'abc'])->getStatusCode());
        self::assertSame(303, new Browser($this->site->kernel, 'http://example.org')->request('GET', '/confirm')->getStatusCode(), 'visitors sign in first');
        self::assertSame([303, '/confirm/continue'], self::moved($browser->submit('/confirm', ['code' => self::code($this->text(0))])));
        $continue = (string) $browser->request('GET', '/confirm/continue')->getBody();
        self::assertStringContainsString('<input type="hidden" name="new_email" value="max@new.example">', $continue);
        self::assertStringNotContainsString(self::PASSWORD, $continue);
        self::assertStringContainsString('We don’t keep passwords or files.', $continue);
        self::assertStringContainsString('<a href="/account/email">', $continue, 'back to where the form was, or its own address');
        self::assertSame(303, $browser->request('GET', '/confirm/continue')->getStatusCode(), 'shown once');
        self::assertSame([303, '/account/email/code'], self::moved($browser->submit('/account/email', ['new_email' => 'max@new.example', 'current_password' => self::PASSWORD])), 'now it goes through');

        $audit = array_map(static fn ($entry): string => $entry->summary, Services::get($this->site->container(), AuditLog::class)->find(new AuditFilter(), 20));
        self::assertContains('Max typed a wrong confirmation code (sent to +44•••••••456).', $audit);

        $other = $this->signedIn('max@example.org');
        self::assertSame([303, '/confirm'], self::moved($other->submit('/account/password', ['current_password' => self::PASSWORD, 'new_password' => 'Another long new password 4!'])), 'another session is not confirmed');

        $page = (string) $other->request('GET', '/account/phone')->getBody();
        self::assertSame([303, '/confirm'], self::moved($other->submit('/account/phone', ['op' => 'remove'])), 'removing your number is critical');
        self::assertSame(303, $other->submit('/account/phone', ['op' => 'texts'])->getStatusCode(), 'text choices are not');
        self::assertStringContainsString('Your mobile number', $page);
    }

    public function testTooManyWrongCodesStopForAWhile(): void
    {
        $this->boot();
        $this->twoStep()->choose(true, null);
        $this->person('max@example.org', 'member', '+447400123456');
        $browser = $this->signedIn('max@example.org');
        $browser->submit('/account/password', ['current_password' => self::PASSWORD, 'new_password' => 'A brand new long password 9!'], headers: ['Referer' => 'http://example.org/account/password?from=menu']);
        $browser->submit('/confirm', ['op' => 'send']);
        for ($i = 0; $i < 10; ++$i) {
            $browser->submit('/confirm', ['code' => '111111']);
        }
        $limited = $browser->submit('/confirm', ['code' => self::code($this->text(0))]);
        self::assertSame(429, $limited->getStatusCode(), 'too many wrong codes');
        self::assertStringContainsString('You have tried a code too many times.', (string) $limited->getBody());
    }

    public function testWithoutANumberThePersonAddsOneFirst(): void
    {
        $this->boot();
        $this->twoStep()->choose(true, null);
        $this->person('bea@example.org', 'editor');
        $browser = $this->signedIn('bea@example.org');
        self::assertSame([303, '/confirm'], self::moved($browser->submit('/account/password', ['current_password' => self::PASSWORD, 'new_password' => 'A brand new long password 9!'])));
        $page = (string) $browser->request('GET', '/confirm')->getBody();
        self::assertStringContainsString('First add your mobile number.', $page);
        self::assertStringContainsString('<a href="/account/phone">', $page);
        self::assertSame([303, '/account/phone/code'], self::moved($browser->submit('/account/phone', ['op' => 'number', 'country' => 'GB', 'phone' => '07400 123457'])), 'adding a first number needs no code');
        self::assertSame([303, '/confirm'], self::moved($browser->submit('/account/phone/code', ['code' => self::code($this->text(0))])), 'and goes back to confirm');
    }

    public function testWhenNoCodeCanBeSentThePageSaysWhoCanHelp(): void
    {
        $this->boot();
        $this->twoStep()->choose(true, null);
        $this->person('max@example.org', 'member', '+447400123456');
        $browser = $this->signedIn('max@example.org');
        $browser->submit('/account/password', ['current_password' => self::PASSWORD, 'new_password' => 'A brand new long password 9!']);
        $this->providerDown = true;
        $down = $browser->submit('/confirm', ['op' => 'send']);
        self::assertSame(503, $down->getStatusCode());
        self::assertStringContainsString('We can’t send a code right now', (string) $down->getBody());
        self::assertStringContainsString('They can make this change for you from the console', (string) $down->getBody());

        $output = new RecordingOutput();
        $tenant = $this->site->kernel->container();
        $command = new ResetPhoneCommand(Services::get($tenant, SiteRepository::class), Services::get($tenant, SiteContainerFactory::class));
        self::assertSame(['site:user:phone:reset', 2], [$command->name(), count($command->parameters())]);
        self::assertNotSame('', $command->description());
        self::assertSame(0, $command->run(new ArrayCommandInput(['slug' => 'demo', 'email' => 'max@example.org']), $output));
        self::assertSame(0, $command->run(new ArrayCommandInput(['slug' => 'demo', 'email' => 'max@example.org']), $output));
        self::assertSame(['The mobile number of max@example.org is removed. They can add a new one on My account.', 'max@example.org has no mobile number on "demo".'], $output->lines);
        self::assertSame(1, $command->run(new ArrayCommandInput(['slug' => 'demo', 'email' => 'nobody@example.org']), $output));
        self::assertSame(1, $command->run(new ArrayCommandInput(['slug' => 'shop', 'email' => 'max@example.org']), $output));
        self::assertStringNotContainsString('7400123456', implode("\n", [...$output->lines, ...$output->errors]));
        self::assertStringContainsString('First add your mobile number.', (string) $browser->request('GET', '/confirm')->getBody());
    }

    public function testAnAdminRemovesSomeonesLostNumberAndTheResetIsByEmailOnly(): void
    {
        $this->boot();
        $this->twoStep()->choose(true, null);
        $this->person('ada@example.org', 'admin', '+447400123450');
        $max = $this->person('max@example.org', 'member', '+447400123456');
        self::assertSame(303, new Browser($this->site->kernel, 'http://example.org')->submit('/login/forgot', ['email' => '07400 123456'])->getStatusCode());
        self::assertSame([], $this->texts, 'no reset by text while two-step is on');

        $browser = $this->signedIn('ada@example.org');
        $page = (string) $browser->request('GET', '/admin/users/' . $max)->getBody();
        self::assertStringContainsString('<dd>+44•••••••456</dd>', $page);
        self::assertStringContainsString('Remove the mobile number of Max', $page);
        self::assertSame([303, '/confirm'], self::moved($browser->submit('/admin/users/' . $max, [], '/admin/users/' . $max . '/phone')));
        $this->confirm($browser);
        self::assertSame([303, '/admin/users/' . $max], self::moved($browser->submit('/admin/users/' . $max, [], '/admin/users/' . $max . '/phone')));
        self::assertFalse(Services::get($this->site->container(), UserRepository::class)->phoneOf($max)?->hasNumber());
        self::assertStringContainsString('Max has no mobile number.', (string) $browser->request('GET', '/admin/users/' . $max)->getBody() . $this->flash($browser, '/admin/users/' . $max, '/admin/users/' . $max . '/phone'));
        self::assertStringContainsString('Remove your own number on My account.', $this->flash($browser, '/admin/users/' . $max, '/admin/users/' . $this->userId('ada@example.org') . '/phone'));
    }

    public function testModuleRoutesAndTheSettingsThatMatter(): void
    {
        $this->boot();
        $this->twoStep()->choose(true, null);
        $this->person('ada@example.org', 'admin', '+447400123450');
        $browser = $this->signedIn('ada@example.org');
        self::assertSame([303, '/confirm'], self::moved($browser->submit('/admin/settings', [], '/admin/vault')), 'a module route marked critical');
        self::assertSame(200, $browser->request('GET', '/admin/vault')->getStatusCode(), 'reading it is not');

        $settings = (string) $browser->request('GET', '/admin/settings')->getBody();
        self::assertStringContainsString('<h1>', $settings);
        self::assertNotSame('/confirm', $browser->submit('/admin/settings', ['name' => 'Demo', 'timezone' => 'Europe/London', 'visibility' => 'public'])->getHeaderLine('Location'), 'renaming the site is not critical');
        self::assertSame([303, '/confirm'], self::moved($browser->submit('/admin/settings', ['name' => 'Demo', 'timezone' => 'Europe/London', 'visibility' => 'members'])), 'making it members-only is');
        self::assertNotSame('/confirm', $browser->submit('/admin/users/invite', ['email' => 'cleo@example.org', 'first_name' => 'Cleo', 'last_name' => 'M', 'role' => 'contributor'])->getHeaderLine('Location'));
        self::assertSame([303, '/confirm'], self::moved($browser->submit('/admin/users/invite', ['email' => 'eve@example.org', 'first_name' => 'Eve', 'last_name' => 'M', 'role' => 'editor'])));
    }

    public function testTheOrganisationCanRequireOrForbidIt(): void
    {
        $this->boot(['two_step' => 'required']);
        $this->person('ada@example.org', 'admin', '+447400123450');
        self::assertTrue($this->twoStep()->isOn());
        $browser = $this->signedIn('ada@example.org');
        $page = (string) $browser->request('GET', '/admin/settings/security')->getBody();
        self::assertStringContainsString('Your organisation requires two-step confirmation on every site.', $page);
        self::assertStringNotContainsString('name="two_step"', $page);
        $this->site->remove();

        $this->boot(['two_step' => 'off']);
        $this->twoStep()->choose(true, null);
        self::assertFalse($this->twoStep()->isOn());
        $this->person('ada@example.org', 'admin', '+447400123450');
        self::assertSame(404, $this->signedIn('ada@example.org')->request('GET', '/admin/settings/security')->getStatusCode());
        $this->site->remove();

        $this->boot(sms: false);
        $this->person('ada@example.org', 'admin', '+447400123450');
        $this->twoStep()->choose(true, null);
        self::assertFalse($this->twoStep()->isOn(), 'needs text messages');
        $browser = $this->signedIn('ada@example.org');
        self::assertStringContainsString('It needs text messages, which are off for this site.', (string) $browser->request('GET', '/admin/settings/security')->getBody());
        self::assertSame(403, $this->signedIn('bea@example.org', 'editor')->request('GET', '/admin/settings/security')->getStatusCode());
    }

    private function confirm(Browser $browser): void
    {
        $this->clock->advance(61);
        $before = count($this->texts);
        $browser->submit('/confirm', ['op' => 'send']);
        self::assertCount($before + 1, $this->texts);
        self::assertSame([303, '/confirm/continue'], self::moved($browser->submit('/confirm', ['code' => self::code($this->text($before))])));
    }

    private function twoStep(): TwoStep
    {
        return Services::get($this->site->container(), TwoStep::class);
    }

    private function flash(Browser $browser, string $page, string $action): string
    {
        $browser->submit($page, [], $action);

        return (string) $browser->request('GET', $page)->getBody();
    }

    private function text(int $index): string
    {
        return $this->texts[$index]['text'] ?? '';
    }

    private static function code(string $text): string
    {
        return str_replace(' ', '', substr($text, 0, 7));
    }

    /**
     * @return array{int, string}
     */
    private static function moved(\Psr\Http\Message\ResponseInterface $response): array
    {
        return [$response->getStatusCode(), $response->getHeaderLine('Location')];
    }

    private function signedIn(string $email, ?string $role = null): Browser
    {
        if ($role !== null) {
            $this->person($email, $role);
        }
        $browser = new Browser($this->site->kernel, 'http://example.org');
        $signedIn = $browser->submit('/login', ['email' => $email, 'password' => self::PASSWORD]);
        self::assertSame(303, $signedIn->getStatusCode());
        if ($signedIn->getHeaderLine('Location') === '/account/phone') {
            $browser->submit('/account/phone', ['op' => 'skip']);
        }

        return $browser;
    }

    private function userId(string $email): int
    {
        return (int) Services::get($this->site->container(), UserRepository::class)->findByEmail($email)?->id();
    }

    private function person(string $email, string $role, ?string $phone = null): int
    {
        $users = Services::get($this->site->container(), UserRepository::class);
        $user = $users->findByEmail($email) ?? new User($email, ucfirst(explode('@', $email)[0]), 'Lovelace', 'Europe/London', new DateTimeImmutable());
        $user->setPasswordHash(new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(self::PASSWORD), new DateTimeImmutable());
        $user->activate(new DateTimeImmutable());
        $users->save($user);
        $id = (int) $user->id();
        $roles = Services::get($this->site->container(), RoleRepository::class);
        $roles->assign($id, $roles->findRole($role) ?? throw new \LogicException($role));
        if ($phone !== null) {
            $users->confirmPhone($id, $phone, 'mobile GB');
        }

        return $id;
    }
}
