<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Sms\Privacy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Privacy\Row;
use SightPress\Core\Infrastructure\Sms\SmsProviders;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Sms\Http\SmsHttp;
use SightPress\Core\Sms\Privacy\SmsLogData;
use SightPress\Core\Sms\Privacy\SmsProcessors;
use SightPress\Core\Sms\Provider\TwilioProvider;
use SightPress\Core\Sms\Provider\VonageProvider;
use SightPress\Core\Sms\SmsNumberHash;
use SightPress\Core\Sms\SmsSettings;
use SightPress\Core\Sms\SmsSetup;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\ArrayContainer;
use SightPress\Core\Tests\Support\ArraySiteSettings;
use SightPress\Core\Tests\Support\FakeModule;
use SightPress\Core\Tests\Support\FixedClock;
use SightPress\Core\Tests\Support\Privacy\SiteTables;
use SightPress\Core\Tests\Support\RecordingEvents;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\Modules\ExampleSmsProvider;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\Privacy\UserRef;
use SightPress\Module\Sms\PhoneNumber;
use Symfony\Component\HttpClient\MockHttpClient;

#[CoversClass(SmsLogData::class)]
#[CoversClass(SmsProcessors::class)]
#[UsesNamespace('SightPress\Core')]
final class SmsPrivacyTest extends TestCase
{
    private const string SECRET = 'a-secret-that-is-long-enough-for-the-tenant-0123456789';

    public function testExportsAndDeletesTheTextsSentToThePerson(): void
    {
        $connection = SiteTables::connection();
        $hashes = new SmsNumberHash(TenantConfig::fromArray([], '/tmp/project', ['APP_SECRET' => self::SECRET]), SiteFixture::site());
        $mine = $hashes->of(new PhoneNumber('+447700900123'));
        foreach ([[1, 7, 'other-hash'], [2, null, $mine], [3, 8, 'grace-hash']] as [$id, $user, $hash]) {
            $connection->insert('sms_message', ['id' => $id, 'created_at' => '2026-11-0' . $id . ' 10:00:00', 'month' => '2026-11', 'purpose' => 'security_code', 'kind' => null, 'to_masked' => '•••• 0123', 'to_hash' => $hash, 'user_id' => $user, 'provider' => 'twilio', 'parts' => 1, 'status' => 'sent', 'body' => 'Your code is 123456', 'sent_at' => '2026-11-0' . $id . ' 10:00:01', 'updated_at' => '2026-11-0' . $id . ' 10:00:01']);
        }
        $data = new SmsLogData($connection, $hashes);
        $ada = new UserRef(7, 'ada@example.org', new PhoneNumber('+447700900123'));

        self::assertSame(90, $data->describe()->retention->days);
        $texts = iterator_to_array($data->export($ada), false);
        self::assertCount(2, $texts);
        self::assertSame(['2026-11-01T10:00:00Z', 'security_code', null, '•••• 0123', 'sent', 1, 'Your code is 123456', '2026-11-01T10:00:01Z'], array_values($texts[0]));
        self::assertCount(1, iterator_to_array($data->export(new UserRef(7, 'ada@example.org')), false), 'without a number, by account only');
        self::assertSame(2, $data->erase($ada)->deleted);
        self::assertSame([3], array_map(Row::int(...), $connection->fetchFirstColumn('SELECT id FROM sms_message')));
    }

    public function testDeclaresTheProviderOnlyWhileOneIsSetUp(): void
    {
        self::assertSame([], [...new SmsProcessors(self::smsSetup([], []))->processors()]);
        self::assertSame([], [...new SmsProcessors(self::smsSetup(['provider' => 'twilio', 'account' => 'AC' . str_repeat('0', 32), 'sender' => 'SightPress'], []))->processors()], 'no key yet');

        $twilio = [...new SmsProcessors(self::smsSetup(['provider' => 'twilio', 'account' => 'AC' . str_repeat('0', 32), 'sender' => 'SightPress'], ['SMS_API_KEY' => 'key']))->processors()];
        self::assertSame('sms.twilio', $twilio[0]->id);
        self::assertSame(['api.twilio.com', 'lookups.twilio.com'], $twilio[0]->hosts);
        self::assertSame(['US'], $twilio[0]->countries);

        $vonage = [...new SmsProcessors(self::smsSetup(['provider' => 'vonage', 'account' => 'abcd1234', 'sender' => 'SightPress'], ['SMS_API_KEY' => 'key']))->processors()];
        self::assertSame(['rest.nexmo.com', 'api.nexmo.com'], $vonage[0]->hosts);

        self::assertSame([], [...new SmsProcessors(self::smsSetup(['provider' => 'example', 'account' => 'AC' . str_repeat('0', 32), 'sender' => 'SightPress'], ['SMS_API_KEY' => 'key']))->processors()], 'a module\'s provider is declared by its module');
    }

    /**
     * @param array<string, mixed>  $sms
     * @param array<string, string> $environment
     */
    private static function smsSetup(array $sms, array $environment): SmsSetup
    {
        $http = new SmsHttp(new MockHttpClient());
        $clock = new FixedClock();
        $registry = ModuleRegistry::register(new ModuleLoader('0.6.0')->catalogue([new ModuleCandidate(new FakeModule('acme.texts', register: static function (ModuleRegistrar $registrar): void {
            foreach ([TwilioProvider::class, VonageProvider::class, ExampleSmsProvider::class] as $provider) {
                $registrar->sms()->addProvider($provider);
            }
        }), 'acme/texts')])->resolve(new ModuleSelection()));
        $providers = new SmsProviders(new ArrayContainer([
            TwilioProvider::class => new TwilioProvider($http, $clock),
            VonageProvider::class => new VonageProvider($http, $clock),
            ExampleSmsProvider::class => new ExampleSmsProvider(),
        ]), $registry, $registry);

        return new SmsSetup(
            new SmsSettings(new ArraySiteSettings(), SiteFixture::secretBox(), new RecordingEvents()),
            $providers,
            TenantConfig::fromArray(['sms' => $sms], '/tmp/project', $environment),
        );
    }
}
