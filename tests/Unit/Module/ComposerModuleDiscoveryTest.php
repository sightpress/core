<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Module;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Module\ComposerModuleDiscovery;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleException;
use SightPress\Core\Tests\Support\Modules\ExampleModule;
use SightPress\Core\Tests\Support\Modules\ModuleWithArguments;
use SightPress\Core\Tests\Support\TemporaryDirectory;
use SightPress\Module\ModuleInterface;
use stdClass;

#[CoversClass(ComposerModuleDiscovery::class)]
#[CoversClass(CoreModules::class)]
#[UsesClass(ModuleCandidate::class)]
final class ComposerModuleDiscoveryTest extends TestCase
{
    private TemporaryDirectory $project;

    protected function setUp(): void
    {
        $this->project = new TemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testCoreHasNoFeatureModulesYet(): void
    {
        self::assertSame([], CoreModules::candidates());
    }

    public function testAProjectWithoutInstalledPackagesHasNoExternalModules(): void
    {
        self::assertSame([], ComposerModuleDiscovery::forProject($this->project->path . '/')->candidates());
    }

    public function testFindsModulesDeclaredInPackageExtras(): void
    {
        $this->installed([
            ['name' => 'acme/tools', 'require' => ['php' => '>=8.5']],
            'not a package',
            [
                'name' => 'acme/example',
                'require' => ['sightpress/module-interface' => '^0.2'],
                'extra' => ['sightpress' => ['module' => ExampleModule::class]],
            ],
            [
                'name' => 'acme/more',
                'require' => ['sightpress/module-interface' => '^0.2 || ^1.0'],
                'extra' => ['sightpress' => ['module' => [ExampleModule::class, ExampleModule::class]]],
            ],
            ['name' => 'acme/other', 'extra' => ['branch-alias' => []]],
        ]);

        $candidates = ComposerModuleDiscovery::forProject($this->project->path)->candidates();

        self::assertCount(3, $candidates);
        self::assertInstanceOf(ExampleModule::class, $candidates[0]->module);
        self::assertSame('acme/example', $candidates[0]->source);
        self::assertSame('^0.2', $candidates[0]->contractConstraint);
        self::assertSame('acme/more', $candidates[2]->source);
        self::assertSame('^0.2 || ^1.0', $candidates[2]->contractConstraint);
    }

    public function testAnUnreadableFileSaysToReinstall(): void
    {
        $this->project->write('vendor/composer/installed.json', '{not json');

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessageMatches('/^Could not read the installed packages from .+installed\.json: .+ Run composer install again\.$/');

        ComposerModuleDiscovery::forProject($this->project->path)->candidates();
    }

    public function testAFileWithoutPackagesHasNoModules(): void
    {
        $this->project->write('vendor/composer/installed.json', '[]');

        self::assertSame([], ComposerModuleDiscovery::forProject($this->project->path)->candidates());
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalidPackages(): iterable
    {
        $contract = ['sightpress/module-interface' => '^0.2'];

        yield 'module is not a class name or list' => [
            ['name' => 'acme/bad', 'require' => $contract, 'extra' => ['sightpress' => ['module' => ['a' => 'b']]]],
            'Package acme/bad: "extra.sightpress.module" must be a module class name or a list of them.',
        ];
        yield 'module list is empty' => [
            ['require' => $contract, 'extra' => ['sightpress' => ['module' => []]]],
            'Package an unnamed package: "extra.sightpress.module" must be a module class name or a list of them.',
        ];
        yield 'no contract requirement' => [
            ['name' => 'acme/bad', 'extra' => ['sightpress' => ['module' => ExampleModule::class]]],
            'Package acme/bad declares a SightPress module but does not require sightpress/module-interface. '
                . 'Add it to the package\'s "require" with the versions the module works with.',
        ];
        yield 'class does not exist' => [
            ['name' => 'acme/bad', 'require' => $contract, 'extra' => ['sightpress' => ['module' => 'Acme\Missing']]],
            'Package acme/bad names a module class that does not exist: Acme\Missing.',
        ];
        yield 'class is not a string' => [
            ['name' => 'acme/bad', 'require' => $contract, 'extra' => ['sightpress' => ['module' => [42]]]],
            'Package acme/bad names a module class that does not exist: int.',
        ];
        yield 'class is not a module' => [
            ['name' => 'acme/bad', 'require' => $contract, 'extra' => ['sightpress' => ['module' => stdClass::class]]],
            'Package acme/bad: stdClass must implement ' . ModuleInterface::class . '.',
        ];
        yield 'constructor needs arguments' => [
            ['name' => 'acme/bad', 'require' => $contract, 'extra' => ['sightpress' => ['module' => ModuleWithArguments::class]]],
            'Package acme/bad: module ' . ModuleWithArguments::class . ' must be a class that can be created without arguments.',
        ];
    }

    /**
     * @param array<mixed> $package
     */
    #[DataProvider('invalidPackages')]
    public function testInvalidModuleDeclarationsSayWhatIsWrong(array $package, string $message): void
    {
        $this->installed([$package]);

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage($message);

        ComposerModuleDiscovery::forProject($this->project->path)->candidates();
    }

    /**
     * @param list<mixed> $packages
     */
    private function installed(array $packages): void
    {
        $this->project->write(
            'vendor/composer/installed.json',
            json_encode(['packages' => $packages], JSON_THROW_ON_ERROR),
        );
    }
}
