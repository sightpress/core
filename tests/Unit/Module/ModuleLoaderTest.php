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
use SightPress\Core\Tests\Support\FakeModule;
use SightPress\Module\ModuleManifest;

#[CoversClass(ModuleLoader::class)]
#[CoversClass(ModuleCandidate::class)]
#[CoversClass(LoadedModule::class)]
#[UsesClass(ModuleCatalogue::class)]
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
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage(
            'Two modules use the id "acme.events": one from acme/events and one from other/events. Remove one of them.',
        );

        new ModuleLoader('0.2.0')->catalogue([
            new ModuleCandidate(new FakeModule('acme.events'), 'acme/events', '^0.2'),
            new ModuleCandidate(new FakeModule('acme.events'), 'other/events', '^0.2'),
        ]);
    }

    public function testAnInvalidManifestNamesTheModuleAndItsPackage(): void
    {
        $module = new FakeModule('x', manifest: static fn (): ModuleManifest => new ModuleManifest('Bad Id', '1.0.0'));

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessageMatches(
            '/^The module SightPress\\\\Core\\\\Tests\\\\Support\\\\FakeModule from acme\/bad has an invalid manifest: /',
        );

        new ModuleLoader('0.2.0')->catalogue([new ModuleCandidate($module, 'acme/bad', '^0.2')]);
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

    public function testRejectsAModuleThatNeedsAnotherContractVersion(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage(
            'Module "acme.events" from acme/events works with sightpress/module-interface ^0.1, but 0.2.0 is installed.',
        );

        new ModuleLoader('0.2.0')->catalogue([
            new ModuleCandidate(new FakeModule('acme.events'), 'acme/events', '^0.1'),
        ]);
    }

    public function testRejectsAnInvalidContractConstraint(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage(
            'Module "acme.events" from acme/events has an invalid constraint on sightpress/module-interface ("^^nope").',
        );

        new ModuleLoader('0.2.0')->catalogue([
            new ModuleCandidate(new FakeModule('acme.events'), 'acme/events', '^^nope'),
        ]);
    }
}
