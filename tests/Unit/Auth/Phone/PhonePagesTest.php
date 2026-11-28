<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Auth\Phone;

use SightPress\Core\Infrastructure\Http\OutboundHttp;
use DateTimeImmutable;
use DI\Container;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Audit\AuditFilter;
use SightPress\Core\Audit\AuditLog;
use SightPress\Core\Auth\Account\PhoneChange;
use SightPress\Core\Auth\Http\ChangePhoneCodeHandler;
use SightPress\Core\Auth\Http\ChangePhoneHandler;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Auth\PhonePrompt;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Sms\TextMessages;
use SightPress\Core\Sms\TextRepliesToAccounts;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\User\Contract\AccountPhone;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\Http\AccountPages;
use SightPress\Core\User\UserRepository;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Adding a mobile number through the whole kernel (N-3): the prompt after signing in, the number with the provider's
 * lookup, the code by text, the text message choices, removing it, and STOP replies.
 */
#[CoversClass(ChangePhoneHandler::class)]
#[CoversClass(ChangePhoneCodeHandler::class)]
#[CoversClass(PhoneChange::class)]
#[CoversClass(PhonePrompt::class)]
#[CoversClass(TextMessages::class)]
#[CoversClass(TextRepliesToAccounts::class)]
#[CoversClass(AccountPhone::class)]
#[CoversClass(UserRepository::class)]
#[CoversClass(User::class)]
#[CoversClass(AccountPages::class)]
#[UsesNamespace('SightPress\Core')]
final class PhonePagesTest extends TestCase
{
    private const string PASSWORD = 'Correct horse battery staple 7!';

    private const string ACCOUNT = 'AC' . '0123456789abcdef0123456789abcdef';

    private KernelSite $site;

    /** @var list<string> texts sent */
    private array $texts = [];

