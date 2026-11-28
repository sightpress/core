<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Privacy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Privacy\PrivacyExtensions;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Privacy\Contract\PersonalDataInventory;
use SightPress\Core\Privacy\MailerProcessors;
use SightPress\Core\Privacy\PrivacyModule;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\ArrayContainer;
use SightPress\Core\Tests\Support\Modules\ExamplePersonalDataProvider;
use SightPress\Core\Tests\Support\Modules\ExampleProcessorSource;
use SightPress\Core\Tests\Support\RecordingLogger;

#[CoversClass(PrivacyModule::class)]
#[CoversClass(MailerProcessors::class)]
#[CoversClass(PersonalDataInventory::class)]
#[UsesNamespace('SightPress\Core')]
final class PrivacyModuleTest extends TestCase
{
    public function testIsRequiredAndRegistersTheMailerAndThePermission(): void
    {
        $module = new PrivacyModule();
        $registry = ModuleRegistry::register(new ModuleLoader('0.6.0')->catalogue(CoreModules::candidates())->resolve(new ModuleSelection()));

        self::assertSame(['privacy', true], [$module->manifest()->id, $module->manifest()->required]);
        $permissions = array_values(array_filter($registry->permissions(), static fn ($p) => $p->module === 'privacy'));
        self::assertSame([PrivacyModule::MANAGE], array_map(static fn ($p) => $p->item->name, $permissions));
        self::assertSame(['admin'], $permissions[0]->item->defaultRoles);
        self::assertContains(MailerProcessors::class, array_map(static fn ($s) => $s->item, $registry->processorSources()));
        self::assertSame(
            ['site.sessions', 'user.account', 'user.avatar', 'user.phone', 'rbac.roles', 'auth.remember', 'auth.codes', 'auth.throttle', 'media.uploads', 'page.authors', 'people.invitations', 'feature.changes', 'audit.log'],
            array_map(static fn ($p) => new \ReflectionClass($p->item)->newInstanceWithoutConstructor()->describe()->key, $registry->personalDataProviders()),
        );
        self::assertSame(['__Host-sightpress_session', 'sightpress_session', '__Host-sightpress_remember', 'sightpress_remember'], array_map(static fn ($c) => $c->item->name, $registry->cookies()));
    }

    public function testDeclaresTheMailServerOnlyWhenItIsSomeoneElses(): void
    {
        $mailer = static fn (string $dsn): array => [...new MailerProcessors(TenantConfig::fromArray([], '/tmp/project', ['MAILER_DSN' => $dsn]))->processors()];

        foreach (['', 'null://null', 'sendmail://default', 'native://default', 'smtp://mailpit:1025', 'smtp://10.0.0.2:25'] as $dsn) {
            self::assertSame([], $mailer($dsn), $dsn);
        }
        $declared = $mailer('smtps://user:secret@smtp.example.org:465');
        self::assertSame([MailerProcessors::ID, 'smtp.example.org', ['ZZ'], []], [$declared[0]->id, $declared[0]->name, $declared[0]->countries, $declared[0]->hosts]);
    }

    public function testTheInventoryListsWhatTheModulesDeclare(): void
    {
        $provider = new ExamplePersonalDataProvider();
        $registry = ModuleRegistry::register(new ModuleLoader('0.6.0')->catalogue([new \SightPress\Core\Module\ModuleCandidate(new \SightPress\Core\Tests\Support\FakeModule('acme.events', register: static function (\SightPress\Module\ModuleRegistrar $registrar): void {
            $registrar->privacy()->addProvider(ExamplePersonalDataProvider::class);
            $registrar->privacy()->addProcessors(ExampleProcessorSource::class);
        }), 'acme/events')])->resolve(new ModuleSelection()));
        $inventory = new PersonalDataInventory(new PrivacyExtensions(new ArrayContainer([ExamplePersonalDataProvider::class => $provider, ExampleProcessorSource::class => new ExampleProcessorSource()]), $registry, new RecordingLogger()));

        self::assertSame(['acme_events.bookings'], array_keys($inventory->dataSets()));
        self::assertSame('Event bookings', $inventory->dataSets()['acme_events.bookings']->label->text);
        self::assertSame(['acme_events.bookings' => $provider], $inventory->providers());
        self::assertSame($provider, $inventory->provider('acme_events.bookings'));
        self::assertSame('acme.events', $inventory->moduleOf('acme_events.bookings'));
        self::assertSame([], $inventory->cookies());
        self::assertSame(['acme_events.tickets'], array_keys($inventory->processors()));
        self::assertSame(['analytics', 'search', 'contact'], array_keys(PersonalDataInventory::notStored()));
    }
}
