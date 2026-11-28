<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Infrastructure\Privacy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Privacy\InventoryCheck;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Tests\Support\FakeModule;
use SightPress\Core\Tests\Support\Modules\ExamplePersonalDataProvider;
use SightPress\Core\Tests\Support\Privacy\CanaryEntity;
use SightPress\Core\Tests\Support\Privacy\CanaryMigrations;
use SightPress\Core\Tests\Support\Privacy\NeedyProvider;
use SightPress\Core\Tests\Support\Privacy\SameKeyProvider;
use SightPress\Core\User\Entity\User;
use SightPress\Module\ModuleRegistrar;

#[CoversClass(InventoryCheck::class)]
#[UsesNamespace('SightPress\Core')]
final class InventoryCheckTest extends TestCase
{
    public function testCoreKeepsItsInventoryComplete(): void
    {
        $candidates = CoreModules::candidates();
        $all = array_fill_keys(array_map(static fn (ModuleCandidate $candidate): string => $candidate->module->manifest()->id, $candidates), true);
        $registry = ModuleRegistry::register(new ModuleLoader('0.6.0')->catalogue($candidates)->resolve(new ModuleSelection($all)));

        self::assertSame([], new InventoryCheck($registry)->problems([User::class]));
    }

    public function testFlagsACanaryEntityAndACanaryModuleTable(): void
    {
        $registry = ModuleRegistry::register(new ModuleLoader('0.6.0')->catalogue([
            new ModuleCandidate(new FakeModule('acme.events', register: static function (ModuleRegistrar $registrar): void {
                $registrar->privacy()->addProvider(ExamplePersonalDataProvider::class);
                $registrar->privacy()->addProvider(SameKeyProvider::class);
                $registrar->privacy()->addProvider(NeedyProvider::class);
            }), 'acme/events'),
            new ModuleCandidate(new FakeModule('acme.rsvp', register: static function (ModuleRegistrar $registrar): void {
                $registrar->migrations()->add(new CanaryMigrations());
            }), 'acme/rsvp'),
        ])->resolve(new ModuleSelection()));

        $problems = new InventoryCheck($registry)->problems([CanaryEntity::class]);

        self::assertSame([
            'Data set "acme_events.bookings" is described twice (module "acme.events" and module "acme.events").',
            'Personal data provider ' . NeedyProvider::class . ' of module "acme.events" can\'t describe its data set without its services: no set',
            CanaryEntity::class . '::$nickname is personal data of data set "canary.missing", which no module\'s personal data provider describes.',
            CanaryEntity::class . '::$ownerUserId (column owner_user_id) looks like it holds a person, but isn\'t marked with #[PersonalData].',
            CanaryEntity::class . '::$notMarked (column contact_email) looks like it holds a person, but isn\'t marked with #[PersonalData].',
            CanaryEntity::class . '::$promoted is personal data of data set "canary.promoted", which no module\'s personal data provider describes.',
            'Module "acme.rsvp" keeps people in canary_rsvp.user_id, but registers no personal data provider. Add one with $registrar->privacy()->addProvider().',
        ], $problems);
        self::assertCount(7, new CanaryEntity()->values());
    }
}
