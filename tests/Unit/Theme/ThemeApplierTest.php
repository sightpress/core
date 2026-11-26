<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Theme;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\ArraySiteSettings;
use SightPress\Core\Tests\Support\RecordingEvents;
use SightPress\Core\Tests\Support\TemporaryDirectory;
use SightPress\Core\Tests\Support\Theme\HarbourTheme;
use SightPress\Module\Event\ThemeApplied;
use SightPress\Core\Theme\SiteTheme;
use SightPress\Core\Theme\ThemeApplier;
use SightPress\Core\Theme\ThemeChecker;
use SightPress\Core\Theme\ThemeException;
use SightPress\Core\Theme\ThemeRegistry;
use SightPress\Theme\ThemeInterface;
use SightPress\Theme\ThemeManifest;
use SightPress\Theme\ThemePaths;
use SightPress\Theme\ThemeRequirement;

#[CoversClass(ThemeApplier::class)]
#[UsesClass(ThemeChecker::class)]
#[UsesNamespace('SightPress\Core\Theme')]
#[UsesNamespace('SightPress\Core\Tenant')]
#[UsesClass(\SightPress\Core\Media\MediaLimits::class)]
final class ThemeApplierTest extends TestCase
{
    private ArraySiteSettings $settings;

    private RecordingEvents $events;

    protected function setUp(): void
    {
        $this->settings = new ArraySiteSettings();
        $this->events = new RecordingEvents();
    }

    public function testAppliesACheckedThemeAndSaysSo(): void
    {
        $check = $this->applier()->apply('acme-harbour', 7);

        self::assertSame('Harbour', $check->name);
        self::assertSame('acme-harbour', $this->settings->get(SiteTheme::SETTING));
        self::assertEquals([new ThemeApplied('acme-harbour', 'Harbour', null, 7)], $this->events->events);

        $this->applier()->apply('default');
        self::assertEquals(new ThemeApplied('default', 'SightPress Default', 'acme-harbour', null), $this->events->events[1]);
    }

    public function testRefusesAThemeThatIsNotInstalled(): void
    {
        $this->expectExceptionObject(new ThemeException('The theme "acme-gone" is not installed. Installed themes: default, acme-harbour.'));
        $this->applier()->apply('acme-gone');
    }

    public function testRefusesAThemeTheTenantDoesNotAllow(): void
    {
        $this->expectExceptionObject(new ThemeException('The theme "acme-harbour" is not allowed on this server (tenant setting "themes.allowed").'));
        $this->applier(['themes' => ['allowed' => ['default']]])->apply('acme-harbour');
    }

    public function testRefusesAThemeForAnotherContract(): void
    {
        $this->expectExceptionObject(new ThemeException('The theme "acme-harbour" can\'t be used: It needs sightpress/theme-interface ^0.3, and this SightPress has 0.4.0.0.'));
        $this->applier([], '0.4.0.0')->apply('acme-harbour');
    }

    public function testRefusesAThemeThatFailsItsChecksAndKeepsTheOldOne(): void
    {
        $directory = new TemporaryDirectory();
        try {
            $directory->write('theme.json', '{}');
            $broken = new class ($directory->path) implements ThemeInterface {
                public function __construct(private string $root)
                {
                }

                public function manifest(): ThemeManifest
                {
                    return new ThemeManifest('acme-broken', 'Broken', 'Broken.', '1.0.0', new ThemeRequirement('^0.3'), previewDescription: 'Broken.');
                }

                public function paths(): ThemePaths
                {
                    return new ThemePaths($this->root);
                }
            };
            $this->settings->set(SiteTheme::SETTING, 'default');
            $applier = new ThemeApplier(new ThemeRegistry([$broken]), TenantConfig::fromArray([], '/srv/app'), $this->settings, new ThemeChecker(), $this->events);

            try {
                $applier->apply('acme-broken');
                self::fail('The broken theme was applied.');
            } catch (ThemeException $exception) {
                self::assertStringStartsWith('The theme "acme-broken" can\'t be applied: ', $exception->getMessage());
            }
            self::assertSame('default', $this->settings->get(SiteTheme::SETTING));
            self::assertSame([], $this->events->events);
        } finally {
            $directory->remove();
        }
    }

    /**
     * @param array<string, mixed> $tenant
     */
    private function applier(array $tenant = [], ?string $contract = null): ThemeApplier
    {
        return new ThemeApplier(
            new ThemeRegistry([new HarbourTheme()], [], $contract),
            TenantConfig::fromArray($tenant, '/srv/app'),
            $this->settings,
            new ThemeChecker(),
            $this->events,
        );
    }
}
