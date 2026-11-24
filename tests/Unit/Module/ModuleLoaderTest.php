<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Module;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Module\LoadedModule;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleCatalogue;
use SightPress\Core\Module\ModuleException;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\UnavailableModule;
use RuntimeException;
use SightPress\Core\Tests\Support\FakeModule;
use SightPress\Module\ModuleManifest;

#[CoversClass(ModuleLoader::class)]
#[CoversClass(ModuleCandidate::class)]
#[CoversClass(LoadedModule::class)]
#[UsesClass(ModuleCatalogue::class)]
#[CoversClass(UnavailableModule::class)]
final class ModuleLoaderTest extends TestCase
{
    public function testBuildsTheCatalogueInDiscoveryOrder(): void
    {
        $catalogue = new ModuleLoader('0.2.0')->catalogue([
            new ModuleCandidate(new FakeModule('user', required: true), 'sightpress/core'),
            new ModuleCandidate(new FakeModule('acme.events', ['user' => '*']), 'acme/events', '^0.2'),
        ]);

        self::assertSame(['user', 'acme.events'], $catalogue->ids());
        $events = $catalogue->get('acme.events');
        self::assertSame('acme/events', $events->source);
        self::assertSame('acme.events', $events->id());
        self::assertSame(['user'], $events->requiredIds());
    }

    public function testTwoModulesCantShareAnId(): void
    {
        $catalogue = new ModuleLoader('0.2.0')->catalogue([
            new ModuleCandidate(new FakeModule('acme.events'), 'acme/events', '^0.2'),
            new ModuleCandidate(new FakeModule('acme.events'), 'other/events', '^0.2'),
        ]);

        self::assertSame('acme/events', $catalogue->get('acme.events')->source);
        self::assertEquals([new UnavailableModule(
            'other/events',
            'acme.events',
            'Two modules use the id "acme.events": one from acme/events and one from other/events. Remove one of them.',
        )], $catalogue->unavailable());
    }

    public function testCoreModulesThatShareAnIdStopTheTenant(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('Two modules use the id "user": one from sightpress/core and one from sightpress/core.');

        new ModuleLoader('0.2.0')->catalogue([
            new ModuleCandidate(new FakeModule('user'), 'sightpress/core'),
            new ModuleCandidate(new FakeModule('user'), 'sightpress/core'),
        ]);
    }

    public function testAnInvalidManifestNamesTheModuleAndItsPackage(): void
    {
        $module = new FakeModule('x', manifest: static fn (): ModuleManifest => new ModuleManifest('Bad Id', '1.0.0'));

        $catalogue = new ModuleLoader('0.2.0')->catalogue([new ModuleCandidate($module, 'acme/bad', '^0.2')]);

        self::assertSame([], $catalogue->ids());
        self::assertCount(1, $catalogue->unavailable());
        self::assertNull($catalogue->unavailable()[0]->id);
        self::assertSame('acme/bad', $catalogue->unavailable()[0]->identity());
        self::assertMatchesRegularExpression(
            '/^The module SightPress\\\\Core\\\\Tests\\\\Support\\\\FakeModule from acme\/bad has an invalid manifest: /',
            $catalogue->unavailable()[0]->reason,
        );
    }

    public function testAManifestThatFailsMakesAnExternalModuleUnavailable(): void
    {
        $module = new FakeModule('x', manifest: static fn (): ModuleManifest => throw new RuntimeException('No manifest file.'));

        $catalogue = new ModuleLoader('0.2.0')->catalogue([new ModuleCandidate($module, 'acme/bad', '^0.2')]);

        self::assertSame(
            'The module SightPress\Core\Tests\Support\FakeModule from acme/bad failed to give its manifest: No manifest file.',
            $catalogue->unavailable()[0]->reason,
        );
    }

    public function testAnInvalidCoreManifestStopsTheTenant(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessageMatches('/from sightpress\/core has an invalid manifest: /');

        new ModuleLoader('0.2.0')->catalogue([new ModuleCandidate(new FakeModule('x', manifest: static fn (): ModuleManifest => new ModuleManifest('Bad Id', '1.0.0')), 'sightpress/core')]);
    }

    public function testACoreManifestThatFailsStopsTheTenant(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No manifest file.');

        new ModuleLoader('0.2.0')->catalogue([new ModuleCandidate(new FakeModule('x', manifest: static fn (): ModuleManifest => throw new RuntimeException('No manifest file.')), 'sightpress/core')]);
    }

