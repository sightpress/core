<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Infrastructure\Privacy;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Http\OutboundHostSource;
use SightPress\Core\Infrastructure\Privacy\PrivacyExtensions;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Tests\Support\ArrayContainer;
use SightPress\Core\Tests\Support\FakeModule;
use SightPress\Core\Tests\Support\Modules\ExamplePersonalDataProvider;
use SightPress\Core\Tests\Support\Modules\ExampleProcessorSource;
use SightPress\Core\Tests\Support\Privacy\FailingProcessorSource;
use SightPress\Core\Tests\Support\Privacy\OwnHostProcessorSource;
use SightPress\Core\Tests\Support\Privacy\SameKeyProvider;
use SightPress\Core\Tests\Support\RecordingLogger;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\Privacy\ConsentPurpose;
use SightPress\Module\Privacy\CookieDeclaration;
use SightPress\Module\Text\Message;

#[CoversClass(PrivacyExtensions::class)]
#[UsesNamespace('SightPress\Core')]
final class PrivacyExtensionsTest extends TestCase
{
    public function testCollectsWhatTheModulesThatAreOnDeclare(): void
    {
        $provider = new ExamplePersonalDataProvider();
        $logger = new RecordingLogger();
        $hosts = new class () implements OutboundHostSource {
            public function outboundHosts(): iterable
            {
                yield 'storage.internal';
            }
        };
        $extensions = new PrivacyExtensions(
            new ArrayContainer([
                ExamplePersonalDataProvider::class => $provider,
                ExampleProcessorSource::class => new ExampleProcessorSource(),
                OwnHostProcessorSource::class => new OwnHostProcessorSource(),
                FailingProcessorSource::class => new FailingProcessorSource(),
            ]),
            self::registry(static function (ModuleRegistrar $registrar): void {
                $privacy = $registrar->privacy();
                $privacy->addProvider(ExamplePersonalDataProvider::class);
                $privacy->addProcessors(ExampleProcessorSource::class);
                $privacy->addProcessors(OwnHostProcessorSource::class);
                $privacy->addProcessors(FailingProcessorSource::class);
                $privacy->addCookie(new CookieDeclaration('acme_choice', new Message('Remembers a choice.'), 3600, false, 'acme_events.maps'));
                $privacy->addConsentPurpose(new ConsentPurpose('acme_events.maps', new Message('Maps'), new Message('Show maps.')));
            }),
            $logger,
            [$hosts],
        );

        self::assertSame(['acme_events.bookings' => $provider], $extensions->providers());
        self::assertSame($provider, $extensions->provider('acme_events.bookings'));
        self::assertNull($extensions->provider('other'));
        self::assertSame('acme.events', $extensions->moduleOf('acme_events.bookings'));
        self::assertNull($extensions->moduleOf('other'));
        self::assertSame(['acme_choice'], array_map(static fn (CookieDeclaration $cookie): string => $cookie->name, $extensions->cookies()));
        self::assertSame('acme_events.maps', $extensions->consentPurposes()[0]->key);

        $processors = $extensions->processors();
        self::assertSame(['acme_events.tickets', 'acme_events.maps'], array_keys($processors));
        self::assertSame('Acme Tickets Ltd', $processors['acme_events.tickets']->name, 'the first declaration of an id wins');
        self::assertSame(['api.acme-tickets.example', '*.maps.example', 'storage.internal', 'minio'], $extensions->outboundHosts(), 'only the hosts of the declarations that count');
        self::assertStringContainsString('Processor source ' . FailingProcessorSource::class . ' of module "acme.events" failed', $logger->messages()[0] ?? '');
        self::assertStringNotContainsString('{', $logger->messages()[0] ?? '{');
    }

    public function testRefusesWhatIsNotAProviderOrSourceAndKeysUsedTwice(): void
    {
        $wrong = new PrivacyExtensions(
            new ArrayContainer([ExamplePersonalDataProvider::class => new \stdClass(), ExampleProcessorSource::class => new \stdClass()]),
            self::registry(static function (ModuleRegistrar $registrar): void {
                $registrar->privacy()->addProvider(ExamplePersonalDataProvider::class);
                $registrar->privacy()->addProcessors(ExampleProcessorSource::class);
            }),
            new RecordingLogger(),
        );
        foreach ([static fn () => $wrong->providers(), static fn () => $wrong->processors()] as $call) {
            try {
                $call();
                self::fail('Expected a LogicException.');
            } catch (LogicException $exception) {
                self::assertStringContainsString('of module "acme.events" is not a', $exception->getMessage());
            }
        }

        $twice = new PrivacyExtensions(
            new ArrayContainer([ExamplePersonalDataProvider::class => new ExamplePersonalDataProvider(), SameKeyProvider::class => new SameKeyProvider()]),
            self::registry(static function (ModuleRegistrar $registrar): void {
                $registrar->privacy()->addProvider(ExamplePersonalDataProvider::class);
                $registrar->privacy()->addProvider(SameKeyProvider::class);
            }),
            new RecordingLogger(),
        );
        $this->expectExceptionMessage('uses the data set key "acme_events.bookings", which ' . ExamplePersonalDataProvider::class . ' already uses');
        $twice->providers();
    }

    /**
     * @param \Closure(ModuleRegistrar): void $register
     */
    private static function registry(\Closure $register): ModuleRegistry
    {
        return ModuleRegistry::register(new ModuleLoader('0.6.0')->catalogue([new ModuleCandidate(new FakeModule('acme.events', register: $register), 'acme/events')])->resolve(new ModuleSelection()));
    }
}