    /** @var list<string> line types the lookups answer, in turn (default mobile) */
    private array $lookups = [];

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    /**
     * @param array<string, mixed> $sms
     */
    private function boot(array $sms = [], bool $on = true): void
    {
        $this->site = new KernelSite('phone_pages', tenant: ['sms' => $sms + ['provider' => 'twilio', 'account' => self::ACCOUNT, 'sender' => 'SightPress']], variables: ['SMS_API_KEY' => 'canary5f2b9c1d7e4a']);
        $tenant = $this->site->kernel->container();
        self::assertInstanceOf(Container::class, $tenant);
        $tenant->set(OutboundHttp::INNER, new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if (str_contains($url, 'lookups.twilio.com')) {
                $type = array_shift($this->lookups) ?? 'mobile';

                return $type === 'timeout'
                    ? throw new \Symfony\Component\HttpClient\Exception\TimeoutException('Idle timeout.')
                    : new MockResponse((string) json_encode(['valid' => true, 'country_code' => 'GB', 'line_type_intelligence' => ['type' => $type]]));
            }
            parse_str(is_string($options['body'] ?? null) ? $options['body'] : '', $form);
            $this->texts[] = is_string($form['Body'] ?? null) ? $form['Body'] : '';

            return new MockResponse('{"sid":"SM' . count($this->texts) . '"}', ['http_code' => 201]);
        }));
        if ($on) {
            Services::get($tenant, SiteModules::class)->turnOn($this->site->site(), 'sms', null);
            Services::get($tenant, SiteContainerFactory::class)->evict('demo');
        }
    }

    public function testSigningInWithoutANumberAsksForOneAndItCanBeSkipped(): void
    {
        $this->boot();
        $browser = $this->signedIn('ada@example.org', '/account');
        $page = (string) $browser->request('GET', '/account/phone')->getBody();
        self::assertStringContainsString('<h1>Add your mobile number</h1>', $page);
        self::assertStringContainsString('Skip for now', $page);
        self::assertStringContainsString('autocomplete="tel-national"', $page);
        self::assertStringContainsString('<option value="GB" selected>United Kingdom (+44)</option>', $page);

        $skipped = $browser->submit('/account/phone', ['op' => 'skip']);
        self::assertSame([303, '/account'], [$skipped->getStatusCode(), $skipped->getHeaderLine('Location')]);
        self::assertStringContainsString('You haven’t added a mobile number.', (string) $browser->request('GET', '/account')->getBody());
        self::assertSame('<h1>Add a mobile number</h1>', self::heading((string) $browser->request('GET', '/account/phone')->getBody()));
    }

    public function testANumberIsCheckedThenConfirmedWithATextedCode(): void
    {
        $this->boot();
        $browser = $this->signedIn('ada@example.org', '/');
        $sent = $browser->submit('/account/phone', ['op' => 'number', 'country' => 'GB', 'phone' => '07400 123456']);
        self::assertSame([303, '/account/phone/code'], [$sent->getStatusCode(), $sent->getHeaderLine('Location')]);
        self::assertCount(1, $this->texts);
        self::assertMatchesRegularExpression('/^\d{3} \d{3} is your code for Demo\. It works for 10 minutes\. Never share it\.$/', $this->texts[0]);
        self::assertStringNotContainsString('http', $this->texts[0]);
        $page = (string) $browser->request('GET', '/account/phone/code')->getBody();
        self::assertStringContainsString('•••• •••• 3456', $page);
        self::assertStringContainsString('autocomplete="one-time-code"', $page);

        self::assertSame(422, $browser->submit('/account/phone/code', ['code' => '000000'])->getStatusCode());
        $resent = $browser->submit('/account/phone/code', ['resend' => '1']);
        self::assertSame(429, $resent->getStatusCode(), 'once a minute');
        $code = str_replace(' ', '', substr($this->texts[0], 0, 7));
        $confirmed = $browser->submit('/account/phone/code', ['code' => $code]);
        self::assertSame([303, '/'], [$confirmed->getStatusCode(), $confirmed->getHeaderLine('Location')], 'on to where the person was going');

        $phone = Services::get($this->site->container(), UserRepository::class)->phoneOf($this->userId('ada@example.org'));
        self::assertNotNull($phone);
        self::assertSame(['+447400123456', true, false], [$phone->e164, $phone->verifiedAt !== null, $phone->wantsTexts]);
        $account = (string) $browser->request('GET', '/account')->getBody();
        self::assertStringContainsString('Mobile number: 07400 123456', $account);
        self::assertStringContainsString('You get no text messages.', $account);
        self::assertSame('<h1>Your mobile number</h1>', self::heading((string) $browser->request('GET', '/account/phone')->getBody()));
        self::assertSame(303, $browser->request('GET', '/account/phone/code')->getStatusCode(), 'nothing waits for a code');
        self::assertSame(422, $browser->submit('/account/phone', ['op' => 'number', 'country' => 'GB', 'phone' => '+44 7400 123456'])->getStatusCode(), 'already theirs');

        $audit = Services::get($this->site->container(), AuditLog::class)->find(new AuditFilter(), 10);
        self::assertStringContainsString('Changed the phone of', $audit[0]->summary);
        self::assertStringNotContainsString('7400123456', serialize($audit));

        $this->signedIn('ada@example.org', '/');
        self::assertCount(1, $this->texts, 'no prompt once there is a number');
    }

    public function testRefusesWhatCannotGetTexts(): void
    {
        $this->boot();
        $browser = $this->signedIn('ada@example.org', '/');
        foreach ([
            ['', 'Enter your mobile number'],
            ['call me', 'Enter a mobile number, like 07700 900123'],
            ['020 7946 0000', 'This isn’t a mobile number'],
            ['+351 912 345 678', 'Text messages can’t be sent to numbers in this country from this site.'],
        ] as [$input, $error]) {
            $refused = $browser->submit('/account/phone', ['op' => 'number', 'country' => 'XX', 'phone' => $input]);
            self::assertSame(422, $refused->getStatusCode(), $input);
            self::assertStringContainsString($error, (string) $refused->getBody(), $input);
        }
        $this->lookups = ['landline', 'timeout'];
        self::assertStringContainsString('This isn’t a mobile number, so it can’t get text messages.', (string) $browser->submit('/account/phone', ['op' => 'number', 'country' => 'GB', 'phone' => '07400 123456'])->getBody());
        self::assertStringContainsString('The text message provider didn’t answer in time. Try again.', (string) $browser->submit('/account/phone', ['op' => 'number', 'country' => 'GB', 'phone' => '07400 123456'])->getBody());
        self::assertSame([], $this->texts);
    }

    public function testAnotherAccountsNumberGetsTheSameAnswerAndNoText(): void
    {
        $this->boot();
        Services::get($this->site->container(), UserRepository::class)->confirmPhone($this->user('bea@example.org'), '+447400123456', null);
        $browser = $this->signedIn('ada@example.org', '/');
        $sent = $browser->submit('/account/phone', ['op' => 'number', 'country' => 'GB', 'phone' => '07400 123456']);
        self::assertSame([303, '/account/phone/code'], [$sent->getStatusCode(), $sent->getHeaderLine('Location')]);
        self::assertSame([], $this->texts, 'nothing is sent to a number another account has');
        self::assertSame(422, $browser->submit('/account/phone/code', ['code' => '123456'])->getStatusCode());
        self::assertNull(Services::get($this->site->container(), UserRepository::class)->phoneOf($this->userId('ada@example.org'))?->e164);
    }

    public function testTextChoicesRemovingTheNumberAndStopReplies(): void
    {
        $this->boot();
        $phones = Services::get($this->site->container(), UserRepository::class);
        $browser = $this->signedIn('ada@example.org', '/');
        $id = $this->userId('ada@example.org');
        self::assertSame(422, $browser->submit('/account/phone', ['op' => 'texts', 'texts' => '1'])->getStatusCode(), 'a number first');

        $phones->confirmPhone($id, '+447400123456', 'mobile GB');
        self::assertSame(303, $browser->submit('/account/phone', ['op' => 'texts', 'texts' => '1', 'notifications' => ['review_request', 'made_up']])->getStatusCode());
        $phone = $phones->phoneOf($id);
        self::assertNotNull($phone);
        self::assertSame([true, ['review_request'], true], [$phone->wantsTexts, $phone->notifications, $phone->wants('review_request')]);
        self::assertStringContainsString('You get the text messages you chose.', (string) $browser->request('GET', '/account')->getBody());

        new TextRepliesToAccounts($phones)(new \SightPress\Core\Sms\Contract\TextMessageOptChanged(new \SightPress\Module\Sms\PhoneNumber('+447400123456'), '+44•••••••456', 'hash', true));
        self::assertFalse($phones->phoneOf($id)?->wantsTexts, 'STOP');
        self::assertNull($phones->textReply('+447400000000', true));
        $phones->textReply('+447400123456', false);
        self::assertTrue($phones->phoneOf($id)?->wantsTexts, 'START');

        self::assertSame(303, $browser->submit('/account/phone', ['op' => 'texts'])->getStatusCode());
        $phone = $phones->phoneOf($id);
        self::assertNotNull($phone);
        self::assertSame([false, [], false], [$phone->wantsTexts, $phone->notifications, $phone->optedOutAt === null]);

        self::assertSame(303, $browser->submit('/account/phone', ['op' => 'remove'])->getStatusCode());
        self::assertFalse($phones->phoneOf($id)?->hasNumber());
        self::assertFalse($phones->removePhone($id));
        self::assertNull($phones->phoneOf(999));
        $phones->chooseTexts(999, true, []);
        self::assertSame(303, $browser->submit('/account/phone', ['op' => 'skip'])->getStatusCode());
    }

    public function testTheOrganisationCanRequireANumber(): void
    {
        $this->boot(['require_for_existing' => true]);
        $browser = $this->signedIn('ada@example.org', '/account');
        self::assertStringNotContainsString('Skip for now', (string) $browser->request('GET', '/account/phone')->getBody());
        self::assertSame(422, $browser->submit('/account/phone', ['op' => 'skip'])->getStatusCode(), 'no skipping');
        self::assertSame('<h1>Add your mobile number</h1>', self::heading((string) $browser->request('GET', '/account/phone')->getBody()), 'still asked');
    }

    public function testNothingChangesWhileTextMessagesAreOff(): void
    {
        $this->boot(on: false);
        $browser = $this->signedIn('ada@example.org', '/account');
        self::assertSame(404, $browser->request('GET', '/account/phone')->getStatusCode());
        self::assertSame(404, $browser->request('GET', '/account/phone/code')->getStatusCode());
        self::assertStringNotContainsString('Mobile number', (string) $browser->request('GET', '/account')->getBody());
        self::assertSame(303, new Browser($this->site->kernel, 'http://example.org')->request('GET', '/account/phone')->getStatusCode(), 'visitors sign in first');
        self::assertSame(303, new Browser($this->site->kernel, 'http://example.org')->request('GET', '/account/phone/code')->getStatusCode());
        $texts = Services::get($this->site->container(), TextMessages::class);
        self::assertSame([false, [], 'GB'], [$texts->isOn(), $texts->countries(), $texts->defaultCountry()]);
        self::assertSame([44, 0], [$texts->callingCode('gb'), $texts->callingCode('XX')]);
        self::assertSame('+447400123456', $texts->parse('07400 123456')?->e164);
        $this->expectException(\SightPress\Core\Sms\Contract\SmsRefused::class);
        $texts->sendCode(new \SightPress\Module\Sms\PhoneNumber('+447400123456'), '123 456', null, null);
    }

    private function signedIn(string $email, string $return): Browser
    {
        $this->user($email);
        $browser = new Browser($this->site->kernel, 'http://example.org');
        $response = $browser->submit('/login?return=' . rawurlencode($return), ['email' => $email, 'password' => self::PASSWORD]);
        self::assertSame(303, $response->getStatusCode());

        return $browser;
    }

    private function user(string $email): int
    {
        $users = Services::get($this->site->container(), UserRepository::class);
        $user = $users->findByEmail($email) ?? new User($email, 'Ada', 'Lovelace', 'Europe/London', new DateTimeImmutable());
        $user->setPasswordHash(new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(self::PASSWORD), new DateTimeImmutable());
        $user->activate(new DateTimeImmutable());
        $users->save($user);

        return (int) $user->id();
    }

    private function userId(string $email): int
    {
        return (int) Services::get($this->site->container(), UserRepository::class)->findByEmail($email)?->id();
    }

    private static function heading(string $page): string
    {
        return preg_match('#<h1>.*?</h1>#', $page, $match) === 1 ? $match[0] : '';
    }
}
