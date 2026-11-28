<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Sms;

use SightPress\Core\Infrastructure\Http\OutboundHttp;
use DI\Container;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Sms\SmsProviders;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Sms\Contract\SmsRefused;
use SightPress\Core\Sms\SmsBudgetStatus;
use SightPress\Core\Sms\SmsConfiguration;
use SightPress\Core\Sms\SmsError;
use SightPress\Core\Sms\SmsLog;
use SightPress\Core\Sms\SmsNumberHash;
use SightPress\Core\Sms\SmsOptOuts;
use SightPress\Core\Sms\SmsReports;
use SightPress\Core\Sms\SmsSender;
use SightPress\Core\Sms\SmsSettings;
use SightPress\Core\Sms\SmsSetup;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Module\Sms\PhoneNumber;
use SightPress\Module\Sms\SmsDeliveryStatus;
use SightPress\Module\Sms\SmsFailure;
use SightPress\Module\Sms\SmsMessage;
use SightPress\Module\Sms\SmsPurpose;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[CoversClass(SmsSender::class)]
#[CoversClass(SmsSetup::class)]
#[CoversClass(SmsSettings::class)]
#[CoversClass(SmsConfiguration::class)]
#[CoversClass(SmsLog::class)]
#[CoversClass(SmsOptOuts::class)]
#[CoversClass(SmsReports::class)]
#[CoversClass(SmsNumberHash::class)]
#[CoversClass(SmsBudgetStatus::class)]
#[CoversClass(SmsError::class)]
#[CoversClass(SmsRefused::class)]
#[CoversClass(SmsProviders::class)]
#[UsesNamespace('SightPress\Core')]
final class SmsSenderTest extends TestCase
{
    public const string ACCOUNT = 'AC' . '0123456789abcdef0123456789abcdef';

    public const string KEY = 'canary5f2b9c1d7e4a';

    private const string MOBILE = '+447400123456';

    private KernelSite $site;

    /** @var list<array{method: string, url: string, body: string}> */
    private array $sent = [];

