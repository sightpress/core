<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Infrastructure\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Assistant\Http\AssistantHttp;
use SightPress\Core\Assistant\Http\EndpointGuard;
use SightPress\Core\Infrastructure\Http\OutboundHosts;
use SightPress\Core\Infrastructure\Http\OutboundHttp;
use SightPress\Core\Infrastructure\Storage\Provisioning\CloudflareProvisioner;
use SightPress\Core\Infrastructure\Storage\Provisioning\ProvisioningException;
use SightPress\Core\Infrastructure\Storage\Provisioning\ProvisioningRequest;
use SightPress\Core\Sms\Http\SmsHttp;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\Assistant\FixedResolver;
use SightPress\Module\Assistant\AssistantFailure;
use SightPress\Module\Exception\AssistantException;
use SightPress\Module\Exception\SmsException;
use SightPress\Module\Sms\SmsFailure;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Core's callers say clearly that a host was refused (GD-1), instead of "couldn't be reached".
 */
#[CoversClass(AssistantHttp::class)]
#[CoversClass(SmsHttp::class)]
#[CoversClass(CloudflareProvisioner::class)]
#[UsesNamespace('SightPress\Core')]
final class RefusedHostsTest extends TestCase
{
    private const string REFUSED = 'no service the site uses declares this address';

    public function testTheAssistantSaysTheHostWasRefused(): void
    {
        $guard = new EndpointGuard(TenantConfig::fromArray([], '/tmp/project', ['APP_ENV' => 'production']), new FixedResolver(['ai.example.org' => ['93.184.216.34']]));
        try {
            new AssistantHttp(self::nowhere(), $guard, 0)->postJson('Fake AI', 'https://ai.example.org/v1', [], [], static fn (): AssistantFailure => AssistantFailure::Unavailable);
            self::fail('It was sent.');
        } catch (AssistantException $exception) {
            self::assertSame(AssistantFailure::Unavailable, $exception->failure);
            self::assertStringStartsWith('Fake AI: The request to ai.example.org was refused', $exception->getMessage());
        }
    }

    public function testTextMessagesSayTheHostWasRefused(): void
    {
        try {
            new SmsHttp(self::nowhere(), 0)->request('Twilio', 'POST', 'https://api.twilio.com/x', []);
            self::fail('It was sent.');
        } catch (SmsException $exception) {
            self::assertSame(SmsFailure::Unavailable, $exception->failure);
            self::assertStringContainsString(self::REFUSED, $exception->getMessage());
        }
    }

    public function testProvisioningSaysTheHostWasRefused(): void
    {
        $guard = new EndpointGuard(TenantConfig::fromArray([], '/tmp/project', ['APP_ENV' => 'production']), new FixedResolver(['api.cloudflare.com' => ['104.16.132.229']]));
        try {
            new CloudflareProvisioner(self::nowhere(), $guard, 'acc', 'token-value', null, 0)->provision(new ProvisioningRequest('media-bucket', 'eu', 'sites/one', ['https://demo.example'], 'demo.example', null));
            self::fail('It was sent.');
        } catch (ProvisioningException $exception) {
            self::assertStringContainsString(self::REFUSED, $exception->getMessage());
        }
    }

    private static function nowhere(): OutboundHttp
    {
        return new OutboundHttp(new MockHttpClient(static fn (): never => self::fail('Nothing may be sent.')), OutboundHosts::of([]));
    }
}
