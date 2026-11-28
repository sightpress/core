<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Auth\Phone;

use SightPress\Core\Infrastructure\Http\OutboundHttp;
use DateTimeImmutable;
use DI\Container;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use SightPress\Core\Auth\Account\PhoneChange;
use SightPress\Core\Auth\Http\ChangePhoneCodeHandler;
use SightPress\Core\Auth\Http\ChangePhoneHandler;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\FixedClock;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The limits around phone codes (N-3): once a minute, codes per number and address, wrong codes, and a number taken
 * by another account while the code was on its way.
 */
#[CoversClass(ChangePhoneHandler::class)]
#[CoversClass(ChangePhoneCodeHandler::class)]
#[CoversClass(PhoneChange::class)]
#[CoversClass(UserRepository::class)]
#[UsesNamespace('SightPress\Core')]
final class PhoneLimitsTest extends TestCase
{
    private const string PASSWORD = 'Correct horse battery staple 7!';

    private KernelSite $site;

    private FixedClock $clock;

    /** @var list<string> */
    private array $texts = [];

    protected function setUp(): void
    {
        $this->site = new KernelSite('phone_limits', tenant: ['sms' => ['provider' => 'twilio', 'account' => 'AC' . '0123456789abcdef0123456789abcdef', 'sender' => 'SightPress', 'limits' => ['per_number' => 2, 'per_client' => 50]]], variables: ['SMS_API_KEY' => 'canary5f2b9c1d7e4a']);
        $tenant = $this->site->kernel->container();
        self::assertInstanceOf(Container::class, $tenant);
        $this->clock = new FixedClock(new DateTimeImmutable());
        $tenant->set(ClockInterface::class, $this->clock);
        $tenant->set(OutboundHttp::INNER, new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if (str_contains($url, 'lookups.twilio.com')) {
                return new MockResponse('{"valid":true,"country_code":"GB","line_type_intelligence":{"type":"mobile"}}');
            }
            parse_str(is_string($options['body'] ?? null) ? $options['body'] : '', $form);
            $this->texts[] = is_string($form['Body'] ?? null) ? $form['Body'] : '';

            return new MockResponse('{"sid":"SM' . count($this->texts) . '"}', ['http_code' => 201]);
        }));
        Services::get($tenant, SiteModules::class)->turnOn($this->site->site(), 'sms', null);
        Services::get($tenant, SiteContainerFactory::class)->evict('demo');
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testCodesAreLimitedPerMinuteAndPerNumber(): void
    {
        $browser = $this->signedIn();
        $number = ['op' => 'number', 'country' => 'GB', 'phone' => '07400 123456'];
        self::assertSame(303, $browser->submit('/account/phone', $number)->getStatusCode());
        $again = $browser->submit('/account/phone', $number);
        self::assertSame(429, $again->getStatusCode());
        self::assertStringContainsString('then ask again. The code we sent before still works.', (string) $again->getBody());

        $this->clock->advance(61);
        $resent = $browser->submit('/account/phone/code', ['resend' => '1']);
        self::assertSame([303, '/account/phone/code'], [$resent->getStatusCode(), $resent->getHeaderLine('Location')]);
        self::assertStringContainsString('We have sent a new code to •••• •••• 3456.', (string) $browser->request('GET', '/account/phone/code')->getBody());
        self::assertSame(429, $browser->submit('/account/phone/code', ['resend' => '1'])->getStatusCode(), 'once a minute');

        $this->clock->advance(61);
        $limited = $browser->submit('/account/phone/code', ['resend' => '1']);
        self::assertSame(422, $limited->getStatusCode());
        self::assertStringContainsString('Too many text messages were asked for.', (string) $limited->getBody());
        $this->clock->advance(61);
        self::assertStringContainsString('Too many text messages were asked for.', (string) $browser->submit('/account/phone', $number)->getBody());
        self::assertCount(2, $this->texts);
    }

    public function testTooManyCodesForOneNumberAndTooManyWrongCodes(): void
    {
        $browser = $this->signedIn();
        for ($i = 0; $i < 5; ++$i) {
            $browser->submit('/account/phone', ['op' => 'number', 'country' => 'GB', 'phone' => '07400 12345' . $i]);
            $this->clock->advance(61);
        }
        for ($i = 0; $i < 5; ++$i) {
            $browser->submit('/account/phone', ['op' => 'number', 'country' => 'GB', 'phone' => '07400 123450']);
            $this->clock->advance(61);
        }
        $limited = $browser->submit('/account/phone', ['op' => 'number', 'country' => 'GB', 'phone' => '07400 123450']);
        self::assertSame(429, $limited->getStatusCode());
        self::assertStringContainsString('You have asked for a code too many times.', (string) $limited->getBody());
        self::assertContains($browser->submit('/account/phone/code', ['resend' => '1'])->getStatusCode(), [422, 429], 'no new code');

        for ($i = 0; $i < 10; ++$i) {
            $browser->submit('/account/phone/code', ['code' => '000000']);
        }
        $wrong = $browser->submit('/account/phone/code', ['code' => '000000']);
        self::assertSame(429, $wrong->getStatusCode());
        self::assertStringContainsString('You have tried a code too many times.', (string) $wrong->getBody());
        self::assertSame(422, $browser->submit('/account/phone/code', ['code' => 'abc'])->getStatusCode());
    }

    public function testANumberTakenWhileTheCodeWasOnItsWayIsNotGiven(): void
    {
        $browser = $this->signedIn();
        $browser->submit('/account/phone', ['op' => 'number', 'country' => 'GB', 'phone' => '07400 123456']);
        $users = Services::get($this->site->container(), UserRepository::class);
        $bea = new User('bea@example.org', 'Bea', 'Okafor', 'UTC', new DateTimeImmutable());
        $users->save($bea);
        $users->confirmPhone((int) $bea->id(), '+447400123456', null);

        $code = str_replace(' ', '', substr($this->texts[0], 0, 7));
        self::assertSame(422, $browser->submit('/account/phone/code', ['code' => $code])->getStatusCode());
        $this->expectException(\SightPress\Core\User\Contract\AccountException::class);
        $users->confirmPhone(999, '+447400123456', null);
    }

    private function signedIn(): Browser
    {
        $users = Services::get($this->site->container(), UserRepository::class);
        $user = new User('ada@example.org', 'Ada', 'Lovelace', 'Europe/London', new DateTimeImmutable());
        $user->setPasswordHash(new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(self::PASSWORD), new DateTimeImmutable());
        $user->activate(new DateTimeImmutable());
        $users->save($user);
        $browser = new Browser($this->site->kernel, 'http://example.org');
        self::assertSame(303, $browser->submit('/login', ['email' => 'ada@example.org', 'password' => self::PASSWORD])->getStatusCode());

        return $browser;
    }
}
