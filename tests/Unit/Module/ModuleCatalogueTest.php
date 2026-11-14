<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Module;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Module\LoadedModule;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleCatalogue;
use SightPress\Core\Module\ModuleException;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Tests\Support\FakeModule;

#[CoversClass(ModuleCatalogue::class)]
#[CoversClass(ModuleSelection::class)]
#[UsesClass(ModuleLoader::class)]
#[UsesClass(ModuleCandidate::class)]
#[UsesClass(LoadedModule::class)]
final class ModuleCatalogueTest extends TestCase
{
    public function testEachModuleComesAfterTheModulesItRequires(): void
    {
        $catalogue = $this->catalogue(
            new FakeModule('page', ['block' => '^1.0', 'user' => '*'], required: true),
            new FakeModule('admin', ['page' => '*', 'user' => '*'], required: true),
            new FakeModule('user', required: true),
            new FakeModule('block', ['user' => '*'], required: true),
            new FakeModule('media'),
        );

        self::assertSame(['user', 'block', 'media', 'page', 'admin'], $this->ids($catalogue->resolve(new ModuleSelection())));
    }

    public function testTheOrderIsTheSameEveryTime(): void
    {
        $catalogue = $this->catalogue(new FakeModule('b'), new FakeModule('a'), new FakeModule('c', ['a' => '*']));

        self::assertSame(['b', 'a', 'c'], $catalogue->ids());

        self::assertSame(['b', 'a', 'c'], $this->ids($catalogue->resolve(new ModuleSelection())));
        self::assertSame(['b', 'a', 'c'], $this->ids($catalogue->resolve(new ModuleSelection())));
    }

    public function testAMissingDependencyStopsTheBootAndNamesBothModules(): void
    {
        $catalogue = $this->catalogue(new FakeModule('acme.events', ['calendar' => '^1.0']));

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage(
            'Module "acme.events" requires module "calendar", which is not installed. '
            . 'Install it, or turn "acme.events" off.',
        );

        $catalogue->resolve(new ModuleSelection());
    }

    public function testAMissingDependencyOfAModuleThatIsOffDoesNotMatter(): void
    {
        $catalogue = $this->catalogue(new FakeModule('user'), new FakeModule('acme.events', ['calendar' => '*']));

        self::assertSame(['user'], $this->ids($catalogue->resolve(new ModuleSelection(['acme.events' => false]))));
    }

    public function testACycleStopsTheBootAndShowsTheLoop(): void
    {
        $catalogue = $this->catalogue(
            new FakeModule('user'),
            new FakeModule('a', ['user' => '*', 'b' => '*']),
            new FakeModule('b', ['c' => '*']),
            new FakeModule('c', ['a' => '*']),
            new FakeModule('d', ['a' => '*']),
        );

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage(
            'These modules require each other in a loop, so none of them can start: a → b → c → a. '
            . 'Remove one of the requirements.',
        );

        $catalogue->resolve(new ModuleSelection());
    }

    public function testALoopFoundAfterAModuleOutsideIt(): void
    {
        $catalogue = $this->catalogue(
            new FakeModule('d', ['a' => '*']),
            new FakeModule('a', ['b' => '*']),
            new FakeModule('b', ['a' => '*']),
        );

        $this->expectExceptionMessage('none of them can start: a → b → a.');

        $catalogue->resolve(new ModuleSelection());
    }

    public function testAnUnmetVersionConstraintStopsTheBoot(): void
    {
        $catalogue = $this->catalogue(new FakeModule('user', version: '1.4.0'), new FakeModule('page', ['user' => '^2.0']));

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('Module "page" requires module "user" ^2.0, but version 1.4.0 is installed.');

        $catalogue->resolve(new ModuleSelection());
    }

    public function testAnInvalidVersionConstraintStopsTheBoot(): void
    {
        $catalogue = $this->catalogue(new FakeModule('user'), new FakeModule('page', ['user' => 'not a version']));

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('Module "page" has an invalid version constraint for module "user" ("not a version").');

        $catalogue->resolve(new ModuleSelection());
    }

    public function testARequiredModuleMayOnlyRequireRequiredModules(): void
    {
        $catalogue = $this->catalogue(new FakeModule('media'), new FakeModule('page', ['media' => '*'], required: true));

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage(
            'Module "page" is required, so it may only require other required modules, but "media" is optional.',
        );

        $catalogue->resolve(new ModuleSelection());
    }

