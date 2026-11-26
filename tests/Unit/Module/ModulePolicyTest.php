<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Module;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Module\LoadedModule;
use SightPress\Core\Module\ModuleAbout;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleCatalogue;
use SightPress\Core\Module\ModuleException;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModulePolicy;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\FakeModule;

#[\PHPUnit\Framework\Attributes\UsesClass(\SightPress\Core\Tenant\TenantAssistant::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\SightPress\Core\Tenant\TenantPolicies::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\SightPress\Core\Tenant\TenantSms::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\SightPress\Core\Tenant\TwoStepPolicy::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\SightPress\Core\Tenant\TenantThemes::class)]
#[CoversClass(ModulePolicy::class)]
#[CoversClass(ModuleAbout::class)]
#[CoversClass(LoadedModule::class)]
#[UsesClass(ModuleCatalogue::class)]
#[UsesClass(ModuleSelection::class)]
#[UsesClass(ModuleLoader::class)]
#[UsesClass(ModuleCandidate::class)]
#[UsesClass(TenantConfig::class)]
#[UsesClass(\SightPress\Core\Tenant\Environment::class)]
#[UsesClass(\SightPress\Core\Tenant\LocaleCode::class)]
#[UsesClass(\SightPress\Core\Media\MediaLimits::class)]
#[UsesClass(\SightPress\Core\Tenant\SiteMediaLimits::class)]
#[UsesClass(\SightPress\Core\Tenant\TenantMediaLimits::class)]
final class ModulePolicyTest extends TestCase
{
    public function testLocksWinThenTheSitesChoicesThenTheDefaults(): void
    {
        $policy = ModulePolicy::fromConfig($this->catalogue(), $this->config(
            defaults: ['acme.forms' => true],
            locks: ['acme.news' => false],
        ));

        self::assertSame([true, true, false, true, true], [
            $policy->defaultFor('site'),
            $policy->defaultFor('acme.events'),
            $policy->defaultFor('acme.calendar'),
            $policy->defaultFor('acme.forms'),
            $policy->defaultFor('acme.news'),
        ]);
        self::assertSame([null, false], [$policy->lock('acme.events'), $policy->lock('acme.news')]);
        self::assertSame(['acme.events', 'acme.calendar', 'acme.forms', 'acme.news'], array_map(static fn (LoadedModule $m): string => $m->id(), $policy->optional()));

        self::assertSame(['site', 'acme.events', 'acme.forms'], $policy->onIds($policy->selection([])));
        self::assertSame(
            ['site', 'acme.events', 'acme.calendar'],
            $policy->onIds($policy->selection(['acme.calendar' => true, 'acme.forms' => false, 'acme.news' => true, 'site' => false])),
        );
        self::assertSame(['site', 'acme.forms'], $policy->onIds($policy->selection(['acme.events' => false, 'acme.calendar' => true])), 'a module whose requirement is off is off');
        self::assertSame(
            ['site' => true, 'acme.events' => true, 'acme.calendar' => true, 'acme.forms' => true, 'acme.news' => false],
            $policy->tenantSelection()->states(),
        );
        self::assertSame($policy->catalogue()->ids(), $this->catalogue()->ids());
    }

    public function testModulesThatNeedAModuleLockedOffAreNotLoaded(): void
    {
        $policy = ModulePolicy::fromConfig($this->catalogue(), $this->config(locks: ['acme.events' => false]));

        self::assertSame(['site' => true, 'acme.events' => false, 'acme.calendar' => false, 'acme.forms' => true, 'acme.news' => true], $policy->tenantSelection()->states());
    }

    /**
     * @param array<string, bool> $defaults
     * @param array<string, bool> $locks
     */
    #[DataProvider('invalidConfigs')]
    public function testTheTenantsSettingsAreChecked(array $defaults, array $locks, string $message): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage($message);

        ModulePolicy::fromConfig($this->catalogue(), $this->config($defaults, $locks));
    }

    /**
     * @return iterable<string, array{array<string, bool>, array<string, bool>, string}>
     */
    public static function invalidConfigs(): iterable
    {
        yield 'required lock' => [[], ['site' => false], 'Tenant setting "modules.locked" names module "site", which is always on.'];
        yield 'lock needs a locked-off module' => [[], ['acme.calendar' => true, 'acme.events' => false], 'Tenant setting "modules.locked" turns module "acme.calendar" on but module "acme.events", which it requires, off.'];
    }

    public function testSettingsThatNameAModuleThatIsntInstalledAreLeftOutAndReported(): void
    {
        $policy = ModulePolicy::fromConfig($this->catalogue(), $this->config(['acme.nope' => true, 'acme.events' => false], ['acme.gone' => true, 'acme.calendar' => false]));

        self::assertSame([
            ['setting' => 'modules.defaults', 'id' => 'acme.nope'],
            ['setting' => 'modules.locked', 'id' => 'acme.gone'],
        ], $policy->missing());
        self::assertFalse($policy->defaultFor('acme.events'));
        self::assertFalse($policy->lock('acme.calendar'));
        self::assertNull($policy->lock('acme.gone'));
        self::assertSame([], ModulePolicy::fromConfig($this->catalogue(), $this->config([], []))->missing());
    }

    public function testAModuleIsDescribedByItsPackageOrByItsId(): void
    {
        $about = ModuleAbout::fromArray(['name' => ' Events ', 'description' => 'A calendar.', 'when-off' => 7], 'acme.events');
        self::assertSame(['Events', 'A calendar.', ''], [$about->name, $about->description, $about->whenOff]);
        self::assertSame('acme.events', ModuleAbout::fromArray(['name' => ''], 'acme.events')->name);

        $catalogue = new ModuleLoader('0.4.0')->catalogue([
            new ModuleCandidate(new FakeModule('acme.events'), 'acme/events', '*', ['acme.events' => $about]),
            new ModuleCandidate(new FakeModule('acme.calendar'), 'acme/events', '*', ['acme.events' => $about]),
        ]);
        self::assertSame(['Events', 'acme.calendar'], [$catalogue->get('acme.events')->name(), $catalogue->get('acme.calendar')->name()]);
        self::assertSame($about, $catalogue->get('acme.events')->about);
    }

    private function catalogue(): ModuleCatalogue
    {
        return new ModuleLoader('0.4.0')->catalogue(array_map(
            static fn (FakeModule $module): ModuleCandidate => new ModuleCandidate($module, 'acme/test'),
            [
                new FakeModule('site', required: true),
                new FakeModule('acme.events'),
                new FakeModule('acme.calendar', ['acme.events' => '*'], enabledByDefault: false),
                new FakeModule('acme.forms', enabledByDefault: false),
                new FakeModule('acme.news'),
            ],
        ));
    }

    /**
     * @param array<string, bool> $defaults
     * @param array<string, bool> $locks
     */
    private function config(array $defaults = [], array $locks = []): TenantConfig
    {
        return TenantConfig::fromArray(['modules' => ['defaults' => $defaults, 'locked' => $locks]], '/srv/project');
    }
}
