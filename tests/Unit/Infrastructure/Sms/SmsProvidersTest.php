<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Infrastructure\Sms;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Sms\SmsProviders;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Tests\Support\ArrayContainer;
use SightPress\Core\Tests\Support\FakeModule;
use SightPress\Core\Tests\Support\Sms\OtherTwilioProvider;
use SightPress\Core\Tests\Support\Sms\SecondTwilioProvider;
use SightPress\Module\ModuleRegistrar;

#[CoversClass(SmsProviders::class)]
#[UsesNamespace('SightPress\Core\Module')]
final class SmsProvidersTest extends TestCase
{
    public function testOffersCoresProvidersAndThoseOfModulesThatAreOnForTheSite(): void
    {
        $tenant = self::registry();
        $provider = new OtherTwilioProvider();
        self::assertSame([], new SmsProviders(new ArrayContainer([OtherTwilioProvider::class => $provider]), $tenant, $tenant->only([]))->all(), 'the module is off for the site');
        self::assertSame(['twilio' => $provider], new SmsProviders(new ArrayContainer([OtherTwilioProvider::class => $provider]), $tenant, $tenant)->all());
        self::assertNull(new SmsProviders(new ArrayContainer([OtherTwilioProvider::class => $provider]), $tenant, $tenant)->get('vonage'));
    }

    public function testRefusesSomethingThatIsNotAProvider(): void
    {
        $tenant = self::registry();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('is not a SightPress\Module\Sms\SmsProvider');
        new SmsProviders(new ArrayContainer([OtherTwilioProvider::class => new \stdClass()]), $tenant, $tenant)->all();
    }

    public function testRefusesTwoProvidersWithOneId(): void
    {
        $tenant = self::registry(twice: true);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('uses the id "twilio", which is empty or already used');
        new SmsProviders(new ArrayContainer([OtherTwilioProvider::class => new OtherTwilioProvider(), SecondTwilioProvider::class => new SecondTwilioProvider()]), $tenant, $tenant)->all();
    }

    private static function registry(bool $twice = false): ModuleRegistry
    {
        $modules = [new ModuleCandidate(new FakeModule('acme.sms', register: static function (ModuleRegistrar $registrar): void {
            $registrar->sms()->addProvider(OtherTwilioProvider::class);
        }), 'acme/sms')];
        if ($twice) {
            $modules[] = new ModuleCandidate(new FakeModule('acme.sms2', register: static function (ModuleRegistrar $registrar): void {
                $registrar->sms()->addProvider(SecondTwilioProvider::class);
            }), 'acme/sms2');
        }

        return ModuleRegistry::register(new ModuleLoader('0.5.0')->catalogue($modules)->resolve(new ModuleSelection()));
    }
}