    public function testAModuleThatIsOnCantRequireOneThatIsOff(): void
    {
        $catalogue = $this->catalogue(new FakeModule('media'), new FakeModule('gallery', ['media' => '*']));

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage(
            'Module "gallery" requires module "media", which is off. Turn on "media", or turn "gallery" off.',
        );

        $catalogue->resolve(new ModuleSelection(['media' => false]));
    }

    public function testModulesFollowTheSiteChoiceOrTheirDefault(): void
    {
        $catalogue = $this->catalogue(
            new FakeModule('user', required: true),
            new FakeModule('media', enabledByDefault: true),
            new FakeModule('events', enabledByDefault: false),
            new FakeModule('forms', enabledByDefault: false),
        );
        $selection = new ModuleSelection(['media' => false, 'forms' => true, 'uninstalled' => false]);

        self::assertSame(['user', 'forms'], $this->ids($catalogue->resolve($selection)));
        self::assertTrue($catalogue->isOn($selection, 'user'));
        self::assertFalse($catalogue->isOn($selection, 'events'));
        self::assertNull($selection->state('events'));
        self::assertTrue($selection->state('forms'));
    }

    public function testARequiredModuleCantBeTurnedOff(): void
    {
        $catalogue = $this->catalogue(new FakeModule('user', required: true));

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('Module "user" is required, so it can\'t be turned off.');

        $catalogue->turnOff(new ModuleSelection(), 'user');
    }

    public function testASiteCantSwitchOffARequiredModule(): void
    {
        $catalogue = $this->catalogue(new FakeModule('user', required: true));

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('Module "user" is required, so it can\'t be turned off.');

        $catalogue->resolve(new ModuleSelection(['user' => false]));
    }

    public function testAModuleCantBeTurnedOffWhileAnotherNeedsIt(): void
    {
        $catalogue = $this->catalogue(new FakeModule('media'), new FakeModule('gallery', ['media' => '*']));

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage(
            'Module "media" can\'t be turned off while module "gallery" is on, because "gallery" requires it. '
            . 'Turn off "gallery" first.',
        );

        $catalogue->turnOff(new ModuleSelection(), 'media');
    }

    public function testTurningModulesOffAndOnInTheRightOrder(): void
    {
        $catalogue = $this->catalogue(new FakeModule('media'), new FakeModule('gallery', ['media' => '*']));

        $off = $catalogue->turnOff($catalogue->turnOff(new ModuleSelection(), 'gallery'), 'media');
        self::assertSame(['media' => false, 'gallery' => false], $off->states());
        self::assertSame([], $catalogue->resolve($off));

        $on = $catalogue->turnOn($catalogue->turnOn($off, 'media'), 'gallery');
        self::assertSame(['media', 'gallery'], $this->ids($catalogue->resolve($on)));
    }

    public function testAModuleCantBeTurnedOnBeforeItsRequirements(): void
    {
        $catalogue = $this->catalogue(new FakeModule('media'), new FakeModule('gallery', ['media' => '*']));

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage(
            'Module "gallery" can\'t be turned on while module "media" is off, because it requires it. '
            . 'Turn on "media" first.',
        );

        $catalogue->turnOn(new ModuleSelection(['media' => false]), 'gallery');
    }

    public function testAModuleWithAMissingRequirementCantBeTurnedOn(): void
    {
        $catalogue = $this->catalogue(new FakeModule('gallery', ['media' => '*']));

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage(
            'Module "gallery" can\'t be turned on: it requires module "media", which is not installed.',
        );

        $catalogue->turnOn(new ModuleSelection(), 'gallery');
    }

    public function testAnUnknownModuleIsNotInstalled(): void
    {
        $catalogue = $this->catalogue();

        self::assertFalse($catalogue->has('media'));
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('Module "media" is not installed.');

        $catalogue->get('media');
    }

    private function catalogue(FakeModule ...$modules): ModuleCatalogue
    {
        return new ModuleLoader('0.2.0')->catalogue(array_map(
            static fn (FakeModule $module): ModuleCandidate => new ModuleCandidate($module, 'sightpress/core'),
            array_values($modules),
        ));
    }

    /**
     * @param list<LoadedModule> $modules
     *
     * @return list<string>
     */
    private function ids(array $modules): array
    {
        return array_map(static fn (LoadedModule $module): string => $module->id(), $modules);
    }
}