    public function testModulesWhoseRequirementsArentMetAreUnavailableWithTheModulesThatNeedThem(): void
    {
        $catalogue = new ModuleLoader('0.2.0')->catalogue([
            new ModuleCandidate(new FakeModule('user', required: true), 'sightpress/core'),
            new ModuleCandidate(new FakeModule('acme.events', ['user' => '*']), 'acme/events', '^0.2'),
            new ModuleCandidate(new FakeModule('acme.calendar', ['acme.missing' => '^1.0']), 'acme/calendar', '^0.2'),
            new ModuleCandidate(new FakeModule('acme.agenda', ['acme.calendar' => '^1.0']), 'acme/agenda', '^0.2'),
            new ModuleCandidate(new FakeModule('acme.old', ['acme.events' => '^2.0']), 'acme/old', '^0.2'),
            new ModuleCandidate(new FakeModule('acme.typo', ['acme.events' => '^^1']), 'acme/typo', '^0.2'),
            new ModuleCandidate(new FakeModule('acme.always', ['acme.events' => '*'], required: true), 'acme/always', '^0.2'),
            new ModuleCandidate(new FakeModule('acme.ping', ['acme.pong' => '*']), 'acme/ping', '^0.2'),
            new ModuleCandidate(new FakeModule('acme.pong', ['acme.ping' => '*']), 'acme/pong', '^0.2'),
        ]);

        self::assertSame(['user', 'acme.events'], $catalogue->ids());
        self::assertSame([
            'acme.calendar (acme/calendar): It needs module "acme.missing", which is not installed or is unavailable.',
            'acme.agenda (acme/agenda): It needs module "acme.calendar", which is not installed or is unavailable.',
            'acme.old (acme/old): It needs module "acme.events" ^2.0, but version 1.0.0 is installed.',
            'acme.typo (acme/typo): It has an invalid version constraint for module "acme.events" ("^^1").',
            'acme.always (acme/always): It is always on, so it may only need modules that are always on, but "acme.events" is optional.',
            'acme.ping (acme/ping): It needs modules that need it in turn, in a loop, so none of them can start.',
            'acme.pong (acme/pong): It needs modules that need it in turn, in a loop, so none of them can start.',
        ], array_map(static fn (UnavailableModule $module): string => $module->identity() . ': ' . $module->reason, $catalogue->unavailable()));
    }

    public function testCoreModulesAreNeverLeftOut(): void
    {
        $catalogue = new ModuleLoader('0.2.0')->catalogue([
            new ModuleCandidate(new FakeModule('page', ['missing' => '*']), 'sightpress/core'),
            new ModuleCandidate(new FakeModule('a', ['b' => '*']), 'sightpress/core'),
            new ModuleCandidate(new FakeModule('b', ['a' => '*']), 'sightpress/core'),
        ]);

        // Resolving them reports the problem and stops the tenant, as before.
        self::assertSame(['page', 'a', 'b'], $catalogue->ids());
        self::assertSame([], $catalogue->unavailable());
    }

    /**
     * @return iterable<string, array{string, non-empty-string|null}>
     */
    public static function compatibleContracts(): iterable
    {
        yield 'core module without a constraint' => ['0.2.0', null];
        yield 'release in range' => ['0.2.3', '^0.2'];
        yield 'branch alias in range' => ['0.2.x-dev', '^0.2'];
        yield 'development branch without alias' => ['dev-main', '^9.0'];
    }

    /**
     * @param non-empty-string|null $constraint
     */
    #[DataProvider('compatibleContracts')]
    public function testAcceptsModulesThatWorkWithTheInstalledContract(string $installed, ?string $constraint): void
    {
        $catalogue = new ModuleLoader($installed)->catalogue([
            new ModuleCandidate(new FakeModule('acme.events'), 'acme/events', $constraint),
        ]);

        self::assertTrue($catalogue->has('acme.events'));
    }

    public function testAModuleThatNeedsAnotherContractVersionIsUnavailable(): void
    {
        $broken = new UnavailableModule('acme/broken', null, 'It does not load.');
        $catalogue = new ModuleLoader('0.2.0')->catalogue([
            new ModuleCandidate(new FakeModule('acme.events'), 'acme/events', '^0.1'),
        ], [$broken]);

        self::assertFalse($catalogue->has('acme.events'));
        self::assertEquals([$broken, new UnavailableModule(
            'acme/events',
            'acme.events',
            'Module "acme.events" from acme/events works with sightpress/module-interface ^0.1, but 0.2.0 is installed. '
            . 'Update the module, or install a version of it that works with this release of SightPress.',
        )], $catalogue->unavailable());
    }

    public function testAnInvalidContractConstraintMakesTheModuleUnavailable(): void
    {
        $catalogue = new ModuleLoader('0.2.0')->catalogue([
            new ModuleCandidate(new FakeModule('acme.events'), 'acme/events', '^^nope'),
        ]);

        self::assertSame(
            'Module "acme.events" from acme/events has an invalid constraint on sightpress/module-interface ("^^nope").',
            $catalogue->unavailable()[0]->reason,
        );
    }
}
