<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Module;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Module\ComposerModuleDiscovery;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleDiscovery;
use SightPress\Core\Module\ModuleException;
use SightPress\Core\Module\UnavailableModule;
use SightPress\Core\Tests\Support\Modules\BrokenModule;
use SightPress\Core\Tests\Support\Modules\ExampleModule;
use SightPress\Core\Tests\Support\Modules\ModuleWithArguments;
use SightPress\Core\Tests\Support\TemporaryDirectory;
use SightPress\Module\ModuleInterface;
use stdClass;

#[\PHPUnit\Framework\Attributes\UsesClass(\SightPress\Core\Privacy\PrivacyModule::class)]
#[CoversClass(ComposerModuleDiscovery::class)]
#[CoversClass(CoreModules::class)]
#[CoversClass(ModuleDiscovery::class)]
#[UsesClass(UnavailableModule::class)]
#[UsesNamespace('SightPress\Core\Search')]
#[UsesNamespace('SightPress\Core\Analytics')]
#[UsesNamespace('SightPress\Core\Assistant')]
#[UsesNamespace('SightPress\Core\Sms')]
#[UsesClass(ModuleCandidate::class)]
#[UsesClass(\SightPress\Core\Module\ModuleAbout::class)]
#[UsesClass(\SightPress\Core\Site\SiteModule::class)]
#[UsesClass(\SightPress\Core\User\UserModule::class)]
#[UsesClass(\SightPress\Core\Rbac\RbacModule::class)]
#[UsesClass(\SightPress\Core\Auth\AuthModule::class)]
#[UsesClass(\SightPress\Core\Theme\ThemeModule::class)]
#[UsesClass(\SightPress\Core\Media\MediaModule::class)]
#[UsesClass(\SightPress\Core\Page\PageModule::class)]
#[UsesClass(\SightPress\Core\Contact\ContactModule::class)]
#[UsesClass(\SightPress\Core\Block\BlockModule::class)]
#[UsesClass(\SightPress\Core\Admin\AdminModule::class)]
#[UsesClass(\SightPress\Core\People\PeopleModule::class)]
#[UsesClass(\SightPress\Core\Menu\MenuModule::class)]
#[UsesClass(\SightPress\Core\Branding\BrandingModule::class)]
#[UsesClass(\SightPress\Core\Seo\SeoModule::class)]
#[UsesClass(\SightPress\Core\Media\Admin\MediaAdminModule::class)]
#[UsesClass(\SightPress\Core\Page\Admin\PageAdminModule::class)]
#[UsesClass(\SightPress\Core\Audit\AuditModule::class)]
#[UsesClass(\SightPress\Core\Feature\FeatureModule::class)]
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

    public function testCoreListsItsOwnModules(): void
    {
        $candidates = CoreModules::candidates();

        self::assertSame(['site', 'user', 'rbac', 'auth', 'theme', 'media', 'block', 'page', 'contact', 'admin', 'media_admin', 'page_admin', 'people', 'menu', 'branding', 'seo', 'features', 'search', 'analytics', 'assistant', 'sms', 'privacy', 'audit'], array_map(static fn ($candidate) => $candidate->module->manifest()->id, $candidates));
        self::assertSame('sightpress/core', $candidates[0]->source);
        foreach (CoreModules::entityPaths() as $path) {
            self::assertDirectoryExists($path);
        }
        self::assertNull($candidates[0]->contractConstraint);
    }

    public function testAProjectWithoutInstalledPackagesHasNoExternalModules(): void
    {
        self::assertSame([], ComposerModuleDiscovery::forProject($this->project->path . '/')->discover()->candidates);
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
                'extra' => ['sightpress' => [
                    'module' => [ExampleModule::class, ExampleModule::class],
                    'features' => ['acme.example' => ['name' => 'Example', 'description' => 'Shows an example.'], 'acme.bad' => 'text', 7 => []],
                ]],
            ],
            ['name' => 'acme/other', 'extra' => ['branch-alias' => []]],
        ]);

        $discovery = ComposerModuleDiscovery::forProject($this->project->path)->discover();
        $candidates = $discovery->candidates;
        self::assertSame([], $discovery->unavailable);

        self::assertCount(3, $candidates);
        self::assertInstanceOf(ExampleModule::class, $candidates[0]->module);
        self::assertSame('acme/example', $candidates[0]->source);
        self::assertSame('^0.2', $candidates[0]->contractConstraint);
        self::assertSame('acme/more', $candidates[2]->source);
        self::assertSame('^0.2 || ^1.0', $candidates[2]->contractConstraint);
        self::assertSame([], $candidates[0]->about);
        self::assertSame(['acme.example'], array_keys($candidates[2]->about));
        self::assertSame(['Example', 'Shows an example.'], [$candidates[2]->about['acme.example']->name, $candidates[2]->about['acme.example']->description]);
    }

    public function testAnUnreadableFileSaysToReinstall(): void
    {
        $this->project->write('vendor/composer/installed.json', '{not json');

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessageMatches('/^Could not read the installed packages from .+installed\.json: .+ Run composer install again\.$/');

        ComposerModuleDiscovery::forProject($this->project->path)->discover();
    }

    public function testAFileWithoutPackagesHasNoModules(): void
    {
        $this->project->write('vendor/composer/installed.json', '[]');

        self::assertSame([], ComposerModuleDiscovery::forProject($this->project->path)->discover()->candidates);
    }

    public function testWhatPackagesDeclareIsCachedUntilInstalledJsonChanges(): void
    {
        $example = ['name' => 'acme/example', 'require' => ['sightpress/module-interface' => '^0.2'], 'extra' => ['sightpress' => ['module' => ExampleModule::class]]];
        $this->installed([$example]);
        $cacheDir = $this->project->path . '/var/cache/prod';

        self::assertCount(1, ComposerModuleDiscovery::forProject($this->project->path, $cacheDir . '/')->discover()->candidates);
        self::assertFileExists($cacheDir . '/modules.php');

        // Read from the cache: what the file now says is ignored while installed.json is the same.
        $cached = (string) file_get_contents($cacheDir . '/modules.php');
        file_put_contents($cacheDir . '/modules.php', str_replace('acme/example', 'acme/cached', $cached));
        self::assertSame('acme/cached', ComposerModuleDiscovery::forProject($this->project->path, $cacheDir)->discover()->candidates[0]->source);

        // installed.json changed (composer require/remove): read again and the cache is rewritten.
        $this->installed([$example, ['name' => 'acme/second'] + $example]);
        self::assertSame(['acme/example', 'acme/second'], array_map(
            static fn (ModuleCandidate $candidate): string => $candidate->source,
            ComposerModuleDiscovery::forProject($this->project->path, $cacheDir)->discover()->candidates,
        ));
        self::assertStringContainsString('acme/second', (string) file_get_contents($cacheDir . '/modules.php'));

        // cache:clear empties the folder: read again.
        unlink($cacheDir . '/modules.php');
        self::assertCount(2, ComposerModuleDiscovery::forProject($this->project->path, $cacheDir)->discover()->candidates);
    }

    public function testABrokenPackageIsUnavailableAndTheOthersAreStillFound(): void
    {
        BrokenModule::$failAt = 'create';
        $contract = ['sightpress/module-interface' => '^0.2'];
        $this->installed([
            ['name' => 'acme/broken', 'require' => $contract, 'extra' => ['sightpress' => ['module' => [BrokenModule::class, ExampleModule::class]]]],
            ['name' => 'acme/example', 'require' => $contract, 'extra' => ['sightpress' => ['module' => ExampleModule::class]]],
        ]);

        $discovery = ComposerModuleDiscovery::forProject($this->project->path)->discover();

        self::assertSame(['acme/broken', 'acme/example'], array_map(static fn (ModuleCandidate $candidate): string => $candidate->source, $discovery->candidates));
        self::assertEquals([new UnavailableModule('acme/broken', null, 'The module could not connect to its licence server.')], $discovery->unavailable);
    }

    /**
     * @return iterable<string, array{array<mixed>, non-empty-string, string}>
     */
    public static function invalidPackages(): iterable
    {
        $contract = ['sightpress/module-interface' => '^0.2'];

        yield 'module is not a class name or list' => [
            ['name' => 'acme/bad', 'require' => $contract, 'extra' => ['sightpress' => ['module' => ['a' => 'b']]]],
            'acme/bad', '"extra.sightpress.module" must be a module class name or a list of them.',
        ];
        yield 'module list is empty' => [
            ['require' => $contract, 'extra' => ['sightpress' => ['module' => []]]],
            'an unnamed package', '"extra.sightpress.module" must be a module class name or a list of them.',
        ];
        yield 'no contract requirement' => [
            ['name' => 'acme/bad', 'extra' => ['sightpress' => ['module' => ExampleModule::class]]],
            'acme/bad', 'It declares a SightPress module but does not require sightpress/module-interface. '
                . 'Add it to the package\'s "require" with the versions the module works with.',
        ];
        yield 'class does not exist' => [
            ['name' => 'acme/bad', 'require' => $contract, 'extra' => ['sightpress' => ['module' => 'Acme\Missing']]],
            'acme/bad', 'The module class Acme\Missing does not exist. Check "extra.sightpress.module" and the package\'s autoload.',
        ];
        yield 'class is not a string' => [
            ['name' => 'acme/bad', 'require' => $contract, 'extra' => ['sightpress' => ['module' => [42]]]],
            'acme/bad', 'The module class int does not exist. Check "extra.sightpress.module" and the package\'s autoload.',
        ];
        yield 'class is not a module' => [
            ['name' => 'acme/bad', 'require' => $contract, 'extra' => ['sightpress' => ['module' => stdClass::class]]],
            'acme/bad', 'stdClass must implement ' . ModuleInterface::class . '.',
        ];
        yield 'constructor needs arguments' => [
            ['name' => 'acme/bad', 'require' => $contract, 'extra' => ['sightpress' => ['module' => ModuleWithArguments::class]]],
            'acme/bad', 'The module ' . ModuleWithArguments::class . ' must be a class that can be created without arguments.',
        ];
    }

    /**
     * @param array<mixed>     $package
     * @param non-empty-string $source
     */
    #[DataProvider('invalidPackages')]
    public function testInvalidModuleDeclarationsAreUnavailableWithTheReason(array $package, string $source, string $reason): void
    {
        $this->installed([$package]);

        $discovery = ComposerModuleDiscovery::forProject($this->project->path)->discover();

        self::assertSame([], $discovery->candidates);
        self::assertEquals([new UnavailableModule($source, null, $reason)], $discovery->unavailable);
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