    /** @var list<MockResponse> */
    private array $answers = [];

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    /**
     * @param array<string, mixed> $sms
     */
    private function boot(array $sms = [], bool $tenantKey = true, bool $on = true): void
    {
        $this->site = new KernelSite('sms_sender', tenant: ['sms' => $sms + ['provider' => 'twilio', 'account' => self::ACCOUNT, 'sender' => 'SightPress']], variables: $tenantKey ? ['SMS_API_KEY' => self::KEY] : []);
        $tenant = $this->site->kernel->container();
        self::assertInstanceOf(Container::class, $tenant);
        $tenant->set(OutboundHttp::INNER, new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->sent[] = ['method' => $method, 'url' => $url, 'body' => is_string($options['body'] ?? null) ? $options['body'] : ''];

            return array_shift($this->answers) ?? new MockResponse('{"sid":"SM' . count($this->sent) . '"}', ['http_code' => 201]);
        }));
        if ($on) {
            Services::get($tenant, SiteModules::class)->turnOn($this->site->site(), 'sms', null);
            Services::get($tenant, SiteContainerFactory::class)->evict('demo');
        }
    }

    public function testSendsThroughTheTenantsProviderAndLogsTheNumberMasked(): void
    {
        $this->boot();
        $sender = $this->sender();
        $result = $sender->send(new SmsMessage(new PhoneNumber(self::MOBILE), 'Your code is 123456', SmsPurpose::SecurityCode), 7, '203.0.113.9');

        self::assertSame('SM1', $result->messageId);
        self::assertStringContainsString('To=%2B447400123456', $this->sent[0]['body']);
        $log = Services::get($this->site->container(), SmsLog::class)->recent(10);
        self::assertSame([['id' => 1, 'created_at' => $log[0]['created_at'], 'purpose' => 'security_code', 'to' => '+44•••••••456', 'user_id' => 7, 'status' => 'sent', 'failure' => null, 'parts' => 1]], $log);
        self::assertSame(['used' => 1, 'limit' => 1000, 'reserved' => 50], (array) $sender->budget());
        self::assertSame(['GB'], $sender->countries());
        $configuration = Services::get($this->site->container(), SmsSetup::class)->configuration();
        self::assertSame([SmsConfiguration::TENANT, true], [$configuration->source, $configuration->isReady()]);
    }

    public function testRefusesNumbersThatCannotGetTextsOrAreAbroad(): void
    {
        $this->boot();
        foreach ([
            '+449098790000' => SmsFailure::InvalidNumber,
            '+442079460000' => SmsFailure::InvalidNumber,
            '+448001111111' => SmsFailure::InvalidNumber,
            '+447700900123' => SmsFailure::InvalidNumber,
            '+351912345678' => SmsFailure::UnsupportedCountry,
        ] as $number => $failure) {
            self::assertSame($failure, $this->refusal(fn (): mixed => $this->sender()->send(new SmsMessage(new PhoneNumber($number), 'Hello')))->failure, $number);
            self::assertSame($failure, $this->refusal(fn (): mixed => $this->sender()->check(new PhoneNumber($number)))->failure, $number);
        }
        self::assertSame([], $this->sent, 'nothing reaches the provider');
    }

    public function testTheTenantsCountriesWinAndPremiumRateBurstsSendNothing(): void
    {
        $this->boot(['countries' => ['PT'], 'limits' => ['per_client' => 3]]);
        self::assertSame(['PT'], $this->sender()->countries());
        $this->sender()->send(new SmsMessage(new PhoneNumber('+351912345678'), 'Olá'));
        self::assertSame(SmsFailure::UnsupportedCountry, $this->refusal(fn (): mixed => $this->sender()->send(new SmsMessage(new PhoneNumber(self::MOBILE), 'Hello')))->failure);

        // A scripted burst of sign-ups to premium-rate numbers from one client.
        for ($i = 0; $i < 20; ++$i) {
            $this->refusal(fn (): mixed => $this->sender()->send(new SmsMessage(new PhoneNumber(sprintf('+3517%08d', $i)), 'Code 123456', SmsPurpose::SecurityCode), null, '198.51.100.7'));
        }
        self::assertCount(1, $this->sent);
    }

    public function testLimitsCodesPerNumberClientAndAccountAndEverythingPerSite(): void
    {
        $this->boot(['limits' => ['per_number' => 2, 'per_client' => 3, 'per_account' => 2, 'per_site' => 6]]);
        $code = static fn (string $number): SmsMessage => new SmsMessage(new PhoneNumber($number), 'Code 123456', SmsPurpose::SecurityCode);
        $this->sender()->send($code(self::MOBILE), null, 'a');
        $this->sender()->send($code(self::MOBILE), null, 'b');
        $refused = $this->refusal(fn (): mixed => $this->sender()->send($code(self::MOBILE), null, 'c'));
        self::assertSame(SmsFailure::RateLimited, $refused->failure, 'per number');
        self::assertMatchesRegularExpression('/^Too many text messages were asked for\. Try again in \d+ minutes?\.$/', $refused->getMessage());

        $this->sender()->send($code('+447400123457'), 3, 'd');
        $this->sender()->send($code('+447400123458'), 3, 'd');
        self::assertSame(SmsFailure::RateLimited, $this->refusal(fn (): mixed => $this->sender()->send($code('+447400123459'), 3, 'e'))->failure, 'per account');
        $this->sender()->send($code('+447400123460'), null, 'd');
        self::assertSame(SmsFailure::RateLimited, $this->refusal(fn (): mixed => $this->sender()->send($code('+447400123461'), null, 'd'))->failure, 'per client');
        $this->sender()->send(new SmsMessage(new PhoneNumber('+447400123462'), 'Notification'));
        self::assertSame(SmsFailure::RateLimited, $this->refusal(fn (): mixed => $this->sender()->send(new SmsMessage(new PhoneNumber('+447400123463'), 'Notification')))->failure, 'per site');
        self::assertCount(6, $this->sent);
    }

    public function testKeepsAReserveOfTheBudgetForCodes(): void
    {
        $this->boot(['monthly_budget' => 3, 'reserved_for_codes' => 1]);
        $notification = new SmsMessage(new PhoneNumber(self::MOBILE), 'Notification');
        $this->sender()->send($notification);
        $this->sender()->send($notification);
        $refused = $this->refusal(fn (): mixed => $this->sender()->send($notification));
        self::assertSame([SmsFailure::NoCredit, 'This site has used its text message budget for this month. Ask whoever runs your sites to raise it.'], [$refused->failure, $refused->getMessage()]);
        $this->sender()->send(new SmsMessage(new PhoneNumber(self::MOBILE), 'Code 123456', SmsPurpose::SecurityCode));
        self::assertSame(SmsFailure::NoCredit, $this->refusal(fn (): mixed => $this->sender()->send(new SmsMessage(new PhoneNumber(self::MOBILE), 'Code 654321', SmsPurpose::SecurityCode)))->failure);
        $budget = $this->sender()->budget();
        self::assertSame([3, 100, true, true], [$budget->used, $budget->percent(), $budget->isWarning(), $budget->isUsedUp()]);

        Services::get($this->site->container(), SmsSettings::class)->limit(0, null);
        $this->sender()->send($notification);
        $unlimited = $this->sender()->budget();
        self::assertSame([false, 0, false, false, true], [$unlimited->hasLimit(), $unlimited->percent(), $unlimited->isWarning(), $unlimited->isUsedUp(), $unlimited->allows(99, false)]);
    }

    public function testNotificationsStopAfterStopButCodesStillGo(): void
    {
        $this->boot();
        $number = new PhoneNumber(self::MOBILE);
        $hash = Services::get($this->site->container(), SmsNumberHash::class)->of($number);
        self::assertSame(64, strlen($hash));
        self::assertStringNotContainsString('7400123456', $hash);
        $optOuts = Services::get($this->site->container(), SmsOptOuts::class);
        $optOuts->stop($hash, new \DateTimeImmutable());
        $optOuts->stop($hash, new \DateTimeImmutable());
        self::assertSame(SmsFailure::OptedOut, $this->refusal(fn (): mixed => $this->sender()->send(new SmsMessage($number, 'Notification')))->failure);
        $this->sender()->send(new SmsMessage($number, 'Code 123456', SmsPurpose::SecurityCode));
        $optOuts->start($hash);
        self::assertFalse($optOuts->stopped($hash));
        $this->sender()->send(new SmsMessage($number, 'Notification'));
        self::assertCount(2, $this->sent);
    }

    public function testProviderFailuresAreLoggedAndExplained(): void
    {
        $this->boot();
        $this->answers = [new MockResponse('{"code":20003}', ['http_code' => 401])];
        $refused = $this->refusal(fn (): mixed => $this->sender()->send(new SmsMessage(new PhoneNumber(self::MOBILE), 'Hello')));
        self::assertSame([SmsFailure::InvalidKey, 'The text message provider refused the site’s key. Ask whoever runs your sites to check it.'], [$refused->failure, (string) $refused->text()]);
        $log = Services::get($this->site->container(), SmsLog::class);
        self::assertSame(['failed', 'invalid_key'], [$log->recent(1)[0]['status'], $log->recent(1)[0]['failure']]);
        self::assertSame(0, $this->sender()->budget()->used, 'a refused message costs nothing');
        foreach (SmsFailure::cases() as $failure) {
            self::assertNotSame('', (string) SmsError::message($failure));
        }
    }

    public function testReceiptsUpdateTheLogAndReportsAreSeenOnce(): void
    {
        $this->boot();
        $this->sender()->send(new SmsMessage(new PhoneNumber(self::MOBILE), 'Hello'));
        $log = Services::get($this->site->container(), SmsLog::class);
        self::assertTrue($log->receipt('twilio', 'SM1', SmsDeliveryStatus::Delivered, null, new \DateTimeImmutable()));
        $delivered = $log->recent(1)[0];
        self::assertSame('delivered', $delivered['status']);
        self::assertTrue($log->receipt('twilio', 'SM1', SmsDeliveryStatus::Failed, null, new \DateTimeImmutable()));
        $failed = $log->recent(1)[0];
        self::assertSame(['failed', 'undelivered'], [$failed['status'], $failed['failure']]);
        self::assertFalse($log->receipt('vonage', 'SM1', SmsDeliveryStatus::Delivered, null, new \DateTimeImmutable()));

        $reports = Services::get($this->site->container(), SmsReports::class);
        self::assertTrue($reports->first('abc', new \DateTimeImmutable('2026-10-01')));
        self::assertFalse($reports->first('abc', new \DateTimeImmutable('2026-10-01')));
        self::assertSame(1, $reports->forgetBefore(new \DateTimeImmutable('2026-10-02')));
        self::assertTrue($reports->first('abc', new \DateTimeImmutable()));
    }

    public function testLooksUpNumbersWithinTheDailyCap(): void
    {
        $this->boot(['limits' => ['lookups_per_day' => 2]]);
        $number = new PhoneNumber(self::MOBILE);
        $this->answers = [
            new MockResponse('{"valid":true,"country_code":"GB","line_type_intelligence":{"type":"mobile"}}'),
            new MockResponse('{"valid":true,"country_code":"GB","line_type_intelligence":{"type":"landline"}}'),
        ];
        self::assertTrue($this->sender()->check($number, 'a')->isMobile());
        self::assertSame('This isn’t a mobile number, so it can’t get text messages. Use a mobile number.', $this->refusal(fn (): mixed => $this->sender()->check($number, 'b'))->getMessage());
        self::assertSame(SmsFailure::RateLimited, $this->refusal(fn (): mixed => $this->sender()->check($number, 'c'))->failure, 'the daily lookup cap');
        self::assertCount(2, $this->sent);
    }

    public function testLookupFailuresAndInvalidNumbers(): void
    {
        $this->boot();
        $number = new PhoneNumber(self::MOBILE);
        $this->answers = [new MockResponse('{"valid":false}'), new MockResponse('', ['http_code' => 503])];
        self::assertSame(SmsFailure::InvalidNumber, $this->refusal(fn (): mixed => $this->sender()->check($number))->failure);
        self::assertSame(SmsFailure::Unavailable, $this->refusal(fn (): mixed => $this->sender()->check($number))->failure);
    }

    public function testTheSitesOwnProviderWinsAndIsExplainedWhenIncomplete(): void
    {
        $this->boot(tenantKey: false, on: false);
        $setup = Services::get($this->site->container(), SmsSetup::class);
        self::assertSame('There is no key for Twilio yet.', (string) $setup->configuration()->problem);
        self::assertFalse($setup->sitesMayChoose());
        self::assertNull($this->refusal(fn (): mixed => $this->sender()->send(new SmsMessage(new PhoneNumber(self::MOBILE), 'Hello')))->failure);

        $settings = Services::get($this->site->container(), SmsSettings::class);
        self::assertSame(['sms.provider', 'sms.account', 'sms.sender', 'sms.key'], $settings->connect('vonage', 'abcd1234', 'Shop', null, 'secret|signature', 4));
        self::assertSame([], $settings->connect('vonage', 'abcd1234', 'Shop', null, 'secret|signature', 4));
        self::assertSame(['vonage', 'abcd1234', 'Shop', null, '••••ture'], [$settings->provider(), $settings->account(), $settings->sender(), $settings->region(), $settings->maskedKey()]);
        $configuration = $setup->configuration();
        self::assertSame([SmsConfiguration::SITE, 'vonage', 'Shop'], [$configuration->source, $configuration->provider?->id(), $configuration->connection?->sender]);
        self::assertSame(['sms.monthly_budget'], $settings->limit(25, 4));
        self::assertSame(25, $setup->configuration()->budget);
        self::assertSame(['sms.provider', 'sms.account', 'sms.key', 'sms.sender'], $settings->remove(4));
        self::assertSame([null, false, null, null], [$settings->provider(), $settings->hasKey(), $settings->key(), $settings->maskedKey()]);

        $raw = Services::get($this->site->container(), SiteSettings::class);
        $raw->set(SmsSettings::PROVIDER, 'twilio');
        $raw->set(SmsSettings::KEY, 'not-encrypted');
        self::assertSame([true, null, '••••'], [$settings->hasKey(), $settings->key(), $settings->maskedKey()]);
        self::assertSame('There is no key for Twilio yet.', (string) $setup->configuration()->problem);
        $settings->connect('twilio', self::ACCOUNT, 'not a valid sender!', null, 'short', null);
        self::assertSame('••••', $settings->maskedKey());
        self::assertSame('The text message sender or region isn’t valid. Set the provider up again.', (string) $setup->configuration()->problem);
        $raw->set(SmsSettings::PROVIDER, 'gone');
        self::assertSame('The text message provider (gone) isn’t available any more.', (string) $setup->configuration()->problem);
        $raw->set(SmsSettings::BUDGET, 'lots');
        self::assertNull($settings->budget());
    }

    public function testAProviderWithoutLookupOnlyGetsTheFreeChecks(): void
    {
        $module = new \SightPress\Core\Tests\Support\FakeModule('acme.sms', register: static function (\SightPress\Module\ModuleRegistrar $registrar): void {
            $registrar->services()->autowire(\SightPress\Core\Tests\Support\Sms\NoLookupSmsProvider::class);
            $registrar->sms()->addProvider(\SightPress\Core\Tests\Support\Sms\NoLookupSmsProvider::class);
        });
        $this->site = new KernelSite('sms_sender', extraModules: [new \SightPress\Core\Module\ModuleCandidate($module, 'acme/sms')], tenant: ['sms' => ['provider' => 'no_lookup', 'countries' => ['GB', 'IE']]], variables: ['SMS_API_KEY' => self::KEY]);
        $result = $this->sender()->check(new PhoneNumber(self::MOBILE));
        self::assertSame([true, \SightPress\Module\Sms\PhoneLineType::Unknown, 'GB'], [$result->valid, $result->lineType, $result->country]);
        self::assertSame('nl-1', $this->sender()->send(new SmsMessage(new PhoneNumber(self::MOBILE), 'Hello'))->messageId);
        self::assertSame(SmsFailure::UnsupportedCountry, $this->refusal(fn (): mixed => $this->sender()->check(new PhoneNumber('+353851234567')))->failure, 'allowed, but the provider does not deliver there');
    }

    public function testCannotBeTurnedOnWithoutAProvider(): void
    {
        $this->site = new KernelSite('sms_sender');
        $setup = Services::get($this->site->container(), SmsSetup::class);
        self::assertSame('No text message provider is set up for this site yet.', (string) $setup->configuration()->problem);
        self::assertSame('Text messages can’t be turned on yet: No text message provider is set up for this site yet. Ask whoever runs your sites to set up a provider.', (string) $setup->problem());
        self::assertSame(['twilio', 'vonage'], array_keys(Services::get($this->site->container(), SmsProviders::class)->all()));
        try {
            Services::get($this->site->kernel->container(), SiteModules::class)->turnOn($this->site->site(), 'sms', null);
            self::fail('Turned on without a provider.');
        } catch (\SightPress\Core\Tenant\SiteModuleException $exception) {
            self::assertStringStartsWith('Text messages can’t be turned on yet', $exception->getMessage());
        }
    }

    private function sender(): SmsSender
    {
        return Services::get($this->site->container(), SmsSender::class);
    }

    /**
     * @param \Closure(): mixed $call
     */
    private function refusal(\Closure $call): SmsRefused
    {
        try {
            $call();
        } catch (SmsRefused $refused) {
            self::assertStringNotContainsString('7400123456', $refused->getMessage());
            self::assertStringNotContainsString(self::KEY, (string) $refused);

            return $refused;
        }
        self::fail('Expected a refusal.');
    }
}
